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

/**
 * URL de un recurso estático con marca de versión, para que el navegador
 * deje de servir una copia vieja cuando el archivo cambia.
 *
 *   asset_url('assets/theme.css')  -> /fyc_planner/public/assets/theme.css?v=1753...
 *                                  -> /assets/theme.css?v=1753...        (producción)
 *
 * El número es la fecha de modificación del archivo: cambia solo cuando el
 * archivo cambia, y no hay que acordarse de subir ningún contador a mano.
 * Antes las versiones se escribían a mano (?v=1, ?v=2) y quedaban desfasadas
 * en cuanto alguien editaba el archivo sin tocar la plantilla.
 *
 * Solo se llama con rutas literales escritas por nosotros: nunca con datos
 * que vengan del usuario. Aun así valida, porque una regresión futura no
 * debe convertirse en una lectura de archivos arbitraria.
 *
 * Devuelve cadena vacía si la ruta no es aceptable. Si el archivo no existe
 * devuelve la URL sin versión: la página sigue funcionando y en el log queda
 * constancia, sin revelar jamás la ruta física.
 */
function asset_url(string $relativePath): string
{
    static $cache = [];
    if (isset($cache[$relativePath])) {
        return $cache[$relativePath];
    }

    $p = $relativePath;

    // 1) Un byte nulo trunca la ruta en las llamadas al sistema: fuera.
    if ($p === '' || str_contains($p, "\0")) {
        error_log('[asset_url] ruta vacía o con byte nulo');
        return $cache[$relativePath] = '';
    }

    // 2) Nada de URLs absolutas ni de esquema. Se comprueba antes de
    //    normalizar para que //evil.com no se cuele como ruta relativa.
    if (preg_match('#^[a-zA-Z][a-zA-Z0-9+.-]*:#', $p) || str_starts_with($p, '//')) {
        error_log('[asset_url] se rechazó una URL absoluta o con esquema');
        return $cache[$relativePath] = '';
    }

    // 3) Rutas absolutas: Linux (/x) y Windows (C:\x, \\servidor).
    if ($p[0] === '/' || $p[0] === '\\' || preg_match('#^[A-Za-z]:#', $p)) {
        error_log('[asset_url] se rechazó una ruta absoluta');
        return $cache[$relativePath] = '';
    }

    // 4) Las barras invertidas no pintan nada en una URL, y son la vía
    //    habitual para colar ..\ en Windows.
    if (str_contains($p, '\\')) {
        error_log('[asset_url] se rechazó una ruta con barras invertidas');
        return $cache[$relativePath] = '';
    }

    // 5) Separar la query si la hubiera, para no confundirla con la ruta.
    $query = '';
    if (($pos = strpos($p, '?')) !== false) {
        $query = substr($p, $pos + 1);
        $p     = substr($p, 0, $pos);
    }

    // 6) Colapsar separadores repetidos y comprobar segmentos.
    //
    // Se rechaza cualquier segmento formado solo por puntos, no únicamente
    // «.» y «..». Variantes como «....//» existen para burlar los filtros que
    // BORRAN las secuencias «../»: al quitarlas queda «../» otra vez. Este
    // filtro no borra nada, así que la variante no lo engañaría, pero un
    // segmento de puntos no tiene ningún uso legítimo en la ruta de un
    // recurso y descartarlo no cuesta nada.
    $p = preg_replace('#/+#', '/', $p) ?? '';
    foreach (explode('/', $p) as $seg) {
        if ($seg === '' || preg_match('/^\.+$/', $seg)) {
            error_log('[asset_url] se rechazó una ruta con segmentos relativos');
            return $cache[$relativePath] = '';
        }
    }

    // 7) El archivo debe existir DENTRO de public/. Doble barrera: la
    //    comprobación de segmentos de arriba y esta contención con realpath.
    $publicDir = realpath(dirname(__DIR__) . '/public');
    $full      = $publicDir !== false
        ? realpath($publicDir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $p))
        : false;

    $url = app_url($p) . ($query !== '' ? '?' . $query : '');

    if ($publicDir === false || $full === false) {
        // No existe: se sirve sin versión. El log no lleva la ruta física.
        error_log('[asset_url] recurso no encontrado: ' . $p);
        return $cache[$relativePath] = $url;
    }

    $raiz   = rtrim(str_replace('\\', '/', $publicDir), '/') . '/';
    $fullN  = str_replace('\\', '/', $full);
    $dentro = (DIRECTORY_SEPARATOR === '\\')
        ? (stripos($fullN, $raiz) === 0)     // Windows no distingue mayúsculas
        : (strpos($fullN, $raiz) === 0);

    if (!$dentro || !is_file($full)) {
        error_log('[asset_url] la ruta apunta fuera de public/');
        return $cache[$relativePath] = '';
    }

    $mtime = @filemtime($full);
    if ($mtime === false) {
        return $cache[$relativePath] = $url;
    }

    // Si ya venía con query, la versión se añade con & en vez de ?
    return $cache[$relativePath] = $url . ($query !== '' ? '&' : '?') . 'v=' . $mtime;
}
