<?php
// public/admin/user_action.php
require_once __DIR__ . '/../_auth.php';
require_admin();
require_once __DIR__ . '/../../config/db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: users_pending.php?err=1');
    exit;
}

if (!isset($_POST['csrf'], $_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], $_POST['csrf'])) {
    header('Location: users_pending.php?err=1');
    exit;
}

$user_id = (int) ($_POST['user_id'] ?? 0);
$action = $_POST['action'] ?? '';

if ($user_id <= 0 || !in_array($action, ['approve', 'reject', 'pend'], true)) {
    header('Location: users_pending.php?err=1');
    exit;
}

// Evitar auto-editarse
if ($user_id === (int) $_SESSION['user_id']) {
    header('Location: users_pending.php?err=1');
    exit;
}

if ($action === 'approve') {
    $estado = 'aprobado';
} elseif ($action === 'reject') {
    $estado = 'rechazado';
} else {
    $estado = 'pendiente';
}

$stmt = $conn->prepare("UPDATE users SET estado = ? WHERE id = ? LIMIT 1");
$stmt->bind_param('si', $estado, $user_id);
$ok = $stmt->execute();

header('Location: users_pending.php?' . ($ok ? 'ok=1' : 'err=1'));
exit;
