<?php
// public/tasks/assign.php
require_once __DIR__ . '/../_auth.php';
require_login();
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../_perm.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false]);
    exit;
}
if (!isset($_POST['csrf'], $_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], $_POST['csrf'])) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'msg' => 'CSRF']);
    exit;
}

$task_id = (int) ($_POST['task_id'] ?? 0);
$board_id = (int) ($_POST['board_id'] ?? 0);
$assignee = trim($_POST['assignee_id'] ?? ''); // '' = sin asignar

if ($task_id <= 0 || $board_id <= 0) {
    http_response_code(400);
    echo json_encode(['ok' => false]);
    exit;
}

// Validar permisos de escritura en el tablero
$current_user_id = (int) ($_SESSION['user_id'] ?? 0);
if (!can_write_board($conn, $board_id, $current_user_id)) {
    http_response_code(403);
    echo json_encode(['ok' => false]);
    exit;
}

// Tarea actual (para comparar y notificar)
$q = $conn->prepare("SELECT assignee_id, titulo FROM tasks WHERE id = ? AND board_id = ? LIMIT 1");
$q->bind_param('ii', $task_id, $board_id);
$q->execute();
$cur = $q->get_result()->fetch_assoc();
if (!$cur) {
    http_response_code(404);
    echo json_encode(['ok' => false]);
    exit;
}
$oldAssignee = $cur['assignee_id'] ? (int) $cur['assignee_id'] : null;
$taskTitle = $cur['titulo'] ?? 'Tarea';

// Normalizar nuevo responsable (o NULL)
// Validar y resolver el nuevo responsable.
// is_valid_assignee() cubre tableros de equipo (team_members) y personales (board_members).
$newAssignee = null;
$newName = '';
if ($assignee !== '') {
    $aid = (int) $assignee;
    if ($aid > 0) {
        if (!is_valid_assignee($conn, $board_id, $aid)) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'invalid_assignee']);
            exit;
        }
        $uq = $conn->prepare("SELECT id, nombre FROM users WHERE id = ? LIMIT 1");
        $uq->bind_param('i', $aid);
        $uq->execute();
        $row = $uq->get_result()->fetch_assoc();
        if ($row) {
            $newAssignee = (int) $row['id'];
            $newName = (string) $row['nombre'];
        }
    }
}

// Guardar
if ($newAssignee === null) {
    $upd = $conn->prepare("UPDATE tasks SET assignee_id = NULL WHERE id = ? AND board_id = ? LIMIT 1");
    $upd->bind_param('ii', $task_id, $board_id);
} else {
    $upd = $conn->prepare("UPDATE tasks SET assignee_id = ? WHERE id = ? AND board_id = ? LIMIT 1");
    $upd->bind_param('iii', $newAssignee, $task_id, $board_id);
}
$ok = $upd->execute();

// Notificar si cambió y hay nuevo responsable
if ($ok && $newAssignee && $newAssignee !== $oldAssignee) {
    $bq = $conn->prepare("SELECT nombre FROM boards WHERE id = ? LIMIT 1");
    $bq->bind_param('i', $board_id);
    $bq->execute();
    $boardName = ($bq->get_result()->fetch_row()[0] ?? 'Tablero');

    // Nombre del responsable anterior (si existía)
    $oldAssigneeName = '';
    if ($oldAssignee) {
        $oq = $conn->prepare("SELECT nombre FROM users WHERE id = ? LIMIT 1");
        $oq->bind_param('i', $oldAssignee);
        $oq->execute();
        $oldAssigneeName = ($oq->get_result()->fetch_row()[0] ?? '');
    }

    $payload = json_encode([
        'board_id'          => $board_id,
        'board_name'        => $boardName,
        'task_id'           => $task_id,
        'task_title'        => $taskTitle,
        'new_assignee_name' => $newName,
        'old_assignee_name' => $oldAssigneeName,
    ], JSON_UNESCAPED_UNICODE);

    $insN = $conn->prepare("INSERT INTO notifications (user_id, tipo, payload_json) VALUES (?, 'task_assignee_changed', ?)");
    $insN->bind_param('is', $newAssignee, $payload);
    $insN->execute();
}

$firstName = '';
if ($newName !== '') {
    $firstName = explode(' ', $newName)[0];
}

// evento realtime
$colQ = $conn->prepare("SELECT column_id FROM tasks WHERE id=? LIMIT 1");
$colQ->bind_param('i', $task_id);
$colQ->execute();
$col = (int) ($colQ->get_result()->fetch_row()[0] ?? 0);

$ev = $conn->prepare("INSERT INTO board_events (board_id, kind, task_id, column_id, payload_json)
                      VALUES (?, 'task_assigned', ?, ?, JSON_OBJECT('assignee_first', ?))");
$af = $firstName; // de tu código
$ev->bind_param('iiis', $board_id, $task_id, $col, $af);
$ev->execute();


echo json_encode(['ok' => true, 'assignee_first' => $firstName]);
