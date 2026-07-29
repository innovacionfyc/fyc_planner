<?php
/**
 * tests/attachments_ui_smoke.php
 *
 * Pruebas de la interfaz de adjuntos en el drawer (Fase B).
 *
 * Ejecutar SOLO en local:
 *   php tests/attachments_ui_smoke.php
 *
 * Comprueba el HTML que devuelve tasks/drawer.php para cada perfil,
 * más los flujos de subida y borrado por HTTP. No deja residuos.
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

const QA_TAG      = 'QA ATTACH UI 2026-07-29';
const BASE_URL    = 'http://localhost/fyc_planner/public';
const SESSION_DIR = 'C:/laragon/tmp';

$FIXTURES = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'fyc_attach_ui_fx';

$PASS = 0;
$FAIL = 0;

function ok(string $n, string $d = ''): void { global $PASS; $PASS++; printf("  [OK]    %-54s %s\n", $n, $d); }
function ko(string $n, string $d = ''): void { global $FAIL; $FAIL++; printf("  [FALLO] %-54s %s\n", $n, $d); }
function assertTrue(string $n, bool $c, string $d = ''): void { $c ? ok($n, $d) : ko($n, $d); }
function section(string $t): void { echo "\n" . str_repeat('─', 74) . "\n " . $t . "\n" . str_repeat('─', 74) . "\n"; }

function http_request(string $url, array $o = []): array
{
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HEADER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 60);
    if (!empty($o['sessionId'])) curl_setopt($ch, CURLOPT_COOKIE, 'PHPSESSID=' . $o['sessionId']);
    if (!empty($o['headers']))   curl_setopt($ch, CURLOPT_HTTPHEADER, $o['headers']);
    if (isset($o['post']) || isset($o['files'])) {
        curl_setopt($ch, CURLOPT_POST, true);
        $f = $o['post'] ?? [];
        foreach (($o['files'] ?? []) as $i => $x) {
            $p = is_array($x) ? $x['path'] : $x;
            $n = is_array($x) ? $x['name'] : basename($x);
            $f['files[' . $i . ']'] = new CURLFile($p, 'application/octet-stream', $n);
        }
        curl_setopt($ch, CURLOPT_POSTFIELDS, $f);
    }
    $raw = curl_exec($ch);
    if ($raw === false) { $e = curl_error($ch); curl_close($ch); return [0, '', 'CURL: ' . $e]; }
    $st = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $hs = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);
    return [$st, substr($raw, 0, $hs), substr($raw, $hs)];
}

function make_session(int $uid, string $csrf): string
{
    $sid = bin2hex(random_bytes(16));
    file_put_contents(SESSION_DIR . '/sess_' . $sid,
        'user_id|i:' . $uid . ';csrf|s:' . strlen($csrf) . ':"' . $csrf . '";_auth_ts|i:' . time() . ';');
    return $sid;
}

function cleanup(mysqli $conn): array
{
    $files = 0;
    $q = $conn->query("SELECT a.stored_path FROM task_attachments a
                       JOIN tasks t ON t.id=a.task_id JOIN boards b ON b.id=t.board_id
                       WHERE b.nombre LIKE '" . QA_TAG . "%'");
    foreach ($q->fetch_all(MYSQLI_ASSOC) as $r) {
        if (attach_delete_file((string) $r['stored_path'])) $files++;
    }
    $conn->query("DELETE FROM boards WHERE nombre LIKE '" . QA_TAG . "%'");
    $boards = $conn->affected_rows;
    $conn->query("DELETE FROM users WHERE email LIKE 'qa.ui.%@local.test'");
    return ['files' => $files, 'boards' => $boards, 'users' => $conn->affected_rows];
}

echo "\n══════════════════════════════════════════════════════════════════════════\n";
echo " PRUEBAS DE INTERFAZ — ADJUNTOS EN EL DRAWER (Fase B)\n";
echo "══════════════════════════════════════════════════════════════════════════\n";

section('PREPARACIÓN');
$pre = cleanup($conn);
printf("  restos previos: %d tableros, %d usuarios, %d archivos\n", $pre['boards'], $pre['users'], $pre['files']);

$base = [];
foreach (['boards', 'columns', 'tasks', 'users', 'task_attachments'] as $t) {
    $base[$t] = (int) $conn->query("SELECT COUNT(*) FROM `$t`")->fetch_row()[0];
}

$csrf = bin2hex(random_bytes(32));

$email = 'qa.ui.' . bin2hex(random_bytes(4)) . '@local.test';
$hash  = password_hash(bin2hex(random_bytes(12)), PASSWORD_DEFAULT);
$st = $conn->prepare("INSERT INTO users (nombre,email,pass_hash,estado,rol,is_admin,activo) VALUES ('QA Ajeno UI',?,?, 'aprobado','user',0,1)");
$st->bind_param('ss', $email, $hash); $st->execute(); $U_AJENO = (int) $conn->insert_id; $st->close();

$U_PROP = 2; $U_EDITOR = 3; $U_LECTOR = 4;

$st = $conn->prepare("INSERT INTO boards (nombre,color_hex,owner_user_id,team_id) VALUES (?, '#d32f57', ?, NULL)");
$bn = QA_TAG; $st->bind_param('si', $bn, $U_PROP); $st->execute(); $BOARD = (int) $conn->insert_id; $st->close();

$st = $conn->prepare("INSERT INTO board_members (board_id,user_id,rol) VALUES (?,?,?)");
foreach ([[$U_PROP, 'propietario'], [$U_EDITOR, 'editor'], [$U_LECTOR, 'lector']] as [$u, $r]) {
    $st->bind_param('iis', $BOARD, $u, $r); $st->execute();
}
$st->close();

$st = $conn->prepare("INSERT INTO `columns` (board_id,nombre,orden,is_done) VALUES (?, 'QA', 1, 0)");
$st->bind_param('i', $BOARD); $st->execute(); $COL = (int) $conn->insert_id; $st->close();

$st = $conn->prepare("INSERT INTO tasks (board_id,column_id,titulo,prioridad) VALUES (?,?, 'QA Tarea UI', 'med')");
$st->bind_param('ii', $BOARD, $COL); $st->execute(); $TASK = (int) $conn->insert_id; $st->close();

$S_PROP = make_session($U_PROP, $csrf);
$S_EDIT = make_session($U_EDITOR, $csrf);
$S_LECT = make_session($U_LECTOR, $csrf);
$S_AJEN = make_session($U_AJENO, $csrf);

// Fixtures
if (!is_dir($FIXTURES)) mkdir($FIXTURES, 0775, true);
$im = imagecreatetruecolor(60, 40);
imagefill($im, 0, 0, imagecolorallocate($im, 210, 47, 87));
imagejpeg($im, $FIXTURES . '/foto.jpg', 85);
imagedestroy($im);
// WAV mínimo válido (cabecera RIFF + silencio)
$pcm = str_repeat("\x00\x00", 8000);
$wav = 'RIFF' . pack('V', 36 + strlen($pcm)) . 'WAVEfmt ' . pack('V', 16)
     . pack('v', 1) . pack('v', 1) . pack('V', 8000) . pack('V', 16000)
     . pack('v', 2) . pack('v', 16) . 'data' . pack('V', strlen($pcm)) . $pcm;
file_put_contents($FIXTURES . '/sonido.wav', $wav);
// MP4 mínimo (ftyp isom): finfo lo reconoce como video/mp4
$mp4 = "\x00\x00\x00\x20ftypisom\x00\x00\x02\x00isomiso2avc1mp41"
     . "\x00\x00\x00\x08free" . str_repeat("\x00", 512);
file_put_contents($FIXTURES . '/clip.mp4', $mp4);

printf("  board=%d task=%d | prop=%d editor=%d lector=%d ajeno=%d\n", $BOARD, $TASK, $U_PROP, $U_EDITOR, $U_LECTOR, $U_AJENO);

$DRAWER = BASE_URL . '/tasks/drawer.php?id=' . $TASK;
$UP     = BASE_URL . '/tasks/attachment_upload.php';
$DEL    = BASE_URL . '/tasks/attachment_delete.php';
$AJAX   = ['X-Requested-With: fetch', 'Accept: application/json'];

// ═════════════════════════════════════════════════════════════
section('1 · DRAWER SIN ADJUNTOS');

[$s, $h, $html] = http_request($DRAWER, ['sessionId' => $S_EDIT, 'headers' => ['X-Requested-With: fetch']]);
assertTrue('1. Drawer sin adjuntos muestra estado vacío',
    $s === 200 && str_contains($html, 'Adjuntos (0)') && str_contains($html, 'Sin adjuntos todavía'),
    "http=$s");

// ═════════════════════════════════════════════════════════════
section('2-4 · PREVISUALIZACIÓN POR TIPO');

$ids = [];
foreach ([['foto.jpg', 'image'], ['sonido.wav', 'audio'], ['clip.mp4', 'video']] as [$fn, $kind]) {
    [$s, $h, $b] = http_request($UP, ['sessionId' => $S_EDIT, 'headers' => $AJAX,
        'post' => ['csrf' => $csrf, 'task_id' => $TASK], 'files' => [$FIXTURES . '/' . $fn]]);
    $j = json_decode($b, true);
    if (($j['ok'] ?? false) === true && !empty($j['attachments'])) {
        $ids[$kind] = (int) $j['attachments'][0]['id'];
    } else {
        ko('Subida previa de ' . $fn, substr($b, 0, 200));
    }
}

[$s, $h, $html] = http_request($DRAWER, ['sessionId' => $S_EDIT, 'headers' => ['X-Requested-With: fetch']]);

assertTrue('2. Imagen se muestra con <img> y src protegido',
    isset($ids['image'])
    && preg_match('/<img[^>]+src="[^"]*attachment\.php\?id=' . $ids['image'] . '"/', $html) === 1
    && str_contains($html, 'loading="lazy"'),
    'id=' . ($ids['image'] ?? '-'));

assertTrue('3. Audio usa <audio controls preload="metadata">',
    isset($ids['audio'])
    && str_contains($html, '<audio controls preload="metadata"')
    && str_contains($html, 'attachment.php?id=' . $ids['audio'])
    && !str_contains($html, 'autoplay'),
    'id=' . ($ids['audio'] ?? '-'));

assertTrue('4. Video usa <video controls preload="metadata" playsinline>',
    isset($ids['video'])
    && str_contains($html, '<video controls preload="metadata" playsinline')
    && str_contains($html, 'attachment.php?id=' . $ids['video']),
    'id=' . ($ids['video'] ?? '-'));

assertTrue('4b. Contador refleja los 3 adjuntos', str_contains($html, 'Adjuntos (3)'));

// ═════════════════════════════════════════════════════════════
section('5-8 · PERMISOS EN LA INTERFAZ');

[$s, $h, $htmlL] = http_request($DRAWER, ['sessionId' => $S_LECT, 'headers' => ['X-Requested-With: fetch']]);

assertTrue('5. Lector ve los adjuntos', $s === 200 && str_contains($htmlL, 'Adjuntos (3)'), "http=$s");
assertTrue('6. Lector NO ve el selector de archivos',
    !str_contains($htmlL, 'drawer_attach_input') && !str_contains($htmlL, 'attach-pick'));
assertTrue('7. Lector NO ve el botón Eliminar',
    !str_contains($htmlL, 'attach-delete'));
assertTrue('7b. Lector SÍ ve el enlace de descarga', str_contains($htmlL, 'download'));

[$s, $h, $htmlE] = http_request($DRAWER, ['sessionId' => $S_EDIT, 'headers' => ['X-Requested-With: fetch']]);
assertTrue('8. Editor ve selector y botones de eliminar',
    str_contains($htmlE, 'drawer_attach_input') && str_contains($htmlE, 'attach-pick')
    && str_contains($htmlE, 'attach-delete'));

[$s, $h, $htmlP] = http_request($DRAWER, ['sessionId' => $S_PROP, 'headers' => ['X-Requested-With: fetch']]);
assertTrue('8b. Propietario ve selector y eliminar',
    str_contains($htmlP, 'attach-pick') && str_contains($htmlP, 'attach-delete'));

// ═════════════════════════════════════════════════════════════
section('9-11 · SUBIDA, BORRADO Y ACCESO AJENO');

[$s, $h, $b] = http_request($UP, ['sessionId' => $S_EDIT, 'headers' => $AJAX,
    'post' => ['csrf' => $csrf, 'task_id' => $TASK], 'files' => [$FIXTURES . '/foto.jpg']]);
$j = json_decode($b, true);
$tmpId = ($j['ok'] ?? false) ? (int) $j['attachments'][0]['id'] : 0;
assertTrue('9. Editor puede subir desde el flujo real', $tmpId > 0, "http=$s");

[$s, $h, $b] = http_request($DEL, ['sessionId' => $S_EDIT, 'headers' => $AJAX,
    'post' => ['csrf' => $csrf, 'attachment_id' => $tmpId]]);
$j = json_decode($b, true);
$gone = (int) $conn->query("SELECT COUNT(*) FROM task_attachments WHERE id=$tmpId")->fetch_row()[0] === 0;
assertTrue('10. Editor puede eliminar y la fila desaparece', ($j['ok'] ?? false) === true && $gone, "http=$s");

[$s, $h, $b] = http_request($DRAWER, ['sessionId' => $S_AJEN, 'headers' => ['X-Requested-With: fetch']]);
assertTrue('11. Usuario ajeno NO abre el drawer', $s === 403, "http=$s");

// ═════════════════════════════════════════════════════════════
section('12-14 · SEGURIDAD DEL RENDERIZADO');

$xss = '<img src=x onerror=alert(1)>.jpg';
[$s, $h, $b] = http_request($UP, ['sessionId' => $S_EDIT, 'headers' => $AJAX,
    'post' => ['csrf' => $csrf, 'task_id' => $TASK],
    'files' => [['path' => $FIXTURES . '/foto.jpg', 'name' => $xss]]]);
$j = json_decode($b, true);
$xssId = ($j['ok'] ?? false) ? (int) $j['attachments'][0]['id'] : 0;

[$s, $h, $html] = http_request($DRAWER, ['sessionId' => $S_EDIT, 'headers' => ['X-Requested-With: fetch']]);

assertTrue('12. Nombre peligroso se escapa (no se inyecta HTML)',
    $xssId > 0
    && !str_contains($html, '<img src=x onerror=alert(1)>')
    && str_contains($html, '&lt;img src=x onerror=alert(1)&gt;'),
    'id=' . $xssId);

// stored_path real de los adjuntos: no debe aparecer en el HTML
$leak = false;
foreach ($conn->query("SELECT a.stored_path FROM task_attachments a JOIN tasks t ON t.id=a.task_id WHERE t.id=$TASK")->fetch_all(MYSQLI_ASSOC) as $r) {
    if (str_contains($html, (string) $r['stored_path'])) { $leak = true; break; }
    $hex = basename((string) $r['stored_path']);
    if (str_contains($html, $hex)) { $leak = true; break; }
}
assertTrue('13. El HTML no expone stored_path ni el nombre físico',
    !$leak && !str_contains($html, 'storage/attachments'));

assertTrue('14. La descarga usa ?download=1',
    preg_match('/href="[^"]*attachment\.php\?id=\d+&(amp;)?download=1"/', $html) === 1);

// ═════════════════════════════════════════════════════════════
section('15-17 · RESPONSIVE Y REPRODUCCIÓN');

$theme = (string) file_get_contents(__DIR__ . '/../public/assets/theme.css');
assertTrue('15. Cuadrícula fluida definida (1 col en móvil, varias si cabe)',
    str_contains($theme, '.fyc-attach-grid')
    && str_contains($theme, 'auto-fill')
    && str_contains($theme, 'minmax('));
assertTrue('15b. Los reproductores no desbordan la tarjeta',
    str_contains($theme, '.fyc-attach-media audio') && str_contains($theme, 'max-width: 100%'));
assertTrue('15c. Foco visible para teclado', str_contains($theme, ':focus-visible'));

// Range sobre el audio y el video reales servidos
foreach ([['audio', 16], ['video', 17]] as [$kind, $num]) {
    if (!isset($ids[$kind])) { ko("$num. Búsqueda temporal en $kind", 'sin adjunto'); continue; }
    $url = BASE_URL . '/tasks/attachment.php?id=' . $ids[$kind];
    [$st1, $h1, $b1] = http_request($url, ['sessionId' => $S_LECT, 'headers' => ['Range: bytes=0-99']]);
    $ar = preg_match('/^Accept-Ranges:\s*bytes/mi', $h1) === 1;
    assertTrue("$num. $kind admite búsqueda temporal (206 + Accept-Ranges)",
        $st1 === 206 && $ar && strlen($b1) === 100, "http=$st1 bytes=" . strlen($b1));
}

// ═════════════════════════════════════════════════════════════
section('18-21 · ERRORES Y COMPORTAMIENTO DEL CLIENTE');

// 19 primero: 422 con detalle por archivo
file_put_contents($FIXTURES . '/falso.png', "no soy una imagen");
[$s, $h, $b] = http_request($UP, ['sessionId' => $S_EDIT, 'headers' => $AJAX,
    'post' => ['csrf' => $csrf, 'task_id' => $TASK], 'files' => [$FIXTURES . '/falso.png']]);
$j = json_decode($b, true);
assertTrue('19. Error 422 devuelve motivo por archivo (visible en UI)',
    $s === 422 && ($j['ok'] ?? true) === false && !empty($j['rejected'][0]['error']),
    'motivo=' . ($j['rejected'][0]['error'] ?? '-'));

// 18: la ruta de 413 existe en backend y está contemplada en el cliente
$upSrc = (string) file_get_contents(__DIR__ . '/../public/tasks/attachment_upload.php');
$jsSrc = (string) file_get_contents(__DIR__ . '/../public/assets/board-view.js');
assertTrue('18. El 413 se detecta en backend y se muestra en el cliente',
    str_contains($upSrc, 'payload_too_large') && str_contains($upSrc, '413')
    && str_contains($jsSrc, 'res.status === 413'));

assertTrue('20. Doble envío bloqueado (bandera de ocupado)',
    str_contains($jsSrc, 'attachBusy') && str_contains($jsSrc, 'attachSetBusy(true)')
    && str_contains($jsSrc, 'btn.disabled = true'));

assertTrue('21. Refresco solo del drawer, sin recargar la página',
    str_contains($jsSrc, 'loadDrawer(taskId)')
    && !preg_match('/location\.reload\(\)/', $jsSrc));

assertTrue('21b. El cliente valida cantidad y tamaño antes de enviar',
    str_contains($jsSrc, 'ATTACH_MAX_FILES') && str_contains($jsSrc, 'ATTACH_LIMITS'));

// ═════════════════════════════════════════════════════════════
section('LIMPIEZA');

$paths = [];
foreach ($conn->query("SELECT a.stored_path FROM task_attachments a
                       JOIN tasks t ON t.id=a.task_id JOIN boards b ON b.id=t.board_id
                       WHERE b.nombre LIKE '" . QA_TAG . "%'")->fetch_all(MYSQLI_ASSOC) as $r) {
    $paths[] = (string) $r['stored_path'];
}

$post = cleanup($conn);
printf("  eliminados: %d tableros, %d usuarios, %d archivos\n", $post['boards'], $post['users'], $post['files']);

foreach ([$S_PROP, $S_EDIT, $S_LECT, $S_AJEN] as $sid) @unlink(SESSION_DIR . '/sess_' . $sid);
foreach (glob($FIXTURES . '/*') ?: [] as $p) @unlink($p);
@rmdir($FIXTURES);

$orph = 0;
foreach ($paths as $p) if (attach_absolute_path($p) !== null) $orph++;
assertTrue('LIMPIEZA · sin archivos huérfanos', $orph === 0, "huérfanos=$orph");

$after = [];
foreach (array_keys($base) as $t) $after[$t] = (int) $conn->query("SELECT COUNT(*) FROM `$t`")->fetch_row()[0];
foreach ($base as $t => $n) {
    assertTrue("LIMPIEZA · filas en $t", $after[$t] === $n, "antes=$n después={$after[$t]}");
}

$md = attach_storage_root() . '/' . date('Y') . '/' . date('m');
if (is_dir($md) && count(glob($md . '/*') ?: []) === 0) { @rmdir($md); @rmdir(dirname($md)); }

$restantes = glob(attach_storage_root() . '/*') ?: [];
$soloEsqueleto = true;
foreach ($restantes as $r) {
    if (!in_array(basename($r), ['.gitkeep', '.htaccess'], true)) { $soloEsqueleto = false; break; }
}
assertTrue('LIMPIEZA · storage/attachments solo con .gitkeep y .htaccess', $soloEsqueleto);

echo "\n" . str_repeat('═', 74) . "\n";
printf(" RESULTADO: %d correctas, %d fallidas\n", $PASS, $FAIL);
echo str_repeat('═', 74) . "\n";
exit($FAIL === 0 ? 0 : 1);
