<?php
// public/tasks/attachment_delete.php — Elimina un adjunto de una tarea.
//
// POST:
//   csrf           token de sesión
//   attachment_id  entero
//
// Orden deliberado: primero la fila (dentro de transacción), luego el archivo.
// Si el borrado físico fallara, la transacción ya está confirmada y quedaría
// un archivo huérfano — recuperable por el cron de limpieza de una fase
// posterior. El caso inverso (archivo borrado y fila viva) sería peor:
// dejaría un adjunto roto y visible para el usuario.

require_once __DIR__ . '/../_auth.php';
require_login();
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../_perm.php';
require_once __DIR__ . '/../_attachments.php';

// 1) Solo POST
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    attach_json(false, ['error' => 'method_not_allowed'], 405);
}

// 2) CSRF
if (!attach_csrf_ok()) {
    attach_json(false, ['error' => 'csrf'], 403);
}

$user_id = (int) ($_SESSION['user_id'] ?? 0);
if ($user_id <= 0) {
    attach_json(false, ['error' => 'unauthenticated'], 401);
}

// 3) Adjunto
$attachment_id = isset($_POST['attachment_id']) ? (int) $_POST['attachment_id'] : 0;
if ($attachment_id <= 0) {
    attach_json(false, ['error' => 'invalid_attachment'], 400);
}

// 4) Adjunto + tablero por JOIN
$att = attach_find_with_board($conn, $attachment_id);
if ($att === null) {
    attach_json(false, ['error' => 'not_found'], 404);
}

// 5) Permisos de escritura sobre el tablero
if (!can_write_board($conn, (int) $att['board_id'], $user_id)) {
    attach_json(false, ['error' => 'forbidden'], 403);
}

// Un enlace o embed no tiene archivo físico: stored_path llega NULL.
// Se distingue explícitamente para no intentar resolver una ruta vacía.
$storedPath = ($att['stored_path'] !== null && $att['stored_path'] !== '')
    ? (string) $att['stored_path']
    : null;
$esExterno = attach_kind_is_external((string) $att['kind']);

// 6) Borrar la fila dentro de una transacción
$conn->begin_transaction();
try {
    $del = $conn->prepare("DELETE FROM task_attachments WHERE id = ? LIMIT 1");
    if (!$del) {
        throw new RuntimeException('db_prepare_failed');
    }
    $del->bind_param('i', $attachment_id);
    if (!$del->execute()) {
        throw new RuntimeException('db_delete_failed');
    }
    $affected = $del->affected_rows;
    $del->close();

    if ($affected !== 1) {
        throw new RuntimeException('unexpected_affected_rows');
    }

    $conn->commit();
} catch (Throwable $e) {
    $conn->rollback();
    error_log('[attachment_delete] ' . $e->getMessage());
    attach_json(false, ['error' => 'delete_failed',
        'message' => 'No se pudo eliminar el adjunto.'], 500);
}

// 7) Borrar el archivo físico, si lo hubiera.
//    En enlaces y embeds no hay nada que borrar en disco: se omite por
//    completo, sin llamar a unlink con una ruta vacía.
$existiaAntes = false;
$fileRemoved  = true;

if ($storedPath !== null) {
    // Que el archivo ya no exista NO es un error: el objetivo (que
    // desaparezca) está cumplido igualmente.
    $existiaAntes = (attach_absolute_path($storedPath) !== null);
    $fileRemoved  = attach_delete_file($storedPath);

    if (!$fileRemoved) {
        // La fila ya no está; el archivo quedó suelto. Se registra para el
        // barrido posterior, pero al usuario la operación le salió bien.
        error_log('[attachment_delete] archivo no eliminado para adjunto id=' . $attachment_id);
    }
}

attach_json(true, [
    'attachment_id' => $attachment_id,
    'task_id'       => (int) $att['task_id'],
    'kind'          => (string) $att['kind'],
    'is_external'   => $esExterno,
    'file_existed'  => $existiaAntes,
    'file_removed'  => $fileRemoved,
]);
