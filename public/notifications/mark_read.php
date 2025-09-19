<?php
require_once __DIR__ . '/../_auth.php';
require_login();
require_once __DIR__ . '/../../config/db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit;
}
if (!isset($_POST['csrf'], $_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], $_POST['csrf'])) {
    http_response_code(403);
    exit;
}

$all = isset($_POST['all']) && $_POST['all'] == '1';
if ($all) {
    $stmt = $conn->prepare("UPDATE notifications SET leido = 1 WHERE user_id = ? AND leido = 0");
    $stmt->bind_param('i', $_SESSION['user_id']);
    $stmt->execute();
    echo 'ok';
    exit;
}

echo 'ok';
