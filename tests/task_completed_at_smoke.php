<?php
/**
 * tests/task_completed_at_smoke.php
 *
 * Origen de tasks.completed_at: que ninguna tarea acabe en la columna de
 * «hecho» sin fecha de finalización.
 *
 * Ejecutar SOLO en local:
 *   php tests/task_completed_at_smoke.php
 *
 * POR QUÉ EXISTE
 *   completed_at solo se escribía en tasks/move.php, al arrastrar. Una tarea
 *   creada directamente dentro de la columna terminada, o ya presente cuando
 *   esa columna se marcó como «hecho», se quedaba con la fecha vacía. Como los
 *   reportes (admin/stats.php, export_excel.php, _alerts_core.php) usan
 *   `completed_at IS NULL` para saber qué sigue pendiente, esas tareas
 *   contaban como pendientes para siempre. En producción eran 35 de 173.
 *
 *   Esta suite vigila las dos vías corregidas y deja constancia de las que
 *   siguen abiertas, para que la deuda sea visible y no se olvide.
 *
 * Necesita Apache y MySQL. Crea un tablero temporal y lo borra al terminar.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Este script solo puede ejecutarse desde CLI.\n");
}

$ROOT = dirname(__DIR__);

require_once $ROOT . '/config/bootstrap.php';
require_once $ROOT . '/config/db.php';
require_once __DIR__ . '/_qa_users.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

const QA_TAG      = 'QA COMPLETEDAT';
// Etiqueta corta de esta suite. Entra en el correo de sus usuarios QA
// (qa.<suite>.<aleatorio>@local.test) y permite retirar restos de una
// ejecucion que se interrumpiera antes de limpiar.
const QA_SUITE    = 'completedat';
const BASE_URL    = 'http://localhost/fyc_planner/public';
const SESSION_DIR = 'C:/laragon/tmp';

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

/** POST al endpoint real. $json=true para los que hablan JSON. */
function post(string $url, array $campos, string $sid, bool $json = false): array
{
    $ch = curl_init($url);
    $h = ['X-Requested-With: fetch', 'Accept: application/json'];
    if ($json) {
        $h[] = 'Content-Type: application/json';
    }
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => 1, CURLOPT_POST => true, CURLOPT_TIMEOUT => 30,
        CURLOPT_POSTFIELDS => $json ? json_encode($campos) : $campos,
        CURLOPT_COOKIE => 'PHPSESSID=' . $sid, CURLOPT_HTTPHEADER => $h]);
    $b = (string) curl_exec($ch);
    $s = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$s, $b];
}

function limpiar(mysqli $conn): int
{
    $conn->query("DELETE FROM boards WHERE nombre LIKE '" . QA_TAG . "%'");
    $n = $conn->affected_rows;
    // Usuarios QA de esta suite: se retiran por identificador junto con sus
    // tableros, equipos y avisos. Cubre tambien restos de una ejecucion
    // anterior que se interrumpiera antes de limpiar.
    qa_users_cleanup_stale($conn, QA_SUITE);
    foreach (glob(SESSION_DIR . '/sess_qacompat*') ?: [] as $f) {
        @unlink($f);
    }
    return $n;
}

echo "\n";
echo "══════════════════════════════════════════════════════════════════════════════\n";
echo " ORIGEN DE completed_at — NINGUNA TAREA TERMINADA SIN FECHA\n";
echo "══════════════════════════════════════════════════════════════════════════════\n";
echo ' Base   : ' . $conn->query('SELECT DATABASE()')->fetch_row()[0] . "\n";
echo ' Motor  : ' . $conn->server_info . "\n";
echo ' PHP    : ' . PHP_VERSION . "\n";
echo ' Fecha  : ' . date('Y-m-d H:i:s') . "\n";

section('PREPARACIÓN');
$pre = limpiar($conn);
echo "  restos anteriores: $pre tableros\n";

$countBefore = [];
foreach (['boards', 'columns', 'tasks'] as $t) {
    $countBefore[$t] = (int) $conn->query("SELECT COUNT(*) FROM `$t`")->fetch_row()[0];
}

// Usuario QA propio en lugar del primero de la base.
$uid = qa_user($conn, QA_SUITE, ['rol' => 'user', 'is_admin' => 0]);
$conn->query("INSERT INTO boards (nombre, owner_user_id, visibility, created_at)
              VALUES ('" . QA_TAG . "', $uid, 'private', NOW())");
$BOARD = (int) $conn->insert_id;
$conn->query("INSERT INTO board_members (board_id,user_id,rol) VALUES ($BOARD,$uid,'propietario')");

$conn->query("INSERT INTO `columns` (board_id,nombre,orden,is_done,created_at)
              VALUES ($BOARD,'Por hacer',0,0,NOW())");
$COL_NORMAL = (int) $conn->insert_id;
$conn->query("INSERT INTO `columns` (board_id,nombre,orden,is_done,created_at)
              VALUES ($BOARD,'Futura hecho',1,0,NOW())");
$COL_FUTURA = (int) $conn->insert_id;
$conn->query("INSERT INTO `columns` (board_id,nombre,orden,is_done,created_at)
              VALUES ($BOARD,'Hecho',2,1,NOW())");
$COL_HECHO = (int) $conn->insert_id;

$csrf = bin2hex(random_bytes(32));
$sid  = 'qacompat' . bin2hex(random_bytes(8));
file_put_contents(SESSION_DIR . '/sess_' . $sid,
    'user_id|i:' . $uid . ';csrf|s:' . strlen($csrf) . ':"' . $csrf . '";_auth_ts|i:' . time() . ';');

printf("  tablero=%d  normal=%d  futura=%d  hecho=%d\n", $BOARD, $COL_NORMAL, $COL_FUTURA, $COL_HECHO);

// ═════════════════════════════════════════════════════════════
section('1-4 · CREAR UNA TAREA (tasks/create.php)');

[$s, ] = post(BASE_URL . '/tasks/create.php',
    ['csrf' => $csrf, 'board_id' => $BOARD, 'column_id' => $COL_HECHO, 'titulo' => QA_TAG . ' directa'], $sid);
$t1 = (int) ($conn->query("SELECT id FROM tasks WHERE board_id=$BOARD AND titulo LIKE '%directa%' LIMIT 1")->fetch_row()[0] ?? 0);
$c1 = $t1 ? $conn->query("SELECT completed_at FROM tasks WHERE id=$t1")->fetch_row()[0] : null;

chk('1. La tarea se crea en la columna de hecho', $s === 200 && $t1 > 0, "http=$s");
chk('2. Nace CON fecha de finalización', $c1 !== null,
    'completed_at=' . var_export($c1, true));
chk('3. Y esa fecha es de ahora, no una inventada',
    $c1 !== null && abs(strtotime((string) $c1) - time()) < 300,
    'la tarea se termina en el momento de crearla');

post(BASE_URL . '/tasks/create.php',
    ['csrf' => $csrf, 'board_id' => $BOARD, 'column_id' => $COL_NORMAL, 'titulo' => QA_TAG . ' normal'], $sid);
$t2 = (int) ($conn->query("SELECT id FROM tasks WHERE board_id=$BOARD AND titulo LIKE '%normal%' LIMIT 1")->fetch_row()[0] ?? 0);
$c2 = $t2 ? $conn->query("SELECT completed_at FROM tasks WHERE id=$t2")->fetch_row()[0] : 'x';
chk('4. En una columna normal sigue naciendo SIN fecha', $c2 === null,
    'completed_at=' . var_export($c2, true));

// ═════════════════════════════════════════════════════════════
section('5-11 · MARCAR UNA COLUMNA COMO HECHO (columns/column_action.php)');

$ids = [];
foreach ([['A', '2026-03-15 17:30:00'], ['B', '2026-03-16 10:00:00'], ['C', null]] as [$n, $upd]) {
    $st = $conn->prepare("INSERT INTO tasks (board_id,column_id,titulo,prioridad,sort_order,creado_en,updated_at)
                          VALUES (?,?,?,'med',0,'2026-01-10 09:00:00',?)");
    $titulo = QA_TAG . ' antigua ' . $n;
    $st->bind_param('iiss', $BOARD, $COL_FUTURA, $titulo, $upd);
    $st->execute();
    $ids[$n] = (int) $conn->insert_id;
}
// B ya tiene fecha: la corrección no debe pisarla.
$conn->query("UPDATE tasks SET completed_at='2026-02-02 08:00:00' WHERE id={$ids['B']}");

$sinAntes = (int) $conn->query("SELECT COUNT(*) FROM tasks WHERE column_id=$COL_FUTURA AND completed_at IS NULL")->fetch_row()[0];
chk('5. De partida hay tareas sin fecha en esa columna', $sinAntes === 2, "sin fecha=$sinAntes");

[$s2, $b2] = post(BASE_URL . '/columns/column_action.php',
    ['action' => 'set_done', 'board_id' => $BOARD, 'column_id' => $COL_FUTURA, 'is_done' => 1, 'csrf' => $csrf],
    $sid, true);
$j = json_decode($b2, true);

chk('6. La acción se completa', ($j['ok'] ?? false) === true, trim(substr($b2, 0, 60)));
chk('7. Informa cuántas fechas rellenó', ($j['completed_at_rellenados'] ?? -1) === 2,
    'rellenadas=' . var_export($j['completed_at_rellenados'] ?? null, true));

$sinDespues = (int) $conn->query("SELECT COUNT(*) FROM tasks WHERE column_id=$COL_FUTURA AND completed_at IS NULL")->fetch_row()[0];
chk('8. No queda ninguna tarea terminada sin fecha', $sinDespues === 0, "sin fecha=$sinDespues");

// El criterio importa: una fecha del pasado, no la de ahora.
$cA = $conn->query("SELECT completed_at FROM tasks WHERE id={$ids['A']}")->fetch_row()[0];
chk('9. Toma updated_at, no la hora de marcar la columna',
    $cA === '2026-03-15 17:30:00', "A=$cA");

$cC = $conn->query("SELECT completed_at FROM tasks WHERE id={$ids['C']}")->fetch_row()[0];
chk('10. Si updated_at está vacío, recurre a creado_en',
    $cC === '2026-01-10 09:00:00', "C=$cC");

$cB = $conn->query("SELECT completed_at FROM tasks WHERE id={$ids['B']}")->fetch_row()[0];
chk('11. Nunca pisa una fecha que ya existía', $cB === '2026-02-02 08:00:00', "B=$cB");

// ═════════════════════════════════════════════════════════════
section('12-17 · EL LOTE: updated_at REPETIDO NO ES FECHA DE FINALIZACIÓN');

// En producción, 19 tareas de un mismo tablero compartían updated_at al
// segundo exacto: la huella de una operación masiva, no la fecha en que se
// terminaron. Usarla las amontonaría todas en la misma semana, que es
// justamente lo que el filtro por fecha viene a evitar.
$conn->query("INSERT INTO `columns` (board_id,nombre,orden,is_done,created_at)
              VALUES ($BOARD,'Con lote',4,0,NOW())");
$COL_LOTE = (int) $conn->insert_id;

$LOTE_TS = '2026-07-27 10:40:44';
$idsLote = [];
// Tres tareas con el MISMO updated_at (el lote) y creados en días distintos.
foreach ([['L1', '2026-06-18 08:00:00'], ['L2', '2026-06-21 12:00:00'], ['L3', '2026-06-25 16:00:00']] as [$n, $cre]) {
    $st = $conn->prepare("INSERT INTO tasks (board_id,column_id,titulo,prioridad,sort_order,creado_en,updated_at)
                          VALUES (?,?,?,'med',0,?,?)");
    $titulo = QA_TAG . ' ' . $n;
    $st->bind_param('iisss', $BOARD, $COL_LOTE, $titulo, $cre, $LOTE_TS);
    $st->execute();
    $idsLote[$n] = (int) $conn->insert_id;
}
// Y una con updated_at único y creíble: esa SÍ debe usar updated_at.
$st = $conn->prepare("INSERT INTO tasks (board_id,column_id,titulo,prioridad,sort_order,creado_en,updated_at)
                      VALUES (?,?,?,'med',0,'2026-06-20 09:00:00','2026-06-20 18:45:00')");
$tituloU = QA_TAG . ' unica';
$st->bind_param('iis', $BOARD, $COL_LOTE, $tituloU);
$st->execute();
$idUnica = (int) $conn->insert_id;

[, $bL] = post(BASE_URL . '/columns/column_action.php',
    ['action' => 'set_done', 'board_id' => $BOARD, 'column_id' => $COL_LOTE, 'is_done' => 1, 'csrf' => $csrf],
    $sid, true);
$jL = json_decode($bL, true);

chk('12. Detecta que hay un lote', ($jL['lotes_detectados'] ?? -1) === 1,
    'lotes=' . var_export($jL['lotes_detectados'] ?? null, true));
chk('13. Rellena las cuatro tareas', ($jL['completed_at_rellenados'] ?? -1) === 4,
    'rellenadas=' . var_export($jL['completed_at_rellenados'] ?? null, true));

$fechas = [];
foreach ($idsLote as $n => $id) {
    $fechas[$n] = $conn->query("SELECT completed_at FROM tasks WHERE id=$id")->fetch_row()[0];
}
chk('14. Las del lote NO heredan el segundo compartido',
    !in_array($LOTE_TS, array_values($fechas), true),
    'ninguna quedó en ' . $LOTE_TS);

chk('15. Cada una toma su propio creado_en',
    $fechas['L1'] === '2026-06-18 08:00:00'
    && $fechas['L2'] === '2026-06-21 12:00:00'
    && $fechas['L3'] === '2026-06-25 16:00:00',
    'L1=' . $fechas['L1'] . ' L2=' . $fechas['L2'] . ' L3=' . $fechas['L3']);

// Lo que de verdad importa para el filtro: que queden repartidas en el tiempo.
chk('16. Quedan repartidas, no amontonadas en un instante',
    count(array_unique(array_values($fechas))) === 3,
    count(array_unique(array_values($fechas))) . ' fechas distintas de 3');

$fUnica = $conn->query("SELECT completed_at FROM tasks WHERE id=$idUnica")->fetch_row()[0];
chk('17. La de updated_at único SÍ lo conserva',
    $fUnica === '2026-06-20 18:45:00', "única=$fUnica");

// ═════════════════════════════════════════════════════════════
section('18-20 · DESMARCAR Y COHERENCIA GLOBAL');

post(BASE_URL . '/columns/column_action.php',
    ['action' => 'set_done', 'board_id' => $BOARD, 'column_id' => $COL_FUTURA, 'is_done' => 0, 'csrf' => $csrf],
    $sid, true);
$conFecha = (int) $conn->query("SELECT COUNT(*) FROM tasks WHERE column_id=$COL_FUTURA AND completed_at IS NOT NULL")->fetch_row()[0];

// Comportamiento ACTUAL, documentado: desmarcar no borra las fechas. Está
// pendiente de decisión; si algún día se decide limpiarlas, esta prueba
// cambiará a propósito y no por sorpresa.
chk('18. Al desmarcar, las fechas se conservan (comportamiento actual)',
    $conFecha === 3, "con fecha=$conFecha");

// Solo puede haber una columna de hecho por tablero.
$conn->query("INSERT INTO `columns` (board_id,nombre,orden,is_done,created_at)
              VALUES ($BOARD,'Otra hecho',3,0,NOW())");
$COL_OTRA = (int) $conn->insert_id;
post(BASE_URL . '/columns/column_action.php',
    ['action' => 'set_done', 'board_id' => $BOARD, 'column_id' => $COL_OTRA, 'is_done' => 1, 'csrf' => $csrf],
    $sid, true);
$nDone = (int) $conn->query("SELECT COUNT(*) FROM `columns` WHERE board_id=$BOARD AND is_done=1")->fetch_row()[0];
chk('19. Sigue habiendo una sola columna de hecho', $nDone === 1, "columnas hecho=$nDone");

// La invariante que resume todo el arreglo, comprobada sobre TODA la base.
$huerfanas = (int) $conn->query(
    "SELECT COUNT(*) FROM tasks t
       JOIN `columns` c ON c.id = t.column_id
      WHERE c.is_done = 1 AND t.completed_at IS NULL"
)->fetch_row()[0];
chk('20. En toda la base: 0 tareas en «hecho» sin fecha', $huerfanas === 0,
    "encontradas=$huerfanas");

// ═════════════════════════════════════════════════════════════
section('21-23 · LAS TRES VÍAS CERRADAS');

// duplicate.php copia las tareas pero NO completed_at, y sí conserva is_done
// de las columnas: duplicar un tablero vuelve a generar el problema. No se ha
// corregido todavía; queda anotado para que la deuda no se pierda de vista.
$dup = (string) file_get_contents($ROOT . '/public/boards/duplicate.php');
$copiaCompleted = str_contains($dup, "fieldsI[] = 'completed_at'");
if ($copiaCompleted) {
    ok('21. duplicate.php copia completed_at', 'tercer hueco cerrado');
} else {
    pend('21. duplicate.php NO copia completed_at',
        'duplicar un tablero con tareas hechas las deja sin fecha');
}

// Las dos vías corregidas deben seguir estándolo.
$crear = (string) file_get_contents($ROOT . '/public/tasks/create.php');
chk('22. create.php consulta is_done de la columna destino',
    str_contains($crear, "SELECT is_done FROM `columns` WHERE id = ? AND board_id = ?")
    && str_contains($crear, "\$fields[] = 'completed_at'"),
    'no se fía del cliente');

$colAct = (string) file_get_contents($ROOT . '/public/columns/column_action.php');
chk('23. set_done rellena dentro de la transacción existente',
    str_contains($colAct, 'HAVING COUNT(*) > 1')
    && str_contains($colAct, 'AND completed_at IS NULL')
    && strpos($colAct, 'backfill') > strpos($colAct, 'begin_transaction()')
    && strpos($colAct, 'backfill') < strpos($colAct, '$conn->commit()'),
    'si algo falla, no queda a medias');

// ═════════════════════════════════════════════════════════════
section('LIMPIEZA');

$borrados = limpiar($conn);
printf("  tableros QA eliminados: %d\n", $borrados);
chk('LIMPIEZA · no quedan tableros QA',
    (int) $conn->query("SELECT COUNT(*) FROM boards WHERE nombre LIKE '" . QA_TAG . "%'")->fetch_row()[0] === 0);
chk('LIMPIEZA · no quedan sesiones QA', count(glob(SESSION_DIR . '/sess_qacompat*') ?: []) === 0);

foreach ($countBefore as $t => $n) {
    $ahora = (int) $conn->query("SELECT COUNT(*) FROM `$t`")->fetch_row()[0];
    chk("LIMPIEZA · filas en $t", $ahora === $n, "obtenido=$ahora esperado=$n");
}

// ═════════════════════════════════════════════════════════════
echo "\n" . str_repeat('═', 78) . "\n";
printf(" RESULTADO: %d correctas, %d fallidas%s\n", $PASS, $FAIL,
    $PEND ? ", {$PEND} sin resolver" : '');
echo str_repeat('═', 78) . "\n\n";

exit($FAIL === 0 ? 0 : 1);
