<?php
// public/tasks/attachment_link.php — Añade una URL externa a una tarea.
//
// POST:
//   csrf     token de sesión
//   task_id  entero
//   url      dirección http/https
//
// Este endpoint NO hace ninguna petición hacia la URL recibida: no descarga
// títulos, ni favicons, ni Open Graph. Solo valida, clasifica y guarda.
// Así se evita el SSRF y cualquier dependencia de servicios externos.

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

// 4) Permisos de escritura sobre el tablero
if (!can_write_board($conn, $board_id, $user_id)) {
    attach_json(false, ['error' => 'forbidden'], 403);
}

// 5) URL
$raw = isset($_POST['url']) && is_string($_POST['url']) ? $_POST['url'] : '';
$check = attach_validate_external_url($raw);
if ($check['ok'] !== true) {
    attach_json(false, ['error' => 'invalid_url', 'message' => $check['error']], 422);
}

$url  = (string) $check['url'];
$host = (string) $check['host'];

// 6) Clasificación: YouTube, Vimeo o enlace normal
$cls      = attach_classify_external_url($url, $host);
$kind     = $cls['kind'];
$provider = $cls['provider'];
$videoId  = $cls['video_id'];

// Contrato de fuente: esto es un enlace, así que no puede llevar ruta física.
if (!attach_validate_source(null, $url)) {
    attach_json(false, ['error' => 'invalid_source'], 422);
}

// 7) Metadatos: solo datos propios ya validados. Nunca HTML ni contenido remoto.
$meta = ['host' => attach_safe_display_host($host)];
if ($videoId !== null) {
    $meta['video_id'] = $videoId;
}
$metaJson = json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

// Nombre visible: el dominio. No se consulta el título remoto.
$displayName = attach_safe_display_host($host);

// 8) Guardar
try {
    $ins = $conn->prepare(
        "INSERT INTO task_attachments
            (task_id, uploaded_by, kind, original_name, stored_path,
             mime, size_bytes, external_url, provider, meta_json)
         VALUES (?,?,?,?, NULL, NULL, NULL, ?,?,?)"
    );
    if (!$ins) {
        throw new RuntimeException('db_prepare_failed');
    }
    $ins->bind_param('iisssss', $task_id, $user_id, $kind, $displayName, $url, $provider, $metaJson);
    if (!$ins->execute()) {
        throw new RuntimeException('db_insert_failed');
    }
    $newId = (int) $conn->insert_id;
    $ins->close();
} catch (Throwable $e) {
    error_log('[attachment_link] ' . $e->getMessage());
    attach_json(false, ['error' => 'link_failed',
        'message' => 'No se pudo guardar el enlace.'], 500);
}

attach_json(true, [
    'attachment' => [
        'id'           => $newId,
        'task_id'      => $task_id,
        'kind'         => $kind,
        'provider'     => $provider,
        // El src del iframe se construye desde plantilla propia.
        'embed_url'    => attach_build_embed_url($provider, $videoId),
        'external_url' => ($kind === 'link') ? $url : null,
        'display_host' => $displayName,
    ],
]);
