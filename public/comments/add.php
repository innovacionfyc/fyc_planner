<?php
// public/comments/add.php
require_once __DIR__ . '/../_auth.php';
require_login();
require_once __DIR__ . '/../../config/db.php';

// CSRF
if (!isset($_POST['csrf'], $_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], $_POST['csrf'])) {
    header('Location: ../boards/index.php');
    exit;
}

$task_id = (int) ($_POST['task_id'] ?? 0);
$board_id = (int) ($_POST['board_id'] ?? 0);
$body = trim((string) ($_POST['texto_md'] ?? '')); // viene del textarea con name="texto_md"

if ($task_id <= 0 || $board_id <= 0 || $body === '') {
    header('Location: ../boards/index.php');
    exit;
}

// Verificar que el usuario pertenece al board
$chk = $conn->prepare("SELECT 1 FROM board_members WHERE board_id = ? AND user_id = ? LIMIT 1");
$chk->bind_param('ii', $board_id, $_SESSION['user_id']);
$chk->execute();
if (!$chk->get_result()->fetch_row()) {
    header('Location: ../boards/index.php');
    exit;
}

// Verificar que la tarea existe y pertenece a ese board
$ct = $conn->prepare("SELECT 1 FROM tasks WHERE id = ? AND board_id = ? LIMIT 1");
$ct->bind_param('ii', $task_id, $board_id);
$ct->execute();
if (!$ct->get_result()->fetch_row()) {
    header('Location: ../boards/index.php');
    exit;
}

// Insertar comentario (tu tabla usa: body, created_at)
$ins = $conn->prepare("INSERT INTO comments (board_id, task_id, user_id, body) VALUES (?, ?, ?, ?)");
$ins->bind_param('iiis', $board_id, $task_id, $_SESSION['user_id'], $body);
$ins->execute();

// Volver al detalle de la tarea
header('Location: ../tasks/view.php?id=' . $task_id);
exit;
