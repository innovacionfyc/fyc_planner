<?php
/**
 * tests/team_isolation_smoke.php
 *
 * Aislamiento por equipo en las funciones de permiso (public/_perm.php).
 *
 * Ejecutar SOLO en local:
 *   php tests/team_isolation_smoke.php
 *
 * Qué hace:
 *   1. Crea usuarios, equipos, tableros y membresías temporales.
 *   2. Ejercita has_board_access, can_edit_board, can_write_board,
 *      can_manage_board y is_member_of_board_team sobre tableros de equipo,
 *      de otro equipo y personales.
 *   3. Comprueba que expulsar a alguien de team_members le retira el acceso.
 *   4. Limpia absolutamente todo y verifica que no quedan residuos.
 *
 * POR QUÉ EXISTE
 *   has_board_access() tiene una rama para tableros de equipo
 *   (boards.team_id IS NOT NULL) que exige fila en team_members. Ninguna otra
 *   suite la ejecutaba: sus 16 tableros de prueba usan team_id = NULL, así que
 *   la lógica de seguridad más sensible del proyecto viajaba sin red.
 *
 * OJO CON LA SESIÓN
 *   is_super_admin() NO mira el parámetro $user_id: mira $_SESSION['user_id'].
 *   Si la sesión pertenece a un super admin, TODAS estas funciones devuelven
 *   true para cualquier usuario consultado. Por eso cada aserción fija antes
 *   quién pregunta con sesion_de(), y las de usuario normal la ponen a 0. Sin
 *   esa precaución la suite pasaría en verde sin comprobar nada.
 *
 * No deja usuarios, equipos, tableros ni filas QA. Si el script se interrumpe,
 * volver a ejecutarlo limpia los restos de la vez anterior.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Este script solo puede ejecutarse desde CLI.\n");
}

require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../public/_perm.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

// ─────────────────────────────────────────────────────────────
// Configuración
// ─────────────────────────────────────────────────────────────
const QA_TAG        = 'QA TEAMISO';
const QA_MAIL_LIKE  = 'qa.teamiso.%@local.test';

// ─────────────────────────────────────────────────────────────
// Utilidades de salida
// ─────────────────────────────────────────────────────────────
$PASS = 0;
$FAIL = 0;

function ok(string $name, string $detail = ''): void
{
    global $PASS;
    $PASS++;
    printf("  [OK]    %-56s %s\n", $name, $detail);
}

function ko(string $name, string $detail = ''): void
{
    global $FAIL;
    $FAIL++;
    printf("  [FALLO] %-56s %s\n", $name, $detail);
}

function expect(string $name, $got, $want, string $extra = ''): void
{
    if ((string) $got === (string) $want) {
        ok($name, "obtenido=$got" . ($extra ? " | $extra" : ''));
    } else {
        ko($name, "obtenido=$got esperado=$want" . ($extra ? " | $extra" : ''));
    }
}

/** Igual que expect() pero para booleanos, que se leen mejor así. */
function expectBool(string $name, bool $got, bool $want, string $extra = ''): void
{
    expect($name, $got ? 'true' : 'false', $want ? 'true' : 'false', $extra);
}

function section(string $t): void
{
    echo "\n" . str_repeat('─', 74) . "\n " . $t . "\n" . str_repeat('─', 74) . "\n";
}

/**
 * Fija quién está preguntando.
 *
 * Las funciones de _perm.php consultan is_super_admin(), que lee la sesión y
 * no el parámetro. Un 0 significa «nadie con privilegios»: es el escenario en
 * el que de verdad se prueba el aislamiento.
 */
function sesion_de(int $uid): void
{
    $_SESSION['user_id'] = $uid;
}

// ─────────────────────────────────────────────────────────────
// Fixtures
// ─────────────────────────────────────────────────────────────
function crear_usuario(mysqli $conn, string $nombre, string $rol, int $isAdmin): int
{
    $hash  = password_hash(bin2hex(random_bytes(12)), PASSWORD_DEFAULT);
    $email = 'qa.teamiso.' . bin2hex(random_bytes(5)) . '@local.test';
    $st = $conn->prepare(
        "INSERT INTO users (nombre,email,pass_hash,estado,rol,is_admin,activo)
         VALUES (?,?,?,'aprobado',?,?,1)"
    );
    $st->bind_param('ssssi', $nombre, $email, $hash, $rol, $isAdmin);
    $st->execute();
    $id = (int) $conn->insert_id;
    $st->close();
    return $id;
}

function crear_equipo(mysqli $conn, string $nombre, int $owner): int
{
    $st = $conn->prepare("INSERT INTO teams (nombre, owner_user_id) VALUES (?,?)");
    $st->bind_param('si', $nombre, $owner);
    $st->execute();
    $id = (int) $conn->insert_id;
    $st->close();
    return $id;
}

function meter_en_equipo(mysqli $conn, int $team, int $user, string $rol): void
{
    $st = $conn->prepare("INSERT INTO team_members (team_id,user_id,rol) VALUES (?,?,?)");
    $st->bind_param('iis', $team, $user, $rol);
    $st->execute();
    $st->close();
}

function sacar_de_equipo(mysqli $conn, int $team, int $user): int
{
    $st = $conn->prepare("DELETE FROM team_members WHERE team_id=? AND user_id=?");
    $st->bind_param('ii', $team, $user);
    $st->execute();
    $n = $conn->affected_rows;
    $st->close();
    return $n;
}

function crear_tablero(mysqli $conn, string $nombre, int $owner, ?int $team): int
{
    $st = $conn->prepare(
        "INSERT INTO boards (nombre,color_hex,owner_user_id,team_id,visibility)
         VALUES (?, '#d32f57', ?, ?, ?)"
    );
    $vis = $team === null ? 'private' : 'team';
    $st->bind_param('siis', $nombre, $owner, $team, $vis);
    $st->execute();
    $id = (int) $conn->insert_id;
    $st->close();
    return $id;
}

function meter_en_tablero(mysqli $conn, int $board, int $user, string $rol): void
{
    $st = $conn->prepare("INSERT INTO board_members (board_id,user_id,rol) VALUES (?,?,?)");
    $st->bind_param('iis', $board, $user, $rol);
    $st->execute();
    $st->close();
}

/**
 * Borra únicamente lo creado por esta suite.
 *
 * Los tableros van por nombre; los equipos también. Los usuarios, por el
 * patrón de correo. Nunca se tocan datos reales: la base tiene equipos y
 * membresías de verdad que deben quedar intactos.
 */
function limpiar(mysqli $conn): array
{
    $conn->query("DELETE FROM boards WHERE nombre LIKE '" . QA_TAG . "%'");
    $boards = $conn->affected_rows;

    $conn->query("DELETE FROM teams WHERE nombre LIKE '" . QA_TAG . "%'");
    $teams = $conn->affected_rows;

    // team_members y board_members caen por cascada con sus padres; los
    // usuarios se borran al final porque las claves foráneas los referencian.
    $conn->query("DELETE FROM users WHERE email LIKE '" . QA_MAIL_LIKE . "'");
    $users = $conn->affected_rows;

    return ['boards' => $boards, 'teams' => $teams, 'users' => $users];
}

// ═════════════════════════════════════════════════════════════
// INICIO
// ═════════════════════════════════════════════════════════════
echo "\n";
echo "══════════════════════════════════════════════════════════════════════════\n";
echo " AISLAMIENTO POR EQUIPO — FUNCIONES DE PERMISO\n";
echo "══════════════════════════════════════════════════════════════════════════\n";
echo " Base   : " . $conn->query('SELECT DATABASE()')->fetch_row()[0] . "\n";
echo " Motor  : " . $conn->server_info . "\n";
echo " PHP    : " . PHP_VERSION . "\n";
echo " Fecha  : " . date('Y-m-d H:i:s') . "\n";

section('LIMPIEZA PREVIA');
$pre = limpiar($conn);
printf("  restos anteriores: %d tableros, %d equipos, %d usuarios\n",
    $pre['boards'], $pre['teams'], $pre['users']);

$countBefore = [];
foreach (['users', 'teams', 'team_members', 'boards', 'board_members'] as $t) {
    $countBefore[$t] = (int) $conn->query("SELECT COUNT(*) FROM `$t`")->fetch_row()[0];
}
echo '  línea base: ' . json_encode($countBefore) . "\n";

// ─────────────────────────────────────────────────────────────
section('PREPARACIÓN DE DATOS TEMPORALES');

$U_A_MIEMBRO = crear_usuario($conn, 'QA Iso A miembro', 'user', 0);
$U_A_ADMIN   = crear_usuario($conn, 'QA Iso A admin',   'coordinador', 0);
$U_B_MIEMBRO = crear_usuario($conn, 'QA Iso B miembro', 'user', 0);
$U_AJENO     = crear_usuario($conn, 'QA Iso ajeno',     'user', 0);
$U_SUPER     = crear_usuario($conn, 'QA Iso super',     'super_admin', 1);

$TEAM_A = crear_equipo($conn, QA_TAG . ' Equipo A', $U_A_ADMIN);
$TEAM_B = crear_equipo($conn, QA_TAG . ' Equipo B', $U_B_MIEMBRO);

meter_en_equipo($conn, $TEAM_A, $U_A_ADMIN,   'admin_equipo');
meter_en_equipo($conn, $TEAM_A, $U_A_MIEMBRO, 'miembro');
meter_en_equipo($conn, $TEAM_B, $U_B_MIEMBRO, 'admin_equipo');

// Tablero del equipo A, propiedad del admin del equipo A.
$BOARD_A = crear_tablero($conn, QA_TAG . ' Tablero A', $U_A_ADMIN, $TEAM_A);
meter_en_tablero($conn, $BOARD_A, $U_A_ADMIN, 'propietario');

// Tablero del equipo B.
$BOARD_B = crear_tablero($conn, QA_TAG . ' Tablero B', $U_B_MIEMBRO, $TEAM_B);
meter_en_tablero($conn, $BOARD_B, $U_B_MIEMBRO, 'propietario');

// Tablero personal (team_id NULL): el acceso lo decide board_members.
$BOARD_P = crear_tablero($conn, QA_TAG . ' Tablero personal', $U_A_MIEMBRO, null);
meter_en_tablero($conn, $BOARD_P, $U_A_MIEMBRO, 'propietario');
meter_en_tablero($conn, $BOARD_P, $U_B_MIEMBRO, 'lector');

// Tablero del equipo A donde un usuario del equipo B figura como editor en
// board_members SIN pertenecer al equipo. Sirve para comprobar que en un
// tablero de equipo manda team_members, no board_members.
$BOARD_A2 = crear_tablero($conn, QA_TAG . ' Tablero A2', $U_A_ADMIN, $TEAM_A);
meter_en_tablero($conn, $BOARD_A2, $U_A_ADMIN,   'propietario');
meter_en_tablero($conn, $BOARD_A2, $U_B_MIEMBRO, 'editor');

printf("  usuarios: A_miembro=%d A_admin=%d B_miembro=%d ajeno=%d super=%d\n",
    $U_A_MIEMBRO, $U_A_ADMIN, $U_B_MIEMBRO, $U_AJENO, $U_SUPER);
printf("  equipos : A=%d B=%d\n", $TEAM_A, $TEAM_B);
printf("  tableros: A=%d B=%d personal=%d A2=%d\n", $BOARD_A, $BOARD_B, $BOARD_P, $BOARD_A2);

// ═════════════════════════════════════════════════════════════
section('1-6 · has_board_access · TABLERO DE OTRO EQUIPO');

// A partir de aquí, quien pregunta no tiene privilegios de sistema.
sesion_de(0);

expectBool('1. A_miembro NO accede al tablero del equipo B',
    has_board_access($conn, $BOARD_B, $U_A_MIEMBRO), false,
    'la rama de equipo exige fila en team_members');

expectBool('2. A_admin NO accede al tablero del equipo B',
    has_board_access($conn, $BOARD_B, $U_A_ADMIN), false,
    'ser admin de OTRO equipo no sirve');

expectBool('3. B_miembro NO accede al tablero del equipo A',
    has_board_access($conn, $BOARD_A, $U_B_MIEMBRO), false,
    'el aislamiento es simétrico');

expectBool('4. El ajeno no accede a ningún tablero de equipo',
    has_board_access($conn, $BOARD_A, $U_AJENO) || has_board_access($conn, $BOARD_B, $U_AJENO),
    false);

expectBool('5. B_miembro, editor en board_members de un tablero de A, NO entra',
    has_board_access($conn, $BOARD_A2, $U_B_MIEMBRO), false,
    'en un tablero de equipo manda team_members');

expectBool('6. Un tablero inexistente nunca da acceso',
    has_board_access($conn, 999999999, $U_A_ADMIN), false);

// ═════════════════════════════════════════════════════════════
section('7-10 · has_board_access · TABLERO PROPIO Y PERSONAL');

expectBool('7. A_miembro SÍ accede al tablero de su equipo',
    has_board_access($conn, $BOARD_A, $U_A_MIEMBRO), true);

expectBool('8. A_admin SÍ accede al tablero de su equipo',
    has_board_access($conn, $BOARD_A, $U_A_ADMIN), true);

expectBool('9. Tablero personal: el propietario entra',
    has_board_access($conn, $BOARD_P, $U_A_MIEMBRO), true,
    'team_id NULL: decide board_members');

expectBool('10. Tablero personal: un lector de otro equipo entra',
    has_board_access($conn, $BOARD_P, $U_B_MIEMBRO), true,
    'sin equipo no hay aislamiento que aplicar');

// ═════════════════════════════════════════════════════════════
section('11-14 · TABLERO PERSONAL: COMPORTAMIENTO ANTERIOR INTACTO');

expectBool('11. Personal: quien no está en board_members no entra',
    has_board_access($conn, $BOARD_P, $U_AJENO), false);

expectBool('12. Personal: el propietario puede editar',
    can_edit_board($conn, $BOARD_P, $U_A_MIEMBRO), true);

expectBool('13. Personal: un lector NO puede editar',
    can_edit_board($conn, $BOARD_P, $U_B_MIEMBRO), false,
    'lector solo lee');

expectBool('14. Personal: un lector NO puede escribir',
    can_write_board($conn, $BOARD_P, $U_B_MIEMBRO), false);

// ═════════════════════════════════════════════════════════════
section('15-22 · can_edit / can_write / can_manage RESPETAN EL AISLAMIENTO');

expectBool('15. can_edit_board: A_miembro NO edita el tablero de B',
    can_edit_board($conn, $BOARD_B, $U_A_MIEMBRO), false);

expectBool('16. can_write_board: A_miembro NO escribe en el tablero de B',
    can_write_board($conn, $BOARD_B, $U_A_MIEMBRO), false);

expectBool('17. can_manage_board: A_admin NO administra el tablero de B',
    can_manage_board($conn, $BOARD_B, $U_A_ADMIN), false,
    'admin_equipo solo manda en su propio equipo');

expectBool('18. can_edit_board: A_miembro SÍ edita el de su equipo',
    can_edit_board($conn, $BOARD_A, $U_A_MIEMBRO), true,
    'cualquier miembro del equipo edita');

expectBool('19. can_write_board: A_miembro SÍ escribe en el de su equipo',
    can_write_board($conn, $BOARD_A, $U_A_MIEMBRO), true);

expectBool('20. can_manage_board: A_admin SÍ administra el de su equipo',
    can_manage_board($conn, $BOARD_A, $U_A_ADMIN), true);

expectBool('21. can_manage_board: un miembro raso NO administra',
    can_manage_board($conn, $BOARD_A, $U_A_MIEMBRO), false,
    'ni propietario ni admin_equipo');

expectBool('22. can_edit_board: editor en board_members de un tablero de equipo ajeno NO edita',
    can_edit_board($conn, $BOARD_A2, $U_B_MIEMBRO), false,
    'coherente con la prueba 5');

// ═════════════════════════════════════════════════════════════
section('23-27 · BYPASS DE SUPER ADMIN');

// La sesión pasa a ser la del super admin: ahora sí debe abrirse todo.
sesion_de($U_SUPER);

expectBool('23. super_admin accede a un tablero de cualquier equipo',
    has_board_access($conn, $BOARD_A, $U_SUPER) && has_board_access($conn, $BOARD_B, $U_SUPER), true);

expectBool('24. super_admin edita en cualquier equipo',
    can_edit_board($conn, $BOARD_B, $U_SUPER), true);

expectBool('25. super_admin administra en cualquier equipo',
    can_manage_board($conn, $BOARD_B, $U_SUPER), true);

expectBool('26. is_member_of_board_team pasa para super_admin',
    is_member_of_board_team($conn, $BOARD_B, $U_SUPER), true);

// El bypass depende de la SESIÓN, no del usuario consultado. Es la trampa que
// invalidaría toda la suite si no se controlara.
expectBool('27. Con sesión de super admin, cualquier usuario consultado pasa',
    has_board_access($conn, $BOARD_B, $U_A_MIEMBRO), true,
    'is_super_admin() mira $_SESSION, no el parámetro');

sesion_de(0);

// ═════════════════════════════════════════════════════════════
section('28-33 · EXPULSAR DEL EQUIPO RETIRA EL ACCESO');

expectBool('28. Antes de expulsar: A_miembro entra en el tablero de su equipo',
    has_board_access($conn, $BOARD_A, $U_A_MIEMBRO), true);

$borradas = sacar_de_equipo($conn, $TEAM_A, $U_A_MIEMBRO);
expect('29. Se retira la fila de team_members', $borradas, 1);

expectBool('30. Tras expulsar: pierde el acceso de lectura',
    has_board_access($conn, $BOARD_A, $U_A_MIEMBRO), false,
    'sin caché ni sesión que lo sostenga');

expectBool('31. Tras expulsar: pierde la edición',
    can_edit_board($conn, $BOARD_A, $U_A_MIEMBRO), false);

expectBool('32. Tras expulsar: pierde la escritura',
    can_write_board($conn, $BOARD_A, $U_A_MIEMBRO), false);

// El propietario registrado en board_members conserva la LECTURA aunque salga
// del equipo: está documentado en _perm.php:65 y es deliberado.
$borradasAdmin = sacar_de_equipo($conn, $TEAM_A, $U_A_ADMIN);
expect('33. Se retira también al admin del equipo A', $borradasAdmin, 1);

// ═════════════════════════════════════════════════════════════
section('34-37 · EL PROPIETARIO EXPULSADO: LECTURA SÍ, EDICIÓN NO');

expectBool('34. Propietario fuera del equipo conserva la lectura',
    has_board_access($conn, $BOARD_A, $U_A_ADMIN), true,
    'documentado en _perm.php: acceso garantizado al propietario');

expectBool('35. Pero ya NO puede editar',
    can_edit_board($conn, $BOARD_A, $U_A_ADMIN), false,
    'can_edit_board solo mira team_members en tableros de equipo');

expectBool('36. Sigue pudiendo administrar por ser propietario',
    can_manage_board($conn, $BOARD_A, $U_A_ADMIN), true,
    'can_manage_board acepta propietario en board_members');

// can_write_board = can_edit OR can_manage. Con manage en true, escribe.
expectBool('37. Y por tanto conserva la escritura',
    can_write_board($conn, $BOARD_A, $U_A_ADMIN), true,
    'can_write = can_edit OR can_manage');

// Se restituye la membresía para las comprobaciones siguientes.
meter_en_equipo($conn, $TEAM_A, $U_A_ADMIN,   'admin_equipo');
meter_en_equipo($conn, $TEAM_A, $U_A_MIEMBRO, 'miembro');

// ═════════════════════════════════════════════════════════════
section('38-43 · is_member_of_board_team COHERENTE');

expectBool('38. Miembro del equipo del tablero: true',
    is_member_of_board_team($conn, $BOARD_A, $U_A_MIEMBRO), true);

expectBool('39. Miembro de otro equipo: false',
    is_member_of_board_team($conn, $BOARD_A, $U_B_MIEMBRO), false);

expectBool('40. Ajeno sin equipo: false',
    is_member_of_board_team($conn, $BOARD_A, $U_AJENO), false);

expectBool('41. Tablero personal: true para cualquiera',
    is_member_of_board_team($conn, $BOARD_P, $U_AJENO), true,
    'sin equipo no hay restricción de equipo que comprobar');

expectBool('42. Tablero inexistente: false',
    is_member_of_board_team($conn, 999999999, $U_A_MIEMBRO), false);

// Coherencia cruzada: en un tablero de equipo, quien no es del equipo ni es
// propietario no debe pasar ninguna de las dos comprobaciones.
$coherente = (is_member_of_board_team($conn, $BOARD_A, $U_B_MIEMBRO) === false)
    && (has_board_access($conn, $BOARD_A, $U_B_MIEMBRO) === false);
expectBool('43. Coherencia con has_board_access en tablero de equipo',
    $coherente, true);

// ═════════════════════════════════════════════════════════════
section('44-46 · ENTRADAS INVÁLIDAS');

expectBool('44. user_id 0 nunca tiene acceso',
    has_board_access($conn, $BOARD_A, 0), false);

expectBool('45. board_id 0 nunca tiene acceso',
    has_board_access($conn, 0, $U_A_MIEMBRO), false);

expectBool('46. Identificadores negativos se rechazan',
    has_board_access($conn, -5, -5) || can_write_board($conn, -5, -5), false);

// ═════════════════════════════════════════════════════════════
section('LIMPIEZA Y VERIFICACIÓN DE RESIDUOS');

sesion_de(0);
$post = limpiar($conn);
printf("  eliminados: %d tableros, %d equipos, %d usuarios\n",
    $post['boards'], $post['teams'], $post['users']);

expect('LIMPIEZA · tableros QA',
    (int) $conn->query("SELECT COUNT(*) FROM boards WHERE nombre LIKE '" . QA_TAG . "%'")->fetch_row()[0], 0);
expect('LIMPIEZA · equipos QA',
    (int) $conn->query("SELECT COUNT(*) FROM teams WHERE nombre LIKE '" . QA_TAG . "%'")->fetch_row()[0], 0);
expect('LIMPIEZA · usuarios QA',
    (int) $conn->query("SELECT COUNT(*) FROM users WHERE email LIKE '" . QA_MAIL_LIKE . "'")->fetch_row()[0], 0);

$countAfter = [];
foreach (array_keys($countBefore) as $t) {
    $countAfter[$t] = (int) $conn->query("SELECT COUNT(*) FROM `$t`")->fetch_row()[0];
}
foreach ($countBefore as $t => $n) {
    expect("LIMPIEZA · filas en $t", $countAfter[$t], $n);
}

// ═════════════════════════════════════════════════════════════
echo "\n" . str_repeat('═', 74) . "\n";
printf(" RESULTADO: %d correctas, %d fallidas\n", $PASS, $FAIL);
echo str_repeat('═', 74) . "\n";
exit($FAIL === 0 ? 0 : 1);
