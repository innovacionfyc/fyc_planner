<?php
require_once __DIR__ . '/../_auth.php';
require_login();
require_once __DIR__ . '/../../config/db.php';

header('Content-Type: application/json; charset=utf-8');

$stmt = $conn->prepare("SELECT id, tipo, payload_json, creado_en
                        FROM notifications
                        WHERE user_id = ? AND leido = 0
                        ORDER BY creado_en DESC
                        LIMIT 50");
$stmt->bind_param('i', $_SESSION['user_id']);
$stmt->execute();
$res = $stmt->get_result();

$items = [];
while ($row = $res->fetch_assoc()) {
    $p = [];
    if (!empty($row['payload_json'])) {
        $tmp = json_decode($row['payload_json'], true);
        if (is_array($tmp))
            $p = $tmp;
    }

    if ($row['tipo'] === 'task_created') {
        $title = 'Nueva tarea: ' . ($p['task_title'] ?? '—');
    } elseif ($row['tipo'] === 'task_moved') {
        $title = 'Tarea movida a ' . ($p['column_name'] ?? 'columna') . ': ' . ($p['task_title'] ?? '—');
    } elseif ($row['tipo'] === 'task_assigned') {
        $title = 'Te asignaron: ' . ($p['task_title'] ?? '—');
    } else {
        $title = 'Notificación';
    }

    $items[] = ['id' => (int) $row['id'], 'title' => $title, 'when' => $row['creado_en']];
}

echo json_encode(['items' => $items], JSON_UNESCAPED_UNICODE);
