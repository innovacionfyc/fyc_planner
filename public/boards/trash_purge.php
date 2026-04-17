<?php
require_once __DIR__ . '/../_auth.php';
require_login();
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../_perm.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ./trash.php');
    exit;
}

if (!isset($_POST['csrf'], $_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], $_POST['csrf'])) {
    $_SESSION['flash'] = ['type' => 'err', 'msg' => 'CSRF inválido.'];
    header('Location: ./trash.php');
    exit;
}

$userId  = (int)($_SESSION['user_id'] ?? 0);
$boardId = (int)($_POST['board_id'] ?? 0);

if ($boardId <= 0) {
    $_SESSION['flash'] = ['type' => 'err', 'msg' => 'Tablero inválido.'];
    header('Location: ./trash.php');
    exit;
}

// Verificar que el tablero está en papelera
$chk = $conn->prepare("SELECT id FROM boards WHERE id = ? AND deleted_at IS NOT NULL LIMIT 1");
$chk->bind_param('i', $boardId);
$chk->execute();
$exists = $chk->get_result()->fetch_assoc();
$chk->close();
if (!$exists) {
    $_SESSION['flash'] = ['type' => 'err', 'msg' => 'El tablero no está en la papelera.'];
    header('Location: ./trash.php');
    exit;
}

// Permisos: solo propietario o super_admin (no admin_equipo)
if (!can_purge_board($conn, $boardId, $userId)) {
    $_SESSION['flash'] = ['type' => 'err', 'msg' => 'No tienes permisos para eliminar definitivamente este tablero.'];
    header('Location: ./trash.php');
    exit;
}

$conn->begin_transaction();
try {
    // AND deleted_at IS NOT NULL es el seguro absoluto:
    // nunca borra un tablero activo aunque board_id sea manipulado.
    $del = $conn->prepare(
        "DELETE FROM boards WHERE id = ? AND deleted_at IS NOT NULL LIMIT 1"
    );
    $del->bind_param('i', $boardId);
    $del->execute();

    if ($del->affected_rows === 0) {
        throw new RuntimeException('No se eliminó ningún registro.');
    }
    $del->close();

    $conn->commit();
    $_SESSION['flash'] = ['type' => 'ok', 'msg' => 'Tablero eliminado definitivamente.'];
} catch (Throwable $e) {
    $conn->rollback();
    $_SESSION['flash'] = ['type' => 'err', 'msg' => 'No se pudo eliminar el tablero definitivamente.'];
}

header('Location: ./trash.php');
exit;
