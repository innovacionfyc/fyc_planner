<?php
// public/reports/my_team.php — Estadísticas acotadas para admin_equipo
require_once __DIR__ . '/../_auth.php';
require_login();
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../_perm.php';

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

$userId = (int)($_SESSION['user_id'] ?? 0);

// ---- Determinar equipos visibles ----
// Super admin y admin (is_admin=1) ven todos los equipos
if (is_admin_user($conn)) {
    $qAll = $conn->query("SELECT id FROM teams");
    $teamIds = $qAll ? array_map(fn($r) => (int)$r['id'], $qAll->fetch_all(MYSQLI_ASSOC)) : [];
} else {
    $qTm = $conn->prepare("SELECT team_id FROM team_members WHERE user_id = ? AND rol = 'admin_equipo'");
    $qTm->bind_param('i', $userId);
    $qTm->execute();
    $teamIds = array_map(fn($r) => (int)$r['team_id'], $qTm->get_result()->fetch_all(MYSQLI_ASSOC));
}

if (empty($teamIds)) {
    header('Location: ../boards/workspace.php');
    exit;
}

$inList = implode(',', $teamIds); // safe: all are int-cast

// ---- Nombre(s) de equipo para el título ----
$teamNames = [];
$qNames = $conn->query("SELECT nombre FROM teams WHERE id IN ($inList) ORDER BY nombre ASC");
if ($qNames) while ($r = $qNames->fetch_row()) $teamNames[] = $r[0];
$teamLabel = count($teamNames) === 1 ? $teamNames[0] : implode(', ', $teamNames);

// ============================================================
// KPIs acotados al equipo
// ============================================================
function kpiScoped(mysqli $conn, string $where): int {
    $r = $conn->query($where);
    return $r ? (int)($r->fetch_row()[0] ?? 0) : 0;
}

$totalTasks   = kpiScoped($conn, "SELECT COUNT(*) FROM tasks tk JOIN boards b ON b.id = tk.board_id WHERE b.team_id IN ($inList)");
$overdueTasks = kpiScoped($conn, "SELECT COUNT(*) FROM tasks tk JOIN boards b ON b.id = tk.board_id WHERE b.team_id IN ($inList) AND tk.fecha_limite IS NOT NULL AND tk.fecha_limite < NOW()");
$unassigned   = kpiScoped($conn, "SELECT COUNT(*) FROM tasks tk JOIN boards b ON b.id = tk.board_id WHERE b.team_id IN ($inList) AND tk.assignee_id IS NULL");
$stale5       = kpiScoped($conn, "SELECT COUNT(*) FROM tasks tk JOIN boards b ON b.id = tk.board_id WHERE b.team_id IN ($inList) AND COALESCE(tk.updated_at, tk.creado_en) < DATE_SUB(NOW(), INTERVAL 5 DAY)");
$commentsWeek = kpiScoped($conn, "SELECT COUNT(*) FROM comments c JOIN boards b ON b.id = c.board_id WHERE b.team_id IN ($inList) AND c.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)");

$overdueRatio = $totalTasks > 0 ? ($overdueTasks / $totalTasks) : 0;
$staleRatio   = $totalTasks > 0 ? ($stale5 / $totalTasks) : 0;

// ============================================================
// Distribución por prioridad
// ============================================================
$byPriority = ['urgent' => 0, 'high' => 0, 'med' => 0, 'low' => 0];
$qp = $conn->query("
    SELECT tk.prioridad, COUNT(*) AS n
    FROM tasks tk JOIN boards b ON b.id = tk.board_id
    WHERE b.team_id IN ($inList)
    GROUP BY tk.prioridad
");
if ($qp) while ($r = $qp->fetch_assoc()) $byPriority[$r['prioridad']] = (int)$r['n'];

// ============================================================
// Salud por equipo (uno por cada equipo del usuario)
// ============================================================
$teamRows = $conn->query("
    SELECT
        t.id,
        t.nombre AS equipo,
        COUNT(DISTINCT b.id)                                                           AS tableros,
        COUNT(tk.id)                                                                   AS tareas,
        COALESCE(SUM(tk.fecha_limite IS NOT NULL AND tk.fecha_limite < NOW()), 0)      AS vencidas,
        COALESCE(SUM(tk.assignee_id IS NULL), 0)                                       AS sin_resp,
        MAX(COALESCE(tk.updated_at, tk.creado_en))                                     AS ultima_mod
    FROM teams t
    LEFT JOIN boards b  ON b.team_id = t.id
    LEFT JOIN tasks tk  ON tk.board_id = b.id
    WHERE t.id IN ($inList)
    GROUP BY t.id, t.nombre
    ORDER BY vencidas DESC, tareas DESC
");
$teamStats = $teamRows ? $teamRows->fetch_all(MYSQLI_ASSOC) : [];

// ============================================================
// Carga por responsable (scoped)
// ============================================================
$personStats = $conn->query("
    SELECT
        u.nombre,
        COUNT(tk.id)                                                                   AS asignadas,
        COALESCE(SUM(tk.fecha_limite IS NOT NULL AND tk.fecha_limite < NOW()), 0)      AS vencidas,
        COALESCE(SUM(tk.prioridad IN ('high', 'urgent')), 0)                           AS alta_prio,
        MAX(COALESCE(tk.updated_at, tk.creado_en))                                     AS ultima_actividad
    FROM users u
    JOIN tasks  tk ON tk.assignee_id = u.id
    JOIN boards b  ON b.id = tk.board_id
    WHERE b.team_id IN ($inList)
    GROUP BY u.id, u.nombre
    ORDER BY vencidas DESC, asignadas DESC
    LIMIT 25
");
$personRows = $personStats ? $personStats->fetch_all(MYSQLI_ASSOC) : [];

// ============================================================
// Actividad reciente (scoped)
// ============================================================
$actQ = $conn->query("
    SELECT c.created_at, u.nombre AS actor, b.nombre AS tablero, tk.titulo AS tarea
    FROM comments c
    JOIN users  u  ON u.id  = c.user_id
    JOIN boards b  ON b.id  = c.board_id
    JOIN tasks  tk ON tk.id = c.task_id
    WHERE b.team_id IN ($inList) AND c.created_at >= DATE_SUB(NOW(), INTERVAL 48 HOUR)
    ORDER BY c.created_at DESC
    LIMIT 25
");
$activityRows = $actQ ? $actQ->fetch_all(MYSQLI_ASSOC) : [];

// ============================================================
// Helpers
// ============================================================
function riskBadge(int $vencidas, int $tareas): string {
    $dot  = '<span style="width:7px;height:7px;border-radius:50%;background:currentColor;display:inline-block;flex-shrink:0;"></span>';
    $wrap = 'display:inline-flex;align-items:center;gap:5px;font-size:12px;font-weight:700;padding:3px 10px;border-radius:999px;';
    if ($vencidas === 0)
        return '<span style="' . $wrap . 'background:var(--badge-ok-bg);color:var(--badge-ok-tx);">' . $dot . '0</span>';
    $pct = $tareas > 0 ? ($vencidas / $tareas * 100) : 100;
    if ($pct > 20)
        return '<span style="' . $wrap . 'background:var(--badge-overdue-bg);color:var(--badge-overdue-tx);">' . $dot . $vencidas . '</span>';
    return '<span style="' . $wrap . 'background:var(--badge-soon-bg);color:var(--badge-soon-tx);">' . $dot . $vencidas . '</span>';
}

function relTime(string $dt): string {
    if (!$dt) return '—';
    $diff = time() - strtotime($dt);
    if ($diff < 60)    return 'hace ' . $diff . 's';
    if ($diff < 3600)  return 'hace ' . floor($diff / 60) . 'min';
    if ($diff < 86400) return 'hace ' . floor($diff / 3600) . 'h';
    return 'hace ' . floor($diff / 86400) . 'd';
}

if (empty($_SESSION['csrf']))
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
?>
<!doctype html>
<html lang="es" data-theme="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Reporte de equipo — F&amp;C Planner</title>
    <link rel="stylesheet" href="../assets/app.css">
    <link rel="stylesheet" href="../assets/theme.css">
    <script>(function(){
        var t = localStorage.getItem('fyc-theme') || 'dark';
        document.documentElement.setAttribute('data-theme', t);
    })();</script>
    <style>
        body { background: var(--bg-app); }
        .rpt-content { max-width: 1100px; margin: 0 auto; padding: 28px 20px; }
        .rpt-title { font-family:'Sora',sans-serif; font-size:22px; font-weight:800; color:var(--text-primary); letter-spacing:-0.4px; margin:0 0 4px; }
        .rpt-sub { font-size:13px; color:var(--text-ghost); margin:0 0 24px; }
        .admin-stats { display:flex; gap:12px; margin-bottom:24px; flex-wrap:wrap; }
        .admin-stat { flex:1; min-width:140px; background:var(--bg-surface); border:1px solid var(--border-accent); border-radius:14px; padding:14px 18px; }
        .admin-stat-num { font-family:'Sora',sans-serif; font-size:28px; font-weight:800; color:var(--fyc-red); line-height:1; margin-bottom:4px; }
        .admin-stat-label { font-size:11px; font-weight:600; color:var(--text-ghost); text-transform:uppercase; letter-spacing:0.8px; }
        .rpt-card { background:var(--bg-surface); border:1px solid var(--border-accent); border-radius:16px; overflow:hidden; margin-bottom:14px; }
        .rpt-card-header { padding:14px 18px; border-bottom:1px solid var(--border-main); display:flex; justify-content:space-between; align-items:center; gap:10px; flex-wrap:wrap; }
        .rpt-card-title { font-family:'Sora',sans-serif; font-size:15px; font-weight:700; color:var(--text-primary); }
        .rpt-table { width:100%; border-collapse:collapse; font-size:13px; }
        .rpt-table th { text-align:left; padding:10px 12px; font-size:11px; font-weight:700; color:var(--text-ghost); text-transform:uppercase; letter-spacing:0.8px; border-bottom:1px solid var(--border-main); white-space:nowrap; }
        .rpt-table td { padding:11px 12px; border-bottom:1px solid var(--border-main); vertical-align:middle; color:var(--text-secondary); }
        .rpt-table tr:last-child td { border-bottom:none; }
        .rpt-table tr:hover td { background:var(--bg-hover); }
    </style>
</head>
<body>

<!-- Header -->
<header class="fyc-header">
    <div style="display:flex;align-items:center;gap:14px;">
        <a href="../boards/workspace.php" class="fyc-logo">F&amp;C <span>Planner</span></a>
        <div style="width:1px;height:18px;background:var(--border-main);"></div>
        <span style="font-size:12px;color:var(--text-ghost);font-family:'Sora',sans-serif;">Reporte de equipo</span>
    </div>
    <div style="display:flex;align-items:center;gap:10px;">
        <a href="../boards/workspace.php" class="fyc-btn fyc-btn-ghost" style="text-decoration:none;font-size:12px;">← Workspace</a>
        <button id="themeToggle" title="Cambiar tema">
            <span id="themeIcon">🌙</span>
            <span id="themeLabel">Oscuro</span>
        </button>
        <div class="fyc-avatar"><?= strtoupper(mb_substr($_SESSION['nombre'] ?? 'A', 0, 2)) ?></div>
    </div>
</header>

<main class="rpt-content">

    <div style="display:flex;align-items:baseline;justify-content:space-between;gap:16px;flex-wrap:wrap;margin-bottom:4px;">
        <h1 class="rpt-title" style="margin:0;">Reporte: <?= h($teamLabel) ?></h1>
        <a href="export_excel_team.php" class="fyc-btn fyc-btn-ghost" style="text-decoration:none;font-size:12px;flex-shrink:0;">
            ↓ Exportar Excel
        </a>
    </div>
    <p class="rpt-sub">Carga, riesgo y actividad de tu equipo. Actualizado en tiempo real.</p>

    <!-- KPIs -->
    <div class="admin-stats">
        <div class="admin-stat">
            <div class="admin-stat-num"><?= $totalTasks ?></div>
            <div class="admin-stat-label">Tareas activas</div>
        </div>
        <div class="admin-stat">
            <div class="admin-stat-num" style="color:<?= $overdueTasks > 0 ? 'var(--badge-overdue-tx)' : 'var(--badge-ok-tx)' ?>;"><?= $overdueTasks ?></div>
            <div class="admin-stat-label">Vencidas</div>
            <?php if ($totalTasks > 0): ?>
                <div style="font-size:11px;margin-top:4px;color:var(--text-ghost);"><?= round($overdueRatio * 100) ?>% del total</div>
            <?php endif; ?>
        </div>
        <div class="admin-stat">
            <div class="admin-stat-num" style="color:<?= $unassigned > 0 ? 'var(--badge-soon-tx)' : 'var(--badge-ok-tx)' ?>;"><?= $unassigned ?></div>
            <div class="admin-stat-label">Sin responsable</div>
        </div>
        <div class="admin-stat">
            <div class="admin-stat-num" style="color:<?= $staleRatio >= 0.3 ? 'var(--badge-soon-tx)' : 'var(--text-primary)' ?>;"><?= $stale5 ?></div>
            <div class="admin-stat-label">Sin movimiento +5d</div>
            <?php if ($staleRatio >= 0.3): ?>
                <div style="font-size:10px;font-weight:700;margin-top:5px;color:var(--badge-soon-tx);text-transform:uppercase;letter-spacing:0.5px;">▲ <?= round($staleRatio * 100) ?>% del total</div>
            <?php endif; ?>
        </div>
        <div class="admin-stat">
            <div class="admin-stat-num" style="color:var(--text-primary);"><?= $commentsWeek ?></div>
            <div class="admin-stat-label">Comentarios (7d)</div>
        </div>
    </div>

    <!-- Prioridades + Actividad -->
    <div style="display:grid;grid-template-columns:1fr 1.6fr;gap:14px;margin-bottom:14px;">

        <div class="rpt-card" style="margin-bottom:0;">
            <div class="rpt-card-header"><span class="rpt-card-title">Distribución por prioridad</span></div>
            <div style="padding:16px;display:flex;flex-direction:column;gap:10px;">
                <?php
                $prioConf = [
                    'urgent' => ['Urgente', 'var(--badge-urgent-bg)', 'var(--badge-urgent-tx)'],
                    'high'   => ['Alta',    'var(--badge-high-bg)',   'var(--badge-high-tx)'],
                    'med'    => ['Media',   'var(--badge-med-bg)',    'var(--badge-med-tx)'],
                    'low'    => ['Baja',    'var(--badge-low-bg)',    'var(--badge-low-tx)'],
                ];
                foreach ($prioConf as $key => [$lbl, $bg, $tx]):
                    $n   = $byPriority[$key] ?? 0;
                    $pct = $totalTasks > 0 ? round($n / $totalTasks * 100) : 0;
                ?>
                <div style="display:flex;align-items:center;gap:10px;">
                    <span style="width:68px;font-size:11px;font-weight:700;padding:2px 8px;border-radius:999px;background:<?= $bg ?>;color:<?= $tx ?>;text-align:center;flex-shrink:0;"><?= $lbl ?></span>
                    <div style="flex:1;height:8px;border-radius:99px;background:var(--bg-hover);overflow:hidden;">
                        <div style="height:100%;width:<?= $pct ?>%;background:<?= $tx ?>;border-radius:99px;"></div>
                    </div>
                    <span style="width:28px;text-align:right;font-size:13px;font-weight:700;color:var(--text-secondary);flex-shrink:0;"><?= $n ?></span>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="rpt-card" style="margin-bottom:0;">
            <div class="rpt-card-header">
                <span class="rpt-card-title">Actividad reciente</span>
                <span style="font-size:11px;color:var(--text-ghost);">Comentarios — últimas 48h</span>
            </div>
            <?php if (empty($activityRows)): ?>
                <div style="padding:28px;text-align:center;font-size:12px;color:var(--text-ghost);">Sin actividad en las últimas 48 horas.</div>
            <?php else: ?>
                <div style="overflow:auto;max-height:220px;">
                    <table class="rpt-table">
                        <thead><tr><th>Persona</th><th>Tarea</th><th>Tablero</th><th>Cuándo</th></tr></thead>
                        <tbody>
                            <?php foreach ($activityRows as $a): ?>
                                <tr>
                                    <td style="font-weight:700;color:var(--text-primary);white-space:nowrap;"><?= h($a['actor']) ?></td>
                                    <td style="max-width:180px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="<?= h($a['tarea']) ?>"><?= h($a['tarea']) ?></td>
                                    <td style="font-size:12px;color:var(--text-muted);white-space:nowrap;"><?= h($a['tablero']) ?></td>
                                    <td style="font-size:11px;color:var(--text-ghost);white-space:nowrap;"><?= relTime($a['created_at']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

    </div>

    <!-- Salud por tablero dentro del equipo -->
    <?php if (count($teamStats) > 1): ?>
    <div class="rpt-card">
        <div class="rpt-card-header">
            <span class="rpt-card-title">Salud por equipo</span>
            <span style="font-size:11px;color:var(--text-ghost);">
                <span style="display:inline-flex;align-items:center;gap:4px;margin-right:10px;"><span style="width:8px;height:8px;border-radius:50%;background:var(--badge-ok-tx);display:inline-block;"></span>0 vencidas</span>
                <span style="display:inline-flex;align-items:center;gap:4px;margin-right:10px;"><span style="width:8px;height:8px;border-radius:50%;background:var(--badge-soon-tx);display:inline-block;"></span>≤20%</span>
                <span style="display:inline-flex;align-items:center;gap:4px;"><span style="width:8px;height:8px;border-radius:50%;background:var(--badge-overdue-tx);display:inline-block;"></span>&gt;20%</span>
            </span>
        </div>
        <table class="rpt-table">
            <thead><tr><th>Equipo</th><th style="text-align:center;">Tableros</th><th style="text-align:center;">Tareas</th><th style="text-align:center;">Vencidas</th><th style="text-align:center;">Sin resp.</th><th>Última mod.</th></tr></thead>
            <tbody>
                <?php foreach ($teamStats as $row): ?>
                    <tr>
                        <td style="font-weight:700;color:var(--text-primary);"><?= h($row['equipo']) ?></td>
                        <td style="text-align:center;color:var(--text-muted);"><?= (int)$row['tableros'] ?></td>
                        <td style="text-align:center;font-weight:600;"><?= (int)$row['tareas'] ?></td>
                        <td style="text-align:center;"><?= riskBadge((int)$row['vencidas'], (int)$row['tareas']) ?></td>
                        <td style="text-align:center;font-size:12px;color:var(--text-ghost);"><?= (int)$row['sin_resp'] ?: '—' ?></td>
                        <td style="font-size:12px;color:var(--text-ghost);"><?= relTime((string)($row['ultima_mod'] ?? '')) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>

    <!-- Carga por responsable -->
    <div class="rpt-card">
        <div class="rpt-card-header">
            <span class="rpt-card-title">Carga por responsable</span>
            <span style="font-size:11px;color:var(--text-ghost);">Solo miembros con tareas asignadas · ordenado por vencidas</span>
        </div>
        <?php if (empty($personRows)): ?>
            <div style="padding:24px;text-align:center;color:var(--text-ghost);font-size:13px;">Ninguna tarea tiene responsable asignado.</div>
        <?php else: ?>
            <table class="rpt-table">
                <thead><tr><th>Persona</th><th style="text-align:center;">Asignadas</th><th style="text-align:center;">Vencidas</th><th style="text-align:center;">Alta / Urgente</th><th>Última actividad</th></tr></thead>
                <tbody>
                    <?php foreach ($personRows as $p): ?>
                        <tr>
                            <td style="font-weight:700;color:var(--text-primary);"><?= h($p['nombre']) ?></td>
                            <td style="text-align:center;font-weight:600;"><?= (int)$p['asignadas'] ?></td>
                            <td style="text-align:center;"><?= riskBadge((int)$p['vencidas'], (int)$p['asignadas']) ?></td>
                            <td style="text-align:center;">
                                <?php $ap = (int)$p['alta_prio']; ?>
                                <?php if ($ap > 0): ?>
                                    <span style="font-size:12px;font-weight:700;padding:2px 8px;border-radius:999px;background:var(--badge-high-bg);color:var(--badge-high-tx);"><?= $ap ?></span>
                                <?php else: ?>
                                    <span style="font-size:12px;color:var(--text-ghost);">—</span>
                                <?php endif; ?>
                            </td>
                            <td style="font-size:12px;color:var(--text-ghost);"><?= relTime((string)($p['ultima_actividad'] ?? '')) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>

</main>

<script>
(function(){
    var btn = document.getElementById('themeToggle');
    var icon = document.getElementById('themeIcon');
    var lbl  = document.getElementById('themeLabel');
    function apply(t){
        document.documentElement.setAttribute('data-theme', t);
        localStorage.setItem('fyc-theme', t);
        if(icon) icon.textContent  = t === 'dark' ? '🌙' : '☀️';
        if(lbl)  lbl.textContent   = t === 'dark' ? 'Oscuro' : 'Claro';
    }
    apply(localStorage.getItem('fyc-theme') || 'dark');
    if(btn) btn.addEventListener('click', function(){
        apply(document.documentElement.getAttribute('data-theme') === 'dark' ? 'light' : 'dark');
    });
})();
</script>
</body>
</html>
