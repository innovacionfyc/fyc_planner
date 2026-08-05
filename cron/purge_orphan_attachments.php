<?php
/**
 * cron/purge_orphan_attachments.php
 *
 * Borra los archivos de storage/attachments que ya no tienen fila en
 * task_attachments (huérfanos).
 *
 * ¿De dónde salen los huérfanos? De un borrado físico que falló, de una
 * subida interrumpida entre el move_uploaded_file y el INSERT, o de un
 * despliegue antiguo anterior a la Fase F. El caso habitual —borrar una
 * tarea o purgar un tablero— ya limpia sus archivos por sí solo; este cron
 * es la red de seguridad, no el mecanismo principal.
 *
 * QUÉ NO BORRA NUNCA
 *   - .gitkeep ni .htaccess
 *   - archivos cuyo nombre no encaja en AAAA/MM/<32 hex>.<ext>
 *   - enlaces simbólicos (ni los sigue)
 *   - archivos modificados hace menos del margen de gracia
 *   - nada en absoluto si no se pudo leer el inventario de la base
 *
 * Los enlaces y embeds no tienen archivo en disco, así que quedan fuera
 * por construcción.
 *
 * USO
 *   php cron/purge_orphan_attachments.php --dry-run     ver qué haría
 *   php cron/purge_orphan_attachments.php               borrar de verdad
 *   php cron/purge_orphan_attachments.php --grace-hours=48
 *   php cron/purge_orphan_attachments.php --verbose
 *
 * Local (Laragon/Windows):
 *   php C:\laragon\www\fyc_planner\cron\purge_orphan_attachments.php --dry-run
 *
 * Cron en Plesk (semanal, domingos a las 4:00). Conviene estrenarlo con
 * --dry-run durante una o dos semanas y revisar el log antes de dejarlo
 * borrar de verdad:
 *   0 4 * * 0  php /var/www/vhosts/<dominio>/<carpeta>/cron/purge_orphan_attachments.php >> /var/log/fyc_purge_orphans.log 2>&1
 *
 * CÓDIGOS DE SALIDA
 *   0  todo bien (con o sin huérfanos encontrados)
 *   1  hubo errores al borrar
 *   2  argumento inválido
 *   3  aborto de seguridad: no se pudo leer el inventario de la base
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Este script solo puede ejecutarse desde CLI.\n");
}

require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../public/_attachments.php';
app_sync_db_timezone($conn);

// ─────────────────────────────────────────────────────────────
// Argumentos
// ─────────────────────────────────────────────────────────────
$dryRun     = false;
$verbose    = false;
$graceHours = 24;

foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--dry-run' || $arg === '-n') {
        $dryRun = true;
    } elseif ($arg === '--verbose' || $arg === '-v') {
        $verbose = true;
    } elseif (preg_match('/^--grace-hours=(\d+)$/', $arg, $m)) {
        $graceHours = (int) $m[1];
    } elseif ($arg === '--help' || $arg === '-h') {
        echo "Uso: php purge_orphan_attachments.php [--dry-run] [--grace-hours=N] [--verbose]\n";
        exit(0);
    } else {
        fwrite(STDERR, "Argumento no reconocido: {$arg}\n");
        exit(2);
    }
}

$stamp = date('Y-m-d H:i:s');
$modo  = $dryRun ? 'SIMULACRO' : 'REAL';

function say(string $msg): void
{
    echo $msg . "\n";
}

function detalle(string $msg): void
{
    global $verbose;
    if ($verbose) {
        echo '    ' . $msg . "\n";
    }
}

// ─────────────────────────────────────────────────────────────
// 1) Inventario de la base
//
// Es el paso más delicado del script. Si esta consulta fallara y siguiéramos
// adelante, el conjunto saldría vacío, TODOS los archivos parecerían
// huérfanos y se borraría el almacén entero. Por eso cualquier problema
// aquí aborta sin tocar el disco.
// ─────────────────────────────────────────────────────────────
if (!attach_table_exists($conn)) {
    fwrite(STDERR, "{$stamp} — ABORTA: la tabla task_attachments no existe.\n");
    exit(3);
}

$res = $conn->query(
    "SELECT stored_path FROM task_attachments
      WHERE stored_path IS NOT NULL AND stored_path <> ''"
);

if ($res === false) {
    fwrite(STDERR, "{$stamp} — ABORTA: no se pudo leer task_attachments: " . $conn->error . "\n");
    exit(3);
}

$enBase = [];
while ($row = $res->fetch_row()) {
    // Clave en minúsculas: en Windows el sistema de archivos no distingue
    // mayúsculas y no queremos que un archivo legítimo parezca huérfano.
    $enBase[strtolower((string) $row[0])] = true;
}
$res->free();

// ─────────────────────────────────────────────────────────────
// 2) Recorrido del almacén
//
// El layout es fijo: raíz / AAAA / MM / archivo. Se enumera exactamente esa
// forma en lugar de recorrer en profundidad: así cualquier cosa inesperada
// se reporta como omitida en vez de acabar en la lista de borrado.
// ─────────────────────────────────────────────────────────────
$root = realpath(attach_storage_root());
if ($root === false || !is_dir($root)) {
    fwrite(STDERR, "{$stamp} — ABORTA: no existe storage/attachments.\n");
    exit(3);
}

$IGNORAR = ['.gitkeep', '.htaccess', '.gitignore'];
$corte   = time() - ($graceHours * 3600);

$revisados = 0;
$huerfanos = 0;
$eliminados = 0;
$omitidos  = 0;
$enGracia  = 0;
$errores   = 0;

/** Lista las entradas de un directorio sin incluir . ni .. */
function entradas(string $dir): array
{
    $e = @scandir($dir);
    if ($e === false) {
        return [];
    }
    return array_values(array_diff($e, ['.', '..']));
}

foreach (entradas($root) as $anio) {
    $dirAnio = $root . DIRECTORY_SEPARATOR . $anio;

    if (in_array($anio, $IGNORAR, true)) {
        continue;
    }
    // Un enlace simbólico podría apuntar fuera del almacén: ni se sigue ni
    // se borra. Solo se deja constancia.
    //
    // Matiz de plataforma: en Linux —producción— is_link() detecta los
    // symlinks y esta rama es la que actúa. En Windows, PHP NO reconoce las
    // uniones de directorio (mklink /J): is_link() devuelve false. Da igual,
    // porque is_dir() también las da por falsas y acaban descartadas por la
    // comprobación de abajo. En los dos sistemas el resultado es el mismo:
    // se omiten y no se sigue lo que hay al otro lado.
    if (is_link($dirAnio)) {
        $omitidos++;
        detalle("omitido (enlace simbólico): {$anio}");
        continue;
    }
    if (!is_dir($dirAnio) || !preg_match('/^\d{4}$/', $anio)) {
        $omitidos++;
        detalle("omitido (no es carpeta de año): {$anio}");
        continue;
    }

    foreach (entradas($dirAnio) as $mes) {
        $dirMes = $dirAnio . DIRECTORY_SEPARATOR . $mes;

        if (is_link($dirMes)) {
            $omitidos++;
            detalle("omitido (enlace simbólico): {$anio}/{$mes}");
            continue;
        }
        if (!is_dir($dirMes) || !preg_match('/^(0[1-9]|1[0-2])$/', $mes)) {
            $omitidos++;
            detalle("omitido (no es carpeta de mes): {$anio}/{$mes}");
            continue;
        }

        foreach (entradas($dirMes) as $nombre) {
            $ruta      = $dirMes . DIRECTORY_SEPARATOR . $nombre;
            $relativa  = $anio . '/' . $mes . '/' . $nombre;

            if (in_array($nombre, $IGNORAR, true)) {
                continue;
            }
            if (is_link($ruta)) {
                $omitidos++;
                detalle("omitido (enlace simbólico): {$relativa}");
                continue;
            }
            if (!is_file($ruta)) {
                $omitidos++;
                detalle("omitido (no es un archivo): {$relativa}");
                continue;
            }

            $revisados++;

            // Solo se consideran candidatos los nombres que el propio módulo
            // habría generado. Cualquier otro se deja quieto: si alguien
            // dejó ahí un archivo a mano, no es asunto de este cron.
            if (!attach_is_valid_stored_path($relativa)) {
                $omitidos++;
                detalle("omitido (nombre fuera de patrón): {$relativa}");
                continue;
            }

            if (isset($enBase[strtolower($relativa)])) {
                continue; // tiene fila: se queda
            }

            $huerfanos++;

            // Margen de gracia: un archivo recién movido a su sitio puede
            // estar todavía esperando su INSERT. Se le da tiempo.
            $mtime = @filemtime($ruta);
            if ($mtime === false || $mtime > $corte) {
                $enGracia++;
                detalle("en gracia (demasiado reciente): {$relativa}");
                continue;
            }

            if ($dryRun) {
                detalle("SE BORRARÍA: {$relativa}");
                continue;
            }

            if (@unlink($ruta) || !is_file($ruta)) {
                $eliminados++;
                detalle("eliminado: {$relativa}");
            } else {
                $errores++;
                fwrite(STDERR, "{$stamp} — ERROR: no se pudo borrar {$relativa}\n");
            }
        }
    }
}

// ─────────────────────────────────────────────────────────────
// 3) Informe
// ─────────────────────────────────────────────────────────────
say("{$stamp} — purge_orphan_attachments [{$modo}] gracia={$graceHours}h");
say("  revisados : {$revisados}");
say("  en base   : " . count($enBase));
say("  huérfanos : {$huerfanos}");
say("  en gracia : {$enGracia}");
say("  eliminados: {$eliminados}" . ($dryRun ? ' (simulacro: no se borró nada)' : ''));
say("  omitidos  : {$omitidos}");
say("  errores   : {$errores}");

exit($errores > 0 ? 1 : 0);
