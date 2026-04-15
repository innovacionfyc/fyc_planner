<?php
// public/admin/stats.php — Dashboard de estadísticas global
require_once __DIR__ . '/../_auth.php';
require_admin();
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../_perm.php';

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

function kpi(mysqli $conn, string $sql): int {
    $r = $conn->query($sql);
    return $r ? (int)($r->fetch_row()[0] ?? 0) : 0;
}

// ============================================================
// KPIs globales
// ============================================================
$totalTasks   = kpi($conn, "SELECT COUNT(*) FROM tasks WHERE completed_at IS NULL");
$overdueTasks = kpi($conn, "SELECT COUNT(*) FROM tasks WHERE completed_at IS NULL AND fecha_limite IS NOT NULL AND fecha_limite < NOW()");
$unassigned   = kpi($conn, "SELECT COUNT(*) FROM tasks WHERE completed_at IS NULL AND assignee_id IS NULL");
$stale5       = kpi($conn, "SELECT COUNT(*) FROM tasks WHERE completed_at IS NULL AND COALESCE(updated_at, creado_en) < DATE_SUB(NOW(), INTERVAL 5 DAY)");
$commentsWeek = kpi($conn, "SELECT COUNT(*) FROM comments WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)");

$overdueRatio = $totalTasks > 0 ? ($overdueTasks / $totalTasks) : 0;
$staleRatio   = $totalTasks > 0 ? ($stale5 / $totalTasks) : 0;

// ============================================================
// Distribución por prioridad
// ============================================================
$byPriority = ['urgent' => 0, 'high' => 0, 'med' => 0, 'low' => 0];
$qp = $conn->query("SELECT prioridad, COUNT(*) AS n FROM tasks WHERE completed_at IS NULL GROUP BY prioridad");
if ($qp) while ($r = $qp->fetch_assoc()) $byPriority[$r['prioridad']] = (int)$r['n'];

// ============================================================
// Salud por equipo
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
    LEFT JOIN tasks tk  ON tk.board_id = b.id AND tk.completed_at IS NULL
    GROUP BY t.id, t.nombre
    ORDER BY vencidas DESC, tareas DESC
");
$teamStats = $teamRows ? $teamRows->fetch_all(MYSQLI_ASSOC) : [];

// Fila de tableros personales (sin equipo)
$personalRow = $conn->query("
    SELECT
        COUNT(DISTINCT b.id)                                                           AS tableros,
        COUNT(tk.id)                                                                   AS tareas,
        COALESCE(SUM(tk.fecha_limite IS NOT NULL AND tk.fecha_limite < NOW()), 0)      AS vencidas,
        COALESCE(SUM(tk.assignee_id IS NULL), 0)                                       AS sin_resp,
        MAX(COALESCE(tk.updated_at, tk.creado_en))                                     AS ultima_mod
    FROM boards b
    LEFT JOIN tasks tk ON tk.board_id = b.id AND tk.completed_at IS NULL
    WHERE b.team_id IS NULL
")->fetch_assoc();

// ============================================================
// Carga por responsable
// ============================================================
$personStats = $conn->query("
    SELECT
        u.nombre,
        COUNT(tk.id)                                                                   AS asignadas,
        COALESCE(SUM(tk.fecha_limite IS NOT NULL AND tk.fecha_limite < NOW()), 0)      AS vencidas,
        COALESCE(SUM(tk.prioridad IN ('high', 'urgent')), 0)                           AS alta_prio,
        MAX(COALESCE(tk.updated_at, tk.creado_en))                                     AS ultima_actividad
    FROM users u
    JOIN tasks tk ON tk.assignee_id = u.id AND tk.completed_at IS NULL
    GROUP BY u.id, u.nombre
    ORDER BY vencidas DESC, asignadas DESC
    LIMIT 25
");
$personRows = $personStats ? $personStats->fetch_all(MYSQLI_ASSOC) : [];

// ============================================================
// Actividad reciente — comentarios 48h
// ============================================================
$recentComments = $conn->query("
    SELECT c.created_at, u.nombre AS actor, b.nombre AS tablero, tk.titulo AS tarea
    FROM comments c
    JOIN users  u  ON u.id  = c.user_id
    JOIN boards b  ON b.id  = c.board_id
    JOIN tasks  tk ON tk.id = c.task_id
    WHERE c.created_at >= DATE_SUB(NOW(), INTERVAL 48 HOUR)
    ORDER BY c.created_at DESC
    LIMIT 25
");
$activityRows = $recentComments ? $recentComments->fetch_all(MYSQLI_ASSOC) : [];

// ============================================================
// Helpers de presentación
// ============================================================
function riskBadge(int $vencidas, int $tareas): string {
    $dot  = '<span style="width:7px;height:7px;border-radius:50%;background:currentColor;display:inline-block;flex-shrink:0;"></span>';
    $wrap = 'display:inline-flex;align-items:center;gap:5px;font-size:12px;font-weight:700;padding:3px 10px;border-radius:999px;';
    if ($vencidas === 0) {
        return '<span style="' . $wrap . 'background:var(--badge-ok-bg);color:var(--badge-ok-tx);">' . $dot . '0</span>';
    }
    $pct = $tareas > 0 ? ($vencidas / $tareas * 100) : 100;
    if ($pct > 20) {
        return '<span style="' . $wrap . 'background:var(--badge-overdue-bg);color:var(--badge-overdue-tx);">' . $dot . $vencidas . '</span>';
    }
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

// ============================================================
// Pre-cómputo visual (sin queries nuevas)
// ============================================================

// Clasificar equipos por nivel de riesgo
$teamsAtRisk = array_filter($teamStats, function ($r) {
    return (int)$r['tareas'] > 0 && ((int)$r['vencidas'] / (int)$r['tareas'] * 100) > 20;
});
$teamsAtWarn = array_filter($teamStats, function ($r) {
    $v = (int)$r['vencidas']; $t = (int)$r['tareas'];
    return $t > 0 && $v > 0 && ($v / $t * 100) <= 20;
});
$riskCount = count($teamsAtRisk);
$warnCount = count($teamsAtWarn);

// Nivel del banner global
if ($riskCount > 0 || $overdueRatio > 0.20) {
    $bannerLevel = 'red';
} elseif ($warnCount > 0 || ($totalTasks > 0 && ($unassigned / $totalTasks) > 0.20) || $staleRatio >= 0.30) {
    $bannerLevel = 'yellow';
} else {
    $bannerLevel = 'green';
}

// Mensaje del banner
if ($bannerLevel === 'red') {
    $bannerIcon  = '⚠';
    $bannerTitle = 'Atención';
    $bannerMsg   = $riskCount > 0
        ? $riskCount . ' equipo' . ($riskCount > 1 ? 's' : '') . ' ' . ($riskCount > 1 ? 'superan' : 'supera') . ' el 20% de tareas vencidas.'
        : round($overdueRatio * 100) . '% de las tareas están vencidas a nivel global.';
} elseif ($bannerLevel === 'yellow') {
    $bannerIcon  = '▲';
    $bannerTitle = 'Precaución';
    $parts = [];
    if ($warnCount > 0)       $parts[] = $warnCount . ' equipo' . ($warnCount > 1 ? 's' : '') . ' con vencidas moderadas';
    if ($unassigned > 0)      $parts[] = $unassigned . ' tarea' . ($unassigned > 1 ? 's' : '') . ' sin responsable';
    if ($staleRatio >= 0.30)  $parts[] = $stale5 . ' tareas estancadas (' . round($staleRatio * 100) . '%)';
    $bannerMsg = implode(' · ', $parts) . '.';
} else {
    $bannerIcon  = '✓';
    $bannerTitle = 'Sistema en buen estado';
    $bannerMsg   = 'Sin alertas críticas activas. Los equipos están al día.';
}

// Escala máxima para barras de carga por responsable
$maxAsignadas = !empty($personRows) ? (int)max(array_column($personRows, 'asignadas')) : 1;

$pageTitle  = 'Estadísticas';
$activePage = 'estadisticas';
require_once __DIR__ . '/_layout_top.php';
?>

<style>
/* ---- Stats visual upgrade ---- */
.stat-card-enhanced { position:relative; display:flex; flex-direction:column; }
.stat-dot { width:8px; height:8px; border-radius:50%; flex-shrink:0; }
.stat-bar-track { height:5px; border-radius:99px; background:var(--bg-hover); overflow:hidden; margin-top:8px; }
.stat-bar-fill  { height:100%; border-radius:99px; transition:width .6s ease; }
.team-card {
    background:var(--bg-surface); border:1px solid var(--border-accent);
    border-radius:12px; padding:14px 16px;
    display:flex; flex-direction:column;
}
.team-card-bar { height:6px; border-radius:99px; background:var(--bg-hover); overflow:hidden; margin:8px 0 6px; }
.team-card-bar-fill { height:100%; border-radius:99px; transition:width .5s ease; }
.workload-row { display:flex; align-items:center; gap:12px; padding:10px 18px; border-bottom:1px solid var(--border-main); }
.workload-row:last-child { border-bottom:none; }
.wl-track { flex:1; height:14px; border-radius:99px; background:var(--bg-hover); overflow:hidden; position:relative; }
details > summary { list-style:none; cursor:pointer; }
details > summary::-webkit-details-marker { display:none; }
.detail-chevron { display:inline-block; transition:transform .2s ease; }
details[open] .detail-chevron { transform:rotate(180deg); }
</style>

<!-- Encabezado + acciones -->
<div style="display:flex;align-items:center;justify-content:space-between;gap:16px;flex-wrap:wrap;margin-bottom:4px;">
    <h1 class="admin-page-title" style="margin:0;">Estadísticas del sistema</h1>
    <div style="display:flex;align-items:center;gap:10px;flex-shrink:0;">
        <span id="alertsFeedback" style="font-size:12px;color:var(--text-ghost);"></span>
        <button id="runAlertsBtn" class="fyc-btn fyc-btn-ghost" style="font-size:12px;"
                data-csrf="<?= h($_SESSION['csrf']) ?>">▲ Generar alertas</button>
        <a href="export_excel.php" class="fyc-btn fyc-btn-ghost"
           style="text-decoration:none;font-size:12px;">↓ Exportar Excel</a>
    </div>
</div>
<p class="admin-page-sub">Vista global de carga, riesgo y actividad. Actualizada en tiempo real.</p>

<!-- ============================================================
     Banner de salud global
     ============================================================ -->
<?php
$bannerColors = [
    'red'    => ['var(--badge-overdue-bg)', 'var(--badge-overdue-tx)'],
    'yellow' => ['var(--badge-soon-bg)',    'var(--badge-soon-tx)'],
    'green'  => ['var(--badge-ok-bg)',      'var(--badge-ok-tx)'],
];
[$bBg, $bTx] = $bannerColors[$bannerLevel];
?>
<div style="background:<?= $bBg ?>;border:1px solid <?= $bTx ?>;border-radius:12px;
            padding:12px 18px;margin-bottom:18px;display:flex;align-items:center;gap:12px;">
    <span style="font-size:18px;line-height:1;flex-shrink:0;"><?= $bannerIcon ?></span>
    <div>
        <span style="font-size:13px;font-weight:700;color:<?= $bTx ?>;"><?= $bannerTitle ?>.</span>
        <span style="font-size:13px;color:<?= $bTx ?>;opacity:.85;margin-left:6px;"><?= h($bannerMsg) ?></span>
    </div>
</div>

<!-- ============================================================
     KPIs — 5 cards con dot de estado y barra proporcional
     ============================================================ -->
<?php
$unassignedRatio = $totalTasks > 0 ? $unassigned / $totalTasks : 0;
$kpiCards = [
    [
        'label'    => 'TAREAS ACTIVAS',
        'value'    => $totalTasks,
        'ratio'    => null,
        'dotColor' => 'var(--text-ghost)',
        'numColor' => 'var(--text-primary)',
        'barColor' => 'var(--text-ghost)',
        'bg'       => '',
    ],
    [
        'label'    => 'VENCIDAS',
        'value'    => $overdueTasks,
        'ratio'    => $overdueRatio,
        'dotColor' => $overdueTasks > 0 ? 'var(--badge-overdue-tx)' : 'var(--badge-ok-tx)',
        'numColor' => $overdueTasks > 0 ? 'var(--badge-overdue-tx)' : 'var(--badge-ok-tx)',
        'barColor' => $overdueTasks > 0 ? 'var(--badge-overdue-tx)' : 'var(--badge-ok-tx)',
        'bg'       => $overdueTasks > 0 ? 'background:var(--badge-overdue-bg);border-color:var(--badge-overdue-tx);' : '',
    ],
    [
        'label'    => 'SIN RESPONSABLE',
        'value'    => $unassigned,
        'ratio'    => $unassignedRatio,
        'dotColor' => $unassigned > 0 ? 'var(--badge-soon-tx)' : 'var(--badge-ok-tx)',
        'numColor' => $unassigned > 0 ? 'var(--badge-soon-tx)' : 'var(--badge-ok-tx)',
        'barColor' => $unassigned > 0 ? 'var(--badge-soon-tx)' : 'var(--badge-ok-tx)',
        'bg'       => $unassigned > 0 ? 'background:var(--badge-soon-bg);border-color:var(--badge-soon-tx);' : '',
    ],
    [
        'label'    => 'SIN MOVIMIENTO +5D',
        'value'    => $stale5,
        'ratio'    => $staleRatio,
        'dotColor' => $staleRatio >= 0.3 ? 'var(--badge-soon-tx)' : 'var(--text-ghost)',
        'numColor' => $staleRatio >= 0.3 ? 'var(--badge-soon-tx)' : 'var(--text-primary)',
        'barColor' => $staleRatio >= 0.3 ? 'var(--badge-soon-tx)' : 'var(--border-accent)',
        'bg'       => $staleRatio >= 0.3 ? 'background:var(--badge-soon-bg);border-color:var(--badge-soon-tx);' : '',
    ],
    [
        'label'    => 'COMENTARIOS (7D)',
        'value'    => $commentsWeek,
        'ratio'    => null,
        'dotColor' => 'var(--text-ghost)',
        'numColor' => 'var(--text-primary)',
        'barColor' => 'var(--text-ghost)',
        'bg'       => '',
    ],
];
?>
<div class="admin-stats" style="flex-wrap:wrap;margin-bottom:18px;">
    <?php foreach ($kpiCards as $card):
        $pct = ($card['ratio'] !== null && $totalTasks > 0) ? round($card['ratio'] * 100) : null;
    ?>
    <div class="admin-stat stat-card-enhanced" style="<?= $card['bg'] ?>">
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:8px;">
            <span style="font-size:10px;font-weight:700;color:var(--text-ghost);letter-spacing:.7px;"><?= $card['label'] ?></span>
            <span class="stat-dot" style="background:<?= $card['dotColor'] ?>;"></span>
        </div>
        <div class="admin-stat-num" style="color:<?= $card['numColor'] ?>;margin-bottom:0;"><?= $card['value'] ?></div>
        <?php if ($pct !== null): ?>
            <div class="stat-bar-track">
                <div class="stat-bar-fill" style="width:<?= $pct ?>%;background:<?= $card['barColor'] ?>;"></div>
            </div>
            <div style="font-size:10px;color:var(--text-ghost);margin-top:5px;"><?= $pct ?>% del total</div>
        <?php endif; ?>
    </div>
    <?php endforeach; ?>
</div>

<!-- ============================================================
     Fila 2: Salud por equipo | Prioridades + Actividad
     ============================================================ -->
<div style="display:grid;grid-template-columns:1.4fr 1fr;gap:14px;margin-bottom:14px;align-items:start;">

    <!-- Columna izquierda: cards de equipo + tabla colapsable -->
    <div class="admin-card" style="margin-bottom:0;">
        <div class="admin-card-header">
            <span class="admin-card-title">Salud por equipo</span>
            <span style="font-size:11px;color:var(--text-ghost);">
                <span style="display:inline-flex;align-items:center;gap:4px;margin-right:8px;">
                    <span style="width:7px;height:7px;border-radius:50%;background:var(--badge-ok-tx);display:inline-block;"></span>OK
                </span>
                <span style="display:inline-flex;align-items:center;gap:4px;margin-right:8px;">
                    <span style="width:7px;height:7px;border-radius:50%;background:var(--badge-soon-tx);display:inline-block;"></span>Alerta
                </span>
                <span style="display:inline-flex;align-items:center;gap:4px;">
                    <span style="width:7px;height:7px;border-radius:50%;background:var(--badge-overdue-tx);display:inline-block;"></span>Riesgo
                </span>
            </span>
        </div>
        <div style="padding:14px 16px;">

        <?php if (empty($teamStats)): ?>
            <p style="text-align:center;color:var(--text-ghost);font-style:italic;font-size:13px;margin:16px 0;">
                No hay equipos con datos todavía.
            </p>
        <?php else: ?>

            <!-- Cards grid — vista ejecutiva -->
            <?php
            $allTeamCards = $teamStats;
            if (!empty($personalRow) && (int)$personalRow['tableros'] > 0) {
                $allTeamCards[] = array_merge($personalRow, ['equipo' => 'Tableros personales']);
            }
            ?>
            <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(175px,1fr));gap:10px;">
            <?php foreach ($allTeamCards as $row):
                $v   = (int)$row['vencidas'];
                $t   = (int)$row['tareas'];
                $pct = $t > 0 ? round($v / $t * 100) : 0;
                $sr  = (int)$row['sin_resp'];

                if ($v === 0) {
                    $borderColor = 'var(--badge-ok-tx)';
                    $levelLabel  = 'OK';
                    $numColor    = 'var(--badge-ok-tx)';
                } elseif ($pct > 20) {
                    $borderColor = 'var(--badge-overdue-tx)';
                    $levelLabel  = 'RIESGO';
                    $numColor    = 'var(--badge-overdue-tx)';
                } else {
                    $borderColor = 'var(--badge-soon-tx)';
                    $levelLabel  = 'ALERTA';
                    $numColor    = 'var(--badge-soon-tx)';
                }
            ?>
                <div class="team-card" style="border-left:4px solid <?= $borderColor ?>;">
                    <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:6px;margin-bottom:6px;">
                        <span style="font-family:'Sora',sans-serif;font-size:12px;font-weight:700;
                                     color:var(--text-primary);overflow:hidden;text-overflow:ellipsis;
                                     white-space:nowrap;flex:1;" title="<?= h($row['equipo']) ?>"><?= h($row['equipo']) ?></span>
                        <span style="font-size:10px;font-weight:700;color:<?= $borderColor ?>;
                                     letter-spacing:.5px;flex-shrink:0;"><?= $levelLabel ?></span>
                    </div>

                    <div style="font-family:'Sora',sans-serif;font-size:26px;font-weight:800;
                                color:<?= $numColor ?>;line-height:1;margin-bottom:1px;"><?= $v ?></div>
                    <div style="font-size:11px;color:var(--text-ghost);">
                        de <?= $t ?> tareas &middot; <?= $pct ?>%
                    </div>

                    <div class="team-card-bar">
                        <div class="team-card-bar-fill" style="width:<?= $pct ?>%;background:<?= $borderColor ?>;"></div>
                    </div>

                    <div style="display:flex;justify-content:space-between;font-size:11px;color:var(--text-ghost);">
                        <span><?= $sr > 0 ? $sr . ' sin resp.' : '—' ?></span>
                        <span><?= relTime((string)($row['ultima_mod'] ?? '')) ?></span>
                    </div>
                </div>
            <?php endforeach; ?>
            </div>

            <!-- Tabla colapsable — vista técnica -->
            <details style="margin-top:14px;">
                <summary style="display:inline-flex;align-items:center;gap:6px;font-size:12px;
                                color:var(--text-ghost);padding:4px 0;user-select:none;">
                    <span class="detail-chevron">▼</span> Ver detalle en tabla
                </summary>
                <div style="margin-top:10px;overflow-x:auto;">
                    <table class="admin-table">
                        <thead>
                            <tr>
                                <th>Equipo</th>
                                <th style="text-align:center;">Tableros</th>
                                <th style="text-align:center;">Tareas</th>
                                <th style="text-align:center;">Vencidas</th>
                                <th style="text-align:center;">Sin resp.</th>
                                <th>Última modificación</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($teamStats as $row): ?>
                                <tr>
                                    <td style="font-weight:700;color:var(--text-primary);"><?= h($row['equipo']) ?></td>
                                    <td style="text-align:center;color:var(--text-muted);"><?= (int)$row['tableros'] ?></td>
                                    <td style="text-align:center;font-weight:600;"><?= (int)$row['tareas'] ?></td>
                                    <td style="text-align:center;"><?= riskBadge((int)$row['vencidas'], (int)$row['tareas']) ?></td>
                                    <td style="text-align:center;">
                                        <?php $sr = (int)$row['sin_resp']; $tot = (int)$row['tareas']; ?>
                                        <?php if ($sr === 0): ?>
                                            <span style="font-size:12px;color:var(--text-ghost);">—</span>
                                        <?php else: ?>
                                            <span style="font-size:12px;font-weight:700;color:<?= ($tot > 0 && $sr / $tot > 0.3) ? 'var(--badge-soon-tx)' : 'var(--text-muted)' ?>;"><?= $sr ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td style="font-size:12px;color:var(--text-ghost);"><?= relTime((string)($row['ultima_mod'] ?? '')) ?></td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if (!empty($personalRow) && (int)$personalRow['tableros'] > 0): ?>
                                <tr>
                                    <td style="font-style:italic;color:var(--text-muted);">Tableros personales</td>
                                    <td style="text-align:center;color:var(--text-muted);"><?= (int)$personalRow['tableros'] ?></td>
                                    <td style="text-align:center;font-weight:600;"><?= (int)$personalRow['tareas'] ?></td>
                                    <td style="text-align:center;"><?= riskBadge((int)$personalRow['vencidas'], (int)$personalRow['tareas']) ?></td>
                                    <td style="text-align:center;">
                                        <?php $sr = (int)$personalRow['sin_resp']; ?>
                                        <span style="font-size:12px;color:var(--text-ghost);"><?= $sr > 0 ? $sr : '—' ?></span>
                                    </td>
                                    <td style="font-size:12px;color:var(--text-ghost);"><?= relTime((string)($personalRow['ultima_mod'] ?? '')) ?></td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </details>

        <?php endif; ?>
        </div>
    </div><!-- /salud por equipo -->

    <!-- Columna derecha: Prioridades + Actividad apiladas -->
    <div style="display:flex;flex-direction:column;gap:14px;">

        <!-- Distribución por prioridad -->
        <div class="admin-card" style="margin-bottom:0;">
            <div class="admin-card-header">
                <span class="admin-card-title">Distribución por prioridad</span>
            </div>
            <div style="padding:16px;display:flex;flex-direction:column;gap:12px;">
                <?php
                $prioConf = [
                    'urgent' => ['Urgente', 'var(--badge-urgent-bg)', 'var(--badge-urgent-tx)'],
                    'high'   => ['Alta',    'var(--badge-high-bg)',   'var(--badge-high-tx)'],
                    'med'    => ['Media',   'var(--badge-med-bg)',    'var(--badge-med-tx)'],
                    'low'    => ['Baja',    'var(--badge-low-bg)',    'var(--badge-low-tx)'],
                ];
                foreach ($prioConf as $key => [$label, $bg, $tx]):
                    $n   = $byPriority[$key] ?? 0;
                    $pct = $totalTasks > 0 ? round($n / $totalTasks * 100) : 0;
                ?>
                <div>
                    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:5px;">
                        <span style="font-size:11px;font-weight:700;padding:2px 9px;border-radius:999px;
                                     background:<?= $bg ?>;color:<?= $tx ?>;"><?= $label ?></span>
                        <span style="font-size:12px;font-weight:700;color:var(--text-secondary);">
                            <?= $n ?> <span style="font-weight:400;color:var(--text-ghost);">(<?= $pct ?>%)</span>
                        </span>
                    </div>
                    <div style="height:10px;border-radius:99px;background:var(--bg-hover);overflow:hidden;">
                        <div style="height:100%;width:<?= $pct ?>%;background:<?= $tx ?>;border-radius:99px;transition:width .4s ease;"></div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Actividad reciente -->
        <div class="admin-card" style="margin-bottom:0;">
            <div class="admin-card-header">
                <span class="admin-card-title">Actividad reciente</span>
                <span style="font-size:11px;color:var(--text-ghost);">últimas 48h</span>
            </div>
            <?php if (empty($activityRows)): ?>
                <div style="padding:24px;text-align:center;">
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none"
                         style="color:var(--text-ghost);opacity:.4;display:block;margin:0 auto 8px;">
                        <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"
                              stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/>
                    </svg>
                    <span style="font-size:12px;color:var(--text-ghost);">Sin actividad reciente.</span>
                </div>
            <?php else: ?>
                <div style="overflow:auto;max-height:210px;">
                    <table class="admin-table">
                        <thead>
                            <tr>
                                <th>Persona</th>
                                <th>Tarea</th>
                                <th>Cuándo</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($activityRows as $a): ?>
                                <tr>
                                    <td style="font-weight:700;color:var(--text-primary);white-space:nowrap;"><?= h($a['actor']) ?></td>
                                    <td style="max-width:160px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"
                                        title="<?= h($a['tarea']) . ' · ' . h($a['tablero']) ?>"><?= h($a['tarea']) ?></td>
                                    <td style="font-size:11px;color:var(--text-ghost);white-space:nowrap;"><?= relTime($a['created_at']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

    </div><!-- /columna derecha -->

</div><!-- /fila 2 -->

<!-- ============================================================
     Carga por responsable — barras segmentadas
     ============================================================ -->
<div class="admin-card">
    <div class="admin-card-header">
        <span class="admin-card-title">Carga por responsable</span>
        <div style="display:flex;align-items:center;gap:14px;">
            <span style="display:inline-flex;align-items:center;gap:5px;font-size:11px;color:var(--text-ghost);">
                <span style="width:10px;height:10px;border-radius:2px;background:var(--border-accent);display:inline-block;"></span>Asignadas
            </span>
            <span style="display:inline-flex;align-items:center;gap:5px;font-size:11px;color:var(--text-ghost);">
                <span style="width:10px;height:10px;border-radius:2px;background:var(--badge-overdue-tx);display:inline-block;opacity:.85;"></span>Vencidas (alto)
            </span>
            <span style="display:inline-flex;align-items:center;gap:5px;font-size:11px;color:var(--text-ghost);">
                <span style="width:10px;height:10px;border-radius:2px;background:var(--badge-soon-tx);display:inline-block;opacity:.85;"></span>Vencidas (mod.)
            </span>
        </div>
    </div>
    <?php if (empty($personRows)): ?>
        <div style="padding:24px;text-align:center;color:var(--text-ghost);font-size:13px;">
            Ninguna tarea tiene responsable asignado todavía.
        </div>
    <?php else: ?>
        <?php foreach ($personRows as $p):
            $a           = (int)$p['asignadas'];
            $v           = (int)$p['vencidas'];
            $ap          = (int)$p['alta_prio'];
            $barPct      = $maxAsignadas > 0 ? round($a / $maxAsignadas * 100) : 0;
            $vencPct     = $a > 0 ? round($v / $a * 100) : 0;
            $hasRisk     = $v > 0 && $vencPct > 20;
            $overdueColor = $hasRisk ? 'var(--badge-overdue-tx)' : 'var(--badge-soon-tx)';
        ?>
        <div class="workload-row">
            <!-- Nombre -->
            <div style="width:130px;font-size:13px;font-weight:700;color:var(--text-primary);
                        overflow:hidden;text-overflow:ellipsis;white-space:nowrap;flex-shrink:0;"
                 title="<?= h($p['nombre']) ?>"><?= h($p['nombre']) ?></div>

            <!-- Barra segmentada: base = total asignadas / overlay = vencidas -->
            <div class="wl-track">
                <div style="position:absolute;left:0;top:0;bottom:0;width:<?= $barPct ?>%;
                            background:var(--border-accent);border-radius:99px;overflow:hidden;">
                    <?php if ($v > 0): ?>
                    <div style="position:absolute;left:0;top:0;bottom:0;width:<?= $vencPct ?>%;
                                background:<?= $overdueColor ?>;opacity:.9;"></div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Números -->
            <div style="display:flex;align-items:center;gap:8px;flex-shrink:0;min-width:160px;">
                <span style="font-size:13px;font-weight:700;color:var(--text-secondary);"><?= $a ?></span>
                <span style="font-size:11px;color:var(--text-ghost);">asig.</span>
                <?php if ($v > 0): ?>
                    <span style="font-size:12px;font-weight:700;padding:1px 7px;border-radius:999px;
                                 background:<?= $hasRisk ? 'var(--badge-overdue-bg)' : 'var(--badge-soon-bg)' ?>;
                                 color:<?= $overdueColor ?>;"><?= $v ?> venc.</span>
                <?php else: ?>
                    <span style="font-size:11px;color:var(--badge-ok-tx);">✓ al día</span>
                <?php endif; ?>
                <?php if ($ap > 0): ?>
                    <span style="font-size:11px;font-weight:700;padding:1px 6px;border-radius:999px;
                                 background:var(--badge-high-bg);color:var(--badge-high-tx);"><?= $ap ?> alta</span>
                <?php endif; ?>
            </div>

            <!-- Última actividad -->
            <div style="width:70px;text-align:right;font-size:11px;color:var(--text-ghost);flex-shrink:0;">
                <?= relTime((string)($p['ultima_actividad'] ?? '')) ?>
            </div>
        </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<script>
(function () {
    var btn = document.getElementById('runAlertsBtn');
    var fb  = document.getElementById('alertsFeedback');
    if (!btn) return;

    btn.addEventListener('click', function () {
        btn.disabled    = true;
        btn.textContent = 'Procesando…';
        fb.textContent  = '';

        var fd = new FormData();
        fd.append('csrf', btn.dataset.csrf);

        fetch('run_alerts.php', { method: 'POST', body: fd })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                btn.disabled    = false;
                btn.textContent = '▲ Generar alertas';
                if (d.ok) {
                    if (d.inserted > 0) {
                        fb.textContent = '✓ ' + d.inserted + ' alerta(s) enviada(s)'
                            + (d.skipped > 0 ? ', ' + d.skipped + ' omitida(s).' : '.');
                        fb.style.color = 'var(--badge-ok-tx)';
                    } else {
                        fb.textContent = 'Sin nuevas alertas — todas omitidas por deduplicación.';
                        fb.style.color = 'var(--text-ghost)';
                    }
                } else {
                    fb.textContent = 'Error: ' + (d.error || 'desconocido');
                    fb.style.color = 'var(--badge-overdue-tx)';
                }
            })
            .catch(function () {
                btn.disabled    = false;
                btn.textContent = '▲ Generar alertas';
                fb.textContent  = 'Error de red. Intenta de nuevo.';
                fb.style.color  = 'var(--badge-overdue-tx)';
            });
    });
})();
</script>

<?php require_once __DIR__ . '/_layout_bottom.php'; ?>
