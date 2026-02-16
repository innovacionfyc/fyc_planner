<?php
require_once __DIR__ . '/../_auth.php';
require_login();
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../_i18n.php';

// CSRF
if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}

// Mis equipos
$tm = $conn->prepare("SELECT t.id, t.nombre, tm.rol
                      FROM team_members tm
                      JOIN teams t ON t.id = tm.team_id
                      WHERE tm.user_id = ?
                      ORDER BY t.nombre ASC");
$tm->bind_param('i', $_SESSION['user_id']);
$tm->execute();
$mis_teams = $tm->get_result()->fetch_all(MYSQLI_ASSOC);

// Todos mis boards (soy miembro), con nombre de equipo si aplica
$sql = "SELECT b.id, b.nombre, b.color_hex, b.created_at, b.team_id, t.nombre AS team_nombre, bm.rol
        FROM board_members bm
        JOIN boards b ON b.id = bm.board_id
        LEFT JOIN teams t ON t.id = b.team_id
        WHERE bm.user_id = ?
        ORDER BY b.created_at DESC";
$stmt = $conn->prepare($sql);
$stmt->bind_param('i', $_SESSION['user_id']);
$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Separar personales vs de equipo
$boards_personal = [];
$boards_equipo = [];
foreach ($rows as $r) {
    if ($r['team_id'] === null) {
        $boards_personal[] = $r;
    } else {
        $boards_equipo[] = $r;
    }
}
?>
<!doctype html>
<html lang="es">

<head>
    <meta charset="utf-8">
    <title>F&C Planner — Tableros</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <style>
        :root {
            --bg: #f6f7fb;
            --card: #ffffff;
            --text: #1f2937;
            --muted: #6b7280;
            --border: #e5e7eb;
            --brand: #942934;
            --brand2: #d32f57;

            --radius: 16px;
            --shadow: 0 12px 30px rgba(17, 24, 39, .08);
            --shadow2: 0 6px 18px rgba(17, 24, 39, .06);
        }

        * {
            box-sizing: border-box
        }

        html,
        body {
            height: 100%
        }

        body {
            margin: 0;
            font-family: ui-sans-serif, system-ui, -apple-system, Segoe UI, Roboto, Arial;
            background: radial-gradient(1200px 500px at 20% -10%, rgba(211, 47, 87, .12), transparent 60%),
                radial-gradient(900px 400px at 85% 0%, rgba(148, 41, 52, .10), transparent 55%),
                var(--bg);
            color: var(--text);
        }

        .wrap {
            max-width: 1100px;
            margin: 22px auto;
            padding: 0 16px 40px;
        }

        .top {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            margin-bottom: 14px;
            padding: 10px 0;
        }

        .h1 {
            margin: 0;
            color: var(--brand);
            font-weight: 900;
            letter-spacing: -.4px;
            font-size: 26px;
        }

        .top a {
            color: var(--brand);
            text-decoration: none;
            font-weight: 700;
        }

        .top a:hover {
            text-decoration: underline
        }

        .card {
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            box-shadow: var(--shadow2);
            padding: 14px;
        }

        .mb8 {
            margin-bottom: 12px
        }

        .grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(260px, 1fr));
            gap: 12px;
        }

        /* form crear */
        .formBar {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            align-items: center;
            margin-top: 6px;
        }

        label {
            display: block;
            font-size: 12px;
            color: var(--muted);
            font-weight: 800;
            margin-bottom: 8px;
            letter-spacing: .2px;
        }

        .input,
        select {
            width: 100%;
            padding: 11px 12px;
            border: 1px solid var(--border);
            border-radius: 12px;
            background: #fff;
            font-size: 14px;
            outline: none;
            transition: box-shadow .18s ease, border-color .18s ease, transform .18s ease;
        }

        .input:focus,
        select:focus {
            border-color: rgba(211, 47, 87, .55);
            box-shadow: 0 0 0 4px rgba(211, 47, 87, .15);
        }

        .btn {
            padding: 11px 14px;
            border: 0;
            border-radius: 12px;
            background: linear-gradient(135deg, var(--brand2), var(--brand));
            color: #fff;
            font-weight: 900;
            cursor: pointer;
            transition: transform .12s ease, filter .12s ease, box-shadow .12s ease;
            box-shadow: 0 10px 18px rgba(211, 47, 87, .18);
            white-space: nowrap;
        }

        .btn:hover {
            filter: brightness(1.05)
        }

        .btn:active {
            transform: translateY(1px)
        }

        /* cards de board */
        .boardCard {
            position: relative;
            overflow: hidden;
        }

        .boardCard:before {
            content: "";
            position: absolute;
            inset: 0;
            background: radial-gradient(500px 150px at 0% 0%, rgba(211, 47, 87, .12), transparent 60%);
            pointer-events: none;
        }

        .boardTop {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 12px;
        }

        .boardName {
            font-weight: 900;
            letter-spacing: -.2px;
        }

        .boardMeta {
            margin-top: 4px;
            font-size: 12px;
            color: var(--muted);
            font-weight: 700;
        }

        .tag {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 4px 10px;
            border-radius: 999px;
            border: 1px solid var(--border);
            background: rgba(17, 24, 39, .03);
            font-size: 12px;
            font-weight: 900;
            color: #374151;
            white-space: nowrap;
        }

        .openLink {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            margin-top: 12px;
            color: var(--brand);
            font-weight: 900;
            text-decoration: none;
        }

        .openLink:hover {
            text-decoration: underline
        }

        h2 {
            margin: 18px 0 10px;
            font-size: 16px;
            font-weight: 950;
            color: #111827;
        }

        p {
            color: #111827;
        }

        /* responsive */
        @media (max-width: 560px) {
            .h1 {
                font-size: 22px
            }

            .grid {
                grid-template-columns: 1fr
            }
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

                <div class="formBar">
                    <input class="input" style="flex:2;min-width:220px" type="text" name="nombre"
                        placeholder="Ej. Comercial, Personal, TI…" required>

                    <select class="input" style="flex:1;min-width:220px" name="team_id">
                        <option value="">Espacio: Personal</option>

                        <?php foreach ($mis_teams as $t): ?>
                            <?php if ($t['rol'] === 'admin_equipo'): ?>
                                <option value="<?= (int) $t['id'] ?>">
                                    Equipo: <?= htmlspecialchars($t['nombre']) ?> (admin equipo)
                                </option>
                            <?php else: ?>
                                <option value="" disabled>
                                    Equipo: <?= htmlspecialchars($t['nombre']) ?> (solo admin equipo)
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
                    <div class="card boardCard">
                        <div class="boardTop">
                            <div>
                                <div class="boardName"><?= htmlspecialchars($b['nombre']) ?></div>
                                <div class="boardMeta">Espacio: Personal</div>
                            </div>
                            <span class="tag"><?= htmlspecialchars(tr_board_role($b['rol'])) ?></span>
                        </div>

                        <a class="openLink" href="view.php?id=<?= (int) $b['id'] ?>">Abrir tablero →</a>
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
                    <div class="card boardCard">
                        <div class="boardTop">
                            <div>
                                <div class="boardName"><?= htmlspecialchars($b['nombre']) ?></div>
                                <div class="boardMeta">
                                    Equipo: <?= htmlspecialchars($b['team_nombre'] ?? '—') ?>
                                </div>
                            </div>
                            <span class="tag"><?= htmlspecialchars(tr_board_role($b['rol'])) ?></span>
                        </div>

                        <a class="openLink" href="view.php?id=<?= (int) $b['id'] ?>">Abrir tablero →</a>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

    </div>
</body>

</html>