<?php
// public/tasks/create.php
require_once __DIR__ . '/../_auth.php';
require_login();
require_once __DIR__ . '/../../config/db.php';

// Validar CSRF
if (!isset($_POST['csrf'], $_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], $_POST['csrf'])) {
    header('Location: ../boards/index.php');
    exit;
}

$board_id = isset($_POST['board_id']) ? (int) $_POST['board_id'] : 0;
$column_id = isset($_POST['column_id']) ? (int) $_POST['column_id'] : 0;
$titulo = trim($_POST['titulo'] ?? '');

if ($board_id <= 0 || $column_id <= 0 || $titulo === '') {
    header('Location: ../boards/index.php');
    exit;
}

// Verificar que el usuario actual es miembro del board
$sql = "SELECT 1 FROM board_members WHERE board_id = ? AND user_id = ? LIMIT 1";
$stmt = $conn->prepare($sql);
$stmt->bind_param('ii', $board_id, $_SESSION['user_id']);
$stmt->execute();
$is_member = $stmt->get_result()->fetch_row();

if (!$is_member) {
    header('Location: ../boards/index.php');
    exit;
}

// Insertar la tarea (solo título por ahora)
$stmt = $conn->prepare("INSERT INTO tasks (board_id, column_id, titulo, creador_id) VALUES (?, ?, ?, ?)");
$stmt->bind_param('iisi', $board_id, $column_id, $titulo, $_SESSION['user_id']);
$stmt->execute();

// $task_id ya existe, $board_id y $column_id del form
$ev = $conn->prepare("INSERT INTO board_events (board_id, kind, task_id, column_id, payload_json)
                      VALUES (?, 'task_created', ?, ?, JSON_OBJECT('title', ?))");
$ev->bind_param('iiis', $board_id, $task_id, $column_id, $titulo);
$ev->execute();


// Notificar a demás miembros del board
// Traer nombre de columna y de board, y título de tarea
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
    'task_id' => $stmt->insert_id,
    'task_title' => $titulo,
    'column_id' => $column_id,
    'column_name' => $colName
], JSON_UNESCAPED_UNICODE);

// A todos menos a mí
$m = $conn->prepare("SELECT user_id FROM board_members WHERE board_id = ? AND user_id <> ?");
$m->bind_param('ii', $board_id, $_SESSION['user_id']);
$m->execute();
$rows = $m->get_result()->fetch_all(MYSQLI_ASSOC);

$insN = $conn->prepare("INSERT INTO notifications (user_id, tipo, payload_json) VALUES (?, 'task_created', ?)");
foreach ($rows as $r) {
    $uid = (int) $r['user_id'];
    $insN->bind_param('is', $uid, $payload);
    $insN->execute();
}


header('Location: ../boards/view.php?id=' . $board_id);
exit;
