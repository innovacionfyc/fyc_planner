<?php
/**
 * tests/drawer_attachments_smoke.php
 *
 * Bloque de adjuntos compacto y ayuda bajo demanda (Fase F8, bloque F8.3).
 *
 * Ejecutar SOLO en local:
 *   php tests/drawer_attachments_smoke.php
 *
 * Vigila que la ayuda viva en un <details> cerrado por defecto, que los
 * errores queden FUERA de él, que el estado vacío no vuelva a inflarse y —lo
 * más importante— que todas las cifras visibles se sigan derivando de las
 * constantes en lugar de estar escritas a mano.
 *
 * Parte de las pruebas necesitan Apache y MySQL. No dejan residuos.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Este script solo puede ejecutarse desde CLI.\n");
}

$ROOT = dirname(__DIR__);
$DRW  = $ROOT . '/public/tasks/drawer.php';
$CSS  = $ROOT . '/public/assets/theme.css';
$JS   = $ROOT . '/public/assets/board-view.js';

require_once $ROOT . '/config/bootstrap.php';
require_once $ROOT . '/public/_attachments.php';

const BASE_URL    = 'http://localhost/fyc_planner/public';
const SESSION_DIR = 'C:/laragon/tmp';
const QA_TAG      = 'QA F83 SMOKE';

$PASS = 0;
$FAIL = 0;
$PEND = 0;

function ok(string $n, string $d = ''): void
{
    global $PASS;
    $PASS++;
    printf("  [OK]    %-58s %s\n", $n, $d);
}

function ko(string $n, string $d = ''): void
{
    global $FAIL;
    $FAIL++;
    printf("  [FALLO] %-58s %s\n", $n, $d);
}

function pend(string $n, string $d = ''): void
{
    global $PEND;
    $PEND++;
    printf("  [PEND.] %-58s %s\n", $n, $d);
}

function chk(string $n, bool $c, string $d = ''): void
{
    $c ? ok($n, $d) : ko($n, $d);
}

function section(string $t): void
{
    echo "\n" . str_repeat('─', 78) . "\n " . $t . "\n" . str_repeat('─', 78) . "\n";
}

/** Bloque de una regla CSS por selector exacto. '' si no existe. */
function regla(string $css, string $sel): string
{
    $re = '/(?<![\w.\[-])' . preg_quote($sel, '/') . '\s*\{([^}]*)\}/s';
    return preg_match($re, $css, $m) ? $m[1] : '';
}

/** Texto sin etiquetas ni espacios repetidos, para comparar frases. */
function plano(string $html): string
{
    return trim((string) preg_replace('/\s+/u', ' ',
        html_entity_decode(strip_tags($html), ENT_QUOTES, 'UTF-8')));
}

echo "\n";
echo "══════════════════════════════════════════════════════════════════════════════\n";
echo " ADJUNTOS COMPACTOS Y AYUDA BAJO DEMANDA (bloque F8.3)\n";
echo "══════════════════════════════════════════════════════════════════════════════\n";
echo " PHP   : " . PHP_VERSION . "\n";
echo " Fecha : " . date('Y-m-d H:i:s') . "\n";

$leer = fn(string $p): string => str_replace("\r\n", "\n", (string) file_get_contents($p));
$drw = $leer($DRW);
$css = $leer($CSS);
$js  = $leer($JS);

// Aísla el bloque de adjuntos del resto del cajón.
$bloque = '';
if (preg_match('/<!-- ADJUNTOS -->(.*?)<!-- COMENTARIOS -->/s', $drw, $m)) {
    $bloque = $m[1];
}

// ═════════════════════════════════════════════════════════════
section('1-7 · AYUDA BAJO DEMANDA');

chk('1. El bloque de adjuntos se aísla', $bloque !== '');

chk('2. Existe un <details> para la ayuda',
    preg_match('/<details class="fyc-attach-help">/', $bloque) === 1);

chk('3. Con su <summary>',
    preg_match('/<details class="fyc-attach-help">\s*<summary>([^<]+)<\/summary>/s', $bloque, $s) === 1,
    trim($s[1] ?? ''));

// El atributo open es lo único que lo abriría de partida.
chk('4. Cerrado por defecto',
    !preg_match('/<details[^>]*\bopen\b/', $bloque),
    'sin atributo open');

chk('5. No necesita JavaScript',
    !preg_match('/fyc-attach-help/', $js),
    'board-view.js no conoce el desplegable');

// El foco debe verse: es el único indicio para quien navega con teclado.
chk('6. El foco del summary es visible',
    preg_match('/\.fyc-attach-help > summary:focus-visible\s*\{[^}]*outline:/s', $css) === 1);

chk('7. El triángulo gira al abrir',
    preg_match('/\.fyc-attach-help\[open\] > summary::before\s*\{[^}]*transform:\s*rotate/s', $css) === 1,
    'indicador visual discreto');

// ═════════════════════════════════════════════════════════════
section('8-13 · LAS CIFRAS SIGUEN SALIENDO DE LAS CONSTANTES');

chk('8. El máximo por archivo se calcula, no se escribe',
    str_contains($bloque, 'ATTACH_MAX_FILE_BYTES / 1048576')
    && str_contains($bloque, '<?= $maxArchivoMb ?>'));

chk('9. El máximo por envío también',
    str_contains($bloque, 'ATTACH_MAX_REQUEST_BYTES / 1048576')
    && str_contains($bloque, '<?= $maxTotalMb ?>'));

chk('10. El número de archivos sale de la constante',
    str_contains($bloque, '<?= (int) ATTACH_MAX_FILES ?>'));

chk('11. Las extensiones salen de la lista blanca',
    str_contains($bloque, 'foreach (attach_whitelist() as $ext => $def)')
    && str_contains($bloque, "implode(', ', \$porTipo['image']"));

// Lo que de verdad importa: que NADA esté escrito a mano.
$aMano = [];
if (preg_match('/\b14\s*(&nbsp;)?MB\b/u', $bloque)) {
    $aMano[] = '14 MB literal';
}
if (preg_match('/\b5\s+archivos\b/u', $bloque)) {
    $aMano[] = '5 archivos literal';
}
if (preg_match('/\b(JPG|PNG|WEBP|MP3|MP4|WEBM)\b/', $bloque)) {
    $aMano[] = 'extensiones literales';
}
chk('12. Ninguna cifra ni extensión escrita a mano',
    $aMano === [],
    $aMano === [] ? 'todo derivado' : implode(' · ', $aMano));

chk('13. El selector de archivo usa el atributo canónico',
    str_contains($bloque, 'accept="<?= h(attach_accept_attribute()) ?>"'));

// ═════════════════════════════════════════════════════════════
section('14-18 · LOS ERRORES NO SE ESCONDEN');

$posEstado = strpos($bloque, 'id="drawer_attach_status"');
$posDet    = strpos($bloque, '<details class="fyc-attach-help">');
$posFinDet = strpos($bloque, '</details>');

chk('14. El aviso de estado existe', $posEstado !== false);

chk('15. Está FUERA del desplegable',
    $posEstado !== false && $posDet !== false
    && !($posEstado > $posDet && $posEstado < $posFinDet),
    'un aviso que hay que abrir para verlo no es un aviso');

chk('16. Y por encima de él',
    $posEstado !== false && $posDet !== false && $posEstado < $posDet);

chk('17. Conserva su anuncio para lectores de pantalla',
    preg_match('/id="drawer_attach_status" role="status" aria-live="polite"/', $bloque) === 1);

// Los cuatro estados deben distinguirse por clase, no por color improvisado.
chk('18. Los estados siguen diferenciados en el JS',
    str_contains($js, "box.className = 'fyc-attach-status-' + (kind || 'info')")
    && str_contains($js, "attachSetStatus(verbo + files.length")
    && preg_match_all("/attachSetStatus\([^)]*'error'\)/", $js) >= 5,
    'info para progreso · error para validación');

// ═════════════════════════════════════════════════════════════
section('19-24 · ESTADO VACÍO COMPACTO');

chk('19. Desapareció la ilustración del estado vacío',
    !str_contains($bloque, 'ovi-default.svg'),
    'la mascota sigue en el tablero y en comentarios');

chk('20. Y el bloque centrado con relleno de 16px',
    !preg_match('/flex-direction:column;align-items:center;gap:6px;padding:16px 0 8px/', $bloque));

chk('21. Ya no existe el bloque de ayuda permanente',
    !preg_match('/font-size:10\.5px;color:var\(--text-ghost\);line-height:1\.6;margin-bottom:12px/', $bloque),
    'los formatos vivían aquí en ~100px fijos');

// La zona de arrastre hace de estado vacío: un solo mensaje, no dos.
chk('22. La zona de arrastre avisa cuando no hay nada',
    preg_match('/<?php if \(!\$attachments\): ?>Todavía no hay adjuntos\./', $bloque) === 1
    || str_contains($bloque, 'Todavía no hay adjuntos.'),
    'un único mensaje, sin repetir');

chk('23. El lector sin permiso conserva su línea',
    str_contains($bloque, 'class="fyc-attach-empty"')
    && str_contains($bloque, 'Esta tarea no tiene archivos adjuntos.'));

chk('24. Sin tarjeta dentro de la tarjeta',
    substr_count($bloque, 'fyc-attach-section') === 1
    && !preg_match('/fyc-attach-head[^>]*>\s*<div style="background:/', $bloque));

// ═════════════════════════════════════════════════════════════
section('25-30 · CONTROLES Y ALTURA TÁCTIL');

$controles = ['data-action="attach-pick"', 'id="drawer_attach_input"',
    'data-action="attach-add-link"', 'id="drawer_attach_url"',
    'class="fyc-attach-hint"', 'class="fyc-attach-dropmsg"'];
$faltan = array_values(array_filter($controles, fn($c) => !str_contains($bloque, $c)));
chk('25. Se conservan todos los controles',
    $faltan === [],
    $faltan === [] ? count($controles) . '/' . count($controles) : 'faltan: ' . implode(', ', $faltan));

chk('26. Los botones principales llegan a 44px',
    preg_match('/min-height:\s*44px/', regla($css, '.fyc-attach-action')) === 1
    && substr_count($bloque, 'fyc-attach-action') === 2,
    'añadir archivos · añadir enlace');

chk('27. El campo de URL también',
    preg_match('/min-height:\s*44px/', regla($css, '.fyc-attach-linkbar input')) === 1);

// El intercambio de mensajes durante el arrastre depende de estas dos reglas.
chk('28. Arrastrar sigue cambiando el mensaje',
    str_contains($css, '[data-attachments-section].fyc-attach-dropping .fyc-attach-dropmsg')
    && str_contains($css, '[data-attachments-section].fyc-attach-dropping .fyc-attach-hint'));

chk('29. El JS sigue encontrando la zona por su marcador',
    str_contains($js, 'data-attachments-section')
    && str_contains($bloque, 'data-attachments-section'));

chk('30. Pegar y arrastrar siguen cableados',
    str_contains($js, "document.addEventListener('paste'")
    && str_contains($js, "document.addEventListener('drop'"));

// ═════════════════════════════════════════════════════════════
section('31-36 · CONTRATO DE PRODUCCIÓN Y SIN REGRESIONES');

$diff = (string) shell_exec('git -C ' . escapeshellarg($ROOT) . ' diff HEAD 2>&1');
$lineas = preg_split('/\R/', $diff) ?: [];

chk('31. Las constantes del backend no se tocaron',
    ATTACH_MAX_FILE_BYTES === 14 * 1024 * 1024
    && ATTACH_MAX_REQUEST_BYTES === 14 * 1024 * 1024
    && ATTACH_MAX_FILES === 5,
    '14 MB · 14 MB · 5 archivos');

// Buscar «ATTACH_MAX» en todo el diff daba falso positivo: el bloque de
// derivación se ha MOVIDO dentro de drawer.php, así que sus líneas aparecen
// como quitadas y puestas. Usar la constante no es cambiarla. Lo que hay que
// demostrar es que los archivos dueños del contrato no se han tocado.
$duenos = ['public/_attachments.php', 'public/tasks/attachment_upload.php',
    'public/tasks/attachment_delete.php', 'public/tasks/attachment_link.php',
    'public/tasks/attachment.php'];
$modificados = array_values(array_filter($duenos, function ($f) use ($ROOT) {
    return trim((string) shell_exec('git -C ' . escapeshellarg($ROOT)
        . ' diff --name-only HEAD -- ' . escapeshellarg($f) . ' 2>&1')) !== '';
}));
chk('32. Ningún archivo dueño del contrato fue modificado',
    $modificados === [],
    $modificados === [] ? count($duenos) . ' intactos' : 'modificados: ' . implode(', ', $modificados));

chk('33. Las alternativas para archivos grandes siguen ofrecidas',
    str_contains($bloque, 'YouTube o Vimeo')
    && str_contains($bloque, 'enlace externo')
    && str_contains($bloque, 'el envío se rechaza completo'),
    'video por YouTube/Vimeo · resto por enlace · sin aceptación parcial');

$declaran = preg_grep('/^\+.*:\s*[^;{}]*!important\s*;/', $lineas);
chk('34. Ninguna declaración !important nueva',
    $declaran === [],
    $declaran === [] ? '0 declaraciones' : count($declaran) . ' declaraciones');

$hashArbol = trim((string) shell_exec('git -C ' . escapeshellarg($ROOT) . ' hash-object public/assets/app.css 2>&1'));
$hashHead  = trim((string) shell_exec('git -C ' . escapeshellarg($ROOT) . ' rev-parse HEAD:public/assets/app.css 2>&1'));
chk('35. app.css no fue modificado', $hashArbol === $hashHead && $hashArbol !== '',
    substr($hashArbol, 0, 16) . '…');

// F8.2 y F8.2.1 deben seguir en pie.
chk('36. F8.2 y F8.2.1 sin regresión',
    str_contains($css, 'bloque superior compacto (F8.2)')
    && str_contains($drw, 'data-fields-grid')
    && str_contains($js, 'runEmbedScripts(d.body)')
    && str_contains($js, 'window.FCPlannerBoard.loadDrawer'),
    'rejilla · ejecución tras cargar · puerta única');

// ═════════════════════════════════════════════════════════════
section('37-44 · COMPROBACIÓN REAL POR HTTP');

require_once $ROOT . '/config/db.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$conn->query("DELETE FROM boards WHERE nombre LIKE '" . QA_TAG . "%'");
foreach (glob(SESSION_DIR . '/sess_qaf83s*') ?: [] as $f) {
    @unlink($f);
}

$uid = (int) $conn->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetch_row()[0];
$conn->query("INSERT INTO boards (nombre, owner_user_id, visibility, created_at)
              VALUES ('" . QA_TAG . "', $uid, 'private', NOW())");
$bid = (int) $conn->insert_id;
$conn->query("INSERT INTO board_members (board_id,user_id,rol) VALUES ($bid,$uid,'propietario')");
$conn->query("INSERT INTO `columns` (board_id,nombre,orden,is_done,created_at)
              VALUES ($bid,'En curso',0,0,NOW())");
$cid = (int) $conn->insert_id;
$conn->query("INSERT INTO tasks (board_id,column_id,titulo,prioridad,assignee_id,sort_order,creado_en)
              VALUES ($bid,$cid,'Tarea F8.3','med',NULL,0,NOW())");
$tid = (int) $conn->insert_id;

$csrf = bin2hex(random_bytes(32));
$sid  = 'qaf83s' . bin2hex(random_bytes(8));
file_put_contents(SESSION_DIR . '/sess_' . $sid,
    'user_id|i:' . $uid . ';csrf|s:' . strlen($csrf) . ':"' . $csrf . '";_auth_ts|i:' . time() . ';');

$ch = curl_init(BASE_URL . '/tasks/drawer.php?id=' . $tid);
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => 1, CURLOPT_TIMEOUT => 30,
    CURLOPT_COOKIE => 'PHPSESSID=' . $sid, CURLOPT_HTTPHEADER => ['X-Requested-With: fetch']]);
$html = (string) curl_exec($ch);
$st   = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

chk('37. El cajón se sirve', $st === 200, "http=$st");

chk('38. El desplegable llega cerrado al navegador',
    preg_match('/<details class="fyc-attach-help">/', $html) === 1
    && !preg_match('/<details[^>]*\bopen\b/', $html));

// Los valores renderizados deben coincidir con las constantes vivas.
$mbArchivo = (int) round(ATTACH_MAX_FILE_BYTES / 1048576);
$mbTotal   = (int) round(ATTACH_MAX_REQUEST_BYTES / 1048576);
$txt = plano($html);

chk('39. Muestra el máximo por archivo real',
    str_contains($txt, 'máx. ' . $mbArchivo . ' MB cada uno'),
    $mbArchivo . ' MB');

chk('40. Y el máximo por envío real',
    str_contains($txt, 'y ' . $mbTotal . ' MB entre todos'),
    $mbTotal . ' MB');

chk('41. Y el número de archivos real',
    str_contains($txt, 'Hasta ' . ATTACH_MAX_FILES . ' archivos por vez'),
    ATTACH_MAX_FILES . ' archivos');

// Las extensiones renderizadas deben ser exactamente las de la lista blanca.
$esperadas = [];
foreach (attach_whitelist() as $ext => $def) {
    $esperadas[$def[0]][] = strtoupper($ext);
}
$faltanExt = [];
foreach ($esperadas as $tipo => $exts) {
    if (!str_contains($txt, implode(', ', $exts))) {
        $faltanExt[] = $tipo;
    }
}
chk('42. Las extensiones coinciden con la lista blanca',
    $faltanExt === [],
    $faltanExt === [] ? count($esperadas) . ' grupos' : 'no coincide: ' . implode(', ', $faltanExt));

chk('43. Tarea sin adjuntos: un solo mensaje de vacío',
    substr_count($txt, 'Todavía no hay adjuntos.') === 1
    && !str_contains($txt, 'Sin adjuntos todavía'),
    'el antiguo bloque no reaparece');

chk('44. El aviso de estado llega oculto y vacío',
    preg_match('/id="drawer_attach_status"[^>]*style="display:none;[^"]*"><\/div>/', $html) === 1,
    'sin ocupar altura hasta que haga falta');

$conn->query("DELETE FROM boards WHERE nombre LIKE '" . QA_TAG . "%'");
foreach (glob(SESSION_DIR . '/sess_qaf83s*') ?: [] as $f) {
    @unlink($f);
}

// ═════════════════════════════════════════════════════════════
echo "\n" . str_repeat('═', 78) . "\n";
printf(" RESULTADO: %d correctas, %d fallidas%s\n", $PASS, $FAIL,
    $PEND ? ", {$PEND} sin verificar" : '');
echo str_repeat('═', 78) . "\n\n";

exit($FAIL === 0 ? 0 : 1);
