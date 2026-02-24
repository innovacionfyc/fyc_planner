<?php
// public/tasks/comment_create.php
require_once __DIR__ . '/../_auth.php';
require_login();
require_once __DIR__ . '/../../config/db.php';

// Detectar modo fetch (workspace/drawer)
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

    // fallback modo clásico
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

$user_id = (int) ($_SESSION['user_id'] ?? 0);
$task_id = (int) ($_POST['task_id'] ?? 0);
$board_id = (int) ($_POST['board_id'] ?? 0);
$body = trim((string) ($_POST['body'] ?? ''));

if ($user_id <= 0 || $task_id <= 0 || $board_id <= 0 || $body === '') {
    respond(false, ['error' => 'bad_request'], 400);
}

// Validar membresía al board
$mem = $conn->prepare("SELECT 1 FROM board_members WHERE board_id = ? AND user_id = ? LIMIT 1");
if (!$mem)
    respond(false, ['error' => 'db_prepare_membership'], 500);
$mem->bind_param('ii', $board_id, $user_id);
$mem->execute();
if (!$mem->get_result()->fetch_row()) {
    respond(false, ['error' => 'forbidden'], 403);
}

// Validar que la tarea pertenece al board
$tq = $conn->prepare("SELECT 1 FROM tasks WHERE id = ? AND board_id = ? LIMIT 1");
if (!$tq)
    respond(false, ['error' => 'db_prepare_task_check'], 500);
$tq->bind_param('ii', $task_id, $board_id);
$tq->execute();
if (!$tq->get_result()->fetch_row()) {
    respond(false, ['error' => 'task_not_found'], 404);
}

// Insertar comentario
$ins = $conn->prepare("INSERT INTO comments (task_id, user_id, body, created_at) VALUES (?, ?, ?, NOW())");
if (!$ins)
    respond(false, ['error' => 'db_prepare_insert'], 500);
$ins->bind_param('iis', $task_id, $user_id, $body);

if (!$ins->execute()) {
    respond(false, ['error' => 'db_execute_insert', 'detail' => $ins->error], 500);
}

$comment_id = (int) $ins->insert_id;

respond(true, [
    'comment_id' => $comment_id,
    'task_id' => $task_id,
    'board_id' => $board_id
], 200);