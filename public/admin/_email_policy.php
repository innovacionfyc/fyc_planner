<?php
// public/admin/_email_policy.php — Política de entrega por correo
// Solo definiciones: no ejecuta nada al incluirse, ni envía nada.
//
// POR QUÉ EXISTE
//   Hasta ahora send_alert_emails() mandaba un correo por cada notificación
//   recién insertada, sin agrupar, sin ventana y sin tope. Con las condiciones
//   actuales de producción, la primera pasada del cron habría soltado decenas
//   de correos de golpe sobre situaciones que llevan meses ahí.
//
//   Este archivo concentra la decisión de QUÉ se envía y CÓMO, para que no
//   quede repartida en condicionales por el código. No envía: solo decide y
//   selecciona.
//
// LA POLÍTICA
//   · Actividad normal (mover, comentar, asignar…) → nunca por correo.
//     Vive en el Planner, en la campana.
//   · alert_user_overload → correo inmediato. Es el único aviso que llega a
//     quien puede actuar sobre él en ese momento.
//   · Alertas de tablero → resumen diario, uno por persona como máximo.
//   · Nada que contar → ningún correo. No se manda «no hay novedades».

declare(strict_types=1);

// ─────────────────────────────────────────────────────────────
// Modos de entrega
// ─────────────────────────────────────────────────────────────
const EMAIL_MODE_IMMEDIATE = 'IMMEDIATE';
const EMAIL_MODE_DIGEST    = 'DIGEST';
const EMAIL_MODE_NONE      = 'NONE';

/**
 * Fecha y hora en que la política de correo entra en vigor.
 *
 * Mientras valga null NO se envía absolutamente nada: ni inmediatos ni
 * resúmenes. Es el interruptor general, y está apagado a propósito.
 *
 * Cuando se active, hay que ponerle el instante de la activación. Eso resuelve
 * de paso el problema del arranque: las notificaciones creadas ANTES de esa
 * marca no se envían nunca. La primera pasada del cron va a crear alertas
 * sobre tableros que llevan meses vencidos, y nadie quiere recibir treinta
 * correos sobre algo que ya sabía.
 *
 * Se define con defined() || para que un entorno pueda fijarlo desde su
 * configuración sin tocar este archivo.
 *
 * Formato: 'YYYY-MM-DD HH:MM:SS' en la zona de la aplicación, o null.
 */
defined('EMAIL_POLICY_START') || define('EMAIL_POLICY_START', null);

/**
 * Modo de entrega de un tipo de notificación.
 *
 * Cierra en falso: cualquier tipo que no esté en la tabla devuelve NONE. Si
 * mañana alguien añade un evento nuevo, no empezará a mandar correos por
 * descuido; habrá que declararlo aquí de forma explícita.
 */
function email_delivery_mode(string $tipo): string
{
    static $tabla = [
        // Único aviso que va directo: le llega a la persona sobrecargada y
        // puede reaccionar hoy mismo.
        'alert_user_overload'      => EMAIL_MODE_IMMEDIATE,

        // Diagnóstico de gestión: interesa, no urge. Va al resumen.
        'alert_team_overdue'       => EMAIL_MODE_DIGEST,
        'alert_team_stale'         => EMAIL_MODE_DIGEST,
        'alert_team_unassigned'    => EMAIL_MODE_DIGEST,

        // Actividad normal: se queda dentro del Planner.
        'task_moved'               => EMAIL_MODE_NONE,
        'task_assignee_changed'    => EMAIL_MODE_NONE,
        'task_commented'           => EMAIL_MODE_NONE,
        'task_description_changed' => EMAIL_MODE_NONE,
        'task_date_changed'        => EMAIL_MODE_NONE,
        'task_priority_changed'    => EMAIL_MODE_NONE,
    ];
    return $tabla[$tipo] ?? EMAIL_MODE_NONE;
}

/** Tipos declarados con un modo concreto. Útil para construir consultas. */
function email_tipos_por_modo(string $modo): array
{
    $todos = [
        'alert_user_overload', 'alert_team_overdue', 'alert_team_stale',
        'alert_team_unassigned', 'task_moved', 'task_assignee_changed',
        'task_commented', 'task_description_changed', 'task_date_changed',
        'task_priority_changed',
    ];
    $out = [];
    foreach ($todos as $t) {
        if (email_delivery_mode($t) === $modo) {
            $out[] = $t;
        }
    }
    return $out;
}

/** ¿Está la política activada? Con EMAIL_POLICY_START a null, no. */
function email_policy_activa(): bool
{
    return EMAIL_POLICY_START !== null && EMAIL_POLICY_START !== '';
}

// ─────────────────────────────────────────────────────────────
// ARRANQUE
//
// EMAIL_POLICY_START es el interruptor, NO el corte. Se creyó que servía de
// las dos cosas y no es así: las alertas que crea la PRIMERA pasada llevan
// created_at posterior a esa constante, así que pasaban el filtro y se
// habrían enviado. Medido: 6 correos en un escenario mínimo de la base QA y
// 13 sobre la copia de producción.
//
// El corte real tiene que tomarse DESPUÉS de crear las alertas iniciales, y
// por eso no puede escribirse a mano: lo anota el propio cron en app_settings
// la primera vez que corre con la política activa. A partir de ahí solo se
// envía lo creado ESTRICTAMENTE después de esa marca.
//
// Consecuencia buscada: la primera pasada retrata el estado que ya existía
// —tableros vencidos desde hace meses— sin avisar a nadie de algo que ya
// sabía, y no hace falta ejecutar nada en un orden concreto.
// ─────────────────────────────────────────────────────────────

const EMAIL_BOOTSTRAP_CLAVE = 'email_bootstrap_at';

/** Marca de arranque, o null si el sistema aún no ha hecho su primera pasada. */
function email_bootstrap_marca(mysqli $conn): ?string
{
    $st = $conn->prepare("SELECT valor FROM app_settings WHERE clave = ? LIMIT 1");
    if (!$st) {
        return null;
    }
    $clave = EMAIL_BOOTSTRAP_CLAVE;
    $st->bind_param('s', $clave);
    $st->execute();
    $fila = $st->get_result()->fetch_row();
    $st->close();
    $v = $fila[0] ?? null;
    return ($v === null || $v === '') ? null : (string) $v;
}

/**
 * Anota la marca de arranque. Solo la primera vez: si ya existe no se pisa,
 * porque volver a escribirla movería el corte y silenciaría avisos legítimos.
 *
 * @return bool true si quedó anotada (ahora o antes)
 */
function email_bootstrap_registrar(mysqli $conn, string $instante): bool
{
    $st = $conn->prepare(
        "INSERT INTO app_settings (clave, valor) VALUES (?, ?)
         ON DUPLICATE KEY UPDATE clave = clave"
    );
    if (!$st) {
        return false;
    }
    $clave = EMAIL_BOOTSTRAP_CLAVE;
    $st->bind_param('ss', $clave, $instante);
    $ok = $st->execute();
    $st->close();
    return $ok && email_bootstrap_marca($conn) !== null;
}

/**
 * Corte efectivo: solo se envía lo creado DESPUÉS de este instante.
 *
 * Devuelve null mientras no haya arranque anotado, y con null no se envía
 * nada. Es el fallo seguro: si la marca no se pudiera leer o escribir, el
 * sistema calla en vez de disparar.
 */
function email_cutoff_efectivo(mysqli $conn): ?string
{
    if (!email_policy_activa()) {
        return null;
    }
    return email_bootstrap_marca($conn);
}

/**
 * Día natural actual en la zona de la aplicación.
 *
 * bootstrap.php fija America/Bogota y alinea la sesión de MySQL con el mismo
 * desfase, así que PHP y la base coinciden. Colombia no aplica horario de
 * verano: no hay días de 23 ni de 25 horas, y «un resumen al día» no tiene
 * ambigüedad. Por eso se usa el día natural y no una ventana de 24 horas
 * móviles, que desplazaría la hora de envío en cada pasada.
 */
function email_dia_actual(): string
{
    return date('Y-m-d');
}

/** Marcadores SQL para una lista de tipos. Devuelve [sql, tipos, valores]. */
function email_sql_en_lista(array $tipos): array
{
    if ($tipos === []) {
        return ['(NULL)', '', []];
    }
    return ['(' . implode(',', array_fill(0, count($tipos), '?')) . ')',
            str_repeat('s', count($tipos)), $tipos];
}

/**
 * Notificaciones que saldrían por correo INMEDIATO.
 *
 * Solo las recién creadas en esta pasada, del modo inmediato, sin marcar como
 * enviadas y posteriores a la activación de la política.
 *
 * @param array $idsNuevos ids devueltos por run_all_alerts()
 * @return array filas con id, user_id, tipo, payload_json, created_at
 */
function email_pendientes_inmediatas(mysqli $conn, array $idsNuevos): array
{
    $corte = email_cutoff_efectivo($conn);
    if ($corte === null || $idsNuevos === []) {
        return [];
    }
    $tipos = email_tipos_por_modo(EMAIL_MODE_IMMEDIATE);
    if ($tipos === []) {
        return [];
    }
    $ids  = array_values(array_filter(array_map('intval', $idsNuevos), static fn(int $i): bool => $i > 0));
    if ($ids === []) {
        return [];
    }
    $marcasId   = implode(',', array_fill(0, count($ids), '?'));
    [$marcasTipo, $tiposTipo, $valsTipo] = email_sql_en_lista($tipos);

    $sql = "SELECT id, user_id, tipo, payload_json, created_at
              FROM notifications
             WHERE id IN ($marcasId)
               AND tipo IN $marcasTipo
               AND emailed_at IS NULL
               AND created_at > ?
             ORDER BY user_id, id";
    $st = $conn->prepare($sql);
    if (!$st) {
        return [];
    }
    $args  = array_merge($ids, $valsTipo, [$corte]);
    $tipos2 = str_repeat('i', count($ids)) . $tiposTipo . 's';
    $refs = [];
    foreach ($args as $k => $v) {
        $refs[$k] = &$args[$k];
    }
    array_unshift($refs, $tipos2);
    call_user_func_array([$st, 'bind_param'], $refs);
    $st->execute();
    $out = $st->get_result()->fetch_all(MYSQLI_ASSOC);
    $st->close();
    return $out;
}

/**
 * Resúmenes pendientes, agrupados por persona.
 *
 * Recoge TODAS las alertas de modo resumen sin enviar, no solo las de esta
 * pasada: si el cron falló ayer, hoy se recuperan. Cada persona recibe como
 * mucho un resumen al día; quien ya lo recibió hoy queda fuera.
 *
 * @return array<int, array> user_id => lista de filas
 */
function email_digest_pendiente(mysqli $conn): array
{
    $corte = email_cutoff_efectivo($conn);
    if ($corte === null) {
        return [];
    }
    $tipos = email_tipos_por_modo(EMAIL_MODE_DIGEST);
    if ($tipos === []) {
        return [];
    }
    [$marcas, $tiposBind, $vals] = email_sql_en_lista($tipos);

    // El tope de «uno al día» se comprueba contra la propia tabla: si esa
    // persona ya tiene alguna notificación de resumen marcada como enviada
    // HOY, se queda fuera. No hace falta una tabla aparte para llevar la
    // cuenta.
    $sql = "SELECT n.id, n.user_id, n.tipo, n.payload_json, n.created_at
              FROM notifications n
              JOIN users u ON u.id = n.user_id
             WHERE n.tipo IN $marcas
               AND n.emailed_at IS NULL
               AND n.created_at > ?
               AND " . alert_receptor_valido_sql('u') . "
               AND NOT EXISTS (
                     SELECT 1 FROM notifications n2
                      WHERE n2.user_id = n.user_id
                        AND n2.tipo IN $marcas
                        AND n2.emailed_at IS NOT NULL
                        AND DATE(n2.emailed_at) = ?
                   )
             ORDER BY n.user_id, n.created_at, n.id";
    $st = $conn->prepare($sql);
    if (!$st) {
        return [];
    }
    $args   = array_merge($vals, [$corte], $vals, [email_dia_actual()]);
    $tipos2 = $tiposBind . 's' . $tiposBind . 's';
    $refs = [];
    foreach ($args as $k => $v) {
        $refs[$k] = &$args[$k];
    }
    array_unshift($refs, $tipos2);
    call_user_func_array([$st, 'bind_param'], $refs);
    $st->execute();
    $filas = $st->get_result()->fetch_all(MYSQLI_ASSOC);
    $st->close();

    $porUsuario = [];
    foreach ($filas as $f) {
        $porUsuario[(int) $f['user_id']][] = $f;
    }
    return $porUsuario;
}

/**
 * Marca notificaciones como enviadas.
 *
 * Se llama ÚNICAMENTE después de un envío con éxito. Nunca antes, nunca en
 * simulacro y nunca si el SMTP falló: si algo sale mal la fila se queda en
 * NULL y el siguiente intento la vuelve a recoger. Preferimos repetir un aviso
 * a perderlo en silencio.
 */
function email_marcar_enviadas(mysqli $conn, array $ids): int
{
    $ids = array_values(array_filter(array_map('intval', $ids), static fn(int $i): bool => $i > 0));
    if ($ids === []) {
        return 0;
    }
    $marcas = implode(',', array_fill(0, count($ids), '?'));
    $st = $conn->prepare("UPDATE notifications SET emailed_at = NOW() WHERE id IN ($marcas) AND emailed_at IS NULL");
    if (!$st) {
        return 0;
    }
    $args = $ids;
    $refs = [];
    foreach ($args as $k => $v) {
        $refs[$k] = &$args[$k];
    }
    array_unshift($refs, str_repeat('i', count($ids)));
    call_user_func_array([$st, 'bind_param'], $refs);
    $st->execute();
    $n = $conn->affected_rows;
    $st->close();
    return $n;
}

/**
 * Simulacro completo: qué crearía y qué enviaría una pasada del cron.
 *
 * No escribe ni envía. Reutiliza run_all_alerts() en modo simulacro, de modo
 * que evalúa los mismos umbrales y los mismos destinatarios que la ejecución
 * real; no hay una segunda copia de las reglas que pudiera quedarse atrás.
 *
 * @return array por_tipo, inmediatos, digests
 */
function simular_alertas(mysqli $conn): array
{
    $r = run_all_alerts($conn, true);

    $porTipo    = [];
    $inmediatos = [];
    $digestSim  = [];
    foreach ($r['simulados'] as $s) {
        $tipo = (string) $s['tipo'];
        $uid  = (int) $s['user_id'];
        $porTipo[$tipo] = ($porTipo[$tipo] ?? 0) + 1;

        if (!email_policy_activa()) {
            continue;
        }
        $modo = email_delivery_mode($tipo);
        if ($modo === EMAIL_MODE_IMMEDIATE) {
            $inmediatos[] = ['user_id' => $uid, 'tipo' => $tipo];
        } elseif ($modo === EMAIL_MODE_DIGEST) {
            $digestSim[$uid] = ($digestSim[$uid] ?? 0) + 1;
        }
    }
    ksort($porTipo);

    // A lo simulado se suma lo que ya está pendiente en la base: si el cron
    // falló ayer, esas alertas siguen sin enviar y entrarían en el resumen de
    // hoy. Quien ya recibió su resumen hoy queda fuera, y por eso no se suma
    // dos veces.
    if (email_policy_activa()) {
        foreach (email_digest_pendiente($conn) as $uid => $filas) {
            $digestSim[(int) $uid] = ($digestSim[(int) $uid] ?? 0) + count($filas);
        }
    }
    ksort($digestSim);

    return ['por_tipo' => $porTipo, 'inmediatos' => $inmediatos, 'digests' => $digestSim];
}
