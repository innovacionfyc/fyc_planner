<?php
// public/tasks/comment_create.php
require_once __DIR__ . '/../_auth.php';
require_login();
require_once __DIR__ . '/../../config/db.php';

// -----------------------------
// Detectar modo fetch (workspace/drawer)
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

    http_response_code($http);

    if ($is_fetch) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(array_merge(['ok' => $ok], $payload), JSON_UNESCAPED_UNICODE);
        exit;
    }

    // fallback modo clásico
    if (!$ok) {
        header('Location: ../boards/index.php');
        exit;
    }

    // si ok, vuelve al tablero (o donde quieras)
    header('Location: ../boards/index.php');
    exit;
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

// Verificar que exista tabla comments
$t = $conn->query("SHOW TABLES LIKE 'comments'");
if (!$t || !$t->fetch_row()) {
    respond(false, ['error' => 'comments_table_missing'], 500);
}

// Detectar columnas reales en comments
$cols = [];
$rc = $conn->query("SHOW COLUMNS FROM comments");
if ($rc) {
    while ($r = $rc->fetch_assoc()) {
        $cols[$r['Field']] = true;
    }
}

// body/texto
$bodyCol = isset($cols['body']) ? 'body' : (isset($cols['texto']) ? 'texto' : null);
if (!$bodyCol) {
    respond(false, ['error' => 'comments_body_column_missing'], 500);
}

// created_at/creado_en/created (si no hay, no la ponemos en el INSERT)
$dateCol = isset($cols['created_at']) ? 'created_at'
    : (isset($cols['creado_en']) ? 'creado_en'
        : (isset($cols['created']) ? 'created' : null));

// board_id (algunas tablas lo exigen)
$hasBoardCol = isset($cols['board_id']);

// -----------------------------
// Validar membresía al board
// -----------------------------
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

// -----------------------------
// INSERT dinámico según columnas reales
// -----------------------------
if ($hasBoardCol && $dateCol) {
    $sql = "INSERT INTO comments (board_id, task_id, user_id, $bodyCol, $dateCol) VALUES (?, ?, ?, ?, NOW())";
    $ins = $conn->prepare($sql);
    if (!$ins)
        respond(false, ['error' => 'db_prepare_insert', 'detail' => $conn->error], 500);
    $ins->bind_param('iiis', $board_id, $task_id, $user_id, $body);
} elseif ($hasBoardCol && !$dateCol) {
    $sql = "INSERT INTO comments (board_id, task_id, user_id, $bodyCol) VALUES (?, ?, ?, ?)";
    $ins = $conn->prepare($sql);
    if (!$ins)
        respond(false, ['error' => 'db_prepare_insert', 'detail' => $conn->error], 500);
    $ins->bind_param('iiis', $board_id, $task_id, $user_id, $body);
} elseif (!$hasBoardCol && $dateCol) {
    $sql = "INSERT INTO comments (task_id, user_id, $bodyCol, $dateCol) VALUES (?, ?, ?, NOW())";
    $ins = $conn->prepare($sql);
    if (!$ins)
        respond(false, ['error' => 'db_prepare_insert', 'detail' => $conn->error], 500);
    $ins->bind_param('iis', $task_id, $user_id, $body);
} else {
    $sql = "INSERT INTO comments (task_id, user_id, $bodyCol) VALUES (?, ?, ?)";
    $ins = $conn->prepare($sql);
    if (!$ins)
        respond(false, ['error' => 'db_prepare_insert', 'detail' => $conn->error], 500);
    $ins->bind_param('iis', $task_id, $user_id, $body);
}

if (!$ins->execute()) {
    respond(false, ['error' => 'db_execute_insert', 'detail' => $ins->error], 500);
}

$comment_id = (int) $ins->insert_id;

respond(true, [
    'comment_id' => $comment_id,
    'task_id' => $task_id,
    'board_id' => $board_id
], 200);