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

// Validar membresía
$sql = "SELECT 1 FROM board_members WHERE board_id = ? AND user_id = ? LIMIT 1";
$stmt = $conn->prepare($sql);
$stmt->bind_param('ii', $board_id, $_SESSION['user_id']);
$stmt->execute();
if (!$stmt->get_result()->fetch_row()) {
    header("Location: ../boards/view.php?id={$board_id}");
    exit;
}

// Validar que la tarea pertenece al board
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

/* ========= NUEVO: registrar evento realtime ========= */

// Obtener columna actual (para reubicar en el DOM si hace falta)
$colQ = $conn->prepare("SELECT column_id FROM tasks WHERE id = ? LIMIT 1");
$colQ->bind_param('i', $task_id);
$colQ->execute();
$col = (int) ($colQ->get_result()->fetch_row()[0] ?? 0);

// Insertar evento 'task_renamed'
$payload = json_encode(['title' => $titulo], JSON_UNESCAPED_UNICODE);
$ev = $conn->prepare(
    "INSERT INTO board_events (board_id, kind, task_id, column_id, payload_json)
     VALUES (?, 'task_renamed', ?, ?, ?)"
);
$ev->bind_param('iiis', $board_id, $task_id, $col, $payload);
$ev->execute();
/* ======== fin bloque nuevo ======== */

header("Location: ../boards/view.php?id={$board_id}");
exit;
