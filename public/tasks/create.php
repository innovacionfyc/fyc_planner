<?php
// public/tasks/create.php
require_once __DIR__ . '/../_auth.php';
require_login();
require_once __DIR__ . '/../../config/db.php';

/**
 * Detecta si esto viene por fetch (workspace/embed) vs submit normal.
 * - Form submit normal suele traer: Sec-Fetch-Mode: navigate
 * - fetch() suele traer: Sec-Fetch-Mode: cors
 */
$sec_mode = strtolower($_SERVER['HTTP_SEC_FETCH_MODE'] ?? '');
$is_fetch = ($sec_mode !== '' && $sec_mode !== 'navigate');

// Si además algún día mandas headers, también lo detectamos (no estorba)
if (
    (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
    || (isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false)
) {
    $is_fetch = true;
}

// Helper de respuesta según modo
function respond($ok, $payload = [], $http = 200)
{
    global $is_fetch;

    if ($is_fetch) {
        http_response_code($http);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(array_merge(['ok' => (bool) $ok], $payload), JSON_UNESCAPED_UNICODE);
        exit;
    }

    // modo clásico: redirigir
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

$board_id = (int) ($_POST['board_id'] ?? 0);
$column_id = (int) ($_POST['column_id'] ?? 0);
$titulo = trim($_POST['titulo'] ?? '');

if ($board_id <= 0 || $column_id <= 0 || $titulo === '') {
    respond(false, ['error' => 'bad_request'], 400);
}

// Validar que pertenezco al board
$chk = $conn->prepare("SELECT 1 FROM board_members WHERE board_id = ? AND user_id = ? LIMIT 1");
if (!$chk) {
    respond(false, ['error' => 'db_prepare'], 500);
}
$uid = (int) ($_SESSION['user_id'] ?? 0);
$chk->bind_param('ii', $board_id, $uid);
$chk->execute();
if (!$chk->get_result()->fetch_row()) {
    respond(false, ['error' => 'forbidden'], 403);
}

// Detectar columnas reales de tasks (para no asumir schema)
$cols = [];
$resCols = $conn->query("SHOW COLUMNS FROM tasks");
if ($resCols) {
    while ($r = $resCols->fetch_assoc()) {
        $cols[$r['Field']] = true;
    }
}

// Helper bind_param dinámico
function bind_params_dynamic(mysqli_stmt $stmt, string $types, array &$vars): void
{
    $refs = [];
    foreach ($vars as $k => &$v)
        $refs[$k] = &$v;
    array_unshift($refs, $types);
    call_user_func_array([$stmt, 'bind_param'], $refs);
}

// Construir INSERT dinámico según columnas existentes
$fields = [];
$placeholders = [];
$types = '';
$values = [];

// obligatorias (casi seguro existen)
if (isset($cols['board_id'])) {
    $fields[] = 'board_id';
    $placeholders[] = '?';
    $types .= 'i';
    $values[] = $board_id;
}
if (isset($cols['column_id'])) {
    $fields[] = 'column_id';
    $placeholders[] = '?';
    $types .= 'i';
    $values[] = $column_id;
}
if (isset($cols['titulo'])) {
    $fields[] = 'titulo';
    $placeholders[] = '?';
    $types .= 's';
    $values[] = $titulo;
} elseif (isset($cols['title'])) { // por si el schema viejo usa "title"
    $fields[] = 'title';
    $placeholders[] = '?';
    $types .= 's';
    $values[] = $titulo;
}

// opcionales comunes
if (isset($cols['prioridad'])) {
    $prio = trim($_POST['prioridad'] ?? 'med');
    $fields[] = 'prioridad';
    $placeholders[] = '?';
    $types .= 's';
    $values[] = $prio;
}

if (isset($cols['fecha_limite'])) {
    $fecha = trim($_POST['fecha_limite'] ?? '');
    $fecha = ($fecha === '') ? null : $fecha; // permite NULL
    $fields[] = 'fecha_limite';
    $placeholders[] = '?';
    $types .= 's';
    $values[] = $fecha;
}

// creador (varios nombres posibles)
$creatorCandidates = ['creator_id', 'created_by', 'user_id', 'creador_id', 'creado_por', 'owner_id'];
foreach ($creatorCandidates as $cc) {
    if (isset($cols[$cc])) {
        $fields[] = $cc;
        $placeholders[] = '?';
        $types .= 'i';
        $values[] = $uid;
        break;
    }
}

// Si por alguna razón no pudimos armar nada
if (!$fields) {
    respond(false, ['error' => 'schema_unknown'], 500);
}

$sql = "INSERT INTO tasks (" . implode(',', $fields) . ") VALUES (" . implode(',', $placeholders) . ")";
$ins = $conn->prepare($sql);
if (!$ins) {
    respond(false, ['error' => 'db_prepare_insert'], 500);
}

bind_params_dynamic($ins, $types, $values);
if (!$ins->execute()) {
    respond(false, ['error' => 'db_execute_insert'], 500);
}

$task_id = (int) $ins->insert_id;

// (Opcional) registrar evento realtime si existe board_events
try {
    $hasBoardEvents = false;
    $t = $conn->query("SHOW TABLES LIKE 'board_events'");
    if ($t && $t->fetch_row())
        $hasBoardEvents = true;

    if ($hasBoardEvents) {
        $eventCols = [];
        $rc = $conn->query("SHOW COLUMNS FROM board_events");
        if ($rc)
            while ($r = $rc->fetch_assoc())
                $eventCols[$r['Field']] = true;

        $evFields = [];
        $evPh = [];
        $evTypes = '';
        $evVals = [];

        if (isset($eventCols['board_id'])) {
            $evFields[] = 'board_id';
            $evPh[] = '?';
            $evTypes .= 'i';
            $evVals[] = $board_id;
        }
        if (isset($eventCols['kind'])) {
            $evFields[] = 'kind';
            $evPh[] = '?';
            $evTypes .= 's';
            $evVals[] = 'task_created';
        }
        if (isset($eventCols['task_id'])) {
            $evFields[] = 'task_id';
            $evPh[] = '?';
            $evTypes .= 'i';
            $evVals[] = $task_id;
        }
        if (isset($eventCols['column_id'])) {
            $evFields[] = 'column_id';
            $evPh[] = '?';
            $evTypes .= 'i';
            $evVals[] = $column_id;
        }
        if (isset($eventCols['payload_json'])) {
            $payload = json_encode(['title' => $titulo], JSON_UNESCAPED_UNICODE);
            $evFields[] = 'payload_json';
            $evPh[] = '?';
            $evTypes .= 's';
            $evVals[] = $payload;
        }

        if ($evFields) {
            $evSql = "INSERT INTO board_events (" . implode(',', $evFields) . ") VALUES (" . implode(',', $evPh) . ")";
            $ev = $conn->prepare($evSql);
            if ($ev) {
                bind_params_dynamic($ev, $evTypes, $evVals);
                $ev->execute();
            }
        }
    }
} catch (Throwable $e) {
    // silencio: realtime no debe tumbar la app
}

// Responder según modo
if ($is_fetch) {
    respond(true, ['task_id' => $task_id], 200);
}

// volver al tablero (modo clásico)
header('Location: ../boards/view.php?id=' . $board_id);
exit;
