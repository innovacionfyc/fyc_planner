<?php
/**
 * scripts/backup_project.php
 *
 * Respaldo completo del módulo de adjuntos: base de datos + storage +
 * manifiesto verificable.
 *
 * POR QUÉ EXISTE
 *   Desde la Fase A los adjuntos viven en DOS sitios: la fila en
 *   task_attachments y el archivo en storage/attachments. Un mysqldump por
 *   sí solo ya NO restaura el sistema: devolvería las fichas sin los
 *   archivos, y cada adjunto aparecería roto. Hay que respaldar los dos a la
 *   vez y dejar constancia de que casan.
 *
 * USO
 *   php scripts/backup_project.php --help
 *   php scripts/backup_project.php --dry-run
 *   php scripts/backup_project.php
 *   php scripts/backup_project.php --output=D:/respaldos --label=predeploy
 *
 * En Plesk/Linux:
 *   php /var/www/vhosts/<dominio>/<carpeta>/scripts/backup_project.php \
 *       --output=/var/www/vhosts/<dominio>/backups --label=predeploy
 *
 * CREDENCIALES
 *   Se leen de config/db.php (que NO se respalda ni se imprime nunca) y se
 *   pasan a mysqldump por un archivo temporal de opciones, no por la línea
 *   de comandos: en un servidor compartido cualquiera puede leer la lista de
 *   procesos y ahí se vería la contraseña.
 *
 * CÓDIGOS DE SALIDA
 *   0  respaldo correcto
 *   1  fallo durante la ejecución (se limpia lo que quedó a medias)
 *   2  argumento inválido
 *   3  falta un requisito del entorno (mysqldump, extensiones…)
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Este script solo puede ejecutarse desde CLI.\n");
}

const EXIT_OK      = 0;
const EXIT_FALLO   = 1;
const EXIT_USO     = 2;
const EXIT_ENTORNO = 3;

$ROOT = dirname(__DIR__);

// ─────────────────────────────────────────────────────────────
// Ayuda
// ─────────────────────────────────────────────────────────────
function ayuda(): void
{
    echo <<<TXT
Respaldo completo de F&C Planner: base de datos + storage/attachments.

Uso:
  php scripts/backup_project.php [opciones]

Opciones:
  --output=RUTA        Carpeta donde crear el respaldo (por defecto: _backups)
  --label=ETIQUETA     Sufijo para el nombre  [A-Za-z0-9._-], máx. 40
  --dry-run            Enseña lo que haría sin escribir nada
  --db-only            Solo la base de datos
  --storage-only       Solo storage/attachments
  --storage-format=F   zip (por defecto) o targz
  --mysqldump=RUTA     Ruta al ejecutable mysqldump
  --help               Esta ayuda

Salidas: 0 correcto · 1 fallo · 2 argumento inválido · 3 falta un requisito

TXT;
}

// ─────────────────────────────────────────────────────────────
// Argumentos
// ─────────────────────────────────────────────────────────────
$opt = [
    'output'         => null,
    'label'          => '',
    'dry-run'        => false,
    'db-only'        => false,
    'storage-only'   => false,
    'storage-format' => 'zip',
    'mysqldump'      => null,
];

foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--help' || $arg === '-h') {
        ayuda();
        exit(EXIT_OK);
    }
    if ($arg === '--dry-run' || $arg === '-n') {
        $opt['dry-run'] = true;
        continue;
    }
    if ($arg === '--db-only') {
        $opt['db-only'] = true;
        continue;
    }
    if ($arg === '--storage-only') {
        $opt['storage-only'] = true;
        continue;
    }
    if (preg_match('/^--(output|label|storage-format|mysqldump)=(.*)$/', $arg, $m)) {
        $opt[$m[1]] = $m[2];
        continue;
    }
    fwrite(STDERR, "Argumento no reconocido: {$arg}\n");
    ayuda();
    exit(EXIT_USO);
}

if ($opt['db-only'] && $opt['storage-only']) {
    fwrite(STDERR, "--db-only y --storage-only se excluyen entre sí.\n");
    exit(EXIT_USO);
}
if (!in_array($opt['storage-format'], ['zip', 'targz'], true)) {
    fwrite(STDERR, "Formato no soportado: {$opt['storage-format']} (usa zip o targz)\n");
    exit(EXIT_USO);
}

// La etiqueta acaba formando parte de un nombre de carpeta. Se restringe a
// un alfabeto seguro para que no pueda escaparse del directorio de salida
// ni introducir separadores.
if ($opt['label'] !== '' && !preg_match('/^[A-Za-z0-9._-]{1,40}$/', $opt['label'])) {
    fwrite(STDERR, "Etiqueta inválida: solo A-Za-z0-9._- y hasta 40 caracteres.\n");
    exit(EXIT_USO);
}
if ($opt['label'] !== '' && (str_contains($opt['label'], '..') || $opt['label'] === '.')) {
    fwrite(STDERR, "Etiqueta inválida.\n");
    exit(EXIT_USO);
}

// ─────────────────────────────────────────────────────────────
// Entorno
// ─────────────────────────────────────────────────────────────
require_once $ROOT . '/config/bootstrap.php';

foreach (['hash' => 'hash_file', 'zlib' => 'gzopen'] as $ext => $fn) {
    if (!function_exists($fn)) {
        fwrite(STDERR, "Falta la extensión requerida: {$ext}\n");
        exit(EXIT_ENTORNO);
    }
}
if ($opt['storage-format'] === 'zip' && !class_exists('ZipArchive')) {
    fwrite(STDERR, "ZipArchive no está disponible; usa --storage-format=targz\n");
    exit(EXIT_ENTORNO);
}
if ($opt['storage-format'] === 'targz' && !class_exists('PharData')) {
    fwrite(STDERR, "PharData no está disponible; usa --storage-format=zip\n");
    exit(EXIT_ENTORNO);
}

// config/db.php define $DB_HOST, $DB_USER, $DB_PASS, $DB_NAME y $conn.
// Estas variables NO se imprimen, ni se escriben en el manifiesto, ni se
// pasan por la línea de comandos en ningún momento.
require_once $ROOT . '/config/db.php';
/** @var mysqli $conn */
app_sync_db_timezone($conn);

// ─────────────────────────────────────────────────────────────
// Utilidades
// ─────────────────────────────────────────────────────────────
function paso(string $m): void
{
    echo '  ' . $m . "\n";
}

function fallo(string $m): void
{
    fwrite(STDERR, '  ERROR: ' . $m . "\n");
}

/** Borra recursivamente una carpeta. Solo se usa para limpiar lo incompleto. */
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

function humano(int $b): string
{
    $u = ['B', 'KB', 'MB', 'GB'];
    $i = 0;
    $n = (float) $b;
    while ($n >= 1024 && $i < 3) {
        $n /= 1024;
        $i++;
    }
    return ($i === 0 ? (string) $b : number_format($n, 1, ',', '.')) . ' ' . $u[$i];
}

/** Ejecuta un binario SIN pasar por el shell: nada que escapar. */
function ejecutar(array $cmd, ?string $salidaArchivo = null): array
{
    $desc = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    if ($salidaArchivo !== null) {
        $desc[1] = ['file', $salidaArchivo, 'w'];
    }
    $p = @proc_open($cmd, $desc, $pipes);
    if (!is_resource($p)) {
        return [-1, '', 'no se pudo lanzar el proceso'];
    }
    $out = isset($pipes[1]) ? (string) stream_get_contents($pipes[1]) : '';
    $err = (string) stream_get_contents($pipes[2]);
    foreach ($pipes as $pipe) {
        if (is_resource($pipe)) {
            fclose($pipe);
        }
    }
    return [proc_close($p), $out, $err];
}

/**
 * Localiza mysqldump: opción explícita, instalaciones de Laragon o PATH.
 *
 * Cuando hay varias instaladas se prefiere la que coincide con la versión
 * del servidor. Importa: un mysqldump más nuevo que el servidor puede
 * escribir SQL que ese servidor luego no sabe restaurar, y un respaldo que
 * no se puede restaurar no es un respaldo. En esta máquina conviven MySQL
 * 8.0.30 y 8.4.3, así que la elección no es teórica.
 */
function buscar_mysqldump(?string $explicito, string $versionServidor = ''): ?string
{
    if ($explicito !== null && $explicito !== '') {
        return is_file($explicito) ? $explicito : null;
    }
    $win = DIRECTORY_SEPARATOR === '\\';
    $bin = $win ? 'mysqldump.exe' : 'mysqldump';

    if ($win) {
        $dirs = glob('C:/laragon/bin/mysql/*/bin/' . $bin) ?: [];
        rsort($dirs);

        // Primero, coincidencia por major.minor con el servidor.
        if (preg_match('/^(\d+\.\d+)/', $versionServidor, $m)) {
            foreach ($dirs as $d) {
                if (is_file($d) && str_contains(str_replace('\\', '/', $d), '/mysql-' . $m[1] . '.')) {
                    return $d;
                }
            }
        }
        foreach ($dirs as $d) {
            if (is_file($d)) {
                return $d;
            }
        }
    }
    foreach (['/usr/bin/', '/usr/local/bin/', '/opt/plesk/bin/'] as $d) {
        if (is_file($d . $bin)) {
            return $d . $bin;
        }
    }
    [$c, $o] = ejecutar([$win ? 'where' : 'which', $bin]);
    if ($c === 0) {
        $l = trim(strtok($o, "\n") ?: '');
        if ($l !== '' && is_file($l)) {
            return $l;
        }
    }
    return null;
}

// ─────────────────────────────────────────────────────────────
// Rutas de salida
// ─────────────────────────────────────────────────────────────
$salidaBase = $opt['output'] ?? ($ROOT . DIRECTORY_SEPARATOR . '_backups');

// Se rechaza cualquier intento de escaparse con .. antes de tocar el disco.
if (str_contains(str_replace('\\', '/', (string) $opt['output']), '../')) {
    fwrite(STDERR, "La ruta de salida no puede contener '..'\n");
    exit(EXIT_USO);
}

$sello  = date('Ymd_His');
$nombre = 'fyc_planner_backup_' . $sello . ($opt['label'] !== '' ? '_' . $opt['label'] : '');
$destino = rtrim($salidaBase, "/\\") . DIRECTORY_SEPARATOR . $nombre;

$hacerDb      = !$opt['storage-only'];
$hacerStorage = !$opt['db-only'];

echo "\n";
echo "══════════════════════════════════════════════════════════════════════\n";
echo " RESPALDO DE F&C PLANNER" . ($opt['dry-run'] ? '  [SIMULACRO]' : '') . "\n";
echo "══════════════════════════════════════════════════════════════════════\n";
paso('fecha    : ' . date('Y-m-d H:i:s') . ' (' . APP_TIMEZONE . ')');
paso('base     : ' . $conn->query('SELECT DATABASE()')->fetch_row()[0]);
paso('motor    : ' . $conn->server_info);
paso('destino  : ' . $destino);
paso('contenido: ' . ($hacerDb ? 'base' : '') . ($hacerDb && $hacerStorage ? ' + ' : '') . ($hacerStorage ? 'storage' : ''));

$mysqldump = null;
if ($hacerDb) {
    $mysqldump = buscar_mysqldump($opt['mysqldump'], (string) $conn->server_info);
    if ($mysqldump === null) {
        fallo('no se encontró mysqldump. Indícalo con --mysqldump=RUTA');
        exit(EXIT_ENTORNO);
    }
    [$c, $v] = ejecutar([$mysqldump, '--version']);
    $v = trim($v !== '' ? $v : $mysqldump);
    paso('mysqldump: ' . $v);

    // Aviso si el cliente es más nuevo que el servidor: el dump podría
    // contener sintaxis que ese servidor no acepta al restaurar.
    if (preg_match('/Ver (\d+\.\d+)/', $v, $mv)
        && preg_match('/^(\d+\.\d+)/', (string) $conn->server_info, $ms)
        && version_compare($mv[1], $ms[1], '>')) {
        paso('AVISO: mysqldump ' . $mv[1] . ' es más nuevo que el servidor ' . $ms[1]
            . '. Considera --mysqldump=RUTA a la versión del servidor.');
    }
}

if (is_dir($destino)) {
    // Un respaldo válido no se pisa jamás.
    fallo('ya existe una carpeta de respaldo con ese nombre: ' . $nombre);
    exit(EXIT_FALLO);
}

if ($opt['dry-run']) {
    echo "\n";
    paso('SIMULACRO: no se ha creado ningún archivo.');
    paso('Se habría creado: ' . $destino);
    if ($hacerDb) {
        paso('  · database.sql.gz + verificación + SHA-256');
    }
    if ($hacerStorage) {
        paso('  · storage_attachments.' . ($opt['storage-format'] === 'zip' ? 'zip' : 'tar.gz'));
    }
    paso('  · manifest.json y SHA256SUMS.txt');
    echo "\n";
    exit(EXIT_OK);
}

// ─────────────────────────────────────────────────────────────
// A partir de aquí ya se escribe. Cualquier fallo limpia lo creado.
// ─────────────────────────────────────────────────────────────
if (!@mkdir($destino, 0700, true) && !is_dir($destino)) {
    fallo('no se pudo crear la carpeta de destino.');
    exit(EXIT_FALLO);
}

$cnfTemporal = null;

/** Limpia lo incompleto y sale. Nunca toca respaldos anteriores. */
function abortar(string $motivo, string $destino, ?string $cnf): void
{
    fallo($motivo);
    if ($cnf !== null && is_file($cnf)) {
        @unlink($cnf);
    }
    borrar_recursivo($destino);
    fwrite(STDERR, "  Se ha limpiado el respaldo incompleto.\n");
    exit(EXIT_FALLO);
}

$manifiesto = [];
$artefactos = [];

// ─────────────────────────────────────────────────────────────
// 1) Base de datos
// ─────────────────────────────────────────────────────────────
$dbInfo = null;

if ($hacerDb) {
    echo "\n";
    paso('── Base de datos ──');

    $sqlPlano = $destino . DIRECTORY_SEPARATOR . 'database.sql';
    $sqlGz    = $sqlPlano . '.gz';

    // Archivo de opciones temporal: así la contraseña no aparece nunca en la
    // lista de procesos del sistema.
    $cnfTemporal = tempnam(sys_get_temp_dir(), 'fycbk');
    if ($cnfTemporal === false) {
        abortar('no se pudo crear el archivo temporal de opciones.', $destino, null);
    }
    @chmod($cnfTemporal, 0600);

    $ini = "[client]\n"
        . 'host=' . $DB_HOST . "\n"
        . 'user=' . $DB_USER . "\n"
        . 'password=' . $DB_PASS . "\n";
    if (file_put_contents($cnfTemporal, $ini) === false) {
        abortar('no se pudo escribir el archivo temporal de opciones.', $destino, $cnfTemporal);
    }

    $hayRutinas = (int) $conn->query(
        'SELECT COUNT(*) FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA = DATABASE()'
    )->fetch_row()[0];
    $hayEventos = (int) $conn->query(
        'SELECT COUNT(*) FROM information_schema.EVENTS WHERE EVENT_SCHEMA = DATABASE()'
    )->fetch_row()[0];

    $cmd = [
        $mysqldump,
        '--defaults-extra-file=' . $cnfTemporal,
        '--default-character-set=utf8mb4',
        '--single-transaction',      // consistente sin bloquear InnoDB
        '--quick',
        '--hex-blob',
        '--triggers',
        '--add-drop-table',
        '--complete-insert',
        '--set-charset',
    ];
    // --routines y --events solo si hay algo que exportar: en servidores sin
    // privilegios sobre mysql.event, pedirlos a lo tonto aborta el dump.
    if ($hayRutinas > 0) {
        $cmd[] = '--routines';
    }
    if ($hayEventos > 0) {
        $cmd[] = '--events';
    }
    $cmd[] = $DB_NAME;

    [$code, , $err] = ejecutar($cmd, $sqlPlano);
    @unlink($cnfTemporal);
    $cnfTemporal = null;

    if ($code !== 0 || !is_file($sqlPlano) || filesize($sqlPlano) === 0) {
        // El mensaje de mysqldump puede citar el usuario, nunca la contraseña.
        abortar('mysqldump falló (código ' . $code . '): ' . trim($err), $destino, null);
    }

    // Verificación del contenido ANTES de comprimir.
    $sql = (string) file_get_contents($sqlPlano);
    $tablasEnDump = preg_match_all('/^CREATE TABLE /mi', $sql);
    $tablasEsperadas = (int) $conn->query(
        'SELECT COUNT(*) FROM information_schema.TABLES
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = "BASE TABLE"'
    )->fetch_row()[0];

    if ($tablasEnDump === 0) {
        abortar('el dump no contiene ninguna sentencia CREATE TABLE.', $destino, null);
    }
    if (!str_contains($sql, 'task_attachments')) {
        abortar('el dump no contiene la tabla task_attachments.', $destino, null);
    }
    if ($tablasEnDump < $tablasEsperadas) {
        abortar("el dump trae {$tablasEnDump} tablas y la base tiene {$tablasEsperadas}.", $destino, null);
    }
    unset($sql);

    $tamPlano = (int) filesize($sqlPlano);
    paso("dump generado: {$tablasEnDump} tablas, " . humano($tamPlano));

    // Compresión con zlib: no depende de que exista gzip en el sistema.
    $in  = fopen($sqlPlano, 'rb');
    $out = gzopen($sqlGz, 'wb9');
    if ($in === false || $out === false) {
        abortar('no se pudo comprimir el dump.', $destino, null);
    }
    while (!feof($in)) {
        $buf = fread($in, 262144);
        if ($buf === false) {
            break;
        }
        gzwrite($out, $buf);
    }
    fclose($in);
    gzclose($out);

    // Comprobar que el .gz se puede volver a abrir y leer.
    $test = gzopen($sqlGz, 'rb');
    if ($test === false) {
        abortar('el archivo comprimido no se puede abrir.', $destino, null);
    }
    $cabecera = (string) gzread($test, 512);
    gzclose($test);
    if (!str_contains($cabecera, '--') && !str_contains($cabecera, 'CREATE')) {
        abortar('el archivo comprimido no contiene SQL legible.', $destino, null);
    }

    @unlink($sqlPlano);   // el plano ya no hace falta: queda el .gz verificado

    $tamGz  = (int) filesize($sqlGz);
    $shaGz  = (string) hash_file('sha256', $sqlGz);
    @chmod($sqlGz, 0600);

    paso('comprimido  : ' . humano($tamGz) . ' (' . round(100 - $tamGz / max($tamPlano, 1) * 100) . '% menos)');
    paso('sha256      : ' . substr($shaGz, 0, 32) . '…');

    $filasAdj = (int) $conn->query('SELECT COUNT(*) FROM task_attachments')->fetch_row()[0];

    $dbInfo = [
        'engine'                => str_contains(strtolower($conn->server_info), 'mariadb') ? 'MariaDB' : 'MySQL',
        'version'               => $conn->server_info,
        'database_name'         => $DB_NAME,
        'dump_file'             => basename($sqlGz),
        'dump_compressed'       => true,
        'compression'           => 'gzip',
        'size_bytes'            => $tamGz,
        'uncompressed_bytes'    => $tamPlano,
        'sha256'                => $shaGz,
        'table_count'           => $tablasEnDump,
        'task_attachments_rows' => $filasAdj,
        'routines_included'     => $hayRutinas > 0,
        'events_included'       => $hayEventos > 0,
    ];
    $artefactos[basename($sqlGz)] = $shaGz;
}

// ─────────────────────────────────────────────────────────────
// 2) storage/attachments
// ─────────────────────────────────────────────────────────────
$stInfo = null;

if ($hacerStorage) {
    echo "\n";
    paso('── storage/attachments ──');

    $raizStorage = realpath($ROOT . '/storage/attachments');
    if ($raizStorage === false || !is_dir($raizStorage)) {
        abortar('no existe storage/attachments.', $destino, $cnfTemporal);
    }

    // Recorrido propio: se controlan enlaces simbólicos y contención.
    $raizNorm = rtrim(str_replace('\\', '/', $raizStorage), '/') . '/';
    $archivos = [];
    $saltados = 0;
    $bytesTotales = 0;

    $recorrer = function (string $dir, string $prefijo) use (&$recorrer, &$archivos, &$saltados, &$bytesTotales, $raizNorm): void {
        foreach (array_diff(scandir($dir) ?: [], ['.', '..']) as $e) {
            $p = $dir . DIRECTORY_SEPARATOR . $e;
            $rel = $prefijo === '' ? $e : $prefijo . '/' . $e;

            // Ni se siguen ni se incluyen: podrían apuntar fuera del almacén.
            if (is_link($p)) {
                $saltados++;
                continue;
            }
            $real = realpath($p);
            if ($real === false || strpos(str_replace('\\', '/', $real) . '/', $raizNorm) !== 0) {
                $saltados++;
                continue;
            }
            if (is_dir($p)) {
                $recorrer($p, $rel);
                continue;
            }
            if (is_file($p)) {
                $archivos[$rel] = $p;      // incluye .gitkeep y .htaccess
                $bytesTotales += (int) filesize($p);
            }
        }
    };
    $recorrer($raizStorage, '');
    ksort($archivos);

    paso('archivos a incluir: ' . count($archivos) . ' (' . humano($bytesTotales) . ')'
        . ($saltados > 0 ? " · {$saltados} omitidos por enlace o ruta" : ''));

    if ($opt['storage-format'] === 'zip') {
        $arch   = $destino . DIRECTORY_SEPARATOR . 'storage_attachments.zip';
        $formato = 'zip';

        $zip = new ZipArchive();
        if ($zip->open($arch, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            abortar('no se pudo crear el archivo zip.', $destino, $cnfTemporal);
        }
        // Se guarda todo bajo storage/attachments/ para que la ruta de
        // restauración sea evidente al abrirlo.
        foreach ($archivos as $rel => $p) {
            if (!$zip->addFile($p, 'storage/attachments/' . $rel)) {
                $zip->close();
                abortar('no se pudo añadir un archivo al zip.', $destino, $cnfTemporal);
            }
        }
        if (!$zip->close()) {
            abortar('no se pudo cerrar el archivo zip.', $destino, $cnfTemporal);
        }

        // Verificación: se vuelve a abrir y se cuentan las entradas.
        $chk = new ZipArchive();
        if ($chk->open($arch, ZipArchive::CHECKCONS) !== true) {
            abortar('el zip creado no supera la comprobación de consistencia.', $destino, $cnfTemporal);
        }
        $entradas = $chk->numFiles;
        $chk->close();
    } else {
        $base    = $destino . DIRECTORY_SEPARATOR . 'storage_attachments.tar';
        $arch    = $base . '.gz';
        $formato = 'tar.gz';

        try {
            $tar = new PharData($base);
            foreach ($archivos as $rel => $p) {
                $tar->addFile($p, 'storage/attachments/' . $rel);
            }
            $tar->compress(Phar::GZ);
            unset($tar);
            @unlink($base);
        } catch (Throwable $e) {
            abortar('no se pudo crear el tar.gz: ' . $e->getMessage(), $destino, $cnfTemporal);
        }
        try {
            $chk = new PharData($arch);
            $entradas = count($chk);
        } catch (Throwable $e) {
            abortar('el tar.gz creado no se puede leer.', $destino, $cnfTemporal);
        }
    }

    if ($entradas !== count($archivos)) {
        abortar("el archivo comprimido tiene {$entradas} entradas y se esperaban " . count($archivos) . '.', $destino, $cnfTemporal);
    }

    $tamArch = (int) filesize($arch);
    $shaArch = (string) hash_file('sha256', $arch);
    @chmod($arch, 0600);

    paso('archivo     : ' . basename($arch) . ' · ' . humano($tamArch) . ' · ' . $entradas . ' entradas');
    paso('sha256      : ' . substr($shaArch, 0, 32) . '…');

    $stInfo = [
        'archive_file'             => basename($arch),
        'archive_format'           => $formato,
        'size_bytes'               => $tamArch,
        'sha256'                   => $shaArch,
        'file_count'               => count($archivos),
        'total_uncompressed_bytes' => $bytesTotales,
        'symlinks_skipped'         => $saltados,
        'includes_dotfiles'        => isset($archivos['.gitkeep']) && isset($archivos['.htaccess']),
    ];
    $artefactos[basename($arch)] = $shaArch;
}

// ─────────────────────────────────────────────────────────────
// 3) Manifiesto
// ─────────────────────────────────────────────────────────────
echo "\n";
paso('── Manifiesto ──');

function git(string $ROOT, array $args): string
{
    [$c, $o] = ejecutar(array_merge(['git', '-C', $ROOT], $args));
    return $c === 0 ? trim($o) : '';
}

$modulo = [
    'attachment_rows'    => (int) $conn->query('SELECT COUNT(*) FROM task_attachments')->fetch_row()[0],
    'physical_file_rows' => (int) $conn->query(
        "SELECT COUNT(*) FROM task_attachments WHERE stored_path IS NOT NULL AND stored_path <> ''"
    )->fetch_row()[0],
    'link_rows'          => (int) $conn->query("SELECT COUNT(*) FROM task_attachments WHERE kind = 'link'")->fetch_row()[0],
    'embed_rows'         => (int) $conn->query("SELECT COUNT(*) FROM task_attachments WHERE kind = 'embed'")->fetch_row()[0],
];

// Los huérfanos solo se pueden contar de forma fiable si el respaldo incluye
// el recorrido del almacén. Con --db-only se marca como no calculado en
// lugar de inventar un cero.
$modulo['orphan_count'] = $hacerStorage
    ? max(0, ($stInfo['file_count'] ?? 0) - 2 - $modulo['physical_file_rows'])  // -2: .gitkeep y .htaccess
    : null;
$modulo['orphan_count_note'] = $hacerStorage
    ? 'archivos del almacén sin fila, excluidos .gitkeep y .htaccess'
    : 'no calculable con --db-only';

$manifiesto = [
    'generated_at'   => date('c'),
    'timezone'       => APP_TIMEZONE,
    'project_commit' => git($ROOT, ['rev-parse', 'HEAD']),
    'project_branch' => git($ROOT, ['rev-parse', '--abbrev-ref', 'HEAD']),
    'project_dirty'  => git($ROOT, ['status', '--porcelain']) !== '',
    'backup_name'    => $nombre,
    'label'          => $opt['label'],
    'database'       => $dbInfo,
    'storage'        => $stInfo,
    'module'         => $modulo,
    'environment'    => [
        'php_version'         => PHP_VERSION,
        'os_family'           => PHP_OS_FAMILY,
        'app_timezone'        => APP_TIMEZONE,
        'db_session_timezone' => (string) $conn->query('SELECT @@session.time_zone')->fetch_row()[0],
        'db_charset'          => (string) $conn->query('SELECT @@character_set_database')->fetch_row()[0],
    ],
    'restore_order'  => [
        '1. Desplegar código compatible con este commit.',
        '2. Restaurar la base: gunzip -c database.sql.gz | mysql <base>',
        '3. Restaurar storage/attachments desde el archivo comprimido.',
        '4. Ajustar permisos de storage/attachments (propietario del servidor web).',
        '5. Verificar: abrir una tarea con adjuntos y comprobar que se ven.',
    ],
    'notes' => [
        'Un mysqldump por sí solo NO restaura el módulo: las filas de task_attachments '
            . 'apuntan a archivos de storage/attachments que viven fuera de la base.',
        'Este manifiesto no contiene usuario, contraseña ni host de la base de datos.',
        'Los hashes SHA-256 se calculan sobre los artefactos finales ya comprimidos.',
    ],
];

$rutaManifiesto = $destino . DIRECTORY_SEPARATOR . 'manifest.json';
$json = json_encode($manifiesto, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
if ($json === false || file_put_contents($rutaManifiesto, $json . "\n") === false) {
    abortar('no se pudo escribir manifest.json.', $destino, $cnfTemporal);
}
@chmod($rutaManifiesto, 0600);

// Archivo de sumas al estilo sha256sum, para verificar sin PHP.
$sumas = '';
foreach ($artefactos as $f => $h) {
    $sumas .= $h . '  ' . $f . "\n";
}
file_put_contents($destino . DIRECTORY_SEPARATOR . 'SHA256SUMS.txt', $sumas);
@chmod($destino . DIRECTORY_SEPARATOR . 'SHA256SUMS.txt', 0600);

paso('manifest.json y SHA256SUMS.txt escritos');

// ─────────────────────────────────────────────────────────────
// 4) Resumen
// ─────────────────────────────────────────────────────────────
$total = 0;
foreach (array_diff(scandir($destino) ?: [], ['.', '..']) as $f) {
    $total += (int) filesize($destino . DIRECTORY_SEPARATOR . $f);
}

echo "\n";
echo "══════════════════════════════════════════════════════════════════════\n";
paso('RESPALDO COMPLETO · ' . humano($total));
paso('en: ' . $destino);
paso('verificar: php scripts/backup_project.php --help  (sección de restauración en docs/BACKUP_RESTORE.md)');
echo "══════════════════════════════════════════════════════════════════════\n\n";

exit(EXIT_OK);
