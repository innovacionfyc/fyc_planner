<?php
require_once __DIR__ . '/../_auth.php';
require_login();
require_once __DIR__ . '/../../config/db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php');
    exit;
}

if (!isset($_POST['csrf'], $_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], $_POST['csrf'])) {
    $_SESSION['flash'] = ['type' => 'err', 'msg' => 'CSRF inválido.'];
    header('Location: index.php');
    exit;
}

$userId = (int) ($_SESSION['user_id'] ?? 0);
$boardId = (int) ($_POST['board_id'] ?? 0);

if ($boardId <= 0) {
    $_SESSION['flash'] = ['type' => 'err', 'msg' => 'Tablero inválido.'];
    header('Location: index.php');
    exit;
}

// Verificar propietario
$chk = $conn->prepare("SELECT rol FROM board_members WHERE board_id = ? AND user_id = ? LIMIT 1");
$chk->bind_param('ii', $boardId, $userId);
$chk->execute();
$row = $chk->get_result()->fetch_assoc();

if (!$row || ($row['rol'] ?? '') !== 'propietario') {
    $_SESSION['flash'] = ['type' => 'err', 'msg' => 'No tienes permisos para eliminar este tablero (solo propietario).'];
    header('Location: index.php');
    exit;
}

$conn->begin_transaction();
try {
    // ✅ Por tus FKs con CASCADE, esto borra:
    // columns, tasks, comments, board_members, board_events, board_presence
    $del = $conn->prepare("DELETE FROM boards WHERE id = ? LIMIT 1");
    $del->bind_param('i', $boardId);
    $del->execute();

    $conn->commit();
    $_SESSION['flash'] = ['type' => 'ok', 'msg' => 'Tablero eliminado correctamente.'];
} catch (Throwable $e) {
    $conn->rollback();
    $_SESSION['flash'] = ['type' => 'err', 'msg' => 'No se pudo eliminar el tablero.'];
}

header('Location: index.php');
exit;
