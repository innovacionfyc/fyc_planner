<?php
// public/comments/mark_read.php  (nota: esto marca notificaciones como leídas)
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
    // Schema nuevo: read_at (NULL = no leído)
    $stmt = $conn->prepare("UPDATE notifications SET read_at = NOW() WHERE user_id = ? AND read_at IS NULL");
    $stmt->bind_param('i', $_SESSION['user_id']);
    $stmt->execute();
    echo 'ok';
    exit;
}

// Si en el futuro mandas ids[], aquí los marcaríamos.
// Por ahora responde ok para no romper llamadas viejas.
echo 'ok';
