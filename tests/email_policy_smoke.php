<?php
/**
 * tests/email_policy_smoke.php
 *
 * Política de entrega por correo (fase 1: base segura, sin SMTP real).
 *
 * Ejecutar SOLO en local:
 *   php tests/email_policy_smoke.php
 *
 * QUÉ VIGILA
 *   · que los destinatarios se calculen con el contrato correcto —el fallo que
 *     dejaba a los administradores del sistema sin recibir nada—;
 *   · que cada tipo de evento tenga el modo de entrega decidido, y que uno
 *     desconocido no empiece a mandar correos por descuido;
 *   · que el resumen agrupe por persona, uno al día, sin mezclar a nadie;
 *   · que emailed_at solo se marque tras un envío con éxito;
 *   · que el simulacro no escriba absolutamente nada;
 *   · que un argumento mal escrito no acabe ejecutando el modo real.
 *
 * CERO CORREOS
 *   El SMTP se apunta a una dirección imposible ANTES de cargar la
 *   configuración, aprovechando las guardas defined(). Aunque hubiera un
 *   captador local escuchando, esta suite no podría entregarle nada.
 *
 * No deja residuos: crea sus propios usuarios QA y los retira.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Este script solo puede ejecutarse desde CLI.\n");
}

$ROOT = dirname(__DIR__);

// ── Blindaje de envío ────────────────────────────────────────
// Se definen antes que config/mail.php: sus defined() || respetan estos
// valores y el SMTP queda apuntando a un destino inalcanzable.
define('MAIL_SMTP_HOST', '127.0.0.1');
define('MAIL_SMTP_PORT', 9);          // discard, y ademas cerrado
define('MAIL_ENABLED', true);         // se quiere ejercitar el CAMINO de fallo

// Política activa con un instante conocido, para poder probar el corte.
define('EMAIL_POLICY_START', '2000-01-01 00:00:00');

require_once $ROOT . '/config/bootstrap.php';
require_once $ROOT . '/config/db.php';
app_sync_db_timezone($conn);
require_once $ROOT . '/config/mail.php';
require_once $ROOT . '/public/admin/_alerts_core.php';
require_once $ROOT . '/public/admin/_email_policy.php';
require_once $ROOT . '/public/admin/_email_helpers.php';
require_once __DIR__ . '/_qa_users.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

const QA_SUITE = 'emailpolicy';
const QA_TAG   = 'QA EMAILPOLICY';

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
function uno(mysqli $c, string $sql): int
{
    return (int) $c->query($sql)->fetch_row()[0];
}

/** Inserta una notificación QA directamente, sin pasar por las reglas. */
function notif(mysqli $c, int $userId, string $tipo, ?string $creado = null): int
{
    $payload = json_encode(['qa' => true, 'board_id' => 0, 'user_name' => 'QA', 'asignadas' => 11, 'vencidas' => 2,
                            'board_name' => 'QA', 'team_name' => null, 'vencidas_n' => 1, 'tareas' => 10, 'pct' => 50]);
    if ($creado === null) {
        $st = $c->prepare("INSERT INTO notifications (user_id, tipo, payload_json) VALUES (?,?,?)");
        $st->bind_param('iss', $userId, $tipo, $payload);
    } else {
        $st = $c->prepare("INSERT INTO notifications (user_id, tipo, payload_json, created_at) VALUES (?,?,?,?)");
        $st->bind_param('isss', $userId, $tipo, $payload, $creado);
    }
    $st->execute();
    $id = (int) $c->insert_id;
    $st->close();
    return $id;
}

function emailed_at(mysqli $c, int $id): ?string
{
    $st = $c->prepare("SELECT emailed_at FROM notifications WHERE id = ?");
    $st->bind_param('i', $id);
    $st->execute();
    $v = $st->get_result()->fetch_row()[0] ?? null;
    $st->close();
    return $v;
}

echo "══════════════════════════════════════════════════════════════════════════════\n";
echo " POLÍTICA DE ENTREGA POR CORREO\n";
echo "══════════════════════════════════════════════════════════════════════════════\n";
echo " PHP   : " . PHP_VERSION . "\n";
echo " Base  : " . $conn->query("SELECT DATABASE()")->fetch_row()[0] . "\n";
echo " SMTP  : " . MAIL_SMTP_HOST . ':' . MAIL_SMTP_PORT . " (inalcanzable a propósito)\n";
echo " Fecha : " . date('Y-m-d H:i:s') . "\n";

qa_users_cleanup_stale($conn, QA_SUITE);
$notifInicio = uno($conn, "SELECT COUNT(*) FROM notifications");

// La marca de arranque real se guarda y se restaura al terminar: esta suite no
// puede dejar el sistema creyendo que ya arrancó, ni al revés.
$marcaReal = email_bootstrap_marca($conn);
$conn->query("DELETE FROM app_settings WHERE clave = '" . EMAIL_BOOTSTRAP_CLAVE . "'");

// ═════════════════════════════════════════════════════════════
section('1-8 · DESTINATARIOS: EL CONTRATO CORREGIDO');

chk('1. El contrato exige aprobado, activo y sin borrar',
    str_contains(alert_receptor_valido_sql('u'), "estado = 'aprobado'")
    && str_contains(alert_receptor_valido_sql('u'), 'activo = 1')
    && str_contains(alert_receptor_valido_sql('u'), 'deleted_at IS NULL'),
    alert_receptor_valido_sql('u'));

chk('2. Ya no queda el valor inexistente estado = activo',
    !str_contains(file_get_contents(dirname(__DIR__) . '/public/admin/_alerts_core.php'),
        "u.estado = 'activo'"),
    'el ENUM solo admite pendiente, aprobado y rechazado');

// Un usuario por cada caso del contrato.
$uSuper   = qa_user($conn, QA_SUITE, ['rol' => 'super_admin', 'is_admin' => 1]);
$uAdmin   = qa_user($conn, QA_SUITE, ['rol' => 'coordinador', 'is_admin' => 1]);
$uInact   = qa_user($conn, QA_SUITE, ['rol' => 'super_admin', 'is_admin' => 1, 'activo' => 0]);
$uPend    = qa_user($conn, QA_SUITE, ['rol' => 'super_admin', 'is_admin' => 1, 'estado' => 'pendiente']);
$uRech    = qa_user($conn, QA_SUITE, ['rol' => 'super_admin', 'is_admin' => 1, 'estado' => 'rechazado']);
$uBorrado = qa_user($conn, QA_SUITE, ['rol' => 'super_admin', 'is_admin' => 1, 'deleted_at' => '2026-01-01 00:00:00']);
$uNormal  = qa_user($conn, QA_SUITE, ['rol' => 'user', 'is_admin' => 0]);

function es_receptor(mysqli $c, int $id): bool
{
    return uno($c, "SELECT COUNT(*) FROM users WHERE id = $id AND is_admin = 1 AND " . alert_receptor_valido_sql('')) === 1;
}

chk('3. super_admin aprobado y activo SÍ es destinatario',  es_receptor($conn, $uSuper));
chk('4. admin aprobado y activo SÍ es destinatario',        es_receptor($conn, $uAdmin));
chk('5. aprobado pero activo=0 NO',                          !es_receptor($conn, $uInact));
chk('6. estado pendiente NO',                                !es_receptor($conn, $uPend));
chk('7. estado rechazado NO',                                !es_receptor($conn, $uRech));
chk('8. con deleted_at NO',                                  !es_receptor($conn, $uBorrado));
chk('9. usuario sin is_admin NO entra como admin del sistema', !es_receptor($conn, $uNormal));

// ═════════════════════════════════════════════════════════════
section('10-16 · MODO DE ENTREGA');

chk('10. alert_user_overload es inmediato',
    email_delivery_mode('alert_user_overload') === EMAIL_MODE_IMMEDIATE);

chk('11. Las tres alertas de tablero van al resumen',
    email_delivery_mode('alert_team_overdue') === EMAIL_MODE_DIGEST
    && email_delivery_mode('alert_team_stale') === EMAIL_MODE_DIGEST
    && email_delivery_mode('alert_team_unassigned') === EMAIL_MODE_DIGEST);

$actividad = ['task_moved', 'task_assignee_changed', 'task_commented',
              'task_description_changed', 'task_date_changed', 'task_priority_changed'];
$todosNone = true;
foreach ($actividad as $t) {
    if (email_delivery_mode($t) !== EMAIL_MODE_NONE) {
        $todosNone = false;
    }
}
chk('12. Toda la actividad normal es NONE', $todosNone, count($actividad) . ' tipos');

chk('13. Un tipo desconocido cae en NONE, no en correo',
    email_delivery_mode('tipo_que_no_existe_todavia') === EMAIL_MODE_NONE,
    'cierra en falso');

chk('14. Cadena vacía también cae en NONE', email_delivery_mode('') === EMAIL_MODE_NONE);

chk('15. Los tipos por modo cuadran',
    count(email_tipos_por_modo(EMAIL_MODE_IMMEDIATE)) === 1
    && count(email_tipos_por_modo(EMAIL_MODE_DIGEST)) === 3
    && count(email_tipos_por_modo(EMAIL_MODE_NONE)) === 6,
    '1 inmediato, 3 resumen, 6 ninguno');

chk('16. La política está apagada por defecto',
    !str_contains(file_get_contents(dirname(__DIR__) . '/public/admin/_email_policy.php'),
        "define('EMAIL_POLICY_START', '20"),
    'EMAIL_POLICY_START nace en null: sin activar no sale ni un correo');

// ═════════════════════════════════════════════════════════════
section('17-24 · RESUMEN: AGRUPACIÓN Y TOPE DIARIO');

// Sin marca de arranque no hay corte y no se envía nada: es el fallo seguro.
// Se anota una antigua para que las notificaciones QA que siguen queden
// después de ella y puedan evaluarse.
email_bootstrap_registrar($conn, '2000-01-02 00:00:00');

$nA1 = notif($conn, $uSuper, 'alert_team_overdue');
$nA2 = notif($conn, $uSuper, 'alert_team_stale');
$nA3 = notif($conn, $uSuper, 'alert_team_unassigned');
$nB1 = notif($conn, $uAdmin, 'alert_team_overdue');

$d = email_digest_pendiente($conn);
chk('17. Agrupa por persona', isset($d[$uSuper]) && isset($d[$uAdmin]),
    count($d) . ' personas con resumen pendiente');

chk('18. Dos personas producen dos resúmenes distintos',
    count($d[$uSuper]) === 3 && count($d[$uAdmin]) === 1,
    'super=3 alertas · admin=1 alerta');

chk('19. Ninguna alerta aparece en el resumen de otra persona',
    array_reduce($d[$uSuper], static fn($c, $f) => $c && (int) $f['user_id'] === $uSuper, true)
    && array_reduce($d[$uAdmin], static fn($c, $f) => $c && (int) $f['user_id'] === $uAdmin, true));

// Diez alertas de una sola persona siguen siendo UN resumen.
$muchas = [];
for ($i = 0; $i < 10; $i++) {
    $muchas[] = notif($conn, $uNormal, 'alert_team_overdue');
}
$d2 = email_digest_pendiente($conn);
chk('20. Diez alertas de una persona caben en un solo resumen',
    isset($d2[$uNormal]) && count($d2[$uNormal]) === 10,
    'una entrada por persona, con 10 alertas dentro');

chk('21. Lo inmediato NO entra en el resumen',
    (function () use ($conn, $uSuper) {
        $id = notif($conn, $uSuper, 'alert_user_overload');
        $d  = email_digest_pendiente($conn);
        $dentro = false;
        foreach ($d[$uSuper] ?? [] as $f) {
            if ((int) $f['id'] === $id) {
                $dentro = true;
            }
        }
        return !$dentro;
    })(), 'alert_user_overload va por su cuenta');

chk('22. La actividad normal NO entra en el resumen',
    (function () use ($conn, $uSuper) {
        $id = notif($conn, $uSuper, 'task_moved');
        $d  = email_digest_pendiente($conn);
        foreach ($d[$uSuper] ?? [] as $f) {
            if ((int) $f['id'] === $id) {
                return false;
            }
        }
        return true;
    })());

// Tope de uno al día: se simula un resumen ya entregado hoy.
$conn->query("UPDATE notifications SET emailed_at = NOW() WHERE id = $nB1");
$d3 = email_digest_pendiente($conn);
$nB2 = notif($conn, $uAdmin, 'alert_team_stale');
$d4 = email_digest_pendiente($conn);
chk('23. Quien ya recibió su resumen hoy no recibe otro',
    !isset($d4[$uAdmin]),
    'aunque le hayan surgido alertas nuevas después');

chk('24. Los demás siguen recibiendo el suyo', isset($d4[$uSuper]) && isset($d4[$uNormal]));

// ═════════════════════════════════════════════════════════════
section('25-29 · emailed_at: DUPLICADOS, FALLO Y CORTE');

chk('25. Una alerta ya marcada no vuelve al resumen',
    (function () use ($conn, $uSuper, $nA1) {
        $antes = count(email_digest_pendiente($conn)[$uSuper] ?? []);
        $conn->query("UPDATE notifications SET emailed_at = NOW() WHERE id = $nA1");
        $despues = count(email_digest_pendiente($conn)[$uSuper] ?? []);
        // El usuario queda fuera entero por el tope diario, que es aún más
        // restrictivo: en cualquier caso la alerta marcada no reaparece.
        return $despues < $antes;
    })(), 'emailed_at IS NULL es la condición');

// Camino de fallo REAL: el SMTP apunta a un puerto cerrado.
$idFallo = notif($conn, $uSuper, 'alert_user_overload');
$enviados = enviar_inmediatos($conn, [$idFallo]);
chk('26. Un envío fallido no entrega nada', $enviados === 0, "entregados=$enviados");
chk('27. Y NO marca emailed_at', emailed_at($conn, $idFallo) === null,
    'la fila queda pendiente y se reintentará');

$idFallo2 = notif($conn, $uNormal, 'alert_team_overdue');
$res = enviar_digests($conn);
chk('28. Un resumen fallido tampoco marca nada',
    $res === 0 && emailed_at($conn, $idFallo2) === null, "resúmenes=$res");

// Corte de activación: lo anterior a EMAIL_POLICY_START nunca sale.
$idViejo = notif($conn, $uSuper, 'alert_team_overdue', '1999-01-01 00:00:00');
$d5 = email_digest_pendiente($conn);
$hayViejo = false;
foreach ($d5[$uSuper] ?? [] as $f) {
    if ((int) $f['id'] === $idViejo) {
        $hayViejo = true;
    }
}
chk('29. Lo creado antes de la activación queda fuera', !$hayViejo,
    'created_at >= EMAIL_POLICY_START');

// ═════════════════════════════════════════════════════════════
section('30-35 · EL CRON: SIMULACRO Y CERROJOS');

$cron = dirname(__DIR__) . '/cron/run_alerts.php';

function correr_cron(string $cron, array $args = []): array
{
    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($cron);
    foreach ($args as $a) {
        $cmd .= ' ' . escapeshellarg($a);
    }
    $out = [];
    $code = 0;
    exec($cmd . ' 2>&1', $out, $code);
    return [$code, implode("\n", $out)];
}

$antesN = uno($conn, "SELECT COUNT(*) FROM notifications");
$antesE = uno($conn, "SELECT COUNT(*) FROM notifications WHERE emailed_at IS NOT NULL");
[$code, $out] = correr_cron($cron, ['--dry-run']);
$despuesN = uno($conn, "SELECT COUNT(*) FROM notifications");
$despuesE = uno($conn, "SELECT COUNT(*) FROM notifications WHERE emailed_at IS NOT NULL");

chk('30. El simulacro termina bien', $code === 0, "exit=$code");
chk('31. Y dice que no ha escrito nada', str_contains($out, 'SIMULACRO'), 'lo anuncia en la primera línea');
chk('32. No crea ninguna notificación', $despuesN === $antesN, "$antesN -> $despuesN");
chk('33. Ni marca ninguna como enviada', $despuesE === $antesE, "$antesE -> $despuesE");

$malos = ['--dry-runn', '--seco', '-x', '--send'];
$todosDos = true;
foreach ($malos as $m) {
    [$c2, ] = correr_cron($cron, [$m]);
    if ($c2 !== 2) {
        $todosDos = false;
        ko('34. Un argumento inválido aborta con código 2', "«$m» devolvió $c2");
        break;
    }
}
if ($todosDos) {
    ok('34. Un argumento inválido aborta con código 2', count($malos) . ' formas probadas');
}

[$c3, $o3] = correr_cron($cron, ['--help']);
chk('35. --help documenta el simulacro y no ejecuta nada',
    $c3 === 0 && str_contains($o3, '--dry-run'));

// ═════════════════════════════════════════════════════════════
section('36-42 · ARRANQUE: LA PRIMERA PASADA NO ENVÍA');

// Este bloque reproduce la secuencia REAL del cron, no el simulacro: crea
// alertas, decide si es arranque, anota la marca y comprueba la elegibilidad.
// Un simulacro no probaría nada aquí, porque por definición nunca envía.
$conn->query("DELETE FROM app_settings WHERE clave = '" . EMAIL_BOOTSTRAP_CLAVE . "'");

chk('36. Sin marca de arranque no hay corte',
    email_cutoff_efectivo($conn) === null,
    'y sin corte no se selecciona nada');

// Alertas «del arranque»: describen el estado que ya existía.
$bootImm  = notif($conn, $uSuper, 'alert_user_overload');
$bootDig1 = notif($conn, $uSuper, 'alert_team_overdue');
$bootDig2 = notif($conn, $uAdmin, 'alert_team_stale');

chk('37. Con alertas creadas y sin marca, 0 elegibles',
    count(email_pendientes_inmediatas($conn, [$bootImm])) === 0
    && count(email_digest_pendiente($conn)) === 0,
    'el sistema calla mientras no sepa desde cuándo contar');

// El cron anota la marca DESPUÉS de crear las alertas.
$marcaBoot = date('Y-m-d H:i:s');
chk('38. La marca se anota correctamente',
    email_bootstrap_registrar($conn, $marcaBoot)
    && email_bootstrap_marca($conn) === $marcaBoot, $marcaBoot);

chk('39. La primera activación crea alertas pero produce 0 envíos',
    count(email_pendientes_inmediatas($conn, [$bootImm])) === 0
    && count(email_digest_pendiente($conn)) === 0,
    '3 alertas creadas, 0 correos: el corte las deja fuera para siempre');

chk('40. Y no marca ninguna como enviada',
    emailed_at($conn, $bootImm) === null
    && emailed_at($conn, $bootDig1) === null
    && emailed_at($conn, $bootDig2) === null,
    'emailed_at intacto: nada se declara enviado sin haberlo sido');

// Una alerta posterior al arranque SÍ es elegible.
sleep(1);
$postImm = notif($conn, $uSuper, 'alert_user_overload');
$postDig = notif($conn, $uNormal, 'alert_team_overdue');

chk('41. Una alerta creada después del arranque sí es elegible',
    count(email_pendientes_inmediatas($conn, [$postImm])) === 1,
    'el inmediato entra');

chk('42. Y también entra en el resumen',
    (function () use ($conn, $uNormal, $postDig) {
        foreach (email_digest_pendiente($conn)[$uNormal] ?? [] as $f) {
            if ((int) $f['id'] === $postDig) {
                return true;
            }
        }
        return false;
    })());

// Volver a intentar el arranque no mueve la marca: reiniciar el cron no
// puede silenciar avisos legítimos.
email_bootstrap_registrar($conn, date('Y-m-d H:i:s', time() + 3600));
chk('43. Reintentar el arranque NO mueve la marca',
    email_bootstrap_marca($conn) === $marcaBoot,
    'sigue siendo ' . $marcaBoot);

$conn->query("DELETE FROM notifications WHERE id IN ($bootImm,$bootDig1,$bootDig2,$postImm,$postDig)");

// ═════════════════════════════════════════════════════════════
section('44-52 · DOS PASADAS REALES CONSECUTIVAS');

// La deduplicacion de alert_exists() y el corte de arranque son dos cosas
// distintas, y conviene fijar como interactuan. Aqui se ejecuta la secuencia
// REAL del cron dos veces seguidas SIN tocar nada entre medias: ni borrar, ni
// marcar leido, ni cambiar el escenario.

$conn->query("DELETE FROM app_settings WHERE clave = '" . EMAIL_BOOTSTRAP_CLAVE . "'");
$conn->query("DELETE FROM notifications WHERE user_id IN ($uSuper,$uAdmin,$uNormal)");

// Las reglas tambien avisan a los administradores REALES de la base, no solo
// a los usuarios QA. Se anota el ultimo id para retirar despues exactamente
// lo que cree esta seccion, sin rozar ninguna notificacion anterior.
$idAntes = uno($conn, 'SELECT COALESCE(MAX(id),0) FROM notifications');

/** Tablero QA con tareas vencidas asignadas a alguien. */
function escenario_qa(mysqli $c, string $sufijo, int $duenio, int $quien, int $tareas): int
{
    $st = $c->prepare("INSERT INTO boards (nombre, owner_user_id, visibility, created_at) VALUES (?,?, 'private', NOW())");
    $n = QA_TAG . ' ' . $sufijo;
    $st->bind_param('si', $n, $duenio);
    $st->execute();
    $b = (int) $c->insert_id;
    $st->close();
    $c->query("INSERT INTO `columns` (board_id,nombre,orden,is_done,created_at) VALUES ($b,'Por hacer',0,0,NOW())");
    $col = (int) $c->insert_id;
    for ($i = 0; $i < $tareas; $i++) {
        $st = $c->prepare("INSERT INTO tasks (board_id,column_id,titulo,prioridad,assignee_id,fecha_limite,sort_order,creado_en)
                           VALUES (?,?,?, 'med', ?, NOW() - INTERVAL 10 DAY, ?, NOW())");
        $t = QA_TAG . ' t' . $i;
        $st->bind_param('iisii', $b, $col, $t, $quien, $i);
        $st->execute();
        $st->close();
    }
    return $b;
}

/** Secuencia exacta de cron/run_alerts.php en modo real. */
function pasada_cron(mysqli $conn): array
{
    $r = run_all_alerts($conn);
    $arranque = false;
    $inm = [];
    $dig = [];
    if (email_policy_activa()) {
        if (email_bootstrap_marca($conn) === null) {
            $arranque = true;
            email_bootstrap_registrar($conn, date('Y-m-d H:i:s'));
        } else {
            $inm = email_pendientes_inmediatas($conn, $r['new_ids'] ?? []);
            $dig = email_digest_pendiente($conn);
        }
    }
    return ['creadas' => $r['inserted'], 'saltadas' => $r['skipped'], 'ids' => $r['new_ids'],
            'arranque' => $arranque, 'correos' => count($inm) + count($dig),
            'inm' => count($inm), 'dig' => count($dig)];
}

escenario_qa($conn, 'CONSEC-A', $uSuper, $uNormal, 12);

$p1 = pasada_cron($conn);
chk('44. La 1a pasada es el arranque y crea alertas',
    $p1['arranque'] && $p1['creadas'] > 0, 'creadas=' . $p1['creadas']);

chk('45. La 1a pasada no produce ningun correo',
    $p1['correos'] === 0, 'correos=' . $p1['correos']);

// SIN TOCAR NADA
$p2 = pasada_cron($conn);
chk('46. La 2a pasada inmediata NO crea alertas nuevas',
    $p2['creadas'] === 0 && $p2['saltadas'] > 0,
    'alert_exists deduplica: creadas=' . $p2['creadas'] . ' saltadas=' . $p2['saltadas']);

chk('47. Y tampoco produce correos',
    $p2['correos'] === 0,
    'las de la 1a quedaron antes del corte y no hay nuevas');

chk('48. Ninguna quedo marcada como enviada',
    uno($conn, "SELECT COUNT(*) FROM notifications WHERE user_id IN ($uSuper,$uAdmin,$uNormal) AND emailed_at IS NOT NULL") === 0);

// Contexto genuinamente nuevo: otro tablero. alert_exists deduplica por
// (usuario, tipo, contexto), asi que maybe_insert tiene derecho a crear.
sleep(1);
escenario_qa($conn, 'CONSEC-B', $uSuper, $uNormal, 8);
$p3 = pasada_cron($conn);

chk('49. Un contexto nuevo si genera alertas tras el arranque',
    $p3['creadas'] > 0, 'creadas=' . $p3['creadas'] . ' (tablero distinto)');

chk('50. Y esas si quedan elegibles para correo',
    $p3['correos'] > 0, 'correos=' . $p3['correos']);

$cutoff = email_cutoff_efectivo($conn);
$enIds = implode(',', array_map('intval', $p3['ids']));
chk('51. Todas las nuevas tienen created_at posterior al corte',
    $enIds !== '' && uno($conn, "SELECT COUNT(*) FROM notifications WHERE id IN ($enIds) AND created_at > '$cutoff'") === count($p3['ids']),
    'corte=' . $cutoff);

// El bug que aparecio al probar esto: con MAIL_ENABLED=false, smtp_send()
// devuelve true sin enviar y el emisor lo tomaba por exito. Se comprueba en un
// proceso aparte porque MAIL_ENABLED es una constante y aqui vale true.
$tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'qa_mail_off_' . bin2hex(random_bytes(4)) . '.php';
$codigo = <<<'PHPCODE'
<?php
define('MAIL_ENABLED', false);
define('MAIL_SMTP_HOST', '127.0.0.1');
define('MAIL_SMTP_PORT', 9);
$ROOT = $argv[3];   // la raiz la pasa el proceso padre: nada escrito a mano
require $ROOT . '/config/bootstrap.php';
define('EMAIL_POLICY_START', '2000-01-01 00:00:00');
require $ROOT . '/config/db.php';
app_sync_db_timezone($conn);
require $ROOT . '/config/mail.php';
require $ROOT . '/public/admin/_alerts_core.php';
require $ROOT . '/public/admin/_email_policy.php';
require $ROOT . '/public/admin/_email_helpers.php';
$uid = (int) $argv[1];
$id  = (int) $argv[2];
$n1 = enviar_inmediatos($conn, [$id]);
$n2 = enviar_digests($conn);
$marcado = $conn->query("SELECT emailed_at FROM notifications WHERE id = $id")->fetch_row()[0];
echo json_encode(['inm' => $n1, 'dig' => $n2, 'emailed_at' => $marcado]);
PHPCODE;
file_put_contents($tmp, $codigo);
$idPrueba = (int) ($p3['ids'][0] ?? 0);
$salida = (string) shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($tmp)
    . ' ' . $uSuper . ' ' . $idPrueba . ' ' . escapeshellarg(str_replace('\\', '/', dirname(__DIR__))) . ' 2>&1');
@unlink($tmp);
$j = json_decode(trim($salida), true);

chk('52. Con MAIL_ENABLED=false no se entrega nada',
    is_array($j) && $j['inm'] === 0 && $j['dig'] === 0,
    is_array($j) ? "inm={$j['inm']} dig={$j['dig']}" : substr(trim($salida), 0, 90));

chk('53. Y sobre todo, NO marca emailed_at',
    is_array($j) && $j['emailed_at'] === null,
    'smtp_send devuelve true con el correo apagado; marcarlo perderia el aviso');

$conn->query("DELETE FROM notifications WHERE id > $idAntes");
$conn->query("DELETE FROM boards WHERE nombre LIKE '" . QA_TAG . "%'");

// ═════════════════════════════════════════════════════════════
section('LIMPIEZA');

$conn->query("DELETE FROM notifications WHERE user_id IN ($uSuper,$uAdmin,$uInact,$uPend,$uRech,$uBorrado,$uNormal)");
$r = qa_users_cleanup($conn);
$conn->query("DELETE FROM app_settings WHERE clave = '" . EMAIL_BOOTSTRAP_CLAVE . "'");
if ($marcaReal !== null) {
    email_bootstrap_registrar($conn, $marcaReal);
}
$notifFin = uno($conn, "SELECT COUNT(*) FROM notifications");

chk('LIMPIEZA · usuarios QA retirados', $r['users'] === 7, json_encode($r));
chk('LIMPIEZA · no quedan usuarios de esta suite', qa_users_restantes($conn, QA_SUITE) === 0);
chk('LIMPIEZA · notificaciones: sin variación', $notifFin === $notifInicio,
    "$notifInicio -> $notifFin");
chk('LIMPIEZA · la marca de arranque real queda como estaba',
    email_bootstrap_marca($conn) === $marcaReal,
    $marcaReal === null ? 'no había ninguna' : $marcaReal);

echo "\n══════════════════════════════════════════════════════════════════════════════\n";
printf(" RESULTADO: %d correctas, %d fallidas\n", $PASS, $FAIL);
echo "══════════════════════════════════════════════════════════════════════════════\n\n";
exit($FAIL === 0 ? 0 : 1);
