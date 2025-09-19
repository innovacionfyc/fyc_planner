<?php
require_once __DIR__ . '/../_auth.php';
require_login();
require_once __DIR__ . '/../../config/db.php';

header('Content-Type: application/json; charset=utf-8');

$board_id = (int) ($_GET['board_id'] ?? 0);
$after_id = (int) ($_GET['after_id'] ?? 0);
if ($board_id <= 0) {
    http_response_code(400);
    echo '{"events":[]}';
    exit;
}

// validar que pertenezco al board
$chk = $conn->prepare("SELECT 1 FROM board_members WHERE board_id=? AND user_id=? LIMIT 1");
$chk->bind_param('ii', $board_id, $_SESSION['user_id']);
$chk->execute();
if (!$chk->get_result()->fetch_row()) {
    http_response_code(403);
    echo '{"events":[]}';
    exit;
}

$q = $conn->prepare("SELECT id, kind, task_id, column_id, payload_json, created_at
                     FROM board_events
                     WHERE board_id=? AND id>? ORDER BY id ASC LIMIT 100");
$q->bind_param('ii', $board_id, $after_id);
$q->execute();
$res = $q->get_result();

$out = [];
while ($e = $res->fetch_assoc()) {
    $payload = [];
    if (!empty($e['payload_json'])) {
        $tmp = json_decode($e['payload_json'], true);
        if (is_array($tmp))
            $payload = $tmp;
    }
    $out[] = [
        'id' => (int) $e['id'],
        'kind' => $e['kind'],
        'task_id' => (int) $e['task_id'],
        'column_id' => isset($e['column_id']) ? (int) $e['column_id'] : null,
        'payload' => $payload,
        'at' => $e['created_at']
    ];
}
echo json_encode(['events' => $out], JSON_UNESCAPED_UNICODE);
