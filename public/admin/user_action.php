<?php
// public/admin/user_action.php
require_once __DIR__ . '/../_auth.php';
require_admin();
require_once __DIR__ . '/../../config/db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: users_pending.php?err=1');
    exit;
}

if (!isset($_POST['csrf'], $_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], $_POST['csrf'])) {
    header('Location: users_pending.php?err=1');
    exit;
}

$target_user_id = (int) ($_POST['user_id'] ?? 0);
$action = (string) ($_POST['action'] ?? '');

if ($target_user_id <= 0) {
    header('Location: users_pending.php?err=1');
    exit;
}

// Evitar auto-editarse (para no bloquearse por accidente)
if ($target_user_id === (int) ($_SESSION['user_id'] ?? 0)) {
    header('Location: users_pending.php?err=1');
    exit;
}

// -----------------------------
// Determinar si el admin actual es SUPER ADMIN (DB source of truth)
// -----------------------------
$current_id = (int) ($_SESSION['user_id'] ?? 0);
$isSuper = false;

$me = $conn->prepare("SELECT is_admin, rol FROM users WHERE id = ? LIMIT 1");
if ($me) {
    $me->bind_param('i', $current_id);
    $me->execute();
    $row = $me->get_result()->fetch_assoc();
    $isSuper = ((int) ($row['is_admin'] ?? 0) === 1) && (($row['rol'] ?? '') === 'super_admin');
}

// -----------------------------
// Acciones permitidas
// approve/reject/pend: cualquier admin
// set_role/toggle_admin/toggle_active/soft_delete: SOLO super_admin
// -----------------------------
$allowed = ['approve', 'reject', 'pend', 'set_role', 'toggle_admin', 'toggle_active', 'soft_delete'];
if (!in_array($action, $allowed, true)) {
    header('Location: users_pending.php?err=1');
    exit;
}

if (in_array($action, ['set_role', 'toggle_admin', 'toggle_active', 'soft_delete'], true) && !$isSuper) {
    header('Location: users_pending.php?err=1');
    exit;
}

$ok = false;

if ($action === 'approve' || $action === 'reject' || $action === 'pend') {
    $estado = ($action === 'approve') ? 'aprobado' : (($action === 'reject') ? 'rechazado' : 'pendiente');

    $stmt = $conn->prepare("UPDATE users SET estado = ? WHERE id = ? LIMIT 1");
    if ($stmt) {
        $stmt->bind_param('si', $estado, $target_user_id);
        $ok = $stmt->execute();
    }
}

if ($action === 'set_role') {
    $ALLOWED_ROLES = ['super_admin', 'director', 'coordinador', 'ti', 'user'];
    $rol = trim((string) ($_POST['rol'] ?? 'user'));
    if (!in_array($rol, $ALLOWED_ROLES, true)) {
        header('Location: users_pending.php?err=1');
        exit;
    }

    $stmt = $conn->prepare("UPDATE users SET rol = ? WHERE id = ? LIMIT 1");
    if ($stmt) {
        $stmt->bind_param('si', $rol, $target_user_id);
        $ok = $stmt->execute();
    }
}

if ($action === 'toggle_admin') {
    $val = (int) ($_POST['is_admin'] ?? 0);
    $val = ($val === 1) ? 1 : 0;

    $stmt = $conn->prepare("UPDATE users SET is_admin = ? WHERE id = ? LIMIT 1");
    if ($stmt) {
        $stmt->bind_param('ii', $val, $target_user_id);
        $ok = $stmt->execute();
    }
}

// -----------------------------
// ✅ NUEVO: Suspender / Activar (activo 1/0) — SOLO super_admin
// Nota: si activas, limpiamos deleted_at (por si lo habían eliminado lógico)
// -----------------------------
if ($action === 'toggle_active') {
    $val = (int) ($_POST['activo'] ?? 1);
    $val = ($val === 1) ? 1 : 0;

    $stmt = $conn->prepare("UPDATE users SET activo = ?, deleted_at = NULL WHERE id = ? LIMIT 1");
    if ($stmt) {
        $stmt->bind_param('ii', $val, $target_user_id);
        $ok = $stmt->execute();
    }
}

// -----------------------------
// ✅ NUEVO: Eliminado lógico — SOLO super_admin
// Marca deleted_at y desactiva activo
// -----------------------------
if ($action === 'soft_delete') {
    $stmt = $conn->prepare("UPDATE users SET activo = 0, deleted_at = NOW() WHERE id = ? LIMIT 1");
    if ($stmt) {
        $stmt->bind_param('i', $target_user_id);
        $ok = $stmt->execute();
    }
}

header('Location: users_pending.php?' . ($ok ? 'ok=1' : 'err=1'));
exit;