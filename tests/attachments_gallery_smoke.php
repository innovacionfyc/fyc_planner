<?php
/**
 * tests/attachments_gallery_smoke.php
 *
 * Pruebas de la Fase E: galería, visor de imagen, reproductores,
 * accesibilidad, estados de error y fallbacks.
 *
 * Ejecutar SOLO en local:
 *   php tests/attachments_gallery_smoke.php
 *
 * Las comprobaciones de comportamiento del navegador (Escape, foco,
 * bloqueo de scroll) se verifican sobre el contrato del código, y se
 * confirman aparte en la prueba manual con un navegador real.
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

const QA_TAG      = 'QA ATTACH GALLERY 2026-07-31';
const BASE_URL    = 'http://localhost/fyc_planner/public';
const SESSION_DIR = 'C:/laragon/tmp';

$FIX = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'fyc_gallery_fx';

$PASS = 0; $FAIL = 0;
function ok(string $n, string $d = ''): void { global $PASS; $PASS++; printf("  [OK]    %-58s %s\n", $n, $d); }
function ko(string $n, string $d = ''): void { global $FAIL; $FAIL++; printf("  [FALLO] %-58s %s\n", $n, $d); }
function chk(string $n, bool $c, string $d = ''): void { $c ? ok($n, $d) : ko($n, $d); }
function section(string $t): void { echo "\n" . str_repeat('─', 80) . "\n " . $t . "\n" . str_repeat('─', 80) . "\n"; }

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
                           WHERE b.nombre LIKE '" . QA_TAG . "%' AND a.stored_path IS NOT NULL")->fetch_all(MYSQLI_ASSOC) as $r) {
        if (attach_delete_file((string) $r['stored_path'])) $n++;
    }
    $conn->query("DELETE FROM boards WHERE nombre LIKE '" . QA_TAG . "%'");
    $b = $conn->affected_rows;
    $conn->query("DELETE FROM users WHERE email LIKE 'qa.gallery.%@local.test'");
    return ['files' => $n, 'boards' => $b, 'users' => $conn->affected_rows];
}

echo "\n════════════════════════════════════════════════════════════════════════════════\n";
echo " PRUEBAS FASE E — GALERÍA, VISOR Y EXPERIENCIA VISUAL\n";
echo "════════════════════════════════════════════════════════════════════════════════\n";

$DRW = (string) file_get_contents(__DIR__ . '/../public/tasks/drawer.php');
$JS  = (string) file_get_contents(__DIR__ . '/../public/assets/board-view.js');
$CSS = (string) file_get_contents(__DIR__ . '/../public/assets/theme.css');

section('PREPARACIÓN');
$pre = cleanup($conn);
printf("  restos previos: %d tableros, %d usuarios, %d archivos\n", $pre['boards'], $pre['users'], $pre['files']);

$base = [];
foreach (['boards', 'columns', 'tasks', 'users', 'task_attachments'] as $t) {
    $base[$t] = (int) $conn->query("SELECT COUNT(*) FROM `$t`")->fetch_row()[0];
}

$csrf  = bin2hex(random_bytes(32));
$email = 'qa.gallery.' . bin2hex(random_bytes(4)) . '@local.test';
$hash  = password_hash(bin2hex(random_bytes(12)), PASSWORD_DEFAULT);
$st = $conn->prepare("INSERT INTO users (nombre,email,pass_hash,estado,rol,is_admin,activo) VALUES ('QA Gallery',?,?, 'aprobado','user',0,1)");
$st->bind_param('ss', $email, $hash); $st->execute(); $U_AJENO = (int) $conn->insert_id; $st->close();

$U_PROP = 2; $U_EDIT = 3; $U_LECT = 4;
$st = $conn->prepare("INSERT INTO boards (nombre,color_hex,owner_user_id,team_id) VALUES (?, '#d32f57', ?, NULL)");
$bn = QA_TAG; $st->bind_param('si', $bn, $U_PROP); $st->execute(); $BOARD = (int) $conn->insert_id; $st->close();
$st = $conn->prepare("INSERT INTO board_members (board_id,user_id,rol) VALUES (?,?,?)");
foreach ([[$U_PROP,'propietario'],[$U_EDIT,'editor'],[$U_LECT,'lector']] as [$u,$r]) { $st->bind_param('iis',$BOARD,$u,$r); $st->execute(); }
$st->close();
$st = $conn->prepare("INSERT INTO `columns` (board_id,nombre,orden,is_done) VALUES (?, 'QA', 1, 0)");
$st->bind_param('i', $BOARD); $st->execute(); $COL = (int) $conn->insert_id; $st->close();
$st = $conn->prepare("INSERT INTO tasks (board_id,column_id,titulo,prioridad) VALUES (?,?, 'QA Galeria', 'med')");
$st->bind_param('ii', $BOARD, $COL); $st->execute(); $TASK = (int) $conn->insert_id; $st->close();

// Segunda tarea vacía, para el estado vacío
$st = $conn->prepare("INSERT INTO tasks (board_id,column_id,titulo,prioridad) VALUES (?,?, 'QA Vacia', 'med')");
$st->bind_param('ii', $BOARD, $COL); $st->execute(); $TASK_VACIA = (int) $conn->insert_id; $st->close();

$S_EDIT = make_session($U_EDIT, $csrf);
$S_LECT = make_session($U_LECT, $csrf);

if (!is_dir($FIX)) mkdir($FIX, 0775, true);
$im = imagecreatetruecolor(80, 60);
imagefill($im, 0, 0, imagecolorallocate($im, 210, 47, 87));
imagejpeg($im, $FIX . '/foto.jpg', 85); imagedestroy($im);
$pcm = str_repeat("\x00\x00", 8000);
file_put_contents($FIX . '/son.wav', 'RIFF' . pack('V', 36 + strlen($pcm)) . 'WAVEfmt ' . pack('V', 16)
    . pack('v', 1) . pack('v', 1) . pack('V', 8000) . pack('V', 16000)
    . pack('v', 2) . pack('v', 16) . 'data' . pack('V', strlen($pcm)) . $pcm);
file_put_contents($FIX . '/clip.mp4', "\x00\x00\x00\x20ftypisom\x00\x00\x02\x00isomiso2avc1mp41\x00\x00\x00\x08free" . str_repeat("\x00", 512));

$UPEP   = BASE_URL . '/tasks/attachment_upload.php';
$LNKEP  = BASE_URL . '/tasks/attachment_link.php';
$DELEP  = BASE_URL . '/tasks/attachment_delete.php';
$DRWEP  = BASE_URL . '/tasks/drawer.php?id=' . $TASK;
$AJAX   = ['X-Requested-With: fetch', 'Accept: application/json'];

$NOMBRE_LARGO = 'informe trimestral consolidado de resultados del area comercial 2026 revision final aprobada.jpg';
$ids = [];

// Subidas
foreach ([['foto.jpg', $NOMBRE_LARGO], ['son.wav', 'audio de prueba.wav'], ['clip.mp4', 'clip de prueba.mp4']] as [$f, $n]) {
    [$s, , $b] = http_request($UPEP, ['sessionId' => $S_EDIT, 'headers' => $AJAX,
        'post' => ['csrf' => $csrf, 'task_id' => $TASK],
        'files' => [['path' => $FIX . '/' . $f, 'name' => $n]]]);
    $j = json_decode($b, true);
    if (($j['ok'] ?? false) === true) $ids[$f] = (int) $j['attachments'][0]['id'];
}
// XSS en el nombre
[$s, , $b] = http_request($UPEP, ['sessionId' => $S_EDIT, 'headers' => $AJAX,
    'post' => ['csrf' => $csrf, 'task_id' => $TASK],
    'files' => [['path' => $FIX . '/foto.jpg', 'name' => '<img src=x onerror=alert(1)>.jpg']]]);
$j = json_decode($b, true);
if (($j['ok'] ?? false) === true) $ids['xss'] = (int) $j['attachments'][0]['id'];

// Enlaces
foreach ([
    ['yt',   'https://www.youtube.com/watch?v=dQw4w9WgXcQ'],
    ['vm',   'https://vimeo.com/123456789'],
    ['link', 'https://ejemplo.com/documento'],
] as [$k, $u]) {
    [$s, , $b] = http_request($LNKEP, ['sessionId' => $S_EDIT, 'headers' => $AJAX,
        'post' => ['csrf' => $csrf, 'task_id' => $TASK, 'url' => $u]]);
    $j = json_decode($b, true);
    if (($j['ok'] ?? false) === true) $ids[$k] = (int) $j['attachment']['id'];
}

printf("  board=%d task=%d | adjuntos creados: %d\n", $BOARD, $TASK, count($ids));

[$s, , $HTML]  = http_request($DRWEP, ['sessionId' => $S_EDIT, 'headers' => ['X-Requested-With: fetch']]);
[$s2, , $HTMLL] = http_request($DRWEP, ['sessionId' => $S_LECT, 'headers' => ['X-Requested-With: fetch']]);

// ═════════════════════════════════════════════════════════════
section('1-4 · GALERÍA DE IMÁGENES');

chk('1. La imagen usa loading="lazy"',
    preg_match('/<img[^>]+data-action="attach-img"[^>]*loading="lazy"|<img[^>]+loading="lazy"[^>]*data-action="attach-img"/', $HTML) === 1);
chk('2. La imagen se sirve por attachment.php',
    preg_match('/<img[^>]+src="[^"]*attachment\.php\?id=\d+"/', $HTML) === 1);
chk('3. El HTML no expone stored_path',
    !str_contains($HTML, 'storage/attachments') && !preg_match('#\b\d{4}/\d{2}/[a-f0-9]{32}\.#', $HTML));
chk('4. La imagen está dentro de un botón que abre el visor',
    str_contains($HTML, 'data-action="attach-open"')
    && str_contains($HTML, 'class="fyc-attach-imgbtn"')
    && preg_match('/<button[^>]+type="button"[^>]+fyc-attach-imgbtn/', $HTML) === 1);

// ═════════════════════════════════════════════════════════════
section('5-14 · VISOR DE IMAGEN');

chk('5. El visor declara role="dialog"', preg_match('/id="fycImgViewer"[^>]*role="dialog"/', $HTML) === 1);
chk('6. El visor declara aria-modal="true"', preg_match('/id="fycImgViewer"[^>]*aria-modal="true"/', $HTML) === 1);
chk('6b. El visor tiene título accesible (aria-labelledby)',
    str_contains($HTML, 'aria-labelledby="fycImgViewerTitle"') && str_contains($HTML, 'id="fycImgViewerTitle"'));
chk('7. Escape cierra el visor',
    preg_match("/addEventListener\('keydown'[\s\S]{0,400}ev\.key !== 'Escape'[\s\S]{0,400}closeImgViewer\(\)/", $JS) === 1);
chk('8. Clic en el fondo cierra',
    str_contains($HTML, 'class="fyc-imgviewer-backdrop" data-action="viewer-close"')
    && str_contains($JS, "closest('[data-action=\"viewer-close\"]')"));
chk('9. Hay botón visible de cierre',
    str_contains($HTML, 'class="fyc-imgviewer-x"') && str_contains($HTML, 'aria-label="Cerrar visor"'));
chk('10. El foco vuelve al elemento que abrió',
    str_contains($JS, 'viewerLastFocus = triggerBtn')
    && str_contains($JS, 'viewerLastFocus.focus()')
    && str_contains($JS, 'document.contains(viewerLastFocus)'));
chk('11. El scroll se bloquea y se restaura',
    str_contains($JS, "document.body.classList.add('fyc-viewer-open')")
    && str_contains($JS, "document.body.classList.remove('fyc-viewer-open')")
    && str_contains($CSS, 'body.fyc-viewer-open') && str_contains($CSS, 'overflow: hidden'));
chk('11b. El foco no se escapa detrás del modal',
    str_contains($JS, "addEventListener('focusin'") && str_contains($JS, 'v.contains(ev.target)'));
chk('12. El visor ofrece descarga con ?download=1',
    str_contains($HTML, 'id="fycImgViewerDl"')
    && str_contains($JS, "dl.getAttribute('href')")
    && preg_match('/href="[^"]*attachment\.php\?id=\d+&(amp;)?download=1"/', $HTML) === 1);
chk('13. El nombre largo se escapa y se conserva completo en title',
    str_contains($HTML, htmlspecialchars($NOMBRE_LARGO, ENT_QUOTES, 'UTF-8')));
chk('14. El XSS del nombre no se ejecuta',
    isset($ids['xss'])
    && !str_contains($HTML, '<img src=x onerror=alert(1)>')
    && str_contains($HTML, '&lt;img src=x onerror=alert(1)&gt;'));
chk('14b. El visor usa textContent, nunca innerHTML, para el nombre',
    str_contains($JS, 'title.textContent = src.getAttribute')
    && !preg_match('/fycImgViewerTitle[\s\S]{0,200}innerHTML/', $JS));

// ═════════════════════════════════════════════════════════════
section('15-19 · IMAGEN ROTA Y AUDIO');

// La miniatura DEBE estilizarse por clase. Si llevara style="…display:block"
// en línea, ese estilo ganaría siempre y la regla .is-broken no podría
// ocultarla: el mensaje de error aparecería junto al icono de imagen rota.
chk('15. Hay fallback visible si la imagen no carga',
    str_contains($HTML, 'fyc-attach-imgfail')
    && str_contains($JS, "btn.classList.add('is-broken')")
    && str_contains($CSS, '.fyc-attach-imgbtn.is-broken .fyc-attach-thumb')
    && str_contains($CSS, '.fyc-attach-imgbtn.is-broken .fyc-attach-imgfail'));
chk('15b. La miniatura se estiliza por clase, no en línea',
    str_contains($HTML, 'class="fyc-attach-thumb"')
    && preg_match('/<img[^>]+data-action="attach-img"[^>]*style=/', $HTML) !== 1,
    'sin style= en la miniatura');
chk('16. El audio usa preload="metadata"', str_contains($HTML, '<audio controls preload="metadata"'));
chk('17. El audio no tiene autoplay',
    preg_match('/<audio[^>]*\bautoplay\b/', $HTML) !== 1);
chk('18. El audio tiene descarga alternativa',
    preg_match('/fyc-attach-audio[\s\S]{0,2200}attachment\.php\?id=\d+&(amp;)?download=1/', $HTML) === 1);
chk('19. El audio muestra mensaje si no se puede reproducir',
    str_contains($HTML, 'Este navegador no puede reproducir este audio')
    && str_contains($JS, "media.classList.add('is-failed')")
    && str_contains($CSS, '.fyc-attach-audio.is-failed'));

// ═════════════════════════════════════════════════════════════
section('20-25 · VIDEO LOCAL');

chk('20. El video tiene controls', str_contains($HTML, '<video controls'));
chk('21. El video usa preload="metadata"', str_contains($HTML, '<video controls preload="metadata"'));
chk('22. El video usa playsinline', str_contains($HTML, 'playsinline'));
chk('23. El video no tiene autoplay', preg_match('/<video[^>]*\bautoplay\b/', $HTML) !== 1);
chk('24. El video muestra fallback si no se puede reproducir',
    str_contains($HTML, 'Este navegador no puede reproducir este video')
    && str_contains($CSS, '.fyc-attach-video.is-failed'));
chk('25. El video tiene descarga alternativa',
    preg_match('/fyc-attach-video[\s\S]{0,2500}attachment\.php\?id=\d+&(amp;)?download=1/', $HTML) === 1);

// ═════════════════════════════════════════════════════════════
section('26-29 · EMBEDS Y ENLACES');

preg_match_all('/<iframe[^>]+src="([^"]*)"/i', $HTML, $mm);
$srcs = $mm[1] ?? [];
$plantilla = true;
foreach ($srcs as $s2b) {
    if (!preg_match('#^https://(www\.youtube-nocookie\.com/embed/[A-Za-z0-9_-]{11}|player\.vimeo\.com/video/\d+)$#', $s2b)) $plantilla = false;
}
chk('26. Los embeds conservan la plantilla segura', count($srcs) >= 2 && $plantilla, 'iframes=' . count($srcs));
chk('26b. Los embeds mantienen 16:9, lazy, allowfullscreen y referrerpolicy',
    str_contains($CSS, 'padding-top: 56.25%')
    && str_contains($HTML, 'loading="lazy"') && str_contains($HTML, 'allowfullscreen')
    && str_contains($HTML, 'referrerpolicy="strict-origin-when-cross-origin"'));
chk('26c. Los embeds ofrecen respaldo para ver en el proveedor',
    str_contains($HTML, 'fyc-attach-embedfail')
    && (str_contains($HTML, 'https://www.youtube.com/watch?v=') || str_contains($HTML, 'https://vimeo.com/')));

$ytRow = $conn->query("SELECT external_url FROM task_attachments WHERE provider='youtube' AND task_id=$TASK LIMIT 1")->fetch_assoc();
chk('27. YouTube: external_url no se usa como src del iframe',
    !in_array((string) ($ytRow['external_url'] ?? '#'), $srcs, true));
$vmRow = $conn->query("SELECT external_url FROM task_attachments WHERE provider='vimeo' AND task_id=$TASK LIMIT 1")->fetch_assoc();
chk('28. Vimeo: external_url no se usa como src del iframe',
    !in_array((string) ($vmRow['external_url'] ?? '#'), $srcs, true));
chk('29. El enlace normal conserva rel seguro',
    str_contains($HTML, 'rel="noopener noreferrer nofollow"') && str_contains($HTML, 'target="_blank"'));

// ═════════════════════════════════════════════════════════════
section('30-37 · PERMISOS, ORGANIZACIÓN Y ESTADOS');

chk('30. El lector NO ve el botón eliminar', !str_contains($HTMLL, 'attach-delete'));
chk('31. El editor SÍ ve el botón eliminar', str_contains($HTML, 'attach-delete'));
chk('32. Modo oscuro: los estilos usan variables de tema',
    str_contains($CSS, '.fyc-attach-badge') && str_contains($CSS, 'var(--bg-surface)')
    && str_contains($CSS, '.fyc-imgviewer-box') && str_contains($CSS, 'var(--border-main)')
    && !preg_match('/\.fyc-imgviewer-box\s*\{[^}]*background:\s*#fff/i', $CSS));
chk('33. La cuadrícula no desborda por contenido propio',
    str_contains($CSS, 'grid-template-columns: repeat(auto-fill, minmax(')
    && str_contains($CSS, '.fyc-attach-media audio') && str_contains($CSS, 'max-width: 100%')
    && str_contains($CSS, '.fyc-attach-video video') && str_contains($CSS, 'max-height: 190px'));
chk('34. Los nombres largos se truncan visualmente',
    str_contains($HTML, 'text-overflow:ellipsis') && str_contains($HTML, 'white-space:nowrap'));
chk('35. El title conserva el nombre completo',
    preg_match('/title="' . preg_quote(htmlspecialchars($NOMBRE_LARGO, ENT_QUOTES, 'UTF-8'), '/') . '"/', $HTML) === 1);
$total = count($ids);
chk('36. El contador refleja el total real', str_contains($HTML, 'Adjuntos (' . $total . ')'), "esperado=$total");

[$sv, , $HTMLV] = http_request(BASE_URL . '/tasks/drawer.php?id=' . $TASK_VACIA,
    ['sessionId' => $S_EDIT, 'headers' => ['X-Requested-With: fetch']]);
chk('37. El estado vacío sigue correcto',
    str_contains($HTMLV, 'Adjuntos (0)') && str_contains($HTMLV, 'Sin adjuntos todavía'));
chk('37b. Hay distintivo de tipo por tarjeta',
    str_contains($HTML, 'fyc-attach-badge') && str_contains($HTML, 'fyc-attach-k-image')
    && str_contains($HTML, 'fyc-attach-k-embed') && str_contains($HTML, 'fyc-attach-k-link'));
chk('37c. Hay estado visual de eliminación en curso',
    str_contains($CSS, '.fyc-attach-card.is-deleting') && str_contains($JS, "classList.add('is-deleting')"));

// ═════════════════════════════════════════════════════════════
section('38-44 · DELEGACIÓN, INTEGRIDAD Y SEGURIDAD DEL VISOR');

chk('38. Los eventos del visor van por delegación en document',
    substr_count($JS, "document.addEventListener('click'") >= 2
    && str_contains($JS, "closest('[data-action=\"attach-open\"]')"));
chk('39. No se añaden scripts inline nuevos al drawer',
    substr_count($DRW, '<script') === 2, 'bloques <script> = ' . substr_count($DRW, '<script'));
$hashWt = trim((string) shell_exec('cd ' . escapeshellarg(dirname(__DIR__)) . ' && git hash-object public/assets/app.css 2>NUL'));
$hashHd = trim((string) shell_exec('cd ' . escapeshellarg(dirname(__DIR__)) . ' && git rev-parse HEAD:public/assets/app.css 2>NUL'));
chk('40. app.css no cambia', $hashWt !== '' && $hashWt === $hashHd, substr($hashWt, 0, 12));
chk('41. Tailwind no se regenera (sin diff en app.css)', $hashWt === $hashHd);
chk('42. Refrescar el drawer no deja el modal huérfano',
    preg_match('/function loadDrawer[\s\S]{0,400}classList\.remove\(.fyc-viewer-open.\)/', $JS) === 1);
chk('43. El visor no abre con un ID arbitrario',
    str_contains($JS, "var src = triggerBtn.querySelector('img')")
    && !preg_match('/openImgViewer[\s\S]{0,600}attachment\.php\?id=.\s*\+/', $JS),
    'la fuente se copia del <img> ya renderizado');
chk('44. No aparecen rutas físicas en el HTML',
    !str_contains($HTML, 'C:/laragon') && !str_contains($HTML, 'storage/attachments')
    && !str_contains($HTML, 'stored_path'));

// ═════════════════════════════════════════════════════════════
section('45-48 · REGRESIÓN DE LAS FASES ANTERIORES');

$audioId = $ids['son.wav'] ?? 0;
[$sr, $hr, $br] = http_request(BASE_URL . '/tasks/attachment.php?id=' . $audioId,
    ['sessionId' => $S_LECT, 'headers' => ['Range: bytes=0-99']]);
chk('45. Range de audio/video sigue funcionando',
    $sr === 206 && strlen($br) === 100 && preg_match('/^Accept-Ranges:\s*bytes/mi', $hr) === 1,
    "http=$sr bytes=" . strlen($br));

$delId = $ids['link'] ?? 0;
[$sd, , $bd] = http_request($DELEP, ['sessionId' => $S_EDIT, 'headers' => $AJAX,
    'post' => ['csrf' => $csrf, 'attachment_id' => $delId]]);
$jd = json_decode($bd, true);
chk('46. La eliminación sigue funcionando',
    ($jd['ok'] ?? false) === true
    && (int) $conn->query("SELECT COUNT(*) FROM task_attachments WHERE id=$delId")->fetch_row()[0] === 0);
unset($ids['link']);

[$su, , $bu] = http_request($UPEP, ['sessionId' => $S_EDIT, 'headers' => $AJAX,
    'post' => ['csrf' => $csrf, 'task_id' => $TASK], 'files' => [$FIX . '/foto.jpg']]);
$ju = json_decode($bu, true);
$newUp = ($ju['ok'] ?? false) ? (int) $ju['attachments'][0]['id'] : 0;
chk('47. Subida (selector, paste y drop) sigue funcionando',
    $newUp > 0 && str_contains($JS, 'function uploadTaskAttachments(fileList, source)')
    && str_contains($JS, "uploadTaskAttachments(renamed, 'paste')")
    && str_contains($JS, "uploadTaskAttachments(validos, 'drop')"),
    "id=$newUp");
if ($newUp) $ids['reup'] = $newUp;

[$sl, , $bl] = http_request($LNKEP, ['sessionId' => $S_EDIT, 'headers' => $AJAX,
    'post' => ['csrf' => $csrf, 'task_id' => $TASK, 'url' => 'https://youtu.be/dQw4w9WgXcQ']]);
$jl = json_decode($bl, true);
chk('48. Enlaces y embeds siguen funcionando',
    ($jl['ok'] ?? false) === true
    && ($jl['attachment']['embed_url'] ?? '') === 'https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ');
if (($jl['attachment']['id'] ?? 0)) $ids['reyt'] = (int) $jl['attachment']['id'];

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

echo "\n" . str_repeat('═', 80) . "\n";
printf(" RESULTADO: %d correctas, %d fallidas\n", $PASS, $FAIL);
echo str_repeat('═', 80) . "\n";
exit($FAIL === 0 ? 0 : 1);
