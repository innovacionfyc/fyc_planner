<?php
// public/tasks/move.php
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
$column_id = (int) ($_POST['column_id'] ?? 0);

if ($task_id <= 0 || $board_id <= 0 || $column_id <= 0) {
    respond(false, ['error' => 'bad_request'], 400);
}

$user_id = (int) ($_SESSION['user_id'] ?? 0);

// 1) Verificar que el usuario es miembro del board
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

// 2) Verificar que la tarea pertenece al board
$sql = "SELECT 1 FROM tasks WHERE id = ? AND board_id = ? LIMIT 1";
$stmt = $conn->prepare($sql);
if (!$stmt) {
    respond(false, ['error' => 'db_prepare_task_check'], 500);
}
$stmt->bind_param('ii', $task_id, $board_id);
$stmt->execute();
if (!$stmt->get_result()->fetch_row()) {
    respond(false, ['error' => 'task_not_found'], 404);
}

// 3) Verificar que la columna destino también es del mismo board
$sql = "SELECT 1 FROM columns WHERE id = ? AND board_id = ? LIMIT 1";
$stmt = $conn->prepare($sql);
if (!$stmt) {
    respond(false, ['error' => 'db_prepare_column_check'], 500);
}
$stmt->bind_param('ii', $column_id, $board_id);
$stmt->execute();
if (!$stmt->get_result()->fetch_row()) {
    respond(false, ['error' => 'column_not_found'], 404);
}

// 4) Actualizar (filtra por board_id)
$upd = $conn->prepare("UPDATE tasks SET column_id = ? WHERE id = ? AND board_id = ? LIMIT 1");
if (!$upd) {
    respond(false, ['error' => 'db_prepare_update'], 500);
}
$upd->bind_param('iii', $column_id, $task_id, $board_id);
if (!$upd->execute()) {
    respond(false, ['error' => 'db_execute_update'], 500);
}

// ------ Notificar movimiento (igual que tu código) ------
try {
    // Conseguir datos mínimos
    $taskTitle = 'Tarea';
    $taskQ = $conn->prepare("SELECT titulo FROM tasks WHERE id = ? LIMIT 1");
    if ($taskQ) {
        $taskQ->bind_param('i', $task_id);
        $taskQ->execute();
        $taskTitle = ($taskQ->get_result()->fetch_row()[0] ?? 'Tarea');
    }

    $colName = 'Columna';
    $colStmt = $conn->prepare("SELECT nombre FROM columns WHERE id = ? LIMIT 1");
    if ($colStmt) {
        $colStmt->bind_param('i', $column_id);
        $colStmt->execute();
        $colName = ($colStmt->get_result()->fetch_row()[0] ?? 'Columna');
    }

    $boardName = 'Board';
    $boardStmt = $conn->prepare("SELECT nombre FROM boards WHERE id = ? LIMIT 1");
    if ($boardStmt) {
        $boardStmt->bind_param('i', $board_id);
        $boardStmt->execute();
        $boardName = ($boardStmt->get_result()->fetch_row()[0] ?? 'Board');
    }

    $payload = json_encode([
        'board_id' => $board_id,
        'board_name' => $boardName,
        'task_id' => $task_id,
        'task_title' => $taskTitle,
        'column_id' => $column_id,
        'column_name' => $colName
    ], JSON_UNESCAPED_UNICODE);

    $m = $conn->prepare("SELECT user_id FROM board_members WHERE board_id = ? AND user_id <> ?");
    if ($m) {
        $m->bind_param('ii', $board_id, $user_id);
        $m->execute();
        $rows = $m->get_result()->fetch_all(MYSQLI_ASSOC);

        $insN = $conn->prepare("INSERT INTO notifications (user_id, tipo, payload_json) VALUES (?, 'task_moved', ?)");
        if ($insN) {
            foreach ($rows as $r) {
                $uid = (int) $r['user_id'];
                $insN->bind_param('is', $uid, $payload);
                $insN->execute();
            }
        }
    }

    // ------ ✅ Evento realtime con column_id correcto ------
    $ev = $conn->prepare("INSERT INTO board_events (board_id, kind, task_id, column_id, payload_json)
                          VALUES (?, 'task_moved', ?, ?, NULL)");
    if ($ev) {
        $ev->bind_param('iii', $board_id, $task_id, $column_id);
        $ev->execute();
    }
} catch (Throwable $e) {
    // silencio: notificaciones/realtime no deben tumbar la app
}

// Respuesta final según modo
if ($is_fetch) {
    respond(true, [
        'task_id' => $task_id,
        'board_id' => $board_id,
        'column_id' => $column_id
    ], 200);
}

// modo clásico
header("Location: ../boards/view.php?id={$board_id}");
exit;
