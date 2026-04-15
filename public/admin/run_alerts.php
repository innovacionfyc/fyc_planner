<?php
// public/admin/run_alerts.php — Endpoint HTTP para generar alertas (solo admin, POST + CSRF)
// La lógica de checks vive en _alerts_core.php y es compartida con cron/run_alerts.php.
require_once __DIR__ . '/../_auth.php';
require_admin();
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/_alerts_core.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'method_not_allowed']);
    exit;
}

if (
    empty($_POST['csrf']) || empty($_SESSION['csrf']) ||
    !hash_equals($_SESSION['csrf'], $_POST['csrf'])
) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'csrf_invalid']);
    exit;
}

try {
    $result = run_all_alerts($conn);
    echo json_encode(['ok' => true] + $result, JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
exit;
