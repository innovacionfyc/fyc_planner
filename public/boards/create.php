<?php
// public/tasks/create.php
require_once __DIR__ . '/../_auth.php';
require_login();
require_once __DIR__ . '/../../config/db.php';

// Solo POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ../boards/index.php');
    exit;
}

// CSRF
if (!isset($_POST['csrf'], $_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], $_POST['csrf'])) {
    header('Location: ../boards/index.php');
    exit;
}

$board_id = (int) ($_POST['board_id'] ?? 0);
$column_id = (int) ($_POST['column_id'] ?? 0);
$titulo = trim($_POST['titulo'] ?? '');

if ($board_id <= 0 || $column_id <= 0 || $titulo === '') {
    header('Location: ../boards/index.php');
    exit;
}

// Validar que pertenezco al board
$chk = $conn->prepare("SELECT 1 FROM board_members WHERE board_id = ? AND user_id = ? LIMIT 1");
$chk->bind_param('ii', $board_id, $_SESSION['user_id']);
$chk->execute();
if (!$chk->get_result()->fetch_row()) {
    header('Location: ../boards/index.php');
    exit;
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
// prioridad
if (isset($cols['prioridad'])) {
    $prio = trim($_POST['prioridad'] ?? 'med');
    $fields[] = 'prioridad';
    $placeholders[] = '?';
    $types .= 's';
    $values[] = $prio;
}

// fecha limite
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
        $values[] = (int) $_SESSION['user_id'];
        break;
    }
}

// Si por alguna razón no pudimos armar nada
if (!$fields) {
    header('Location: ../boards/view.php?id=' . $board_id . '&err=1');
    exit;
}

$sql = "INSERT INTO tasks (" . implode(',', $fields) . ") VALUES (" . implode(',', $placeholders) . ")";
$ins = $conn->prepare($sql);
if (!$ins) {
    header('Location: ../boards/view.php?id=' . $board_id . '&err=1');
    exit;
}

bind_params_dynamic($ins, $types, $values);
if (!$ins->execute()) {
    header('Location: ../boards/view.php?id=' . $board_id . '&err=1');
    exit;
}

$task_id = (int) $ins->insert_id;

// (Opcional) registrar evento realtime si existe board_events
// No reventamos si esa tabla/columnas no existen
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

// volver al tablero
header('Location: ../boards/view.php?id=' . $board_id);
exit;
