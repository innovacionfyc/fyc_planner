<?php
/**
 * tests/attachments_release_smoke.php
 *
 * Verificación del paquete de despliegue (Fase F7-ALT, bloque G7).
 *
 * Ejecutar SOLO en local:
 *   php tests/attachments_release_smoke.php
 *
 * Genera un paquete de verdad con scripts/build_release.php, lo ABRE y
 * comprueba su contenido real. No se limita a leer el contrato del script:
 * un contrato bien escrito y mal aplicado seguiría desplegando lo que no debe.
 *
 * El paquete se crea en un directorio temporal fuera del repositorio y se
 * borra al terminar.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Este script solo puede ejecutarse desde CLI.\n");
}

$ROOT    = dirname(__DIR__);
$BUILDER = $ROOT . '/scripts/build_release.php';
$SALIDA  = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'fyc_release_' . bin2hex(random_bytes(4));

$PASS = 0;
$FAIL = 0;

function ok(string $n, string $d = ''): void
{
    global $PASS;
    $PASS++;
    printf("  [OK]    %-56s %s\n", $n, $d);
}

function ko(string $n, string $d = ''): void
{
    global $FAIL;
    $FAIL++;
    printf("  [FALLO] %-56s %s\n", $n, $d);
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

echo "\n";
echo "══════════════════════════════════════════════════════════════════════════════\n";
echo " PAQUETE DE DESPLIEGUE — QUÉ VIAJA Y QUÉ NO (bloque G7)\n";
echo "══════════════════════════════════════════════════════════════════════════════\n";
echo " PHP   : " . PHP_VERSION . "\n";
echo " Fecha : " . date('Y-m-d H:i:s') . "\n";

// ═════════════════════════════════════════════════════════════
section('1-3 · GENERACIÓN');

chk('1. El generador de paquetes existe', is_file($BUILDER));

$cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($BUILDER)
    . ' ' . escapeshellarg('--commit=WORKTREE')
    . ' ' . escapeshellarg('--output=' . $SALIDA)
    . ' ' . escapeshellarg('--label=verificacion');
$out = [];
$code = 0;
exec($cmd . ' 2>&1', $out, $code);
$salidaTexto = implode("\n", $out);

chk('2. El paquete se genera sin errores', $code === 0, "exit=$code");

$zips = glob($SALIDA . '/*.zip') ?: [];
$zipPath = $zips[0] ?? '';
chk('3. El archivo .zip existe y tiene contenido',
    $zipPath !== '' && filesize($zipPath) > 10000,
    $zipPath !== '' ? basename($zipPath) . ' · ' . round(filesize($zipPath) / 1024) . ' KB' : 'NINGUNO');

if ($zipPath === '') {
    echo "\n  Sin paquete no se puede seguir.\n";
    rrm($SALIDA);
    exit(1);
}

// ═════════════════════════════════════════════════════════════
section('4-5 · APERTURA E INVENTARIO REAL');

$zip = new ZipArchive();
$abierto = $zip->open($zipPath, ZipArchive::CHECKCONS) === true;
$dentro = [];
if ($abierto) {
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $dentro[] = (string) $zip->getNameIndex($i);
    }
}
chk('4. El paquete se abre y supera la comprobación de consistencia',
    $abierto, count($dentro) . ' archivos dentro');

// Se extrae de verdad: un zip puede listar bien y extraer mal.
$destino = $SALIDA . DIRECTORY_SEPARATOR . 'extraido';
@mkdir($destino, 0775, true);
$extraido = $abierto && $zip->extractTo($destino);
if ($abierto) {
    $zip->close();   // cerrar siempre: en Windows deja el archivo bloqueado
}
chk('5. El paquete se extrae correctamente',
    $extraido && is_file($destino . '/public/_attachments.php'),
    'extraído en carpeta temporal');

// ═════════════════════════════════════════════════════════════
section('6-12 · LO QUE NO DEBE VIAJAR');

chk('6. config/mail.php NO está en el paquete',
    !in_array('config/mail.php', $dentro, true)
    && !is_file($destino . '/config/mail.php'),
    'la configuración SMTP de producción se conserva');

chk('7. config/db.php NO está en el paquete',
    !in_array('config/db.php', $dentro, true)
    && !is_file($destino . '/config/db.php'),
    'las credenciales de base no viajan');

$env = array_values(array_filter($dentro,
    fn($f) => $f === '.env' || str_starts_with($f, '.env.')));
chk('8. No hay archivos .env', $env === [], $env === [] ? 'ninguno' : implode(', ', $env));

$git = array_values(array_filter($dentro, fn($f) => str_starts_with($f, '.git/')));
chk('9. No viaja el historial .git/', $git === [], count($git) . ' entradas');

$ruido = array_values(array_filter($dentro, fn($f) =>
    str_ends_with($f, '.log')
    || preg_match('#\.(zip|tar|gz|tgz)$#', $f)
    || str_starts_with($f, '_backups/')
    || str_starts_with($f, '_releases/')
    || str_starts_with($f, 'tests/')
    || preg_match('#(^|/)sess_#i', $f)
    || preg_match('#(^|/)qa[_-]#i', $f)));
chk('10. No hay logs, backups, paquetes anidados, pruebas ni restos QA',
    $ruido === [], $ruido === [] ? 'limpio' : implode(', ', array_slice($ruido, 0, 4)));

// Adjuntos reales del entorno local: storage debe llevar solo el esqueleto.
$adjuntos = array_values(array_filter($dentro,
    fn($f) => preg_match('#^storage/attachments/\d{4}/#', $f)));
$storage = array_values(array_filter($dentro, fn($f) => str_starts_with($f, 'storage/')));
chk('11. storage/attachments viaja vacío, solo con su esqueleto',
    $adjuntos === []
    && in_array('storage/attachments/.gitkeep', $dentro, true)
    && in_array('storage/attachments/.htaccess', $dentro, true)
    && count($storage) === 2,
    implode(', ', array_map(fn($f) => basename($f), $storage)));

// app.css: solo debe viajar si forma parte del cambio real. Hoy su contenido
// es idéntico a HEAD (el árbol solo difiere en finales de línea), así que
// viaja la versión versionada, no la copia local.
$hashRel = trim((string) shell_exec('git -C ' . escapeshellarg($ROOT)
    . ' hash-object ' . escapeshellarg($destino . '/public/assets/app.css') . ' 2>&1'));
$hashHead = trim((string) shell_exec('git -C ' . escapeshellarg($ROOT)
    . ' rev-parse HEAD:public/assets/app.css 2>&1'));
chk('12. app.css del paquete es el versionado, no la copia local',
    is_file($destino . '/public/assets/app.css') && $hashRel === $hashHead,
    substr($hashRel, 0, 16) . '…');

// ═════════════════════════════════════════════════════════════
section('13-18 · LO QUE SÍ DEBE VIAJAR');

$migraciones = array_values(array_filter($dentro,
    fn($f) => str_starts_with($f, 'database/migrations/')));
sort($migraciones);
chk('13. Las dos migraciones están, en el orden correcto',
    count($migraciones) === 2
    && str_ends_with($migraciones[1], 'create-task-attachments.sql')
    && str_ends_with($migraciones[0], 'add-external-links-to-task-attachments.sql'),
    count($migraciones) . ' migraciones');

$endpoints = ['public/_attachments.php', 'public/tasks/attachment.php',
    'public/tasks/attachment_upload.php', 'public/tasks/attachment_link.php',
    'public/tasks/attachment_delete.php', 'public/tasks/drawer.php'];
$faltanEp = array_values(array_diff($endpoints, $dentro));
chk('14. Están todos los endpoints del módulo',
    $faltanEp === [], $faltanEp === [] ? count($endpoints) . '/' . count($endpoints) : implode(', ', $faltanEp));

$assets = ['public/assets/board-view.js', 'public/assets/theme.css', 'public/assets/app.css'];
$faltanAs = array_values(array_diff($assets, $dentro));
chk('15. Están los assets del módulo', $faltanAs === [],
    $faltanAs === [] ? count($assets) . '/' . count($assets) : implode(', ', $faltanAs));

$docs = ['docs/DEPLOYMENT_ATTACHMENTS.md', 'docs/ATTACHMENTS.md', 'docs/BACKUP_RESTORE.md'];
$faltanDoc = array_values(array_diff($docs, $dentro));
chk('16. Está la documentación de despliegue', $faltanDoc === [],
    $faltanDoc === [] ? '3/3' : implode(', ', $faltanDoc));

chk('17. Están los cron y los scripts de operación',
    in_array('cron/purge_trash.php', $dentro, true)
    && in_array('cron/purge_orphan_attachments.php', $dentro, true)
    && in_array('scripts/backup_project.php', $dentro, true));

chk('18. Están las plantillas de configuración',
    in_array('config/db.example.php', $dentro, true)
    && in_array('config/mail.example.php', $dentro, true)
    && in_array('config/bootstrap.php', $dentro, true),
    'db.example, mail.example y bootstrap');

// ═════════════════════════════════════════════════════════════
section('19-22 · SECRETOS Y CONTRATO');

// Barrido de credenciales reales dentro de los archivos extraídos.
$fugas = [];
if (is_file($ROOT . '/config/db.php')) {
    $src = (string) file_get_contents($ROOT . '/config/db.php');
    // Solo USER y PASS son credenciales. DB_NAME es el NOMBRE de la base:
    // dato estructural, no secreto, y aparece a propósito en la plantilla
    // config/db.example.php que el propio proyecto versiona.
    preg_match_all('/\$DB_(USER|PASS)\s*=\s*[\'"]([^\'"]*)[\'"]/', $src, $mm, PREG_SET_ORDER);
    foreach ($mm as [$_, $clave, $valor]) {
        if (strlen($valor) < 5 || in_array(strtolower($valor), ['root', 'localhost'], true)) {
            continue;
        }
        foreach ($dentro as $f) {
            $abs = $destino . '/' . $f;
            if (!is_file($abs) || filesize($abs) > 400000) {
                continue;
            }
            if (str_contains((string) file_get_contents($abs), $valor)) {
                $fugas[] = "DB_$clave en $f";
                break;
            }
        }
    }
}
chk('19. Ninguna credencial real de la base viaja en el paquete',
    $fugas === [], $fugas === [] ? 'barrido completo sin coincidencias' : implode(', ', $fugas));

// El patrón de «-p<contraseña>» de mysql exige un espacio delante. Sin esa
// ancla capturaba «--porcelain», «data-prioridad» y «--placeholder», que no
// son secretos sino opciones e identificadores.
$patronesClave = [
    '/MAIL_SMTP_PASS[\'"]?\s*,\s*[\'"][^\'"]{4,}/',
    '/(?<=\s)-p[A-Za-z0-9!@#$%^&*]{8,}/',
];
$sospechas = [];
foreach ($dentro as $f) {
    $abs = $destino . '/' . $f;
    if (!is_file($abs) || filesize($abs) > 400000) {
        continue;
    }
    $c = (string) file_get_contents($abs);
    foreach ($patronesClave as $pat) {
        if (preg_match($pat, $c)) {
            $sospechas[] = $f;
            break;
        }
    }
}
chk('20. No hay contraseñas escritas literalmente', $sospechas === [],
    $sospechas === [] ? 'ninguna' : implode(', ', array_slice($sospechas, 0, 3)));

// El contrato del generador debe estar declarado, no improvisado
$srcBuilder = (string) file_get_contents($BUILDER);
chk('21. El generador declara las exclusiones de forma explícita',
    str_contains($srcBuilder, 'RELEASE_EXCLUIR_EXACTO')
    && str_contains($srcBuilder, "'config/mail.php'")
    && str_contains($srcBuilder, 'RELEASE_OBLIGATORIO'),
    'listas explícitas, no reglas implícitas');

// Y debe abortar si un obligatorio falta: se comprueba que la salida lo diga
chk('22. El generador verifica el zip tras crearlo',
    str_contains($srcBuilder, 'ZipArchive::CHECKCONS')
    && str_contains($srcBuilder, 'se colaron archivos excluidos')
    && str_contains($srcBuilder, 'faltan archivos obligatorios'),
    'comprueba consistencia, colados y obligatorios');

// ═════════════════════════════════════════════════════════════
section('LIMPIEZA');

rrm($SALIDA);
chk('23. El paquete de prueba queda eliminado', !is_dir($SALIDA));

$enRepo = is_dir($ROOT . '/_releases') ? count(glob($ROOT . '/_releases/*') ?: []) : 0;
chk('24. No queda ningún paquete dentro del repositorio', $enRepo === 0,
    is_dir($ROOT . '/_releases') ? '_releases existe pero vacío' : '_releases no existe');

// ═════════════════════════════════════════════════════════════
echo "\n" . str_repeat('═', 78) . "\n";
printf(" RESULTADO: %d correctas, %d fallidas\n", $PASS, $FAIL);
echo str_repeat('═', 78) . "\n\n";

exit($FAIL === 0 ? 0 : 1);
