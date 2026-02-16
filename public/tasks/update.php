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
$prioridad = trim((string) ($_POST['prioridad'] ?? 'med'));
$fecha_raw = trim((string) ($_POST['fecha_limite'] ?? ''));
$assignee_raw = (string) ($_POST['assignee_id'] ?? ''); // puede venir ''

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

$oldAssignee = !empty($cur['assignee_id']) ? (int) $cur['assignee_id'] : null;
$taskTitle = $cur['titulo'] ?? 'Tarea';

// Validar prioridad contra enum real
$allowed = ['low', 'med', 'high', 'urgent'];
if (!in_array($prioridad, $allowed, true)) {
    $prioridad = 'med';
}

// Normalizar fecha: input date => guardamos datetime (fin del día)
$fecha = null;
if ($fecha_raw !== '') {
    // Esperamos YYYY-MM-DD
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha_raw)) {
        $fecha = $fecha_raw . ' 23:59:00';
    } else {
        // Si llega raro, lo anulamos para no romper
        $fecha = null;
    }
}

// Normalizar/validar responsable (solo si es miembro del board)
$newAssignee = null;
if ($assignee_raw !== '') {
    $tmp = (int) $assignee_raw;
    if ($tmp > 0) {
        $chkA = $conn->prepare("SELECT 1 FROM board_members WHERE board_id = ? AND user_id = ? LIMIT 1");
        $chkA->bind_param('ii', $board_id, $tmp);
        $chkA->execute();
        if ($chkA->get_result()->fetch_row()) {
            $newAssignee = $tmp;
        }
    }
}

// Actualizar (SIN descripcion_md porque tu tabla no la tiene)
$upd = $conn->prepare("UPDATE tasks
                       SET prioridad = ?, fecha_limite = ?, assignee_id = ?
                       WHERE id = ? AND board_id = ? LIMIT 1");
if (!$upd) {
    die("Error preparando UPDATE: " . $conn->error);
}

// prioridad(s), fecha_limite(s), assignee_id(i), id(i), board_id(i)
$upd->bind_param('ssiii', $prioridad, $fecha, $newAssignee, $task_id, $board_id);
$upd->execute();

// Notificar si cambió el responsable y hay uno nuevo
if ($newAssignee && $newAssignee !== $oldAssignee) {
    // Datos del board
    $boardStmt = $conn->prepare("SELECT nombre FROM boards WHERE id = ? LIMIT 1");
    $boardStmt->bind_param('i', $board_id);
    $boardStmt->execute();
    $rowB = $boardStmt->get_result()->fetch_row();
    $boardName = $rowB ? ($rowB[0] ?? 'Tablero') : 'Tablero';

    $payload = json_encode([
        'board_id' => $board_id,
        'board_name' => $boardName,
        'task_id' => $task_id,
        'task_title' => $taskTitle
    ], JSON_UNESCAPED_UNICODE);

    $insN = $conn->prepare("INSERT INTO notifications (user_id, tipo, payload_json) VALUES (?, 'task_assigned', ?)");
    if ($insN) {
        $insN->bind_param('is', $newAssignee, $payload);
        $insN->execute();
    }
}

header('Location: view.php?id=' . $task_id);
exit;
