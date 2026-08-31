<?php
/**
 * tests/drawer_lifecycle_smoke.php
 *
 * Ciclo de vida del contenido dinámico del cajón (Fase F8, bloque F8.2.1).
 *
 * Ejecutar SOLO en local:
 *   php tests/drawer_lifecycle_smoke.php
 *
 * El cajón se inserta con innerHTML, y el navegador no ejecuta los <script>
 * que llegan por esa vía. Esta suite vigila que se ejecuten después de cargar,
 * que repetirlo no acumule manejadores, que Cancelar esté delegado donde el
 * clic llega de verdad, y que las etiquetas fallen limpiamente cuando sus
 * tablas no existen.
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
$DRW  = $ROOT . '/public/tasks/drawer.php';
$TAG  = $ROOT . '/public/tags/tag_action.php';
$VIEW = $ROOT . '/public/boards/view.php';
$CSS  = $ROOT . '/public/assets/theme.css';

const BASE_URL    = 'http://localhost/fyc_planner/public';
const SESSION_DIR = 'C:/laragon/tmp';
const QA_TAG      = 'QA F821 SMOKE';
// Etiqueta corta de esta suite. Entra en el correo de sus usuarios QA
// (qa.<suite>.<aleatorio>@local.test) y permite retirar restos de una
// ejecucion que se interrumpiera antes de limpiar.
const QA_SUITE    = 'drawerlife';

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

/** Contenido de todos los <script> en línea de una plantilla PHP. */
function scriptsEnLinea(string $html): array
{
    return preg_match_all('/<script>(.*?)<\/script>/s', $html, $m) ? $m[1] : [];
}

echo "\n";
echo "══════════════════════════════════════════════════════════════════════════════\n";
echo " CICLO DE VIDA DEL CAJÓN DINÁMICO (bloque F8.2.1)\n";
echo "══════════════════════════════════════════════════════════════════════════════\n";
echo " PHP   : " . PHP_VERSION . "\n";
echo " Fecha : " . date('Y-m-d H:i:s') . "\n";

$leer = fn(string $p): string => str_replace("\r\n", "\n", (string) file_get_contents($p));

$js   = $leer($JS);
$drw  = $leer($DRW);
$tag  = $leer($TAG);
$view = $leer($VIEW);
$css  = $leer($CSS);

// ═════════════════════════════════════════════════════════════
section('1-6 · EJECUCIÓN DESPUÉS DE LA CARGA DINÁMICA');

$load = cuerpoFuncion($js, 'loadDrawer');

chk('1. loadDrawer se localiza', $load !== '');

chk('2. Sigue insertando el cajón con innerHTML',
    str_contains($load, 'd.body.innerHTML = html'),
    'la inserción no cambia; lo que faltaba era el paso siguiente');

chk('3. Ejecuta los scripts justo después de insertarlos',
    preg_match('/d\.body\.innerHTML = html;\s*(\/\/[^\n]*\n\s*)*runEmbedScripts\(d\.body\);/', $load) === 1,
    'runEmbedScripts(d.body)');

chk('4. Los ejecuta sobre el cajón, no sobre el tablero',
    str_contains($load, 'runEmbedScripts(d.body)')
    && !str_contains($load, 'runEmbedScripts(state.root)'),
    'state.root no contiene el cajón');

chk('5. Una sola llamada dentro de loadDrawer',
    substr_count($load, 'runEmbedScripts(') === 1,
    'ejecutar dos veces duplicaría cualquier efecto del script');

// El tablero conserva su propia llamada, que es independiente.
chk('6. El tablero mantiene la suya intacta',
    str_contains($js, 'runEmbedScripts(state.root);'),
    'recarga del tablero sin cambios');

// ═════════════════════════════════════════════════════════════
section('7-11 · QUÉ SE EJECUTA Y QUÉ NO');

$run = cuerpoFuncion($js, 'runEmbedScripts');

chk('7. Solo scripts JS en línea',
    str_contains($run, 'script:not([type]),script[type="text/javascript"]'),
    'excluye application/json y similares');

chk('8. Se saltan los scripts con src',
    preg_match('/if \(s\.src\) return;/', $run) === 1,
    'nada de código traído de un tercero');

chk('9. Solo se copia el texto, nunca el src',
    str_contains($run, 'n.textContent = s.textContent')
    && !preg_match('/n\.src\s*=/', $run));

// La plantilla del cajón es lo único que se ejecuta por esta vía.
chk('10. El origen es una plantilla propia',
    str_contains($load, "fetch('../tasks/drawer.php?id='"),
    'drawer.php, servido por la propia aplicación');

chk('11. El cajón no carga scripts externos',
    !preg_match('/<script[^>]+src=/i', $drw),
    'ningún <script src> en drawer.php');

// ═════════════════════════════════════════════════════════════
section('12-16 · POR QUÉ REPETIRLO NO DUPLICA MANEJADORES');

// Ésta es LA propiedad de la que depende la seguridad del arreglo: los
// scripts del cajón solo se enlazan a elementos que el propio innerHTML
// destruye y reconstruye. Si alguno se enlazara a document o a window,
// cada apertura dejaría un manejador más y las acciones se dispararían
// varias veces. Está comprobado en navegador: un script enlazado a
// document sí acumula.
$sc = scriptsEnLinea($drw);

chk('12. El cajón trae los dos scripts esperados',
    count($sc) === 2,
    count($sc) . ' scripts: etiquetas y Ctrl+Enter');

$todo = implode("\n", $sc);

chk('13. Ningún script del cajón se enlaza a document',
    !preg_match('/document\s*\.\s*addEventListener/', $todo),
    'sería la única forma de acumular manejadores');

chk('14. Ningún script del cajón se enlaza a window',
    !preg_match('/window\s*\.\s*addEventListener/', $todo));

// Todo enlace debe ser sobre una variable que apunta a un elemento del cajón.
preg_match_all('/(\w+)\s*\.\s*addEventListener/', $todo, $mm);
$destinos = array_values(array_unique($mm[1] ?? []));
$globales = array_values(array_intersect($destinos, ['document', 'window', 'body']));
chk('15. Todos los enlaces son a elementos del propio cajón',
    $globales === [],
    $globales === [] ? implode(', ', $destinos) : 'globales: ' . implode(', ', $globales));

chk('16. Los scripts van en funciones autoejecutadas',
    substr_count($todo, '(function(){') >= 2,
    'sin variables sueltas que se pisen entre cargas');

// ═════════════════════════════════════════════════════════════
section('17-21 · CONTROLES DEL CAJÓN');

$install = cuerpoFuncion($js, 'installListenersOnce');

chk('17. Los manejadores se instalan una sola vez',
    preg_match('/if \(state\.listenersInstalled\) return;\s*state\.listenersInstalled = true;/', $install) === 1,
    'guarda ya existente, comprobada');

// Cancelar estaba en root —el contenedor del tablero— y el cajón vive fuera.
chk('18. Cancelar está delegado en document',
    preg_match('/document\.addEventListener\(\'click\', function \(ev\) \{\s*var btn = ev\.target\.closest && ev\.target\.closest\(\'\[data-action="drawer-cancel"\]\'\)/', $install) === 1,
    'antes en root: el clic no llegaba nunca');

chk('19. Ya no queda ningún manejador del cajón colgado de root',
    !preg_match('/root\.addEventListener\([^)]*\)[^;]*\{[^}]*drawer-(cancel|save|close)/s', $install),
    'root no contiene #taskDrawer');

chk('20. Guardar y comentar siguen en document',
    substr_count($install, "closest('[data-action=\"drawer-save\"]')") === 1
    && substr_count($install, "closest('[data-action=\"drawer-add-comment\"]')") === 1);

chk('21. Escape, fondo y botón ✕ intactos',
    str_contains($install, "closest('[data-drawer-close]')")
    && str_contains($install, "ev.target.id === 'taskDrawerOverlay'")
    && str_contains($install, "if (ev.key !== 'Escape') return;"),
    'las tres vías que ya funcionaban');

// ═════════════════════════════════════════════════════════════
section('22-25 · UNA SOLA PUERTA PARA RECARGAR EL CAJÓN');

chk('22. loadDrawer se expone en la API pública',
    preg_match('/window\.FCPlannerBoard\.loadDrawer\s*=\s*loadDrawer;/', $js) === 1);

// El script de etiquetas hacía su propio fetch + innerHTML, y esa segunda
// vía volvía a dejar sin ejecutar el propio script que la había lanzado.
chk('23. El script de etiquetas ya no hace su propio fetch del cajón',
    !preg_match('/fetch\(\'\.\.\/tasks\/drawer\.php\?id=\'\+taskId/', $drw),
    'la vía duplicada desapareció');

chk('24. Recarga por la API común',
    str_contains($drw, 'window.FCPlannerBoard.loadDrawer(taskId)'));

chk('25. Y comprueba que la API exista antes de usarla',
    preg_match('/typeof window\.FCPlannerBoard\.loadDrawer === \'function\'/', $drw) === 1);

// ═════════════════════════════════════════════════════════════
section('26-30 · ETIQUETAS SIN SUS TABLAS');

chk('26. El cajón sigue comprobando si la tabla existe',
    str_contains($drw, "SHOW TABLES LIKE 'task_tags'")
    && str_contains($drw, 'if ($hasTags)'),
    'resiliencia de esquema, sin cambios');

chk('27. El tablero también la comprueba',
    str_contains($view, "SHOW TABLES LIKE 'task_tags'"));

chk('28. El endpoint de etiquetas ahora también',
    str_contains($tag, "SHOW TABLES LIKE '\" . \$tablaEtiquetas . \"'")
    && str_contains($tag, "foreach (['task_tags', 'task_tag_pivot'] as \$tablaEtiquetas)"),
    'antes moría con un 500 de cuerpo vacío');

chk('29. Comprueba las dos tablas, no solo una',
    str_contains($tag, "'task_tags', 'task_tag_pivot'"),
    'la lista se lee de task_tags y el pivote se escribe aparte');

chk('30. La guarda va después de CSRF y permisos',
    strpos($tag, "fail('CSRF inválido')") < strpos($tag, '$tablaEtiquetas')
    && strpos($tag, "fail('Sin acceso al tablero')") < strpos($tag, '$tablaEtiquetas'),
    'no se filtra el estado del esquema a quien no puede escribir');

// ═════════════════════════════════════════════════════════════
section('31-35 · NADA DE ADJUNTOS NI DE ESTILO');

$diff = (string) shell_exec('git -C ' . escapeshellarg($ROOT) . ' diff HEAD 2>&1');
$lineas = preg_split('/\R/', $diff) ?: [];

// Buscar por palabra en todo el diff confunde «usar la constante» con
// «cambiarla»: F8.3 reordena el bloque de adjuntos del cajón y sus líneas
// aparecen quitadas y puestas. Lo que hay que demostrar es que los archivos
// dueños del contrato no se han tocado.
$duenosAdjuntos = ['public/_attachments.php', 'public/tasks/attachment_upload.php',
    'public/tasks/attachment_delete.php', 'public/tasks/attachment_link.php',
    'public/tasks/attachment.php'];
$tocados = array_values(array_filter($duenosAdjuntos, fn($f) =>
    trim((string) shell_exec('git -C ' . escapeshellarg($ROOT)
        . ' diff --name-only HEAD -- ' . escapeshellarg($f) . ' 2>&1')) !== ''));
chk('31. Los archivos dueños de límites y endpoints están intactos',
    $tocados === [],
    $tocados === [] ? count($duenosAdjuntos) . ' intactos' : 'tocados: ' . implode(', ', $tocados));

chk('32. Las constantes del cliente siguen en 14 MB',
    preg_match('/var ATTACH_MAX_FILE_BYTES\s*=\s*14 \* 1024 \* 1024;/', $js) === 1
    && preg_match('/var ATTACH_MAX_REQUEST_BYTES\s*=\s*14 \* 1024 \* 1024;/', $js) === 1);

// Solo cuenta como uso real una DECLARACIÓN (propiedad: valor !important;).
// Buscar la palabra suelta daba falso positivo con los comentarios de F8.1 y
// F8.2, que explican precisamente por qué no hace falta usarlo.
$mencionan = preg_grep('/^\+.*!important/', $lineas);
$declaran  = preg_grep('/^\+.*:\s*[^;{}]*!important\s*;/', $lineas);
chk('33. No se añadió ninguna declaración !important',
    $declaran === [],
    $declaran === []
        ? '0 declaraciones · ' . count($mencionan) . ' menciones en comentarios'
        : count($declaran) . ' declaraciones');

// theme.css sí aparece en el diff contra HEAD, pero por F8.1 y F8.2, que
// siguen sin confirmar. Lo que este bloque debe demostrar es que NO añadió
// estilo: sus dos bloques anteriores intactos y ninguno nuevo.
chk('34. F8.2.1 no añadió estilo: es un bloque funcional',
    str_contains($css, 'ancho responsivo (F8.1)')
    && str_contains($css, 'bloque superior compacto (F8.2)')
    && !preg_match('/F8\.2\.1/', $css),
    'F8.1 y F8.2 presentes · ningún bloque F8.2.1');

$hashArbol = trim((string) shell_exec('git -C ' . escapeshellarg($ROOT) . ' hash-object public/assets/app.css 2>&1'));
$hashHead  = trim((string) shell_exec('git -C ' . escapeshellarg($ROOT) . ' rev-parse HEAD:public/assets/app.css 2>&1'));
chk('35. app.css no fue modificado', $hashArbol === $hashHead && $hashArbol !== '',
    substr($hashArbol, 0, 16) . '…');

// ═════════════════════════════════════════════════════════════
section('36-42 · COMPROBACIÓN REAL POR HTTP');

$httpOk = false;
if (is_file($ROOT . '/config/db.php')) {
    require_once $ROOT . '/config/bootstrap.php';
    require_once $ROOT . '/config/db.php';
    require_once __DIR__ . '/_qa_users.php';
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    $httpOk = true;
}

if (!$httpOk) {
    pend('36-42. Comprobación por HTTP', 'sin conexión a la base de datos');
} else {
    $conn->query("DELETE FROM boards WHERE nombre LIKE '" . QA_TAG . "%'");
    // Usuarios QA de esta suite: se retiran por identificador junto con sus
    // tableros, equipos y avisos. Cubre tambien restos de una ejecucion
    // anterior que se interrumpiera antes de limpiar.
    qa_users_cleanup_stale($conn, QA_SUITE);
    foreach (glob(SESSION_DIR . '/sess_qaf821s*') ?: [] as $f) {
        @unlink($f);
    }

    // Usuario QA propio en lugar del primero de la base.
    $uid = qa_user($conn, QA_SUITE, ['rol' => 'user', 'is_admin' => 0]);
    $conn->query("INSERT INTO boards (nombre, owner_user_id, visibility, created_at)
                  VALUES ('" . QA_TAG . "', $uid, 'private', NOW())");
    $bid = (int) $conn->insert_id;
    $conn->query("INSERT INTO board_members (board_id,user_id,rol) VALUES ($bid,$uid,'propietario')");
    $conn->query("INSERT INTO `columns` (board_id,nombre,orden,is_done,created_at)
                  VALUES ($bid,'En curso',0,0,NOW())");
    $cid = (int) $conn->insert_id;
    $conn->query("INSERT INTO tasks (board_id,column_id,titulo,descripcion_md,prioridad,assignee_id,sort_order,creado_en)
                  VALUES ($bid,$cid,'Tarea F8.2.1','Prueba.','med',NULL,0,NOW())");
    $tid = (int) $conn->insert_id;

    $csrf = bin2hex(random_bytes(32));
    $sid  = 'qaf821s' . bin2hex(random_bytes(8));
    file_put_contents(SESSION_DIR . '/sess_' . $sid,
        'user_id|i:' . $uid . ';csrf|s:' . strlen($csrf) . ':"' . $csrf . '";_auth_ts|i:' . time() . ';');

    $pedir = function (string $url, array $post = null, bool $json = false) use ($sid): array {
        $ch = curl_init($url);
        $h  = ['X-Requested-With: fetch', 'Accept: application/json'];
        $o  = [CURLOPT_RETURNTRANSFER => 1, CURLOPT_TIMEOUT => 30,
            CURLOPT_COOKIE => 'PHPSESSID=' . $sid];
        if ($post !== null) {
            $o[CURLOPT_POST] = true;
            $o[CURLOPT_POSTFIELDS] = $json ? json_encode($post) : $post;
            if ($json) {
                $h[] = 'Content-Type: application/json';
            }
        }
        $o[CURLOPT_HTTPHEADER] = $h;
        curl_setopt_array($ch, $o);
        $b = (string) curl_exec($ch);
        $s = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return [$s, $b];
    };

    [$st, $html] = $pedir(BASE_URL . '/tasks/drawer.php?id=' . $tid);
    chk('36. El cajón se sirve', $st === 200, "http=$st");

    $nScripts = preg_match_all('/<script>/', $html);
    $hayTags = (bool) $conn->query("SHOW TABLES LIKE 'task_tags'")->fetch_row();
    chk('37. Trae el script de Ctrl+Enter',
        str_contains($html, "ta.addEventListener('keydown'")
        && str_contains($html, "e.ctrlKey || e.metaKey"),
        $nScripts . ' script(s) · etiquetas ' . ($hayTags ? 'presentes' : 'ausentes'));

    chk('38. Con las tablas ausentes, el cajón no ofrece etiquetas',
        $hayTags ? true : (!str_contains($html, 'id="tagList"')
            && !str_contains($html, 'id="btnShowCreateTag"')),
        $hayTags ? 'tablas presentes: no aplica' : 'sin controles de etiqueta');

    // El endpoint debe responder JSON, nunca un 500 mudo.
    [$s2, $b2] = $pedir(BASE_URL . '/tags/tag_action.php',
        ['action' => 'create', 'board_id' => $bid, 'nombre' => 'QA', 'csrf' => $csrf], true);
    $j2 = json_decode($b2, true);
    chk('39. El endpoint de etiquetas responde JSON siempre',
        $s2 === 200 && is_array($j2) && array_key_exists('ok', $j2),
        "http=$s2 " . substr(trim($b2), 0, 60));

    if (!$hayTags) {
        chk('40. Y explica que no están disponibles',
            ($j2['ok'] ?? true) === false
            && str_contains((string) ($j2['error'] ?? ''), 'no están disponibles'));
    } else {
        pend('40. Mensaje de tablas ausentes', 'las tablas existen en esta base');
    }

    // Una petición, una fila: el ciclo no debe multiplicar mutaciones.
    $antes = (int) $conn->query("SELECT COUNT(*) FROM comments WHERE task_id=$tid")->fetch_row()[0];
    [$s3, $b3] = $pedir(BASE_URL . '/tasks/comment_create.php',
        ['csrf' => $csrf, 'task_id' => $tid, 'board_id' => $bid, 'body' => 'Comentario único F8.2.1']);
    $despues = (int) $conn->query("SELECT COUNT(*) FROM comments WHERE task_id=$tid")->fetch_row()[0];
    chk('41. Una petición de comentario crea exactamente una fila',
        ($j3 = json_decode($b3, true)) && ($j3['ok'] ?? false) === true && ($despues - $antes) === 1,
        "http=$s3 filas +" . ($despues - $antes));

    chk('42. El cajón vuelve a servirse tras la mutación',
        $pedir(BASE_URL . '/tasks/drawer.php?id=' . $tid)[0] === 200);

    $conn->query("DELETE FROM boards WHERE nombre LIKE '" . QA_TAG . "%'");
    // Usuarios QA de esta suite: se retiran por identificador junto con sus
    // tableros, equipos y avisos. Cubre tambien restos de una ejecucion
    // anterior que se interrumpiera antes de limpiar.
    qa_users_cleanup_stale($conn, QA_SUITE);
    foreach (glob(SESSION_DIR . '/sess_qaf821s*') ?: [] as $f) {
        @unlink($f);
    }
}

// ═════════════════════════════════════════════════════════════
echo "\n" . str_repeat('═', 78) . "\n";
printf(" RESULTADO: %d correctas, %d fallidas%s\n", $PASS, $FAIL,
    $PEND ? ", {$PEND} sin verificar" : '');
echo str_repeat('═', 78) . "\n\n";

exit($FAIL === 0 ? 0 : 1);
