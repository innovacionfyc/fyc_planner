<?php
/**
 * scripts/build_release.php
 *
 * Genera el paquete de despliegue a partir de un commit, excluyendo de forma
 * explícita todo lo que NO debe viajar al servidor.
 *
 * POR QUÉ EXISTE
 *   El repositorio contiene archivos que son correctos aquí pero destructivos
 *   allí. El caso claro es config/mail.php: apunta a un captador de correo
 *   local (puerto 1025) y, si se desplegara, sobrescribiría la configuración
 *   SMTP de producción y el envío de correo dejaría de funcionar en silencio.
 *
 *   Confiar en «acordarse de no subirlo» no es una defensa. Este script deja
 *   la exclusión escrita, reproducible y comprobable, y
 *   tests/attachments_release_smoke.php abre el paquete resultante para
 *   verificar que efectivamente no está.
 *
 * QUÉ NO VIAJA (y por qué)
 *   config/mail.php   configuración SMTP propia de cada entorno
 *   config/db.php     credenciales de base de datos (ya ignorado por git)
 *   .env y variantes  secretos
 *   .git/             historial completo del repositorio
 *   logs, backups     ruido y datos sensibles
 *   tests/, tools/    no hacen falta en producción
 *   adjuntos reales   los de storage/attachments/AAAA/MM son datos locales
 *
 * QUÉ SÍ VIAJA
 *   El código del módulo, las migraciones, la documentación de despliegue y
 *   el esqueleto de storage/attachments (.gitkeep y .htaccess) para que la
 *   carpeta exista con su protección desde el primer momento.
 *
 * USO
 *   php scripts/build_release.php --help
 *   php scripts/build_release.php --dry-run
 *   php scripts/build_release.php --commit=HEAD --output=D:/releases
 *
 * CÓDIGOS DE SALIDA
 *   0  paquete creado y verificado
 *   1  fallo durante la generación
 *   2  argumento inválido
 *   3  falta un requisito del entorno
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
// Contrato de exclusiones e inclusiones
// ─────────────────────────────────────────────────────────────

/** Rutas exactas que nunca viajan. */
const RELEASE_EXCLUIR_EXACTO = [
    'config/mail.php',
    'config/db.php',
    'config/app.local.php',
    '.env',
    'CLAUDE.md',
    'schema_fyc_planner_db.sql',
];

/** Prefijos de directorio que nunca viajan. */
const RELEASE_EXCLUIR_PREFIJO = [
    '.git/',
    '_backups/',
    'node_modules/',
    'vendor/',
    'tests/',
    'tools/',
    'db/',
];

/** Patrones que nunca viajan, se llamen como se llamen. */
const RELEASE_EXCLUIR_PATRON = [
    '#^\.env\.#',                              // .env.local, .env.production…
    '#\.log$#',
    '#\.(zip|tar|tar\.gz|tgz|gz)$#',            // paquetes previos
    '#(^|/)sess_[a-z0-9]+$#i',                  // sesiones
    '#(^|/)\.DS_Store$#',
    '#(^|/)Thumbs\.db$#',
    '#^storage/attachments/\d{4}/#',            // adjuntos reales del entorno
    '#(^|/)qa[_-]#i',                           // restos de QA
];

/** Rutas que DEBEN estar en el paquete. Si falta una, el paquete se descarta. */
const RELEASE_OBLIGATORIO = [
    'database/migrations/2026-07-29-create-task-attachments.sql',
    'database/migrations/2026-07-29-add-external-links-to-task-attachments.sql',
    'storage/attachments/.gitkeep',
    'storage/attachments/.htaccess',
    'config/bootstrap.php',
    'config/db.example.php',
    'config/mail.example.php',
    'public/_attachments.php',
    'public/tasks/attachment.php',
    'public/tasks/attachment_upload.php',
    'public/tasks/attachment_link.php',
    'public/tasks/attachment_delete.php',
    'public/tasks/drawer.php',
    'public/assets/board-view.js',
    'public/assets/theme.css',
    'cron/purge_trash.php',
    'cron/purge_orphan_attachments.php',
    'scripts/backup_project.php',
    'docs/DEPLOYMENT_ATTACHMENTS.md',
    'docs/ATTACHMENTS.md',
    'docs/BACKUP_RESTORE.md',
];

function release_excluida(string $ruta): bool
{
    if (in_array($ruta, RELEASE_EXCLUIR_EXACTO, true)) {
        return true;
    }
    foreach (RELEASE_EXCLUIR_PREFIJO as $p) {
        if (str_starts_with($ruta, $p)) {
            return true;
        }
    }
    foreach (RELEASE_EXCLUIR_PATRON as $re) {
        if (preg_match($re, $ruta)) {
            return true;
        }
    }
    return false;
}

// ─────────────────────────────────────────────────────────────
// Argumentos
// ─────────────────────────────────────────────────────────────
function ayuda(): void
{
    echo <<<TXT
Genera el paquete de despliegue de F&C Planner.

Uso:
  php scripts/build_release.php [opciones]

Opciones:
  --commit=REF     Commit a empaquetar (por defecto HEAD).
                   Usa WORKTREE para empaquetar el arbol de trabajo y
                   validar el contrato ANTES de confirmar.
  --output=RUTA    Carpeta de destino (por defecto _releases)
  --label=ETIQUETA Sufijo del nombre  [A-Za-z0-9._-], máx. 40
  --dry-run        Enseña qué incluiría y qué excluiría, sin escribir
  --help           Esta ayuda

Salidas: 0 correcto · 1 fallo · 2 argumento inválido · 3 falta un requisito

TXT;
}

$opt = ['commit' => 'HEAD', 'output' => null, 'label' => '', 'dry-run' => false];

foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--help' || $arg === '-h') {
        ayuda();
        exit(EXIT_OK);
    }
    if ($arg === '--dry-run' || $arg === '-n') {
        $opt['dry-run'] = true;
        continue;
    }
    if (preg_match('/^--(commit|output|label)=(.*)$/', $arg, $m)) {
        $opt[$m[1]] = $m[2];
        continue;
    }
    fwrite(STDERR, "Argumento no reconocido: {$arg}\n");
    exit(EXIT_USO);
}

if ($opt['label'] !== '' && !preg_match('/^[A-Za-z0-9._-]{1,40}$/', $opt['label'])) {
    fwrite(STDERR, "Etiqueta inválida: solo A-Za-z0-9._- y hasta 40 caracteres.\n");
    exit(EXIT_USO);
}
if (str_contains(str_replace('\\', '/', (string) $opt['output']), '../')) {
    fwrite(STDERR, "La ruta de salida no puede contener '..'\n");
    exit(EXIT_USO);
}
if (!class_exists('ZipArchive')) {
    fwrite(STDERR, "Falta la extensión zip.\n");
    exit(EXIT_ENTORNO);
}

// ─────────────────────────────────────────────────────────────
function git(string $ROOT, array $args): array
{
    $cmd = 'git';
    foreach (array_merge(['-C', $ROOT], $args) as $a) {
        $cmd .= ' ' . escapeshellarg($a);
    }
    $out = [];
    $code = 0;
    exec($cmd . ' 2>&1', $out, $code);
    return [$code, $out];
}

function paso(string $m): void
{
    echo '  ' . $m . "\n";
}

function humano(int $b): string
{
    $u = ['B', 'KB', 'MB'];
    $i = 0;
    $n = (float) $b;
    while ($n >= 1024 && $i < 2) {
        $n /= 1024;
        $i++;
    }
    return ($i === 0 ? (string) $b : number_format($n, 1, ',', '.')) . ' ' . $u[$i];
}

// ─────────────────────────────────────────────────────────────
// «WORKTREE» empaqueta el árbol de trabajo en lugar de un commit. Sirve para
// validar el paquete ANTES de confirmar —que es cuando de verdad interesa
// saber si algo prohibido se colaría— y para probar cambios sin ensuciar el
// historial. Para el despliegue real se usa siempre un commit.
$desdeWorktree = strtoupper($opt['commit']) === 'WORKTREE';

if ($desdeWorktree) {
    $commit = 'WORKTREE';
    [$c, $listado] = git($ROOT, ['ls-files', '--cached', '--others', '--exclude-standard']);
    if ($c !== 0) {
        fwrite(STDERR, "No se pudo listar el árbol de trabajo.\n");
        exit(EXIT_FALLO);
    }
} else {
    [$c, $o] = git($ROOT, ['rev-parse', $opt['commit']]);
    if ($c !== 0) {
        fwrite(STDERR, "Commit no encontrado: {$opt['commit']}\n");
        exit(EXIT_FALLO);
    }
    $commit = trim($o[0] ?? '');

    [$c, $listado] = git($ROOT, ['ls-tree', '-r', '--name-only', $commit]);
    if ($c !== 0 || $listado === []) {
        fwrite(STDERR, "No se pudo listar el árbol del commit.\n");
        exit(EXIT_FALLO);
    }
}

$incluidos = [];
$excluidos = [];
foreach ($listado as $ruta) {
    $ruta = trim($ruta);
    if ($ruta === '') {
        continue;
    }
    release_excluida($ruta) ? $excluidos[] = $ruta : $incluidos[] = $ruta;
}
sort($incluidos);
sort($excluidos);

echo "\n";
echo "══════════════════════════════════════════════════════════════════════\n";
echo " PAQUETE DE DESPLIEGUE" . ($opt['dry-run'] ? '  [SIMULACRO]' : '') . "\n";
echo "══════════════════════════════════════════════════════════════════════\n";
paso('commit   : ' . substr($commit, 0, 12) . '…');
paso('incluidos: ' . count($incluidos) . ' archivos');
paso('excluidos: ' . count($excluidos) . ' archivos');

// Comprobación de obligatorios ANTES de escribir nada
$faltan = array_values(array_diff(RELEASE_OBLIGATORIO, $incluidos));
if ($faltan !== []) {
    fwrite(STDERR, "\n  ERROR: faltan archivos obligatorios en el paquete:\n");
    foreach ($faltan as $f) {
        fwrite(STDERR, "    · {$f}\n");
    }
    exit(EXIT_FALLO);
}
paso('obligatorios: ' . count(RELEASE_OBLIGATORIO) . '/' . count(RELEASE_OBLIGATORIO) . ' presentes');

if ($opt['dry-run']) {
    echo "\n  ── Excluidos ──\n";
    foreach ($excluidos as $e) {
        echo '    ' . $e . "\n";
    }
    echo "\n  SIMULACRO: no se ha escrito nada.\n\n";
    exit(EXIT_OK);
}

// ─────────────────────────────────────────────────────────────
$salidaBase = $opt['output'] ?? ($ROOT . DIRECTORY_SEPARATOR . '_releases');
$nombre = 'fyc_planner_release_' . date('Ymd_His')
    . '_' . ($desdeWorktree ? 'worktree' : substr($commit, 0, 7))
    . ($opt['label'] !== '' ? '_' . $opt['label'] : '');
$destino = rtrim($salidaBase, "/\\") . DIRECTORY_SEPARATOR . $nombre;
$zipPath = $destino . '.zip';

if (!is_dir($salidaBase) && !@mkdir($salidaBase, 0700, true) && !is_dir($salidaBase)) {
    fwrite(STDERR, "No se pudo crear la carpeta de salida.\n");
    exit(EXIT_FALLO);
}
if (is_file($zipPath)) {
    fwrite(STDERR, "Ya existe un paquete con ese nombre.\n");
    exit(EXIT_FALLO);
}

$zip = new ZipArchive();
if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    fwrite(STDERR, "No se pudo crear el zip.\n");
    exit(EXIT_FALLO);
}

$anadidos = 0;
foreach ($incluidos as $ruta) {
    // Con un commit el contenido se toma de ÉL, no del árbol de trabajo: así
    // el paquete es exactamente lo versionado, sin cambios sueltos. En modo
    // WORKTREE se lee del disco, que es justo lo que se quiere validar.
    if ($desdeWorktree) {
        $abs = $ROOT . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $ruta);
        $datos = @file_get_contents($abs);
        if ($datos === false) {
            $zip->close();
            @unlink($zipPath);
            fwrite(STDERR, "No se pudo leer del disco: {$ruta}\n");
            exit(EXIT_FALLO);
        }
    } else {
        [$c, $contenido] = git($ROOT, ['show', $commit . ':' . $ruta]);
        if ($c !== 0) {
            $zip->close();
            @unlink($zipPath);
            fwrite(STDERR, "No se pudo leer del commit: {$ruta}\n");
            exit(EXIT_FALLO);
        }
        $datos = implode("\n", $contenido) . "\n";
    }
    if (!$zip->addFromString($ruta, $datos)) {
        $zip->close();
        @unlink($zipPath);
        fwrite(STDERR, "No se pudo añadir al zip: {$ruta}\n");
        exit(EXIT_FALLO);
    }
    $anadidos++;
}

if (!$zip->close()) {
    @unlink($zipPath);
    fwrite(STDERR, "No se pudo cerrar el zip.\n");
    exit(EXIT_FALLO);
}

// Verificación: se reabre y se comprueba que no se coló nada prohibido
$chk = new ZipArchive();
if ($chk->open($zipPath, ZipArchive::CHECKCONS) !== true) {
    @unlink($zipPath);
    fwrite(STDERR, "El zip no supera la comprobación de consistencia.\n");
    exit(EXIT_FALLO);
}
$dentro = [];
for ($i = 0; $i < $chk->numFiles; $i++) {
    $dentro[] = (string) $chk->getNameIndex($i);
}
$chk->close();

$colados = array_values(array_filter($dentro, 'release_excluida'));
if ($colados !== []) {
    @unlink($zipPath);
    fwrite(STDERR, "\n  ERROR: se colaron archivos excluidos:\n");
    foreach ($colados as $f) {
        fwrite(STDERR, "    · {$f}\n");
    }
    exit(EXIT_FALLO);
}

$sha = (string) hash_file('sha256', $zipPath);
@chmod($zipPath, 0600);
file_put_contents($destino . '.sha256', $sha . '  ' . basename($zipPath) . "\n");

echo "\n";
paso('paquete  : ' . basename($zipPath));
paso('archivos : ' . $anadidos . ' (verificados dentro del zip: ' . count($dentro) . ')');
paso('tamaño   : ' . humano((int) filesize($zipPath)));
paso('sha256   : ' . $sha);
paso('ruta     : ' . $zipPath);
echo "\n";
paso('Excluidos por contrato: ' . implode(', ', array_slice($excluidos, 0, 6))
    . (count($excluidos) > 6 ? ' …' : ''));
echo "\n";

exit(EXIT_OK);
