<?php
// public/_perm.php
require_once __DIR__ . '/../config/db.php';

/**
 * ¿Es el usuario actual super administrador del sistema?
 * Requiere is_admin=1 Y rol='super_admin' en la tabla users.
 */
function is_super_admin(mysqli $conn): bool
{
    $uid = (int) ($_SESSION['user_id'] ?? 0);
    if ($uid <= 0)
        return false;

    $q = $conn->prepare("SELECT is_admin, rol FROM users WHERE id = ? LIMIT 1");
    if (!$q)
        return false;

    $q->bind_param('i', $uid);
    $q->execute();
    $is_admin = null;
    $rol      = null;
    $q->bind_result($is_admin, $rol);
    $found = $q->fetch();
    $q->close();

    if (!$found)
        return false;

    return ((int) $is_admin === 1) && ($rol === 'super_admin');
}

/**
 * ¿Es el usuario actual administrador (cualquier nivel)?
 */
function is_admin_user(mysqli $conn): bool
{
    $uid = (int) ($_SESSION['user_id'] ?? 0);
    if ($uid <= 0)
        return false;

    $q = $conn->prepare("SELECT is_admin FROM users WHERE id = ? LIMIT 1");
    if (!$q)
        return false;

    $q->bind_param('i', $uid);
    $q->execute();
    $is_admin = null;
    $q->bind_result($is_admin);
    $found = $q->fetch();
    $q->close();

    if (!$found)
        return false;

    return ((int) $is_admin === 1);
}

/**
 * ¿Puede el usuario ver o abrir un tablero?
 *
 * Tablero de equipo (team_id IS NOT NULL):
 *   - super_admin siempre puede.
 *   - Cualquier miembro del equipo (team_members, cualquier rol).
 *   - El propietario registrado en board_members, aunque haya salido del equipo.
 *
 * Tablero personal (team_id IS NULL):
 *   - super_admin siempre puede.
 *   - Quien esté en board_members para ese tablero.
 */
function has_board_access(mysqli $conn, int $board_id, int $user_id): bool
{
    if ($board_id <= 0 || $user_id <= 0)
        return false;

    if (is_super_admin($conn))
        return true;

    // Obtener team_id del tablero
    $q = $conn->prepare("SELECT team_id FROM boards WHERE id = ? LIMIT 1");
    if (!$q)
        return false;
    $q->bind_param('i', $board_id);
    $q->execute();
    $team_id = null;
    $q->bind_result($team_id);
    $found = $q->fetch();
    $q->close();

    if (!$found)
        return false;

    if ($team_id !== null) {
        // Tablero de equipo: miembro del equipo tiene acceso
        $q2 = $conn->prepare(
            "SELECT 1 FROM team_members WHERE team_id = ? AND user_id = ? LIMIT 1"
        );
        if (!$q2) return false;
        $q2->bind_param('ii', $team_id, $user_id);
        $q2->execute();
        $dummy = null;
        $q2->bind_result($dummy);
        $inTeam = $q2->fetch();
        $q2->close();
        if ($inTeam) return true;

        // Propietario del tablero: acceso garantizado aunque haya salido del equipo
        $q3 = $conn->prepare(
            "SELECT 1 FROM board_members WHERE board_id = ? AND user_id = ? AND rol = 'propietario' LIMIT 1"
        );
        if (!$q3) return false;
        $q3->bind_param('ii', $board_id, $user_id);
        $q3->execute();
        $dummy2 = null;
        $q3->bind_result($dummy2);
        $isProp = $q3->fetch();
        $q3->close();
        return (bool) $isProp;
    }

    // Tablero personal: debe estar en board_members
    $q4 = $conn->prepare(
        "SELECT 1 FROM board_members WHERE board_id = ? AND user_id = ? LIMIT 1"
    );
    if (!$q4) return false;
    $q4->bind_param('ii', $board_id, $user_id);
    $q4->execute();
    $dummy3 = null;
    $q4->bind_result($dummy3);
    $inBm = $q4->fetch();
    $q4->close();
    return (bool) $inBm;
}

/**
 * ¿Puede el usuario editar el tablero (crear/mover tareas, editar columnas)?
 *
 * Tablero de equipo: cualquier miembro del equipo puede editar.
 * Tablero personal:  solo propietario o editor en board_members.
 * Super_admin siempre puede.
 */
function can_edit_board(mysqli $conn, int $board_id, int $user_id): bool
{
    if ($board_id <= 0 || $user_id <= 0)
        return false;

    if (is_super_admin($conn))
        return true;

    // Obtener team_id del tablero
    $q = $conn->prepare("SELECT team_id FROM boards WHERE id = ? LIMIT 1");
    if (!$q)
        return false;
    $q->bind_param('i', $board_id);
    $q->execute();
    $team_id = null;
    $q->bind_result($team_id);
    $found = $q->fetch();
    $q->close();

    if (!$found)
        return false;

    if ($team_id !== null) {
        // Tablero de equipo: cualquier miembro puede editar
        $q2 = $conn->prepare(
            "SELECT 1 FROM team_members WHERE team_id = ? AND user_id = ? LIMIT 1"
        );
        if (!$q2) return false;
        $q2->bind_param('ii', $team_id, $user_id);
        $q2->execute();
        $dummy = null;
        $q2->bind_result($dummy);
        $inTeam = $q2->fetch();
        $q2->close();
        return (bool) $inTeam;
    }

    // Tablero personal: lógica original con board_members
    $q3 = $conn->prepare(
        "SELECT rol FROM board_members WHERE board_id = ? AND user_id = ? LIMIT 1"
    );
    if (!$q3)
        return false;
    $q3->bind_param('ii', $board_id, $user_id);
    $q3->execute();
    $rol = null;
    $q3->bind_result($rol);
    $found2 = $q3->fetch();
    $q3->close();

    if (!$found2)
        return false;

    return in_array($rol, ['propietario', 'editor'], true);
}

/**
 * ¿Puede el usuario administrar el tablero (renombrar, eliminar, archivar, gestionar miembros)?
 * True si:
 *   - Es super_admin del sistema, O
 *   - Es propietario en board_members, O
 *   - Es admin_equipo del equipo al que pertenece el tablero.
 */
function can_manage_board(mysqli $conn, int $board_id, int $user_id): bool
{
    if ($board_id <= 0 || $user_id <= 0)
        return false;

    // Super admin tiene acceso total
    if (is_super_admin($conn))
        return true;

    // ¿Es propietario en este tablero?
    $q = $conn->prepare("SELECT rol FROM board_members WHERE board_id = ? AND user_id = ? LIMIT 1");
    if (!$q)
        return false;
    $q->bind_param('ii', $board_id, $user_id);
    $q->execute();
    $rol = null;
    $q->bind_result($rol);
    $found = $q->fetch();
    $q->close();
    if ($found && $rol === 'propietario')
        return true;

    // ¿Es admin_equipo del equipo al que pertenece el tablero?
    $q2 = $conn->prepare(
        "SELECT tm.rol
         FROM team_members tm
         INNER JOIN boards b ON b.team_id = tm.team_id
         WHERE b.id = ? AND tm.user_id = ? AND tm.rol = 'admin_equipo'
         LIMIT 1"
    );
    if (!$q2)
        return false;
    $q2->bind_param('ii', $board_id, $user_id);
    $q2->execute();
    $tmRol = null;
    $q2->bind_result($tmRol);
    $found2 = $q2->fetch();
    $q2->close();

    return $found2 && $tmRol === 'admin_equipo';
}

/**
 * ¿Puede el usuario ver el panel de administración?
 * Requiere is_admin=1 Y rol en ('super_admin', 'director', 'ti').
 * Usuarios con is_admin=1 pero rol 'coordinador' o 'user' NO ven el panel.
 */
function can_see_admin_panel(mysqli $conn): bool
{
    $uid = (int)($_SESSION['user_id'] ?? 0);
    if ($uid <= 0)
        return false;

    static $ADMIN_ROLES = ['super_admin', 'director', 'ti'];

    $q = $conn->prepare("SELECT is_admin, rol FROM users WHERE id = ? LIMIT 1");
    if (!$q)
        return false;

    $q->bind_param('i', $uid);
    $q->execute();
    $is_admin = null;
    $rol      = null;
    $q->bind_result($is_admin, $rol);
    $found = $q->fetch();
    $q->close();

    if (!$found)
        return false;

    return ((int)$is_admin === 1) && in_array($rol, $ADMIN_ROLES, true);
}

/**
 * ¿Puede el usuario escribir en el tablero (crear/editar/eliminar tareas y columnas)?
 * True si: puede editar (propietario o editor en board_members)
 *       O  puede administrar (super_admin, propietario, admin_equipo del equipo).
 * Un lector retorna false.
 */
function can_write_board(mysqli $conn, int $board_id, int $user_id): bool
{
    return can_edit_board($conn, $board_id, $user_id)
        || can_manage_board($conn, $board_id, $user_id);
}

/**
 * ¿El usuario pertenece al equipo del tablero?
 * Usado como validación previa al agregar un usuario a un tablero.
 * - Si el tablero no tiene equipo (personal): retorna true (sin restricción de equipo).
 * - Si tiene equipo: el usuario debe ser miembro de ese equipo.
 * - Super admin siempre pasa.
 */
function is_member_of_board_team(mysqli $conn, int $board_id, int $user_id): bool
{
    if ($board_id <= 0 || $user_id <= 0)
        return false;

    // Super admin siempre puede
    if (is_super_admin($conn))
        return true;

    // Obtener team_id del tablero
    $q = $conn->prepare("SELECT team_id FROM boards WHERE id = ? LIMIT 1");
    if (!$q)
        return false;
    $q->bind_param('i', $board_id);
    $q->execute();
    $team_id = null;
    $q->bind_result($team_id);
    $found = $q->fetch();
    $q->close();

    if (!$found)
        return false;

    // Tablero personal: el acceso lo controla únicamente board_members
    if ($team_id === null)
        return true;

    // Verificar membresía en el equipo
    $q2 = $conn->prepare("SELECT 1 FROM team_members WHERE team_id = ? AND user_id = ? LIMIT 1");
    if (!$q2)
        return false;
    $q2->bind_param('ii', $team_id, $user_id);
    $q2->execute();
    $dummy = null;
    $q2->bind_result($dummy);
    $found2 = $q2->fetch();
    $q2->close();

    return (bool) $found2;
}
