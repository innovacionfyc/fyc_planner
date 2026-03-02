<?php
require_once __DIR__ . '/../_auth.php';
require_login();
require_once __DIR__ . '/../../config/db.php';
// ===== Return helper (workspace o index) =====
$return = $_GET['return'] ?? $_POST['return'] ?? '';
$return = strtolower(trim($return));

$RETURN_URL = ($return === 'workspace')
    ? './workspace.php'
    : './index.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . $RETURN_URL);
    exit;
}

if (!isset($_POST['csrf'], $_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], $_POST['csrf'])) {
    $_SESSION['flash'] = ['type' => 'err', 'msg' => 'CSRF inválido.'];
    header('Location: ' . $RETURN_URL);
    exit;
}

$userId = (int) ($_SESSION['user_id'] ?? 0);
$boardId = (int) ($_POST['board_id'] ?? 0);

if ($boardId <= 0) {
    $_SESSION['flash'] = ['type' => 'err', 'msg' => 'Tablero inválido.'];
    header('Location: ' . $RETURN_URL);
    exit;
}

// Verificar propietario
$chk = $conn->prepare("SELECT rol FROM board_members WHERE board_id = ? AND user_id = ? LIMIT 1");
$chk->bind_param('ii', $boardId, $userId);
$chk->execute();
$row = $chk->get_result()->fetch_assoc();

if (!$row || ($row['rol'] ?? '') !== 'propietario') {
    $_SESSION['flash'] = ['type' => 'err', 'msg' => 'No tienes permisos para eliminar este tablero (solo propietario).'];
    header('Location: ' . $RETURN_URL);
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

header('Location: ' . $RETURN_URL);
exit;
