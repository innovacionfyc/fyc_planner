<?php
// public/admin/teams.php
require_once __DIR__ . '/../_auth.php';
require_admin();
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../_i18n.php';


if (empty($_SESSION['csrf']))
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
$msg = '';

/* Crear equipo */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['do'] ?? '') === 'create_team') {
    if (!hash_equals($_SESSION['csrf'], $_POST['csrf'] ?? '')) {
        $msg = 'CSRF';
    } else {
        $name = trim($_POST['nombre'] ?? '');
        if ($name !== '') {
            $ins = $conn->prepare("INSERT INTO teams (nombre) VALUES (?)");
            $ins->bind_param('s', $name);
            $msg = $ins->execute() ? 'Equipo creado.' : 'No se pudo crear.';
        }
    }
}

/* Crear equipo */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['do'] ?? '') === 'create_team') {
    if (!hash_equals($_SESSION['csrf'], $_POST['csrf'] ?? '')) {
        $msg = 'CSRF';
    } else {
        $name = trim($_POST['nombre'] ?? '');
        if ($name !== '') {
            $ins = $conn->prepare("INSERT INTO teams (nombre) VALUES (?)");
            $ins->bind_param('s', $name);
            if ($ins->execute()) {
                $team_id = $conn->insert_id; // 👈 id del nuevo equipo
                // auto-agregar al creador como owner
                $own = $conn->prepare("INSERT IGNORE INTO team_members (team_id, user_id, rol_en_team) VALUES (?, ?, 'owner')");
                $own->bind_param('ii', $team_id, $_SESSION['user_id']);
                $own->execute();
                $msg = 'Equipo creado.';
            } else {
                $msg = 'No se pudo crear.';
            }
        }
    }
}


/* Agregar miembro por email */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['do'] ?? '') === 'add_member') {
    if (!hash_equals($_SESSION['csrf'], $_POST['csrf'] ?? '')) {
        $msg = 'CSRF';
    } else {
        $team_id = (int) ($_POST['team_id'] ?? 0);
        $email = trim($_POST['email'] ?? '');
        $rol = ($_POST['rol'] ?? 'member') === 'owner' ? 'owner' : 'member';

        if ($team_id > 0 && $email !== '') {
            // localizar usuario
            $u = $conn->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
            $u->bind_param('s', $email);
            $u->execute();
            $uid = $u->get_result()->fetch_row()[0] ?? 0;

            if ($uid) {
                $ins = $conn->prepare("INSERT IGNORE INTO team_members (team_id, user_id, rol_en_team) VALUES (?, ?, ?)");
                $ins->bind_param('iis', $team_id, $uid, $rol);
                $msg = $ins->execute() ? 'Miembro agregado.' : 'No se pudo agregar.';
            } else {
                $msg = 'No existe un usuario con ese email.';
            }
        }
    }
}

/* Cargar equipos + miembros */
$teams = $conn->query("SELECT id, nombre, creado_en FROM teams ORDER BY nombre ASC")->fetch_all(MYSQLI_ASSOC);

function membersOf($conn, $team_id)
{
    $q = $conn->prepare("SELECT u.nombre, u.email, tm.rol_en_team
                       FROM team_members tm
                       JOIN users u ON u.id = tm.user_id
                       WHERE tm.team_id = ?
                       ORDER BY u.nombre ASC");
    $q->bind_param('i', $team_id);
    $q->execute();
    return $q->get_result()->fetch_all(MYSQLI_ASSOC);
}
?>
<!doctype html>
<html lang="es">

<head>
    <meta charset="utf-8">
    <title>Equipos — F&C Planner</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <style>
        body {
            font-family: system-ui;
            margin: 0;
            background: #f7f7f7
        }

        .wrap {
            max-width: 1000px;
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
            gap: 8px;
            align-items: center;
            flex-wrap: wrap
        }

        .input,
        select {
            padding: 10px;
            border: 1px solid #ccc;
            border-radius: 10px
        }

        .btn {
            padding: 10px 14px;
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

        .pill {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 999px;
            border: 1px solid #ddd;
            background: #fafafa;
            font-size: 12px
        }

        .msg {
            margin-bottom: 10px;
            color: #333
        }

        table {
            width: 100%;
            border-collapse: collapse
        }

        th,
        td {
            padding: 8px;
            border-bottom: 1px solid #eee;
            text-align: left
        }
    </style>
</head>

<body>
    <div class="wrap">
        <div class="top">
            <h1 style="margin:0;color:#942934">Equipos (admin)</h1>
            <div><a href="../boards/index.php">← Volver</a></div>
        </div>

        <?php if ($msg): ?>
            <div class="msg"><?= htmlspecialchars($msg) ?></div><?php endif; ?>

        <div class="card">
            <h3 style="margin:0 0 8px">Crear equipo</h3>
            <form method="post" class="row">
                <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['csrf']) ?>">
                <input type="hidden" name="do" value="create_team">
                <input class="input" type="text" name="nombre" placeholder="Nombre del equipo (TI, Comercial...)"
                    required>
                <button class="btn">Crear</button>
            </form>
        </div>

        <?php foreach ($teams as $t):
            $m = membersOf($conn, (int) $t['id']); ?>
            <div class="card">
                <div class="row" style="justify-content:space-between">
                    <strong><?= htmlspecialchars($t['nombre']) ?></strong>
                    <span class="pill">Miembros: <?= count($m) ?></span>
                </div>
                <?php if ($m): ?>
                    <table>
                        <thead>
                            <tr>
                                <th>Nombre</th>
                                <th>Email</th>
                                <th>Rol</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($m as $x): ?>
                                <tr>
                                    <td><?= htmlspecialchars($x['nombre']) ?></td>
                                    <td><?= htmlspecialchars($x['email']) ?></td>
                                    <td><?= htmlspecialchars(tr_team_role($x['rol_en_team'])) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <p style="color:#666">Sin miembros todavía.</p>
                <?php endif; ?>

                <form method="post" class="row" style="margin-top:10px">
                    <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['csrf']) ?>">
                    <input type="hidden" name="do" value="add_member">
                    <input type="hidden" name="team_id" value="<?= (int) $t['id'] ?>">
                    <input class="input" type="email" name="email" placeholder="email@fycconsultores.com" required>
                    <select name="rol">
                        <option value="member">Miembro</option>
                        <option value="owner">Propietario</option>
                    </select>
                    <button class="btn">Agregar miembro</button>
                </form>
            </div>
        <?php endforeach; ?>

    </div>
</body>

</html>