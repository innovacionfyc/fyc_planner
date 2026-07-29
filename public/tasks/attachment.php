<?php
// public/tasks/attachment.php — Entrega protegida de un adjunto.
//
// GET ?id=N[&download=1]
//
// Los archivos viven fuera de public/, así que el servidor web no puede
// entregarlos por su cuenta: cada byte pasa por aquí y, antes, por la
// comprobación de permisos del tablero.
//
// Implementa peticiones Range (HTTP 206) porque <audio> y <video> las
// necesitan para poder adelantar y retroceder. Sin esto la barra de
// progreso no funciona y Safari directamente no reproduce.

require_once __DIR__ . '/../_auth.php';
require_login();
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../_perm.php';
require_once __DIR__ . '/../_attachments.php';

/** Error en texto plano: este endpoint no devuelve JSON ni rutas internas. */
function attach_fail(int $code, string $msg): void
{
    if (!headers_sent()) {
        http_response_code($code);
        header('Content-Type: text/plain; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
    }
    echo $msg;
    exit;
}

$user_id = (int) ($_SESSION['user_id'] ?? 0);
if ($user_id <= 0) {
    attach_fail(401, 'No autenticado.');
}

$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
if ($id <= 0) {
    attach_fail(400, 'Identificador inválido.');
}

// Adjunto + tablero (por JOIN: la tabla no guarda board_id)
$att = attach_find_with_board($conn, $id);
if ($att === null) {
    attach_fail(404, 'Adjunto no encontrado.');
}

// Permiso de lectura: basta con tener acceso al tablero
if (!has_board_access($conn, (int) $att['board_id'], $user_id)) {
    attach_fail(403, 'Sin acceso.');
}

// Ruta física validada: patrón estricto + realpath dentro de storage/attachments
$full = attach_absolute_path((string) $att['stored_path']);
if ($full === null) {
    error_log('[attachment] ruta inválida o ausente para adjunto id=' . $id);
    attach_fail(404, 'Archivo no disponible.');
}

$size  = (int) @filesize($full);
$mtime = (int) @filemtime($full);
if ($size <= 0) {
    attach_fail(404, 'Archivo no disponible.');
}

$mime     = (string) $att['mime'];
$download = isset($_GET['download']) && $_GET['download'] === '1';
$etag     = '"' . md5($att['stored_path'] . '|' . $size . '|' . $mtime) . '"';

// Nombre para descargar: se envía en las dos formas para que cualquier
// navegador lo entienda, con tildes o sin ellas.
$origName  = (string) $att['original_name'];
$asciiName = preg_replace('/[^\x20-\x7E]/', '_', $origName) ?: 'archivo';
$asciiName = str_replace(['"', '\\'], '_', $asciiName);
$disp      = $download ? 'attachment' : 'inline';

// Sin buffers ni compresión: los archivos pueden ser grandes.
while (ob_get_level() > 0) {
    ob_end_clean();
}
if (function_exists('ini_set')) {
    @ini_set('zlib.output_compression', '0');
}

header('Content-Type: ' . $mime);
header('X-Content-Type-Options: nosniff');
header('Content-Disposition: ' . $disp . '; filename="' . $asciiName . '"; '
    . "filename*=UTF-8''" . rawurlencode($origName));
header('Cache-Control: private, max-age=3600, must-revalidate');
header('Pragma: private');
header('ETag: ' . $etag);
header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $mtime) . ' GMT');
header('Accept-Ranges: bytes');
// Los adjuntos nunca son documentos navegables.
header('X-Frame-Options: SAMEORIGIN');

// Revalidación por ETag
$ifNoneMatch = trim((string) ($_SERVER['HTTP_IF_NONE_MATCH'] ?? ''));
if ($ifNoneMatch !== '' && $ifNoneMatch === $etag) {
    http_response_code(304);
    exit;
}

// ─────────────────────────────────────────────────────────────
// Range
// ─────────────────────────────────────────────────────────────
$start = 0;
$end   = $size - 1;
$isPartial = false;

$rangeHeader = trim((string) ($_SERVER['HTTP_RANGE'] ?? ''));

if ($rangeHeader !== '') {
    // Solo "bytes=". Cualquier otra unidad no se admite.
    if (stripos($rangeHeader, 'bytes=') !== 0) {
        header('Content-Range: bytes */' . $size);
        attach_fail(416, 'Rango no admitido.');
    }

    $spec = substr($rangeHeader, 6);

    // Múltiples rangos: no se admiten en esta versión. En lugar de fallar,
    // se ignora la cabecera y se entrega el archivo completo (200),
    // que es el comportamiento permitido por el estándar.
    if (strpos($spec, ',') === false) {
        if (!preg_match('/^(\d*)-(\d*)$/', trim($spec), $m)) {
            header('Content-Range: bytes */' . $size);
            attach_fail(416, 'Rango mal formado.');
        }

        $rawStart = $m[1];
        $rawEnd   = $m[2];

        if ($rawStart === '' && $rawEnd === '') {
            header('Content-Range: bytes */' . $size);
            attach_fail(416, 'Rango mal formado.');
        }

        if ($rawStart === '') {
            // Sufijo: últimos N bytes
            $suffix = (int) $rawEnd;
            if ($suffix <= 0) {
                header('Content-Range: bytes */' . $size);
                attach_fail(416, 'Rango no satisfactible.');
            }
            $start = max(0, $size - $suffix);
            $end   = $size - 1;
        } else {
            $start = (int) $rawStart;
            $end   = ($rawEnd === '') ? ($size - 1) : (int) $rawEnd;
        }

        if ($start > $end || $start >= $size || $start < 0) {
            header('Content-Range: bytes */' . $size);
            attach_fail(416, 'Rango no satisfactible.');
        }
        if ($end >= $size) {
            $end = $size - 1;
        }

        $isPartial = true;
    }
}

$length = $end - $start + 1;

if ($isPartial) {
    http_response_code(206);
    header('Content-Range: bytes ' . $start . '-' . $end . '/' . $size);
} else {
    http_response_code(200);
}
header('Content-Length: ' . $length);

// ─────────────────────────────────────────────────────────────
// Envío por bloques: nunca se carga el archivo entero en memoria
// ─────────────────────────────────────────────────────────────
$fh = @fopen($full, 'rb');
if ($fh === false) {
    error_log('[attachment] no se pudo abrir el adjunto id=' . $id);
    attach_fail(500, 'No se pudo leer el archivo.');
}

if ($start > 0) {
    fseek($fh, $start);
}

$chunk     = 256 * 1024;   // 256 KB
$remaining = $length;

while ($remaining > 0 && !feof($fh)) {
    if (connection_aborted()) {
        break;
    }
    $read = ($remaining > $chunk) ? $chunk : $remaining;
    $buf  = fread($fh, $read);
    if ($buf === false || $buf === '') {
        break;
    }
    echo $buf;
    $remaining -= strlen($buf);
    flush();
}

fclose($fh);
exit;
