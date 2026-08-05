<?php
// public/tasks/delete.php
require_once __DIR__ . '/../_auth.php';
require_login();
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../_perm.php';
require_once __DIR__ . '/../_attachments.php';

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

if ($task_id <= 0 || $board_id <= 0) {
    respond(false, ['error' => 'bad_request'], 400);
}

$user_id = (int) ($_SESSION['user_id'] ?? 0);

// 1) Validar permisos de escritura en el tablero
if (!can_write_board($conn, $board_id, $user_id)) {
    respond(false, ['error' => 'forbidden'], 403);
}

// 2) Validar que la tarea pertenece al board y obtener datos
$q = $conn->prepare("SELECT titulo, column_id FROM tasks WHERE id = ? AND board_id = ? LIMIT 1");
if (!$q) {
    respond(false, ['error' => 'db_prepare_task_check'], 500);
}
$q->bind_param('ii', $task_id, $board_id);
$q->execute();
$row = $q->get_result()->fetch_assoc();
if (!$row) {
    respond(false, ['error' => 'task_not_found'], 404);
}

$taskTitle = $row['titulo'] ?? 'Tarea';
$colId = (int) ($row['column_id'] ?? 0);

// 3) Recoger las rutas de los adjuntos ANTES de borrar.
//    task_attachments.task_id tiene ON DELETE CASCADE: en cuanto se borre la
//    tarea, las filas desaparecen y ya no habría forma de saber qué archivos
//    dejar de conservar. Los enlaces y embeds no entran (stored_path NULL).
$attachPaths = attach_stored_paths_of_task($conn, $task_id);

// 4) Borrar comentarios y tarea dentro de una transacción.
//    Los adjuntos se van solos por la cascada de la clave foránea.
$conn->begin_transaction();
try {
    try {
        $delC = $conn->prepare("DELETE FROM comments WHERE task_id = ?");
        if ($delC) {
            $delC->bind_param('i', $task_id);
            $delC->execute();
        }
    } catch (Throwable $e) {
        // si comments no existe o falla, no tumbar
    }

    $delT = $conn->prepare("DELETE FROM tasks WHERE id = ? AND board_id = ? LIMIT 1");
    if (!$delT) {
        throw new RuntimeException('db_prepare_delete');
    }
    $delT->bind_param('ii', $task_id, $board_id);
    if (!$delT->execute()) {
        throw new RuntimeException('db_execute_delete');
    }
    if ($delT->affected_rows !== 1) {
        throw new RuntimeException('task_not_deleted');
    }
    $delT->close();

    $conn->commit();
} catch (Throwable $e) {
    $conn->rollback();
    error_log('[tasks/delete] ' . $e->getMessage());
    // Nada se ha borrado: los archivos siguen intactos en disco.
    respond(false, ['error' => 'delete_failed'], 500);
}

// 5) Ya confirmado en base: ahora sí se borran los archivos.
//    Mismo orden que attachment_delete.php — un archivo suelto lo recoge el
//    cron de huérfanos; una fila viva sin archivo sería un adjunto roto.
//    Si algo falla aquí no se le cuenta al usuario ni se exponen rutas.
$attachStats = attach_delete_files($attachPaths, 'task=' . $task_id);

// 6) Evento realtime (opcional pero recomendado)
try {
    $payload = json_encode(['task_title' => $taskTitle], JSON_UNESCAPED_UNICODE);
    $ev = $conn->prepare(
        "INSERT INTO board_events (board_id, kind, task_id, column_id, payload_json)
         VALUES (?, 'task_deleted', ?, ?, ?)"
    );
    if ($ev) {
        $ev->bind_param('iiis', $board_id, $task_id, $colId, $payload);
        $ev->execute();
    }
} catch (Throwable $e) {
    // silencio
}

// Responder según modo
if ($is_fetch) {
    respond(true, [
        'task_id' => $task_id,
        'board_id' => $board_id,
        // Solo contadores: nunca rutas ni nombres de archivo.
        'attachments' => [
            'total'   => $attachStats['total'],
            'deleted' => $attachStats['deleted'],
            'missing' => $attachStats['missing'],
        ],
    ], 200);
}

// modo clásico
header("Location: ../boards/workspace.php?board={$board_id}");
exit;