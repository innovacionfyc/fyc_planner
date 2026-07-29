<?php
// public/_attachments.php — Helpers compartidos para adjuntos de tareas.
//
// Toda la política de seguridad de adjuntos vive aquí:
// whitelist, límites, nombres físicos, validación de rutas y respuestas JSON.
// Los endpoints no deben reimplementar ninguna de estas reglas.
//
// Reglas que NO se negocian:
//   · Nunca se confía en $_FILES['type'] (lo envía el navegador).
//   · Nunca se confía en el nombre original para construir rutas.
//   · La extensión sola no basta: debe coincidir con el MIME real.
//   · El usuario jamás aporta una ruta.

require_once __DIR__ . '/../config/bootstrap.php';

// ─────────────────────────────────────────────────────────────
// WHITELIST
// ─────────────────────────────────────────────────────────────
// extensión => [kind, [MIME reales aceptados], tamaño máximo en bytes]
//
// Los MIME son los que devuelve finfo, que no siempre es el "oficial":
//   · un .m4a es un contenedor MP4 y finfo suele decir audio/mp4 o video/mp4
//   · un .ogg puede salir como audio/ogg o application/ogg
// Por eso cada extensión acepta varios MIME, pero SIEMPRE de su familia.
const ATTACH_MAX_IMAGE = 10 * 1024 * 1024;   // 10 MB
const ATTACH_MAX_AUDIO = 20 * 1024 * 1024;   // 20 MB
const ATTACH_MAX_VIDEO = 50 * 1024 * 1024;   // 50 MB
const ATTACH_MAX_FILES = 5;

function attach_whitelist(): array
{
    return [
        // Imágenes
        'jpg'  => ['image', ['image/jpeg'], ATTACH_MAX_IMAGE],
        'jpeg' => ['image', ['image/jpeg'], ATTACH_MAX_IMAGE],
        'png'  => ['image', ['image/png'], ATTACH_MAX_IMAGE],
        'webp' => ['image', ['image/webp'], ATTACH_MAX_IMAGE],
        'gif'  => ['image', ['image/gif'], ATTACH_MAX_IMAGE],

        // Audio
        'mp3'  => ['audio', ['audio/mpeg'], ATTACH_MAX_AUDIO],
        'm4a'  => ['audio', ['audio/mp4', 'audio/x-m4a', 'audio/m4a', 'video/mp4'], ATTACH_MAX_AUDIO],
        'ogg'  => ['audio', ['audio/ogg', 'application/ogg'], ATTACH_MAX_AUDIO],
        'wav'  => ['audio', ['audio/wav', 'audio/x-wav', 'audio/wave', 'audio/vnd.wave'], ATTACH_MAX_AUDIO],

        // Vídeo
        'mp4'  => ['video', ['video/mp4'], ATTACH_MAX_VIDEO],
        'webm' => ['video', ['video/webm'], ATTACH_MAX_VIDEO],
        'mov'  => ['video', ['video/quicktime'], ATTACH_MAX_VIDEO],
    ];
}

/** Extensiones explícitamente peligrosas. Redundante con la whitelist, pero deja constancia. */
function attach_is_forbidden_ext(string $ext): bool
{
    static $bad = [
        'svg', 'svgz', 'html', 'htm', 'xhtml', 'xml', 'php', 'php3', 'php4', 'php5',
        'php7', 'php8', 'phtml', 'phar', 'js', 'mjs', 'exe', 'dll', 'bat', 'cmd',
        'com', 'sh', 'bash', 'ps1', 'jar', 'msi', 'scr', 'vbs', 'hta', 'cgi', 'pl', 'py',
    ];
    return in_array(strtolower($ext), $bad, true);
}

// ─────────────────────────────────────────────────────────────
// ALMACENAMIENTO
// ─────────────────────────────────────────────────────────────

/** Raíz física de los adjuntos. Fuera de public/ a propósito. */
function attach_storage_root(): string
{
    return dirname(__DIR__) . DIRECTORY_SEPARATOR . 'storage'
        . DIRECTORY_SEPARATOR . 'attachments';
}

/**
 * Formato exacto de stored_path: AAAA/MM/<32 hex>.<ext>
 * Cualquier valor que no encaje se rechaza sin tocar el disco.
 */
function attach_is_valid_stored_path(string $path): bool
{
    return (bool) preg_match('#^\d{4}/(0[1-9]|1[0-2])/[a-f0-9]{32}\.[a-z0-9]{2,4}$#', $path);
}

/**
 * Convierte stored_path en ruta absoluta y comprueba que sigue dentro
 * de storage/attachments. Doble barrera contra path traversal:
 * el patrón de arriba y esta comprobación con realpath.
 *
 * @return string|null  null si no es válida, no existe o se sale de la raíz.
 */
function attach_absolute_path(string $storedPath): ?string
{
    if (!attach_is_valid_stored_path($storedPath)) {
        return null;
    }
    $root = realpath(attach_storage_root());
    if ($root === false) {
        return null;
    }
    $full = realpath($root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $storedPath));
    if ($full === false) {
        return null;
    }
    $rootN = rtrim(str_replace('\\', '/', $root), '/') . '/';
    $fullN = str_replace('\\', '/', $full);

    // En Windows el sistema de archivos no distingue mayúsculas.
    $inside = (DIRECTORY_SEPARATOR === '\\')
        ? (stripos($fullN, $rootN) === 0)
        : (strpos($fullN, $rootN) === 0);

    return ($inside && is_file($full)) ? $full : null;
}

/** Genera una ruta relativa nueva: AAAA/MM/<32 hex>.<ext> */
function attach_generate_stored_path(string $ext): string
{
    return date('Y') . '/' . date('m') . '/' . bin2hex(random_bytes(16)) . '.' . strtolower($ext);
}

/** Crea el directorio AAAA/MM si hace falta. Devuelve false si no se pudo. */
function attach_ensure_dir(string $storedPath): bool
{
    $dir = attach_storage_root() . DIRECTORY_SEPARATOR
        . str_replace('/', DIRECTORY_SEPARATOR, dirname($storedPath));
    if (is_dir($dir)) {
        return true;
    }
    return @mkdir($dir, 0775, true) && is_dir($dir);
}

/** Borra el archivo físico. Devuelve true si al terminar ya no existe. */
function attach_delete_file(string $storedPath): bool
{
    $full = attach_absolute_path($storedPath);
    if ($full === null) {
        return true; // no existe o es inválida: nada que borrar
    }
    return @unlink($full) || !is_file($full);
}

// ─────────────────────────────────────────────────────────────
// NOMBRES
// ─────────────────────────────────────────────────────────────

/**
 * Limpia el nombre original para guardarlo y mostrarlo.
 * Conserva tildes, espacios y comillas; neutraliza los separadores de ruta.
 * Este valor NUNCA se usa para construir rutas físicas.
 *
 * No se usa basename(): un nombre como  informe </b> final.jpg  contiene una
 * barra dentro del propio texto y basename() lo truncaría a  b> final.jpg .
 * Como «/» y «\» son ilegales en un nombre de archivo real, se sustituyen por
 * «_»: el nombre sigue siendo reconocible y pierde toda semántica de ruta.
 */
function attach_sanitize_original_name(string $name): string
{
    $name = str_replace(["\0", "\r", "\n", "\t"], '', $name);
    $name = str_replace(['/', '\\'], '_', $name);      // sin semántica de ruta
    $name = preg_replace('/[\x00-\x1F\x7F]/u', '', $name) ?? '';
    $name = trim($name);
    if ($name === '' || $name === '.' || $name === '..') {
        $name = 'archivo';
    }
    if (function_exists('mb_substr') && mb_strlen($name, 'UTF-8') > 255) {
        $name = mb_substr($name, 0, 255, 'UTF-8');
    } elseif (strlen($name) > 255) {
        $name = substr($name, 0, 255);
    }
    return $name;
}

/** Extensión en minúsculas, sin punto. */
function attach_extension_of(string $name): string
{
    $ext = pathinfo($name, PATHINFO_EXTENSION);
    return strtolower(is_string($ext) ? $ext : '');
}

// ─────────────────────────────────────────────────────────────
// VALIDACIÓN
// ─────────────────────────────────────────────────────────────

/** Texto legible para cada UPLOAD_ERR_*. Sin rutas internas. */
function attach_upload_error_message(int $code): string
{
    switch ($code) {
        case UPLOAD_ERR_OK:
            return '';
        case UPLOAD_ERR_INI_SIZE:
        case UPLOAD_ERR_FORM_SIZE:
            return 'El archivo supera el tamaño permitido por el servidor.';
        case UPLOAD_ERR_PARTIAL:
            return 'La subida se interrumpió antes de completarse.';
        case UPLOAD_ERR_NO_FILE:
            return 'No se recibió ningún archivo.';
        case UPLOAD_ERR_NO_TMP_DIR:
            return 'El servidor no pudo almacenar el archivo temporalmente.';
        case UPLOAD_ERR_CANT_WRITE:
            return 'El servidor no pudo escribir el archivo.';
        case UPLOAD_ERR_EXTENSION:
            return 'Una extensión del servidor bloqueó la subida.';
        default:
            return 'No se pudo procesar el archivo.';
    }
}

/**
 * Detecta el MIME real leyendo el contenido. Nunca el que envía el cliente.
 */
function attach_detect_mime(string $tmpPath): ?string
{
    if (!is_readable($tmpPath)) {
        return null;
    }
    if (function_exists('finfo_open')) {
        $fi = finfo_open(FILEINFO_MIME_TYPE);
        if ($fi !== false) {
            $mime = finfo_file($fi, $tmpPath);
            finfo_close($fi);
            if (is_string($mime) && $mime !== '') {
                return strtolower($mime);
            }
        }
    }
    return null;
}

/**
 * Valida un archivo subido contra toda la política.
 *
 * @param array $file  entrada de $_FILES ya normalizada a un solo archivo
 * @return array{ok:bool, error?:string, kind?:string, ext?:string, mime?:string, size?:int}
 */
function attach_validate_upload(array $file): array
{
    $err = isset($file['error']) ? (int) $file['error'] : UPLOAD_ERR_NO_FILE;
    if ($err !== UPLOAD_ERR_OK) {
        return ['ok' => false, 'error' => attach_upload_error_message($err)];
    }

    $tmp = (string) ($file['tmp_name'] ?? '');
    if ($tmp === '' || !is_uploaded_file($tmp)) {
        return ['ok' => false, 'error' => 'El archivo no llegó correctamente.'];
    }

    $size = (int) ($file['size'] ?? 0);
    if ($size <= 0) {
        return ['ok' => false, 'error' => 'El archivo está vacío.'];
    }

    $original = attach_sanitize_original_name((string) ($file['name'] ?? ''));
    $ext      = attach_extension_of($original);

    if ($ext === '') {
        return ['ok' => false, 'error' => 'El archivo no tiene extensión.'];
    }
    if (attach_is_forbidden_ext($ext)) {
        return ['ok' => false, 'error' => 'Ese tipo de archivo no está permitido.'];
    }

    $wl = attach_whitelist();
    if (!isset($wl[$ext])) {
        return ['ok' => false, 'error' => 'Extensión no permitida: .' . $ext];
    }
    [$kind, $allowedMimes, $maxBytes] = $wl[$ext];

    if ($size > $maxBytes) {
        return [
            'ok' => false,
            'error' => 'El archivo supera el límite de ' . (int) round($maxBytes / 1048576) . ' MB para ' . $kind . '.',
        ];
    }

    $mime = attach_detect_mime($tmp);
    if ($mime === null) {
        return ['ok' => false, 'error' => 'No se pudo verificar el tipo real del archivo.'];
    }
    if (!in_array($mime, $allowedMimes, true)) {
        // La extensión dice una cosa y el contenido dice otra.
        return ['ok' => false, 'error' => 'El contenido del archivo no corresponde a su extensión.'];
    }

    // Segunda barrera para imágenes: debe ser una imagen real y coherente.
    if ($kind === 'image') {
        $info = @getimagesize($tmp);
        if ($info === false || empty($info['mime'])) {
            return ['ok' => false, 'error' => 'La imagen no es válida.'];
        }
        if (strtolower((string) $info['mime']) !== $mime) {
            return ['ok' => false, 'error' => 'La imagen no es válida.'];
        }
    }

    return [
        'ok'       => true,
        'kind'     => $kind,
        'ext'      => $ext,
        'mime'     => $mime,
        'size'     => $size,
        'original' => $original,
    ];
}

// ─────────────────────────────────────────────────────────────
// CONSULTA
// ─────────────────────────────────────────────────────────────

/**
 * Devuelve el adjunto junto con el board_id de su tarea.
 * El tablero se resuelve por JOIN: task_attachments no lo almacena.
 *
 * @return array|null
 */
function attach_find_with_board(mysqli $conn, int $attachmentId): ?array
{
    if ($attachmentId <= 0) {
        return null;
    }
    $sql = "SELECT a.id, a.task_id, a.uploaded_by, a.kind, a.original_name,
                   a.stored_path, a.mime, a.size_bytes, a.created_at,
                   t.board_id
            FROM task_attachments a
            JOIN tasks t ON t.id = a.task_id
            WHERE a.id = ? LIMIT 1";
    $st = $conn->prepare($sql);
    if (!$st) {
        return null;
    }
    $st->bind_param('i', $attachmentId);
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    $st->close();
    return $row ?: null;
}

/**
 * Lista los adjuntos de una tarea, ya preparados para renderizar.
 *
 * Devuelve SOLO información segura: stored_path nunca sale de aquí.
 * El acceso al archivo siempre pasa por attachment.php, que revalida permisos.
 *
 * IMPORTANTE: esta función NO comprueba permisos. Quien la llama debe haber
 * validado antes el acceso al tablero (drawer.php lo hace con has_board_access).
 *
 * @return array<int, array{id:int,kind:string,original_name:string,mime:string,
 *                          size_bytes:int,size_human:string,created_at:string,
 *                          url:string,download_url:string}>
 */
function attach_list_by_task(mysqli $conn, int $taskId): array
{
    if ($taskId <= 0) {
        return [];
    }
    $st = $conn->prepare(
        "SELECT id, kind, original_name, mime, size_bytes, created_at,
                external_url, provider, meta_json
         FROM task_attachments
         WHERE task_id = ?
         ORDER BY id ASC"
    );
    if (!$st) {
        return [];
    }
    $st->bind_param('i', $taskId);
    $st->execute();
    $rows = $st->get_result()->fetch_all(MYSQLI_ASSOC);
    $st->close();

    $out = [];
    foreach ($rows as $r) {
        $id   = (int) $r['id'];
        $kind = (string) $r['kind'];

        // meta_json nunca sale crudo: solo se extrae lo que hace falta.
        $meta    = json_decode((string) ($r['meta_json'] ?? ''), true);
        $meta    = is_array($meta) ? $meta : [];
        $videoId = isset($meta['video_id']) && is_string($meta['video_id']) ? $meta['video_id'] : null;
        $host    = isset($meta['host']) && is_string($meta['host']) ? $meta['host'] : '';

        if (attach_kind_is_external($kind)) {
            $provider  = $r['provider'] !== null ? (string) $r['provider'] : null;
            $embedUrl  = attach_build_embed_url($provider, $videoId);
            $externa   = (string) ($r['external_url'] ?? '');
            if ($host === '' && $externa !== '') {
                $host = attach_safe_display_host((string) (parse_url($externa, PHP_URL_HOST) ?? ''));
            }

            $out[] = [
                'id'            => $id,
                'kind'          => $kind,
                'provider'      => $provider,
                // La URL original solo se expone para enlaces normales, para
                // poder abrirlos. En un embed no hace falta y no se envía.
                'external_url'  => ($kind === 'link') ? $externa : null,
                // Construida desde plantilla propia, jamás desde external_url.
                'embed_url'     => $embedUrl,
                'display_host'  => $host,
                'original_name' => $r['original_name'] !== null ? (string) $r['original_name'] : $host,
                'mime'          => null,
                'size_bytes'    => null,
                'size_human'    => '',
                'created_at'    => (string) $r['created_at'],
                'url'           => null,
                'download_url'  => null,
                'can_download'  => false,
                'can_preview'   => ($kind === 'embed' && $embedUrl !== null),
            ];
            continue;
        }

        // Archivo físico
        $out[] = [
            'id'            => $id,
            'kind'          => $kind,
            'provider'      => null,
            'external_url'  => null,
            'embed_url'     => null,
            'display_host'  => '',
            'original_name' => (string) $r['original_name'],
            'mime'          => (string) $r['mime'],
            'size_bytes'    => (int) $r['size_bytes'],
            'size_human'    => attach_human_size((int) $r['size_bytes']),
            'created_at'    => (string) $r['created_at'],
            'url'           => attach_public_url($id),
            'download_url'  => attach_public_url($id, true),
            'can_download'  => true,
            'can_preview'   => true,
        ];
    }
    return $out;
}

/** ¿Existe la tabla de adjuntos? Patrón de resiliencia de esquema del proyecto. */
function attach_table_exists(mysqli $conn): bool
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    $r = $conn->query("SHOW TABLES LIKE 'task_attachments'");
    $cache = (bool) ($r && $r->fetch_row());
    return $cache;
}

/** Tamaño legible: 812 KB, 4,2 MB… */
function attach_human_size(int $bytes): string
{
    if ($bytes < 1024) {
        return $bytes . ' B';
    }
    if ($bytes < 1048576) {
        return number_format($bytes / 1024, 0, ',', '.') . ' KB';
    }
    return number_format($bytes / 1048576, 1, ',', '.') . ' MB';
}

/** Etiqueta corta del tipo, para mostrar en la tarjeta. */
function attach_kind_label(string $kind): string
{
    switch ($kind) {
        case 'image':
            return 'Imagen';
        case 'audio':
            return 'Audio';
        case 'video':
            return 'Video';
        case 'link':
            return 'Enlace';
        case 'embed':
            return 'Video externo';
        default:
            return 'Archivo';
    }
}

/** Extensiones aceptadas por el input file, en formato accept="". */
function attach_accept_attribute(): string
{
    $exts = array_map(static fn($e) => '.' . $e, array_keys(attach_whitelist()));
    return implode(',', $exts);
}

// ─────────────────────────────────────────────────────────────
// ENLACES EXTERNOS Y EMBEDS
// ─────────────────────────────────────────────────────────────
//
// Regla que no se negocia: la URL que escribe el usuario NUNCA se usa como
// src de un iframe. Para YouTube y Vimeo se extrae un identificador validado
// y el src se construye desde una plantilla propia.
//
// Tampoco se hace ninguna petición HTTP hacia la URL: nada de títulos,
// favicons ni Open Graph. Eso evita SSRF y dependencias externas.

const ATTACH_URL_MAX = 2048;

/** Hosts admitidos por proveedor. Coincidencia EXACTA, nunca por sufijo. */
function attach_provider_hosts(): array
{
    return [
        'youtube' => [
            'youtube.com', 'www.youtube.com', 'm.youtube.com', 'youtu.be',
            'youtube-nocookie.com', 'www.youtube-nocookie.com',
        ],
        'vimeo' => [
            'vimeo.com', 'www.vimeo.com', 'player.vimeo.com',
        ],
    ];
}

/**
 * Valida una URL externa aportada por el usuario.
 *
 * @return array{ok:bool, error?:string, url?:string, host?:string, scheme?:string}
 */
function attach_validate_external_url(string $raw): array
{
    $url = trim($raw);

    if ($url === '') {
        return ['ok' => false, 'error' => 'La URL está vacía.'];
    }
    if (strlen($url) > ATTACH_URL_MAX) {
        return ['ok' => false, 'error' => 'La URL supera los ' . ATTACH_URL_MAX . ' caracteres.'];
    }
    // Saltos de línea o caracteres de control: intento de inyección de cabeceras.
    if (preg_match('/[\x00-\x1F\x7F]/', $url)) {
        return ['ok' => false, 'error' => 'La URL contiene caracteres no válidos.'];
    }

    $parts = parse_url($url);
    if ($parts === false || !is_array($parts)) {
        return ['ok' => false, 'error' => 'La URL no tiene un formato válido.'];
    }

    $scheme = strtolower((string) ($parts['scheme'] ?? ''));
    if ($scheme !== 'http' && $scheme !== 'https') {
        return ['ok' => false, 'error' => 'Solo se admiten direcciones http o https.'];
    }

    // Credenciales embebidas: se rechazan por seguridad y para no almacenarlas.
    if (isset($parts['user']) || isset($parts['pass'])) {
        return ['ok' => false, 'error' => 'La URL no puede incluir usuario ni contraseña.'];
    }

    $host = strtolower(trim((string) ($parts['host'] ?? '')));
    if ($host === '') {
        return ['ok' => false, 'error' => 'La URL no tiene un dominio válido.'];
    }
    // Un host legítimo solo lleva letras, dígitos, puntos y guiones.
    // Esto descarta de paso caracteres Unicode engañosos sin normalizar nada.
    if (!preg_match('/^[a-z0-9.-]+$/', $host) || str_contains($host, '..')) {
        return ['ok' => false, 'error' => 'El dominio de la URL no es válido.'];
    }
    if (!str_contains($host, '.')) {
        return ['ok' => false, 'error' => 'El dominio de la URL no es válido.'];
    }

    return ['ok' => true, 'url' => $url, 'host' => $host, 'scheme' => $scheme];
}

/** Host normalizado para mostrar, sin www. */
function attach_safe_display_host(string $host): string
{
    $h = strtolower(trim($host));
    return preg_replace('/^www\./', '', $h) ?? $h;
}

/**
 * Extrae el ID de un vídeo de YouTube. Devuelve null si el host no es de
 * YouTube o si no hay un ID con el formato exacto de 11 caracteres.
 */
function attach_parse_youtube_id(string $url, string $host): ?string
{
    if (!in_array($host, attach_provider_hosts()['youtube'], true)) {
        return null;   // coincidencia exacta: youtube.com.malicioso.com no entra
    }

    $parts = parse_url($url);
    $path  = (string) ($parts['path'] ?? '');
    $id    = null;

    if ($host === 'youtu.be') {
        // youtu.be/ID
        if (preg_match('#^/([A-Za-z0-9_-]{11})(?:/|$)#', $path, $m)) {
            $id = $m[1];
        }
    } else {
        if (preg_match('#^/embed/([A-Za-z0-9_-]{11})(?:/|$)#', $path, $m)) {
            $id = $m[1];
        } elseif (preg_match('#^/shorts/([A-Za-z0-9_-]{11})(?:/|$)#', $path, $m)) {
            $id = $m[1];
        } elseif ($path === '/watch' || $path === '/watch/') {
            parse_str((string) ($parts['query'] ?? ''), $q);
            $v = isset($q['v']) && is_string($q['v']) ? $q['v'] : '';
            if (preg_match('/^[A-Za-z0-9_-]{11}$/', $v)) {
                $id = $v;
            }
        }
    }

    return $id;
}

/** Extrae el ID numérico de un vídeo de Vimeo. */
function attach_parse_vimeo_id(string $url, string $host): ?string
{
    if (!in_array($host, attach_provider_hosts()['vimeo'], true)) {
        return null;
    }

    $path = (string) (parse_url($url, PHP_URL_PATH) ?? '');

    if ($host === 'player.vimeo.com') {
        if (preg_match('#^/video/(\d{6,15})(?:/|$)#', $path, $m)) {
            return $m[1];
        }
        return null;
    }
    // vimeo.com/NUMERO
    if (preg_match('#^/(\d{6,15})(?:/|$)#', $path, $m)) {
        return $m[1];
    }
    return null;
}

/**
 * Clasifica una URL ya validada.
 *
 * @return array{kind:string, provider:?string, video_id:?string, host:string}
 */
function attach_classify_external_url(string $url, string $host): array
{
    $yt = attach_parse_youtube_id($url, $host);
    if ($yt !== null) {
        return ['kind' => 'embed', 'provider' => 'youtube', 'video_id' => $yt, 'host' => $host];
    }
    $vm = attach_parse_vimeo_id($url, $host);
    if ($vm !== null) {
        return ['kind' => 'embed', 'provider' => 'vimeo', 'video_id' => $vm, 'host' => $host];
    }
    // Incluye el caso "host de YouTube pero sin ID válido": se guarda como
    // enlace normal, nunca como embed.
    return ['kind' => 'link', 'provider' => null, 'video_id' => null, 'host' => $host];
}

/**
 * URL del iframe, construida SIEMPRE desde plantilla propia.
 * Nunca devuelve la URL que escribió el usuario.
 */
function attach_build_embed_url(?string $provider, ?string $videoId): ?string
{
    if ($provider === null || $videoId === null || $videoId === '') {
        return null;
    }
    if ($provider === 'youtube' && preg_match('/^[A-Za-z0-9_-]{11}$/', $videoId)) {
        return 'https://www.youtube-nocookie.com/embed/' . $videoId;
    }
    if ($provider === 'vimeo' && preg_match('/^\d{6,15}$/', $videoId)) {
        return 'https://player.vimeo.com/video/' . $videoId;
    }
    return null;
}

/**
 * Contrato de fuente: una fila es archivo O enlace, nunca ambos ni ninguno.
 * Se comprueba en PHP porque no podemos depender de CHECK en MariaDB 10.6.
 */
function attach_validate_source(?string $storedPath, ?string $externalUrl): bool
{
    $hasFile = ($storedPath !== null && $storedPath !== '');
    $hasUrl  = ($externalUrl !== null && $externalUrl !== '');
    return $hasFile xor $hasUrl;
}

/** ¿Este tipo de adjunto es un enlace o un embed? */
function attach_kind_is_external(string $kind): bool
{
    return $kind === 'link' || $kind === 'embed';
}

/** board_id de una tarea, o null si no existe. */
function attach_board_id_of_task(mysqli $conn, int $taskId): ?int
{
    if ($taskId <= 0) {
        return null;
    }
    $st = $conn->prepare("SELECT board_id FROM tasks WHERE id = ? LIMIT 1");
    if (!$st) {
        return null;
    }
    $st->bind_param('i', $taskId);
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    $st->close();
    return $row ? (int) $row['board_id'] : null;
}

// ─────────────────────────────────────────────────────────────
// RESPUESTAS JSON
// ─────────────────────────────────────────────────────────────

/** Respuesta uniforme {"ok":bool,...}. Nunca incluye rutas internas. */
function attach_json(bool $ok, array $payload = [], int $http = 200): void
{
    if (!headers_sent()) {
        http_response_code($http);
        header('Content-Type: application/json; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
    }
    echo json_encode(array_merge(['ok' => $ok], $payload), JSON_UNESCAPED_UNICODE);
    exit;
}

/** Comprueba el token CSRF del POST contra el de la sesión. */
function attach_csrf_ok(): bool
{
    return isset($_POST['csrf'], $_SESSION['csrf'])
        && is_string($_POST['csrf'])
        && hash_equals((string) $_SESSION['csrf'], (string) $_POST['csrf']);
}

/**
 * Normaliza $_FILES['files'] (array o archivo suelto) a una lista uniforme.
 *
 * @return array<int, array{name:string,type:string,tmp_name:string,error:int,size:int}>
 */
function attach_normalize_files(array $entry): array
{
    if (!isset($entry['name'])) {
        return [];
    }
    if (!is_array($entry['name'])) {
        return [[
            'name'     => (string) $entry['name'],
            'type'     => (string) ($entry['type'] ?? ''),
            'tmp_name' => (string) ($entry['tmp_name'] ?? ''),
            'error'    => (int) ($entry['error'] ?? UPLOAD_ERR_NO_FILE),
            'size'     => (int) ($entry['size'] ?? 0),
        ]];
    }
    $out = [];
    foreach (array_keys($entry['name']) as $i) {
        $out[] = [
            'name'     => (string) ($entry['name'][$i] ?? ''),
            'type'     => (string) ($entry['type'][$i] ?? ''),
            'tmp_name' => (string) ($entry['tmp_name'][$i] ?? ''),
            'error'    => (int) ($entry['error'][$i] ?? UPLOAD_ERR_NO_FILE),
            'size'     => (int) ($entry['size'][$i] ?? 0),
        ];
    }
    return $out;
}

/** URL pública del endpoint que sirve un adjunto. */
function attach_public_url(int $attachmentId, bool $download = false): string
{
    return app_url('tasks/attachment.php?id=' . $attachmentId . ($download ? '&download=1' : ''));
}
