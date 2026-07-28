<?php
// config/bootstrap.php — Arranque común de F&C Planner.
//
// Este archivo SÍ está versionado (a diferencia de config/db.php, que es local
// y está en .gitignore). Por eso vive aquí la configuración que debe viajar
// igual a todos los entornos: zona horaria y construcción de URLs.
//
// Se incluye desde:
//   - public/_auth.php   (cubre todas las páginas web protegidas)
//   - cron/*.php         (procesos de línea de comandos)

// ─────────────────────────────────────────────────────────────
// 1) ZONA HORARIA ÚNICA
// ─────────────────────────────────────────────────────────────
// Toda la aplicación (PHP y la sesión de MySQL) trabaja en la hora de Colombia.
// Se fija de forma explícita para NO depender del huso del sistema operativo,
// que difiere entre el portátil de desarrollo y el servidor de producción.
//
// Colombia no aplica horario de verano, por lo que el desfase es siempre -05:00.
if (!defined('APP_TIMEZONE')) {
    define('APP_TIMEZONE', 'America/Bogota');
}
if (!defined('APP_TIMEZONE_OFFSET')) {
    // Offset fijo para MySQL. Se usa el offset y no el nombre porque las tablas
    // de husos horarios de MySQL (mysql.time_zone*) no siempre están cargadas,
    // sobre todo en instalaciones Windows/Laragon.
    define('APP_TIMEZONE_OFFSET', '-05:00');
}

date_default_timezone_set(APP_TIMEZONE);

/**
 * Alinea la sesión de MySQL con la zona horaria de la aplicación.
 *
 * A partir de aquí NOW() y CURRENT_TIMESTAMP devuelven la misma hora que
 * date() de PHP, sin importar cómo esté configurado el servidor.
 */
function app_sync_db_timezone(?mysqli $conn): void
{
    if (!$conn instanceof mysqli) {
        return;
    }
    static $done = [];
    $key = spl_object_id($conn);
    if (isset($done[$key])) {
        return;
    }
    $done[$key] = true;

    try {
        $stmt = $conn->prepare("SET time_zone = ?");
        if ($stmt) {
            $offset = APP_TIMEZONE_OFFSET;
            $stmt->bind_param('s', $offset);
            $stmt->execute();
            $stmt->close();
        }
    } catch (Throwable $e) {
        // Si el servidor rechazara el SET, se sigue operando con su huso por
        // defecto. No es motivo para tumbar la petición.
    }
}

// ─────────────────────────────────────────────────────────────
// 2) URLs PORTABLES
// ─────────────────────────────────────────────────────────────

/**
 * Prefijo público de la instalación, sin barra final.
 *
 * Devuelve '' cuando public/ es el DocumentRoot del dominio (producción),
 * con lo que las URLs generadas son idénticas a las actuales.
 * Devuelve '/fyc_planner/public' cuando la app cuelga de un subdirectorio.
 *
 * Se deduce comparando la ruta real del script en ejecución con la ruta URL
 * con la que el servidor lo publicó; así funciona incluso con enlaces
 * simbólicos, donde DOCUMENT_ROOT no es fiable.
 */
function app_base(): string
{
    static $base = null;
    if ($base !== null) {
        return $base;
    }

    $publicDir = realpath(dirname(__DIR__) . '/public');
    $scriptFs  = isset($_SERVER['SCRIPT_FILENAME']) ? realpath($_SERVER['SCRIPT_FILENAME']) : false;
    $scriptUrl = $_SERVER['SCRIPT_NAME'] ?? '';

    if ($publicDir !== false && $scriptFs !== false && $scriptUrl !== '') {
        $publicDir = rtrim(str_replace('\\', '/', $publicDir), '/');
        $scriptFs  = str_replace('\\', '/', $scriptFs);

        // En Windows el sistema de archivos no distingue mayúsculas.
        $needle = $publicDir . '/';
        $match  = (DIRECTORY_SEPARATOR === '\\')
            ? (stripos($scriptFs, $needle) === 0)
            : (strpos($scriptFs, $needle) === 0);

        if ($match) {
            $rel = substr($scriptFs, strlen($publicDir));       // p.ej. /boards/trash.php
            if ($rel !== '' && strlen($scriptUrl) >= strlen($rel)
                && substr($scriptUrl, -strlen($rel)) === $rel) {
                $base = rtrim(substr($scriptUrl, 0, strlen($scriptUrl) - strlen($rel)), '/');
                return $base;
            }
        }
    }

    // Último recurso: comportamiento histórico (public/ como DocumentRoot).
    $base = '';
    return $base;
}

/**
 * Construye una URL absoluta desde la raíz pública de la aplicación.
 *
 *   app_url('login.php')                  -> /login.php            (producción)
 *                                         -> /fyc_planner/public/login.php (local)
 *   app_url('boards/workspace.php?x=1')   -> .../boards/workspace.php?x=1
 */
function app_url(string $path = ''): string
{
    return app_base() . '/' . ltrim($path, '/');
}

/**
 * Redirige a una ruta interna y termina la ejecución.
 */
function redirect_to(string $path): void
{
    header('Location: ' . app_url($path));
    exit;
}
