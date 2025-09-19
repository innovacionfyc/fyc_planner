<?php
// public/tasks/rename.php
require_once __DIR__ . '/../_auth.php';
require_login();
require_once __DIR__ . '/../../config/db.php';

if (!isset($_POST['csrf'], $_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], $_POST['csrf'])) {
    header('Location: ../boards/index.php');
    exit;
}

$task_id = (int) ($_POST['task_id'] ?? 0);
$board_id = (int) ($_POST['board_id'] ?? 0);
$titulo = trim($_POST['titulo'] ?? '');

if ($task_id <= 0 || $board_id <= 0 || $titulo === '') {
    header("Location: ../boards/view.php?id={$board_id}");
    exit;
}

// Miembro del board
$sql = "SELECT 1 FROM board_members WHERE board_id = ? AND user_id = ? LIMIT 1";
$stmt = $conn->prepare($sql);
$stmt->bind_param('ii', $board_id, $_SESSION['user_id']);
$stmt->execute();
if (!$stmt->get_result()->fetch_row()) {
    header("Location: ../boards/view.php?id={$board_id}");
    exit;
}

// Tarea del board
$sql = "SELECT 1 FROM tasks WHERE id = ? AND board_id = ? LIMIT 1";
$stmt = $conn->prepare($sql);
$stmt->bind_param('ii', $task_id, $board_id);
$stmt->execute();
if (!$stmt->get_result()->fetch_row()) {
    header("Location: ../boards/view.php?id={$board_id}");
    exit;
}

// Renombrar
$upd = $conn->prepare("UPDATE tasks SET titulo = ? WHERE id = ? LIMIT 1");
$upd->bind_param('si', $titulo, $task_id);
$upd->execute();

header("Location: ../boards/view.php?id={$board_id}");
exit;
