<?php
// public/tasks/update.php
require_once __DIR__ . '/../_auth.php';
require_login();
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../_perm.php';

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

// ✅ NUEVO: descripción (puede venir vacío)
$desc_raw = isset($_POST['descripcion_md']) ? (string) $_POST['descripcion_md'] : '';

if ($task_id <= 0 || $board_id <= 0) {
    respond(false, ['error' => 'bad_request'], 400);
}

$user_id = (int) ($_SESSION['user_id'] ?? 0);

// ✅ Detectar si existe columna descripcion_md (para no romper esquemas)
$hasDescCol = false;
$colsTasks = $conn->query("SHOW COLUMNS FROM tasks");
if ($colsTasks) {
    while ($r = $colsTasks->fetch_assoc()) {
        if (($r['Field'] ?? '') === 'descripcion_md') {
            $hasDescCol = true;
            break;
        }
    }
}

// Validar permisos de escritura en el tablero
if (!can_write_board($conn, $board_id, $user_id)) {
    respond(false, ['error' => 'forbidden'], 403);
}

// Validar tarea y leer valores actuales para comparar después del UPDATE
$descSelectCol = $hasDescCol ? ', descripcion_md' : '';
$sql = "SELECT assignee_id, titulo, prioridad, fecha_limite{$descSelectCol}
        FROM tasks WHERE id = ? AND board_id = ? LIMIT 1";
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

$oldAssignee  = ($cur['assignee_id'] !== null && $cur['assignee_id'] !== '') ? (int) $cur['assignee_id'] : null;
$taskTitle    = $cur['titulo'] ?? 'Tarea';
$oldPrioridad = $cur['prioridad'] ?? 'med';
$oldFecha     = $cur['fecha_limite'] ? substr((string)$cur['fecha_limite'], 0, 10) : null;
$oldDesc      = $hasDescCol ? ($cur['descripcion_md'] ?? null) : null;

// Validar prioridad
$allowed = ['low', 'med', 'high', 'urgent'];
if (!in_array($prioridad, $allowed, true)) {
    $prioridad = 'med';
}

// Normalizar fecha (NULL si viene vacía o inválida)
$fecha = null;
if ($fecha_raw !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha_raw)) {
    $fecha = $fecha_raw . ' 23:59:00';
}

// Validar responsable (NULL si no aplica)
// Usa is_valid_assignee() para cubrir tableros de equipo (team_members) y personales (board_members).
$newAssignee = null;
if ($assignee_raw !== '') {
    $tmp = (int) $assignee_raw;
    if ($tmp > 0 && is_valid_assignee($conn, $board_id, $tmp)) {
        $newAssignee = $tmp;
    }
}

// ✅ Normalizar descripción:
// - si existe columna: guardar NULL si viene vacío (trim)
// - si viene con texto: guardar tal cual (sin recortar contenido, pero sí quitamos espacios extremos)
$desc = null;
if ($hasDescCol) {
    $tmpDesc = trim((string) $desc_raw);
    $desc = ($tmpDesc === '') ? null : $tmpDesc;
}

// ------------------------------------
// UPDATE seguro con NULL reales
// ------------------------------------
$setParts = [];
$types = '';
$params = [];

$setParts[] = "prioridad = ?";
$types .= 's';
$params[] = $prioridad;

if ($fecha !== null) {
    $setParts[] = "fecha_limite = ?";
    $types .= 's';
    $params[] = $fecha;
} else {
    $setParts[] = "fecha_limite = NULL";
}

if ($newAssignee !== null) {
    $setParts[] = "assignee_id = ?";
    $types .= 'i';
    $params[] = $newAssignee;
} else {
    $setParts[] = "assignee_id = NULL";
}

// ✅ NUEVO: descripcion_md (si la columna existe)
if ($hasDescCol) {
    if ($desc !== null) {
        $setParts[] = "descripcion_md = ?";
        $types .= 's';
        $params[] = $desc;
    } else {
        $setParts[] = "descripcion_md = NULL";
    }
}

$sqlUpd = "
    UPDATE tasks
    SET " . implode(", ", $setParts) . "
    WHERE id = ? AND board_id = ?
    LIMIT 1
";

$upd = $conn->prepare($sqlUpd);
if (!$upd) {
    respond(false, ['error' => 'db_prepare_update', 'detail' => $conn->error], 500);
}

// agregar WHERE params
$types .= 'ii';
$params[] = $task_id;
$params[] = $board_id;

// bind dinámico por referencia
$bind = [];
$bind[] = $types;
foreach ($params as $k => $v) {
    $bind[] = &$params[$k];
}

if (!call_user_func_array([$upd, 'bind_param'], $bind)) {
    respond(false, ['error' => 'db_bind_update'], 500);
}

if (!$upd->execute()) {
    respond(false, ['error' => 'db_execute_update', 'detail' => $upd->error], 500);
}

// ---- Notificaciones específicas post-UPDATE ----
try {
    // Nombre del tablero (necesario en todos los payloads)
    $boardName = 'Tablero';
    $boardStmt = $conn->prepare("SELECT nombre FROM boards WHERE id = ? LIMIT 1");
    if ($boardStmt) {
        $boardStmt->bind_param('i', $board_id);
        $boardStmt->execute();
        $rowB = $boardStmt->get_result()->fetch_row();
        $boardName = $rowB ? ($rowB[0] ?? 'Tablero') : 'Tablero';
    }

    // Responsable actual tras el UPDATE (puede ser el nuevo o el mismo)
    $currentAssignee = $newAssignee ?? $oldAssignee;

    $insN = $conn->prepare("INSERT INTO notifications (user_id, tipo, payload_json) VALUES (?, ?, ?)");

    // 1. Cambio de responsable → notificar al NUEVO responsable
    if ($insN && $newAssignee !== null && $newAssignee !== $oldAssignee) {
        $oldAssigneeName = '';
        if ($oldAssignee) {
            $oq = $conn->prepare("SELECT nombre FROM users WHERE id = ? LIMIT 1");
            $oq->bind_param('i', $oldAssignee);
            $oq->execute();
            $oldAssigneeName = ($oq->get_result()->fetch_row()[0] ?? '');
        }
        $newAssigneeName = '';
        $nq = $conn->prepare("SELECT nombre FROM users WHERE id = ? LIMIT 1");
        $nq->bind_param('i', $newAssignee);
        $nq->execute();
        $newAssigneeName = ($nq->get_result()->fetch_row()[0] ?? '');

        $p = json_encode([
            'board_id'          => $board_id,
            'board_name'        => $boardName,
            'task_id'           => $task_id,
            'task_title'        => $taskTitle,
            'new_assignee_name' => $newAssigneeName,
            'old_assignee_name' => $oldAssigneeName,
        ], JSON_UNESCAPED_UNICODE);
        $tipo = 'task_assignee_changed';
        $insN->bind_param('iss', $newAssignee, $tipo, $p);
        $insN->execute();
    }

    // Helper: notificar al responsable actual si existe y no es el actor
    $notifyAssignee = function(string $tipo, array $extra) use ($conn, $insN, $currentAssignee, $user_id, $board_id, $boardName, $task_id, $taskTitle) {
        if (!$currentAssignee || $currentAssignee === $user_id) return;
        $p = json_encode(array_merge([
            'board_id'   => $board_id,
            'board_name' => $boardName,
            'task_id'    => $task_id,
            'task_title' => $taskTitle,
        ], $extra), JSON_UNESCAPED_UNICODE);
        $insN->bind_param('iss', $currentAssignee, $tipo, $p);
        $insN->execute();
    };

    // 2. Cambio de prioridad
    if ($insN && $prioridad !== $oldPrioridad) {
        $notifyAssignee('task_priority_changed', [
            'old_value' => $oldPrioridad,
            'new_value' => $prioridad,
        ]);
    }

    // 3. Cambio de fecha límite
    $newFecha = $fecha ? substr($fecha, 0, 10) : null;
    if ($insN && $newFecha !== $oldFecha) {
        $notifyAssignee('task_date_changed', [
            'old_value' => $oldFecha,
            'new_value' => $newFecha,
        ]);
    }

    // 4. Cambio de descripción
    if ($insN && $hasDescCol && $desc !== $oldDesc) {
        $notifyAssignee('task_description_changed', []);
    }

} catch (Throwable $e) {
    // no romper app por notificaciones
}

// FETCH response
if ($is_fetch) {
    respond(true, [
        'task_id' => $task_id,
        'board_id' => $board_id,
        'prioridad' => $prioridad,
        'fecha_limite' => ($fecha !== null ? substr($fecha, 0, 10) : ''),
        'assignee_id' => ($newAssignee !== null ? $newAssignee : ''),
        'descripcion_md' => ($hasDescCol ? ($desc !== null ? $desc : '') : null),
        'has_desc_col' => $hasDescCol
    ], 200);
}

// Modo clásico → volver al tablero
header('Location: ../boards/workspace.php?board=' . $board_id);
exit;