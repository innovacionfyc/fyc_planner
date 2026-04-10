<?php
// public/boards/presence_ping.php
require_once __DIR__ . '/../_auth.php';
require_login();
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../_perm.php';

header('Content-Type: application/json; charset=utf-8');

// Validar CSRF y parámetros
if (!isset($_POST['csrf'], $_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], $_POST['csrf'])) {
    echo json_encode(['active' => []]);
    exit;
}
$board_id = (int) ($_POST['board_id'] ?? 0);
if ($board_id <= 0) {
    echo json_encode(['active' => []]);
    exit;
}

// Verificar acceso usando la misma lógica que view.php:
// cubre tableros de equipo (team_members) y personales (board_members).
if (!has_board_access($conn, $board_id, (int) $_SESSION['user_id'])) {
    echo json_encode(['active' => []]);
    exit;
}

// Registrar/actualizar el latido (heartbeat)
$up = $conn->prepare("
  INSERT INTO board_presence (board_id, user_id, last_seen)
  VALUES (?, ?, NOW())
  ON DUPLICATE KEY UPDATE last_seen = NOW()
");
$up->bind_param('ii', $board_id, $_SESSION['user_id']);
$up->execute();

// Consultar quiénes están activos en los últimos 25s
$q = $conn->prepare("
  SELECT u.id, u.nombre
  FROM board_presence p
  JOIN users u ON u.id = p.user_id
  WHERE p.board_id = ?
    AND p.last_seen >= (NOW() - INTERVAL 25 SECOND)
  ORDER BY u.nombre ASC
");
$q->bind_param('i', $board_id);
$q->execute();
$res = $q->get_result();

$active = [];
while ($row = $res->fetch_assoc()) {
    $active[] = ['id' => (int) $row['id'], 'nombre' => $row['nombre']];
}

echo json_encode(['active' => $active], JSON_UNESCAPED_UNICODE);
