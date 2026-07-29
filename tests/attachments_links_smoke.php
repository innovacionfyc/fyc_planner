<?php
/**
 * tests/attachments_links_smoke.php
 *
 * Pruebas de la Fase D: enlaces externos y embeds de YouTube y Vimeo.
 *
 * Ejecutar SOLO en local:
 *   php tests/attachments_links_smoke.php
 *
 * No realiza ninguna petición hacia los sitios externos: eso es
 * precisamente uno de los puntos que se verifican.
 * No deja residuos.
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

const QA_TAG      = 'QA ATTACH LINKS 2026-07-29';
const BASE_URL    = 'http://localhost/fyc_planner/public';
const SESSION_DIR = 'C:/laragon/tmp';

$FIX = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'fyc_links_fx';

$PASS = 0; $FAIL = 0;
function ok(string $n, string $d = ''): void { global $PASS; $PASS++; printf("  [OK]    %-56s %s\n", $n, $d); }
function ko(string $n, string $d = ''): void { global $FAIL; $FAIL++; printf("  [FALLO] %-56s %s\n", $n, $d); }
function chk(string $n, bool $c, string $d = ''): void { $c ? ok($n, $d) : ko($n, $d); }
function section(string $t): void { echo "\n" . str_repeat('─', 78) . "\n " . $t . "\n" . str_repeat('─', 78) . "\n"; }

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
            $f['files[' . $i . ']'] = new CURLFile($x, 'application/octet-stream', basename($x));
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
    $n = 0;
    foreach ($conn->query("SELECT a.stored_path FROM task_attachments a
                           JOIN tasks t ON t.id=a.task_id JOIN boards b ON b.id=t.board_id
                           WHERE b.nombre LIKE '" . QA_TAG . "%' AND a.stored_path IS NOT NULL")->fetch_all(MYSQLI_ASSOC) as $r) {
        if (attach_delete_file((string) $r['stored_path'])) $n++;
    }
    $conn->query("DELETE FROM boards WHERE nombre LIKE '" . QA_TAG . "%'");
    $b = $conn->affected_rows;
    $conn->query("DELETE FROM users WHERE email LIKE 'qa.links.%@local.test'");
    return ['files' => $n, 'boards' => $b, 'users' => $conn->affected_rows];
}

echo "\n════════════════════════════════════════════════════════════════════════════════\n";
echo " PRUEBAS FASE D — ENLACES EXTERNOS Y EMBEDS (YouTube / Vimeo)\n";
echo "════════════════════════════════════════════════════════════════════════════════\n";

$DRW = (string) file_get_contents(__DIR__ . '/../public/tasks/drawer.php');
$JS  = (string) file_get_contents(__DIR__ . '/../public/assets/board-view.js');
$LNK = (string) file_get_contents(__DIR__ . '/../public/tasks/attachment_link.php');
$CSS = (string) file_get_contents(__DIR__ . '/../public/assets/theme.css');

section('PREPARACIÓN');
$pre = cleanup($conn);
printf("  restos previos: %d tableros, %d usuarios, %d archivos\n", $pre['boards'], $pre['users'], $pre['files']);

$base = [];
foreach (['boards', 'columns', 'tasks', 'users', 'task_attachments'] as $t) {
    $base[$t] = (int) $conn->query("SELECT COUNT(*) FROM `$t`")->fetch_row()[0];
}

$csrf  = bin2hex(random_bytes(32));
$email = 'qa.links.' . bin2hex(random_bytes(4)) . '@local.test';
$hash  = password_hash(bin2hex(random_bytes(12)), PASSWORD_DEFAULT);
$st = $conn->prepare("INSERT INTO users (nombre,email,pass_hash,estado,rol,is_admin,activo) VALUES ('QA Links',?,?, 'aprobado','user',0,1)");
$st->bind_param('ss', $email, $hash); $st->execute(); $U_AJENO = (int) $conn->insert_id; $st->close();

$U_PROP = 2; $U_EDIT = 3; $U_LECT = 4;
$st = $conn->prepare("INSERT INTO boards (nombre,color_hex,owner_user_id,team_id) VALUES (?, '#d32f57', ?, NULL)");
$bn = QA_TAG; $st->bind_param('si', $bn, $U_PROP); $st->execute(); $BOARD = (int) $conn->insert_id; $st->close();
$st = $conn->prepare("INSERT INTO board_members (board_id,user_id,rol) VALUES (?,?,?)");
foreach ([[$U_PROP,'propietario'],[$U_EDIT,'editor'],[$U_LECT,'lector']] as [$u,$r]) { $st->bind_param('iis',$BOARD,$u,$r); $st->execute(); }
$st->close();
$st = $conn->prepare("INSERT INTO `columns` (board_id,nombre,orden,is_done) VALUES (?, 'QA', 1, 0)");
$st->bind_param('i', $BOARD); $st->execute(); $COL = (int) $conn->insert_id; $st->close();
$st = $conn->prepare("INSERT INTO tasks (board_id,column_id,titulo,prioridad) VALUES (?,?, 'QA Links', 'med')");
$st->bind_param('ii', $BOARD, $COL); $st->execute(); $TASK = (int) $conn->insert_id; $st->close();

$S_EDIT = make_session($U_EDIT, $csrf);
$S_LECT = make_session($U_LECT, $csrf);
$S_AJEN = make_session($U_AJENO, $csrf);

if (!is_dir($FIX)) mkdir($FIX, 0775, true);
$im = imagecreatetruecolor(40, 30);
imagefill($im, 0, 0, imagecolorallocate($im, 200, 40, 60));
imagejpeg($im, $FIX . '/foto.jpg', 85); imagedestroy($im);

printf("  board=%d task=%d | editor=%d lector=%d ajeno=%d\n", $BOARD, $TASK, $U_EDIT, $U_LECT, $U_AJENO);

$LINKEP = BASE_URL . '/tasks/attachment_link.php';
$DELEP  = BASE_URL . '/tasks/attachment_delete.php';
$UPEP   = BASE_URL . '/tasks/attachment_upload.php';
$DRWEP  = BASE_URL . '/tasks/drawer.php?id=' . $TASK;
$AJAX   = ['X-Requested-With: fetch', 'Accept: application/json'];

/** Añade un enlace y devuelve [status, json] */
function addLink(string $ep, string $sid, string $csrf, int $task, string $url, array $ajax): array
{
    [$s, , $b] = http_request($ep, ['sessionId' => $sid, 'headers' => $ajax,
        'post' => ['csrf' => $csrf, 'task_id' => $task, 'url' => $url]]);
    return [$s, json_decode($b, true) ?: []];
}

$ids = [];

// ═════════════════════════════════════════════════════════════
section('1-8 · VALIDACIÓN DE URLs');

[$s, $j] = addLink($LINKEP, $S_EDIT, $csrf, $TASK, 'https://ejemplo.com/articulo', $AJAX);
$idHttps = ($j['ok'] ?? false) ? (int) $j['attachment']['id'] : 0;
if ($idHttps) $ids['link_https'] = $idHttps;
chk('1. Enlace HTTPS normal aceptado', $idHttps > 0 && ($j['attachment']['kind'] ?? '') === 'link', "http=$s");

[$s, $j] = addLink($LINKEP, $S_EDIT, $csrf, $TASK, 'http://ejemplo.org/pagina', $AJAX);
$idHttp = ($j['ok'] ?? false) ? (int) $j['attachment']['id'] : 0;
if ($idHttp) $ids['link_http'] = $idHttp;
chk('2. Enlace HTTP normal aceptado', $idHttp > 0, "http=$s");

foreach ([
    ['3. javascript: rechazado',  'javascript:alert(1)'],
    ['4. data: rechazado',        'data:text/html,<script>alert(1)</script>'],
    ['5. file:// rechazado',      'file:///etc/passwd'],
    ['6. ftp:// rechazado',       'ftp://ejemplo.com/x'],
    ['7. credenciales rechazadas','https://user:pass@ejemplo.com/'],
] as [$nombre, $u]) {
    [$s, $j] = addLink($LINKEP, $S_EDIT, $csrf, $TASK, $u, $AJAX);
    chk($nombre, $s === 422 && ($j['ok'] ?? true) === false, 'motivo=' . ($j['message'] ?? '-'));
}

[$s, $j] = addLink($LINKEP, $S_EDIT, $csrf, $TASK, 'https://ejemplo.com/' . str_repeat('a', 2100), $AJAX);
chk('8. URL de más de 2048 caracteres rechazada', $s === 422 && ($j['ok'] ?? true) === false, 'motivo=' . ($j['message'] ?? '-'));

// Extras de seguridad
[$s, $j] = addLink($LINKEP, $S_EDIT, $csrf, $TASK, "https://ejemplo.com/x\nHost: malo.com", $AJAX);
chk('8b. URL con salto de línea rechazada', $s === 422 && ($j['ok'] ?? true) === false);
[$s, $j] = addLink($LINKEP, $S_EDIT, $csrf, $TASK, 'https://', $AJAX);
chk('8c. Host vacío rechazado', $s === 422 && ($j['ok'] ?? true) === false);
[$s, $j] = addLink($LINKEP, $S_EDIT, $csrf, $TASK, 'https://ejemplo.соm/x', $AJAX);  // 'с' cirílica
chk('8d. Unicode engañoso en el host rechazado', $s === 422 && ($j['ok'] ?? true) === false);
[$s, $j] = addLink($LINKEP, $S_EDIT, $csrf, $TASK, '<iframe src="https://youtube.com/embed/dQw4w9WgXcQ"></iframe>', $AJAX);
chk('8e. HTML de iframe pegado rechazado', $s === 422 && ($j['ok'] ?? true) === false);

// ═════════════════════════════════════════════════════════════
section('9-15 · YOUTUBE');

$VID = 'dQw4w9WgXcQ';
foreach ([
    ['9. YouTube watch?v= válido',      'https://www.youtube.com/watch?v=' . $VID],
    ['10. youtu.be válido',             'https://youtu.be/' . $VID],
    ['11. YouTube /embed/ válido',      'https://www.youtube.com/embed/' . $VID],
    ['12. YouTube /shorts/ válido',     'https://www.youtube.com/shorts/' . $VID],
    ['13. youtube-nocookie válido',     'https://www.youtube-nocookie.com/embed/' . $VID],
] as [$nombre, $u]) {
    [$s, $j] = addLink($LINKEP, $S_EDIT, $csrf, $TASK, $u, $AJAX);
    $a = $j['attachment'] ?? [];
    $bien = ($j['ok'] ?? false) === true
        && ($a['kind'] ?? '') === 'embed'
        && ($a['provider'] ?? '') === 'youtube'
        && ($a['embed_url'] ?? '') === 'https://www.youtube-nocookie.com/embed/' . $VID;
    chk($nombre, $bien, 'embed=' . ($a['embed_url'] ?? '-'));
    if ($bien) $ids['yt_' . md5($u)] = (int) $a['id'];
}

[$s, $j] = addLink($LINKEP, $S_EDIT, $csrf, $TASK, 'https://youtube.com.malicioso.com/watch?v=' . $VID, $AJAX);
$a = $j['attachment'] ?? [];
chk('14. Host falso de YouTube NO se trata como proveedor',
    ($j['ok'] ?? false) === true && ($a['kind'] ?? '') === 'link' && ($a['provider'] ?? null) === null,
    'kind=' . ($a['kind'] ?? '-') . ' provider=' . var_export($a['provider'] ?? null, true));
if (($a['id'] ?? 0)) $ids['yt_falso'] = (int) $a['id'];

[$s, $j] = addLink($LINKEP, $S_EDIT, $csrf, $TASK, 'https://www.youtube.com/watch?v=corto', $AJAX);
$a = $j['attachment'] ?? [];
chk('15. ID de YouTube inválido cae a enlace normal',
    ($a['kind'] ?? '') === 'link' && ($a['provider'] ?? null) === null,
    'kind=' . ($a['kind'] ?? '-'));
if (($a['id'] ?? 0)) $ids['yt_idmalo'] = (int) $a['id'];

// ═════════════════════════════════════════════════════════════
section('16-19 · VIMEO');

$VIM = '123456789';
foreach ([
    ['16. vimeo.com/NUMERO válido',        'https://vimeo.com/' . $VIM],
    ['17. player.vimeo.com/video/ válido', 'https://player.vimeo.com/video/' . $VIM],
] as [$nombre, $u]) {
    [$s, $j] = addLink($LINKEP, $S_EDIT, $csrf, $TASK, $u, $AJAX);
    $a = $j['attachment'] ?? [];
    $bien = ($a['kind'] ?? '') === 'embed' && ($a['provider'] ?? '') === 'vimeo'
        && ($a['embed_url'] ?? '') === 'https://player.vimeo.com/video/' . $VIM;
    chk($nombre, $bien, 'embed=' . ($a['embed_url'] ?? '-'));
    if ($bien) $ids['vm_' . md5($u)] = (int) $a['id'];
}

[$s, $j] = addLink($LINKEP, $S_EDIT, $csrf, $TASK, 'https://vimeo.com.malicioso.com/' . $VIM, $AJAX);
$a = $j['attachment'] ?? [];
chk('18. Host falso de Vimeo NO se trata como proveedor',
    ($a['kind'] ?? '') === 'link' && ($a['provider'] ?? null) === null);
if (($a['id'] ?? 0)) $ids['vm_falso'] = (int) $a['id'];

[$s, $j] = addLink($LINKEP, $S_EDIT, $csrf, $TASK, 'https://vimeo.com/abcdefg', $AJAX);
$a = $j['attachment'] ?? [];
chk('19. ID de Vimeo no numérico cae a enlace normal', ($a['kind'] ?? '') === 'link');
if (($a['id'] ?? 0)) $ids['vm_idmalo'] = (int) $a['id'];

// ═════════════════════════════════════════════════════════════
section('20-23 · MODELO DE DATOS Y CONSTRUCCIÓN DEL EMBED');

$row = $conn->query("SELECT stored_path, external_url, mime, size_bytes, provider, meta_json
                     FROM task_attachments WHERE id=" . (int) $idHttps)->fetch_assoc();
chk('20. El enlace se guarda sin stored_path',
    $row && $row['stored_path'] === null && $row['external_url'] !== null
    && $row['mime'] === null && $row['size_bytes'] === null,
    'external_url=' . substr((string) ($row['external_url'] ?? ''), 0, 40));

// Subir un archivo real: debe seguir conservando stored_path
[$s, , $b] = http_request($UPEP, ['sessionId' => $S_EDIT, 'headers' => $AJAX,
    'post' => ['csrf' => $csrf, 'task_id' => $TASK], 'files' => [$FIX . '/foto.jpg']]);
$ju = json_decode($b, true);
$fileId = ($ju['ok'] ?? false) ? (int) $ju['attachments'][0]['id'] : 0;
$frow = $fileId ? $conn->query("SELECT stored_path, external_url, mime, size_bytes FROM task_attachments WHERE id=$fileId")->fetch_assoc() : null;
chk('21. El archivo previo conserva stored_path y sus datos',
    $frow && $frow['stored_path'] !== null && $frow['external_url'] === null
    && $frow['mime'] === 'image/jpeg' && (int) $frow['size_bytes'] > 0,
    "id=$fileId");
if ($fileId) $ids['archivo'] = $fileId;

$ytRow = $conn->query("SELECT external_url, provider, meta_json FROM task_attachments
                       WHERE provider='youtube' AND task_id=$TASK LIMIT 1")->fetch_assoc();
$m = json_decode((string) ($ytRow['meta_json'] ?? ''), true);
chk('22. embed_url se construye desde plantilla + video_id validado',
    is_array($m) && ($m['video_id'] ?? '') === $VID
    && attach_build_embed_url('youtube', $m['video_id']) === 'https://www.youtube-nocookie.com/embed/' . $VID,
    'video_id=' . ($m['video_id'] ?? '-'));

// El HTML del drawer no debe usar external_url como src del iframe
[$s, , $html] = http_request($DRWEP, ['sessionId' => $S_EDIT, 'headers' => ['X-Requested-With: fetch']]);
$srcs = [];
preg_match_all('/<iframe[^>]+src="([^"]*)"/i', $html, $mm);
$srcs = $mm[1] ?? [];
$todosDesdePlantilla = true;
foreach ($srcs as $src) {
    if (!preg_match('#^https://(www\.youtube-nocookie\.com/embed/[A-Za-z0-9_-]{11}|player\.vimeo\.com/video/\d+)$#', $src)) {
        $todosDesdePlantilla = false;
    }
}
chk('23. Ningún iframe usa external_url como src',
    count($srcs) > 0 && $todosDesdePlantilla
    && !str_contains($html, 'src="https://youtube.com.malicioso.com')
    && !str_contains($html, 'src="https://ejemplo.com'),
    'iframes=' . count($srcs));

// ═════════════════════════════════════════════════════════════
section('24-27 · PERMISOS');

[$s, $j] = addLink($LINKEP, $S_LECT, $csrf, $TASK, 'https://ejemplo.com/lector', $AJAX);
chk('24. El lector NO puede añadir enlaces', $s === 403 && ($j['error'] ?? '') === 'forbidden', "http=$s");

chk('25. El editor SÍ puede añadir enlaces', $idHttps > 0 && isset($ids['archivo']));

[$s, $j] = addLink($LINKEP, $S_AJEN, $csrf, $TASK, 'https://ejemplo.com/ajeno', $AJAX);
chk('26. El usuario ajeno NO puede añadir enlaces', $s === 403, "http=$s");

[$s, , $htmlL] = http_request($DRWEP, ['sessionId' => $S_LECT, 'headers' => ['X-Requested-With: fetch']]);
chk('27. El lector SÍ visualiza enlaces y embeds',
    $s === 200 && str_contains($htmlL, 'youtube-nocookie.com/embed/') && str_contains($htmlL, 'Abrir enlace'),
    "http=$s");

// ═════════════════════════════════════════════════════════════
section('28-30 · ELIMINACIÓN (incluye regresión de archivos)');

$delLink = $ids['link_http'] ?? 0;
[$s, , $b] = http_request($DELEP, ['sessionId' => $S_EDIT, 'headers' => $AJAX,
    'post' => ['csrf' => $csrf, 'attachment_id' => $delLink]]);
$j = json_decode($b, true);
$gone = (int) $conn->query("SELECT COUNT(*) FROM task_attachments WHERE id=$delLink")->fetch_row()[0] === 0;
chk('28. Eliminar un enlace borra la fila y no toca disco',
    ($j['ok'] ?? false) === true && $gone && ($j['is_external'] ?? false) === true
    && ($j['file_existed'] ?? true) === false,
    'is_external=' . var_export($j['is_external'] ?? null, true));

$delEmbed = 0;
foreach ($ids as $k => $v) { if (str_starts_with($k, 'yt_') && $k !== 'yt_falso' && $k !== 'yt_idmalo') { $delEmbed = $v; break; } }
[$s, , $b] = http_request($DELEP, ['sessionId' => $S_EDIT, 'headers' => $AJAX,
    'post' => ['csrf' => $csrf, 'attachment_id' => $delEmbed]]);
$j = json_decode($b, true);
$gone = (int) $conn->query("SELECT COUNT(*) FROM task_attachments WHERE id=$delEmbed")->fetch_row()[0] === 0;
chk('29. Eliminar un embed borra la fila', ($j['ok'] ?? false) === true && $gone, "id=$delEmbed");

// REGRESIÓN: eliminar un archivo físico debe seguir borrando el archivo
$spBefore = (string) $conn->query("SELECT stored_path FROM task_attachments WHERE id=" . (int) $ids['archivo'])->fetch_row()[0];
$existiaAntes = attach_absolute_path($spBefore) !== null;
[$s, , $b] = http_request($DELEP, ['sessionId' => $S_EDIT, 'headers' => $AJAX,
    'post' => ['csrf' => $csrf, 'attachment_id' => $ids['archivo']]]);
$j = json_decode($b, true);
$rowGone  = (int) $conn->query("SELECT COUNT(*) FROM task_attachments WHERE id=" . (int) $ids['archivo'])->fetch_row()[0] === 0;
$fileGone = attach_absolute_path($spBefore) === null;
chk('30. REGRESIÓN: eliminar archivo físico sigue borrando fila y archivo',
    ($j['ok'] ?? false) === true && $rowGone && $fileGone && $existiaAntes
    && ($j['is_external'] ?? true) === false && ($j['file_existed'] ?? false) === true);
unset($ids['archivo']);

// ═════════════════════════════════════════════════════════════
section('31-38 · RENDERIZADO, CLIENTE Y GARANTÍAS');

// XSS: se añade un enlace con payload en la ruta
[$s, $j] = addLink($LINKEP, $S_EDIT, $csrf, $TASK, 'https://ejemplo.com/"><img src=x onerror=alert(1)>', $AJAX);
$xssId = ($j['ok'] ?? false) ? (int) $j['attachment']['id'] : 0;
if ($xssId) $ids['xss'] = $xssId;
[$s, , $html] = http_request($DRWEP, ['sessionId' => $S_EDIT, 'headers' => ['X-Requested-With: fetch']]);
chk('31. XSS en la URL queda escapado',
    $xssId > 0 && !str_contains($html, '"><img src=x onerror=alert(1)>')
    && (str_contains($html, '&quot;&gt;&lt;img') || str_contains($html, '&lt;img src=x')),
    'id=' . $xssId);

chk('32. Los enlaces llevan rel seguro y target en blanco',
    str_contains($html, 'rel="noopener noreferrer nofollow"') && str_contains($html, 'target="_blank"'));

chk('33. El iframe es responsive (contenedor 16:9)',
    str_contains($CSS, '.fyc-attach-embed') && str_contains($CSS, 'padding-top: 56.25%')
    && str_contains($CSS, 'position: absolute'));

chk('33b. El iframe usa lazy, allowfullscreen y referrerpolicy',
    str_contains($DRW, 'loading="lazy"') && str_contains($DRW, 'allowfullscreen')
    && str_contains($DRW, 'referrerpolicy="strict-origin-when-cross-origin"'));

chk('34. El formulario de enlace NO se muestra al lector',
    !str_contains($htmlL, 'drawer_attach_url') && !str_contains($htmlL, 'attach-add-link'));

chk('35. Doble envío bloqueado en el cliente',
    str_contains($JS, 'function attachAddLink') && str_contains($JS, 'if (attachBusy) return;'));

chk('36. Refresco solo del drawer, sin recarga total',
    preg_match('/attachAddLink[\s\S]{0,3000}loadDrawer\(ctx\.taskId\)/', $JS) === 1
    && !preg_match('/location\.reload\(\)/', $JS));

// 37. La migración conservó filas previas: se comprueba que la tabla
//     admite ambos modelos a la vez en este mismo momento.
$mix = $conn->query("SELECT
        SUM(stored_path IS NOT NULL AND external_url IS NULL) AS archivos,
        SUM(stored_path IS NULL AND external_url IS NOT NULL) AS enlaces,
        SUM(stored_path IS NOT NULL AND external_url IS NOT NULL) AS invalidos,
        SUM(stored_path IS NULL AND external_url IS NULL) AS vacios
     FROM task_attachments")->fetch_assoc();
chk('37. Coexisten archivos y enlaces sin filas inválidas',
    (int) $mix['invalidos'] === 0 && (int) $mix['vacios'] === 0 && (int) $mix['enlaces'] > 0,
    'archivos=' . (int) $mix['archivos'] . ' enlaces=' . (int) $mix['enlaces']
    . ' invalidos=' . (int) $mix['invalidos'] . ' vacios=' . (int) $mix['vacios']);

// 38. Ninguna petición HTTP saliente
chk('38. El endpoint no hace peticiones hacia la URL externa',
    !preg_match('/\b(curl_init|file_get_contents\s*\(\s*\$|fopen\s*\(\s*\$url|get_headers|fsockopen|stream_context_create)/', $LNK),
    'sin curl, file_get_contents remoto ni get_headers');

chk('38b. El contrato de fuente se valida también en PHP',
    str_contains($LNK, 'attach_validate_source')
    && str_contains(file_get_contents(__DIR__ . '/../public/_attachments.php'), 'function attach_validate_source'));

// ═════════════════════════════════════════════════════════════
section('LIMPIEZA');

$paths = [];
foreach ($conn->query("SELECT a.stored_path FROM task_attachments a
                       JOIN tasks t ON t.id=a.task_id JOIN boards b ON b.id=t.board_id
                       WHERE b.nombre LIKE '" . QA_TAG . "%' AND a.stored_path IS NOT NULL")->fetch_all(MYSQLI_ASSOC) as $r) {
    $paths[] = (string) $r['stored_path'];
}
$post = cleanup($conn);
printf("  eliminados: %d tableros, %d usuarios, %d archivos\n", $post['boards'], $post['users'], $post['files']);

foreach ([$S_EDIT, $S_LECT, $S_AJEN] as $sid) @unlink(SESSION_DIR . '/sess_' . $sid);
foreach (glob($FIX . '/*') ?: [] as $p) @unlink($p);
@rmdir($FIX);

$orph = 0;
foreach ($paths as $p) if (attach_absolute_path($p) !== null) $orph++;
chk('LIMPIEZA · sin archivos huérfanos', $orph === 0, "huérfanos=$orph");

$after = [];
foreach (array_keys($base) as $t) $after[$t] = (int) $conn->query("SELECT COUNT(*) FROM `$t`")->fetch_row()[0];
foreach ($base as $t => $n) chk("LIMPIEZA · filas en $t", $after[$t] === $n, "antes=$n después={$after[$t]}");

$md = attach_storage_root() . '/' . date('Y') . '/' . date('m');
if (is_dir($md) && count(glob($md . '/*') ?: []) === 0) { @rmdir($md); @rmdir(dirname($md)); }
$rest = glob(attach_storage_root() . '/*') ?: [];
$solo = true;
foreach ($rest as $r) if (!in_array(basename($r), ['.gitkeep', '.htaccess'], true)) { $solo = false; break; }
chk('LIMPIEZA · storage solo con .gitkeep y .htaccess', $solo);

echo "\n" . str_repeat('═', 78) . "\n";
printf(" RESULTADO: %d correctas, %d fallidas\n", $PASS, $FAIL);
echo str_repeat('═', 78) . "\n";
exit($FAIL === 0 ? 0 : 1);
