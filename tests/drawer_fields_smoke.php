<?php
/**
 * tests/drawer_fields_smoke.php
 *
 * Compactación del bloque superior del cajón de tarea (Fase F8, bloque F8.2).
 *
 * Ejecutar SOLO en local:
 *   php tests/drawer_fields_smoke.php
 *
 * Comprueba que prioridad, fecha y responsable comparten rejilla, que las
 * etiquetas dejaron de tener tarjeta propia, que la descripción sigue
 * guardando y que nada del módulo de adjuntos se movió por el camino.
 *
 * Parte de las pruebas necesitan Apache y MySQL: crean un tablero temporal,
 * lo usan y lo borran. No dejan residuos.
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

const BASE_URL    = 'http://localhost/fyc_planner/public';
const SESSION_DIR = 'C:/laragon/tmp';
const QA_TAG      = 'QA F82 SMOKE';
// Etiqueta corta de esta suite. Entra en el correo de sus usuarios QA
// (qa.<suite>.<aleatorio>@local.test) y permite retirar restos de una
// ejecucion que se interrumpiera antes de limpiar.
const QA_SUITE    = 'drawerfields';

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

/** Bloque de una regla CSS por selector exacto. Devuelve '' si no existe. */
function regla(string $css, string $selector): string
{
    $re = '/(?<![\w.-])' . preg_quote($selector, '/') . '\s*\{([^}]*)\}/s';
    return preg_match($re, $css, $m) ? $m[1] : '';
}

/** Igual, pero dentro de una @media (min-width: Npx). */
function reglaEnMedia(string $css, string $ancho, string $selector): string
{
    $re = '/@media\s*\(\s*min-width:\s*' . preg_quote($ancho, '/') . '\s*\)\s*\{\s*'
        . preg_quote($selector, '/') . '\s*\{([^}]*)\}/s';
    return preg_match($re, $css, $m) ? $m[1] : '';
}

echo "\n";
echo "══════════════════════════════════════════════════════════════════════════════\n";
echo " BLOQUE SUPERIOR COMPACTO DEL CAJÓN DE TAREA (bloque F8.2)\n";
echo "══════════════════════════════════════════════════════════════════════════════\n";
echo " PHP   : " . PHP_VERSION . "\n";
echo " Fecha : " . date('Y-m-d H:i:s') . "\n";

// Los archivos del proyecto están guardados con CRLF. Se normaliza a LF nada
// más leerlos: si no, cualquier patrón multilínea escrito con \n falla sin que
// haya nada roto en el código. Ya me costó una falsa alarma en esta suite.
$leer = fn(string $p): string => str_replace("\r\n", "\n", (string) file_get_contents($p));

$drw = $leer($DRW);
$css = $leer($CSS);
$js  = $leer($JS);

// ═════════════════════════════════════════════════════════════
section('1-6 · LOS TRES CAMPOS COMPARTEN REJILLA');

$base   = regla($css, '.fyc-drawer-fields');
$en640  = reglaEnMedia($css, '640px', '.fyc-drawer-fields');

chk('1. Existe la rejilla de campos', $base !== '');

chk('2. Móvil: una sola columna',
    preg_match('/grid-template-columns:\s*minmax\(\s*0\s*,\s*1fr\s*\)\s*;/', $base) === 1,
    'por debajo de 640px no cabe otra cosa');

chk('3. A partir de 640px: tres columnas iguales',
    preg_match('/grid-template-columns:\s*repeat\(\s*3\s*,\s*minmax\(\s*0\s*,\s*1fr\s*\)\s*\)\s*;/', $en640) === 1,
    $en640 === '' ? 'ausente' : 'repeat(3, minmax(0, 1fr))');

// minmax(0,1fr) y no 1fr: con 1fr, un <select> de opción larga ensancha su
// columna y desborda la rejilla. Es el fallo clásico de grid.
chk('4. El mínimo va a cero en ambos casos',
    !preg_match('/grid-template-columns:\s*(repeat\(\s*3\s*,\s*)?1fr/', $base . $en640),
    'sin minmax(0,…) un nombre largo desbordaría la columna');

chk('5. Los tres campos están dentro de la rejilla',
    preg_match('/<div class="fyc-drawer-fields" data-fields-grid>(.*?)<\/div>\s*<!-- ETIQUETAS/s', $drw, $m) === 1
    && str_contains($m[1], 'id="drawer_prioridad"')
    && str_contains($m[1], 'id="drawer_fecha"')
    && str_contains($m[1], 'id="drawer_assignee"'),
    'prioridad + fecha límite + responsable');

chk('6. Ya no queda la columna flexible antigua',
    !preg_match('/flex-direction:column;gap:12px;">\s*<input type="hidden" id="drawer_task_id"/', $drw),
    'el apilado vertical fijo desapareció');

// ═════════════════════════════════════════════════════════════
section('7-9 · ALTURA TÁCTIL');

$tactil = regla($css, '.fyc-drawer-fields .fyc-input,
.fyc-drawer-fields .fyc-select');
if ($tactil === '') {
    // Tolerar que el par de selectores esté escrito en una sola línea.
    $tactil = regla($css, '.fyc-drawer-fields .fyc-input, .fyc-drawer-fields .fyc-select');
}

chk('7. Los controles declaran un mínimo de 44px',
    preg_match('/min-height:\s*44px/', $tactil) === 1,
    $tactil === '' ? 'regla ausente' : 'min-height: 44px');

// El peso propio de la regla es lo que evita el !important: dos clases
// (0,2,0) superan a la global .fyc-select (0,1,0).
chk('8. La regla táctil lleva dos clases, no !important',
    str_contains($css, '.fyc-drawer-fields .fyc-select')
    && !preg_match('/\.fyc-drawer-fields[^{]*\{[^}]*!important/s', $css),
    'gana por especificidad');

chk('9. No se alteró la regla global de formularios',
    preg_match('/\.fyc-input,\s*\.fyc-select,\s*\.fyc-textarea\s*\{[^}]*padding:\s*8px 12px/s', $css) === 1,
    'el resto de la aplicación conserva su tamaño');

// ═════════════════════════════════════════════════════════════
section('10-13 · ESTADO JUNTO AL TÍTULO, SIN DUPLICAR');

chk('10. El cajón consulta el nombre de la columna',
    str_contains($drw, 'c.nombre AS estado_nombre')
    && str_contains($drw, 'LEFT JOIN `columns` c ON c.id = t.column_id'),
    'LEFT JOIN: una tarea sin columna sigue abriendo');

chk('11. El estado se pinta como distintivo en la cabecera',
    preg_match('/<div class="fyc-drawer-meta">.*?data-drawer-estado.*?<\/div>\s*<h2 class="fyc-drawer-title">/s', $drw) === 1,
    'en la fila de identificación, encima del título');

chk('12. Solo aparece si la tarea tiene columna',
    preg_match('/if \(\$estado_nombre !== \'\'\)/', $drw) === 1);

// El distintivo del responsable repetía el dato del selector de más abajo.
chk('13. El responsable ya no está duplicado en la cabecera',
    substr_count($drw, 'id="drawer_assignee"') === 1
    && !preg_match('/fyc-drawer-meta.*?explode\(\' \', \$asig_name\)/s', $drw),
    'una sola vez, en su campo');

// ═════════════════════════════════════════════════════════════
section('14-18 · ETIQUETAS SIN TARJETA PROPIA');

chk('14. Campos y etiquetas comparten un único panel',
    preg_match('/<div class="fyc-drawer-panel">(.*?)<!-- DESCRIPCIÓN/s', $drw, $m) === 1
    && str_contains($m[1], 'id="drawer_prioridad"')
    && str_contains($m[1], 'id="tagList"'),
    'un contorno menos y un hueco menos');

chk('15. Desapareció la tarjeta independiente de etiquetas',
    !preg_match('/<!-- ETIQUETAS \/ TAGS -->\s*<\?php if \(\$hasTags\): \?>\s*<div style="background:var\(--bg-surface\);border:1px solid/s', $drw));

chk('16. Los separa un filete, no un borde completo',
    regla($css, '.fyc-drawer-sep') !== ''
    && preg_match('/height:\s*1px/', regla($css, '.fyc-drawer-sep')) === 1
    && str_contains($drw, '<div class="fyc-drawer-sep"></div>'));

// Toda la funcionalidad de etiquetas debe seguir presente en el marcado.
$piezas = ['id="tagList"', 'id="btnShowCreateTag"', 'id="createTagForm"', 'id="newTagName"',
    'id="newTagColor"', 'id="btnCancelCreateTag"', 'id="btnConfirmCreateTag"',
    'class="tag-toggle-btn"', 'class="tag-color-opt"'];
$faltan = array_values(array_filter($piezas, fn($p) => !str_contains($drw, $p)));
chk('17. Se conserva añadir, quitar y crear etiquetas',
    $faltan === [],
    $faltan === [] ? count($piezas) . '/' . count($piezas) . ' piezas' : 'faltan: ' . implode(', ', $faltan));

// El script en línea las mueve por estilo directo; convertir los chips a
// clases lo habría roto en silencio.
// Nada de [^>]* aquí: entre los atributos hay etiquetas PHP cortas, y el
// cierre de cada una aporta un signo mayor-que que corta la clase negada.
// Se buscan las piezas por separado.
chk('18. Los chips conservan su estilo en línea manipulable',
    str_contains($drw, 'style="display:inline-flex;align-items:center;gap:5px;padding:3px 10px;')
    && preg_match('/background:<\?=\s*\$isActive \? h\(\$tag\[\'color_hex\'\]\)/', $drw) === 1
    && str_contains($drw, "btn.style.background = 'var(--bg-hover)'"),
    'el script sigue encontrando lo que espera');

// ═════════════════════════════════════════════════════════════
section('19-23 · DESCRIPCIÓN');

chk('19. El alto mínimo son 3 líneas, no 5 fijas',
    preg_match('/min-height:\s*76px/', regla($css, '.fyc-drawer-desc')) === 1
    && !str_contains($drw, 'min-height:90px'),
    '3 × 13px × 1.5 + relleno');

chk('20. Las filas se derivan del contenido guardado',
    preg_match('/\$descRows\s*=\s*max\(3,\s*min\(14,\s*\$descLineas\)\)/', $drw) === 1
    && str_contains($drw, 'rows="<?= (int) $descRows ?>"'),
    'mínimo 3, crece hasta 14');

chk('21. Conserva el marcador de posición',
    str_contains($drw, 'placeholder="Escribe una descripción, notas o pasos a seguir…"'));

chk('22. Sigue redimensionable a mano',
    preg_match('/resize:\s*vertical/', regla($css, '.fyc-drawer-desc')) === 1);

chk('23. El campo mantiene su identificador de guardado',
    substr_count($drw, 'id="drawer_desc"') === 1
    && str_contains($js, "getElementById('drawer_desc')"),
    'board-view.js lo localiza igual');

// ═════════════════════════════════════════════════════════════
section('24-28 · NADA DE ADJUNTOS SE MOVIÓ');

// Comparación literal contra HEAD: la sección de adjuntos no debe aparecer
// en el diff de este bloque.
$diff = (string) shell_exec('git -C ' . escapeshellarg($ROOT)
    . ' diff HEAD -- public/tasks/drawer.php 2>&1');

// Esta prueba vigilaba que F8.2 —un bloque de maquetación de la zona
// superior— no se metiera con los adjuntos. F8.3 sí los reordena a propósito,
// y un diff contra HEAD no distingue un bloque de otro. Se conserva lo que
// F8.2 necesitaba de verdad y sigue siendo cierto: que los archivos dueños
// del módulo estén intactos.
$duenosAdjuntos = ['public/_attachments.php', 'public/tasks/attachment_upload.php',
    'public/tasks/attachment_delete.php', 'public/tasks/attachment_link.php',
    'public/tasks/attachment.php'];
$tocados = array_values(array_filter($duenosAdjuntos, fn($f) =>
    trim((string) shell_exec('git -C ' . escapeshellarg($ROOT)
        . ' diff --name-only HEAD -- ' . escapeshellarg($f) . ' 2>&1')) !== ''));
chk('24. Los archivos del módulo de adjuntos siguen intactos',
    $tocados === [],
    $tocados === [] ? count($duenosAdjuntos) . ' intactos' : 'tocados: ' . implode(', ', $tocados));

chk('25. Las constantes de tamaño se siguen derivando',
    str_contains($drw, 'ATTACH_MAX_FILE_BYTES / 1048576')
    && str_contains($drw, 'ATTACH_MAX_REQUEST_BYTES / 1048576'),
    'el contrato de 14 MB no se tocó');

chk('26. La ayuda de adjuntos sigue intacta',
    str_contains($drw, '<strong>¿Algo más grande?</strong>')
    && str_contains($drw, 'attach_accept_attribute()'));

// F8.2 solo necesitaba no romper los comentarios, y lo comprobaba pidiendo
// que no apareciesen en el diff. F8.4 los rediseña a propósito, así que ese
// contrato ya no se puede verificar contra HEAD. Se conserva la intención de
// fondo: el campo de comentario sigue existiendo con el mismo identificador y
// el mismo cableado de envío.
chk('27. El campo de comentario conserva su cableado',
    substr_count($drw, 'id="drawer_comment"') === 1
    && str_contains($js, "getElementById('drawer_comment')")
    && str_contains($js, "fetch('../tasks/comment_create.php'")
    && str_contains($drw, 'data-action="drawer-add-comment"'));

// Esta prueba exigía que board-view.js estuviera intacto respecto a HEAD.
// Era correcto mientras F8.2 fue el último bloque, pero F8.2.1 lo modifica a
// propósito para revivir los scripts del cajón, y un diff contra HEAD no sabe
// distinguir un bloque de otro. Mantenerla obligaría a no arreglar el ciclo de
// vida solo para que una prueba siguiera verde: se reexpresa el invariante que
// F8.2 necesitaba de verdad —que el cableado de guardado no cambió—, que sí
// sigue siendo comprobable.
$camposJs = ['drawer_task_id', 'drawer_board_id', 'drawer_csrf',
    'drawer_prioridad', 'drawer_fecha', 'drawer_assignee', 'drawer_desc'];
$sinLocalizar = array_values(array_filter($camposJs,
    fn($id) => !str_contains($js, "getElementById('" . $id . "')")));
chk('28. board-view.js sigue localizando los mismos campos',
    $sinLocalizar === [] && str_contains($js, "fetch('../tasks/update.php'"),
    $sinLocalizar === [] ? count($camposJs) . '/' . count($camposJs) . ' · update.php'
        : 'no encuentra: ' . implode(', ', $sinLocalizar));

// ═════════════════════════════════════════════════════════════
section('29-33 · SIN EFECTOS COLATERALES');

// !important solo cuenta como declaración real, no dentro de un comentario
// que explique por qué no hace falta.
$sinComentarios = (string) preg_replace('#/\*.*?\*/#s', '', $css);
$totalImportant = preg_match_all('/!important\s*;/', $sinComentarios);
$bloqueF82 = '';
if (preg_match('/bloque superior compacto \(F8\.2\).*$/s', $css, $m)) {
    $bloqueF82 = (string) preg_replace('#/\*.*?\*/#s', '', $m[0]);
}
chk('29. El bloque nuevo no usa !important',
    $bloqueF82 !== '' && preg_match_all('/!important\s*;/', $bloqueF82) === 0,
    "0 nuevos · {$totalImportant} en todo el archivo (preexistentes)");

// app.css lo genera Tailwind y queda fuera del alcance de esta fase.
$hashArbol = trim((string) shell_exec('git -C ' . escapeshellarg($ROOT)
    . ' hash-object public/assets/app.css 2>&1'));
$hashHead = trim((string) shell_exec('git -C ' . escapeshellarg($ROOT)
    . ' rev-parse HEAD:public/assets/app.css 2>&1'));
chk('30. app.css no fue modificado', $hashArbol === $hashHead && $hashArbol !== '',
    substr($hashArbol, 0, 16) . '…');

chk('31. El ancho responsivo de F8.1 sigue en pie',
    reglaEnMedia($css, '768px', '.fyc-task-drawer') !== ''
    && reglaEnMedia($css, '1280px', '.fyc-task-drawer') !== ''
    && reglaEnMedia($css, '1536px', '.fyc-task-drawer') !== '',
    'las tres consultas de F8.1 intactas');

chk('32. El cajón conserva sus tres vías de cierre',
    str_contains($js, "function closeDrawer")
    && str_contains($js, "'Escape'")
    && str_contains((string) file_get_contents($ROOT . '/public/boards/workspace.php'), 'taskDrawerOverlay')
    && str_contains((string) file_get_contents($ROOT . '/public/boards/workspace.php'), 'data-drawer-close'),
    'Escape · fondo · botón ✕');

chk('33. El marcado de la cabecera está equilibrado',
    substr_count($drw, '<div class="fyc-drawer-meta">') === 1
    && substr_count($drw, '<div class="fyc-drawer-panel">') === 1
    && substr_count($drw, 'class="fyc-drawer-tagrow"') === 1);

// ═════════════════════════════════════════════════════════════
section('34-39 · COMPROBACIÓN REAL POR HTTP');

$conn = null;
$httpOk = false;
if (is_file($ROOT . '/config/db.php')) {
    require_once $ROOT . '/config/bootstrap.php';
    require_once $ROOT . '/config/db.php';
    require_once __DIR__ . '/_qa_users.php';
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    $httpOk = true;
}

if (!$httpOk) {
    pend('34-39. Comprobación por HTTP', 'sin conexión a la base de datos');
} else {
    // ── escenario temporal ──
    $conn->query("DELETE FROM boards WHERE nombre LIKE '" . QA_TAG . "%'");
    // Usuarios QA de esta suite: se retiran por identificador junto con sus
    // tableros, equipos y avisos. Cubre tambien restos de una ejecucion
    // anterior que se interrumpiera antes de limpiar.
    qa_users_cleanup_stale($conn, QA_SUITE);
    foreach (glob(SESSION_DIR . '/sess_qaf82s*') ?: [] as $f) {
        @unlink($f);
    }

    // Usuario QA propio en lugar del primero de la base.
    $uid = qa_user($conn, QA_SUITE, ['rol' => 'user', 'is_admin' => 0]);
    $conn->query("INSERT INTO boards (nombre, owner_user_id, visibility, created_at)
                  VALUES ('" . QA_TAG . "', $uid, 'private', NOW())");
    $bid = (int) $conn->insert_id;
    $conn->query("INSERT INTO board_members (board_id,user_id,rol) VALUES ($bid,$uid,'propietario')");
    $conn->query("INSERT INTO `columns` (board_id,nombre,orden,is_done,created_at)
                  VALUES ($bid,'En revisión',0,0,NOW())");
    $cid = (int) $conn->insert_id;
    $conn->query("INSERT INTO tasks (board_id,column_id,titulo,descripcion_md,prioridad,assignee_id,sort_order,creado_en)
                  VALUES ($bid,$cid,'Tarea de prueba F8.2','Una línea.','med',NULL,0,NOW())");

    $csrf = bin2hex(random_bytes(32));
    $sid  = 'qaf82s' . bin2hex(random_bytes(8));
    file_put_contents(SESSION_DIR . '/sess_' . $sid,
        'user_id|i:' . $uid . ';csrf|s:' . strlen($csrf) . ':"' . $csrf . '";_auth_ts|i:' . time() . ';');

    $tid = (int) $conn->query("SELECT id FROM tasks WHERE board_id=$bid LIMIT 1")->fetch_row()[0];

    $get = function (string $url) use ($sid): array {
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => 1, CURLOPT_TIMEOUT => 30,
            CURLOPT_COOKIE => 'PHPSESSID=' . $sid,
            CURLOPT_HTTPHEADER => ['X-Requested-With: fetch']]);
        $b = (string) curl_exec($ch);
        $s = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return [$s, $b];
    };

    [$st, $html] = $get(BASE_URL . '/tasks/drawer.php?id=' . $tid);

    chk('34. El cajón se sirve correctamente', $st === 200, "http=$st");

    chk('35. Muestra el estado real de la tarea',
        str_contains($html, 'data-drawer-estado') && str_contains($html, 'En revisión'),
        'nombre de la columna');

    chk('36. Una descripción de una línea rinde 3 filas',
        preg_match('/id="drawer_desc" rows="(\d+)"/', $html, $m) === 1 && $m[1] === '3',
        'rows=' . ($m[1] ?? '?') . ' (mínimo de 3)');

    // Guardar por el endpoint real y volver a leer.
    $texto = "Primera línea.\nSegunda.\nTercera.\nCuarta.\nQuinta.";
    $ch = curl_init(BASE_URL . '/tasks/update.php');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => 1, CURLOPT_POST => true,
        CURLOPT_TIMEOUT => 30, CURLOPT_COOKIE => 'PHPSESSID=' . $sid,
        CURLOPT_HTTPHEADER => ['X-Requested-With: fetch', 'Accept: application/json'],
        CURLOPT_POSTFIELDS => ['csrf' => $csrf, 'task_id' => $tid, 'board_id' => $bid,
            'prioridad' => 'high', 'fecha_limite' => '', 'assignee_id' => '',
            'descripcion_md' => $texto]]);
    $resp = (string) curl_exec($ch);
    curl_close($ch);
    $j = json_decode($resp, true);

    chk('37. La descripción se guarda por el endpoint real',
        ($j['ok'] ?? false) === true, substr($resp, 0, 70));

    [, $html2] = $get(BASE_URL . '/tasks/drawer.php?id=' . $tid);

    chk('38. Y vuelve a leerse íntegra',
        str_contains($html2, 'Quinta.') && str_contains($html2, 'Primera línea.'));

    chk('39. Con 5 líneas guardadas, el campo crece a 5 filas',
        preg_match('/id="drawer_desc" rows="(\d+)"/', $html2, $m2) === 1 && $m2[1] === '5',
        'rows=' . ($m2[1] ?? '?'));

    // ── limpieza ──
    $conn->query("DELETE FROM boards WHERE nombre LIKE '" . QA_TAG . "%'");
    // Usuarios QA de esta suite: se retiran por identificador junto con sus
    // tableros, equipos y avisos. Cubre tambien restos de una ejecucion
    // anterior que se interrumpiera antes de limpiar.
    qa_users_cleanup_stale($conn, QA_SUITE);
    foreach (glob(SESSION_DIR . '/sess_qaf82s*') ?: [] as $f) {
        @unlink($f);
    }
}

// ═════════════════════════════════════════════════════════════
echo "\n" . str_repeat('═', 78) . "\n";
printf(" RESULTADO: %d correctas, %d fallidas%s\n", $PASS, $FAIL,
    $PEND ? ", {$PEND} sin verificar" : '');
echo str_repeat('═', 78) . "\n\n";

exit($FAIL === 0 ? 0 : 1);
