<?php
// public/tags/tag_action.php
// Acciones: create | delete | attach | detach
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../_auth.php';
require_once __DIR__ . '/../_perm.php';
require_login();

header('Content-Type: application/json; charset=utf-8');

function fail($msg)
{
    echo json_encode(['ok' => false, 'error' => $msg]);
    exit;
}
function ok($extra = [])
{
    echo json_encode(array_merge(['ok' => true], $extra));
    exit;
}

// Leer datos
$ct = $_SERVER['HTTP_CONTENT_TYPE'] ?? $_SERVER['CONTENT_TYPE'] ?? '';
if (stripos($ct, 'application/json') !== false) {
    $data = json_decode(file_get_contents('php://input'), true) ?: [];
} else {
    $data = $_POST;
}

// CSRF
$csrf = trim((string) ($data['csrf'] ?? ''));
if (!$csrf || !hash_equals($_SESSION['csrf'] ?? '', $csrf))
    fail('CSRF inválido');

$action = trim((string) ($data['action'] ?? ''));
$board_id = (int) ($data['board_id'] ?? 0);
$user_id = (int) ($_SESSION['user_id'] ?? 0);

if ($board_id <= 0)
    fail('board_id requerido');

// Verificar permisos de escritura en el tablero
if (!can_write_board($conn, $board_id, $user_id))
    fail('Sin acceso al tablero');

// ---- CREAR tag ----
if ($action === 'create') {
    $nombre = trim((string) ($data['nombre'] ?? ''));
    $color_hex = trim((string) ($data['color_hex'] ?? '#9070e8'));
    if ($nombre === '')
        fail('Nombre requerido');
    if (mb_strlen($nombre) > 60)
        fail('Nombre demasiado largo');
    if (!preg_match('/^#[0-9a-fA-F]{6}$/', $color_hex))
        $color_hex = '#9070e8';

    $ins = $conn->prepare("INSERT INTO task_tags (board_id, nombre, color_hex) VALUES (?,?,?)");
    $ins->bind_param('iss', $board_id, $nombre, $color_hex);
    if (!$ins->execute())
        fail('Error al crear etiqueta');
    ok(['tag_id' => (int) $conn->insert_id, 'nombre' => $nombre, 'color_hex' => $color_hex]);
}

// ---- ELIMINAR tag ----
if ($action === 'delete') {
    $tag_id = (int) ($data['tag_id'] ?? 0);
    if ($tag_id <= 0)
        fail('tag_id requerido');

    $del = $conn->prepare("DELETE FROM task_tags WHERE id=? AND board_id=?");
    $del->bind_param('ii', $tag_id, $board_id);
    if (!$del->execute())
        fail('Error al eliminar etiqueta');
    ok(['tag_id' => $tag_id]);
}

// ---- ATTACH tag a tarea ----
if ($action === 'attach') {
    $task_id = (int) ($data['task_id'] ?? 0);
    $tag_id = (int) ($data['tag_id'] ?? 0);
    if ($task_id <= 0 || $tag_id <= 0)
        fail('task_id y tag_id requeridos');

    // Verificar que tarea pertenece al tablero
    $chkT = $conn->prepare("SELECT 1 FROM tasks WHERE id=? AND board_id=? LIMIT 1");
    $chkT->bind_param('ii', $task_id, $board_id);
    $chkT->execute();
    if (!$chkT->get_result()->fetch_row())
        fail('Tarea no encontrada en este tablero');

    // Verificar que tag pertenece al tablero
    $chkTg = $conn->prepare("SELECT 1 FROM task_tags WHERE id=? AND board_id=? LIMIT 1");
    $chkTg->bind_param('ii', $tag_id, $board_id);
    $chkTg->execute();
    if (!$chkTg->get_result()->fetch_row())
        fail('Etiqueta no encontrada en este tablero');

    // INSERT IGNORE para evitar duplicados
    $ins = $conn->prepare("INSERT IGNORE INTO task_tag_pivot (task_id, tag_id) VALUES (?,?)");
    $ins->bind_param('ii', $task_id, $tag_id);
    if (!$ins->execute())
        fail('Error al asignar etiqueta');
    ok(['task_id' => $task_id, 'tag_id' => $tag_id]);
}

// ---- DETACH tag de tarea ----
if ($action === 'detach') {
    $task_id = (int) ($data['task_id'] ?? 0);
    $tag_id = (int) ($data['tag_id'] ?? 0);
    if ($task_id <= 0 || $tag_id <= 0)
        fail('task_id y tag_id requeridos');

    $del = $conn->prepare("DELETE FROM task_tag_pivot WHERE task_id=? AND tag_id=?");
    $del->bind_param('ii', $task_id, $tag_id);
    if (!$del->execute())
        fail('Error al quitar etiqueta');
    ok(['task_id' => $task_id, 'tag_id' => $tag_id]);
}

fail('Acción desconocida');