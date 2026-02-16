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

// ✅ FIX 1: estado es texto (aprobado/pendiente/rechazado)
if ($user['estado'] !== 'aprobado') {
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
$_SESSION['user_id'] = (int) $user['id'];
$_SESSION['nombre'] = $user['nombre'];
$_SESSION['email'] = $user['email'];
$_SESSION['rol'] = $user['rol'];

// ✅ FIX 2: quitar ultimo_login (si no existe en tabla, rompe)
// (Si después quieres, lo agregamos a la BD correctamente)

// (opcional) renovar token CSRF
$_SESSION['csrf'] = bin2hex(random_bytes(32));

// Redirigir a un panel temporal (lo reemplazamos pronto por Boards)
header('Location: app.php');
exit;
