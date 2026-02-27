<?php
// public/auth_login.php
session_start();
require_once __DIR__ . '/../config/db.php';

// Validar CSRF
if (!isset($_POST['csrf'], $_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], $_POST['csrf'])) {
    header('Location: login.php?e=4');
    exit;
}

// Validar campos
$email = isset($_POST['email']) ? trim($_POST['email']) : '';
$pass = $_POST['password'] ?? '';

if ($email === '' || $pass === '') {
    header('Location: login.php?e=2');
    exit;
}

// Buscar usuario por email
$stmt = $conn->prepare("SELECT id, nombre, email, pass_hash, rol, estado FROM users WHERE email = ? LIMIT 1");
$stmt->bind_param('s', $email);
$stmt->execute();
$res = $stmt->get_result();
$user = $res->fetch_assoc();

if (!$user) {
    header('Location: login.php?e=1');
    exit;
}

// estado es texto (aprobado/pendiente/rechazado)
if (($user['estado'] ?? '') !== 'aprobado') {
    header('Location: login.php?e=3');
    exit;
}

// Verificar contraseña
if (!password_verify($pass, $user['pass_hash'])) {
    header('Location: login.php?e=1');
    exit;
}

// OK: crear sesión
session_regenerate_id(true);

// Claves "nuevas" (las que usan tus boards/workspace/view/_auth)
$_SESSION['user_id'] = (int) $user['id'];
$_SESSION['user_nombre'] = (string) $user['nombre'];
$_SESSION['user_email'] = (string) $user['email'];
$_SESSION['user_rol'] = (string) $user['rol'];

// Claves "legacy" (por compatibilidad con pantallas viejas que aún lean estas)
$_SESSION['nombre'] = (string) $user['nombre'];
$_SESSION['email'] = (string) $user['email'];
$_SESSION['rol'] = (string) $user['rol'];

// Renovar token CSRF
$_SESSION['csrf'] = bin2hex(random_bytes(32));

// ✅ Redirigir al Workspace (modo Notion)
header('Location: boards/workspace.php');
exit;