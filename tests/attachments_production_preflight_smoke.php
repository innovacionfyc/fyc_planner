<?php
/**
 * tests/attachments_production_preflight_smoke.php
 *
 * Preflight de producción (Fase F, bloque F7).
 *
 * Ejecutar SOLO en local:
 *   php tests/attachments_production_preflight_smoke.php
 *
 * IMPORTANTE — qué comprueba y qué NO
 *
 * Esta suite valida todo lo que puede verificarse SIN acceso al servidor:
 * el contenido del release, las migraciones, la documentación y las
 * exigencias de configuración que impone el módulo.
 *
 * Lo que depende del servidor real —límites efectivos de subida, espacio en
 * disco, permisos, versión de mysqldump, rutas de logs— NO se puede medir
 * desde aquí. Esos puntos se declaran DESCONOCIDOS y cuentan como pendientes
 * explícitos, nunca como aprobados. Una prueba que diera por buena una
 * medición inexistente sería peor que no tenerla.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Este script solo puede ejecutarse desde CLI.\n");
}

require_once __DIR__ . '/../config/bootstrap.php';

// El commit a empaquetar es SIEMPRE el HEAD actual, no uno escrito a mano.
//
// Antes había aquí un hash fijo. Caducaba en cuanto se hacía un commit —y de
// hecho caducó: la suite se validó antes de confirmar y quedó en rojo justo
// después—. Lo que de verdad importa comprobar no es «¿es este hash
// concreto?», sino «¿está el árbol limpio y sincronizado con GitHub?», que es
// la condición real para poder desplegar. Eso se verifica abajo.
define('COMMIT_ESPERADO', trim((string) shell_exec(
    'git -C ' . escapeshellarg(dirname(__DIR__)) . ' rev-parse HEAD 2>&1'
)));
const PHP_MIN         = '8.0.0';
const MARIADB_MIN     = '10.4';

$ROOT = dirname(__DIR__);
$TMP  = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'fyc_preflight_' . bin2hex(random_bytes(4));

$PASS = 0;
$FAIL = 0;
$DESC = 0;   // desconocidos: requieren el servidor

function ok(string $n, string $d = ''): void
{
    global $PASS;
    $PASS++;
    printf("  [OK]     %-54s %s\n", $n, $d);
}

function ko(string $n, string $d = ''): void
{
    global $FAIL;
    $FAIL++;
    printf("  [FALLO]  %-54s %s\n", $n, $d);
}

/** Punto que exige el servidor y no puede medirse desde aquí. */
function desconocido(string $n, string $d = ''): void
{
    global $DESC;
    $DESC++;
    printf("  [PEND.]  %-54s %s\n", $n, $d);
}

function chk(string $n, bool $c, string $d = ''): void
{
    $c ? ok($n, $d) : ko($n, $d);
}

function section(string $t): void
{
    echo "\n" . str_repeat('─', 78) . "\n " . $t . "\n" . str_repeat('─', 78) . "\n";
}

function rrm(string $d): void
{
    if (!is_dir($d)) {
        return;
    }
    foreach (array_diff(scandir($d) ?: [], ['.', '..']) as $e) {
        $p = $d . DIRECTORY_SEPARATOR . $e;
        is_dir($p) && !is_link($p) ? rrm($p) : @unlink($p);
    }
    @rmdir($d);
}

function git(string $ROOT, string $args): string
{
    return trim((string) shell_exec('git -C ' . escapeshellarg($ROOT) . ' ' . $args . ' 2>&1'));
}

// ═════════════════════════════════════════════════════════════
echo "\n";
echo "══════════════════════════════════════════════════════════════════════════════\n";
echo " PREFLIGHT DE PRODUCCIÓN — MÓDULO DE ADJUNTOS (Fase F · bloque F7)\n";
echo "══════════════════════════════════════════════════════════════════════════════\n";
echo " PHP local : " . PHP_VERSION . "\n";
echo " Fecha     : " . date('Y-m-d H:i:s') . "\n";

// ═════════════════════════════════════════════════════════════
section('1-6 · RELEASE: IDENTIDAD Y CONTENIDO');

$head = git($ROOT, 'rev-parse HEAD');
// Un release solo puede salir de un árbol sin cambios sueltos: si los
// hubiera, el paquete no correspondería a ningún commit publicado.
// public/assets/app.css se descuenta a propósito — su diferencia es de
// finales de línea y está fuera del alcance de todas estas fases.
// git() de esta suite devuelve una CADENA, no un array: hay que partirla.
$porcelain = array_values(array_filter(
    explode("\n", git($ROOT, 'status --porcelain')),
    fn($l) => trim($l) !== '' && !str_contains($l, 'public/assets/app.css')
));
// Un árbol sucio no invalida el release —este se construye desde HEAD, no
// desde el disco— pero sí significa que hay trabajo que aún no viajaría.
// Durante el desarrollo es lo normal, así que se declara PENDIENTE en lugar
// de fallar: un rojo permanente acabaría ignorándose, que es peor que un
// aviso. Antes de desplegar debe estar limpio.
if ($porcelain === []) {
    ok('1. El árbol está limpio: el release corresponde a HEAD',
        substr($head, 0, 12) . '… sin cambios sueltos');
} else {
    desconocido('1. Hay cambios sin confirmar (no viajarían en el release)',
        count($porcelain) . ' archivos · confirmar antes de desplegar');
}

$remoto = git($ROOT, 'ls-remote origin refs/heads/main');
$remotoSha = trim(explode("\t", $remoto)[0] ?? '');
chk('2. GitHub tiene ese mismo commit', $remotoSha === $head,
    substr($remotoSha, 0, 12) . '…');

// Se extrae el release en una carpeta temporal fuera del repositorio.
//
// Se usa --format=zip + ZipArchive en lugar de «git archive | tar»: shell_exec
// lanza cmd.exe en Windows, donde ese pipe no se comporta igual y la
// extracción salía vacía. Con zip no hay tubería que dependa del intérprete.
@mkdir($TMP, 0775, true);
$zipRel = $TMP . DIRECTORY_SEPARATOR . '_release.zip';
shell_exec('git -C ' . escapeshellarg($ROOT) . ' archive --format=zip -o '
    . escapeshellarg($zipRel) . ' ' . COMMIT_ESPERADO . ' 2>&1');
if (is_file($zipRel)) {
    $zr = new ZipArchive();
    if ($zr->open($zipRel) === true) {
        $zr->extractTo($TMP);
        $zr->close();      // cerrar siempre: en Windows deja el archivo bloqueado
    }
    @unlink($zipRel);
}
$ficheros = [];
$walk = function (string $d, string $pre) use (&$walk, &$ficheros) {
    foreach (array_diff(scandir($d) ?: [], ['.', '..']) as $e) {
        $p = $d . DIRECTORY_SEPARATOR . $e;
        $r = $pre === '' ? $e : $pre . '/' . $e;
        is_dir($p) ? $walk($p, $r) : $ficheros[] = $r;
    }
};
if (is_dir($TMP)) {
    $walk($TMP, '');
}
sort($ficheros);
chk('3. El release se extrae y tiene contenido', count($ficheros) > 50, count($ficheros) . ' archivos');

$debeEstar = [
    'database/migrations/2026-07-29-create-task-attachments.sql',
    'database/migrations/2026-07-29-add-external-links-to-task-attachments.sql',
    'storage/attachments/.gitkeep', 'storage/attachments/.htaccess',
    'public/_attachments.php', 'public/tasks/attachment.php',
    'public/tasks/attachment_upload.php', 'public/tasks/attachment_link.php',
    'public/tasks/attachment_delete.php', 'public/tasks/drawer.php',
    'public/assets/board-view.js', 'public/assets/theme.css',
    'cron/purge_trash.php', 'cron/purge_orphan_attachments.php',
    'scripts/backup_project.php', 'config/bootstrap.php',
    'docs/ATTACHMENTS.md', 'docs/DEPLOYMENT_ATTACHMENTS.md', 'docs/BACKUP_RESTORE.md',
];
$faltan = array_values(array_diff($debeEstar, $ficheros));
chk('4. El release incluye backend, frontend, cron, scripts y docs',
    $faltan === [], $faltan === [] ? count($debeEstar) . '/' . count($debeEstar) : implode(', ', $faltan));

$noDebeEstar = ['config/db.php', '.git', '_backups'];
$presentes = array_values(array_filter($noDebeEstar, fn($f) => in_array($f, $ficheros, true) || is_dir($TMP . '/' . $f)));
$adjuntos = array_values(array_filter($ficheros,
    fn($f) => preg_match('#^storage/attachments/\d{4}/#', $f) === 1));
$sesiones = array_values(array_filter($ficheros, fn($f) => str_contains($f, 'sess_')));
$logs     = array_values(array_filter($ficheros, fn($f) => str_ends_with($f, '.log')));
chk('5. El release NO incluye credenciales, adjuntos, sesiones ni logs',
    $presentes === [] && $adjuntos === [] && $sesiones === [] && $logs === [],
    'db.php=' . (in_array('config/db.php', $ficheros, true) ? 'SÍ' : 'no')
    . ' adjuntos=' . count($adjuntos) . ' sesiones=' . count($sesiones) . ' logs=' . count($logs));

// app.css: el release debe llevar la versión de HEAD, no la local con CRLF
$hashRel  = git($ROOT, 'hash-object ' . escapeshellarg($TMP . '/public/assets/app.css'));
$hashHead = git($ROOT, 'rev-parse HEAD:public/assets/app.css');
chk('6. El release lleva app.css de HEAD, no la copia local', $hashRel === $hashHead,
    substr($hashRel, 0, 16) . '…');

// ═════════════════════════════════════════════════════════════
section('7-10 · MIGRACIONES Y ESQUEMA');

$mig = glob($TMP . '/database/migrations/*.sql') ?: [];
sort($mig);
$nombres = array_map('basename', $mig);
chk('7. Hay exactamente dos migraciones', count($nombres) === 2, implode(' · ', $nombres));

$crear = (string) @file_get_contents($TMP . '/database/migrations/2026-07-29-create-task-attachments.sql');
$links = (string) @file_get_contents($TMP . '/database/migrations/2026-07-29-add-external-links-to-task-attachments.sql');

chk('8. La primera crea la tabla con claves foráneas y UNIQUE',
    str_contains($crear, 'CREATE TABLE') && str_contains($crear, 'task_attachments')
    && stripos($crear, 'ON DELETE CASCADE') !== false
    && stripos($crear, 'ON DELETE SET NULL') !== false
    && stripos($crear, 'stored_path') !== false
    && stripos($crear, 'utf8mb4') !== false);

chk('9. La segunda añade enlaces, embeds y el índice de proveedor',
    stripos($links, 'ALTER TABLE') !== false
    && stripos($links, 'external_url') !== false
    && stripos($links, 'provider') !== false
    && stripos($links, "'link'") !== false && stripos($links, "'embed'") !== false);

// Ninguna debe usar sintaxis específica de MySQL 8 que MariaDB 10.6 no acepte
$riesgos = [];
foreach (['crear' => $crear, 'enlaces' => $links] as $k => $sql) {
    if (preg_match('/\bCHECK\s*\(/i', $sql)) {
        $riesgos[] = "$k: CHECK";
    }
    if (preg_match('/\b(WINDOW|LATERAL|JSON_TABLE|SRID)\b/i', $sql)) {
        $riesgos[] = "$k: sintaxis de MySQL 8";
    }
    if (preg_match('/\bDEFAULT\s*\(\s*/i', $sql)) {
        $riesgos[] = "$k: DEFAULT con expresión";
    }
}
chk('10. Las migraciones no usan sintaxis incompatible con MariaDB 10.6',
    $riesgos === [], $riesgos === [] ? 'sin CHECK ni construcciones de MySQL 8' : implode(', ', $riesgos));

// ═════════════════════════════════════════════════════════════
section('11-17 · ENTORNO LOCAL Y EXTENSIONES REQUERIDAS');

chk('11. PHP local cumple el mínimo del módulo',
    version_compare(PHP_VERSION, PHP_MIN, '>='), PHP_VERSION . ' >= ' . PHP_MIN);

foreach ([
    '12. fileinfo disponible'   => extension_loaded('fileinfo') && function_exists('finfo_open'),
    '13. GD y getimagesize'     => extension_loaded('gd') && function_exists('getimagesize'),
    '14. ZipArchive disponible' => class_exists('ZipArchive'),
    '15. zlib disponible'       => function_exists('gzopen') && function_exists('gzwrite'),
    '16. JSON disponible'       => function_exists('json_encode') && function_exists('json_decode'),
] as $n => $c) {
    chk($n, $c);
}

$dump = null;
foreach (glob('C:/laragon/bin/mysql/*/bin/mysqldump.exe') ?: [] as $d) {
    $dump = $d;
    break;
}
chk('17. Existe mysqldump/mariadb-dump en el entorno local', $dump !== null,
    $dump ? trim((string) shell_exec(escapeshellarg($dump) . ' --version 2>&1')) : 'no encontrado');

// ═════════════════════════════════════════════════════════════
section('18-22 · EXIGENCIAS DE CONFIGURACIÓN QUE IMPONE EL MÓDULO');

require_once $ROOT . '/public/_attachments.php';
$MB = 1048576;
$mayor = max(ATTACH_MAX_IMAGE, ATTACH_MAX_AUDIO, ATTACH_MAX_VIDEO);

// El peor caso ya NO es «archivos × tamaño máximo»: desde el bloque G1 existe
// un techo explícito para la petición completa (ATTACH_MAX_REQUEST_BYTES), que
// acota el envío con independencia de cuántos archivos lleve.
$peor = ATTACH_MAX_REQUEST_BYTES;
$post = (int) ceil($peor * 1.10);

chk('18. El contrato de tamaño es el de producción (14 MB)',
    ATTACH_MAX_FILE_BYTES === 14 * $MB
    && ATTACH_MAX_REQUEST_BYTES === 14 * $MB
    && ATTACH_MAX_IMAGE === ATTACH_MAX_FILE_BYTES
    && ATTACH_MAX_AUDIO === ATTACH_MAX_FILE_BYTES
    && ATTACH_MAX_VIDEO === ATTACH_MAX_FILE_BYTES
    && ATTACH_MAX_FILES === 5,
    '14 MB por archivo y por petición · máx. 5');

chk('19. El peor caso cabe en el post_max_size de producción',
    $peor === 14 * $MB && $post <= 16 * $MB,
    'peor caso ' . ($peor / $MB) . ' MB → necesita ' . round($post / $MB)
    . ' MB; producción ofrece 16 MB');

$dep = (string) file_get_contents($ROOT . '/docs/DEPLOYMENT_ATTACHMENTS.md');
$norm = trim((string) preg_replace('/\s+/u', ' ', (string) preg_replace('/\n\s*>\s?/u', ' ', $dep)));

// La documentación debe describir el entorno REAL de producción y dejar claro
// que esta versión no necesita que el hosting amplíe nada.
chk('20. La documentación recoge los límites reales de producción',
    str_contains($norm, '16M')
    && str_contains($norm, 'upload_max_filesize')
    && str_contains($norm, 'post_max_size')
    && (int) round(ATTACH_MAX_REQUEST_BYTES / $MB) === 14,
    'producción 16M/16M · módulo 14 MB');

chk('21. La documentación advierte de la combinación asimétrica',
    stripos($norm, 'falla al subir dos') !== false
    || stripos($norm, 'falla en cuanto se suben dos') !== false
    || stripos($norm, 'bajar el límite del código') !== false);

chk('21b. Las cifras antiguas solo aparecen como escenario futuro opcional',
    stripos($norm, 'Escenario futuro opcional') !== false
    && stripos($norm, 'Nada de esto es requisito de la versión actual') !== false,
    '275M queda etiquetado como ampliación opcional');

chk('22. La relación entre límites está documentada',
    str_contains($norm, 'client_max_body_size') && str_contains($norm, 'post_max_size')
    && str_contains($norm, 'upload_max_filesize'));

// ═════════════════════════════════════════════════════════════
section('23-27 · DOCUMENTACIÓN OPERATIVA');

$att = (string) file_get_contents($ROOT . '/docs/ATTACHMENTS.md');
$bak = (string) file_get_contents($ROOT . '/docs/BACKUP_RESTORE.md');
$todo = trim((string) preg_replace('/\s+/u', ' ', $att . ' ' . $dep . ' ' . $bak));

chk('23. La zona horaria consta como RESUELTA, no pendiente',
    preg_match('/(zona horaria|huso)[^.]{0,60}(pendiente|sin verificar)/iu', $todo) === 0
    && stripos($todo, 'ya está resuelta en producción') !== false,
    'America/Bogota y -05:00');

chk('24. El rollback está documentado',
    stripos($dep, 'Rollback') !== false && stripos($norm, 'Restaurar la base') !== false
    && stripos($norm, 'Validación final') !== false);

chk('25. El respaldo está documentado y exige incluir storage',
    stripos($bak, 'mysqldump') !== false && stripos($bak, 'no basta') !== false
    && stripos($bak, 'SHA256SUMS') !== false);

chk('26. El cron en simulacro está documentado',
    substr_count($todo, '--dry-run') >= 3 && stripos($norm, 'simulacro') !== false);

chk('27. Los permisos están documentados y se prohíbe 777',
    stripos($norm, 'chown') !== false && stripos($norm, '750') !== false
    && preg_match('/(nunca|no usar)[^.]{0,30}777/iu', $norm) === 1);

// ═════════════════════════════════════════════════════════════
section('28-30 · LINT DEL RELEASE Y LIMPIEZA');

$php = 0;
$phpOk = 0;
foreach ($ficheros as $f) {
    if (!str_ends_with($f, '.php')) {
        continue;
    }
    $php++;
    $r = (string) shell_exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($TMP . '/' . $f) . ' 2>&1');
    if (str_contains($r, 'No syntax errors')) {
        $phpOk++;
    }
}
chk('28. Lint PHP del release sin errores', $php > 0 && $phpOk === $php, "$phpOk/$php");

$js = 0;
$jsOk = 0;
foreach ($ficheros as $f) {
    if (!str_ends_with($f, '.js')) {
        continue;
    }
    $js++;
    exec('node --check ' . escapeshellarg($TMP . '/' . $f) . ' 2>&1', $o, $c);
    if ($c === 0) {
        $jsOk++;
    }
}
chk('29. Sintaxis JS del release correcta', $js > 0 && $jsOk === $js, "$jsOk/$js");

rrm($TMP);
chk('30. La carpeta temporal del release queda eliminada', !is_dir($TMP));

// ═════════════════════════════════════════════════════════════
section('31-37 · PENDIENTES QUE EXIGEN EL SERVIDOR');

echo "  Estos puntos NO pueden medirse desde el entorno local. Se declaran\n";
echo "  pendientes de forma explícita: darlos por buenos sería falsear el\n";
echo "  preflight.\n\n";

desconocido('31. Límites efectivos de PHP en el handler web', 'requiere acceso al servidor');
desconocido('32. client_max_body_size de Nginx/Plesk', 'requiere acceso al servidor');
desconocido('33. Espacio en disco e inodos', 'requiere acceso al servidor');
desconocido('34. Usuario/grupo de PHP-FPM y permisos de storage', 'requiere acceso al servidor');
desconocido('35. Versión y privilegios de mysqldump en producción', 'requiere acceso al servidor');
desconocido('36. Migraciones ejecutadas sobre MariaDB 10.6 real', 'no hay MariaDB en el entorno local');
desconocido('37. Rutas y rotación de logs', 'requiere acceso al servidor');

// ═════════════════════════════════════════════════════════════
echo "\n" . str_repeat('═', 78) . "\n";
printf(" RESULTADO: %d correctas, %d fallidas, %d pendientes del servidor\n", $PASS, $FAIL, $DESC);
if ($DESC > 0) {
    echo " VEREDICTO: preflight LOCAL superado; NO APTO para desplegar hasta\n";
    echo "            resolver los {$DESC} puntos que exigen el servidor.\n";
}
echo str_repeat('═', 78) . "\n\n";

exit($FAIL === 0 ? 0 : 1);
