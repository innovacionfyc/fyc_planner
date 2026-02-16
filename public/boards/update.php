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
$nombre = trim((string) ($_POST['nombre'] ?? ''));
$color = trim((string) ($_POST['color_hex'] ?? ''));

if ($boardId <= 0 || $nombre === '') {
    $_SESSION['flash'] = ['type' => 'err', 'msg' => 'Datos incompletos para editar el tablero.'];
    header('Location: index.php');
    exit;
}

if ($color !== '' && !preg_match('/^#[0-9a-fA-F]{6}$/', $color)) {
    $color = '#d32f57';
}

// Verificar propietario
$chk = $conn->prepare("SELECT rol FROM board_members WHERE board_id = ? AND user_id = ? LIMIT 1");
$chk->bind_param('ii', $boardId, $userId);
$chk->execute();
$row = $chk->get_result()->fetch_assoc();

if (!$row || ($row['rol'] ?? '') !== 'propietario') {
    $_SESSION['flash'] = ['type' => 'err', 'msg' => 'No tienes permisos para editar este tablero (solo propietario).'];
    header('Location: index.php');
    exit;
}

$upd = $conn->prepare("UPDATE boards SET nombre = ?, color_hex = ? WHERE id = ? LIMIT 1");
$upd->bind_param('ssi', $nombre, $color, $boardId);
$ok = $upd->execute();

$_SESSION['flash'] = $ok
    ? ['type' => 'ok', 'msg' => 'Tablero actualizado correctamente.']
    : ['type' => 'err', 'msg' => 'No se pudo actualizar el tablero.'];

header('Location: index.php');
exit;
