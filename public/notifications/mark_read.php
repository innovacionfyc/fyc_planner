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

// Marcar una sola notificación (si viene)
$note_id = isset($_POST['note_id']) ? (int) $_POST['note_id'] : 0;
if ($note_id > 0) {
    $stmt = $conn->prepare("UPDATE notifications
                            SET read_at = NOW()
                            WHERE id = ? AND user_id = ? AND read_at IS NULL");
    $stmt->bind_param('ii', $note_id, $_SESSION['user_id']);
    $stmt->execute();
    echo 'ok';
    exit;
}

// Marcar todas
$all = isset($_POST['all']) && $_POST['all'] == '1';
if ($all) {
    $stmt = $conn->prepare("UPDATE notifications
                            SET read_at = NOW()
                            WHERE user_id = ? AND read_at IS NULL");
    $stmt->bind_param('i', $_SESS
