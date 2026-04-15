<?php
// public/boards/member_action.php
// Gestión de miembros de un tablero: add | remove | set_role
// Requiere can_manage_board(). Al agregar valida aislamiento por equipo.
require_once __DIR__ . '/../_auth.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../_perm.php';
require_login();

header('Content-Type: application/json; charset=utf-8');

function fail($msg)  { echo json_encode(['ok' => false, 'error' => $msg]); exit; }
function ok($extra = []) { echo json_encode(array_merge(['ok' => true], $extra)); exit; }

// Leer datos (JSON o form-data)
$ct = $_SERVER['HTTP_CONTENT_TYPE'] ?? $_SERVER['CONTENT_TYPE'] ?? '';
if (stripos($ct, 'application/json') !== false) {
    $data = json_decode(file_get_contents('php://input'), true) ?: [];
} else {
    $data = $_POST;
}

// CSRF
$csrf = trim((string) ($data['csrf'] ?? ''));
if (!$csrf || !hash_equals($_SESSION['csrf'] ?? '', $csrf))
    fail('CSRF inválido');

$action   = trim((string) ($data['action']   ?? ''));
$board_id = (int) ($data['board_id'] ?? 0);
$user_id  = (int) ($_SESSION['user_id'] ?? 0);

if ($board_id <= 0)
    fail('board_id requerido');

// Solo puede gestionar miembros quien puede administrar el tablero
if (!can_manage_board($conn, $board_id, $user_id))
    fail('Sin permisos para gestionar miembros de este tablero');

// ============================================================
// AGREGAR miembro
// ============================================================
if ($action === 'add') {
    $target_uid = (int) ($data['target_user_id'] ?? 0);
    $rol        = trim((string) ($data['rol'] ?? 'editor'));

    if ($target_uid <= 0)
        fail('target_user_id requerido');
    if (!in_array($rol, ['propietario', 'editor', 'lector'], true))
        $rol = 'editor';

    // Verificar que el usuario existe y está activo
    $q = $conn->prepare("SELECT id FROM users WHERE id = ? AND estado = 'aprobado' AND activo = 1 LIMIT 1");
    if (!$q) fail('Error interno al validar usuario');
    $q->bind_param('i', $target_uid);
    $q->execute();
    $dummy = null;
    $q->bind_result($dummy);
    $exists = $q->fetch();
    $q->close();
    if (!$exists)
        fail('Usuario no encontrado o inactivo');

    // Aislamiento por equipo: el usuario debe pertenecer al equipo del tablero
    if (!is_member_of_board_team($conn, $board_id, $target_uid))
        fail('El usuario no pertenece al equipo de este tablero');

    // INSERT o actualizar rol si ya existía
    $ins = $conn->prepare(
        "INSERT INTO board_members (board_id, user_id, rol) VALUES (?, ?, ?)
         ON DUPLICATE KEY UPDATE rol = VALUES(rol)"
    );
    if (!$ins) fail('Error interno al agregar');
    $ins->bind_param('iis', $board_id, $target_uid, $rol);
    if (!$ins->execute())
        fail('Error al agregar miembro');

    ok(['board_id' => $board_id, 'user_id' => $target_uid, 'rol' => $rol]);
}

// ============================================================
// QUITAR miembro
// ============================================================
if ($action === 'remove') {
    $target_uid = (int) ($data['target_user_id'] ?? 0);
    if ($target_uid <= 0)
        fail('target_user_id requerido');

    // Verificar que es miembro del tablero
    $q = $conn->prepare("SELECT rol FROM board_members WHERE board_id = ? AND user_id = ? LIMIT 1");
    if (!$q) fail('Error interno');
    $q->bind_param('ii', $board_id, $target_uid);
    $q->execute();
    $targetRol = null;
    $q->bind_result($targetRol);
    $found = $q->fetch();
    $q->close();
    if (!$found)
        fail('El usuario no es miembro de este tablero');

    // No eliminar al último propietario
    if ($targetRol === 'propietario') {
        $q2 = $conn->prepare("SELECT COUNT(*) FROM board_members WHERE board_id = ? AND rol = 'propietario'");
        if (!$q2) fail('Error interno');
        $q2->bind_param('i', $board_id);
        $q2->execute();
        $propCount = 0;
        $q2->bind_result($propCount);
        $q2->fetch();
        $q2->close();
        if ((int) $propCount <= 1)
            fail('No se puede quitar al único propietario del tablero');
    }

    $del = $conn->prepare("DELETE FROM board_members WHERE board_id = ? AND user_id = ?");
    if (!$del) fail('Error interno');
    $del->bind_param('ii', $board_id, $target_uid);
    if (!$del->execute())
        fail('Error al quitar miembro');

    ok(['board_id' => $board_id, 'user_id' => $target_uid]);
}

// ============================================================
// CAMBIAR ROL de un miembro existente
// ============================================================
if ($action === 'set_role') {
    $target_uid = (int) ($data['target_user_id'] ?? 0);
    $new_rol    = trim((string) ($data['rol'] ?? ''));

    if ($target_uid <= 0)
        fail('target_user_id requerido');
    if (!in_array($new_rol, ['propietario', 'editor', 'lector'], true))
        fail('Rol inválido (propietario, editor, lector)');

    // Verificar que es miembro
    $q = $conn->prepare("SELECT rol FROM board_members WHERE board_id = ? AND user_id = ? LIMIT 1");
    if (!$q) fail('Error interno');
    $q->bind_param('ii', $board_id, $target_uid);
    $q->execute();
    $currentRol = null;
    $q->bind_result($currentRol);
    $found = $q->fetch();
    $q->close();
    if (!$found)
        fail('El usuario no es miembro de este tablero');

    // No degradar al último propietario
    if ($currentRol === 'propietario' && $new_rol !== 'propietario') {
        $q2 = $conn->prepare("SELECT COUNT(*) FROM board_members WHERE board_id = ? AND rol = 'propietario'");
        if (!$q2) fail('Error interno');
        $q2->bind_param('i', $board_id);
        $q2->execute();
        $propCount = 0;
        $q2->bind_result($propCount);
        $q2->fetch();
        $q2->close();
        if ((int) $propCount <= 1)
            fail('No se puede cambiar el rol del único propietario');
    }

    $upd = $conn->prepare("UPDATE board_members SET rol = ? WHERE board_id = ? AND user_id = ?");
    if (!$upd) fail('Error interno');
    $upd->bind_param('sii', $new_rol, $board_id, $target_uid);
    if (!$upd->execute())
        fail('Error al cambiar rol');

    ok(['board_id' => $board_id, 'user_id' => $target_uid, 'rol' => $new_rol]);
}

fail('Acción desconocida: ' . htmlspecialchars($action));
