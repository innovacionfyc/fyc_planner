<?php
// public/tasks/move.php
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
$column_id = (int) ($_POST['column_id'] ?? 0);

if ($task_id <= 0 || $board_id <= 0 || $column_id <= 0) {
    header("Location: ../boards/view.php?id={$board_id}");
    exit;
}

// 1) Verificar que el usuario es miembro del board
$sql = "SELECT 1 FROM board_members WHERE board_id = ? AND user_id = ? LIMIT 1";
$stmt = $conn->prepare($sql);
$stmt->bind_param('ii', $board_id, $_SESSION['user_id']);
$stmt->execute();
if (!$stmt->get_result()->fetch_row()) {
    header("Location: ../boards/view.php?id={$board_id}");
    exit;
}

// 2) Verificar que la tarea pertenece al board
$sql = "SELECT 1 FROM tasks WHERE id = ? AND board_id = ? LIMIT 1";
$stmt = $conn->prepare($sql);
$stmt->bind_param('ii', $task_id, $board_id);
$stmt->execute();
if (!$stmt->get_result()->fetch_row()) {
    header("Location: ../boards/view.php?id={$board_id}");
    exit;
}

// 3) Verificar que la columna destino también es del mismo board
$sql = "SELECT 1 FROM columns WHERE id = ? AND board_id = ? LIMIT 1";
$stmt = $conn->prepare($sql);
$stmt->bind_param('ii', $column_id, $board_id);
$stmt->execute();
if (!$stmt->get_result()->fetch_row()) {
    header("Location: ../boards/view.php?id={$board_id}");
    exit;
}

// 4) Actualizar
$upd = $conn->prepare("UPDATE tasks SET column_id = ? WHERE id = ? LIMIT 1");
$upd->bind_param('ii', $column_id, $task_id);
$upd->execute();

// Notificar movimiento
// Conseguir datos mínimos
$taskQ = $conn->prepare("SELECT titulo FROM tasks WHERE id = ? LIMIT 1");
$taskQ->bind_param('i', $task_id);
$taskQ->execute();
$taskTitle = ($taskQ->get_result()->fetch_row()[0] ?? 'Tarea');

$colStmt = $conn->prepare("SELECT nombre FROM columns WHERE id = ? LIMIT 1");
$colStmt->bind_param('i', $column_id);
$colStmt->execute();
$colName = ($colStmt->get_result()->fetch_row()[0] ?? 'Columna');

$boardStmt = $conn->prepare("SELECT nombre FROM boards WHERE id = ? LIMIT 1");
$boardStmt->bind_param('i', $board_id);
$boardStmt->execute();
$boardName = ($boardStmt->get_result()->fetch_row()[0] ?? 'Board');

$payload = json_encode([
    'board_id' => $board_id,
    'board_name' => $boardName,
    'task_id' => $task_id,
    'task_title' => $taskTitle,
    'column_id' => $column_id,
    'column_name' => $colName
], JSON_UNESCAPED_UNICODE);

$m = $conn->prepare("SELECT user_id FROM board_members WHERE board_id = ? AND user_id <> ?");
$m->bind_param('ii', $board_id, $_SESSION['user_id']);
$m->execute();
$rows = $m->get_result()->fetch_all(MYSQLI_ASSOC);

$insN = $conn->prepare("INSERT INTO notifications (user_id, tipo, payload_json) VALUES (?, 'task_moved', ?)");
foreach ($rows as $r) {
    $uid = (int) $r['user_id'];
    $insN->bind_param('is', $uid, $payload);
    $insN->execute();
}


header("Location: ../boards/view.php?id={$board_id}");
exit;
