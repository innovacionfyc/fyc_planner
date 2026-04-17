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

// Permisos: propietario + admin_equipo + super_admin
if (!can_manage_board($conn, $boardId, $userId)) {
    $_SESSION['flash'] = ['type' => 'err', 'msg' => 'No tienes permisos para restaurar este tablero.'];
    header('Location: ./trash.php');
    exit;
}

$up = $conn->prepare(
    "UPDATE boards SET deleted_at = NULL, deleted_by = NULL WHERE id = ? AND deleted_at IS NOT NULL LIMIT 1"
);
$up->bind_param('i', $boardId);

if ($up->execute() && $up->affected_rows > 0) {
    $_SESSION['flash'] = ['type' => 'ok', 'msg' => 'Tablero restaurado correctamente.'];
} else {
    $_SESSION['flash'] = ['type' => 'err', 'msg' => 'No se pudo restaurar el tablero.'];
}
$up->close();

header('Location: ./trash.php');
exit;
