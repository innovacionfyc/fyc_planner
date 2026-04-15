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

// Validar acceso al tablero (cubre equipo y personal)
require_once __DIR__ . '/../_perm.php';
if (!has_board_access($conn, $board_id, $user_id)) {
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

// Notificar al responsable de la tarea (si existe y no es el actor)
try {
    $tInfo = $conn->prepare("SELECT titulo, assignee_id FROM tasks WHERE id = ? LIMIT 1");
    if ($tInfo) {
        $tInfo->bind_param('i', $task_id);
        $tInfo->execute();
        $tRow = $tInfo->get_result()->fetch_assoc();
        $taskTitle   = $tRow['titulo'] ?? 'Tarea';
        $assigneeId  = $tRow['assignee_id'] ? (int) $tRow['assignee_id'] : null;

        $commenterName = $_SESSION['nombre'] ?? 'Alguien';

        $bInfo = $conn->prepare("SELECT nombre FROM boards WHERE id = ? LIMIT 1");
        if ($bInfo) {
            $bInfo->bind_param('i', $board_id);
            $bInfo->execute();
            $boardName = ($bInfo->get_result()->fetch_row()[0] ?? 'Tablero');
        } else {
            $boardName = 'Tablero';
        }

        $payload = json_encode([
            'board_id'       => $board_id,
            'board_name'     => $boardName,
            'task_id'        => $task_id,
            'task_title'     => $taskTitle,
            'commenter_name' => $commenterName,
            'assignee_id'    => $assigneeId,   // guardado para futura lógica de filtrado
        ], JSON_UNESCAPED_UNICODE);

        /*
         * REGLA ACTUAL: notificar a todos los miembros del tablero excepto el actor.
         * get_board_notification_recipients() usa team_members o board_members según el tipo de tablero.
         *
         * PUNTO DE EXTENSIÓN: para limitar en el futuro a responsable + participantes activos,
         * reemplazar $recipients por una lista filtrada aquí, sin tocar el resto del código.
         */
        $recipients = get_board_notification_recipients($conn, $board_id, $user_id);

        if ($recipients) {
            $insN = $conn->prepare(
                "INSERT INTO notifications (user_id, tipo, payload_json) VALUES (?, 'task_commented', ?)"
            );
            if ($insN) {
                foreach ($recipients as $uid) {
                    $insN->bind_param('is', $uid, $payload);
                    $insN->execute();
                }
            }
        }
    }
} catch (Throwable $e) {
    // no romper la app por una notificación fallida
}

respond(true, [
    'comment_id' => $comment_id,
    'task_id'    => $task_id,
    'board_id'   => $board_id,
], 200);