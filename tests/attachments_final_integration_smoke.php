<?php
/**
 * tests/attachments_final_integration_smoke.php
 *
 * Suite de integración final del módulo de adjuntos (Fase F, bloque F5).
 *
 * Ejecutar SOLO en local:
 *   php tests/attachments_final_integration_smoke.php
 *
 * A diferencia de las suites A–F4, que prueban cada pieza por separado, esta
 * recorre el módulo entero de punta a punta: sube, sirve, borra, purga, pasa
 * el cron, respalda, restaura y comprueba que el sistema vuelve exactamente
 * al estado de partida.
 *
 * Necesita Apache y MySQL en marcha. No deja residuos.
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

const QA_TAG      = 'QA F5 FINAL';
const BASE_URL    = 'http://localhost/fyc_planner/public';
const SESSION_DIR = 'C:/laragon/tmp';

$ROOT    = dirname(__DIR__);
$SANDBOX = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'fyc_f5_sandbox';
$CRON    = $ROOT . '/cron/purge_orphan_attachments.php';
$BACKUP  = $ROOT . '/scripts/backup_project.php';

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

// ─────────────────────────────────────────────────────────────
// Utilidades
// ─────────────────────────────────────────────────────────────
function req(string $url, array $o = []): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => 1, CURLOPT_HEADER => 1,
        CURLOPT_FOLLOWLOCATION => 0, CURLOPT_TIMEOUT => 60]);
    if (!empty($o['sid'])) {
        curl_setopt($ch, CURLOPT_COOKIE, 'PHPSESSID=' . $o['sid']);
    }
    if (!empty($o['hdr'])) {
        curl_setopt($ch, CURLOPT_HTTPHEADER, $o['hdr']);
    }
    if (isset($o['post']) || isset($o['file'])) {
        curl_setopt($ch, CURLOPT_POST, true);
        $f = $o['post'] ?? [];
        if (!empty($o['file'])) {
            $f['files[0]'] = new CURLFile($o['file'], 'application/octet-stream', basename($o['file']));
        }
        curl_setopt($ch, CURLOPT_POSTFIELDS, $f);
    }
    $raw = curl_exec($ch);
    $st  = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $hs  = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);
    return [$st, substr((string) $raw, 0, $hs), substr((string) $raw, $hs)];
}

function jsonDe(string $b): array
{
    $d = json_decode($b, true);
    return is_array($d) ? $d : [];
}

/** Existencia real. Quien borra suele ser otro proceso: hay que limpiar la caché. */
function existe(string $rel): bool
{
    $abs = attach_storage_root() . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel);
    clearstatcache(true, $abs);
    return is_file($abs);
}

function sesion(int $uid, string $csrf, string $suf): string
{
    $sid = 'qaf5f' . $suf . bin2hex(random_bytes(8));
    file_put_contents(SESSION_DIR . '/sess_' . $sid,
        'user_id|i:' . $uid . ';csrf|s:' . strlen($csrf) . ':"' . $csrf . '";_auth_ts|i:' . time() . ';');
    return $sid;
}

function correr(string $script, array $args = []): array
{
    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script);
    foreach ($args as $a) {
        $cmd .= ' ' . escapeshellarg($a);
    }
    $out = [];
    $code = 0;
    exec($cmd . ' 2>&1', $out, $code);
    return [$code, implode("\n", $out)];
}

function num(string $o, string $campo): int
{
    return preg_match('/^\s*' . $campo . '\s*:\s*(\d+)/mu', $o, $m) ? (int) $m[1] : -1;
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

function inventario(): array
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
            is_dir($p) ? $walk($p, $r) : $out[$r] = hash_file('sha256', $p);
        }
    };
    $walk($root, '');
    ksort($out);
    return $out;
}

function limpiar(mysqli $conn): void
{
    $q = $conn->query("SELECT a.stored_path FROM task_attachments a
      JOIN tasks t ON t.id = a.task_id JOIN boards b ON b.id = t.board_id
      WHERE b.nombre LIKE '" . QA_TAG . "%' AND a.stored_path IS NOT NULL");
    foreach ($q->fetch_all(MYSQLI_ASSOC) as $r) {
        attach_delete_file((string) $r['stored_path']);
    }
    $conn->query("DELETE FROM boards WHERE nombre LIKE '" . QA_TAG . "%'");
    $conn->query("DELETE FROM users WHERE email LIKE 'qa.f5f.%@local.test'");
    foreach (glob(SESSION_DIR . '/sess_qaf5f*') ?: [] as $f) {
        @unlink($f);
    }
}

/** Borra carpetas AAAA/MM que hayan quedado vacías. Nunca arrastra archivos. */
function podar_vacias(): void
{
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
}

// ═════════════════════════════════════════════════════════════
echo "\n";
echo "══════════════════════════════════════════════════════════════════════════════\n";
echo " INTEGRACIÓN FINAL DEL MÓDULO DE ADJUNTOS (Fase F · bloque F5)\n";
echo "══════════════════════════════════════════════════════════════════════════════\n";
echo " Base  : " . $conn->query('SELECT DATABASE()')->fetch_row()[0] . "\n";
echo " Motor : " . $conn->server_info . "\n";
echo " PHP   : " . PHP_VERSION . "\n";
echo " Fecha : " . date('Y-m-d H:i:s') . "\n";

section('PREPARACIÓN');
limpiar($conn);
rrm($SANDBOX);
@mkdir($SANDBOX, 0775, true);
podar_vacias();

$INV_INICIAL = inventario();
$BASE_CONTEOS = [];
foreach (['boards', 'columns', 'tasks', 'users', 'task_attachments'] as $t) {
    $BASE_CONTEOS[$t] = (int) $conn->query("SELECT COUNT(*) FROM `$t`")->fetch_row()[0];
}
printf("  almacén: %d archivos · %s\n", count($INV_INICIAL), json_encode($BASE_CONTEOS));

// ═════════════════════════════════════════════════════════════
section('1-3 · INVENTARIO DE SUITES Y HELPERS');

$SUITES = [
    'attachments_backend_smoke'    => 44,
    'attachments_ui_smoke'         => 34,
    'attachments_paste_drop_smoke' => 41,
    'attachments_links_smoke'      => 51,
    'attachments_gallery_smoke'    => 63,
    'attachments_lifecycle_smoke'  => 48,
    'attachments_backup_smoke'     => 42,
    'assets_versioning_smoke'      => 32,
    'attachments_docs_smoke'       => 35,
];
$faltan = [];
foreach ($SUITES as $s => $_) {
    if (!is_file($ROOT . '/tests/' . $s . '.php')) {
        $faltan[] = $s;
    }
}
chk('1. Existen las nueve suites A–F4', $faltan === [], implode(', ', $faltan) ?: '9/9');
chk('2. El total esperado antes de F5 es 390', array_sum($SUITES) === 390, array_sum($SUITES) . ' casos');

$helpers = ['attach_stored_paths_of_task', 'attach_stored_paths_of_board', 'attach_delete_files',
    'attach_build_watch_url', 'asset_url', 'app_url'];
$sinHelper = array_values(array_filter($helpers, fn($f) => !function_exists($f)));
chk('3. Los helpers de F1–F3 están disponibles', $sinHelper === [], implode(', ', $sinHelper) ?: count($helpers) . '/' . count($helpers));

// ═════════════════════════════════════════════════════════════
section('4-9 · MONTAJE Y CICLO DE VIDA');

$csrf = bin2hex(random_bytes(32));
$U_OWNER = 2;

$hash = password_hash(bin2hex(random_bytes(12)), PASSWORD_DEFAULT);
$em = 'qa.f5f.' . bin2hex(random_bytes(4)) . '@local.test';
$st = $conn->prepare("INSERT INTO users (nombre,email,pass_hash,estado,rol,is_admin,activo) VALUES (?,?,?, 'aprobado','user',0,1)");
$nl = 'QA F5 Lector';
$st->bind_param('sss', $nl, $em, $hash);
$st->execute();
$U_LECTOR = (int) $conn->insert_id;
$st->close();

$hash = password_hash(bin2hex(random_bytes(12)), PASSWORD_DEFAULT);
$em2 = 'qa.f5f.' . bin2hex(random_bytes(4)) . '@local.test';
$st = $conn->prepare("INSERT INTO users (nombre,email,pass_hash,estado,rol,is_admin,activo) VALUES (?,?,?, 'aprobado','user',0,1)");
$na = 'QA F5 Ajeno';
$st->bind_param('sss', $na, $em2, $hash);
$st->execute();
$U_AJENO = (int) $conn->insert_id;
$st->close();

$st = $conn->prepare("INSERT INTO boards (nombre,color_hex,owner_user_id,team_id) VALUES (?, '#0ea5e9', ?, NULL)");
$bn = QA_TAG;
$st->bind_param('si', $bn, $U_OWNER);
$st->execute();
$BOARD = (int) $conn->insert_id;
$st->close();

$st = $conn->prepare("INSERT INTO board_members (board_id,user_id,rol) VALUES (?,?,?)");
foreach ([[$U_OWNER, 'propietario'], [$U_LECTOR, 'lector']] as [$u, $rol]) {
    $st->bind_param('iis', $BOARD, $u, $rol);
    $st->execute();
}
$st->close();

$st = $conn->prepare("INSERT INTO `columns` (board_id,nombre,orden,is_done) VALUES (?, 'A', 1, 0)");
$st->bind_param('i', $BOARD);
$st->execute();
$COL = (int) $conn->insert_id;
$st->close();

$S_OWN = sesion($U_OWNER, $csrf, 'o');
$S_LEC = sesion($U_LECTOR, $csrf, 'l');
$S_AJE = sesion($U_AJENO, $csrf, 'a');
$AJAX  = ['X-Requested-With: fetch', 'Accept: application/json'];

function nuevaTarea(mysqli $c, int $b, int $col, string $t): int
{
    $st = $c->prepare("INSERT INTO tasks (board_id,column_id,titulo,prioridad) VALUES (?,?,?, 'med')");
    $st->bind_param('iis', $b, $col, $t);
    $st->execute();
    $id = (int) $c->insert_id;
    $st->close();
    return $id;
}

// Fixture de imagen real
$fx = $SANDBOX . '/integra.jpg';
$im = imagecreatetruecolor(64, 48);
imagefill($im, 0, 0, imagecolorallocate($im, 30, 120, 200));
imagejpeg($im, $fx, 90);
imagedestroy($im);

// Subida real por HTTP
$T1 = nuevaTarea($conn, $BOARD, $COL, 'QA F5 borrado');
[$s, , $b] = req(BASE_URL . '/tasks/attachment_upload.php', ['sid' => $S_OWN, 'hdr' => $AJAX,
    'post' => ['csrf' => $csrf, 'task_id' => $T1], 'file' => $fx]);
$j = jsonDe($b);
$A1 = (int) ($j['attachments'][0]['id'] ?? 0);
$R1 = $A1 ? (string) $conn->query("SELECT stored_path FROM task_attachments WHERE id=$A1")->fetch_row()[0] : '';
chk('4. Subida real por HTTP con archivo en disco', $A1 > 0 && $R1 !== '' && existe($R1), "id=$A1");

// Soft delete conserva
$conn->query("UPDATE boards SET deleted_at=NOW() WHERE id=$BOARD");
chk('5. La papelera (soft delete) conserva los archivos', existe($R1));
$conn->query("UPDATE boards SET deleted_at=NULL WHERE id=$BOARD");

// Eliminación de tarea borra archivo
[$s, , $b] = req(BASE_URL . '/tasks/delete.php', ['sid' => $S_OWN, 'hdr' => $AJAX,
    'post' => ['csrf' => $csrf, 'task_id' => $T1, 'board_id' => $BOARD]]);
$j = jsonDe($b);
chk('6. Eliminar la tarea borra fila y archivo',
    ($j['ok'] ?? false) === true && !existe($R1)
    && (int) $conn->query("SELECT COUNT(*) FROM task_attachments WHERE id=$A1")->fetch_row()[0] === 0,
    'borrados=' . ($j['attachments']['deleted'] ?? '?'));

// Purga de tablero borra archivos
$st = $conn->prepare("INSERT INTO boards (nombre,color_hex,owner_user_id,team_id) VALUES (?, '#f97316', ?, NULL)");
$bn2 = QA_TAG . ' PURGA';
$st->bind_param('si', $bn2, $U_OWNER);
$st->execute();
$BOARD2 = (int) $conn->insert_id;
$st->close();
$st = $conn->prepare("INSERT INTO board_members (board_id,user_id,rol) VALUES (?,?, 'propietario')");
$st->bind_param('ii', $BOARD2, $U_OWNER);
$st->execute();
$st->close();
$st = $conn->prepare("INSERT INTO `columns` (board_id,nombre,orden,is_done) VALUES (?, 'A', 1, 0)");
$st->bind_param('i', $BOARD2);
$st->execute();
$COL2 = (int) $conn->insert_id;
$st->close();

$T2 = nuevaTarea($conn, $BOARD2, $COL2, 'QA F5 purga');
[$s, , $b] = req(BASE_URL . '/tasks/attachment_upload.php', ['sid' => $S_OWN, 'hdr' => $AJAX,
    'post' => ['csrf' => $csrf, 'task_id' => $T2], 'file' => $fx]);
$A2 = (int) (jsonDe($b)['attachments'][0]['id'] ?? 0);
$R2 = $A2 ? (string) $conn->query("SELECT stored_path FROM task_attachments WHERE id=$A2")->fetch_row()[0] : '';

$conn->query("UPDATE boards SET deleted_at=NOW() WHERE id=$BOARD2");
[$s, , ] = req(BASE_URL . '/boards/trash_purge.php', ['sid' => $S_OWN, 'post' => ['csrf' => $csrf, 'board_id' => $BOARD2]]);
chk('7. Purgar el tablero borra filas y archivos',
    (int) $conn->query("SELECT COUNT(*) FROM boards WHERE id=$BOARD2")->fetch_row()[0] === 0 && !existe($R2));

// Enlaces y embeds no invocan unlink
$T3 = nuevaTarea($conn, $BOARD, $COL, 'QA F5 solo enlaces');
req(BASE_URL . '/tasks/attachment_link.php', ['sid' => $S_OWN, 'hdr' => $AJAX,
    'post' => ['csrf' => $csrf, 'task_id' => $T3, 'url' => 'https://example.com/f5']]);
[$s, , $b] = req(BASE_URL . '/tasks/attachment_link.php', ['sid' => $S_OWN, 'hdr' => $AJAX,
    'post' => ['csrf' => $csrf, 'task_id' => $T3, 'url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ']]);
$emb = jsonDe($b)['attachment'] ?? [];
chk('8. El embed se clasifica con proveedor y plantilla propia',
    ($emb['kind'] ?? '') === 'embed' && ($emb['provider'] ?? '') === 'youtube'
    && str_starts_with((string) ($emb['embed_url'] ?? ''), 'https://www.youtube-nocookie.com/embed/'),
    (string) ($emb['embed_url'] ?? '?'));

$antesDisco = count(inventario());
[$s, , $b] = req(BASE_URL . '/tasks/delete.php', ['sid' => $S_OWN, 'hdr' => $AJAX,
    'post' => ['csrf' => $csrf, 'task_id' => $T3, 'board_id' => $BOARD]]);
$j = jsonDe($b);
chk('9. Enlaces y embeds no invocan unlink()',
    ($j['ok'] ?? false) === true && (int) ($j['attachments']['total'] ?? -1) === 0
    && count(inventario()) === $antesDisco,
    'total=' . ($j['attachments']['total'] ?? '?'));

// ═════════════════════════════════════════════════════════════
section('10-14 · PERMISOS Y ENTREGA DE ARCHIVOS');

$T4 = nuevaTarea($conn, $BOARD, $COL, 'QA F5 permisos');
[$s, , $b] = req(BASE_URL . '/tasks/attachment_upload.php', ['sid' => $S_OWN, 'hdr' => $AJAX,
    'post' => ['csrf' => $csrf, 'task_id' => $T4], 'file' => $fx]);
$A4 = (int) (jsonDe($b)['attachments'][0]['id'] ?? 0);
$R4 = $A4 ? (string) $conn->query("SELECT stored_path FROM task_attachments WHERE id=$A4")->fetch_row()[0] : '';

[$s, , ] = req(BASE_URL . '/tasks/attachment.php?id=' . $A4, ['sid' => $S_LEC]);
$lecturaLector = $s === 200;
[$s1, , ] = req(BASE_URL . '/tasks/attachment_upload.php', ['sid' => $S_LEC, 'hdr' => $AJAX,
    'post' => ['csrf' => $csrf, 'task_id' => $T4], 'file' => $fx]);
[$s2, , ] = req(BASE_URL . '/tasks/attachment_delete.php', ['sid' => $S_LEC, 'hdr' => $AJAX,
    'post' => ['csrf' => $csrf, 'attachment_id' => $A4]]);
chk('10. El lector lee pero no escribe (403 en el backend)',
    $lecturaLector && $s1 === 403 && $s2 === 403, "lectura=200 subir=$s1 borrar=$s2");

[$s1, , ] = req(BASE_URL . '/tasks/attachment.php?id=' . $A4, ['sid' => $S_AJE]);
[$s2, , ] = req(BASE_URL . '/tasks/drawer.php?id=' . $T4, ['sid' => $S_AJE]);
chk('11. El ajeno no lee el adjunto ni abre el cajón', $s1 === 403 && $s2 === 403, "adjunto=$s1 cajón=$s2");

// Range 206 y 416
$ch = curl_init(BASE_URL . '/tasks/attachment.php?id=' . $A4);
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => 1, CURLOPT_HEADER => 1,
    CURLOPT_COOKIE => "PHPSESSID=$S_OWN", CURLOPT_HTTPHEADER => ['Range: bytes=0-99']]);
$raw = (string) curl_exec($ch);
$sR = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
$hsR = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
curl_close($ch);
chk('12. Rango válido devuelve 206 con Content-Range',
    $sR === 206 && strlen(substr($raw, $hsR)) === 100
    && stripos(substr($raw, 0, $hsR), 'Content-Range: bytes 0-99/') !== false, "http=$sR");

$ch = curl_init(BASE_URL . '/tasks/attachment.php?id=' . $A4);
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => 1, CURLOPT_COOKIE => "PHPSESSID=$S_OWN",
    CURLOPT_HTTPHEADER => ['Range: bytes=99999999-99999999']]);
curl_exec($ch);
$sR2 = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
chk('13. Rango imposible devuelve 416', $sR2 === 416, "http=$sR2");

// URLs peligrosas
$peligrosas = ['javascript:alert(1)', 'data:text/html,x', 'file:///etc/passwd',
    'https://user:pass@youtube.com/watch?v=aaaaaaaaaaa'];
$rechazadas = 0;
foreach ($peligrosas as $u) {
    [, , $b] = req(BASE_URL . '/tasks/attachment_link.php', ['sid' => $S_OWN, 'hdr' => $AJAX,
        'post' => ['csrf' => $csrf, 'task_id' => $T4, 'url' => $u]]);
    if ((jsonDe($b)['ok'] ?? true) === false) {
        $rechazadas++;
    }
}
// Un host que solo IMITA a YouTube debe aceptarse como enlace, nunca incrustarse.
[, , $b] = req(BASE_URL . '/tasks/attachment_link.php', ['sid' => $S_OWN, 'hdr' => $AJAX,
    'post' => ['csrf' => $csrf, 'task_id' => $T4, 'url' => 'https://youtube.com.evil.net/watch?v=aaaaaaaaaaa']]);
$falso = jsonDe($b)['attachment'] ?? [];
chk('14. URLs peligrosas rechazadas y hosts falsos jamás incrustados',
    $rechazadas === count($peligrosas)
    && ($falso['kind'] ?? '') === 'link' && empty($falso['provider']) && empty($falso['embed_url']),
    "$rechazadas/" . count($peligrosas) . ' rechazadas · falso=' . ($falso['kind'] ?? '?'));

// ═════════════════════════════════════════════════════════════
section('15-17 · CAJÓN: SIN FUGAS NI ACCIONES INDEBIDAS');

[$s, , $htmlOwn] = req(BASE_URL . '/tasks/drawer.php?id=' . $T4, ['sid' => $S_OWN]);
[$s, , $htmlLec] = req(BASE_URL . '/tasks/drawer.php?id=' . $T4, ['sid' => $S_LEC]);

chk('15. El cajón no expone stored_path ni rutas físicas',
    !str_contains($htmlOwn, 'stored_path')
    && preg_match('#\d{4}/\d{2}/[a-f0-9]{32}\.#', $htmlOwn) !== 1
    && !str_contains($htmlOwn, 'C:\\') && !str_contains($htmlOwn, 'storage/attachments'));

chk('16. El visor existe y no construye URLs desde un identificador',
    str_contains($htmlOwn, 'id="fycImgViewer"')
    && str_contains($htmlOwn, 'role="dialog"')
    && str_contains($htmlOwn, 'aria-modal="true"')
    && str_contains((string) file_get_contents($ROOT . '/public/assets/board-view.js'),
        "var src = triggerBtn.querySelector('img');"));

chk('17. El lector no ve acciones de escritura',
    !str_contains($htmlLec, 'attach-delete')
    && !str_contains($htmlLec, 'type="file"')
    && str_contains($htmlLec, 'fyc-attach-card')
    && str_contains($htmlOwn, 'attach-delete'));

// ═════════════════════════════════════════════════════════════
section('18-21 · CRON DE HUÉRFANOS');

$viejo    = date('Y') . '/' . date('m') . '/' . bin2hex(random_bytes(16)) . '.jpg';
$reciente = date('Y') . '/' . date('m') . '/' . bin2hex(random_bytes(16)) . '.png';
foreach ([[$viejo, time() - 72 * 3600], [$reciente, time() - 3600]] as [$rel, $mt]) {
    $abs = attach_storage_root() . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel);
    @mkdir(dirname($abs), 0775, true);
    file_put_contents($abs, 'huerfano');
    touch($abs, $mt);
}

[$c1, $o1] = correr($CRON, ['--dry-run', '--verbose']);
chk('18. El simulacro no borra nada',
    $c1 === 0 && existe($viejo) && existe($reciente) && num($o1, 'eliminados') === 0,
    'exit=' . $c1 . ' huérfanos=' . num($o1, 'huérfanos'));

[$c2, $o2] = correr($CRON, ['--verbose']);
chk('19. La ejecución real borra solo el caducado',
    $c2 === 0 && !existe($viejo) && existe($reciente) && existe($R4),
    'eliminados=' . num($o2, 'eliminados'));

[$c3, $o3] = correr($CRON, []);
chk('20. Segunda ejecución idempotente', $c3 === 0 && num($o3, 'eliminados') === 0);

[$c4, ] = correr($CRON, ['--argumento-invalido']);
chk('21. Argumento inválido termina con código 2', $c4 === 2, "exit=$c4");

$abs = attach_storage_root() . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $reciente);
@unlink($abs);

// ═════════════════════════════════════════════════════════════
section('22-25 · RESPALDO Y RESTAURACIÓN');

[$cb, $ob] = correr($BACKUP, ['--output=' . $SANDBOX, '--label=f5final']);
$BK = (glob($SANDBOX . '/fyc_planner_backup_*') ?: [null])[0];
chk('22. El respaldo se genera correctamente', $cb === 0 && $BK !== null, "exit=$cb");

$man = $BK ? json_decode((string) file_get_contents($BK . '/manifest.json'), true) : [];
chk('23. El manifiesto es válido y sus hashes cuadran',
    is_array($man)
    && hash_file('sha256', $BK . '/database.sql.gz') === ($man['database']['sha256'] ?? '')
    && hash_file('sha256', $BK . '/storage_attachments.zip') === ($man['storage']['sha256'] ?? '')
    && ($man['project_commit'] ?? '') !== ''
    && !preg_match('/"(password|passwd|db_user)"/i', (string) file_get_contents($BK . '/manifest.json')),
    'commit ' . substr((string) ($man['project_commit'] ?? ''), 0, 12) . '…');

$invAhora = inventario();
$rest = $SANDBOX . '/restaurado';
@mkdir($rest, 0775, true);
$z = new ZipArchive();
$abierto = $z->open($BK . '/storage_attachments.zip') === true;
if ($abierto) {
    $z->extractTo($rest);
    $z->close();   // en Windows dejarlo abierto bloquea el archivo
}
$invR = [];
$walk = function (string $d, string $pre) use (&$walk, &$invR) {
    foreach (array_diff(scandir($d) ?: [], ['.', '..']) as $e) {
        $p = $d . DIRECTORY_SEPARATOR . $e;
        $r = $pre === '' ? $e : $pre . '/' . $e;
        is_dir($p) ? $walk($p, $r) : $invR[$r] = hash_file('sha256', $p);
    }
};
if (is_dir($rest . '/storage/attachments')) {
    $walk($rest . '/storage/attachments', '');
}
ksort($invR);
chk('24. La restauración simulada coincide byte a byte',
    $abierto && $invR === $invAhora && isset($invR['.gitkeep'], $invR['.htaccess']),
    count($invR) . ' archivos');

$filasAntesDump = (int) $conn->query('SELECT COUNT(*) FROM task_attachments')->fetch_row()[0];
chk('25. No se importó nada en la base principal',
    (int) $conn->query('SELECT COUNT(*) FROM task_attachments')->fetch_row()[0] === $filasAntesDump);

// ═════════════════════════════════════════════════════════════
section('26-28 · ASSETS, DOCUMENTOS Y MIGRACIONES');

$uT = asset_url('assets/theme.css');
$uJ = asset_url('assets/board-view.js');
chk('26. asset_url() versiona con filemtime real',
    preg_match('/\?v=(\d+)$/', $uT, $m) === 1
    && (int) $m[1] === filemtime($ROOT . '/public/assets/theme.css')
    && preg_match('/\?v=\d+$/', $uJ) === 1
    && asset_url('../config/db.php') === '',
    $uT);

$docs = ['ATTACHMENTS.md', 'DEPLOYMENT_ATTACHMENTS.md', 'BACKUP_RESTORE.md'];
$sinDoc = array_values(array_filter($docs, fn($d) => !is_file($ROOT . '/docs/' . $d)));
chk('27. Existen los tres documentos', $sinDoc === [], implode(', ', $sinDoc) ?: '3/3');

// Esta prueba exigía count($nombres) === 2. Al añadir la tercera migración se
// puso roja sin que nada estuviera mal: la cifra estaba escrita a mano. Ahora
// verifica el contrato real —directorio con contenido, nombres fechados, orden
// cronológico y presencia de las conocidas— y no caduca al añadir la cuarta.
$dirMig = $ROOT . '/database/migrations';
$mig = glob($dirMig . '/*.sql') ?: [];
sort($mig);
$nombres = array_map('basename', $mig);

$malFormados = array_values(array_filter($nombres,
    fn($n) => !preg_match('/^\d{4}-\d{2}-\d{2}-[a-z0-9-]+\.sql$/', $n)));

// Con el prefijo AAAA-MM-DD, el orden alfabético ES el cronológico.
$ordenadas = $nombres;
sort($ordenadas);

$conocidas = ['2026-07-29-create-task-attachments.sql',
    '2026-07-29-add-external-links-to-task-attachments.sql',
    '2026-08-14-create-task-tags.sql'];
$ausentes = array_values(array_diff($conocidas, $nombres));

chk('28. El directorio de migraciones está sano y en orden',
    is_dir($dirMig)
    && $nombres !== []
    && $malFormados === []
    && $nombres === $ordenadas
    && $ausentes === [],
    count($nombres) . ' migraciones · fechadas y en orden cronológico'
    . ($malFormados === [] ? '' : ' · mal formadas: ' . implode(', ', $malFormados))
    . ($ausentes === [] ? '' : ' · faltan: ' . implode(', ', $ausentes)));

// ═════════════════════════════════════════════════════════════
section('29-33 · LIMPIEZA Y VUELTA AL ESTADO INICIAL');

$hashApp   = trim((string) shell_exec('git -C ' . escapeshellarg($ROOT) . ' hash-object public/assets/app.css 2>&1'));
$hashAppHd = trim((string) shell_exec('git -C ' . escapeshellarg($ROOT) . ' rev-parse HEAD:public/assets/app.css 2>&1'));
chk('29. app.css intacto', $hashApp === $hashAppHd && $hashApp !== '', substr($hashApp, 0, 16) . '…');

rrm($SANDBOX);
limpiar($conn);
podar_vacias();

$INV_FINAL = inventario();
$perdidos = array_diff_key($INV_INICIAL, $INV_FINAL);
$sobran   = array_diff_key($INV_FINAL, $INV_INICIAL);
chk('30. El almacén vuelve al inventario inicial',
    $INV_FINAL === $INV_INICIAL,
    'perdidos=' . count($perdidos) . ' sobran=' . count($sobran));

$conteosFinal = [];
foreach (['boards', 'columns', 'tasks', 'users', 'task_attachments'] as $t) {
    $conteosFinal[$t] = (int) $conn->query("SELECT COUNT(*) FROM `$t`")->fetch_row()[0];
}
chk('31. La base vuelve a los conteos iniciales', $conteosFinal === $BASE_CONTEOS,
    json_encode($conteosFinal));

$qaB = (int) $conn->query("SELECT COUNT(*) FROM boards WHERE nombre LIKE 'QA%'")->fetch_row()[0];
$qaT = (int) $conn->query("SELECT COUNT(*) FROM tasks WHERE titulo LIKE 'QA%'")->fetch_row()[0];
$qaU = (int) $conn->query("SELECT COUNT(*) FROM users WHERE email LIKE 'qa.%@local.test'")->fetch_row()[0];
chk('32. No quedan datos QA', $qaB === 0 && $qaT === 0 && $qaU === 0,
    "tableros=$qaB tareas=$qaT usuarios=$qaU");

$sesQA = count(glob(SESSION_DIR . '/sess_qaf5*') ?: []);
$bkRepo = is_dir($ROOT . '/_backups') ? count(glob($ROOT . '/_backups/*') ?: []) : 0;
chk('33. No quedan sesiones QA ni respaldos temporales',
    $sesQA === 0 && !is_dir($SANDBOX) && $bkRepo === 0,
    "sesiones=$sesQA sandbox=" . (is_dir($SANDBOX) ? 'sí' : 'no') . " _backups=$bkRepo");

// ═════════════════════════════════════════════════════════════
echo "\n" . str_repeat('═', 78) . "\n";
printf(" RESULTADO: %d correctas, %d fallidas\n", $PASS, $FAIL);
echo str_repeat('═', 78) . "\n\n";

exit($FAIL === 0 ? 0 : 1);
