<?php
// public/auth_register.php
session_start();
require_once __DIR__ . '/../config/db.php';

// CSRF
if (!isset($_POST['csrf'], $_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], $_POST['csrf'])) {
    header('Location: register.php?e=6');
    exit;
}

$nombre = trim($_POST['nombre'] ?? '');
$email = trim($_POST['email'] ?? '');
$pass1 = $_POST['password'] ?? '';
$pass2 = $_POST['password2'] ?? '';

if ($nombre === '' || $email === '' || $pass1 === '' || $pass2 === '') {
    header('Location: register.php?e=1');
    exit;
}

// Validar dominio
if (!preg_match('/@fycconsultores\.com$/i', $email)) {
    header('Location: register.php?e=2');
    exit;
}

// Validar password
if ($pass1 !== $pass2) {
    header('Location: register.php?e=3');
    exit;
}
if (strlen($pass1) < 8) {
    header('Location: register.php?e=4');
    exit;
}

// ¿Existe ya el correo?
$check = $conn->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
$check->bind_param('s', $email);
$check->execute();
if ($check->get_result()->fetch_row()) {
    header('Location: register.php?e=5');
    exit;
}

// Crear usuario pendiente (estado=0)
$hash = password_hash($pass1, PASSWORD_DEFAULT);
$ins = $conn->prepare("INSERT INTO users (nombre, email, pass_hash, rol, estado) VALUES (?, ?, ?, 'colaborador', 0)");
$ins->bind_param('sss', $nombre, $email, $hash);
if (!$ins->execute()) {
    header('Location: register.php?e=6');
    exit;
}

// Redirigir con OK
header('Location: register.php?ok=1');
exit;
