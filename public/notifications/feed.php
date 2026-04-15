<?php
require_once __DIR__ . '/../_auth.php';
require_login();
require_once __DIR__ . '/../../config/db.php';

header('Content-Type: application/json; charset=utf-8');

function build_notif_row(array $row): array
{
    $p = [];
    if (!empty($row['payload_json'])) {
        $tmp = json_decode($row['payload_json'], true);
        if (is_array($tmp)) $p = $tmp;
    }

    $tipo  = $row['tipo'] ?? '';
    $tarea = $p['task_title'] ?? '—';
    if ($tipo === 'task_created') {
        $title = 'Nueva tarea: ' . $tarea;
    } elseif ($tipo === 'task_moved') {
        $title = 'Movida a "' . ($p['column_name'] ?? 'columna') . '": ' . $tarea;
    } elseif ($tipo === 'task_assigned' || $tipo === 'task_assignee_changed') {
        $title = 'Te asignaron: ' . $tarea;
    } elseif ($tipo === 'task_priority_changed') {
        $labels = ['low' => 'Baja', 'med' => 'Media', 'high' => 'Alta', 'urgent' => 'Urgente'];
        $newLabel = $labels[$p['new_value'] ?? ''] ?? ($p['new_value'] ?? '?');
        $title = 'Prioridad → ' . $newLabel . ': ' . $tarea;
    } elseif ($tipo === 'task_date_changed') {
        $nv = $p['new_value'] ?? null;
        $title = $nv
            ? 'Fecha límite → ' . $nv . ': ' . $tarea
            : 'Fecha límite eliminada: ' . $tarea;
    } elseif ($tipo === 'task_description_changed') {
        $title = 'Descripción actualizada: ' . $tarea;
    } elseif ($tipo === 'task_commented' || $tipo === 'comment') {
        $who   = $p['commenter_name'] ?? 'Alguien';
        $title = $who . ' comentó en: ' . $tarea;
    } elseif ($tipo === 'alert_team_overdue') {
        $board = $p['board_name'] ?? $p['team_name'] ?? 'Tablero';
        $pct   = $p['pct'] ?? 0;
        $venc  = $p['vencidas'] ?? 0;
        $title = '⚠ ' . $board . ': ' . $venc . ' tarea(s) vencida(s) (' . $pct . '%)';
    } elseif ($tipo === 'alert_team_stale') {
        $board = $p['board_name'] ?? $p['team_name'] ?? 'Tablero';
        $count = $p['stale_count'] ?? 0;
        $dias  = $p['dias'] ?? 5;
        $title = '⚠ ' . $board . ': ' . $count . ' tarea(s) sin movimiento >' . $dias . 'd';
    } elseif ($tipo === 'alert_team_unassigned') {
        $board = $p['board_name'] ?? $p['team_name'] ?? 'Tablero';
        $sr    = $p['sin_resp'] ?? 0;
        $pct   = $p['pct'] ?? 0;
        $title = '⚠ ' . $board . ': ' . $sr . ' tarea(s) sin responsable (' . $pct . '%)';
    } elseif ($tipo === 'alert_user_overload') {
        $nombre    = $p['user_name'] ?? 'Usuario';
        $asignadas = $p['asignadas'] ?? 0;
        $title     = '⚠ Carga alta — ' . $nombre . ': ' . $asignadas . ' tareas asignadas';
    } else {
        $title = 'Notificación';
    }

    return [
        'id'       => (int) $row['id'],
        'title'    => $title,
        'when'     => $row['created_at'],
        'board_id' => isset($p['board_id']) ? (int) $p['board_id'] : null,
        'task_id'  => !empty($p['task_id'])  ? (int) $p['task_id']  : null,
    ];
}

// No leídas
$s1 = $conn->prepare(
    "SELECT id, tipo, payload_json, created_at
     FROM notifications
     WHERE user_id = ? AND read_at IS NULL
     ORDER BY created_at DESC LIMIT 20"
);
$s1->bind_param('i', $_SESSION['user_id']);
$s1->execute();
$unread = [];
foreach ($s1->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
    $unread[] = build_notif_row($row);
}

// Leídas recientes
$s2 = $conn->prepare(
    "SELECT id, tipo, payload_json, created_at
     FROM notifications
     WHERE user_id = ? AND read_at IS NOT NULL
     ORDER BY read_at DESC LIMIT 10"
);
$s2->bind_param('i', $_SESSION['user_id']);
$s2->execute();
$recent = [];
foreach ($s2->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
    $recent[] = build_notif_row($row);
}

echo json_encode(['unread' => $unread, 'recent' => $recent], JSON_UNESCAPED_UNICODE);
