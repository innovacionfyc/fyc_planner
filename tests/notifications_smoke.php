<?php
/**
 * tests/notifications_smoke.php
 *
 * Sistema de avisos: un único contenedor (Fase F8, bloque F8.4.1).
 *
 * Ejecutar SOLO en local:
 *   php tests/notifications_smoke.php
 *
 * Había dos elementos con id="toast": uno en workspace.php y otro en el HTML
 * que view.php?embed=1 inyecta dentro de #boardMount. getElementById devuelve
 * el primero del documento —el que vive en la zona que reloadBoard() reemplaza
 * entera—, así que un aviso podía desaparecer a media vida.
 *
 * Esta suite vigila que quede un solo contenedor, que se seleccione de forma
 * explícita y no por orden del DOM, y que viva fuera del contenido dinámico.
 *
 * Parte de las pruebas necesitan Apache y MySQL. No dejan residuos.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Este script solo puede ejecutarse desde CLI.\n");
}

$ROOT = dirname(__DIR__);
$JS   = $ROOT . '/public/assets/board-view.js';
$WS   = $ROOT . '/public/boards/workspace.php';
$VIEW = $ROOT . '/public/boards/view.php';
$CSS  = $ROOT . '/public/assets/theme.css';
$DRW  = $ROOT . '/public/tasks/drawer.php';

require_once $ROOT . '/config/bootstrap.php';
require_once $ROOT . '/public/_attachments.php';

const BASE_URL    = 'http://localhost/fyc_planner/public';
const SESSION_DIR = 'C:/laragon/tmp';
const QA_TAG      = 'QA F841 SMOKE';
// Etiqueta corta de esta suite. Entra en el correo de sus usuarios QA
// (qa.<suite>.<aleatorio>@local.test) y permite retirar restos de una
// ejecucion que se interrumpiera antes de limpiar.
const QA_SUITE    = 'notifs';

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

/** Cuerpo de una función de board-view.js, por llaves equilibradas. */
function cuerpoFuncion(string $js, string $nombre): string
{
    $ini = strpos($js, 'function ' . $nombre . '(');
    if ($ini === false) {
        return '';
    }
    $abre = strpos($js, '{', $ini);
    if ($abre === false) {
        return '';
    }
    $n = 0;
    $len = strlen($js);
    for ($i = $abre; $i < $len; $i++) {
        if ($js[$i] === '{') {
            $n++;
        } elseif ($js[$i] === '}') {
            $n--;
            if ($n === 0) {
                return substr($js, $abre, $i - $abre + 1);
            }
        }
    }
    return '';
}

echo "\n";
echo "══════════════════════════════════════════════════════════════════════════════\n";
echo " SISTEMA DE AVISOS · UN ÚNICO CONTENEDOR (bloque F8.4.1)\n";
echo "══════════════════════════════════════════════════════════════════════════════\n";
echo " PHP   : " . PHP_VERSION . "\n";
echo " Fecha : " . date('Y-m-d H:i:s') . "\n";

$leer = fn(string $p): string => str_replace("\r\n", "\n", (string) file_get_contents($p));

/**
 * Quita los bloques PHP para contar solo marcado real.
 *
 * Sin esto, un comentario que explica «aquí había un <div id="toast">» cuenta
 * como si el elemento siguiera ahí. Me ha pasado ya varias veces en este
 * proyecto: el detector confunde la explicación con lo explicado.
 */
function soloMarcado(string $php): string
{
    return (string) preg_replace('/<\?php.*?(\?>|$)/s', '', $php);
}
$js   = $leer($JS);
$ws   = $leer($WS);
$view = $leer($VIEW);
$css  = $leer($CSS);
$drw  = $leer($DRW);

// ═════════════════════════════════════════════════════════════
section('1-6 · UN SOLO CONTENEDOR, Y EN EL SITIO CORRECTO');

chk('1. workspace.php declara el contenedor una vez',
    substr_count($ws, 'id="toast"') === 1,
    substr_count($ws, 'id="toast"') . ' declaración(es)');

// El de view.php viajaba dentro del fragmento que se inyecta en #boardMount.
$viewMarcado = soloMarcado($view);
chk('2. view.php ya no declara ninguno',
    substr_count($viewMarcado, 'id="toast"') === 0
    && substr_count($viewMarcado, 'id="toast-msg"') === 0,
    'su HTML se inyecta en #boardMount, que se reemplaza entero');

chk('3. Y queda constancia de por qué se retiró',
    str_contains($view, 'reloadBoard() lo destruye'),
    'para que nadie lo reponga sin saberlo');

chk('4. El contenedor lleva marcador explícito',
    str_contains($ws, 'data-toast-global'),
    'seleccionar por él no depende del orden del DOM');

// Debe ser hijo directo del body, fuera de #boardMount.
chk('5. Vive fuera del contenido dinámico',
    preg_match('/<div id="boardMount".*?<div id="toast" data-toast-global/s', $ws) === 1
    && !preg_match('/id="boardMount"[^>]*>\s*(?:(?!<\/div>).)*id="toast"/s', $ws),
    'hermano de #boardMount, no hijo');

chk('6. Solo hay un id="toast-msg"',
    substr_count($ws, 'id="toast-msg"') === 1
    && substr_count($viewMarcado, 'id="toast-msg"') === 0);

// ═════════════════════════════════════════════════════════════
section('7-12 · SELECCIÓN EXPLÍCITA');

$sel = cuerpoFuncion($js, 'toastEl');

chk('7. Existe una función que localiza el contenedor', $sel !== '');

chk('8. Busca primero por el marcador explícito',
    str_contains($sel, "document.querySelector('[data-toast-global]')"));

// El respaldo debe descartar cualquiera dentro de la zona dinámica.
chk('9. Su respaldo descarta el contenido dinámico',
    str_contains($sel, "querySelectorAll('#toast')")
    && str_contains($sel, "closest('#boardMount')"),
    'si alguna vista repusiera otro, no se elegiría');

chk('10. showToast ya no usa getElementById',
    preg_match('/function showToast\([^)]*\)\s*\{\s*var t = toastEl\(\);/', $js) === 1
    && !preg_match('/[^\/]\s*var t = document\.getElementById\(\'toast\'\)/', $js),
    'la llamada ambigua desapareció');

// El mensaje debe buscarse DENTRO del contenedor elegido.
chk('11. El texto se escribe dentro del contenedor elegido',
    str_contains($js, "var msgEl = t.querySelector('#toast-msg') || inner;")
    && !str_contains($js, "document.getElementById('toast-msg')"),
    'no por id global');

$mostrar = cuerpoFuncion($js, 'showToast');
chk('12. El texto se fija ANTES de mostrar',
    strpos($mostrar, 'msgEl.textContent') < strpos($mostrar, "t.style.opacity       = '1'"),
    'evita que se lea el mensaje anterior');

// ═════════════════════════════════════════════════════════════
section('13-18 · CICLO DE VIDA DEL AVISO');

chk('13. Un temporizador pendiente se cancela antes de mostrar',
    str_contains($mostrar, 'clearTimeout(t._hideTimer);'),
    'un segundo mensaje sustituye al primero sin heredar su cuenta atrás');

chk('14. Se muestra y se programa el ocultado',
    str_contains($mostrar, "t.style.opacity       = '1'")
    && str_contains($mostrar, 't._hideTimer = setTimeout')
    && preg_match('/\}, 2800\);/', $mostrar) === 1,
    '2800 ms');

chk('15. Al ocultarse deja de capturar el puntero',
    preg_match('/t\.style\.pointerEvents = \'none\';\s*\}, 2800\);/s', $mostrar) === 1);

chk('16. El estilo de error se limpia en el mensaje siguiente',
    preg_match('/\} else \{\s*inner\.style\.background\s*= \'\';/s', $mostrar) === 1,
    'sin residuo rojo de un aviso anterior');

// Una sola definición y una sola vía de entrada.
chk('17. showToast se define una sola vez',
    substr_count($js, 'function showToast') === 1);

chk('18. El contenedor está por encima del cajón',
    preg_match('/#toast\s*\{[^}]*z-index:\s*300/s', $css) === 1
    && str_contains($ws, 'z-50'),
    'z-index 300 frente a 50 del cajón');

// ═════════════════════════════════════════════════════════════
section('19-23 · ACCESIBILIDAD');

chk('19. El contenedor anuncia su papel',
    preg_match('/id="toast" data-toast-global[^>]*role="status"/s', $ws) === 1);

chk('20. Con aria-live cortés por defecto',
    preg_match('/aria-live="polite"/', $ws) === 1);

chk('21. Y aria-atomic para que lea el mensaje entero',
    preg_match('/aria-atomic="true"/', $ws) === 1);

// Un error debe interrumpir; el resto espera turno.
chk('22. Un error eleva la urgencia del anuncio',
    str_contains($js, "t.setAttribute('aria-live', type === 'error' ? 'assertive' : 'polite');"));

// No se debe robar el foco: el aviso es informativo.
chk('23. El aviso no roba el foco',
    !preg_match('/toastEl\(\)[^;]*\.focus\(\)/', $js)
    && !preg_match('/t\.focus\(\)/', $mostrar),
    'no se mueve el foco al mostrarlo');

// ═════════════════════════════════════════════════════════════
section('24-29 · SIN REGRESIONES');

$diff = (string) shell_exec('git -C ' . escapeshellarg($ROOT) . ' diff HEAD 2>&1');
$lineas = preg_split('/\R/', $diff) ?: [];

$duenos = ['public/_attachments.php', 'public/tasks/attachment_upload.php',
    'public/tasks/attachment_delete.php', 'public/tasks/attachment_link.php',
    'public/tasks/attachment.php', 'public/tasks/comment_create.php'];
$tocados = array_values(array_filter($duenos, fn($f) =>
    trim((string) shell_exec('git -C ' . escapeshellarg($ROOT)
        . ' diff --name-only HEAD -- ' . escapeshellarg($f) . ' 2>&1')) !== ''));
chk('24. Endpoints intactos', $tocados === [],
    $tocados === [] ? count($duenos) . ' intactos' : implode(', ', $tocados));

chk('25. Los límites siguen siendo los del contrato',
    ATTACH_MAX_FILE_BYTES === 14 * 1024 * 1024
    && ATTACH_MAX_REQUEST_BYTES === 14 * 1024 * 1024
    && ATTACH_MAX_FILES === 5,
    '14 MB · 14 MB · 5 archivos');

chk('26. F8.2.1: sin doble inicialización',
    preg_match('/if \(state\.listenersInstalled\) return;/', $js) === 1
    && str_contains($js, 'runEmbedScripts(d.body)')
    && substr_count(cuerpoFuncion($js, 'loadDrawer'), 'runEmbedScripts(') === 1);

chk('27. F8.3 y F8.4 sin regresión',
    str_contains($drw, '<details class="fyc-attach-help">')
    && str_contains($drw, 'class="fyc-attach-row"')
    && str_contains($drw, '<div class="fyc-comments">')
    && str_contains($css, 'bloque superior compacto (F8.2)'));

$declaran = preg_grep('/^\+.*:\s*[^;{}]*!important\s*;/', $lineas);
chk('28. Ninguna declaración !important nueva', $declaran === [],
    $declaran === [] ? '0 declaraciones' : count($declaran) . ' declaraciones');

$hashArbol = trim((string) shell_exec('git -C ' . escapeshellarg($ROOT) . ' hash-object public/assets/app.css 2>&1'));
$hashHead  = trim((string) shell_exec('git -C ' . escapeshellarg($ROOT) . ' rev-parse HEAD:public/assets/app.css 2>&1'));
chk('29. app.css no fue modificado', $hashArbol === $hashHead && $hashArbol !== '',
    substr($hashArbol, 0, 16) . '…');

// ═════════════════════════════════════════════════════════════
section('30-35 · LO QUE SE SIRVE DE VERDAD');

require_once $ROOT . '/config/db.php';
require_once __DIR__ . '/_qa_users.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$conn->query("DELETE FROM boards WHERE nombre LIKE '" . QA_TAG . "%'");
// Usuarios QA de esta suite: se retiran por identificador junto con sus
// tableros, equipos y avisos. Cubre tambien restos de una ejecucion
// anterior que se interrumpiera antes de limpiar.
qa_users_cleanup_stale($conn, QA_SUITE);
foreach (glob(SESSION_DIR . '/sess_qaf841s*') ?: [] as $f) {
    @unlink($f);
}

// Usuario QA propio. Importa especialmente aqui: notifications NO tiene
// clave ajena hacia boards, asi que borrar el tablero no retiraba los avisos.
// Colgando de un usuario QA, desaparecen con el (users -> notifications
// CASCADE) y no queda nada en la bandeja de una persona real.
$uid = qa_user($conn, QA_SUITE, ['rol' => 'user', 'is_admin' => 0]);
$conn->query("INSERT INTO boards (nombre, owner_user_id, visibility, created_at)
              VALUES ('" . QA_TAG . "', $uid, 'private', NOW())");
$bid = (int) $conn->insert_id;
$conn->query("INSERT INTO board_members (board_id,user_id,rol) VALUES ($bid,$uid,'propietario')");
$conn->query("INSERT INTO `columns` (board_id,nombre,orden,is_done,created_at)
              VALUES ($bid,'En curso',0,0,NOW())");

$sid = 'qaf841s' . bin2hex(random_bytes(8));
$csrf = bin2hex(random_bytes(32));
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

[$sW, $hW] = $pedir(BASE_URL . '/boards/workspace.php?board=' . $bid);
chk('30. El workspace se sirve', $sW === 200, "http=$sW");

chk('31. Y trae exactamente un contenedor',
    preg_match_all('/id="toast"/', $hW) === 1
    && preg_match_all('/id="toast-msg"/', $hW) === 1
    && preg_match_all('/data-toast-global/', $hW) === 1);

[$sE, $hE] = $pedir(BASE_URL . '/boards/view.php?id=' . $bid . '&embed=1');
chk('32. El fragmento del tablero no trae ninguno',
    $sE === 200 && preg_match_all('/id="toast"/', $hE) === 0,
    'antes aportaba el duplicado');

// Sumados —que es como quedan en la página— sigue habiendo uno solo.
chk('33. Sumando ambos sigue habiendo uno solo',
    preg_match_all('/id="toast"/', $hW) + preg_match_all('/id="toast"/', $hE) === 1);

chk('34. Los atributos de accesibilidad llegan al navegador',
    preg_match('/role="status"/', $hW) === 1
    && preg_match('/aria-live="polite"/', $hW) === 1
    && preg_match('/aria-atomic="true"/', $hW) === 1);

// admin/teams.php es una página aparte: su propio toast, su propio JS y nunca
// coincide en la misma página con éste. No se toca.
$teams = $leer($ROOT . '/public/admin/teams.php');
chk('35. La página de equipos conserva su aviso independiente',
    substr_count($teams, 'id="toast"') === 1
    && !str_contains($teams, 'board-view.js'),
    'nunca coexiste con el del workspace');

$conn->query("DELETE FROM boards WHERE nombre LIKE '" . QA_TAG . "%'");
// Usuarios QA de esta suite: se retiran por identificador junto con sus
// tableros, equipos y avisos. Cubre tambien restos de una ejecucion
// anterior que se interrumpiera antes de limpiar.
qa_users_cleanup_stale($conn, QA_SUITE);
foreach (glob(SESSION_DIR . '/sess_qaf841s*') ?: [] as $f) {
    @unlink($f);
}

// ═════════════════════════════════════════════════════════════
echo "\n" . str_repeat('═', 78) . "\n";
printf(" RESULTADO: %d correctas, %d fallidas\n", $PASS, $FAIL);
echo str_repeat('═', 78) . "\n\n";

exit($FAIL === 0 ? 0 : 1);
