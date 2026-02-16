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

// Traer usuarios (pendientes primero)
$users = $conn->query("
    SELECT id, nombre, email, estado, rol, is_admin, created_at
    FROM users
    ORDER BY
      (estado = 'pendiente') DESC,
      created_at DESC
")->fetch_all(MYSQLI_ASSOC);

function estadoBadge($estado)
{
    if ($estado === 'aprobado')
        return 'Aprobado';
    if ($estado === 'rechazado')
        return 'Rechazado';
    return 'Pendiente';
}
?>
<!doctype html>
<html lang="es">

<head>
    <meta charset="utf-8">
    <title>Aprobar usuarios — F&C Planner</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <style>
        body {
            font-family: system-ui;
            margin: 0;
            background: #f7f7f7
        }

        .wrap {
            max-width: 1050px;
            margin: 24px auto;
            padding: 0 16px
        }

        .top {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 16px
        }

        a {
            color: #942934;
            text-decoration: none;
            font-weight: 600
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

        .pill {
            display: inline-block;
            padding: 3px 10px;
            border-radius: 999px;
            border: 1px solid #ddd;
            background: #fafafa;
            font-size: 12px
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
            font-weight: 800;
            cursor: pointer
        }

        .btn.approve {
            background: #4CAF50;
            color: #fff
        }

        .btn.reject {
            background: #d32f57;
            color: #fff
        }

        .btn.pend {
            background: #f39322;
            color: #1f1f1f
        }

        .btn:hover {
            filter: brightness(1.05)
        }

        .msg {
            margin: 0 0 12px;
            padding: 10px 12px;
            border-radius: 12px;
            border: 1px solid #ddd;
            background: #fff
        }

        .msg.ok {
            border-color: #4CAF50;
            background: rgba(76, 175, 80, .10)
        }

        .msg.err {
            border-color: #d32f57;
            background: rgba(211, 47, 87, .10)
        }

        .small {
            font-size: 12px;
            color: #666
        }

        .actions {
            display: flex;
            gap: 8px;
            flex-wrap: wrap
        }
    </style>
</head>

<body>
    <div class="wrap">
        <div class="top">
            <h1 style="margin:0;color:#942934">Aprobar usuarios</h1>
            <div><a href="../boards/index.php">← Volver</a></div>
        </div>

        <?php if ($ok): ?>
            <div class="msg ok">✅ Acción aplicada correctamente.</div>
        <?php endif; ?>
        <?php if ($err): ?>
            <div class="msg err">❌ No se pudo aplicar la acción (CSRF o datos inválidos).</div>
        <?php endif; ?>

        <div class="card">
            <table>
                <thead>
                    <tr>
                        <th>Nombre</th>
                        <th>Email</th>
                        <th>Estado</th>
                        <th>Rol</th>
                        <th>Admin</th>
                        <th>Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($users as $u): ?>
                        <?php
                        $estado = $u['estado'] ?? 'pendiente';
                        $pillClass = $estado === 'aprobado' ? 'apr' : ($estado === 'rechazado' ? 'rej' : 'pend');
                        ?>
                        <tr>
                            <td>
                                <?= htmlspecialchars($u['nombre'] ?? '') ?>
                            </td>
                            <td>
                                <?= htmlspecialchars($u['email'] ?? '') ?>
                            </td>
                            <td><span class="pill <?= $pillClass ?>">
                                    <?= htmlspecialchars(estadoBadge($estado)) ?>
                                </span></td>
                            <td><span class="pill">
                                    <?= htmlspecialchars($u['rol'] ?? 'user') ?>
                                </span></td>
                            <td class="small">
                                <?= ((int) ($u['is_admin'] ?? 0) === 1) ? 'Sí' : 'No' ?>
                            </td>
                            <td>
                                <div class="actions">
                                    <form method="post" action="user_action.php">
                                        <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['csrf']) ?>">
                                        <input type="hidden" name="user_id" value="<?= (int) $u['id'] ?>">
                                        <input type="hidden" name="action" value="approve">
                                        <button class="btn approve" type="submit">Aprobar</button>
                                    </form>

                                    <form method="post" action="user_action.php">
                                        <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['csrf']) ?>">
                                        <input type="hidden" name="user_id" value="<?= (int) $u['id'] ?>">
                                        <input type="hidden" name="action" value="pend">
                                        <button class="btn pend" type="submit">Pendiente</button>
                                    </form>

                                    <form method="post" action="user_action.php">
                                        <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['csrf']) ?>">
                                        <input type="hidden" name="user_id" value="<?= (int) $u['id'] ?>">
                                        <input type="hidden" name="action" value="reject">
                                        <button class="btn reject" type="submit">Rechazar</button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</body>

</html>