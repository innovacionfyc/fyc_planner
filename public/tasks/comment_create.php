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

// Detectar columnas reales en comments
$cols = [];
$rc = $conn->query("SHOW COLUMNS FROM comments");
if (!$rc)
    respond(false, ['error' => 'comments_table_missing'], 500);
while ($r = $rc->fetch_assoc()) {
    $cols[$r['Field']] = true;
}

$has_board_id = isset($cols['board_id']);
$has_created_at = isset($cols['created_at']);
$has_creado_en = isset($cols['creado_en']);
$has_created = isset($cols['created']);

// Detectar columna body
$bodyCol = isset($cols['body']) ? 'body' : (isset($cols['texto']) ? 'texto' : null);
if (!$bodyCol) {
    respond(false, ['error' => 'comments_body_column_missing'], 500);
}

// Armar INSERT dinámico según columnas
$fields = ['task_id', 'user_id', $bodyCol];
$placeholders = ['?', '?', '?'];
$types = 'iis';
$params = [$task_id, $user_id, $body];

if ($has_board_id) {
    $fields[] = 'board_id';
    $placeholders[] = '?';
    $types .= 'i';
    $params[] = $board_id;
}

// fecha automática si existe
if ($has_created_at) {
    $fields[] = 'created_at';
    $placeholders[] = 'NOW()';
} elseif ($has_creado_en) {
    $fields[] = 'creado_en';
    $placeholders[] = 'NOW()';
} elseif ($has_created) {
    $fields[] = 'created';
    $placeholders[] = 'NOW()';
}

$sql = "INSERT INTO comments (" . implode(',', $fields) . ") VALUES (" . implode(',', $placeholders) . ")";
$ins = $conn->prepare($sql);
if (!$ins)
    respond(false, ['error' => 'db_prepare_insert', 'detail' => $conn->error], 500);

// bind_param dinámico
$bind = [];
$bind[] = $types;
for ($i = 0; $i < count($params); $i++) {
    $bind[] = &$params[$i];
}
call_user_func_array([$ins, 'bind_param'], $bind);

if (!$ins->execute()) {
    respond(false, ['error' => 'db_execute_insert', 'detail' => $ins->error], 500);
}

$comment_id = (int) $ins->insert_id;

respond(true, [
    'comment_id' => $comment_id,
    'task_id' => $task_id,
    'board_id' => $board_id
], 200);