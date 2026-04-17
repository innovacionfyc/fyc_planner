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

require_once __DIR__ . '/../config/db.php';

$totalDeleted = 0;

do {
    $result = $conn->query(
        "DELETE FROM boards
         WHERE deleted_at IS NOT NULL
           AND deleted_at < NOW() - INTERVAL 30 DAY
         LIMIT 100"
    );

    if ($result === false) {
        fwrite(STDERR, date('Y-m-d H:i:s') . " — ERROR: " . $conn->error . "\n");
        exit(1);
    }

    $batch = $conn->affected_rows;
    $totalDeleted += $batch;
} while ($batch === 100);

echo date('Y-m-d H:i:s') . " — Purged {$totalDeleted} board(s) from trash.\n";
exit(0);
