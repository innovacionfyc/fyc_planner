<?php
// public/admin/users_pending.php
require_once __DIR__ . '/../_auth.php';
require_admin();
require_once __DIR__ . '/../../config/db.php';

// CSRF para acciones
if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}

$msg = '';
if (!empty($_GET['ok']))
    $msg = '✅ Acción realizada.';
if (!empty($_GET['err']))
    $msg = '❌ No se pudo completar la acción.';

$stmt = $conn->prepare("SELECT id, nombre, email, rol, estado, creado_en
                        FROM users
                        WHERE estado IN (0, -1)
                        ORDER BY estado ASC, creado_en ASC");
$stmt->execute();
$pend = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
?>
<!doctype html>
<html lang="es">

<head>
    <meta charset="utf-8">
    <title>F&C Planner — Aprobación de usuarios</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <style>
        body {
            font-family: system-ui;
            margin: 0;
            background: #f7f7f7
        }

        .wrap {
            max-width: 900px;
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
            padding: 16px;
            margin-bottom: 12px
        }

        .row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 12px;
            flex-wrap: wrap
        }

        .pill {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 999px;
            font-size: 12px;
            border: 1px solid #ddd;
            background: #fafafa
        }

        .pill-pend {
            border-color: #f39322;
            background: #fff7ed;
            color: #9a3412
        }

        .pill-rej {
            border-color: #d32f57;
            background: #ffe8ea;
            color: #8b1c2b
        }

        .btn {
            padding: 8px 12px;
            border: 0;
            border-radius: 10px;
            background: #d32f57;
            color: #fff;
            font-weight: 700;
            cursor: pointer
        }

        .btn:hover {
            filter: brightness(1.08)
        }

        .btn-sec {
            background: #e5e7eb;
            color: #111827
        }

        .btn-dan {
            background: #e96510
        }

        .msg {
            margin: 8px 0
        }
    </style>
</head>

<body>
    <div class="wrap">
        <div class="top">
            <h1 style="margin:0;color:#942934">Aprobación de usuarios</h1>
            <div>
                <a href="../boards/index.php">← Volver</a>
            </div>
        </div>

        <?php if ($msg): ?>
            <div class="msg"><?= htmlspecialchars($msg) ?></div><?php endif; ?>

        <?php if (!$pend): ?>
            <div class="card">No hay usuarios pendientes ni rechazados.</div>
        <?php else: ?>
            <?php foreach ($pend as $u): ?>
                <div class="card">
                    <div class="row">
                        <div>
                            <div><strong><?= htmlspecialchars($u['nombre']) ?></strong> · <?= htmlspecialchars($u['email']) ?>
                            </div>
                            <div style="font-size:12px;color:#666">
                                Rol: <?= htmlspecialchars($u['rol']) ?> · Registrado: <?= htmlspecialchars($u['creado_en']) ?>
                                <?php if ((int) $u['estado'] === 0): ?>
                                    · <span class="pill pill-pend">pendiente</span>
                                <?php elseif ((int) $u['estado'] === -1): ?>
                                    · <span class="pill pill-rej">rechazado</span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="row" style="gap:8px">
                            <?php if ((int) $u['estado'] !== 1): ?>
                                <form method="post" action="user_action.php">
                                    <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['csrf']) ?>">
                                    <input type="hidden" name="user_id" value="<?= (int) $u['id'] ?>">
                                    <input type="hidden" name="action" value="approve">
                                    <button class="btn" type="submit">Aprobar</button>
                                </form>
                            <?php endif; ?>
                            <?php if ((int) $u['estado'] !== -1): ?>
                                <form method="post" action="user_action.php" onsubmit="return confirm('¿Rechazar este usuario?');">
                                    <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['csrf']) ?>">
                                    <input type="hidden" name="user_id" value="<?= (int) $u['id'] ?>">
                                    <input type="hidden" name="action" value="reject">
                                    <button class="btn btn-dan" type="submit">Rechazar</button>
                                </form>
                            <?php else: ?>
                                <form method="post" action="user_action.php">
                                    <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['csrf']) ?>">
                                    <input type="hidden" name="user_id" value="<?= (int) $u['id'] ?>">
                                    <input type="hidden" name="action" value="pend">
                                    <button class="btn btn-sec" type="submit">Marcar como pendiente</button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>

    </div>
</body>

</html>