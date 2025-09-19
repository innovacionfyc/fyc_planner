<?php
// public/tasks/assign.php
require_once __DIR__ . '/../_auth.php';
require_login();
require_once __DIR__ . '/../../config/db.php';

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

// Validar que yo pertenezco al tablero
$chk = $conn->prepare("SELECT 1 FROM board_members WHERE board_id = ? AND user_id = ? LIMIT 1");
$chk->bind_param('ii', $board_id, $_SESSION['user_id']);
$chk->execute();
if (!$chk->get_result()->fetch_row()) {
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
$newAssignee = null;
$newName = '';
if ($assignee !== '') {
    $aid = (int) $assignee;
    if ($aid > 0) {
        // El responsable debe ser miembro del tablero
        $m = $conn->prepare("SELECT u.id, u.nombre FROM board_members bm JOIN users u ON u.id=bm.user_id
                         WHERE bm.board_id=? AND bm.user_id=? LIMIT 1");
        $m->bind_param('ii', $board_id, $aid);
        $m->execute();
        $row = $m->get_result()->fetch_assoc();
        if (!$row) {
            http_response_code(400);
            echo json_encode(['ok' => false]);
            exit;
        }
        $newAssignee = (int) $row['id'];
        $newName = (string) $row['nombre'];
    }
}

// Guardar
$upd = $conn->prepare("UPDATE tasks SET assignee_id = ? WHERE id = ? AND board_id = ? LIMIT 1");
if ($newAssignee === null) {
    // set NULL
    $null = null;
    $upd->bind_param('sii', $null, $task_id, $board_id); // 's' acepta null con mysqli si var es null
} else {
    $upd->bind_param('iii', $newAssignee, $task_id, $board_id);
}
$ok = $upd->execute();

// Notificar si cambió y hay nuevo responsable
if ($ok && $newAssignee && $newAssignee !== $oldAssignee) {
    $bq = $conn->prepare("SELECT nombre FROM boards WHERE id = ? LIMIT 1");
    $bq->bind_param('i', $board_id);
    $bq->execute();
    $boardName = ($bq->get_result()->fetch_row()[0] ?? 'Tablero');

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
