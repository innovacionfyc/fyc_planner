<?php
// public/tasks/update.php
require_once __DIR__ . '/../_auth.php';
require_login();
require_once __DIR__ . '/../../config/db.php';

if (!isset($_POST['csrf'], $_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], $_POST['csrf'])) {
    header('Location: ../boards/index.php');
    exit;
}

$task_id = (int) ($_POST['task_id'] ?? 0);
$board_id = (int) ($_POST['board_id'] ?? 0);
$prioridad = $_POST['prioridad'] ?? 'med';
$fecha_raw = trim($_POST['fecha_limite'] ?? '');
$descripcion = $_POST['descripcion_md'] ?? '';
$assignee_raw = $_POST['assignee_id'] ?? '';

if ($task_id <= 0 || $board_id <= 0) {
    header("Location: ../boards/index.php");
    exit;
}

// Validar membresía
$sql = "SELECT 1 FROM board_members WHERE board_id = ? AND user_id = ? LIMIT 1";
$stmt = $conn->prepare($sql);
$stmt->bind_param('ii', $board_id, $_SESSION['user_id']);
$stmt->execute();
if (!$stmt->get_result()->fetch_row()) {
    header("Location: ../boards/index.php");
    exit;
}

// Validar que la tarea existe y obtener asignado actual + título
$sql = "SELECT assignee_id, titulo FROM tasks WHERE id = ? AND board_id = ? LIMIT 1";
$stmt = $conn->prepare($sql);
$stmt->bind_param('ii', $task_id, $board_id);
$stmt->execute();
$cur = $stmt->get_result()->fetch_assoc();
if (!$cur) {
    header("Location: ../boards/index.php");
    exit;
}

$oldAssignee = $cur['assignee_id'] ? (int) $cur['assignee_id'] : null;
$taskTitle = $cur['titulo'] ?? 'Tarea';

// Normalizar fecha
$fecha = $fecha_raw !== '' ? $fecha_raw : NULL;

// Normalizar/validar responsable
$newAssignee = null;
if ($assignee_raw !== '') {
    $tmp = (int) $assignee_raw;
    if ($tmp > 0) {
        $chkA = $conn->prepare("SELECT 1 FROM board_members WHERE board_id = ? AND user_id = ? LIMIT 1");
        $chkA->bind_param('ii', $board_id, $tmp);
        $chkA->execute();
        if ($chkA->get_result()->fetch_row()) {
            $newAssignee = $tmp; // válido (miembro del tablero)
        }
    }
}

// Actualizar
$upd = $conn->prepare("UPDATE tasks
                       SET prioridad = ?, fecha_limite = ?, descripcion_md = ?, assignee_id = ?
                       WHERE id = ? AND board_id = ? LIMIT 1");
$upd->bind_param('sssiii', $prioridad, $fecha, $descripcion, $newAssignee, $task_id, $board_id);
$upd->execute();

// Notificar si cambió el responsable y hay uno nuevo
if ($newAssignee && $newAssignee !== $oldAssignee) {
    // Datos del board
    $boardStmt = $conn->prepare("SELECT nombre FROM boards WHERE id = ? LIMIT 1");
    $boardStmt->bind_param('i', $board_id);
    $boardStmt->execute();
    $boardName = ($boardStmt->get_result()->fetch_row()[0] ?? 'Tablero');

    $payload = json_encode([
        'board_id' => $board_id,
        'board_name' => $boardName,
        'task_id' => $task_id,
        'task_title' => $taskTitle
    ], JSON_UNESCAPED_UNICODE);

    $insN = $conn->prepare("INSERT INTO notifications (user_id, tipo, payload_json) VALUES (?, 'task_assigned', ?)");
    $insN->bind_param('is', $newAssignee, $payload);
    $insN->execute();
}

header('Location: view.php?id=' . $task_id);
exit;
