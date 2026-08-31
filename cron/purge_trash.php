<?php
/**
 * cron/purge_trash.php
 *
 * Elimina definitivamente los tableros en papelera con más de 30 días.
 * Ejecuta en lotes de 100 para no bloquear la base de datos.
 *
 * Uso local (Laragon/Windows):
 *   php C:\laragon\www\fyc_planner\cron\purge_trash.php
 *
 * Cron en Plesk (diario a las 3:00 AM):
 *   0 3 * * *  php /var/www/vhosts/<dominio>/fyc_planner/cron/purge_trash.php >> /var/log/fyc_purge_trash.log 2>&1
 *
 * ALCANCE ACOTADO  --board-id=N
 *   Sin argumentos el comportamiento es el de siempre: purga TODOS los
 *   tableros en papelera con más de 30 días. Es lo que hace el cron nocturno
 *   y no ha cambiado.
 *
 *   Con --board-id=N la purga se limita a ese tablero. La regla de antigüedad
 *   sigue aplicándose: no es un modo que desactive la lógica, solo restringe
 *   sobre qué opera. Un tablero reciente no se borra ni pidiéndolo por id.
 *
 *   Existe porque una prueba lanzaba este cron sin acotar y borró un tablero
 *   real de una copia de producción. Un argumento mal escrito NO puede
 *   degradar en purga global: cualquier argumento desconocido aborta.
 *
 * SIMULACRO  --dry-run
 *   Enumera y cuenta lo que purgaría sin borrar nada, ni en base ni en disco.
 *   Sirve para dos cosas: comprobar el alcance global sin destruir datos —una
 *   prueba no puede permitirse ejecutarlo de verdad sobre una copia real— y
 *   para mirar antes de actuar en producción.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Este script solo puede ejecutarse desde CLI.\n");
}

// ─────────────────────────────────────────────────────────────
// Argumentos
// ─────────────────────────────────────────────────────────────
$boardId = null;   // null = alcance global, el de siempre
$dryRun  = false;  // simulacro: enumera pero no borra

foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--board-id=(\d+)$/', $arg, $m)) {
        $boardId = (int) $m[1];
        if ($boardId <= 0) {
            fwrite(STDERR, "--board-id debe ser un entero positivo.\n");
            exit(2);
        }
    } elseif ($arg === '--dry-run' || $arg === '-n') {
        $dryRun = true;
    } elseif ($arg === '--help' || $arg === '-h') {
        echo "Uso: php purge_trash.php [--board-id=N] [--dry-run]\n";
        echo "  sin argumentos : purga todos los tableros en papelera de mas de 30 dias\n";
        echo "  --board-id=N   : limita la purga a ese tablero (la regla de 30 dias sigue vigente)\n";
        echo "  --dry-run      : enumera lo que purgaria, sin borrar nada\n";
        exit(0);
    } else {
        // Deliberadamente estricto: si alguien escribe --board-di=5, prefiero
        // que falle a que se interprete como «sin alcance» y arrase la base.
        fwrite(STDERR, "Argumento no reconocido: {$arg}\n");
        exit(2);
    }
}

require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../public/_attachments.php';
app_sync_db_timezone($conn);

$totalDeleted = 0;
$totalFiles   = ['total' => 0, 'deleted' => 0, 'missing' => 0, 'invalid' => 0, 'failed' => 0];

do {
    // 1) IDs del lote. Antes se hacía DELETE ... LIMIT 100 directamente, pero
    //    hay que conocer los tableros de antemano: la cascada
    //    boards → tasks → task_attachments borra las filas y sin las rutas
    //    los archivos quedarían huérfanos en disco para siempre.
    //    El filtro de alcance se AÑADE a las condiciones de siempre; nunca las
    //    sustituye. Con --board-id el lote trae como mucho un tablero, y solo
    //    si además cumple la antigüedad.
    $filtroAlcance = ($boardId !== null) ? " AND id = " . (int) $boardId : "";
    $sel = $conn->query(
        "SELECT id FROM boards
          WHERE deleted_at IS NOT NULL
            AND deleted_at < NOW() - INTERVAL 30 DAY
            {$filtroAlcance}
          ORDER BY id
          LIMIT 100"
    );

    if ($sel === false) {
        fwrite(STDERR, date('Y-m-d H:i:s') . " — ERROR: " . $conn->error . "\n");
        exit(1);
    }

    $ids = [];
    while ($row = $sel->fetch_row()) {
        $ids[] = (int) $row[0];
    }
    $sel->free();

    $batch = count($ids);
    if ($batch === 0) {
        break;
    }

    // 2) Rutas de archivo de todo el lote, aún con las filas vivas.
    $paths = [];
    foreach ($ids as $bid) {
        foreach (attach_stored_paths_of_board($conn, $bid) as $p) {
            $paths[] = $p;
        }
    }

    // 3) Simulacro: informa de lo que se habria borrado y corta antes de
    //    tocar la base y el disco. La seleccion es exactamente la misma
    //    que en la ejecucion real: no hay un segundo camino de decision.
    //
    //    Solo enumera el PRIMER lote (100 como maximo). Iterar seria imposible
    //    sin borrar: la consulta devolveria siempre los mismos identificadores
    //    y el bucle no terminaria nunca. Con mas de 100 candidatos, el
    //    simulacro informa de menos de los que la ejecucion real purgaria.
    if ($dryRun) {
        $totalDeleted += $batch;
        $totalFiles['total'] += count($paths);
        echo date('Y-m-d H:i:s') . " - SIMULACRO: se purgarian los tableros "
            . implode(",", $ids) . " ({$batch}) y " . count($paths) . " archivo(s).\n";
        break;
    }

    // 4) Borrado. Los IDs vienen de la propia consulta y ya son enteros,
    //    pero se mantiene el filtro de papelera como seguro.
    $lista  = implode(',', $ids);
    // El alcance se repite aquí como segundo cerrojo: aunque la selección ya
    // venga acotada, el borrado no puede salirse del tablero pedido.
    $result = $conn->query(
        "DELETE FROM boards WHERE id IN ($lista) AND deleted_at IS NOT NULL{$filtroAlcance}"
    );

    if ($result === false) {
        fwrite(STDERR, date('Y-m-d H:i:s') . " — ERROR: " . $conn->error . "\n");
        exit(1);
    }

    $totalDeleted += $conn->affected_rows;

    // 5) Ya confirmado en base: se borran los archivos del lote.
    if ($paths !== []) {
        $st = attach_delete_files($paths, 'cron_purge_trash');
        foreach ($totalFiles as $k => $_) {
            $totalFiles[$k] += $st[$k];
        }
    }
} while ($batch === 100);

echo date('Y-m-d H:i:s') . ($dryRun ? " — DRY RUN: " : " — Purged ")
    . "{$totalDeleted} board(s) from trash; "
    . "files: {$totalFiles['deleted']} deleted, {$totalFiles['missing']} missing, "
    . "{$totalFiles['failed']} failed.\n";

// Salida distinta de 0 si algún archivo no se pudo borrar: el cron de
// huérfanos lo recogerá, pero conviene que quede constancia en el log.
exit($totalFiles['failed'] > 0 ? 1 : 0);
