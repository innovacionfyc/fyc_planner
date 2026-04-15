<?php
// cron/run_alerts.php — Ejecución automática de alertas (CLI únicamente)
// Uso: php C:\laragon\www\fyc_planner\cron\run_alerts.php
//
// Este archivo está fuera de public/ y no es accesible por HTTP.
// Si por algún motivo el servidor lo sirviera, la guardia SAPI lo detiene.

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit(1);
}

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/mail.php';
require_once __DIR__ . '/../public/admin/_alerts_core.php';
require_once __DIR__ . '/../public/admin/_email_helpers.php';

$start = microtime(true);

try {
    $result  = run_all_alerts($conn);
    $emailed = send_alert_emails($conn, $result['new_ids'] ?? []);
    $elapsed = round((microtime(true) - $start) * 1000);
    echo '[' . date('Y-m-d H:i:s') . '] OK — '
       . 'inserted=' . $result['inserted'] . ' '
       . 'skipped='  . $result['skipped']  . ' '
       . 'emailed='  . $emailed            . ' '
       . '(' . $elapsed . 'ms)' . PHP_EOL;
    exit(0);
} catch (Throwable $e) {
    echo '[' . date('Y-m-d H:i:s') . '] ERROR — ' . $e->getMessage() . PHP_EOL;
    exit(1);
}
