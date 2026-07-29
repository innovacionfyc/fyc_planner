<?php
/**
 * tests/attachments_paste_drop_smoke.php
 *
 * Pruebas de la Fase C: pegar con Ctrl+V y arrastrar y soltar.
 *
 * Ejecutar SOLO en local:
 *   php tests/attachments_paste_drop_smoke.php
 *
 * Estrategia:
 *   · El comportamiento de pegado y arrastre vive en el cliente, así que se
 *     verifica sobre el código de board-view.js (contratos y salvaguardas).
 *   · Lo que sí toca el servidor (subida real, permisos, límites) se ejercita
 *     por HTTP, igual que en las fases A y B.
 *   · Las comprobaciones de interacción real en navegador se hacen aparte,
 *     en la prueba manual; aquí se validan las reglas que las sustentan.
 *
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

const QA_TAG      = 'QA ATTACH PASTE 2026-07-29';
const BASE_URL    = 'http://localhost/fyc_planner/public';
const SESSION_DIR = 'C:/laragon/tmp';

$FIX = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'fyc_paste_fx';

$PASS = 0; $FAIL = 0;
function ok(string $n, string $d = ''): void { global $PASS; $PASS++; printf("  [OK]    %-56s %s\n", $n, $d); }
function ko(string $n, string $d = ''): void { global $FAIL; $FAIL++; printf("  [FALLO] %-56s %s\n", $n, $d); }
function chk(string $n, bool $c, string $d = ''): void { $c ? ok($n, $d) : ko($n, $d); }
function section(string $t): void { echo "\n" . str_repeat('─', 76) . "\n " . $t . "\n" . str_repeat('─', 76) . "\n"; }

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
    $n = 0;
    foreach ($conn->query("SELECT a.stored_path FROM task_attachments a
                           JOIN tasks t ON t.id=a.task_id JOIN boards b ON b.id=t.board_id
                           WHERE b.nombre LIKE '" . QA_TAG . "%'")->fetch_all(MYSQLI_ASSOC) as $r) {
        if (attach_delete_file((string) $r['stored_path'])) $n++;
    }
    $conn->query("DELETE FROM boards WHERE nombre LIKE '" . QA_TAG . "%'");
    $b = $conn->affected_rows;
    $conn->query("DELETE FROM users WHERE email LIKE 'qa.paste.%@local.test'");
    return ['files' => $n, 'boards' => $b, 'users' => $conn->affected_rows];
}

echo "\n══════════════════════════════════════════════════════════════════════════════\n";
echo " PRUEBAS FASE C — PEGAR (Ctrl+V) Y ARRASTRAR Y SOLTAR\n";
echo "══════════════════════════════════════════════════════════════════════════════\n";

$JS = (string) file_get_contents(__DIR__ . '/../public/assets/board-view.js');
$CSS = (string) file_get_contents(__DIR__ . '/../public/assets/theme.css');
$DRW = (string) file_get_contents(__DIR__ . '/../public/tasks/drawer.php');

section('PREPARACIÓN');
$pre = cleanup($conn);
printf("  restos previos: %d tableros, %d usuarios, %d archivos\n", $pre['boards'], $pre['users'], $pre['files']);

$base = [];
foreach (['boards', 'columns', 'tasks', 'users', 'task_attachments'] as $t) {
    $base[$t] = (int) $conn->query("SELECT COUNT(*) FROM `$t`")->fetch_row()[0];
}

$csrf = bin2hex(random_bytes(32));
$email = 'qa.paste.' . bin2hex(random_bytes(4)) . '@local.test';
$hash  = password_hash(bin2hex(random_bytes(12)), PASSWORD_DEFAULT);
$st = $conn->prepare("INSERT INTO users (nombre,email,pass_hash,estado,rol,is_admin,activo) VALUES ('QA Paste',?,?, 'aprobado','user',0,1)");
$st->bind_param('ss', $email, $hash); $st->execute(); $U_AJENO = (int) $conn->insert_id; $st->close();

$U_PROP = 2; $U_EDIT = 3; $U_LECT = 4;

$st = $conn->prepare("INSERT INTO boards (nombre,color_hex,owner_user_id,team_id) VALUES (?, '#d32f57', ?, NULL)");
$bn = QA_TAG; $st->bind_param('si', $bn, $U_PROP); $st->execute(); $BOARD = (int) $conn->insert_id; $st->close();
$st = $conn->prepare("INSERT INTO board_members (board_id,user_id,rol) VALUES (?,?,?)");
foreach ([[$U_PROP,'propietario'],[$U_EDIT,'editor'],[$U_LECT,'lector']] as [$u,$r]) { $st->bind_param('iis',$BOARD,$u,$r); $st->execute(); }
$st->close();
$st = $conn->prepare("INSERT INTO `columns` (board_id,nombre,orden,is_done) VALUES (?, 'QA', 1, 0)");
$st->bind_param('i', $BOARD); $st->execute(); $COL = (int) $conn->insert_id; $st->close();
$st = $conn->prepare("INSERT INTO tasks (board_id,column_id,titulo,prioridad) VALUES (?,?, 'QA Paste Drop', 'med')");
$st->bind_param('ii', $BOARD, $COL); $st->execute(); $TASK = (int) $conn->insert_id; $st->close();

$S_EDIT = make_session($U_EDIT, $csrf);
$S_LECT = make_session($U_LECT, $csrf);

if (!is_dir($FIX)) mkdir($FIX, 0775, true);
$im = imagecreatetruecolor(50, 40);
imagefill($im, 0, 0, imagecolorallocate($im, 210, 47, 87));
imagejpeg($im, $FIX . '/captura.jpg', 85); imagedestroy($im);
file_put_contents($FIX . '/prohibido.svg', '<svg xmlns="http://www.w3.org/2000/svg"></svg>');
$fh = fopen($FIX . '/enorme.jpg', 'wb');
fwrite($fh, file_get_contents($FIX . '/captura.jpg'));
fwrite($fh, str_repeat("\x00", 11 * 1024 * 1024));
fclose($fh);

printf("  board=%d task=%d | editor=%d lector=%d ajeno=%d\n", $BOARD, $TASK, $U_EDIT, $U_LECT, $U_AJENO);

$UP   = BASE_URL . '/tasks/attachment_upload.php';
$AJAX = ['X-Requested-With: fetch', 'Accept: application/json'];

// ═════════════════════════════════════════════════════════════
section('1-6 · PEGADO: CUÁNDO SE INTERCEPTA Y CUÁNDO NO');

chk('1. Existe un listener de paste delegado en document',
    str_contains($JS, "document.addEventListener('paste'"));

chk('2-4. El pegado NO se intercepta en campos editables',
    str_contains($JS, 'attachIsEditableTarget')
    && str_contains($JS, "el.closest('input, textarea, select')")
    && str_contains($JS, 'contenteditable')
    && preg_match('/if \(attachIsEditableTarget\(ev\.target\).*?\) return;/s', $JS) === 1,
    'input, textarea, select y contenteditable protegidos');

chk('2b. La comprobación cubre también document.activeElement',
    str_contains($JS, 'attachIsEditableTarget(document.activeElement)'));

chk('5. Sin drawer abierto el pegado se ignora',
    str_contains($JS, 'if (!attachCanWriteHere()) return;')
    && str_contains($JS, "document.getElementById('drawer_attach_input')"));

chk('6. Como lector el pegado se ignora (no existe el input)',
    str_contains($JS, 'function attachCanWriteHere')
    && !str_contains($DRW, 'drawer_attach_input" ')  // el input solo se pinta con canWrite
    && preg_match('/canWrite.*?drawer_attach_input/s', $DRW) === 1);

chk('6b. Solo se toman del portapapeles elementos de tipo imagen',
    str_contains($JS, "it.kind !== 'file'")
    && str_contains($JS, "indexOf('image/') !== 0"));

chk('6c. Si no hay imágenes, no se llama a preventDefault (pegado normal)',
    preg_match('/if \(!imgs\.length\) return;.*?ev\.preventDefault\(\);/s', $JS) === 1);

// ═════════════════════════════════════════════════════════════
section('7-10 · SUBIDA POR PEGADO Y NOMBRES DE CAPTURA');

// 7. El editor sube una imagen "pegada" (mismo endpoint y contrato)
[$s, $h, $b] = http_request($UP, ['sessionId' => $S_EDIT, 'headers' => $AJAX,
    'post' => ['csrf' => $csrf, 'task_id' => $TASK],
    'files' => [['path' => $FIX . '/captura.jpg', 'name' => 'captura-2026-07-29-090500.jpg']]]);
$j = json_decode($b, true);
$pasteId = ($j['ok'] ?? false) ? (int) $j['attachments'][0]['id'] : 0;
chk('7. Imagen pegada se sube por el flujo real', $pasteId > 0, "http=$s");

chk('8. Nombre genérico se sustituye por captura-FECHA',
    str_contains($JS, 'function attachScreenshotName')
    && str_contains($JS, "'captura-'")
    && str_contains($JS, 'function attachNameLooksGeneric')
    && preg_match('/\^\(image\|imagen\|blob\|captura\|screenshot\|unknown\)/', $JS) === 1);

chk('9. Un nombre válido del navegador se conserva',
    preg_match('/if \(!attachNameLooksGeneric\(f\.name\)\) return f;/', $JS) === 1);

chk('9b. El nombre generado usa la hora local ya configurada',
    str_contains($JS, 'new Date()') && str_contains($JS, 'getFullYear()'));

chk('10. Más de 5 imágenes pegadas se rechazan con aviso',
    str_contains($JS, 'imgs.length > ATTACH_MAX_FILES')
    && str_contains($JS, 'Solo puedes pegar hasta'));

// Y el backend también lo rechaza
$seis = array_fill(0, 6, $FIX . '/captura.jpg');
[$s6, , $b6] = http_request($UP, ['sessionId' => $S_EDIT, 'headers' => $AJAX,
    'post' => ['csrf' => $csrf, 'task_id' => $TASK], 'files' => $seis]);
$j6 = json_decode($b6, true);
chk('10b. El backend rechaza igualmente más de 5', ($j6['error'] ?? '') === 'too_many_files', "http=$s6");

// ═════════════════════════════════════════════════════════════
section('11-18 · ARRASTRAR Y SOLTAR');

chk('11. dragenter activa el estado visual',
    str_contains($JS, "document.addEventListener('dragenter'")
    && str_contains($JS, 'attachDropSetActive(true)'));

chk('12. dragleave lo limpia con contador de profundidad',
    str_contains($JS, "document.addEventListener('dragleave'")
    && str_contains($JS, 'var dragDepth = 0')
    && str_contains($JS, 'dragDepth++')
    && str_contains($JS, 'dragDepth--')
    && str_contains($JS, 'if (dragDepth <= 0) attachDropSetActive(false)'));

chk('12b. Existen los 4 eventos requeridos',
    str_contains($JS, "addEventListener('dragenter'")
    && str_contains($JS, "addEventListener('dragover'")
    && str_contains($JS, "addEventListener('dragleave'")
    && str_contains($JS, "addEventListener('drop'"));

chk('12c. dragover hace preventDefault (si no, drop no dispara)',
    preg_match("/addEventListener\('dragover'.*?ev\.preventDefault\(\);/s", $JS) === 1);

// 13. drop válido sube (mismo endpoint)
[$s, $h, $b] = http_request($UP, ['sessionId' => $S_EDIT, 'headers' => $AJAX,
    'post' => ['csrf' => $csrf, 'task_id' => $TASK], 'files' => [$FIX . '/captura.jpg']]);
$j = json_decode($b, true);
$dropId = ($j['ok'] ?? false) ? (int) $j['attachments'][0]['id'] : 0;
chk('13. Archivo soltado se sube por el flujo real', $dropId > 0, "http=$s");

chk('14-15. Texto y URLs arrastrados NO se procesan',
    str_contains($JS, 'function attachDragHasFiles')
    && str_contains($JS, "dt.types[i] === 'Files'")
    && preg_match("/addEventListener\('drop'.*?if \(!attachDragHasFiles\(ev\)\) return;/s", $JS) === 1);

chk('16. Las carpetas se rechazan con mensaje',
    str_contains($JS, 'pareceCarpeta')
    && str_contains($JS, 'No se pueden adjuntar carpetas'));

// 17. archivo no permitido
[$s, , $b] = http_request($UP, ['sessionId' => $S_EDIT, 'headers' => $AJAX,
    'post' => ['csrf' => $csrf, 'task_id' => $TASK], 'files' => [$FIX . '/prohibido.svg']]);
$j = json_decode($b, true);
chk('17. Archivo no permitido devuelve motivo visible',
    $s === 422 && !empty($j['rejected'][0]['error']),
    'motivo=' . ($j['rejected'][0]['error'] ?? '-'));

// 18. archivo demasiado grande
[$s, , $b] = http_request($UP, ['sessionId' => $S_EDIT, 'headers' => $AJAX,
    'post' => ['csrf' => $csrf, 'task_id' => $TASK], 'files' => [$FIX . '/enorme.jpg']]);
$j = json_decode($b, true);
chk('18. Archivo demasiado grande devuelve motivo visible',
    $s === 422 && str_contains((string) ($j['rejected'][0]['error'] ?? ''), 'límite'),
    'motivo=' . ($j['rejected'][0]['error'] ?? '-'));

chk('18b. El cliente también avisa del tamaño antes de enviar',
    str_contains($JS, 'supera ') && str_contains($JS, 'ATTACH_LIMITS[kind].bytes'));

// ═════════════════════════════════════════════════════════════
section('19-25 · FLUJO COMPARTIDO, PERMISOS Y ESTADOS');

chk('19. Existe una única función de subida compartida',
    str_contains($JS, 'function uploadTaskAttachments(fileList, source)'));

$llamadas = preg_match_all('/uploadTaskAttachments\(/', $JS);
chk('19b. Los tres orígenes la reutilizan (selector, paste y drop)',
    str_contains($JS, "uploadTaskAttachments(input.files, 'picker')")
    && str_contains($JS, "uploadTaskAttachments(renamed, 'paste')")
    && str_contains($JS, "uploadTaskAttachments(validos, 'drop')"),
    "llamadas totales=$llamadas");

chk('19c. Un solo fetch de subida en todo el archivo (sin duplicar)',
    preg_match_all("#fetch\('\.\./tasks/attachment_upload\.php'#", $JS) === 1);

chk('20. Doble envío bloqueado con attachBusy',
    str_contains($JS, 'if (attachBusy) {')
    && str_contains($JS, 'Espera a que termine la subida en curso'));

chk('21. El estado visual SIEMPRE se limpia al terminar',
    preg_match('/Equivalente a finally.*?attachSetBusy\(false\);.*?attachDropSetActive\(false\);/s', $JS) === 1);

// 22-23. Lector
[$s, $h, $htmlL] = http_request(BASE_URL . '/tasks/drawer.php?id=' . $TASK,
    ['sessionId' => $S_LECT, 'headers' => ['X-Requested-With: fetch']]);
chk('22. El lector no recibe zona de arrastre ni pista de pegado',
    $s === 200
    && !str_contains($htmlL, 'drawer_attach_input')
    && !str_contains($htmlL, 'fyc-attach-hint')
    && !str_contains($htmlL, 'fyc-attach-dropmsg'),
    "http=$s");

[$s, , $b] = http_request($UP, ['sessionId' => $S_LECT, 'headers' => $AJAX,
    'post' => ['csrf' => $csrf, 'task_id' => $TASK], 'files' => [$FIX . '/captura.jpg']]);
$j = json_decode($b, true);
chk('23. El lector no puede subir aunque dispare el evento a mano',
    $s === 403 && ($j['error'] ?? '') === 'forbidden', "http=$s");

chk('24. Refresco solo del drawer, sin recarga total',
    str_contains($JS, 'loadDrawer(ctx.taskId)')
    && !preg_match('/location\.reload\(\)/', $JS));

// 25. app.css intacto.
// Se compara el hash del contenido, no la salida de git: en Windows, git
// escribe en stderr un aviso sobre finales de línea que no es una diferencia.
$repo   = escapeshellarg(dirname(__DIR__));
$hashWt = trim((string) shell_exec('cd ' . $repo . ' && git hash-object public/assets/app.css 2>' . (DIRECTORY_SEPARATOR === '\\' ? 'NUL' : '/dev/null')));
$hashHd = trim((string) shell_exec('cd ' . $repo . ' && git rev-parse HEAD:public/assets/app.css 2>' . (DIRECTORY_SEPARATOR === '\\' ? 'NUL' : '/dev/null')));
chk('25. app.css no se modificó',
    $hashWt !== '' && $hashWt === $hashHd,
    'hash worktree=' . substr($hashWt, 0, 12) . ' HEAD=' . substr($hashHd, 0, 12));

// Estados visuales en CSS
chk('E1. Estados visuales de arrastre definidos en theme.css',
    str_contains($CSS, '.fyc-attach-dropping')
    && str_contains($CSS, '.fyc-attach-dropmsg')
    && str_contains($CSS, '.fyc-attach-hint'));

chk('E2. Los estados usan variables de tema (claro y oscuro)',
    str_contains($CSS, 'var(--fyc-red)') && str_contains($CSS, 'var(--text-ghost)'));

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

foreach ([$S_EDIT, $S_LECT] as $sid) @unlink(SESSION_DIR . '/sess_' . $sid);
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

echo "\n" . str_repeat('═', 76) . "\n";
printf(" RESULTADO: %d correctas, %d fallidas\n", $PASS, $FAIL);
echo str_repeat('═', 76) . "\n";
exit($FAIL === 0 ? 0 : 1);
