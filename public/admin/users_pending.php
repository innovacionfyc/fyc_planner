<?php
// public/admin/users_pending.php
require_once __DIR__ . '/../_auth.php';
require_admin();
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../_perm.php';

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

// ¿Es super admin?
$meId   = (int)($_SESSION['user_id'] ?? 0);
$isSuper = is_super_admin($conn);

// Roles disponibles
$ROLE_OPTIONS = [
    'super_admin' => 'Super Admin',
    'director'    => 'Director',
    'coordinador' => 'Coordinador',
    'ti'          => 'TI',
    'user'        => 'Usuario',
];

// Usuarios (pendientes primero)
$users = $conn->query("
    SELECT id, nombre, email, estado, rol, is_admin, activo, deleted_at, created_at
    FROM users
    ORDER BY (estado = 'pendiente') DESC, created_at DESC
")->fetch_all(MYSQLI_ASSOC);

function estadoBadge($estado) {
    if ($estado === 'aprobado')  return ['Aprobado',  'fyc-badge fyc-badge-ok'];
    if ($estado === 'rechazado') return ['Rechazado', 'fyc-badge fyc-badge-overdue'];
    return ['Pendiente', 'fyc-badge fyc-badge-soon'];
}

// Flash de reset de contraseña
$pwFlash = null;
if (!empty($_SESSION['admin_pw_reset']) && is_array($_SESSION['admin_pw_reset'])) {
    $pwFlash = $_SESSION['admin_pw_reset'];
    unset($_SESSION['admin_pw_reset']);
}

$ok  = isset($_GET['ok']);
$err = isset($_GET['err']);

$pageTitle  = 'Usuarios';
$activePage = 'usuarios';
require_once __DIR__ . '/_layout_top.php';
?>

<h1 class="admin-page-title">Usuarios</h1>
<p class="admin-page-sub">
    <?= $isSuper ? 'Modo <strong>Super Admin</strong> — puedes cambiar roles y permisos.' : 'Modo <strong>Admin</strong> — puedes aprobar o rechazar cuentas.' ?>
</p>

<?php if ($pwFlash): ?>
    <div class="admin-flash admin-flash-error" style="background:var(--badge-soon-bg);color:var(--badge-soon-tx);border-color:var(--badge-soon-tx);">
        🔐 Contraseña temporal generada para <strong><?= h($pwFlash['nombre'] ?? '') ?></strong>
        (<?= h($pwFlash['email'] ?? '') ?>) —
        Temporal: <code style="font-family:ui-monospace,monospace;font-size:13px;"><?= h($pwFlash['temp'] ?? '') ?></code>
        <div style="font-size:11px;margin-top:4px;opacity:.8;">⚠ Copia esto ya. Se muestra una sola vez.</div>
    </div>
<?php endif; ?>

<?php if ($ok): ?>
    <div class="admin-flash admin-flash-ok">✅ Acción aplicada correctamente.</div>
<?php endif; ?>
<?php if ($err): ?>
    <div class="admin-flash admin-flash-error">❌ No se pudo aplicar la acción (CSRF / permisos / datos inválidos).</div>
<?php endif; ?>

<!-- Buscador -->
<div style="margin-bottom:16px;">
    <input id="userSearch" class="fyc-input" style="max-width:320px;"
           type="text" placeholder="Buscar por nombre o email…">
</div>

<div class="admin-card">
    <div class="admin-card-body">
        <table class="admin-table">
            <thead>
                <tr>
                    <th>Nombre</th>
                    <th>Email</th>
                    <th>Estado</th>
                    <th>Rol</th>
                    <th>Admin</th>
                    <th>Activo</th>
                    <th>Acciones</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($users as $u): ?>
                    <?php
                    $estado    = $u['estado'] ?? 'pendiente';
                    $uid       = (int)($u['id'] ?? 0);
                    $rolActual = (string)($u['rol'] ?? 'user');
                    $isAdminU  = ((int)($u['is_admin'] ?? 0) === 1);
                    $activo    = ((int)($u['activo'] ?? 1) === 1);
                    $deletedAt = (string)($u['deleted_at'] ?? '');
                    [$badgeLabel, $badgeClass] = estadoBadge($estado);
                    ?>
                    <tr>
                        <td style="font-weight:700;color:var(--text-primary);"><?= h($u['nombre'] ?? '') ?></td>
                        <td style="color:var(--text-muted);font-size:12px;"><?= h($u['email'] ?? '') ?></td>
                        <td><span class="<?= $badgeClass ?>"><?= h($badgeLabel) ?></span></td>

                        <td>
                            <?php if ($isSuper): ?>
                                <form method="post" action="user_action.php" style="display:flex;gap:6px;align-items:center;flex-wrap:wrap;">
                                    <input type="hidden" name="csrf" value="<?= h($_SESSION['csrf']) ?>">
                                    <input type="hidden" name="user_id" value="<?= $uid ?>">
                                    <input type="hidden" name="action" value="set_role">
                                    <select name="rol" class="fyc-select" style="width:auto;padding:5px 8px;font-size:12px;">
                                        <?php foreach ($ROLE_OPTIONS as $key => $label): ?>
                                            <option value="<?= h($key) ?>" <?= ($rolActual === $key ? 'selected' : '') ?>><?= h($label) ?></option>
                                        <?php endforeach; ?>
                                        <?php if (!isset($ROLE_OPTIONS[$rolActual])): ?>
                                            <option value="<?= h($rolActual) ?>" selected><?= h($rolActual) ?></option>
                                        <?php endif; ?>
                                    </select>
                                    <button class="fyc-btn fyc-btn-ghost" type="submit" style="padding:5px 10px;font-size:11px;">Guardar</button>
                                </form>
                            <?php else: ?>
                                <span class="fyc-badge" style="background:var(--bg-hover);color:var(--text-muted);border:1px solid var(--border-accent);"><?= h($rolActual) ?></span>
                            <?php endif; ?>
                        </td>

                        <td>
                            <span class="fyc-badge <?= $isAdminU ? 'fyc-badge-ok' : '' ?>"
                                  style="<?= !$isAdminU ? 'background:var(--bg-hover);color:var(--text-ghost);border:1px solid var(--border-main);' : '' ?>">
                                <?= $isAdminU ? 'Sí' : 'No' ?>
                            </span>
                        </td>

                        <td>
                            <?php if (!$activo): ?>
                                <span class="fyc-badge fyc-badge-overdue">Suspendido</span>
                            <?php else: ?>
                                <span class="fyc-badge fyc-badge-ok">Activo</span>
                            <?php endif; ?>
                            <?php if ($deletedAt): ?>
                                <span class="fyc-badge fyc-badge-soon" style="margin-left:4px;">Eliminado</span>
                            <?php endif; ?>
                        </td>

                        <td>
                            <div style="display:flex;flex-direction:column;gap:8px;">
                                <!-- Acciones base -->
                                <div style="display:flex;gap:6px;flex-wrap:wrap;">
                                    <form method="post" action="user_action.php">
                                        <input type="hidden" name="csrf" value="<?= h($_SESSION['csrf']) ?>">
                                        <input type="hidden" name="user_id" value="<?= $uid ?>">
                                        <input type="hidden" name="action" value="approve">
                                        <button class="fyc-btn fyc-btn-primary" type="submit" style="padding:5px 10px;font-size:11px;background:var(--badge-ok-tx);color:#fff;">Aprobar</button>
                                    </form>
                                    <form method="post" action="user_action.php">
                                        <input type="hidden" name="csrf" value="<?= h($_SESSION['csrf']) ?>">
                                        <input type="hidden" name="user_id" value="<?= $uid ?>">
                                        <input type="hidden" name="action" value="pend">
                                        <button class="fyc-btn fyc-btn-ghost" type="submit" style="padding:5px 10px;font-size:11px;">Pendiente</button>
                                    </form>
                                    <form method="post" action="user_action.php">
                                        <input type="hidden" name="csrf" value="<?= h($_SESSION['csrf']) ?>">
                                        <input type="hidden" name="user_id" value="<?= $uid ?>">
                                        <input type="hidden" name="action" value="reject">
                                        <button class="fyc-btn fyc-btn-danger" type="submit" style="padding:5px 10px;font-size:11px;">Rechazar</button>
                                    </form>
                                </div>

                                <?php if ($isSuper): ?>
                                    <div style="height:1px;background:var(--border-main);"></div>
                                    <!-- Acciones super admin -->
                                    <div style="display:flex;gap:6px;flex-wrap:wrap;">
                                        <form method="post" action="user_action.php">
                                            <input type="hidden" name="csrf" value="<?= h($_SESSION['csrf']) ?>">
                                            <input type="hidden" name="user_id" value="<?= $uid ?>">
                                            <input type="hidden" name="action" value="toggle_admin">
                                            <input type="hidden" name="is_admin" value="<?= $isAdminU ? '0' : '1' ?>">
                                            <button class="fyc-btn fyc-btn-ghost" type="submit" style="padding:5px 10px;font-size:11px;">
                                                <?= $isAdminU ? 'Quitar admin' : 'Hacer admin' ?>
                                            </button>
                                        </form>
                                        <form method="post" action="user_password_reset.php"
                                              onsubmit="return confirm('¿Resetear contraseña y generar una temporal?');">
                                            <input type="hidden" name="csrf" value="<?= h($_SESSION['csrf']) ?>">
                                            <input type="hidden" name="user_id" value="<?= $uid ?>">
                                            <button class="fyc-btn fyc-btn-ghost" type="submit" style="padding:5px 10px;font-size:11px;">Reset clave</button>
                                        </form>
                                        <form method="post" action="user_action.php"
                                              onsubmit="return confirm('¿Seguro que deseas <?= $activo ? 'SUSPENDER' : 'ACTIVAR' ?> este usuario?');">
                                            <input type="hidden" name="csrf" value="<?= h($_SESSION['csrf']) ?>">
                                            <input type="hidden" name="user_id" value="<?= $uid ?>">
                                            <input type="hidden" name="action" value="toggle_active">
                                            <input type="hidden" name="activo" value="<?= $activo ? '0' : '1' ?>">
                                            <button class="fyc-btn fyc-btn-ghost" type="submit" style="padding:5px 10px;font-size:11px;">
                                                <?= $activo ? 'Suspender' : 'Activar' ?>
                                            </button>
                                        </form>
                                        <form method="post" action="user_action.php"
                                              onsubmit="return confirm('¿ELIMINAR lógico este usuario?');">
                                            <input type="hidden" name="csrf" value="<?= h($_SESSION['csrf']) ?>">
                                            <input type="hidden" name="user_id" value="<?= $uid ?>">
                                            <input type="hidden" name="action" value="soft_delete">
                                            <button class="fyc-btn fyc-btn-danger" type="submit" style="padding:5px 10px;font-size:11px;">Eliminar</button>
                                        </form>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
(function () {
    var inp = document.getElementById('userSearch');
    if (!inp) return;
    inp.addEventListener('input', function () {
        var q = (inp.value || '').toLowerCase().trim();
        document.querySelectorAll('tbody tr').forEach(function (tr) {
            tr.style.display = (!q || tr.textContent.toLowerCase().indexOf(q) !== -1) ? '' : 'none';
        });
    });
})();
</script>

<?php require_once __DIR__ . '/_layout_bottom.php'; ?>
