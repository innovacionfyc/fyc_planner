<?php
/**
 * tests/cron_scope_smoke.php
 *
 * Alcance acotado de los dos crons destructivos.
 *
 * Ejecutar SOLO en local:
 *   php tests/cron_scope_smoke.php
 *
 * POR QUÉ EXISTE
 *   Una suite lanzaba cron/purge_trash.php sin acotar. El cron hizo su
 *   trabajo correctamente —purga todo lo que lleve más de 30 días en la
 *   papelera— y se llevó un tablero real de una copia de producción, con sus
 *   columnas, tareas y comentarios.
 *
 *   La solución no fue debilitar los crons, sino darles un alcance opcional:
 *   --board-id en la papelera y --storage-root en los huérfanos. Sin esos
 *   argumentos el comportamiento es idéntico al de siempre.
 *
 *   Esta suite vigila las dos mitades del contrato: que el alcance acote de
 *   verdad, y que su ausencia no cambie nada. Vigila además la propiedad que
 *   evita repetir el accidente: un argumento mal escrito NUNCA puede degradar
 *   en «sin alcance».
 *
 * No deja residuos: crea sus propios tableros y usuarios QA y los retira.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Este script solo puede ejecutarse desde CLI.\n");
}

$ROOT = dirname(__DIR__);
require_once $ROOT . '/config/db.php';
require_once __DIR__ . '/_qa_users.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

const QA_TAG      = 'QA CRONSCOPE';
const QA_SUITE    = 'cronscope';

$CRON_TRASH  = $ROOT . '/cron/purge_trash.php';
$CRON_ORPHAN = $ROOT . '/cron/purge_orphan_attachments.php';

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
    echo "\n──────────────────────────────────────────────────────────────────────────────\n";
    echo " $t\n";
    echo "──────────────────────────────────────────────────────────────────────────────\n";
}

function correr(string $script, array $args = []): array
{
    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script);
    foreach ($args as $a) {
        $cmd .= ' ' . escapeshellarg($a);
    }
    $out = [];
    $code = 0;
    exec($cmd . ' 2>&1', $out, $code);
    return [$code, implode("\n", $out)];
}

/** @var mysqli $conn */
function uno(mysqli $c, string $sql): int
{
    return (int) $c->query($sql)->fetch_row()[0];
}

function existe(string $ruta): bool
{
    clearstatcache(true, $ruta);
    return is_file($ruta);
}

/** Crea un tablero QA, opcionalmente en papelera con una antigüedad dada. */
function tablero(mysqli $c, int $owner, string $sufijo, ?int $diasEnPapelera = null): int
{
    if ($diasEnPapelera === null) {
        $st = $c->prepare("INSERT INTO boards (nombre, owner_user_id, visibility, created_at) VALUES (?,?, 'private', NOW())");
        $n  = QA_TAG . ' ' . $sufijo;
        $st->bind_param('si', $n, $owner);
    } else {
        $st = $c->prepare("INSERT INTO boards (nombre, owner_user_id, visibility, created_at, deleted_at) VALUES (?,?, 'private', NOW(), NOW() - INTERVAL ? DAY)");
        $n  = QA_TAG . ' ' . $sufijo;
        $st->bind_param('sii', $n, $owner, $diasEnPapelera);
    }
    $st->execute();
    $id = (int) $c->insert_id;
    $st->close();
    return $id;
}

function vive(mysqli $c, int $boardId): bool
{
    return uno($c, "SELECT COUNT(*) FROM boards WHERE id = $boardId") === 1;
}

echo "══════════════════════════════════════════════════════════════════════════════\n";
echo " ALCANCE ACOTADO DE LOS CRONS DESTRUCTIVOS\n";
echo "══════════════════════════════════════════════════════════════════════════════\n";
echo " PHP   : " . PHP_VERSION . "\n";
echo " Base  : " . $conn->query("SELECT DATABASE()")->fetch_row()[0] . "\n";
echo " Fecha : " . date('Y-m-d H:i:s') . "\n";

// Restos de una ejecución interrumpida
$conn->query("DELETE FROM boards WHERE nombre LIKE '" . QA_TAG . "%'");
qa_users_cleanup_stale($conn, QA_SUITE);

$boardsInicio = uno($conn, "SELECT COUNT(*) FROM boards");
$owner = qa_user($conn, QA_SUITE, ['rol' => 'user', 'is_admin' => 0]);

// ═════════════════════════════════════════════════════════════
section('1-4 · PURGE_TRASH · EL ALCANCE ACOTA DE VERDAD');

$objetivo = tablero($conn, $owner, 'OBJETIVO', 40);   // caducado, se pedirá
$testigo  = tablero($conn, $owner, 'TESTIGO', 90);    // caducado, NO se pedirá
$reciente = tablero($conn, $owner, 'RECIENTE', 5);    // en papelera pero joven
$vivo     = tablero($conn, $owner, 'VIVO');           // fuera de la papelera

chk('1. Escenario preparado', vive($conn, $objetivo) && vive($conn, $testigo)
    && vive($conn, $reciente) && vive($conn, $vivo),
    "objetivo=$objetivo testigo=$testigo reciente=$reciente vivo=$vivo");

[$code, $out] = correr($CRON_TRASH, ['--board-id=' . $objetivo]);
chk('2. Con --board-id purga el tablero pedido',
    $code === 0 && !vive($conn, $objetivo), 'exit=' . $code . ' | ' . trim($out));

// El corazón del asunto: otro tablero MÁS antiguo aún, que el cron sin
// alcance habría purgado sin dudarlo, sigue vivo.
chk('3. NO purga otro tablero caducado que no se pidió',
    vive($conn, $testigo), 'lleva 90 días en papelera y sobrevive');

chk('4. Tampoco toca el reciente ni el que no está en papelera',
    vive($conn, $reciente) && vive($conn, $vivo));

// ═════════════════════════════════════════════════════════════
section('5-9 · PURGE_TRASH · EL ALCANCE NO SE PUEDE ESQUIVAR');

// Un id que no existe no debe convertirse en «sin alcance».
$idInexistente = uno($conn, "SELECT COALESCE(MAX(id),0) + 5000 FROM boards");
[$code, $out] = correr($CRON_TRASH, ['--board-id=' . $idInexistente]);
chk('5. Un board-id inexistente no dispara purga global',
    $code === 0 && vive($conn, $testigo) && vive($conn, $reciente),
    'exit=' . $code . ' | ' . trim($out));

// La regla de antigüedad sigue vigente: el alcance restringe, no falsea.
[$code, $out] = correr($CRON_TRASH, ['--board-id=' . $reciente]);
chk('6. Pedir un tablero reciente no lo purga',
    $code === 0 && vive($conn, $reciente),
    'la regla de 30 días sigue aplicándose');

[$code, $out] = correr($CRON_TRASH, ['--board-id=' . $vivo]);
chk('7. Pedir un tablero que no está en papelera no lo purga',
    $code === 0 && vive($conn, $vivo));

// Lo que provocó el accidente: un argumento mal escrito no puede degradar.
[$code, $out] = correr($CRON_TRASH, ['--board-di=' . $testigo]);
chk('8. Un argumento mal escrito aborta en vez de purgar todo',
    $code === 2 && vive($conn, $testigo),
    'exit=' . $code . ' | ' . trim($out));

foreach (['--board-id=abc', '--board-id=', '--board-id=-3', '--board-id'] as $malo) {
    [$c, ] = correr($CRON_TRASH, [$malo]);
    if ($c !== 2) {
        ko('9. Todo board-id inválido termina en código 2', "«$malo» devolvió $c");
        $fallo9 = true;
        break;
    }
}
if (!isset($fallo9)) {
    ok('9. Todo board-id inválido termina en código 2', 'cuatro formas probadas');
}

// ═════════════════════════════════════════════════════════════
section('10-12 · PURGE_TRASH · SIN ARGUMENTOS NO CAMBIA NADA');

chk('10. --help documenta el alcance y no borra',
    (function () use ($CRON_TRASH, $conn, $testigo) {
        [$c, $o] = correr($CRON_TRASH, ['--help']);
        return $c === 0 && str_contains($o, '--board-id') && vive($conn, $testigo);
    })());

// Comportamiento heredado: sin argumentos el cron abarca TODO lo caducado.
//
// Esto NO puede comprobarse ejecutandolo de verdad. La primera version de esta
// suite lo hizo y, sobre la copia de produccion, purgo un tablero real de 112
// dias: exactamente el accidente que este bloque venia a impedir. El simulacro
// recorre la misma seleccion y no borra nada, asi que da la misma garantia sin
// el precio.
$caducadosAntes = uno($conn, "SELECT COUNT(*) FROM boards WHERE deleted_at IS NOT NULL AND deleted_at < NOW() - INTERVAL 30 DAY");
$boardsAntes11  = uno($conn, "SELECT COUNT(*) FROM boards");
[$code, $out] = correr($CRON_TRASH, ['--dry-run']);
chk('11. Sin alcance abarca todo lo caducado (comprobado en simulacro)',
    $code === 0 && $caducadosAntes >= 1 && str_contains($out, (string) $testigo)
    && str_contains($out, 'SIMULACRO'),
    "caducados={$caducadosAntes} | " . trim($out));

chk('12. El simulacro no borra absolutamente nada',
    uno($conn, "SELECT COUNT(*) FROM boards") === $boardsAntes11 && vive($conn, $testigo)
    && vive($conn, $reciente) && vive($conn, $vivo),
    'tableros=' . $boardsAntes11 . ' sin variacion');

// Y el testigo se retira por su id, no por barrido global.
[$code, ] = correr($CRON_TRASH, ['--board-id=' . $testigo]);
chk('12b. El testigo se purga cuando SI se pide por id',
    $code === 0 && !vive($conn, $testigo));

// ═════════════════════════════════════════════════════════════
section('13-19 · PURGE_ORPHAN · ALMACÉN ACOTADO');

$QA_ST = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'fyc_scope_' . bin2hex(random_bytes(6));
$sub   = $QA_ST . DIRECTORY_SEPARATOR . date('Y') . DIRECTORY_SEPARATOR . date('m');
@mkdir($sub, 0775, true);
file_put_contents($QA_ST . DIRECTORY_SEPARATOR . '.gitkeep', '');

$relHuerfano  = date('Y') . '/' . date('m') . '/' . bin2hex(random_bytes(16)) . '.jpg';
$relReferido  = date('Y') . '/' . date('m') . '/' . bin2hex(random_bytes(16)) . '.jpg';
$absHuerfano  = $QA_ST . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relHuerfano);
$absReferido  = $QA_ST . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relReferido);
file_put_contents($absHuerfano, 'huerfano');
file_put_contents($absReferido, 'referenciado');
touch($absHuerfano, time() - 72 * 3600);
touch($absReferido, time() - 72 * 3600);

// El referenciado necesita una fila en base para que el cron lo respete.
$colB = tablero($conn, $owner, 'ORPHAN');
$st = $conn->prepare("INSERT INTO `columns` (board_id,nombre,orden,is_done) VALUES (?, 'QA', 1, 0)");
$st->bind_param('i', $colB);
$st->execute();
$col = (int) $conn->insert_id;
$st->close();
$st = $conn->prepare("INSERT INTO tasks (board_id,column_id,titulo,prioridad) VALUES (?,?, 'QA scope', 'med')");
$st->bind_param('ii', $colB, $col);
$st->execute();
$tarea = (int) $conn->insert_id;
$st->close();
$st = $conn->prepare("INSERT INTO task_attachments (task_id,uploaded_by,kind,original_name,stored_path,mime,size_bytes) VALUES (?,?, 'image','qa.jpg',?, 'image/jpeg', 12)");
$st->bind_param('iis', $tarea, $owner, $relReferido);
$st->execute();
$st->close();

/** Archivos presentes en el almacén REAL, para probar que ni se mira. */
function almacen_real(): array
{
    $root = dirname(__DIR__) . '/storage/attachments';
    $out = [];
    foreach (@scandir($root) ?: [] as $y) {
        if (!preg_match('/^\d{4}$/', $y)) {
            continue;
        }
        foreach (@scandir("$root/$y") ?: [] as $m) {
            if (!preg_match('/^\d{2}$/', $m)) {
                continue;
            }
            foreach (array_diff(@scandir("$root/$y/$m") ?: [], ['.', '..']) as $f) {
                $out[] = "$y/$m/$f";
            }
        }
    }
    sort($out);
    return $out;
}

$realAntes = almacen_real();

[$code, $out] = correr($CRON_ORPHAN, ['--storage-root=' . $QA_ST, '--grace-hours=0', '--verbose']);
chk('13. Opera sobre el almacén indicado', $code === 0 && str_contains($out, 'almacén acotado'),
    'exit=' . $code);
chk('14. Borra el huérfano QA', !existe($absHuerfano));
chk('15. Conserva el archivo QA referenciado en base', existe($absReferido),
    'tiene fila en task_attachments');
chk('16. El almacén real ni se recorrió', almacen_real() === $realAntes,
    count($realAntes) . ' archivos, sin cambios');

[$code, ] = correr($CRON_ORPHAN, ['--storage-root=' . $QA_ST . '_no_existe']);
chk('17. Un almacén inexistente se rechaza, no se amplía el alcance', $code === 2, 'exit=' . $code);

[$code, ] = correr($CRON_ORPHAN, ['--storage-rot=' . $QA_ST]);
chk('18. Un argumento mal escrito aborta', $code === 2, 'exit=' . $code);

[$code, $out] = correr($CRON_ORPHAN, ['--dry-run', '--storage-root=' . $QA_ST]);
chk('19. El simulacro sigue funcionando con almacén acotado',
    $code === 0 && existe($absReferido), 'exit=' . $code);

// ═════════════════════════════════════════════════════════════
section('20-21 · PURGE_ORPHAN · SIN ARGUMENTOS NO CAMBIA NADA');

[$code, $out] = correr($CRON_ORPHAN, ['--dry-run']);
chk('20. Sin --storage-root usa el almacén real', $code === 0 && !str_contains($out, 'almacén acotado'),
    'exit=' . $code);

chk('21. Y en simulacro no toca nada', almacen_real() === $realAntes);

// ═════════════════════════════════════════════════════════════
section('LIMPIEZA');

foreach ([$absHuerfano, $absReferido, $QA_ST . DIRECTORY_SEPARATOR . '.gitkeep'] as $f) {
    @unlink($f);
}
@rmdir($sub);
@rmdir(dirname($sub));
@rmdir($QA_ST);

$conn->query("DELETE FROM boards WHERE nombre LIKE '" . QA_TAG . "%'");
qa_users_cleanup($conn);

$boardsFin = uno($conn, "SELECT COUNT(*) FROM boards");
chk('LIMPIEZA · tableros: sin variación', $boardsFin === $boardsInicio,
    "$boardsInicio -> $boardsFin");
chk('LIMPIEZA · no quedan usuarios QA', qa_users_restantes($conn, QA_SUITE) === 0);
chk('LIMPIEZA · almacén temporal retirado', !is_dir($QA_ST));
chk('LIMPIEZA · almacén real intacto', almacen_real() === $realAntes);

echo "\n══════════════════════════════════════════════════════════════════════════════\n";
printf(" RESULTADO: %d correctas, %d fallidas\n", $PASS, $FAIL);
echo "══════════════════════════════════════════════════════════════════════════════\n\n";
exit($FAIL === 0 ? 0 : 1);
