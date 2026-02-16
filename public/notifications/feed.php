<?php
require_once __DIR__ . '/../_auth.php';
require_login();
require_once __DIR__ . '/../../config/db.php';

header('Content-Type: application/json; charset=utf-8');

$stmt = $conn->prepare("SELECT id, tipo, payload_json, created_at
                        FROM notifications
                        WHERE user_id = ? AND read_at IS NULL
                        ORDER BY created_at DESC
                        LIMIT 50");
$stmt->bind_param('i', $_SESSION['user_id']);
$stmt->execute();
$res = $stmt->get_result();

$items = [];
while ($row = $res->fetch_assoc()) {
    $p = [];
    if (!empty($row['payload_json'])) {
        $tmp = json_decode($row['payload_json'], true);
        if (is_array($tmp)) {
            $p = $tmp;
        }
    }

    if ($row['tipo'] === 'task_created') {
        $title = 'Nueva tarea: ' . ($p['task_title'] ?? '—');
    } elseif ($row['tipo'] === 'task_moved') {
        $title = 'Tarea movida a ' . ($p['column_name'] ?? 'columna') . ': ' . ($p['task_title'] ?? '—');
    } elseif ($row['tipo'] === 'task_assigned') {
        $title = 'Te asignaron: ' . ($p['task_title'] ?? '—');
    } elseif ($row['tipo'] === 'comment') {
        $title = 'Nuevo comentario: ' . ($p['task_title'] ?? '—');
    } else {
        $title = 'Notificación';
    }

    // Link directo al detalle si hay task_id
    $url = !empty($p['task_id']) ? ('../tasks/view.php?id=' . (int) $p['task_id']) : null;

    $items[] = [
        'id' => (int) $row['id'],
        'title' => $title,
        'when' => $row['created_at'],
        'url' => $url
    ];
}

echo json_encode(['items' => $items], JSON_UNESCAPED_UNICODE);
