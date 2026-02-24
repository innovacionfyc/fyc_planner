<?php
// public/tasks/update.php
require_once __DIR__ . '/../_auth.php';
require_login();
require_once __DIR__ . '/../../config/db.php';

// -----------------------------
// Detectar modo fetch (workspace/embed)
// -----------------------------
$sec_mode = strtolower($_SERVER['HTTP_SEC_FETCH_MODE'] ?? '');
$is_fetch = ($sec_mode !== '' && $sec_mode !== 'navigate');

if (
    (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
    || (isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false)
) {
    $is_fetch = true;
}

function respond(bool $ok, array $payload = [], int $http = 200): void
{
    global $is_fetch;

    if ($is_fetch) {
        http_response_code($http);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(array_merge(['ok' => $ok], $payload), JSON_UNESCAPED_UNICODE);
        exit;
    }

    if (!$ok) {
        header('Location: ../boards/index.php');
        exit;
    }
}

// Solo POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(false, ['error' => 'method_not_allowed'], 405);
}

// CSRF
if (!isset($_POST['csrf'], $_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], $_POST['csrf'])) {
    respond(false, ['error' => 'csrf'], 403);
}

$task_id = (int) ($_POST['task_id'] ?? 0);
$board_id = (int) ($_POST['board_id'] ?? 0);
$prioridad = trim((string) ($_POST['prioridad'] ?? 'med'));
$fecha_raw = trim((string) ($_POST['fecha_limite'] ?? ''));
$assignee_raw = (string) ($_POST['assignee_id'] ?? '');

if ($task_id <= 0 || $board_id <= 0) {
    respond(false, ['error' => 'bad_request'], 400);
}

$user_id = (int) ($_SESSION['user_id'] ?? 0);

// Validar membresía
$sql = "SELECT 1 FROM board_members WHERE board_id = ? AND user_id = ? LIMIT 1";
$stmt = $conn->prepare($sql);
if (!$stmt) {
    respond(false, ['error' => 'db_prepare_membership'], 500);
}
$stmt->bind_param('ii', $board_id, $user_id);
$stmt->execute();
if (!$stmt->get_result()->fetch_row()) {
    respond(false, ['error' => 'forbidden'], 403);
}

// Validar tarea
$sql = "SELECT assignee_id, titulo FROM tasks WHERE id = ? AND board_id = ? LIMIT 1";
$stmt = $conn->prepare($sql);
if (!$stmt) {
    respond(false, ['error' => 'db_prepare_task_check'], 500);
}
$stmt->bind_param('ii', $task_id, $board_id);
$stmt->execute();
$cur = $stmt->get_result()->fetch_assoc();
if (!$cur) {
    respond(false, ['error' => 'task_not_found'], 404);
}

$oldAssignee = !empty($cur['assignee_id']) ? (int) $cur['assignee_id'] : null;
$taskTitle = $cur['titulo'] ?? 'Tarea';

// Validar prioridad
$allowed = ['low', 'med', 'high', 'urgent'];
if (!in_array($prioridad, $allowed, true)) {
    $prioridad = 'med';
}

// Normalizar fecha
$fecha = null;
if ($fecha_raw !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha_raw)) {
    $fecha = $fecha_raw . ' 23:59:00';
}

// Validar responsable
$newAssignee = null;
if ($assignee_raw !== '') {
    $tmp = (int) $assignee_raw;
    if ($tmp > 0) {
        $chkA = $conn->prepare("SELECT 1 FROM board_members WHERE board_id = ? AND user_id = ? LIMIT 1");
        if ($chkA) {
            $chkA->bind_param('ii', $board_id, $tmp);
            $chkA->execute();
            if ($chkA->get_result()->fetch_row()) {
                $newAssignee = $tmp;
            }
        }
    }
}

// Update
$upd = $conn->prepare("
    UPDATE tasks
    SET prioridad = ?, fecha_limite = ?, assignee_id = ?
    WHERE id = ? AND board_id = ?
    LIMIT 1
");
if (!$upd) {
    respond(false, ['error' => 'db_prepare_update', 'detail' => $conn->error], 500);
}

$upd->bind_param('ssiii', $prioridad, $fecha, $newAssignee, $task_id, $board_id);

if (!$upd->execute()) {
    respond(false, ['error' => 'db_execute_update', 'detail' => $upd->error], 500);
}

// Notificación si cambia responsable
if ($newAssignee && $newAssignee !== $oldAssignee) {
    try {
        $boardName = 'Tablero';
        $boardStmt = $conn->prepare("SELECT nombre FROM boards WHERE id = ? LIMIT 1");
        if ($boardStmt) {
            $boardStmt->bind_param('i', $board_id);
            $boardStmt->execute();
            $rowB = $boardStmt->get_result()->fetch_row();
            $boardName = $rowB ? ($rowB[0] ?? 'Tablero') : 'Tablero';
        }

        $payload = json_encode([
            'board_id' => $board_id,
            'board_name' => $boardName,
            'task_id' => $task_id,
            'task_title' => $taskTitle
        ], JSON_UNESCAPED_UNICODE);

        $insN = $conn->prepare("INSERT INTO notifications (user_id, tipo, payload_json)
                                VALUES (?, 'task_assigned', ?)");
        if ($insN) {
            $insN->bind_param('is', $newAssignee, $payload);
            $insN->execute();
        }
    } catch (Throwable $e) {
        // no romper app por notificación
    }
}

// FETCH response
if ($is_fetch) {
    respond(true, [
        'task_id' => $task_id,
        'board_id' => $board_id
    ], 200);
}

// Modo clásico → volver al tablero
header('Location: ../boards/view.php?id=' . $board_id);
exit;