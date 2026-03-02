<?php
// public/admin/users_pending.php
require_once __DIR__ . '/../_auth.php';
require_admin();
require_once __DIR__ . '/../../config/db.php';

if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}

$ok = isset($_GET['ok']);
$err = isset($_GET['err']);

// Fuente de verdad: saber si el usuario actual es super_admin
$meId = (int) ($_SESSION['user_id'] ?? 0);
$isSuper = false;
$me = $conn->prepare("SELECT is_admin, rol FROM users WHERE id = ? LIMIT 1");
if ($me) {
    $me->bind_param('i', $meId);
    $me->execute();
    $row = $me->get_result()->fetch_assoc();
    $isSuper = ((int) ($row['is_admin'] ?? 0) === 1) && (($row['rol'] ?? '') === 'super_admin');
}

// Roles sugeridos (puedes ajustar luego sin romper nada)
$ROLE_OPTIONS = [
    'super_admin' => 'Super Admin',
    'admin' => 'Admin',
    'director' => 'Director',
    'coordinador' => 'Coordinador',
    'ti' => 'TI',
    'user' => 'Usuario',
];

// Traer usuarios (pendientes primero)
$users = $conn->query("
    SELECT id, nombre, email, estado, rol, is_admin, activo, deleted_at, created_at
    FROM users
    ORDER BY
      (estado = 'pendiente') DESC,
      created_at DESC
")->fetch_all(MYSQLI_ASSOC);

function estadoBadge($estado)
{
    if ($estado === 'aprobado') return 'Aprobado';
    if ($estado === 'rechazado') return 'Rechazado';
    return 'Pendiente';
}
?>
<!doctype html>
<html lang="es">

<head>
    <meta charset="utf-8">
    <title>Usuarios — Admin — F&C Planner</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <style>
        body {
            font-family: system-ui;
            margin: 0;
            background: #f7f7f7
        }

        .wrap {
            max-width: 1200px;
            margin: 24px auto;
            padding: 0 16px
        }

        .top {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 16px;
            gap: 12px;
            flex-wrap: wrap
        }

        a {
            color: #942934;
            text-decoration: none;
            font-weight: 700
        }

        .card {
            background: #fff;
            border: 1px solid #ddd;
            border-radius: 16px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, .06);
            padding: 16px
        }

        table {
            width: 100%;
            border-collapse: collapse
        }

        th,
        td {
            padding: 10px;
            border-bottom: 1px solid #eee;
            text-align: left;
            vertical-align: middle
        }

        thead th {
            font-size: 13px;
            color: #111;
            white-space: nowrap;
        }

        .pill {
            display: inline-block;
            padding: 3px 10px;
            border-radius: 999px;
            border: 1px solid #ddd;
            background: #fafafa;
            font-size: 12px;
            font-weight: 800;
            white-space: nowrap;
        }

        .pill.pend {
            border-color: #f39322;
            background: rgba(243, 147, 34, .12)
        }

        .pill.apr {
            border-color: #4CAF50;
            background: rgba(76, 175, 80, .12)
        }

        .pill.rej {
            border-color: #d32f57;
            background: rgba(211, 47, 87, .12)
        }

        .btn {
            padding: 8px 12px;
            border: 0;
            border-radius: 10px;
            font-weight: 900;
            cursor: pointer;
            white-space: nowrap;
        }

        .btn.approve { background: #4CAF50; color: #fff }
        .btn.reject { background: #d32f57; color: #fff }
        .btn.pend { background: #f39322; color: #1f1f1f }
        .btn.save { background: #0F172A; color: #fff }
        .btn.reset { background: #111827; color: #fff }

        .btn:hover { filter: brightness(1.05) }

        .msg {
            margin: 0 0 12px;
            padding: 10px 12px;
            border-radius: 12px;
            border: 1px solid #ddd;
            background: #fff;
            font-weight: 700
        }

        .msg.ok { border-color: #4CAF50; background: rgba(76, 175, 80, .10) }
        .msg.err { border-color: #d32f57; background: rgba(211, 47, 87, .10) }
        .msg.warn { border-color: #f39322; background: rgba(243, 147, 34, .10) }

        .small { font-size: 12px; color: #666 }

        code {
            font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
            font-size: 12px
        }

        .row2 {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
            align-items: center
        }

        select {
            padding: 8px 10px;
            border: 1px solid #ccc;
            border-radius: 10px
        }

        .muted {
            color: #444;
            font-weight: 700
        }

        .divider {
            height: 1px;
            background: #eee;
            margin: 10px 0;
        }

        .search {
            padding: 10px 12px;
            border: 1px solid #ccc;
            border-radius: 12px;
            min-width: 280px;
            font-weight: 700;
        }

        /* ✅ Acciones: organizadas y sin apeñuscar */
        .cell-actions {
            display: flex;
            flex-direction: column;
            gap: 10px;
            min-width: 360px;
        }

        .actionsRow {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
            align-items: center;
        }

        .actionsRow form { margin: 0; }

        /* Acomodar columnas para que no se “vaya” el espacio */
        .col-name { width: 18%; }
        .col-email { width: 20%; }
        .col-estado { width: 10%; }
        .col-rol { width: 18%; }
        .col-admin { width: 10%; }
        .col-activo { width: 10%; }
        .col-actions { width: 14%; }

        @media (max-width: 980px) {
            .cell-actions { min-width: 280px; }
            .search { min-width: 220px; }
        }
    </style>
</head>

<body>
    <div class="wrap">
        <div class="top">
            <div>
                <h1 style="margin:0;color:#942934">Usuarios (Admin)</h1>
                <div class="small">
                    <?php
                    $pwFlash = null;
                    if (!empty($_SESSION['admin_pw_reset']) && is_array($_SESSION['admin_pw_reset'])) {
                        $pwFlash = $_SESSION['admin_pw_reset'];
                        unset($_SESSION['admin_pw_reset']); // mostrar 1 sola vez
                    }
                    ?>

                    <?php if ($pwFlash): ?>
                        <div class="msg warn">
                            🔐 Contraseña temporal generada para:
                            <strong><?= htmlspecialchars($pwFlash['nombre'] ?? '') ?></strong>
                            (<?= htmlspecialchars($pwFlash['email'] ?? '') ?>) —
                            Temporal: <code><?= htmlspecialchars($pwFlash['temp'] ?? '') ?></code>
                            <div class="small">⚠️ Copia esto ya. Se muestra una sola vez.</div>
                        </div>
                    <?php endif; ?>

                    <?php if ($isSuper): ?>
                        Modo: <strong>Super Admin</strong> (puedes cambiar roles y admins)
                    <?php else: ?>
                        Modo: <strong>Admin</strong> (solo aprobar / rechazar)
                    <?php endif; ?>
                </div>
            </div>

            <div class="row2">
                <input id="userSearch" class="search" type="text" placeholder="Buscar por nombre o email…">
                <a href="../boards/index.php">← Volver</a>
                <a href="teams.php">Equipos →</a>
            </div>
        </div>

        <?php if ($ok): ?>
            <div class="msg ok">✅ Acción aplicada correctamente.</div>
        <?php endif; ?>
        <?php if ($err): ?>
            <div class="msg err">❌ No se pudo aplicar la acción (CSRF / permisos / datos inválidos).</div>
        <?php endif; ?>

        <div class="card">
            <table>
                <thead>
                    <tr>
                        <th class="col-name">Nombre</th>
                        <th class="col-email">Email</th>
                        <th class="col-estado">Estado</th>
                        <th class="col-rol">Rol</th>
                        <th class="col-admin">Admin</th>
                        <th class="col-activo">Activo</th>
                        <th class="col-actions">Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($users as $u): ?>
                        <?php
                        $estado = $u['estado'] ?? 'pendiente';
                        $pillClass = $estado === 'aprobado' ? 'apr' : ($estado === 'rechazado' ? 'rej' : 'pend');
                        $uid = (int) ($u['id'] ?? 0);
                        $rolActual = (string) ($u['rol'] ?? 'user');
                        $isAdmin = ((int) ($u['is_admin'] ?? 0) === 1);
                        $activo = ((int) ($u['activo'] ?? 1) === 1);
                        $deletedAt = (string) ($u['deleted_at'] ?? '');
                        ?>
                        <tr>
                            <td class="muted"><?= htmlspecialchars($u['nombre'] ?? '') ?></td>
                            <td><?= htmlspecialchars($u['email'] ?? '') ?></td>
                            <td>
                                <span class="pill <?= $pillClass ?>"><?= htmlspecialchars(estadoBadge($estado)) ?></span>
                            </td>

                            <td>
                                <?php if ($isSuper): ?>
                                    <form method="post" action="user_action.php" class="row2">
                                        <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['csrf']) ?>">
                                        <input type="hidden" name="user_id" value="<?= $uid ?>">
                                        <input type="hidden" name="action" value="set_role">
                                        <select name="rol">
                                            <?php foreach ($ROLE_OPTIONS as $key => $label): ?>
                                                <option value="<?= htmlspecialchars($key) ?>" <?= ($rolActual === $key ? 'selected' : '') ?>>
                                                    <?= htmlspecialchars($label) ?>
                                                </option>
                                            <?php endforeach; ?>
                                            <?php if (!isset($ROLE_OPTIONS[$rolActual])): ?>
                                                <option value="<?= htmlspecialchars($rolActual) ?>" selected><?= htmlspecialchars($rolActual) ?></option>
                                            <?php endif; ?>
                                        </select>
                                        <button class="btn save" type="submit">Guardar</button>
                                    </form>
                                <?php else: ?>
                                    <span class="pill"><?= htmlspecialchars($rolActual) ?></span>
                                <?php endif; ?>
                            </td>

                            <td class="small">
                                <?php if ($isSuper): ?>
                                    <span class="pill <?= $isAdmin ? 'apr' : 'pend' ?>"><?= $isAdmin ? 'Sí' : 'No' ?></span>
                                <?php else: ?>
                                    <?= $isAdmin ? 'Sí' : 'No' ?>
                                <?php endif; ?>
                            </td>

                            <td class="small">
                                <?php
                                if (!$activo) echo '<span class="pill rej">Suspendido</span>';
                                else echo '<span class="pill apr">Activo</span>';

                                if ($deletedAt) echo ' <span class="pill pend">Eliminado</span>';
                                ?>
                            </td>

                            <td>
                                <div class="cell-actions">
                                    <!-- ✅ Acciones base -->
                                    <div class="actionsRow">
                                        <form method="post" action="user_action.php">
                                            <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['csrf']) ?>">
                                            <input type="hidden" name="user_id" value="<?= $uid ?>">
                                            <input type="hidden" name="action" value="approve">
                                            <button class="btn approve" type="submit">Aprobar</button>
                                        </form>

                                        <form method="post" action="user_action.php">
                                            <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['csrf']) ?>">
                                            <input type="hidden" name="user_id" value="<?= $uid ?>">
                                            <input type="hidden" name="action" value="pend">
                                            <button class="btn pend" type="submit">Pendiente</button>
                                        </form>

                                        <form method="post" action="user_action.php">
                                            <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['csrf']) ?>">
                                            <input type="hidden" name="user_id" value="<?= $uid ?>">
                                            <input type="hidden" name="action" value="reject">
                                            <button class="btn reject" type="submit">Rechazar</button>
                                        </form>
                                    </div>

                                    <?php if ($isSuper): ?>
                                        <div class="divider"></div>

                                        <!-- ✅ Acciones super admin (sin duplicar nada) -->
                                        <div class="actionsRow">
                                            <!-- Toggle Admin -->
                                            <form method="post" action="user_action.php">
                                                <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['csrf']) ?>">
                                                <input type="hidden" name="user_id" value="<?= $uid ?>">
                                                <input type="hidden" name="action" value="toggle_admin">
                                                <input type="hidden" name="is_admin" value="<?= $isAdmin ? '0' : '1' ?>">
                                                <button class="btn <?= $isAdmin ? 'reject' : 'approve' ?>" type="submit">
                                                    <?= $isAdmin ? 'Quitar admin' : 'Hacer admin' ?>
                                                </button>
                                            </form>

                                            <!-- Reset clave -->
                                            <form method="post" action="user_password_reset.php"
                                                onsubmit="return confirm('¿Resetear contraseña y generar una temporal?');">
                                                <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['csrf']) ?>">
                                                <input type="hidden" name="user_id" value="<?= $uid ?>">
                                                <button class="btn reset" type="submit">Reset clave</button>
                                            </form>

                                            <!-- Suspender/Activar -->
                                            <form method="post" action="user_action.php"
                                                onsubmit="return confirm('¿Seguro que deseas <?= $activo ? 'SUSPENDER' : 'ACTIVAR' ?> este usuario?');">
                                                <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['csrf']) ?>">
                                                <input type="hidden" name="user_id" value="<?= $uid ?>">
                                                <input type="hidden" name="action" value="toggle_active">
                                                <input type="hidden" name="activo" value="<?= $activo ? '0' : '1' ?>">
                                                <button class="btn <?= $activo ? 'pend' : 'approve' ?>" type="submit">
                                                    <?= $activo ? 'Suspender' : 'Activar' ?>
                                                </button>
                                            </form>

                                            <!-- Eliminar -->
                                            <form method="post" action="user_action.php"
                                                onsubmit="return confirm('¿ELIMINAR lógico este usuario? (quedará desactivado)');">
                                                <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['csrf']) ?>">
                                                <input type="hidden" name="user_id" value="<?= $uid ?>">
                                                <input type="hidden" name="action" value="soft_delete">
                                                <button class="btn reject" type="submit">Eliminar</button>
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
        var rows = document.querySelectorAll('tbody tr');
        rows.forEach(function (tr) {
          var txt = (tr.textContent || '').toLowerCase();
          tr.style.display = (!q || txt.indexOf(q) !== -1) ? '' : 'none';
        });
      });
    })();
    </script>
</body>

</html>