<?php
// public/columns/column_action.php
// Maneja: create | rename | delete
// Acepta POST con JSON o form-data, responde JSON.

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

// Leer datos (soporta form-data y JSON)
$ct = $_SERVER['HTTP_CONTENT_TYPE'] ?? $_SERVER['CONTENT_TYPE'] ?? '';
if (stripos($ct, 'application/json') !== false) {
    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true) ?: [];
} else {
    $data = $_POST;
}

// CSRF
$csrf = trim((string) ($data['csrf'] ?? ''));
$sessCsrf = $_SESSION['csrf'] ?? '';
if (!$csrf || !hash_equals($sessCsrf, $csrf)) {
    fail('CSRF inválido');
}

$action = trim((string) ($data['action'] ?? ''));
$board_id = (int) ($data['board_id'] ?? 0);
$user_id = (int) ($_SESSION['user_id'] ?? 0);

if ($board_id <= 0)
    fail('board_id requerido');

// Verificar que el usuario es miembro con rol de escritura
if (!can_edit_board($conn, $board_id, $user_id))
    fail('Sin acceso a este tablero');

// ============================================================
// CREAR columna
// ============================================================
if ($action === 'create') {
    $nombre = trim((string) ($data['nombre'] ?? ''));
    if ($nombre === '')
        fail('Nombre requerido');
    if (mb_strlen($nombre) > 120)
        fail('Nombre demasiado largo');

    // Calcular orden: máximo actual + 1
    $r = $conn->query("SELECT COALESCE(MAX(orden),0)+1 AS next_orden FROM columns WHERE board_id={$board_id}");
    $next_orden = (int) ($r->fetch_assoc()['next_orden'] ?? 1);

    $ins = $conn->prepare("INSERT INTO columns (board_id, nombre, orden) VALUES (?,?,?)");
    $ins->bind_param('isi', $board_id, $nombre, $next_orden);
    if (!$ins->execute())
        fail('Error al crear columna');

    ok(['column_id' => (int) $conn->insert_id, 'nombre' => $nombre, 'orden' => $next_orden]);
}

// ============================================================
// RENOMBRAR columna
// ============================================================
if ($action === 'rename') {
    $column_id = (int) ($data['column_id'] ?? 0);
    $nombre = trim((string) ($data['nombre'] ?? ''));
    if ($column_id <= 0)
        fail('column_id requerido');
    if ($nombre === '')
        fail('Nombre requerido');
    if (mb_strlen($nombre) > 120)
        fail('Nombre demasiado largo');

    // Verificar que la columna pertenece al tablero
    $chkCol = $conn->prepare("SELECT id FROM columns WHERE id=? AND board_id=? LIMIT 1");
    $chkCol->bind_param('ii', $column_id, $board_id);
    $chkCol->execute();
    if (!$chkCol->get_result()->fetch_row())
        fail('Columna no encontrada');

    $upd = $conn->prepare("UPDATE columns SET nombre=? WHERE id=? AND board_id=?");
    $upd->bind_param('sii', $nombre, $column_id, $board_id);
    if (!$upd->execute())
        fail('Error al renombrar');

    ok(['column_id' => $column_id, 'nombre' => $nombre]);
}

// ============================================================
// ELIMINAR columna
// ============================================================
if ($action === 'delete') {
    $column_id = (int) ($data['column_id'] ?? 0);
    if ($column_id <= 0)
        fail('column_id requerido');

    // Verificar que la columna pertenece al tablero
    $chkCol = $conn->prepare("SELECT id FROM columns WHERE id=? AND board_id=? LIMIT 1");
    $chkCol->bind_param('ii', $column_id, $board_id);
    $chkCol->execute();
    if (!$chkCol->get_result()->fetch_row())
        fail('Columna no encontrada');

    // Las tareas se eliminan en cascada (FK ON DELETE CASCADE en la tabla tasks)
    $del = $conn->prepare("DELETE FROM columns WHERE id=? AND board_id=?");
    $del->bind_param('ii', $column_id, $board_id);
    if (!$del->execute())
        fail('Error al eliminar');

    ok(['column_id' => $column_id]);
}

// ============================================================
// MARCAR columna como "done" (finalización real)
// Solo puede haber una columna is_done=1 por tablero.
// ============================================================
if ($action === 'set_done') {
    $column_id = (int) ($data['column_id'] ?? 0);
    $mark      = isset($data['is_done']) ? ((int)$data['is_done'] === 1 ? 1 : 0) : -1;

    if ($column_id <= 0)
        fail('column_id requerido');
    if ($mark === -1)
        fail('is_done requerido (0 o 1)');

    // Verificar que la columna pertenece al tablero
    $chkCol = $conn->prepare("SELECT id FROM columns WHERE id = ? AND board_id = ? LIMIT 1");
    $chkCol->bind_param('ii', $column_id, $board_id);
    $chkCol->execute();
    if (!$chkCol->get_result()->fetch_row())
        fail('Columna no encontrada');

    $conn->begin_transaction();
    try {
        // Quitar is_done de todas las columnas del tablero primero
        $clear = $conn->prepare("UPDATE columns SET is_done = 0 WHERE board_id = ?");
        $clear->bind_param('i', $board_id);
        if (!$clear->execute()) throw new Exception('clear_failed');

        // Si mark=1, activar solo esta columna
        if ($mark === 1) {
            $set = $conn->prepare("UPDATE columns SET is_done = 1 WHERE id = ? AND board_id = ?");
            $set->bind_param('ii', $column_id, $board_id);
            if (!$set->execute()) throw new Exception('set_failed');
        }

        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollback();
        fail('Error al actualizar: ' . $e->getMessage());
    }

    ok(['column_id' => $column_id, 'is_done' => $mark]);
}

fail('Acción desconocida: ' . htmlspecialchars($action));