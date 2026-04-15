<?php
// public/admin/export_excel.php — Exportación XLS global (solo administradores)
require_once __DIR__ . '/../_auth.php';
require_admin();
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../reports/_export_helpers.php';

// ============================================================
// 1. Salud por equipo
// ============================================================
$teamRows = $conn->query("
    SELECT
        t.nombre                                                                         AS equipo,
        COUNT(DISTINCT b.id)                                                             AS tableros,
        COUNT(tk.id)                                                                     AS tareas,
        COALESCE(SUM(tk.fecha_limite IS NOT NULL AND tk.fecha_limite < NOW()), 0)        AS vencidas,
        COALESCE(SUM(tk.assignee_id IS NULL), 0)                                         AS sin_resp,
        MAX(COALESCE(tk.updated_at, tk.creado_en))                                       AS ultima_mod
    FROM teams t
    LEFT JOIN boards b  ON b.team_id = t.id
    LEFT JOIN tasks  tk ON tk.board_id = b.id AND tk.completed_at IS NULL
    GROUP BY t.id, t.nombre
    ORDER BY vencidas DESC, tareas DESC
");
$teamStats = $teamRows ? $teamRows->fetch_all(MYSQLI_ASSOC) : [];

$personalRow = $conn->query("
    SELECT
        COUNT(DISTINCT b.id)                                                             AS tableros,
        COUNT(tk.id)                                                                     AS tareas,
        COALESCE(SUM(tk.fecha_limite IS NOT NULL AND tk.fecha_limite < NOW()), 0)        AS vencidas,
        COALESCE(SUM(tk.assignee_id IS NULL), 0)                                         AS sin_resp,
        MAX(COALESCE(tk.updated_at, tk.creado_en))                                       AS ultima_mod
    FROM boards b
    LEFT JOIN tasks tk ON tk.board_id = b.id AND tk.completed_at IS NULL
    WHERE b.team_id IS NULL
")->fetch_assoc();

// ============================================================
// 2. Carga por responsable
// ============================================================
$personStats = $conn->query("
    SELECT
        u.nombre,
        COUNT(tk.id)                                                                     AS asignadas,
        COALESCE(SUM(tk.fecha_limite IS NOT NULL AND tk.fecha_limite < NOW()), 0)        AS vencidas,
        COALESCE(SUM(tk.prioridad IN ('high', 'urgent')), 0)                             AS alta_prio,
        MAX(COALESCE(tk.updated_at, tk.creado_en))                                       AS ultima_actividad
    FROM users u
    JOIN tasks tk ON tk.assignee_id = u.id AND tk.completed_at IS NULL
    GROUP BY u.id, u.nombre
    ORDER BY vencidas DESC, asignadas DESC
    LIMIT 25
");
$personRows = $personStats ? $personStats->fetch_all(MYSQLI_ASSOC) : [];

// ============================================================
// 3. Tareas vencidas — detalle individual
// ============================================================
$overdueQ = $conn->query("
    SELECT
        tk.titulo,
        b.nombre                              AS tablero,
        COALESCE(t.nombre, 'Personal')        AS equipo,
        COALESCE(u.nombre, 'Sin asignar')     AS responsable,
        tk.fecha_limite,
        tk.prioridad,
        DATEDIFF(NOW(), tk.fecha_limite)      AS dias_vencida
    FROM tasks tk
    JOIN  boards b ON b.id  = tk.board_id
    LEFT JOIN teams  t ON t.id  = b.team_id
    LEFT JOIN users  u ON u.id  = tk.assignee_id
    WHERE tk.fecha_limite IS NOT NULL AND tk.fecha_limite < NOW()
      AND tk.completed_at IS NULL
    ORDER BY tk.fecha_limite ASC
");
$overdueRows = $overdueQ ? $overdueQ->fetch_all(MYSQLI_ASSOC) : [];

// ============================================================
// Construir hoja 1 — Salud por equipo
// ============================================================
$prioLabel = ['urgent' => 'Urgente', 'high' => 'Alta', 'med' => 'Media', 'low' => 'Baja'];

$rows1 = [];
foreach ($teamStats as $r) {
    $v     = (int)$r['vencidas'];
    $t     = (int)$r['tareas'];
    $pct   = $t > 0 ? round($v / $t * 100) : 0;
    $style = $v > 0 && $pct > 20 ? 'R' : ($v > 0 ? 'W' : '');
    $rows1[] = [
        ['v' => $r['equipo'],             's' => $style],
        ['v' => (int)$r['tableros'],      's' => $style],
        ['v' => $t,                       's' => $style],
        ['v' => $v,                       's' => $style],
        ['v' => (int)$r['sin_resp'],      's' => $style],
        ['v' => $pct . '%',               's' => $style],
        ['v' => $r['ultima_mod'] ?? '—',  's' => $style],
    ];
}
if (!empty($personalRow) && (int)$personalRow['tableros'] > 0) {
    $v     = (int)$personalRow['vencidas'];
    $t     = (int)$personalRow['tareas'];
    $pct   = $t > 0 ? round($v / $t * 100) : 0;
    $style = $v > 0 && $pct > 20 ? 'R' : ($v > 0 ? 'W' : '');
    $rows1[] = [
        ['v' => 'Tableros personales',         's' => $style],
        ['v' => (int)$personalRow['tableros'], 's' => $style],
        ['v' => $t,                            's' => $style],
        ['v' => $v,                            's' => $style],
        ['v' => (int)$personalRow['sin_resp'], 's' => $style],
        ['v' => $pct . '%',                    's' => $style],
        ['v' => $personalRow['ultima_mod'] ?? '—', 's' => $style],
    ];
}

$sheet1 = xls_sheet(
    'Salud por equipo',
    ['Equipo', 'Tableros', 'Tareas', 'Vencidas', 'Sin responsable', '% Riesgo', 'Última modificación'],
    $rows1
);

// ============================================================
// Construir hoja 2 — Carga por responsable
// ============================================================
$rows2 = [];
foreach ($personRows as $p) {
    $v     = (int)$p['vencidas'];
    $a     = (int)$p['asignadas'];
    $pct   = $a > 0 ? $v / $a * 100 : 0;
    $style = $v > 0 && $pct > 20 ? 'R' : ($v > 0 ? 'W' : '');
    $rows2[] = [
        ['v' => $p['nombre'],                  's' => $style],
        ['v' => $a,                            's' => $style],
        ['v' => $v,                            's' => $style],
        ['v' => (int)$p['alta_prio'],          's' => $style],
        ['v' => $p['ultima_actividad'] ?? '—', 's' => $style],
    ];
}

$sheet2 = xls_sheet(
    'Carga por responsable',
    ['Persona', 'Asignadas', 'Vencidas', 'Alta / Urgente', 'Última actividad'],
    $rows2
);

// ============================================================
// Construir hoja 3 — Tareas vencidas (detalle)
// ============================================================
$rows3 = [];
foreach ($overdueRows as $o) {
    $dias  = (int)$o['dias_vencida'];
    $style = $dias > 7 ? 'R' : 'W';
    $rows3[] = [
        ['v' => $o['titulo'],                                    's' => $style],
        ['v' => $o['tablero'],                                   's' => $style],
        ['v' => $o['equipo'],                                    's' => $style],
        ['v' => $o['responsable'],                               's' => $style],
        ['v' => $o['fecha_limite'],                              's' => $style],
        ['v' => $prioLabel[$o['prioridad']] ?? $o['prioridad'],  's' => $style],
        ['v' => $dias,                                           's' => $style],
    ];
}

$sheet3 = xls_sheet(
    'Tareas vencidas',
    ['Tarea', 'Tablero', 'Equipo', 'Responsable', 'Fecha límite', 'Prioridad', 'Días vencida'],
    $rows3
);

// ============================================================
// Salida
// ============================================================
xls_send_headers('FYC_Reporte_Global_' . date('Y-m-d'));
echo xls_doc([$sheet1, $sheet2, $sheet3]);
exit;
