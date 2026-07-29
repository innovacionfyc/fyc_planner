<?php
// public/tasks/attachment_upload.php — Subida de adjuntos a una tarea.
//
// POST multipart/form-data:
//   csrf     token de sesión
//   task_id  entero
//   files[]  hasta 5 archivos
//
// Responde SIEMPRE JSON. Nunca revela rutas internas del servidor.

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

// 3) Tarea
$task_id = isset($_POST['task_id']) ? (int) $_POST['task_id'] : 0;
if ($task_id <= 0) {
    attach_json(false, ['error' => 'invalid_task'], 400);
}

$board_id = attach_board_id_of_task($conn, $task_id);
if ($board_id === null) {
    attach_json(false, ['error' => 'task_not_found'], 404);
}

// 4) Permisos: hace falta poder escribir en el tablero
if (!can_write_board($conn, $board_id, $user_id)) {
    attach_json(false, ['error' => 'forbidden'], 403);
}

// 5) Archivos recibidos
if (!isset($_FILES['files'])) {
    // post_max_size superado deja $_POST y $_FILES vacíos: se detecta aquí.
    $contentLength = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
    if ($contentLength > 0 && empty($_POST)) {
        attach_json(false, ['error' => 'payload_too_large',
            'message' => 'La solicitud supera el tamaño máximo admitido por el servidor.'], 413);
    }
    attach_json(false, ['error' => 'no_files'], 400);
}

$files = attach_normalize_files($_FILES['files']);
if (count($files) === 0) {
    attach_json(false, ['error' => 'no_files'], 400);
}
if (count($files) > ATTACH_MAX_FILES) {
    attach_json(false, ['error' => 'too_many_files',
        'message' => 'Máximo ' . ATTACH_MAX_FILES . ' archivos por solicitud.'], 400);
}

// 6) Procesar
$saved    = [];   // filas insertadas, para la respuesta
$movedRel = [];   // rutas movidas al disco, para limpiar si algo falla
$rejected = [];   // archivos rechazados por validación (no son fatales)

$conn->begin_transaction();

try {
    $ins = $conn->prepare(
        "INSERT INTO task_attachments
            (task_id, uploaded_by, kind, original_name, stored_path, mime, size_bytes)
         VALUES (?,?,?,?,?,?,?)"
    );
    if (!$ins) {
        throw new RuntimeException('db_prepare_failed');
    }

    foreach ($files as $idx => $file) {
        $check = attach_validate_upload($file);

        if ($check['ok'] !== true) {
            $rejected[] = [
                'index' => $idx,
                'name'  => attach_sanitize_original_name((string) ($file['name'] ?? '')),
                'error' => $check['error'] ?? 'Archivo no válido.',
            ];
            continue;
        }

        $storedPath = attach_generate_stored_path($check['ext']);
        if (!attach_ensure_dir($storedPath)) {
            throw new RuntimeException('storage_unavailable');
        }

        $dest = attach_storage_root() . DIRECTORY_SEPARATOR
            . str_replace('/', DIRECTORY_SEPARATOR, $storedPath);

        if (!move_uploaded_file($file['tmp_name'], $dest)) {
            throw new RuntimeException('move_failed');
        }
        $movedRel[] = $storedPath;
        @chmod($dest, 0644);

        $original = $check['original'];
        $kind     = $check['kind'];
        $mime     = $check['mime'];
        $size     = $check['size'];

        $ins->bind_param('iissssi', $task_id, $user_id, $kind, $original, $storedPath, $mime, $size);
        if (!$ins->execute()) {
            throw new RuntimeException('db_insert_failed');
        }

        $newId = (int) $conn->insert_id;
        $saved[] = [
            'id'            => $newId,
            'task_id'       => $task_id,
            'kind'          => $kind,
            'original_name' => $original,
            'mime'          => $mime,
            'size_bytes'    => $size,
            'url'           => attach_public_url($newId),
            'download_url'  => attach_public_url($newId, true),
        ];
    }
    $ins->close();

    if (count($saved) === 0) {
        // Nada que guardar: se deshace y se informa del motivo de cada rechazo.
        $conn->rollback();
        foreach ($movedRel as $rel) {
            attach_delete_file($rel);
        }
        attach_json(false, ['error' => 'no_valid_files', 'rejected' => $rejected], 422);
    }

    $conn->commit();

} catch (Throwable $e) {
    // Compensación: si algo falló, ni fila ni archivo quedan sueltos.
    $conn->rollback();
    foreach ($movedRel as $rel) {
        attach_delete_file($rel);
    }
    error_log('[attachment_upload] ' . $e->getMessage());
    attach_json(false, ['error' => 'upload_failed',
        'message' => 'No se pudo guardar el adjunto.'], 500);
}

attach_json(true, [
    'attachments' => $saved,
    'rejected'    => $rejected,
]);
