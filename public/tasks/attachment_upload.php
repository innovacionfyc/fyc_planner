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

// 2) ¿PHP descartó el cuerpo por superar post_max_size?
//
// Este control va ANTES del CSRF a propósito, y conviene entender por qué.
//
// Cuando el cuerpo de la petición supera post_max_size, PHP lo descarta
// entero antes de ceder el control a este archivo: $_POST y $_FILES llegan
// VACÍOS. El token CSRF viaja dentro de ese cuerpo, así que la comprobación
// de CSRF no encontraría nada y respondería 403 «csrf». El usuario vería
// «no tienes permiso» al subir su propio archivo a su propia tarea: un
// mensaje falso que manda a buscar un problema de permisos inexistente.
//
// Esto NO debilita el CSRF:
//   · no ejecuta ninguna mutación: ni base de datos, ni disco, ni sesión;
//   · no lee ni da por bueno ningún token; solo constata que NO llegó cuerpo;
//   · únicamente se activa cuando no hay nada que validar;
//   · toda petición que sí trae cuerpo sigue pasando por el CSRF de abajo.
//
// La condición es deliberadamente estrecha para no dar falsos positivos:
// solo multipart —el tipo que PHP siempre vuelca en $_POST/$_FILES—, con
// cuerpo anunciado y AMBOS superglobales vacíos. Un POST de JSON, por
// ejemplo, también deja $_POST vacío y por eso se exige el multipart.
$contentType   = strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? ''));
$contentLength = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);

if (str_starts_with($contentType, 'multipart/form-data')
    && $contentLength > 0
    && $_POST === []
    && $_FILES === []
) {
    attach_json(false, [
        'error'   => 'payload_too_large',
        'message' => 'El envío supera el tamaño máximo que admite el servidor. '
            . 'Reduce la selección o comparte el archivo como enlace externo '
            . '(YouTube, Vimeo o una URL).',
        'max_bytes' => ATTACH_MAX_REQUEST_BYTES,
        'max_mb'    => (int) round(ATTACH_MAX_REQUEST_BYTES / 1048576),
    ], 413);
}

// 3) CSRF
if (!attach_csrf_ok()) {
    attach_json(false, ['error' => 'csrf'], 403);
}

$user_id = (int) ($_SESSION['user_id'] ?? 0);
if ($user_id <= 0) {
    attach_json(false, ['error' => 'unauthenticated'], 401);
}

// 4) Tarea
$task_id = isset($_POST['task_id']) ? (int) $_POST['task_id'] : 0;
if ($task_id <= 0) {
    attach_json(false, ['error' => 'invalid_task'], 400);
}

$board_id = attach_board_id_of_task($conn, $task_id);
if ($board_id === null) {
    attach_json(false, ['error' => 'task_not_found'], 404);
}

// 5) Permisos: hace falta poder escribir en el tablero
if (!can_write_board($conn, $board_id, $user_id)) {
    attach_json(false, ['error' => 'forbidden'], 403);
}

// 6) Archivos recibidos
if (!isset($_FILES['files'])) {
    // Llegados aquí el cuerpo SÍ se parseó —el CSRF de arriba lo exige—, así
    // que este caso ya no puede ser un desbordamiento de post_max_size: eso
    // se resuelve en el paso 2. Aquí solo queda un envío sin el campo files[].
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

// 7) Tamaño TOTAL del envío
//
// Comprobar cada archivo por separado no basta: dos de 8 MB pasan el máximo
// individual de 14 MB y sin embargo suman 16, que es justo lo que el servidor
// de producción no admite. Este control cierra ese hueco.
//
// Se suma el 'size' que reporta PHP —lo que realmente recibió—, no un valor
// declarado por el cliente. Los archivos con error de subida traen size 0 y
// por tanto no inflan la cuenta.
//
// Es TODO O NADA: si la suma se pasa, se rechaza el envío completo sin
// guardar ni una fila ni un byte. Aceptar «los que quepan» daría una
// sensación de éxito parcial confusa, y la petición que PHP descarta por
// tamaño se pierde entera de todos modos.
$totalBytes = 0;
foreach ($files as $f) {
    $s = (int) ($f['size'] ?? 0);
    if ($s > 0) {
        $totalBytes += $s;
    }
}

if ($totalBytes > ATTACH_MAX_REQUEST_BYTES) {
    $limiteMb = (int) round(ATTACH_MAX_REQUEST_BYTES / 1048576);
    attach_json(false, [
        'error'   => 'request_too_large',
        'message' => 'El envío completo ocupa '
            . attach_human_size($totalBytes) . ' y el máximo son '
            . $limiteMb . ' MB. No se ha adjuntado ningún archivo: reduce la '
            . 'selección o comparte los más grandes como enlace externo '
            . '(YouTube, Vimeo o una URL).',
        'total_bytes' => $totalBytes,
        'max_bytes'   => ATTACH_MAX_REQUEST_BYTES,
        'max_mb'      => $limiteMb,
    ], 413);
}

// 8) Procesar
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
