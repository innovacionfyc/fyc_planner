<?php
/**
 * tests/qa_users_helper_smoke.php
 *
 * Cobertura de la fábrica de usuarios QA (tests/_qa_users.php).
 *
 * Ejecutar SOLO en local:
 *   php tests/qa_users_helper_smoke.php
 *
 * El helper es ahora la pieza de la que dependen quince suites para no tocar
 * cuentas de personas reales. Si se rompe, se rompen en silencio: seguirían
 * dando verde midiendo otra cosa. Por eso tiene pruebas propias.
 *
 * Lo que se vigila:
 *   · crea usuarios con la semántica pedida, no con identificadores mágicos;
 *   · los correos son únicos y viven en un dominio reservado;
 *   · la limpieza va por identificador y arrastra tableros, equipos y avisos;
 *   · la limpieza NO toca a nadie más, ni QA ajeno ni cuentas normales;
 *   · se puede ejecutar dos veces seguidas sin chocar.
 *
 * No deja residuos: al terminar la base queda exactamente como estaba.
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

const QA_SUITE  = 'helperself';
const QA_VECINA = 'helpervecina';

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

/** @var mysqli $conn */
function fila(mysqli $c, int $id): ?array
{
    $st = $c->prepare("SELECT id, email, rol, is_admin, activo, estado, deleted_at FROM users WHERE id = ?");
    $st->bind_param('i', $id);
    $st->execute();
    $r = $st->get_result()->fetch_assoc();
    $st->close();
    return $r ?: null;
}

function contar(mysqli $c, string $sql): int
{
    return (int) $c->query($sql)->fetch_row()[0];
}

echo "══════════════════════════════════════════════════════════════════════════════\n";
echo " FÁBRICA DE USUARIOS QA\n";
echo "══════════════════════════════════════════════════════════════════════════════\n";
echo " PHP   : " . PHP_VERSION . "\n";
echo " Base  : " . $conn->query("SELECT DATABASE()")->fetch_row()[0] . "\n";
echo " Fecha : " . date('Y-m-d H:i:s') . "\n";

// Restos de una ejecución anterior, por si acaso.
qa_users_cleanup_stale($conn, QA_SUITE);
qa_users_cleanup_stale($conn, QA_VECINA);

$usuariosInicio = contar($conn, "SELECT COUNT(*) FROM users");
$boardsInicio   = contar($conn, "SELECT COUNT(*) FROM boards");
$teamsInicio    = contar($conn, "SELECT COUNT(*) FROM teams");
$notifInicio    = contar($conn, "SELECT COUNT(*) FROM notifications");
printf("\n  estado inicial: %d usuarios, %d tableros, %d equipos, %d avisos\n",
    $usuariosInicio, $boardsInicio, $teamsInicio, $notifInicio);

// ═════════════════════════════════════════════════════════════
section('1-9 · CREACIÓN CON SEMÁNTICA EXPLÍCITA');

$normal = qa_user($conn, QA_SUITE, ['rol' => 'user', 'is_admin' => 0]);
$f = fila($conn, $normal);
chk('1. Crea un usuario normal', $f !== null && (int) $f['id'] === $normal, "id=$normal");
chk('2. Con el rol pedido y sin permiso de administración',
    $f['rol'] === 'user' && (int) $f['is_admin'] === 0,
    "rol={$f['rol']} is_admin={$f['is_admin']}");
chk('3. Nace activo y aprobado',
    (int) $f['activo'] === 1 && $f['estado'] === 'aprobado',
    "activo={$f['activo']} estado={$f['estado']}");

$super = qa_user($conn, QA_SUITE, ['rol' => 'super_admin', 'is_admin' => 1]);
$fs = fila($conn, $super);
chk('4. Crea un super admin de verdad',
    $fs['rol'] === 'super_admin' && (int) $fs['is_admin'] === 1,
    'las dos condiciones que exige is_super_admin()');

// El caso que motivó todo esto: is_admin=1 SIN ser super_admin. La base de
// desarrollo lo tenía por casualidad en el usuario 3; ahora se pide.
$adminNoSuper = qa_user($conn, QA_SUITE, ['rol' => 'coordinador', 'is_admin' => 1]);
$fa = fila($conn, $adminNoSuper);
chk('5. Crea admin que NO es super admin',
    $fa['rol'] === 'coordinador' && (int) $fa['is_admin'] === 1,
    'permite probar que is_admin por sí solo no abre atajos');

$inactivo = qa_user($conn, QA_SUITE, ['rol' => 'user', 'activo' => 0]);
chk('6. Crea un usuario desactivado', (int) fila($conn, $inactivo)['activo'] === 0);

$pendiente = qa_user($conn, QA_SUITE, ['rol' => 'user', 'estado' => 'pendiente']);
chk('7. Crea un usuario pendiente de aprobación',
    fila($conn, $pendiente)['estado'] === 'pendiente');

$borrado = qa_user($conn, QA_SUITE, ['rol' => 'user', 'deleted_at' => '2026-01-01 00:00:00']);
chk('8. Admite deleted_at cuando la suite lo necesita',
    fila($conn, $borrado)['deleted_at'] === '2026-01-01 00:00:00');

$creados = [$normal, $super, $adminNoSuper, $inactivo, $pendiente, $borrado];
chk('9. Todos los identificadores son distintos',
    count(array_unique($creados)) === count($creados), count($creados) . ' usuarios');

// ═════════════════════════════════════════════════════════════
section('10-14 · CORREOS: ÚNICOS Y EN DOMINIO RESERVADO');

$marcas = implode(',', $creados);
$correos = [];
$r = $conn->query("SELECT email FROM users WHERE id IN ($marcas)");
while ($x = $r->fetch_row()) {
    $correos[] = $x[0];
}
chk('10. Cada usuario tiene un correo distinto',
    count(array_unique($correos)) === count($creados));

$todosDominio = true;
$todosPrefijo = true;
foreach ($correos as $c) {
    if (!str_ends_with($c, QA_USER_DOMAIN)) {
        $todosDominio = false;
    }
    if (!str_starts_with($c, 'qa.' . QA_SUITE . '.')) {
        $todosPrefijo = false;
    }
}
chk('11. Todos en el dominio reservado', $todosDominio, QA_USER_DOMAIN);
chk('12. Todos llevan el prefijo de la suite', $todosPrefijo, 'qa.' . QA_SUITE . '.');

// Ninguna cuenta real puede caer en el patrón: el dominio no es enrutable y
// no aparece en la base fuera de las pruebas.
chk('13. El patrón no alcanza a ninguna cuenta ajena a QA',
    contar($conn, "SELECT COUNT(*) FROM users WHERE email LIKE '%" . QA_USER_DOMAIN . "' AND email NOT LIKE 'qa.%'") === 0);

$repetidos = [];
for ($i = 0; $i < 50; $i++) {
    $repetidos[] = qa_user_email(QA_SUITE);
}
chk('14. Cincuenta correos seguidos no repiten',
    count(array_unique($repetidos)) === 50);

// ═════════════════════════════════════════════════════════════
section('15-20 · LA LIMPIEZA ARRASTRA LO QUE CUELGA');

// Un tablero y un equipo del usuario QA, más un aviso suyo.
$st = $conn->prepare("INSERT INTO boards (nombre, owner_user_id, visibility, created_at) VALUES (?,?, 'private', NOW())");
$bn = 'QA HELPER TABLERO';
$st->bind_param('si', $bn, $normal);
$st->execute();
$board = (int) $conn->insert_id;
$st->close();

$st = $conn->prepare("INSERT INTO teams (nombre, owner_user_id, created_at) VALUES (?,?, NOW())");
$tn = 'QA HELPER EQUIPO';
$st->bind_param('si', $tn, $normal);
$st->execute();
$team = (int) $conn->insert_id;
$st->close();

$st = $conn->prepare("INSERT INTO team_members (team_id, user_id, rol) VALUES (?,?, 'admin_equipo')");
$st->bind_param('ii', $team, $normal);
$st->execute();
$st->close();

// El aviso es la prueba de fuego: notifications NO tiene clave ajena hacia
// boards, así que borrar el tablero no lo retira. Solo desaparece si se borra
// el usuario. Ese era el camino por el que una suite podía dejar avisos en la
// bandeja de una persona real.
$st = $conn->prepare("INSERT INTO notifications (user_id, tipo, payload_json, created_at) VALUES (?, 'qa_helper', ?, NOW())");
$payload = '{"qa":true}';
$st->bind_param('is', $normal, $payload);
$st->execute();
$st->close();

chk('15. Se preparó un tablero, un equipo y un aviso QA',
    contar($conn, "SELECT COUNT(*) FROM boards WHERE id = $board") === 1
    && contar($conn, "SELECT COUNT(*) FROM teams WHERE id = $team") === 1
    && contar($conn, "SELECT COUNT(*) FROM notifications WHERE user_id = $normal") === 1);

// Un segundo usuario QA de OTRA suite: no debe verse afectado.
$vecino = qa_user($conn, QA_VECINA, ['rol' => 'user']);

$res = qa_users_cleanup($conn, [$normal]);
chk('16. La limpieza informa de lo que retiró',
    $res['users'] === 1 && $res['boards'] === 1 && $res['teams'] === 1,
    json_encode($res));
chk('17. El usuario desapareció', fila($conn, $normal) === null);
chk('18. Su tablero también', contar($conn, "SELECT COUNT(*) FROM boards WHERE id = $board") === 0);
chk('19. Y su equipo', contar($conn, "SELECT COUNT(*) FROM teams WHERE id = $team") === 0);
chk('20. Sus avisos se fueron con él',
    contar($conn, "SELECT COUNT(*) FROM notifications WHERE user_id = $normal") === 0,
    'users -> notifications en CASCADE');

// ═════════════════════════════════════════════════════════════
section('21-25 · LA LIMPIEZA NO SE LLEVA NADA MÁS');

chk('21. El usuario QA de otra suite sigue vivo', fila($conn, $vecino) !== null, "id=$vecino");
chk('22. Los otros usuarios QA de esta suite siguen vivos',
    fila($conn, $super) !== null && fila($conn, $adminNoSuper) !== null
    && fila($conn, $inactivo) !== null && fila($conn, $pendiente) !== null
    && fila($conn, $borrado) !== null,
    'solo se pidió borrar uno');

$noQA = contar($conn, "SELECT COUNT(*) FROM users WHERE email NOT LIKE '%" . QA_USER_DOMAIN . "'");
chk('23. Ninguna cuenta ajena a QA fue tocada',
    $noQA === $usuariosInicio, "no-QA=$noQA inicio=$usuariosInicio");

// Limpieza por patrón: retira lo que quede de ESTA suite y nada de la vecina.
$restantes = qa_users_cleanup_stale($conn, QA_SUITE);
chk('24. La limpieza por patrón retira el resto de la suite', $restantes === 5, "retirados=$restantes");
chk('25. Y sigue sin tocar a la suite vecina', fila($conn, $vecino) !== null);

qa_users_cleanup_stale($conn, QA_VECINA);
chk('26. La vecina se limpia con su propio patrón', fila($conn, $vecino) === null);

// ═════════════════════════════════════════════════════════════
section('27-30 · REPETIBLE Y SIN RESIDUO');

// Segunda vuelta completa: si algo dependiera del estado anterior, aquí falla.
$a = qa_user($conn, QA_SUITE, ['rol' => 'user']);
$b = qa_user($conn, QA_SUITE, ['rol' => 'super_admin', 'is_admin' => 1]);
chk('27. Se puede ejecutar dos veces seguidas', $a > 0 && $b > 0 && $a !== $b);
$r2 = qa_users_cleanup($conn);
chk('28. La segunda limpieza también funciona', $r2['users'] === 2, json_encode($r2));

chk('29. Limpiar dos veces no falla ni borra de más',
    qa_users_cleanup($conn) === ['boards' => 0, 'teams' => 0, 'users' => 0],
    'sin identificadores pendientes, no hace nada');

chk('30. No quedan usuarios QA de esta suite',
    qa_users_restantes($conn, QA_SUITE) === 0 && qa_users_restantes($conn, QA_VECINA) === 0);

// ═════════════════════════════════════════════════════════════
section('CIERRE · LA BASE QUEDA COMO ESTABA');

$fin = [
    'users'         => contar($conn, "SELECT COUNT(*) FROM users"),
    'boards'        => contar($conn, "SELECT COUNT(*) FROM boards"),
    'teams'         => contar($conn, "SELECT COUNT(*) FROM teams"),
    'notifications' => contar($conn, "SELECT COUNT(*) FROM notifications"),
];
chk('31. Usuarios: sin variación', $fin['users'] === $usuariosInicio, "{$usuariosInicio} -> {$fin['users']}");
chk('32. Tableros: sin variación', $fin['boards'] === $boardsInicio, "{$boardsInicio} -> {$fin['boards']}");
chk('33. Equipos: sin variación', $fin['teams'] === $teamsInicio, "{$teamsInicio} -> {$fin['teams']}");
chk('34. Avisos: sin variación', $fin['notifications'] === $notifInicio, "{$notifInicio} -> {$fin['notifications']}");

echo "\n══════════════════════════════════════════════════════════════════════════════\n";
printf(" RESULTADO: %d correctas, %d fallidas\n", $PASS, $FAIL);
echo "══════════════════════════════════════════════════════════════════════════════\n\n";
exit($FAIL === 0 ? 0 : 1);
