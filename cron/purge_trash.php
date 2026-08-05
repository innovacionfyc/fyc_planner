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
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Este script solo puede ejecutarse desde CLI.\n");
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
    $sel = $conn->query(
        "SELECT id FROM boards
          WHERE deleted_at IS NOT NULL
            AND deleted_at < NOW() - INTERVAL 30 DAY
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

    // 3) Borrado. Los IDs vienen de la propia consulta y ya son enteros,
    //    pero se mantiene el filtro de papelera como seguro.
    $lista  = implode(',', $ids);
    $result = $conn->query(
        "DELETE FROM boards WHERE id IN ($lista) AND deleted_at IS NOT NULL"
    );

    if ($result === false) {
        fwrite(STDERR, date('Y-m-d H:i:s') . " — ERROR: " . $conn->error . "\n");
        exit(1);
    }

    $totalDeleted += $conn->affected_rows;

    // 4) Ya confirmado en base: se borran los archivos del lote.
    if ($paths !== []) {
        $st = attach_delete_files($paths, 'cron_purge_trash');
        foreach ($totalFiles as $k => $_) {
            $totalFiles[$k] += $st[$k];
        }
    }
} while ($batch === 100);

echo date('Y-m-d H:i:s') . " — Purged {$totalDeleted} board(s) from trash; "
    . "files: {$totalFiles['deleted']} deleted, {$totalFiles['missing']} missing, "
    . "{$totalFiles['failed']} failed.\n";

// Salida distinta de 0 si algún archivo no se pudo borrar: el cron de
// huérfanos lo recogerá, pero conviene que quede constancia en el log.
exit($totalFiles['failed'] > 0 ? 1 : 0);
