<?php
// public/admin/user_password_reset.php
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

$target_user_id = (int) ($_POST['user_id'] ?? 0);
if ($target_user_id <= 0) {
    header('Location: users_pending.php?err=1');
    exit;
}

// Evitar resetearse a sí mismo (para no bloquearse por accidente)
if ($target_user_id === (int) ($_SESSION['user_id'] ?? 0)) {
    header('Location: users_pending.php?err=1');
    exit;
}

// Verificar SUPER ADMIN (fuente: BD)
$meId = (int) ($_SESSION['user_id'] ?? 0);
$isSuper = false;

$me = $conn->prepare("SELECT is_admin, rol FROM users WHERE id = ? LIMIT 1");
if ($me) {
    $me->bind_param('i', $meId);
    $me->execute();
    $row = $me->get_result()->fetch_assoc();
    $isSuper = ((int) ($row['is_admin'] ?? 0) === 1) && (($row['rol'] ?? '') === 'super_admin');
}

if (!$isSuper) {
    header('Location: users_pending.php?err=1');
    exit;
}

// Confirmar usuario existe
$u = $conn->prepare("SELECT id, nombre, email FROM users WHERE id = ? LIMIT 1");
if (!$u) {
    header('Location: users_pending.php?err=1');
    exit;
}
$u->bind_param('i', $target_user_id);
$u->execute();
$user = $u->get_result()->fetch_assoc();
if (!$user) {
    header('Location: users_pending.php?err=1');
    exit;
}

// Generar contraseña temporal
function gen_temp_password(int $len = 12): string
{
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789';
    $out = '';
    for ($i = 0; $i < $len; $i++) {
        $out .= $alphabet[random_int(0, strlen($alphabet) - 1)];
    }
    return $out;
}

$temp = gen_temp_password(12);
$hash = password_hash($temp, PASSWORD_DEFAULT);

$upd = $conn->prepare("UPDATE users SET pass_hash = ? WHERE id = ? LIMIT 1");
if (!$upd) {
    header('Location: users_pending.php?err=1');
    exit;
}
$upd->bind_param('si', $hash, $target_user_id);

if (!$upd->execute()) {
    header('Location: users_pending.php?err=1');
    exit;
}

// Flash (mostrar 1 vez)
$_SESSION['admin_pw_reset'] = [
    'user_id' => (int) $user['id'],
    'nombre' => (string) ($user['nombre'] ?? ''),
    'email' => (string) ($user['email'] ?? ''),
    'temp' => $temp,
    'ts' => time(),
];

header('Location: users_pending.php?ok=1');
exit;