<?php
// public/tasks/delete.php
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

if ($task_id <= 0 || $board_id <= 0) {
    header("Location: ../boards/view.php?id={$board_id}");
    exit;
}

// 1) Validar membresía al board
$sql = "SELECT 1 FROM board_members WHERE board_id = ? AND user_id = ? LIMIT 1";
$stmt = $conn->prepare($sql);
$stmt->bind_param('ii', $board_id, $_SESSION['user_id']);
$stmt->execute();
if (!$stmt->get_result()->fetch_row()) {
    header("Location: ../boards/view.php?id={$board_id}");
    exit;
}

// 2) Validar que la tarea pertenece al board y obtener datos
$q = $conn->prepare("SELECT titulo, column_id FROM tasks WHERE id = ? AND board_id = ? LIMIT 1");
$q->bind_param('ii', $task_id, $board_id);
$q->execute();
$row = $q->get_result()->fetch_assoc();
if (!$row) {
    header("Location: ../boards/view.php?id={$board_id}");
    exit;
}
$taskTitle = $row['titulo'] ?? 'Tarea';
$colId = (int) ($row['column_id'] ?? 0);

// 3) Borrar comentarios (si aplica) y tarea
// Si tu tabla comments no existe o no tiene task_id, comenta este bloque.
$delC = $conn->prepare("DELETE FROM comments WHERE task_id = ?");
if ($delC) {
    $delC->bind_param('i', $task_id);
    $delC->execute();
}

$delT = $conn->prepare("DELETE FROM tasks WHERE id = ? AND board_id = ? LIMIT 1");
$delT->bind_param('ii', $task_id, $board_id);
$delT->execute();

// 4) Evento realtime (opcional pero recomendado)
$payload = json_encode(['task_title' => $taskTitle], JSON_UNESCAPED_UNICODE);
$ev = $conn->prepare(
    "INSERT INTO board_events (board_id, kind, task_id, column_id, payload_json)
     VALUES (?, 'task_deleted', ?, ?, ?)"
);
if ($ev) {
    $ev->bind_param('iiis', $board_id, $task_id, $colId, $payload);
    $ev->execute();
}

header("Location: ../boards/view.php?id={$board_id}");
exit;
