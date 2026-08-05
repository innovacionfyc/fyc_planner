<?php
/**
 * tests/attachments_backup_smoke.php
 *
 * Pruebas del respaldo completo (Fase F, bloque F2):
 * scripts/backup_project.php — base de datos + storage + manifiesto.
 *
 * Ejecutar SOLO en local:
 *   php tests/attachments_backup_smoke.php
 *
 * Qué hace:
 *   1. Crea adjuntos QA temporales (archivo físico, enlace y embed).
 *   2. Ejecuta el respaldo de verdad y verifica cada artefacto.
 *   3. Simula la restauración en carpetas temporales y compara inventarios.
 *   4. Fuerza fallos a propósito para comprobar la limpieza.
 *   5. Borra todo lo que ha creado, dentro y fuera del repositorio.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Este script solo puede ejecutarse desde CLI.\n");
}

require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../public/_attachments.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
app_sync_db_timezone($conn);

const QA_TAG = 'QA BACKUP 2026-07-31';

$ROOT   = dirname(__DIR__);
$SCRIPT = $ROOT . '/scripts/backup_project.php';
$SANDBOX = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'fyc_backup_sandbox';

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

/** Ejecuta el script de respaldo y devuelve [código, salida]. */
function correr(array $args = []): array
{
    global $SCRIPT;
    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($SCRIPT);
    foreach ($args as $a) {
        $cmd .= ' ' . escapeshellarg($a);
    }
    $out = [];
    $code = 0;
    exec($cmd . ' 2>&1', $out, $code);
    return [$code, implode("\n", $out)];
}

function borrar_recursivo(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    foreach (array_diff(scandir($dir) ?: [], ['.', '..']) as $e) {
        $p = $dir . DIRECTORY_SEPARATOR . $e;
        is_dir($p) && !is_link($p) ? borrar_recursivo($p) : @unlink($p);
    }
    @rmdir($dir);
}

/** Última carpeta de respaldo creada dentro de $base. */
function ultimo_respaldo(string $base): ?string
{
    $d = glob($base . '/fyc_planner_backup_*') ?: [];
    if ($d === []) {
        return null;
    }
    sort($d);
    return end($d);
}

function abs_of(string $rel): string
{
    return attach_storage_root() . DIRECTORY_SEPARATOR
        . str_replace('/', DIRECTORY_SEPARATOR, $rel);
}

function existe(string $p): bool
{
    clearstatcache(true, $p);
    return is_file($p);
}

// ─────────────────────────────────────────────────────────────
function cleanup(mysqli $conn): array
{
    $files = 0;
    $q = $conn->query(
        "SELECT a.stored_path FROM task_attachments a
          JOIN tasks t ON t.id = a.task_id
          JOIN boards b ON b.id = t.board_id
         WHERE b.nombre LIKE '" . QA_TAG . "%' AND a.stored_path IS NOT NULL"
    );
    foreach ($q->fetch_all(MYSQLI_ASSOC) as $r) {
        if (attach_delete_file((string) $r['stored_path'])) {
            $files++;
        }
    }
    $conn->query("DELETE FROM boards WHERE nombre LIKE '" . QA_TAG . "%'");
    return ['boards' => $conn->affected_rows, 'files' => $files];
}

/** Inventario del almacén real. */
function scan_storage(): array
{
    $root = attach_storage_root();
    $out  = [];
    $walk = function (string $d, string $pre) use (&$walk, &$out) {
        foreach (array_diff(scandir($d) ?: [], ['.', '..']) as $e) {
            $p = $d . DIRECTORY_SEPARATOR . $e;
            $r = $pre === '' ? $e : $pre . '/' . $e;
            if (is_link($p)) {
                continue;
            }
            if (is_dir($p)) {
                $walk($p, $r);
            } elseif (is_file($p)) {
                $out[$r] = hash_file('sha256', $p);
            }
        }
    };
    $walk($root, '');
    ksort($out);
    return $out;
}

// ═════════════════════════════════════════════════════════════
echo "\n";
echo "══════════════════════════════════════════════════════════════════════════════\n";
echo " PRUEBAS DE RESPALDO COMPLETO — BASE + STORAGE + MANIFIESTO (Fase F · F2)\n";
echo "══════════════════════════════════════════════════════════════════════════════\n";
echo " Base  : " . $conn->query('SELECT DATABASE()')->fetch_row()[0] . "\n";
echo " PHP   : " . PHP_VERSION . "\n";
echo " Fecha : " . date('Y-m-d H:i:s') . "\n";

section('PREPARACIÓN');
cleanup($conn);
borrar_recursivo($SANDBOX);
@mkdir($SANDBOX, 0775, true);

$storageInicial = scan_storage();
printf("  almacén al empezar: %d archivos\n", count($storageInicial));

// Datos QA: tablero + columna + tarea + tres adjuntos (archivo, enlace, embed)
$U = 2;
$st = $conn->prepare("INSERT INTO boards (nombre,color_hex,owner_user_id,team_id) VALUES (?, '#8e44ad', ?, NULL)");
$bn = QA_TAG;
$st->bind_param('si', $bn, $U);
$st->execute();
$BOARD = (int) $conn->insert_id;
$st->close();

$st = $conn->prepare("INSERT INTO `columns` (board_id,nombre,orden,is_done) VALUES (?, 'QA Col', 1, 0)");
$st->bind_param('i', $BOARD);
$st->execute();
$COL = (int) $conn->insert_id;
$st->close();

$st = $conn->prepare("INSERT INTO tasks (board_id,column_id,titulo,prioridad) VALUES (?,?, 'QA Respaldo', 'med')");
$st->bind_param('ii', $BOARD, $COL);
$st->execute();
$TASK = (int) $conn->insert_id;
$st->close();

// Archivo físico real dentro del almacén
$relQA = date('Y') . '/' . date('m') . '/' . bin2hex(random_bytes(16)) . '.jpg';
$absQA = abs_of($relQA);
@mkdir(dirname($absQA), 0775, true);
$im = imagecreatetruecolor(32, 24);
imagefill($im, 0, 0, imagecolorallocate($im, 120, 60, 200));
imagejpeg($im, $absQA, 88);
imagedestroy($im);
$shaQA = hash_file('sha256', $absQA);

$st = $conn->prepare("INSERT INTO task_attachments (task_id,uploaded_by,kind,original_name,stored_path,mime,size_bytes) VALUES (?,?, 'image','respaldo qa.jpg',?, 'image/jpeg', ?)");
$tam = (int) filesize($absQA);
$st->bind_param('iisi', $TASK, $U, $relQA, $tam);
$st->execute();
$st->close();

$st = $conn->prepare("INSERT INTO task_attachments (task_id,uploaded_by,kind,original_name,external_url,provider) VALUES (?,?, 'link','example.com', 'https://example.com/doc', NULL)");
$st->bind_param('ii', $TASK, $U);
$st->execute();
$st->close();

$st = $conn->prepare("INSERT INTO task_attachments (task_id,uploaded_by,kind,original_name,external_url,provider,meta_json) VALUES (?,?, 'embed','YouTube', 'https://www.youtube.com/watch?v=dQw4w9WgXcQ', 'youtube', ?)");
$meta = json_encode(['host' => 'www.youtube.com', 'video_id' => 'dQw4w9WgXcQ']);
$st->bind_param('iis', $TASK, $U, $meta);
$st->execute();
$st->close();

printf("  QA creado: board=%d task=%d · 1 archivo + 1 enlace + 1 embed\n", $BOARD, $TASK);

$filasTotal = (int) $conn->query('SELECT COUNT(*) FROM task_attachments')->fetch_row()[0];
printf("  task_attachments: %d filas\n", $filasTotal);

// ═════════════════════════════════════════════════════════════
section('1-6 · INTERFAZ, VALIDACIÓN Y SIMULACRO');

[$c, $o] = correr(['--help']);
chk('1. --help documenta las opciones y sale con 0',
    $c === 0 && str_contains($o, '--output=RUTA') && str_contains($o, '--dry-run'), "exit=$c");

$src = file_get_contents($SCRIPT);
chk('2. Ejecución fuera de CLI rechazada',
    str_contains($src, "PHP_SAPI !== 'cli'") && str_contains($src, 'http_response_code(403)'));

$antes = glob($SANDBOX . '/*') ?: [];
[$c, $o] = correr(['--output=' . $SANDBOX, '--dry-run']);
$despues = glob($SANDBOX . '/*') ?: [];
chk('3. --dry-run no crea ningún archivo',
    $c === 0 && count($antes) === count($despues) && str_contains($o, 'SIMULACRO'),
    'archivos antes=' . count($antes) . ' después=' . count($despues));

[$c, ] = correr(['--output=' . $SANDBOX, '--storage-format=rar']);
chk('4. Salida inválida rechazada con código 2', $c === 2, "exit=$c");

$traversal = 0;
foreach (['--label=../fuera', '--label=a/b', '--label=..', '--output=../../fuera'] as $mal) {
    [$c, ] = correr([$mal]);
    if ($c === 2) {
        $traversal++;
    }
}
chk('5. Etiqueta y salida con path traversal rechazadas', $traversal === 4, "$traversal/4 rechazadas");

[$c1, ] = correr(['--output=' . $SANDBOX, '--label=uno']);
[$c2, ] = correr(['--output=' . $SANDBOX, '--label=dos']);
$carpetas = glob($SANDBOX . '/fyc_planner_backup_*') ?: [];
chk('6. Cada respaldo crea una carpeta única',
    $c1 === 0 && $c2 === 0 && count($carpetas) === 2 && count(array_unique($carpetas)) === 2,
    count($carpetas) . ' carpetas');

// ═════════════════════════════════════════════════════════════
section('7-12 · DUMP DE LA BASE DE DATOS');

$BK = ultimo_respaldo($SANDBOX);
$sqlGz = $BK . '/database.sql.gz';

chk('7. El dump se ha creado', existe($sqlGz), basename((string) $BK));

$sql = '';
$gz = gzopen($sqlGz, 'rb');
while ($gz && !gzeof($gz)) {
    $sql .= gzread($gz, 262144);
}
if ($gz) {
    gzclose($gz);
}

chk('8. El dump contiene CREATE TABLE', substr_count($sql, 'CREATE TABLE') > 0,
    substr_count($sql, 'CREATE TABLE') . ' sentencias');
chk('9. El dump contiene task_attachments',
    str_contains($sql, 'CREATE TABLE `task_attachments`')
    && str_contains($sql, 'INSERT INTO `task_attachments`'),
    'estructura y datos');

$tablasReales = (int) $conn->query(
    'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = "BASE TABLE"'
)->fetch_row()[0];
$tablasDump = preg_match_all('/^CREATE TABLE /mi', $sql);
chk('10. El dump trae todas las tablas', $tablasDump === $tablasReales,
    "dump=$tablasDump base=$tablasReales");

chk('11. El SQL comprimido se abre y es legible',
    strlen($sql) > 1000 && str_contains($sql, 'utf8mb4'), strlen($sql) . ' bytes descomprimidos');

$man = json_decode((string) file_get_contents($BK . '/manifest.json'), true);
chk('12. El SHA-256 del dump coincide con el manifiesto',
    hash_file('sha256', $sqlGz) === ($man['database']['sha256'] ?? ''),
    substr((string) ($man['database']['sha256'] ?? ''), 0, 24) . '…');

// ═════════════════════════════════════════════════════════════
section('13-17 · ARCHIVO DE STORAGE');

$zipPath = $BK . '/storage_attachments.zip';
chk('13. El archivo de storage se ha creado', existe($zipPath));

$zip = new ZipArchive();
$abierto = $zip->open($zipPath, ZipArchive::CHECKCONS) === true;
$entradas = [];
if ($abierto) {
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $entradas[] = $zip->getNameIndex($i);
    }
    // Cerrar es obligatorio: en Windows un ZipArchive abierto deja el
    // archivo bloqueado y la limpieza final no podría borrarlo.
    $zip->close();
}
chk('14. El archivo incluye .gitkeep',
    in_array('storage/attachments/.gitkeep', $entradas, true));
chk('15. El archivo incluye .htaccess',
    in_array('storage/attachments/.htaccess', $entradas, true));
chk('16. Se preserva la estructura AAAA/MM',
    in_array('storage/attachments/' . $relQA, $entradas, true),
    'incluye ' . $relQA);
chk('17. El SHA-256 del storage coincide con el manifiesto',
    hash_file('sha256', $zipPath) === ($man['storage']['sha256'] ?? ''),
    substr((string) ($man['storage']['sha256'] ?? ''), 0, 24) . '…');

// ═════════════════════════════════════════════════════════════
section('18-26 · MANIFIESTO');

chk('18. manifest.json es JSON válido y completo',
    is_array($man)
    && isset($man['generated_at'], $man['timezone'], $man['project_commit'],
        $man['database'], $man['storage'], $man['module'], $man['environment'],
        $man['restore_order'], $man['notes']),
    count($man ?? []) . ' claves de primer nivel');

// No debe filtrarse ninguna credencial.
//
// Se comprueban los VALORES reales y los NOMBRES de clave. Buscar palabras
// sueltas en el texto no sirve: la nota del propio manifiesto explica que no
// guarda la contraseña, y esa frase dispararía la alarma sin haber fuga.
$crudo = (string) file_get_contents($BK . '/manifest.json');
$fugas = [];

foreach (['usuario' => $DB_USER, 'contraseña' => $DB_PASS, 'host' => $DB_HOST] as $k => $v) {
    if (is_string($v) && strlen($v) >= 3 && stripos($crudo, $v) !== false) {
        $fugas[] = 'valor de ' . $k;
    }
}

// Recorrido de claves a cualquier profundidad
$revisarClaves = function (array $a, string $ruta = '') use (&$revisarClaves, &$fugas): void {
    foreach ($a as $k => $v) {
        $actual = $ruta === '' ? (string) $k : $ruta . '.' . $k;
        if (is_string($k) && preg_match('/pass|passwd|secret|credential|db_user|dbuser/i', $k)) {
            $fugas[] = 'clave ' . $actual;
        }
        if (is_array($v)) {
            $revisarClaves($v, $actual);
        }
    }
};
$revisarClaves(is_array($man) ? $man : []);

chk('19. El manifiesto no contiene credenciales',
    $fugas === [],
    $fugas === [] ? 'ni valores ni claves de usuario, contraseña o host' : implode(', ', $fugas));

$commitReal = trim((string) shell_exec('git -C ' . escapeshellarg($ROOT) . ' rev-parse HEAD 2>&1'));
chk('20. El commit del manifiesto es el real',
    ($man['project_commit'] ?? '') === $commitReal, substr($commitReal, 0, 12) . '…');

$ramaReal = trim((string) shell_exec('git -C ' . escapeshellarg($ROOT) . ' rev-parse --abbrev-ref HEAD 2>&1'));
chk('21. La rama del manifiesto es la real', ($man['project_branch'] ?? '') === $ramaReal, $ramaReal);

chk('22. Motor y versión correctos',
    ($man['database']['version'] ?? '') === $conn->server_info
    && in_array($man['database']['engine'] ?? '', ['MySQL', 'MariaDB'], true),
    ($man['database']['engine'] ?? '?') . ' ' . ($man['database']['version'] ?? '?'));

chk('23. Zona horaria registrada en PHP y en la base',
    ($man['environment']['app_timezone'] ?? '') === APP_TIMEZONE
    && ($man['environment']['db_session_timezone'] ?? '') === APP_TIMEZONE_OFFSET,
    APP_TIMEZONE . ' / ' . ($man['environment']['db_session_timezone'] ?? '?'));

$fisicas = (int) $conn->query("SELECT COUNT(*) FROM task_attachments WHERE stored_path IS NOT NULL AND stored_path <> ''")->fetch_row()[0];
$enlaces = (int) $conn->query("SELECT COUNT(*) FROM task_attachments WHERE kind = 'link'")->fetch_row()[0];
$embeds  = (int) $conn->query("SELECT COUNT(*) FROM task_attachments WHERE kind = 'embed'")->fetch_row()[0];
chk('24. Los conteos de adjuntos del manifiesto son correctos',
    ($man['module']['attachment_rows'] ?? -1) === $filasTotal
    && ($man['module']['physical_file_rows'] ?? -1) === $fisicas
    && ($man['module']['link_rows'] ?? -1) === $enlaces
    && ($man['module']['embed_rows'] ?? -1) === $embeds,
    "filas=$filasTotal físicos=$fisicas enlaces=$enlaces embeds=$embeds");

$archivosReales = count(scan_storage());
chk('25. El conteo de archivos del manifiesto es correcto',
    ($man['storage']['file_count'] ?? -1) === $archivosReales,
    ($man['storage']['file_count'] ?? '?') . ' vs ' . $archivosReales);

$bytesReales = 0;
foreach (array_keys(scan_storage()) as $r) {
    $bytesReales += (int) filesize(abs_of($r));
}
chk('26. El tamaño total sin comprimir es correcto',
    ($man['storage']['total_uncompressed_bytes'] ?? -1) === $bytesReales,
    ($man['storage']['total_uncompressed_bytes'] ?? '?') . ' vs ' . $bytesReales . ' bytes');

// ═════════════════════════════════════════════════════════════
section('27-29 · RESTAURACIÓN SIMULADA');

$restDir = $SANDBOX . DIRECTORY_SEPARATOR . 'restauracion';
@mkdir($restDir, 0775, true);

// 27. SQL descomprimido a un archivo temporal
$sqlRest = $restDir . DIRECTORY_SEPARATOR . 'restaurado.sql';
$in = gzopen($sqlGz, 'rb');
$out = fopen($sqlRest, 'wb');
while ($in && !gzeof($in)) {
    fwrite($out, (string) gzread($in, 262144));
}
if ($in) {
    gzclose($in);
}
fclose($out);
chk('27. El SQL se restaura a un archivo temporal',
    existe($sqlRest) && filesize($sqlRest) === ($man['database']['uncompressed_bytes'] ?? -1),
    filesize($sqlRest) . ' bytes = los del manifiesto');

// 28. storage extraído a carpeta temporal
$stRest = $restDir . DIRECTORY_SEPARATOR . 'storage_out';
@mkdir($stRest, 0775, true);
$z = new ZipArchive();
$extraido = false;
if ($z->open($zipPath) === true) {
    $extraido = $z->extractTo($stRest);
    $z->close();
}
chk('28. El storage se extrae a una carpeta temporal',
    $extraido && is_dir($stRest . '/storage/attachments'), 'extraído sin errores');

// 29. inventario restaurado idéntico al original (ruta + hash)
$raizRest = $stRest . '/storage/attachments';
$invRest = [];
$walk = function (string $d, string $pre) use (&$walk, &$invRest) {
    foreach (array_diff(scandir($d) ?: [], ['.', '..']) as $e) {
        $p = $d . DIRECTORY_SEPARATOR . $e;
        $r = $pre === '' ? $e : $pre . '/' . $e;
        is_dir($p) ? $walk($p, $r) : $invRest[$r] = hash_file('sha256', $p);
    }
};
if (is_dir($raizRest)) {
    $walk($raizRest, '');
}
ksort($invRest);
$invOrig = scan_storage();
chk('29. El inventario restaurado coincide byte a byte',
    $invRest === $invOrig && ($invRest[$relQA] ?? '') === $shaQA,
    count($invRest) . ' archivos, hashes idénticos');

// ═════════════════════════════════════════════════════════════
section('30-35 · FALLOS, COLISIONES Y CASOS LÍMITE');

// 30. fallo forzado: mysqldump inválido → debe limpiar lo creado
$antesFallo = count(glob($SANDBOX . '/fyc_planner_backup_*') ?: []);
[$cF, $oF] = correr(['--output=' . $SANDBOX, '--label=fallo', '--mysqldump=' . PHP_BINARY]);
$despuesFallo = count(glob($SANDBOX . '/fyc_planner_backup_*') ?: []);
chk('30. Un fallo limpia el respaldo incompleto',
    $cF === 1 && $antesFallo === $despuesFallo
    && count(glob($SANDBOX . '/*fallo*') ?: []) === 0,
    "exit=$cF carpetas antes=$antesFallo después=$despuesFallo");

// 31. no sobrescribir un respaldo previo: se pre-crea la carpeta del segundo
//     actual y se comprueba que el script se niega. Se sincroniza con el
//     borde del segundo para que el nombre no cambie a mitad.
$colision = false;
for ($intento = 0; $intento < 4 && !$colision; $intento++) {
    usleep((int) ((1 - (microtime(true) - floor(microtime(true)))) * 1000000) + 50000);
    $nombreCol = 'fyc_planner_backup_' . date('Ymd_His') . '_colision';
    $dirCol = $SANDBOX . DIRECTORY_SEPARATOR . $nombreCol;
    @mkdir($dirCol, 0775, true);
    file_put_contents($dirCol . '/CENTINELA.txt', 'respaldo anterior valido');

    [$cC, $oC] = correr(['--output=' . $SANDBOX, '--label=colision']);
    if ($cC === 1 && str_contains($oC, 'ya existe')) {
        $colision = existe($dirCol . '/CENTINELA.txt');
        break;
    }
    // El segundo cambió entre medias: se limpia y se reintenta.
    borrar_recursivo($dirCol);
    foreach (glob($SANDBOX . '/*_colision') ?: [] as $g) {
        borrar_recursivo($g);
    }
}
chk('31. Nunca se sobrescribe un respaldo previo', $colision,
    $colision ? 'el centinela del respaldo anterior sigue intacto' : 'no se pudo provocar la colisión');
foreach (glob($SANDBOX . '/*_colision') ?: [] as $g) {
    borrar_recursivo($g);
}

// 32. un enlace dentro del almacén no se sigue ni se incluye
$dirDestino = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'fyc_bk_link_target';
@mkdir($dirDestino, 0775, true);
file_put_contents($dirDestino . DIRECTORY_SEPARATOR . 'ajeno.txt', 'no debe entrar en el respaldo');
$dirEnlace = attach_storage_root() . DIRECTORY_SEPARATOR . '2998';
if (is_dir($dirEnlace)) {
    @rmdir($dirEnlace);
}
clearstatcache(true);
$salida = [];
$codeMk = 0;
exec('mklink /J "' . $dirEnlace . '" "' . $dirDestino . '" 2>&1', $salida, $codeMk);

if ($codeMk === 0) {
    [$cL, ] = correr(['--output=' . $SANDBOX, '--label=symlink']);
    $bkL = null;
    foreach (glob($SANDBOX . '/*_symlink') ?: [] as $g) {
        $bkL = $g;
    }
    $contiene = false;
    if ($bkL !== null && is_file($bkL . '/storage_attachments.zip')) {
        $zl = new ZipArchive();
        if ($zl->open($bkL . '/storage_attachments.zip') === true) {
            for ($i = 0; $i < $zl->numFiles; $i++) {
                if (str_contains((string) $zl->getNameIndex($i), 'ajeno.txt')
                    || str_contains((string) $zl->getNameIndex($i), '2998')) {
                    $contiene = true;
                }
            }
            $zl->close();
        }
    }
    chk('32. El enlace simbólico no se sigue ni se incluye',
        $cL === 0 && !$contiene && existe($dirDestino . DIRECTORY_SEPARATOR . 'ajeno.txt'),
        'el destino queda fuera del respaldo y sobrevive');
    @rmdir($dirEnlace);
} else {
    ko('32. El enlace simbólico no se sigue ni se incluye',
        'no se pudo crear el enlace: ' . trim(implode(' ', $salida)));
}
@unlink($dirDestino . DIRECTORY_SEPARATOR . 'ajeno.txt');
@rmdir($dirDestino);
clearstatcache(true);

// 33. ruta de salida con espacios
$conEspacios = $SANDBOX . DIRECTORY_SEPARATOR . 'carpeta con espacios';
@mkdir($conEspacios, 0775, true);
[$cE, $oE] = correr(['--output=' . $conEspacios, '--label=espacios']);
$bkE = ultimo_respaldo($conEspacios);
chk('33. Funciona con rutas que llevan espacios',
    $cE === 0 && $bkE !== null && existe($bkE . '/manifest.json'),
    "exit=$cE");

// 34. idempotencia: el archivo de storage es idéntico si nada ha cambiado
[$cI1, ] = correr(['--output=' . $SANDBOX, '--label=idem1']);
[$cI2, ] = correr(['--output=' . $SANDBOX, '--label=idem2']);
$i1 = glob($SANDBOX . '/*_idem1/storage_attachments.zip')[0] ?? '';
$i2 = glob($SANDBOX . '/*_idem2/storage_attachments.zip')[0] ?? '';
chk('34. Dos ejecuciones seguidas dan el mismo archivo de storage',
    $i1 !== '' && $i2 !== '' && hash_file('sha256', $i1) === hash_file('sha256', $i2),
    'mismo SHA-256 con el almacén sin cambios');

// 35. códigos de salida
$codigos = [];
[$codigos['help'], ] = correr(['--help']);
[$codigos['ok'], ] = correr(['--output=' . $SANDBOX, '--label=exitcode']);
[$codigos['uso'], ] = correr(['--argumento-inexistente']);
[$codigos['dryrun'], ] = correr(['--output=' . $SANDBOX, '--dry-run']);
chk('35. Los códigos de salida son coherentes',
    $codigos['help'] === 0 && $codigos['ok'] === 0
    && $codigos['uso'] === 2 && $codigos['dryrun'] === 0,
    'help=0 ok=0 uso=2 simulacro=0');

// Extras de seguridad del script
section('36-38 · SEGURIDAD DEL SCRIPT');

chk('36. La contraseña no viaja en la línea de comandos',
    str_contains($src, '--defaults-extra-file=')
    && !preg_match('/[\x27"]--password=/', $src)
    && !preg_match('/\'-p\'\s*\.\s*\$DB_PASS/', $src),
    'se usa un archivo de opciones temporal');

chk('37. El archivo temporal de credenciales se borra siempre',
    substr_count($src, '@unlink($cnfTemporal)') >= 1
    && str_contains($src, 'chmod($cnfTemporal, 0600)'),
    'permisos restrictivos y borrado tras el dump');

chk('38. config/db.php nunca entra en el respaldo',
    !str_contains($src, "'config/db.php'") || !str_contains($src, 'addFile'),
    'solo se archiva storage/attachments');

// ═════════════════════════════════════════════════════════════
section('LIMPIEZA FINAL');

borrar_recursivo($SANDBOX);
$post = cleanup($conn);
clearstatcache(true);

$storageFinal = scan_storage();
$filasFinal = (int) $conn->query('SELECT COUNT(*) FROM task_attachments')->fetch_row()[0];
$qaBoards = (int) $conn->query("SELECT COUNT(*) FROM boards WHERE nombre LIKE '" . QA_TAG . "%'")->fetch_row()[0];

// Carpetas AAAA/MM vacías creadas por la prueba
$root = attach_storage_root();
foreach (array_diff(scandir($root) ?: [], ['.', '..']) as $y) {
    if (!preg_match('/^\d{4}$/', $y) || !is_dir("$root/$y")) {
        continue;
    }
    foreach (array_diff(scandir("$root/$y") ?: [], ['.', '..']) as $m) {
        if (is_dir("$root/$y/$m") && count(array_diff(scandir("$root/$y/$m") ?: [], ['.', '..'])) === 0) {
            @rmdir("$root/$y/$m");
        }
    }
    if (count(array_diff(scandir("$root/$y") ?: [], ['.', '..'])) === 0) {
        @rmdir("$root/$y");
    }
}
clearstatcache(true);

printf("  QA eliminado: %d tableros, %d archivos\n", $post['boards'], $post['files']);
printf("  sandbox borrado: %s\n", is_dir($SANDBOX) ? 'NO' : 'sí');
printf("  almacén: %d archivos (al empezar: %d)\n", count($storageFinal), count($storageInicial));

chk('39. El almacén queda como estaba', $storageFinal === $storageInicial,
    count($storageFinal) . ' vs ' . count($storageInicial));
chk('40. No quedan filas ni tableros QA', $filasFinal === 0 && $qaBoards === 0,
    "filas=$filasFinal tableros=$qaBoards");
chk('41. No queda ningún respaldo de prueba fuera del repositorio', !is_dir($SANDBOX));
chk('42. No hay respaldos dentro del repositorio',
    !is_dir($ROOT . '/_backups') || count(glob($ROOT . '/_backups/*') ?: []) === 0,
    'la carpeta _backups del proyecto sigue vacía');

// ═════════════════════════════════════════════════════════════
echo "\n" . str_repeat('═', 78) . "\n";
printf(" RESULTADO: %d correctas, %d fallidas\n", $PASS, $FAIL);
echo str_repeat('═', 78) . "\n\n";

exit($FAIL === 0 ? 0 : 1);
