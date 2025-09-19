<?php
// public/comments/add.php
require_once __DIR__ . '/../_auth.php';
require_login();
require_once __DIR__ . '/../../config/db.php';

if (!isset($_POST['csrf'], $_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], $_POST['csrf'])) {
    header('Location: ../boards/index.php');
    exit;
}

$task_id = (int) ($_POST['task_id'] ?? 0);
$board_id = (int) ($_POST['board_id'] ?? 0);
$texto = trim($_POST['texto_md'] ?? '');

if ($task_id <= 0 || $board_id <= 0 || $texto === '') {
    header('Location: ../boards/index.php');
    exit;
}

// Validar membresía y que la tarea pertenece al board
$sql = "SELECT t.id FROM tasks t
        JOIN board_members bm ON bm.board_id = t.board_id AND bm.user_id = ?
        WHERE t.id = ? AND t.board_id = ? LIMIT 1";
$stmt = $conn->prepare($sql);
$stmt->bind_param('iii', $_SESSION['user_id'], $task_id, $board_id);
$stmt->execute();
if (!$stmt->get_result()->fetch_row()) {
    header('Location: ../boards/index.php');
    exit;
}

// Insertar comentario
$ins = $conn->prepare("INSERT INTO comments (task_id, user_id, texto_md) VALUES (?, ?, ?)");
$ins->bind_param('iis', $task_id, $_SESSION['user_id'], $texto);
$ins->execute();

header('Location: ../tasks/view.php?id=' . $task_id);
exit;
