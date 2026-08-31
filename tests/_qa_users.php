<?php
/**
 * tests/_qa_users.php — fábrica de usuarios QA para las suites.
 *
 * POR QUÉ EXISTE
 *   Las suites tomaban usuarios de la base: unas con identificadores fijos
 *   (`$U = 2`) y otras con «el primero que haya» (`ORDER BY id LIMIT 1`).
 *   Eso funcionaba por casualidad en la base de desarrollo, donde el usuario 3
 *   resultaba ser un coordinador con is_admin=1. Sobre una copia de producción
 *   ese mismo identificador es otra persona, con otro rol y quizá desactivada.
 *
 *   El peligro no es borrar datos —está medido que las suites dejan delta 0—,
 *   sino que una prueba de permisos crea estar usando un administrador y esté
 *   usando una cuenta inactiva. Puede salir verde sin significar nada, que es
 *   peor que fallar, porque no se nota.
 *
 *   Aquí las suites piden SEMÁNTICA («dame un super admin activo») en lugar de
 *   un número, y reciben un usuario recién creado con exactamente esas
 *   propiedades. El resultado no depende del contenido previo de la base.
 *
 * LIMPIEZA
 *   Cada usuario creado queda anotado en un registro del proceso, y
 *   qa_users_cleanup() borra exactamente esos identificadores, nunca por
 *   rangos ni por números fijos. El orden importa: boards y teams apuntan a
 *   users con RESTRICT, así que hay que retirarlos antes que a su dueño.
 *
 *   Un efecto secundario valioso: notifications, comments, board_members,
 *   board_presence y team_members van en CASCADE desde users. Al borrar el
 *   usuario QA desaparecen sus avisos, que era la vía por la que una suite
 *   podía dejar notificaciones colgando de una persona real —notifications no
 *   tiene clave ajena hacia boards, así que borrar el tablero no bastaba—.
 *
 * SIN SECRETOS
 *   La contraseña es un valor artificial fijo, y su hash se calcula en tiempo
 *   de ejecución: no hay ningún hash escrito en el código. Las suites abren
 *   sesión escribiendo el archivo de sesión, no autenticándose, así que esta
 *   contraseña no se usa para entrar a ninguna parte.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Este archivo solo puede usarse desde CLI.\n");
}

/** Dominio reservado. Ninguna cuenta real puede estar aquí. */
const QA_USER_DOMAIN = '@local.test';

/** Valor artificial: no es la contraseña de nadie ni sirve para entrar. */
const QA_USER_PASSWORD = 'qa-usuario-de-prueba-sin-valor';

/** Registro de lo creado en esta ejecución, por sufijo de suite. */
$GLOBALS['__qa_users'] = [];

/**
 * Hash de la contraseña QA. Se calcula una sola vez por proceso porque bcrypt
 * es deliberadamente lento y una suite puede crear varios usuarios.
 */
function qa_user_hash(): string
{
    static $hash = null;
    if ($hash === null) {
        $hash = password_hash(QA_USER_PASSWORD, PASSWORD_DEFAULT);
    }
    return $hash;
}

/**
 * Correo único e inequívocamente QA.
 * Forma: qa.<suite>.<aleatorio>@local.test
 *
 * El aleatorio evita que dos suites —o dos ejecuciones seguidas de la misma—
 * choquen contra el índice único de email.
 */
function qa_user_email(string $suite): string
{
    $suite = strtolower(preg_replace('/[^A-Za-z0-9]+/', '', $suite) ?? 'qa');
    return 'qa.' . $suite . '.' . bin2hex(random_bytes(5)) . QA_USER_DOMAIN;
}

/**
 * Crea un usuario QA y devuelve su id.
 *
 * @param string $suite  Etiqueta corta de la suite: entra en el correo y
 *                       permite limpiar restos de una ejecución interrumpida.
 * @param array  $opts   rol, is_admin, activo, estado, nombre, deleted_at.
 *                       Todo explícito: la suite declara qué necesita.
 */
function qa_user(mysqli $conn, string $suite, array $opts = []): int
{
    $rol       = (string) ($opts['rol'] ?? 'user');
    $isAdmin   = (int) ($opts['is_admin'] ?? 0);
    $activo    = (int) ($opts['activo'] ?? 1);
    $estado    = (string) ($opts['estado'] ?? 'aprobado');
    $nombre    = (string) ($opts['nombre'] ?? 'QA ' . ucfirst($rol));
    $deletedAt = $opts['deleted_at'] ?? null;

    $email = qa_user_email($suite);
    $hash  = qa_user_hash();

    $st = $conn->prepare(
        "INSERT INTO users (nombre, email, pass_hash, estado, rol, is_admin, activo, deleted_at)
         VALUES (?,?,?,?,?,?,?,?)"
    );
    if (!$st) {
        throw new RuntimeException('qa_user: no se pudo preparar el INSERT');
    }
    $st->bind_param('sssssiis', $nombre, $email, $hash, $estado, $rol, $isAdmin, $activo, $deletedAt);
    if (!$st->execute()) {
        throw new RuntimeException('qa_user: no se pudo crear el usuario QA');
    }
    $id = (int) $conn->insert_id;
    $st->close();

    $GLOBALS['__qa_users'][$suite][] = $id;
    return $id;
}

/** Identificadores creados en esta ejecución (todos, o los de una suite). */
function qa_user_ids(?string $suite = null): array
{
    if ($suite !== null) {
        return $GLOBALS['__qa_users'][$suite] ?? [];
    }
    $todos = [];
    foreach ($GLOBALS['__qa_users'] as $lista) {
        foreach ($lista as $id) {
            $todos[] = $id;
        }
    }
    return $todos;
}

/**
 * Borra los usuarios QA creados en esta ejecución, y con ellos todo lo que
 * cuelga: tableros, equipos, avisos, comentarios y membresías.
 *
 * Solo por identificador, nunca por rango ni por número fijo.
 *
 * @return array Recuento por tabla, para que la suite pueda afirmarlo.
 */
function qa_users_cleanup(mysqli $conn, ?array $ids = null): array
{
    $ids = $ids ?? qa_user_ids();
    $ids = array_values(array_unique(array_map('intval', $ids)));
    $ids = array_filter($ids, static fn(int $i): bool => $i > 0);
    if (!$ids) {
        return ['boards' => 0, 'teams' => 0, 'users' => 0];
    }

    $marcas = implode(',', array_fill(0, count($ids), '?'));
    $tipos  = str_repeat('i', count($ids));
    $out    = [];

    // 1) Tableros y 2) equipos primero: apuntan a users con RESTRICT, así que
    //    mientras existan no se puede borrar a su dueño. Sus hijos —columnas,
    //    tareas, comentarios, adjuntos, eventos— caen en cascada.
    // 3) El usuario al final; sus avisos y membresías caen con él.
    foreach ([
        'boards' => "DELETE FROM boards WHERE owner_user_id IN ($marcas)",
        'teams'  => "DELETE FROM teams  WHERE owner_user_id IN ($marcas)",
        'users'  => "DELETE FROM users  WHERE id IN ($marcas)",
    ] as $clave => $sql) {
        $st = $conn->prepare($sql);
        if (!$st) {
            throw new RuntimeException("qa_users_cleanup: no se pudo preparar la limpieza de $clave");
        }
        $args = $ids;
        $refs = [];
        foreach ($args as $k => $v) {
            $refs[$k] = &$args[$k];
        }
        array_unshift($refs, $tipos);
        call_user_func_array([$st, 'bind_param'], $refs);
        $st->execute();
        $out[$clave] = $conn->affected_rows;
        $st->close();
    }

    foreach ($ids as $id) {
        foreach ($GLOBALS['__qa_users'] as $suite => $lista) {
            $GLOBALS['__qa_users'][$suite] = array_values(array_diff($lista, [$id]));
        }
    }
    return $out;
}

/**
 * Retira restos de una ejecución anterior que se interrumpiera antes de
 * limpiar. Actúa sobre el patrón de correo de la suite, que por construcción
 * no puede coincidir con ninguna cuenta real: el dominio está reservado.
 */
function qa_users_cleanup_stale(mysqli $conn, string $suite): int
{
    $suite   = strtolower(preg_replace('/[^A-Za-z0-9]+/', '', $suite) ?? 'qa');
    $patron  = 'qa.' . $suite . '.%' . QA_USER_DOMAIN;

    $st = $conn->prepare("SELECT id FROM users WHERE email LIKE ?");
    if (!$st) {
        return 0;
    }
    $st->bind_param('s', $patron);
    $st->execute();
    $ids = [];
    foreach ($st->get_result()->fetch_all(MYSQLI_ASSOC) as $f) {
        $ids[] = (int) $f['id'];
    }
    $st->close();
    if (!$ids) {
        return 0;
    }
    $r = qa_users_cleanup($conn, $ids);
    return $r['users'];
}

/**
 * Cuántos usuarios QA quedan en la base para esta suite. Una suite limpia debe
 * terminar con 0; sirve como aserción de cierre.
 */
function qa_users_restantes(mysqli $conn, string $suite): int
{
    $suite  = strtolower(preg_replace('/[^A-Za-z0-9]+/', '', $suite) ?? 'qa');
    $patron = 'qa.' . $suite . '.%' . QA_USER_DOMAIN;
    $st = $conn->prepare("SELECT COUNT(*) FROM users WHERE email LIKE ?");
    if (!$st) {
        return -1;
    }
    $st->bind_param('s', $patron);
    $st->execute();
    $n = (int) $st->get_result()->fetch_row()[0];
    $st->close();
    return $n;
}
