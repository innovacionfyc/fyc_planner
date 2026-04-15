<?php
// public/admin/team_action.php
// Acciones: create_team | delete_team | add_member | remove_member | set_member_role
require_once __DIR__ . '/../_auth.php';
require_admin();
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../_perm.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Método no permitido']);
    exit;
}

// Leer datos (JSON o form-urlencoded)
$ct = $_SERVER['HTTP_CONTENT_TYPE'] ?? $_SERVER['CONTENT_TYPE'] ?? '';
$data = stripos($ct, 'application/json') !== false
    ? (json_decode(file_get_contents('php://input'), true) ?: [])
    : $_POST;

// CSRF
$csrf = trim((string) ($data['csrf'] ?? ''));
if (!$csrf || !hash_equals($_SESSION['csrf'] ?? '', $csrf)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'CSRF inválido']);
    exit;
}

$action          = trim((string) ($data['action'] ?? ''));
$current_user_id = (int) ($_SESSION['user_id'] ?? 0);

function fail(string $msg, int $code = 400): void
{
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $msg]);
    exit;
}

function ok(array $extra = []): void
{
    echo json_encode(array_merge(['ok' => true], $extra));
    exit;
}

// ================================================================
// CREATE TEAM
// ================================================================
if ($action === 'create_team') {
    $nombre = trim((string) ($data['nombre'] ?? ''));
    if ($nombre === '')
        fail('El nombre del equipo es requerido.');
    if (mb_strlen($nombre) > 120)
        fail('Nombre demasiado largo (máx 120 caracteres).');

    // Evitar duplicados exactos (case-insensitive)
    $chk = $conn->prepare("SELECT id FROM teams WHERE LOWER(nombre) = LOWER(?) LIMIT 1");
    if (!$chk) fail('Error interno.', 500);
    $chk->bind_param('s', $nombre);
    $chk->execute();
    $chk->store_result();
    $isDup = $chk->num_rows > 0;
    $chk->close();
    if ($isDup) fail('Ya existe un equipo con ese nombre.');

    $ins = $conn->prepare("INSERT INTO teams (nombre, owner_user_id) VALUES (?, ?)");
    if (!$ins) fail('Error interno.', 500);
    $ins->bind_param('si', $nombre, $current_user_id);
    if (!$ins->execute()) fail('Error al crear equipo: ' . $ins->error, 500);
    $team_id = (int) $conn->insert_id;

    // Auto-agregar al creador como admin_equipo
    $own = $conn->prepare("INSERT IGNORE INTO team_members (team_id, user_id, rol) VALUES (?, ?, 'admin_equipo')");
    if ($own) {
        $own->bind_param('ii', $team_id, $current_user_id);
        $own->execute();
    }

    // Obtener datos del creador para la respuesta
    $uq = $conn->prepare("SELECT nombre, email FROM users WHERE id = ? LIMIT 1");
    if (!$uq) fail('Error interno.', 500);
    $uq->bind_param('i', $current_user_id);
    $uq->execute();
    $creatorNombre = null;
    $creatorEmail  = null;
    $uq->bind_result($creatorNombre, $creatorEmail);
    $uq->fetch();
    $uq->close();

    ok([
        'team_id' => $team_id,
        'nombre'  => $nombre,
        'creator' => [
            'user_id' => $current_user_id,
            'nombre'  => (string) $creatorNombre,
            'email'   => (string) $creatorEmail,
            'rol'     => 'admin_equipo',
        ],
    ]);
}

// ================================================================
// DELETE TEAM  (solo super_admin)
// ================================================================
if ($action === 'delete_team') {
    if (!is_super_admin($conn))
        fail('Solo super admin puede eliminar equipos.', 403);

    $team_id = (int) ($data['team_id'] ?? 0);
    if ($team_id <= 0) fail('team_id requerido.');

    $chk = $conn->prepare("SELECT id FROM teams WHERE id = ? LIMIT 1");
    if (!$chk) fail('Error interno.', 500);
    $chk->bind_param('i', $team_id);
    $chk->execute();
    $chk->store_result();
    if ($chk->num_rows === 0) fail('Equipo no encontrado.', 404);
    $chk->close();

    $del = $conn->prepare("DELETE FROM teams WHERE id = ? LIMIT 1");
    if (!$del) fail('Error interno.', 500);
    $del->bind_param('i', $team_id);
    if (!$del->execute()) fail('Error al eliminar equipo.', 500);

    ok(['team_id' => $team_id]);
}

// ================================================================
// ADD MEMBER
// ================================================================
if ($action === 'add_member') {
    $team_id = (int) ($data['team_id'] ?? 0);
    $email   = trim((string) ($data['email'] ?? ''));
    $rol     = (($data['rol'] ?? 'miembro') === 'admin_equipo') ? 'admin_equipo' : 'miembro';

    if ($team_id <= 0) fail('team_id requerido.');
    if ($email === '')  fail('El email es requerido.');

    // Verificar que el equipo existe
    $chkT = $conn->prepare("SELECT id FROM teams WHERE id = ? LIMIT 1");
    if (!$chkT) fail('Error interno.', 500);
    $chkT->bind_param('i', $team_id);
    $chkT->execute();
    $chkT->store_result();
    if ($chkT->num_rows === 0) fail('Equipo no encontrado.', 404);
    $chkT->close();

    // Localizar usuario y validar su estado
    $uq = $conn->prepare("SELECT id, nombre, estado, activo, deleted_at FROM users WHERE email = ? LIMIT 1");
    if (!$uq) fail('Error interno.', 500);
    $uq->bind_param('s', $email);
    $uq->execute();
    $uid = null; $uNombre = null; $estado = null; $activo = null; $deletedAt = null;
    $uq->bind_result($uid, $uNombre, $estado, $activo, $deletedAt);
    $found = $uq->fetch();
    $uq->close();

    if (!$found || !$uid)        fail('No existe un usuario con ese email.');
    if ($estado !== 'aprobado')  fail('El usuario no está aprobado en el sistema.');
    if ((int) $activo !== 1)     fail('El usuario está suspendido.');
    if (!empty($deletedAt))      fail('El usuario está eliminado.');

    $uid = (int) $uid;

    // Evitar duplicados
    $chkM = $conn->prepare("SELECT 1 FROM team_members WHERE team_id = ? AND user_id = ? LIMIT 1");
    if (!$chkM) fail('Error interno.', 500);
    $chkM->bind_param('ii', $team_id, $uid);
    $chkM->execute();
    $chkM->store_result();
    $alreadyMember = $chkM->num_rows > 0;
    $chkM->close();
    if ($alreadyMember) fail('Este usuario ya es miembro del equipo.');

    $ins = $conn->prepare("INSERT INTO team_members (team_id, user_id, rol) VALUES (?, ?, ?)");
    if (!$ins) fail('Error interno.', 500);
    $ins->bind_param('iis', $team_id, $uid, $rol);
    if (!$ins->execute()) fail('Error al agregar miembro.', 500);

    ok([
        'user_id' => $uid,
        'nombre'  => (string) $uNombre,
        'email'   => $email,
        'rol'     => $rol,
    ]);
}

// ================================================================
// REMOVE MEMBER
// ================================================================
if ($action === 'remove_member') {
    $team_id = (int) ($data['team_id'] ?? 0);
    $user_id = (int) ($data['user_id'] ?? 0);

    if ($team_id <= 0 || $user_id <= 0)
        fail('team_id y user_id requeridos.');

    // Obtener rol actual del miembro
    $chkM = $conn->prepare("SELECT rol FROM team_members WHERE team_id = ? AND user_id = ? LIMIT 1");
    if (!$chkM) fail('Error interno.', 500);
    $chkM->bind_param('ii', $team_id, $user_id);
    $chkM->execute();
    $memberRol = null;
    $chkM->bind_result($memberRol);
    $found = $chkM->fetch();
    $chkM->close();

    if (!$found) fail('El usuario no es miembro de este equipo.', 404);

    // Proteger al último admin_equipo
    if ($memberRol === 'admin_equipo') {
        $cntQ = $conn->prepare("SELECT COUNT(*) FROM team_members WHERE team_id = ? AND rol = 'admin_equipo'");
        if (!$cntQ) fail('Error interno.', 500);
        $cntQ->bind_param('i', $team_id);
        $cntQ->execute();
        $adminCount = 0;
        $cntQ->bind_result($adminCount);
        $cntQ->fetch();
        $cntQ->close();
        if ((int) $adminCount <= 1)
            fail('No puedes quitar al único admin del equipo.');
    }

    $del = $conn->prepare("DELETE FROM team_members WHERE team_id = ? AND user_id = ? LIMIT 1");
    if (!$del) fail('Error interno.', 500);
    $del->bind_param('ii', $team_id, $user_id);
    if (!$del->execute()) fail('Error al quitar miembro.', 500);

    ok(['team_id' => $team_id, 'user_id' => $user_id]);
}

// ================================================================
// SET MEMBER ROLE
// ================================================================
if ($action === 'set_member_role') {
    $team_id = (int) ($data['team_id'] ?? 0);
    $user_id = (int) ($data['user_id'] ?? 0);
    $rol     = (($data['rol'] ?? 'miembro') === 'admin_equipo') ? 'admin_equipo' : 'miembro';

    if ($team_id <= 0 || $user_id <= 0)
        fail('team_id y user_id requeridos.');

    // Obtener rol actual
    $chkM = $conn->prepare("SELECT rol FROM team_members WHERE team_id = ? AND user_id = ? LIMIT 1");
    if (!$chkM) fail('Error interno.', 500);
    $chkM->bind_param('ii', $team_id, $user_id);
    $chkM->execute();
    $currentRol = null;
    $chkM->bind_result($currentRol);
    $found = $chkM->fetch();
    $chkM->close();

    if (!$found) fail('El usuario no es miembro de este equipo.', 404);

    // Proteger al último admin_equipo de ser degradado
    if ($currentRol === 'admin_equipo' && $rol === 'miembro') {
        $cntQ = $conn->prepare("SELECT COUNT(*) FROM team_members WHERE team_id = ? AND rol = 'admin_equipo'");
        if (!$cntQ) fail('Error interno.', 500);
        $cntQ->bind_param('i', $team_id);
        $cntQ->execute();
        $adminCount = 0;
        $cntQ->bind_result($adminCount);
        $cntQ->fetch();
        $cntQ->close();
        if ((int) $adminCount <= 1)
            fail('No puedes degradar al único admin del equipo.');
    }

    $upd = $conn->prepare("UPDATE team_members SET rol = ? WHERE team_id = ? AND user_id = ? LIMIT 1");
    if (!$upd) fail('Error interno.', 500);
    $upd->bind_param('sii', $rol, $team_id, $user_id);
    if (!$upd->execute()) fail('Error al actualizar rol.', 500);

    ok(['team_id' => $team_id, 'user_id' => $user_id, 'rol' => $rol]);
}

fail('Acción desconocida.');
