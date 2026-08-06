<?php
/**
 * tests/drawer_gallery_comments_smoke.php
 *
 * Galería y comentarios compactos (Fase F8, bloque F8.4).
 *
 * Ejecutar SOLO en local:
 *   php tests/drawer_gallery_comments_smoke.php
 *
 * Vigila que las tarjetas de la galería no vuelvan a gastar una línea entera
 * en las acciones, que el tipo de adjunto no se diga dos veces, que un nombre
 * o una URL larga se recorten sin desbordar, y que el bloque de comentarios
 * conserve autor, fecha, texto, Ctrl+Enter y publicación mientras deja de
 * llevar cajas dentro de cajas.
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
const QA_TAG      = 'QA F84 SMOKE';

$PASS = 0;
$FAIL = 0;

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
    $re = '/(?<![\w.\[:-])' . preg_quote($sel, '/') . '\s*\{([^}]*)\}/s';
    return preg_match($re, $css, $m) ? $m[1] : '';
}

echo "\n";
echo "══════════════════════════════════════════════════════════════════════════════\n";
echo " GALERÍA Y COMENTARIOS COMPACTOS (bloque F8.4)\n";
echo "══════════════════════════════════════════════════════════════════════════════\n";
echo " PHP   : " . PHP_VERSION . "\n";
echo " Fecha : " . date('Y-m-d H:i:s') . "\n";

$leer = fn(string $p): string => str_replace("\r\n", "\n", (string) file_get_contents($p));
$drw = $leer($DRW);
$css = $leer($CSS);
$js  = $leer($JS);

$bloqueAdj = '';
if (preg_match('/<!-- ADJUNTOS -->(.*?)<!-- COMENTARIOS -->/s', $drw, $m)) {
    $bloqueAdj = $m[1];
}
$bloqueCom = '';
if (preg_match('/<!-- COMENTARIOS -->(.*)$/s', $drw, $m)) {
    $bloqueCom = $m[1];
}

// ═════════════════════════════════════════════════════════════
section('1-7 · TARJETA DE GALERÍA COMPACTA');

chk('1. Los dos bloques se aíslan', $bloqueAdj !== '' && $bloqueCom !== '');

chk('2. Metadatos y acciones comparten fila',
    preg_match('/<div class="fyc-attach-row">\s*<span class="fyc-attach-meta">.*?<span class="fyc-attach-acts">/s', $bloqueAdj) === 1,
    'antes las acciones gastaban una línea entera');

chk('3. Desapareció la fila de acciones con estilo en línea',
    !str_contains($bloqueAdj, 'display:flex;align-items:center;gap:10px;margin-top:8px;'));

// El tipo lo dice el distintivo sobre la miniatura: repetirlo era el caso
// exacto de «no repitas el mismo dato en dos lugares».
chk('4. El tipo ya no se repite en los metadatos',
    !preg_match('/\$meta\s*=\s*attach_kind_label/', $bloqueAdj)
    && preg_match('/\$meta = \(\$esExtern \? \'\' : \$a\[\'size_human\'\]/', $bloqueAdj) === 1,
    'el distintivo lo sigue diciendo una vez');

chk('5. El distintivo de tipo se conserva',
    str_contains($bloqueAdj, 'class="fyc-attach-badge"')
    && str_contains($bloqueAdj, 'attach_kind_label($a[\'kind\'])'));

chk('6. La tercera línea de la URL desapareció',
    !preg_match('/font-size:10px;color:var\(--text-ghost\);margin-top:2px;overflow:hidden/', $bloqueAdj),
    'la dirección completa vive ahora en el title');

chk('7. El relleno de la tarjeta se redujo',
    preg_match('/padding:\s*7px 9px/', regla($css, '.fyc-attach-info')) === 1
    && !str_contains($bloqueAdj, 'style="padding:8px 10px;"'));

// ═════════════════════════════════════════════════════════════
section('8-13 · NOMBRES LARGOS Y ZONA TÁCTIL');

$rNombre = regla($css, '.fyc-attach-name');
chk('8. El nombre se recorta con puntos suspensivos',
    preg_match('/overflow:\s*hidden/', $rNombre) === 1
    && preg_match('/text-overflow:\s*ellipsis/', $rNombre) === 1
    && preg_match('/white-space:\s*nowrap/', $rNombre) === 1);

chk('9. Y conserva el texto completo en el title',
    preg_match('/<div class="fyc-attach-name" title="<\?= h\(\$titulo\) \?>">/', $bloqueAdj) === 1);

chk('10. En un enlace, el title lleva la dirección completa',
    preg_match('/\$titulo = \$esExtern\s*\?\s*\(string\) \(\$a\[\'external_url\'\] \?\? \$nombre\)/', $bloqueAdj) === 1);

// min-width:0 es lo que impide que un metadato largo empuje las acciones fuera.
chk('11. Un metadato largo no empuja las acciones fuera',
    preg_match('/min-width:\s*0/', regla($css, '.fyc-attach-meta')) === 1
    && preg_match('/flex-shrink:\s*0/', regla($css, '.fyc-attach-acts')) === 1);

$rAct = regla($css, '.fyc-attach-act');
chk('12. Las acciones conservan zona táctil aunque el texto sea pequeño',
    preg_match('/min-height:\s*32px/', $rAct) === 1
    && preg_match('/padding:\s*0 6px/', $rAct) === 1,
    '32px de alto · texto de 11px');

chk('13. Y foco visible para quien navega con teclado',
    preg_match('/\.fyc-attach-act:focus-visible\s*\{[^}]*outline:/s', $css) === 1);

// ═════════════════════════════════════════════════════════════
section('14-19 · TIPOS, VISOR Y SEGURIDAD INTACTOS');

foreach ([
    'imagen'  => 'data-action="attach-open"',
    'audio'   => '<audio controls preload="metadata"',
    'video'   => '<video controls preload="metadata"',
    'enlace'  => 'class="fyc-attach-linkicon"',
    'incrust' => '<iframe src="<?= h($a[\'embed_url\']) ?>"',
] as $etq => $marca) {
    chk('14. Se conserva el tipo: ' . $etq, str_contains($bloqueAdj, $marca));
}

chk('15. El visor de imagen sigue completo',
    str_contains($bloqueAdj, 'id="fycImgViewer"')
    && str_contains($bloqueAdj, 'data-action="viewer-close"')
    && str_contains($bloqueAdj, 'id="fycImgViewerDl"'));

chk('16. Descargar y eliminar siguen presentes',
    str_contains($bloqueAdj, 'href="<?= h((string) $a[\'download_url\']) ?>" download')
    && str_contains($bloqueAdj, 'data-action="attach-delete"'));

// La seguridad del embed no se toca: src desde plantilla propia, nunca
// external_url, y el enlace externo con rel completo.
chk('17. El incrustado sigue usando embed_url, no la URL externa',
    str_contains($bloqueAdj, 'referrerpolicy="strict-origin-when-cross-origin"')
    && !preg_match('/<iframe[^>]*src="<\?= h\(\$a\[\'external_url\'\]/', $bloqueAdj));

chk('18. Los enlaces externos conservan rel y destino',
    preg_match('/target="_blank" rel="noopener noreferrer nofollow"/', $bloqueAdj) === 1);

chk('19. Las columnas responsive no cambiaron',
    preg_match('/grid-template-columns:\s*repeat\(auto-fill,\s*minmax\(190px,\s*1fr\)\)/', regla($css, '.fyc-attach-grid')) === 1,
    'auto-fill minmax(190px): 2 · 2 · 3 · 4 columnas');

// ═════════════════════════════════════════════════════════════
section('20-26 · COMENTARIOS COMPACTOS');

chk('20. La sección usa clase, no estilo en línea',
    str_contains($bloqueCom, '<div class="fyc-comments">')
    && !preg_match('/<!-- COMENTARIOS -->\s*<div style="background:var\(--bg-surface\)/', $drw));

chk('21. Desapareció la ilustración del estado vacío',
    !str_contains($bloqueCom, 'ovi-saludo.svg')
    && str_contains($bloqueCom, 'class="fyc-comments-empty"'),
    'una frase en lugar de un bloque de 121px');

// Se busca la ETIQUETA, no la palabra: el comentario del código explica que
// el rótulo se retiró y contiene el mismo texto. Ya me pasó con !important.
chk('22. Y el rótulo que repetía el marcador de posición',
    !preg_match('/<label[^>]*>\s*Agregar comentario\s*<\/label>/', $bloqueCom)
    && str_contains($bloqueCom, 'placeholder="Escribe un comentario… (Ctrl+Enter para enviar)"'),
    'el campo ya dice qué escribir y cómo enviarlo');

// Sin borde: el fondo ya separa el comentario del panel.
$rCom = regla($css, '.fyc-comment');
chk('23. El comentario pierde el contorno dentro del contorno',
    !preg_match('/border:\s*1px/', $rCom)
    && preg_match('/background:\s*var\(--bg-hover\)/', $rCom) === 1
    && preg_match('/padding:\s*8px 10px/', $rCom) === 1);

chk('24. Autor y fecha comparten fila',
    preg_match('/<div class="fyc-comment-meta">\s*<span class="fyc-comment-who">.*?<span class="fyc-comment-when">/s', $bloqueCom) === 1);

chk('25. Se conservan autor, fecha y texto',
    str_contains($bloqueCom, '<?= h($who) ?>')
    && str_contains($bloqueCom, '<?= h($when) ?>')
    && str_contains($bloqueCom, '<?= h($c[\'body\'] ?? \'\') ?>'));

$rBody = regla($css, '.fyc-comment-body');
chk('26. Los saltos de línea y las URL largas se respetan',
    preg_match('/white-space:\s*pre-wrap/', $rBody) === 1
    && preg_match('/overflow-wrap:\s*anywhere/', $rBody) === 1,
    'pre-wrap conserva los saltos · anywhere parte una URL sin espacios');

// ═════════════════════════════════════════════════════════════
section('27-33 · FORMULARIO Y ENVÍO');

chk('27. El campo arranca compacto',
    preg_match('/rows="2"/', $bloqueCom) === 1
    && preg_match('/min-height:\s*56px/', regla($css, '.fyc-comment-input')) === 1,
    'dos líneas de partida');

// El crecimiento vive en el script del cajón, que desde F8.2.1 sí se ejecuta.
chk('28. Crece con el contenido, con tope',
    str_contains($bloqueCom, "ta.style.height = Math.min(ta.scrollHeight, MAX)")
    && str_contains($bloqueCom, "ta.addEventListener('input', ajustar)"));

chk('29. Y vuelve a su altura tras publicar',
    str_contains($bloqueCom, "ta.addEventListener('fyc-reset', ajustar)")
    && str_contains($js, "ta.dispatchEvent(new CustomEvent('fyc-reset'))"));

// Enlaces al propio textarea: es la condición que hace seguro repetir el
// script en cada carga del cajón (invariante de F8.2.1).
chk('30. El script sigue sin enlazarse a document ni window',
    preg_match('/<script>(.*?)<\/script>\s*$/s', $bloqueCom, $mS) === 1
    && !preg_match('/(document|window)\s*\.\s*addEventListener/', $mS[1]),
    'repetirlo no acumula manejadores');

chk('31. Ctrl+Enter se conserva',
    str_contains($bloqueCom, "e.key === 'Enter' && (e.ctrlKey || e.metaKey)")
    && str_contains($bloqueCom, 'e.preventDefault();'),
    'y Enter normal no dispara nada: la condición exige Ctrl o Cmd');

chk('32. El botón Publicar conserva zona táctil',
    preg_match('/min-height:\s*44px/', regla($css, '.fyc-comment-send')) === 1
    && str_contains($bloqueCom, 'data-action="drawer-add-comment"'));

// La tarjeta que inserta el JS debe verse igual que las del servidor.
chk('33. El comentario recién publicado usa las mismas clases',
    str_contains($js, "div.className = 'fyc-comment'")
    && str_contains($js, "div.querySelector('.fyc-comment-body').textContent = body")
    && !str_contains($js, "border-radius:10px;border:1px solid var(--border-main);background:var(--bg-hover);padding:10px;"),
    'sin estilos en línea que se desincronicen');

// ═════════════════════════════════════════════════════════════
section('34-38 · CONTRATOS Y SIN REGRESIONES');

$diff = (string) shell_exec('git -C ' . escapeshellarg($ROOT) . ' diff HEAD 2>&1');
$lineas = preg_split('/\R/', $diff) ?: [];

$duenos = ['public/_attachments.php', 'public/tasks/attachment_upload.php',
    'public/tasks/attachment_delete.php', 'public/tasks/attachment_link.php',
    'public/tasks/attachment.php', 'public/tasks/comment_create.php'];
$tocados = array_values(array_filter($duenos, fn($f) =>
    trim((string) shell_exec('git -C ' . escapeshellarg($ROOT)
        . ' diff --name-only HEAD -- ' . escapeshellarg($f) . ' 2>&1')) !== ''));
chk('34. Endpoints de adjuntos y comentarios intactos',
    $tocados === [],
    $tocados === [] ? count($duenos) . ' intactos' : 'tocados: ' . implode(', ', $tocados));

chk('35. Los límites siguen siendo los del contrato',
    ATTACH_MAX_FILE_BYTES === 14 * 1024 * 1024
    && ATTACH_MAX_REQUEST_BYTES === 14 * 1024 * 1024
    && ATTACH_MAX_FILES === 5,
    '14 MB · 14 MB · 5 archivos');

$declaran = preg_grep('/^\+.*:\s*[^;{}]*!important\s*;/', $lineas);
chk('36. Ninguna declaración !important nueva',
    $declaran === [],
    $declaran === [] ? '0 declaraciones' : count($declaran) . ' declaraciones');

$hashArbol = trim((string) shell_exec('git -C ' . escapeshellarg($ROOT) . ' hash-object public/assets/app.css 2>&1'));
$hashHead  = trim((string) shell_exec('git -C ' . escapeshellarg($ROOT) . ' rev-parse HEAD:public/assets/app.css 2>&1'));
chk('37. app.css no fue modificado', $hashArbol === $hashHead && $hashArbol !== '',
    substr($hashArbol, 0, 16) . '…');

chk('38. F8.1 a F8.3 siguen en pie',
    str_contains($css, 'ancho responsivo (F8.1)')
    && str_contains($drw, 'data-fields-grid')
    && str_contains($js, 'runEmbedScripts(d.body)')
    && str_contains($drw, '<details class="fyc-attach-help">'),
    'ancho · rejilla · ejecución tras cargar · ayuda desplegable');

// ═════════════════════════════════════════════════════════════
section('39-45 · COMPROBACIÓN REAL POR HTTP');

require_once $ROOT . '/config/db.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$conn->query("DELETE FROM boards WHERE nombre LIKE '" . QA_TAG . "%'");
foreach (glob(SESSION_DIR . '/sess_qaf84s*') ?: [] as $f) {
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
              VALUES ($bid,$cid,'Tarea F8.4','med',NULL,0,NOW())");
$tVacia = (int) $conn->insert_id;
$conn->query("INSERT INTO tasks (board_id,column_id,titulo,prioridad,assignee_id,sort_order,creado_en)
              VALUES ($bid,$cid,'Tarea F8.4 con texto','med',NULL,1,NOW())");
$tCom = (int) $conn->insert_id;

$largo = "Primera línea.\nSegunda con una dirección larguísima: "
       . "https://ejemplo-de-dominio-muy-largo.com/contabilidad/2026/anticipos-revisados.pdf\n"
       . "Tercera línea.";
$st = $conn->prepare("INSERT INTO comments (board_id,task_id,user_id,body,created_at) VALUES (?,?,?,?,NOW())");
$st->bind_param('iiis', $bid, $tCom, $uid, $largo);
$st->execute();

$csrf = bin2hex(random_bytes(32));
$sid  = 'qaf84s' . bin2hex(random_bytes(8));
file_put_contents(SESSION_DIR . '/sess_' . $sid,
    'user_id|i:' . $uid . ';csrf|s:' . strlen($csrf) . ':"' . $csrf . '";_auth_ts|i:' . time() . ';');

$pedir = function (string $url) use ($sid): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => 1, CURLOPT_TIMEOUT => 30,
        CURLOPT_COOKIE => 'PHPSESSID=' . $sid, CURLOPT_HTTPHEADER => ['X-Requested-With: fetch']]);
    $b = (string) curl_exec($ch);
    $s = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$s, $b];
};

[$s1, $h1] = $pedir(BASE_URL . '/tasks/drawer.php?id=' . $tVacia);
chk('39. El cajón se sirve', $s1 === 200, "http=$s1");

chk('40. Sin comentarios: una frase, sin ilustración',
    str_contains($h1, 'class="fyc-comments-empty"')
    && str_contains($h1, 'Sin comentarios aún. Escribe el primero.')
    && !str_contains($h1, 'ovi-saludo.svg'));

chk('41. El formulario llega con dos filas y el botón completo',
    preg_match('/id="drawer_comment" rows="2"/', $h1) === 1
    && str_contains($h1, 'class="fyc-btn fyc-btn-primary fyc-comment-send"'));

chk('42. El campo conserva su etiqueta accesible',
    str_contains($h1, 'aria-label="Escribe un comentario"'),
    'el rótulo visible se fue; la etiqueta accesible no');

[$s2, $h2] = $pedir(BASE_URL . '/tasks/drawer.php?id=' . $tCom);
chk('43. Un comentario se pinta con las clases nuevas',
    $s2 === 200 && str_contains($h2, '<div class="fyc-comment">')
    && str_contains($h2, 'class="fyc-comment-who"')
    && str_contains($h2, 'class="fyc-comment-when"')
    && str_contains($h2, 'class="fyc-comment-body"'));

chk('44. El texto largo llega íntegro y escapado',
    str_contains($h2, 'anticipos-revisados.pdf')
    && str_contains($h2, 'Primera línea.')
    && str_contains($h2, 'Tercera línea.'));

// La lista conserva el gancho que usa board-view.js para insertar.
chk('45. La lista conserva el gancho del JS',
    str_contains($h2, 'class="space-y-3 fyc-comments-list"')
    && str_contains($js, "querySelector('#taskDrawerBody .space-y-3')"),
    'si se pierde .space-y-3, el comentario nuevo no aparece hasta recargar');

$conn->query("DELETE FROM boards WHERE nombre LIKE '" . QA_TAG . "%'");
foreach (glob(SESSION_DIR . '/sess_qaf84s*') ?: [] as $f) {
    @unlink($f);
}

// ═════════════════════════════════════════════════════════════
echo "\n" . str_repeat('═', 78) . "\n";
printf(" RESULTADO: %d correctas, %d fallidas\n", $PASS, $FAIL);
echo str_repeat('═', 78) . "\n\n";

exit($FAIL === 0 ? 0 : 1);
