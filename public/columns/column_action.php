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
        $rellenadas = 0;
        $enLote     = 0;
        if ($mark === 1) {
            $set = $conn->prepare("UPDATE columns SET is_done = 1 WHERE id = ? AND board_id = ?");
            $set->bind_param('ii', $column_id, $board_id);
            if (!$set->execute()) throw new Exception('set_failed');

            // Las tareas que YA estaban dentro se dan por terminadas ahora, y
            // hasta hoy se quedaban sin fecha: completed_at solo se escribía al
            // arrastrar (tasks/move.php). Como los reportes usan
            // `completed_at IS NULL` para saber qué sigue pendiente, esas
            // tareas contaban como pendientes indefinidamente.
            //
            // QUÉ FECHA SE USA, Y POR QUÉ NO ES LA DE AHORA
            //   La tarea se terminó en algún momento del pasado. Poner la hora
            //   actual diría que todas se terminaron en el mismo segundo, lo
            //   que falsearía cualquier filtro por fecha construido encima.
            //
            //   updated_at suele ser la mejor aproximación: para una tarea
            //   arrastrada hasta aquí es justo el instante de ese movimiento.
            //   PERO no siempre: una operación masiva —una migración, un
            //   arreglo por lotes— deja a decenas de tareas con el MISMO
            //   updated_at al segundo. Eso no es una fecha de finalización,
            //   es la huella de esa operación, y usarla las amontonaría todas
            //   en la misma semana.
            //
            //   Regla, idéntica a la del relleno puntual de producción para
            //   que el dato no dependa de por qué camino se rellenó:
            //     · updated_at vacío                  → creado_en
            //     · updated_at repetido (lote)        → creado_en
            //     · resto                             → updated_at
            //
            // Solo toca las que no tienen fecha: nunca pisa una ya existente.

            // 1) Detectar los segundos repetidos entre las candidatas. Se hace
            //    en una consulta aparte y no como subconsulta del UPDATE
            //    porque MySQL no admite leer de la misma tabla que actualiza.
            $lote = [];
            $qLote = $conn->prepare(
                "SELECT updated_at
                   FROM tasks
                  WHERE board_id = ? AND column_id = ?
                    AND completed_at IS NULL
                    AND updated_at IS NOT NULL
                  GROUP BY updated_at
                 HAVING COUNT(*) > 1"
            );
            if (!$qLote) throw new Exception('lote_prepare_failed');
            $qLote->bind_param('ii', $board_id, $column_id);
            if (!$qLote->execute()) throw new Exception('lote_failed');
            foreach ($qLote->get_result()->fetch_all(MYSQLI_ASSOC) as $r) {
                $lote[] = (string) $r['updated_at'];
            }
            $qLote->close();

            // 2) Rellenar aplicando la regla.
            if ($lote === []) {
                $sqlFill = "UPDATE tasks
                               SET completed_at = COALESCE(updated_at, creado_en)
                             WHERE board_id = ? AND column_id = ?
                               AND completed_at IS NULL";
                $tipos = 'ii';
                $args  = [$board_id, $column_id];
            } else {
                $marcas  = implode(',', array_fill(0, count($lote), '?'));
                $sqlFill = "UPDATE tasks
                               SET completed_at = CASE
                                     WHEN updated_at IS NULL           THEN creado_en
                                     WHEN updated_at IN ($marcas)      THEN creado_en
                                     ELSE updated_at
                                   END
                             WHERE board_id = ? AND column_id = ?
                               AND completed_at IS NULL";
                $tipos = str_repeat('s', count($lote)) . 'ii';
                $args  = array_merge($lote, [$board_id, $column_id]);
            }

            $backfill = $conn->prepare($sqlFill);
            if (!$backfill) throw new Exception('backfill_prepare_failed');
            $refs = [];
            foreach ($args as $k => $v) {
                $refs[$k] = &$args[$k];
            }
            array_unshift($refs, $tipos);
            call_user_func_array([$backfill, 'bind_param'], $refs);
            if (!$backfill->execute()) throw new Exception('backfill_failed');
            $rellenadas = $conn->affected_rows;
            $enLote      = count($lote);
        }

        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollback();
        fail('Error al actualizar: ' . $e->getMessage());
    }

    ok([
        'column_id'               => $column_id,
        'is_done'                 => $mark,
        'completed_at_rellenados' => $rellenadas,
        'lotes_detectados'        => $enLote,
    ]);
}

fail('Acción desconocida: ' . htmlspecialchars($action));