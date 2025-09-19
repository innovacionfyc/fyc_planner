<?php
require_once __DIR__ . '/../_auth.php';
require_login();
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../_i18n.php';


// CSRF
if (empty($_SESSION['csrf']))
    $_SESSION['csrf'] = bin2hex(random_bytes(32));

// Mis equipos
$tm = $conn->prepare("SELECT t.id, t.nombre, tm.rol_en_team
                      FROM team_members tm
                      JOIN teams t ON t.id = tm.team_id
                      WHERE tm.user_id = ?
                      ORDER BY t.nombre ASC");
$tm->bind_param('i', $_SESSION['user_id']);
$tm->execute();
$mis_teams = $tm->get_result()->fetch_all(MYSQLI_ASSOC);


// Todos mis boards (soy miembro), con nombre de equipo si aplica
$sql = "SELECT b.id, b.nombre, b.color_hex, b.creado_en, b.team_id, t.nombre AS team_nombre, bm.rol_en_board
        FROM board_members bm
        JOIN boards b ON b.id = bm.board_id
        LEFT JOIN teams t ON t.id = b.team_id
        WHERE bm.user_id = ?
        ORDER BY b.creado_en DESC";
$stmt = $conn->prepare($sql);
$stmt->bind_param('i', $_SESSION['user_id']);
$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Separar personales vs de equipo
$boards_personal = [];
$boards_equipo = [];
foreach ($rows as $r) {
    if ($r['team_id'] === null)
        $boards_personal[] = $r;
    else
        $boards_equipo[] = $r;
}
?>
<!doctype html>
<html lang="es">

<head>
    <meta charset="utf-8">
    <title>F&C Planner — Tableros</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <style>
        body {
            font-family: system-ui;
            margin: 0;
            background: #f7f7f7
        }

        .wrap {
            max-width: 980px;
            margin: 24px auto;
            padding: 0 16px
        }

        .top {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 16px
        }

        .h1 {
            margin: 0;
            color: #942934
        }

        .grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(240px, 1fr));
            gap: 12px
        }

        .card {
            background: #fff;
            border: 1px solid #ddd;
            border-radius: 16px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, .06);
            padding: 14px
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

        .input,
        select {
            width: 100%;
            padding: 10px;
            border: 1px solid #ccc;
            border-radius: 10px
        }

        .mb8 {
            margin-bottom: 8px
        }

        .tag {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 999px;
            background: #eee;
            font-size: 12px
        }

        a {
            color: #942934;
            text-decoration: none;
            font-weight: 600
        }

        h2 {
            margin: 16px 0 10px
        }
    </style>
</head>

<body>
    <div class="wrap">
        <div class="top">
            <h1 class="h1">Tus tableros</h1>
            <div>
                <?php if (isset($_SESSION['rol']) && $_SESSION['rol'] === 'admin'): ?>
                    <a href="../admin/users_pending.php">Aprobar usuarios</a> &nbsp;|&nbsp;
                    <a href="../admin/teams.php">Equipos</a> &nbsp;|&nbsp;
                <?php endif; ?>
                <a href="../logout.php">Cerrar sesión</a>
            </div>
        </div>

        <div class="card mb8">
            <form method="post" action="create.php">
                <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['csrf']) ?>">
                <label>Nombre del tablero</label>
                <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:6px">
                    <input class="input" style="flex:2" type="text" name="nombre"
                        placeholder="Ej. Comercial, Personal, TI…" required>
                    <select class="input" style="flex:1" name="team_id">
                        <option value="">Espacio: Personal</option>
                        <?php foreach ($mis_teams as $t): ?>
                            <?php if ($t['rol_en_team'] === 'owner'): ?>
                                <option value="<?= (int) $t['id'] ?>">Equipo: <?= htmlspecialchars($t['nombre']) ?>
                                    (propietario)</option>
                            <?php else: ?>
                                <option value="" disabled>Equipo: <?= htmlspecialchars($t['nombre']) ?> (solo propietarios)
                                </option>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </select>
                    <button class="btn" type="submit">Crear</button>
                </div>
            </form>
        </div>

        <h2>Personales</h2>
        <?php if (!$boards_personal): ?>
            <p>No tienes tableros personales todavía.</p>
        <?php else: ?>
            <div class="grid">
                <?php foreach ($boards_personal as $b): ?>
                    <div class="card">
                        <div style="display:flex;justify-content:space-between;align-items:center">
                            <strong><?= htmlspecialchars($b['nombre']) ?></strong>
                            <span class="tag"><?= htmlspecialchars(tr_board_role($b['rol_en_board'])) ?></span>
                        </div>
                        <div style="margin-top:10px">
                            <a href="view.php?id=<?= (int) $b['id'] ?>">Abrir tablero →</a>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <h2 style="margin-top:20px">De mis equipos</h2>
        <?php if (!$boards_equipo): ?>
            <p>No perteneces aún a tableros de equipo.</p>
        <?php else: ?>
            <div class="grid">
                <?php foreach ($boards_equipo as $b): ?>
                    <div class="card">
                        <div style="display:flex;justify-content:space-between;align-items:center">
                            <div>
                                <strong><?= htmlspecialchars($b['nombre']) ?></strong>
                                <div style="font-size:12px;color:#666">Equipo: <?= htmlspecialchars($b['team_nombre'] ?? '—') ?>
                                </div>
                            </div>
                            <span class="tag"><?= htmlspecialchars(tr_board_role($b['rol_en_board'])) ?></span>
                        </div>
                        <div style="margin-top:10px">
                            <a href="view.php?id=<?= (int) $b['id'] ?>">Abrir tablero →</a>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

    </div>
</body>

</html>