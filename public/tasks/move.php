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
$before_task_id = (int) ($_POST['before_task_id'] ?? 0); // opcional

if ($task_id <= 0 || $board_id <= 0 || $column_id <= 0) {
    respond(false, ['error' => 'bad_request'], 400);
}

$user_id = (int) ($_SESSION['user_id'] ?? 0);

// Helper: renumerar sort_order de una columna (10,20,30...)
function renumber_column(mysqli $conn, int $board_id, int $column_id): bool
{
    // MySQL 8 soporta variables de usuario sin problema.
    // Renumeramos por sort_order ASC, id ASC.
    $sql = "
      UPDATE tasks t
      JOIN (
        SELECT id, (@rn:=@rn+1) AS rn
        FROM (
          SELECT id
          FROM tasks
          WHERE board_id = ? AND column_id = ?
          ORDER BY sort_order ASC, id ASC
        ) s
        JOIN (SELECT @rn:=0) vars
      ) x ON x.id = t.id
      SET t.sort_order = x.rn * 10
      WHERE t.board_id = ? AND t.column_id = ?
    ";
    $st = $conn->prepare($sql);
    if (!$st)
        return false;
    $st->bind_param('iiii', $board_id, $column_id, $board_id, $column_id);
    return (bool) $st->execute();
}

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

// 2) Verificar que la tarea pertenece al board + obtener su column actual
$sql = "SELECT column_id, sort_order FROM tasks WHERE id = ? AND board_id = ? LIMIT 1";
$stmt = $conn->prepare($sql);
if (!$stmt) {
    respond(false, ['error' => 'db_prepare_task_check'], 500);
}
$stmt->bind_param('ii', $task_id, $board_id);
$stmt->execute();
$rowTask = $stmt->get_result()->fetch_assoc();
if (!$rowTask) {
    respond(false, ['error' => 'task_not_found'], 404);
}
$from_column_id = (int) ($rowTask['column_id'] ?? 0);

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

// 4) Si viene before_task_id, validar que existe y está en la MISMA columna destino y board
$before_sort = null;
if ($before_task_id > 0) {
    if ($before_task_id === $task_id) {
        $before_task_id = 0; // no tiene sentido
    } else {
        $q = $conn->prepare("SELECT column_id, sort_order FROM tasks WHERE id = ? AND board_id = ? LIMIT 1");
        if (!$q)
            respond(false, ['error' => 'db_prepare_before_check'], 500);
        $q->bind_param('ii', $before_task_id, $board_id);
        $q->execute();
        $r = $q->get_result()->fetch_assoc();
        if (!$r) {
            $before_task_id = 0;
        } else {
            $bcol = (int) ($r['column_id'] ?? 0);
            if ($bcol !== $column_id) {
                // si el before_task no está en la columna destino, lo ignoramos
                $before_task_id = 0;
            } else {
                $before_sort = (int) ($r['sort_order'] ?? 0);
            }
        }
    }
}

// 5) Calcular nuevo sort_order en columna destino
$new_sort = 0;

$conn->begin_transaction();
try {
    // Caso A: insertar ANTES de before_task_id
    if ($before_task_id > 0 && $before_sort !== null) {

        // prev_sort = sort_order de la tarea inmediatamente anterior en esa columna (excluyendo task actual)
        $prev_sort = 0;
        $ps = $conn->prepare("
            SELECT sort_order
            FROM tasks
            WHERE board_id = ? AND column_id = ? AND id <> ? AND sort_order < ?
            ORDER BY sort_order DESC
            LIMIT 1
        ");
        if (!$ps)
            throw new Exception('db_prepare_prev_sort');
        $ps->bind_param('iiii', $board_id, $column_id, $task_id, $before_sort);
        $ps->execute();
        $rr = $ps->get_result()->fetch_assoc();
        if ($rr)
            $prev_sort = (int) ($rr['sort_order'] ?? 0);

        // Si hay hueco suficiente, ponemos en la mitad
        if (($before_sort - $prev_sort) >= 2) {
            $new_sort = (int) floor(($prev_sort + $before_sort) / 2);
        } else {
            // No hay hueco -> renumerar toda la columna destino y recalcular
            if (!renumber_column($conn, $board_id, $column_id)) {
                throw new Exception('renumber_failed');
            }

            // Releer before_sort y prev_sort luego de renumerar
            $q2 = $conn->prepare("SELECT sort_order FROM tasks WHERE id = ? AND board_id = ? LIMIT 1");
            if (!$q2)
                throw new Exception('db_prepare_before_recheck');
            $q2->bind_param('ii', $before_task_id, $board_id);
            $q2->execute();
            $r2 = $q2->get_result()->fetch_assoc();
            $before_sort = $r2 ? (int) ($r2['sort_order'] ?? 0) : 0;

            $prev_sort = 0;
            $ps2 = $conn->prepare("
                SELECT sort_order
                FROM tasks
                WHERE board_id = ? AND column_id = ? AND id <> ? AND sort_order < ?
                ORDER BY sort_order DESC
                LIMIT 1
            ");
            if (!$ps2)
                throw new Exception('db_prepare_prev_sort2');
            $ps2->bind_param('iiii', $board_id, $column_id, $task_id, $before_sort);
            $ps2->execute();
            $rr2 = $ps2->get_result()->fetch_assoc();
            if ($rr2)
                $prev_sort = (int) ($rr2['sort_order'] ?? 0);

            // Ahora sí debe haber hueco (10 en 10)
            $new_sort = (int) floor(($prev_sort + $before_sort) / 2);
            if ($new_sort <= 0)
                $new_sort = $before_sort - 1;
        }

    } else {
        // Caso B: append al final de la columna destino
        $mx = 0;
        $ms = $conn->prepare("
            SELECT COALESCE(MAX(sort_order), 0) AS mx
            FROM tasks
            WHERE board_id = ? AND column_id = ? AND id <> ?
        ");
        if (!$ms)
            throw new Exception('db_prepare_max_sort');
        $ms->bind_param('iii', $board_id, $column_id, $task_id);
        $ms->execute();
        $rmx = $ms->get_result()->fetch_assoc();
        $mx = $rmx ? (int) ($rmx['mx'] ?? 0) : 0;
        $new_sort = $mx + 10;
        if ($new_sort <= 0)
            $new_sort = 10;
    }

    // 6) Actualizar (column_id + sort_order)
    $upd = $conn->prepare("UPDATE tasks SET column_id = ?, sort_order = ? WHERE id = ? AND board_id = ? LIMIT 1");
    if (!$upd)
        throw new Exception('db_prepare_update');
    $upd->bind_param('iiii', $column_id, $new_sort, $task_id, $board_id);
    if (!$upd->execute())
        throw new Exception('db_execute_update');

    $conn->commit();
} catch (Throwable $e) {
    $conn->rollback();
    respond(false, ['error' => 'move_failed'], 500);
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
        'column_name' => $colName,
        'from_column_id' => $from_column_id,
        'sort_order' => $new_sort,
        'before_task_id' => $before_task_id ?: null
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

    // Evento realtime
    $ev = $conn->prepare("INSERT INTO board_events (board_id, kind, task_id, column_id, payload_json)
                          VALUES (?, 'task_moved', ?, ?, ?)");
    if ($ev) {
        $ev->bind_param('iiis', $board_id, $task_id, $column_id, $payload);
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
        'column_id' => $column_id,
        'sort_order' => $new_sort,
        'before_task_id' => $before_task_id ?: null
    ], 200);
}

// modo clásico
header("Location: ../boards/view.php?id={$board_id}");
exit;