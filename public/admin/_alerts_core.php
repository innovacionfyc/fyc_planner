<?php
// public/admin/_alerts_core.php — Lógica central de alertas del sistema
// Solo definiciones: no ejecuta nada al incluirse.
// Usado por run_alerts.php (HTTP) y cron/run_alerts.php (CLI).

// ============================================================
// Umbrales (consistentes con la lógica de stats.php)
// ============================================================
defined('THRESHOLD_OVERDUE_PCT')    || define('THRESHOLD_OVERDUE_PCT',    20);  // % vencidas por equipo
defined('THRESHOLD_STALE_COUNT')    || define('THRESHOLD_STALE_COUNT',     3);  // tareas sin movimiento >5d
defined('THRESHOLD_UNASSIGNED_PCT') || define('THRESHOLD_UNASSIGNED_PCT', 30);  // % sin responsable por equipo
defined('THRESHOLD_OVERLOAD')       || define('THRESHOLD_OVERLOAD',       10);  // tareas asignadas por persona

// ============================================================
// Helpers internos
// ============================================================

/**
 * Verifica si ya existe una alerta no leída del mismo tipo + contexto
 * para ese usuario en las últimas 24 horas.
 *
 * $contextPath : ruta JSON para acotar al contexto exacto (ej. '$.team_id').
 * $contextVal  : valor esperado (ej. 5 para team_id = 5).
 * Si ambos son null, la dedup es solo por (user_id, tipo).
 */
function alert_exists(mysqli $conn, int $userId, string $tipo, ?string $contextPath = null, mixed $contextVal = null): bool {
    if ($contextPath !== null && $contextVal !== null) {
        $val  = (string)$contextVal;
        $stmt = $conn->prepare(
            "SELECT COUNT(*) FROM notifications
             WHERE user_id = ? AND tipo = ? AND read_at IS NULL
               AND JSON_UNQUOTE(JSON_EXTRACT(payload_json, ?)) = ?
               AND created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)"
        );
        $stmt->bind_param('isss', $userId, $tipo, $contextPath, $val);
    } else {
        $stmt = $conn->prepare(
            "SELECT COUNT(*) FROM notifications
             WHERE user_id = ? AND tipo = ? AND read_at IS NULL
               AND created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)"
        );
        $stmt->bind_param('is', $userId, $tipo);
    }
    $stmt->execute();
    return (int)($stmt->get_result()->fetch_row()[0] ?? 0) > 0;
}

/**
 * Inserta la alerta solo si no existe duplicado reciente.
 * Si se inserta, añade el nuevo ID a $newIds para que el llamador pueda enviar emails.
 */
function maybe_insert(
    mysqli $conn,
    int $userId,
    string $tipo,
    array $payload,
    ?string $contextPath,
    mixed $contextVal,
    int &$inserted,
    int &$skipped,
    array &$newIds = []
): void {
    if (alert_exists($conn, $userId, $tipo, $contextPath, $contextVal)) {
        $skipped++;
        return;
    }
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE);
    $stmt = $conn->prepare("INSERT INTO notifications (user_id, tipo, payload_json) VALUES (?, ?, ?)");
    $stmt->bind_param('iss', $userId, $tipo, $json);
    if ($stmt->execute()) {
        $inserted++;
        $newIds[] = (int)$conn->insert_id;
    }
}

/**
 * Nombre del responsable con más tareas asignadas en el tablero.
 * Devuelve null si no hay ninguna tarea asignada en él.
 */
function board_top_assignee(mysqli $conn, int $boardId): ?string {
    $stmt = $conn->prepare(
        "SELECT u.nombre
         FROM tasks tk
         JOIN users u ON u.id = tk.assignee_id
         WHERE tk.board_id = ?
         GROUP BY u.id, u.nombre
         ORDER BY COUNT(*) DESC
         LIMIT 1"
    );
    $stmt->bind_param('i', $boardId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_row();
    return $row ? $row[0] : null;
}

/**
 * Devuelve los user_id de los admin_equipo activos de un equipo.
 */
function team_admin_ids(mysqli $conn, int $teamId): array {
    $stmt = $conn->prepare(
        "SELECT tm.user_id
         FROM team_members tm
         JOIN users u ON u.id = tm.user_id
         WHERE tm.team_id = ? AND tm.rol = 'admin_equipo'
           AND u.deleted_at IS NULL AND u.estado = 'activo'"
    );
    $stmt->bind_param('i', $teamId);
    $stmt->execute();
    return array_column($stmt->get_result()->fetch_all(MYSQLI_ASSOC), 'user_id');
}

// ============================================================
// Función principal
// ============================================================

/**
 * Ejecuta los 4 checks de alertas e inserta notificaciones.
 * Devuelve ['inserted' => N, 'skipped' => M].
 * Lanza Throwable en caso de error grave (el llamador decide qué hacer).
 */
function run_all_alerts(mysqli $conn): array {
    $inserted = 0;
    $skipped  = 0;
    $newIds   = [];

    // Receptores base: todos los admins del sistema
    $adminRows   = $conn->query(
        "SELECT id FROM users WHERE is_admin = 1 AND deleted_at IS NULL AND estado = 'activo'"
    );
    $sysAdminIds = $adminRows ? array_column($adminRows->fetch_all(MYSQLI_ASSOC), 'id') : [];

    // ----------------------------------------------------------
    // Alerta 1 — alert_team_overdue
    // Tableros (de equipo o personales) con más del THRESHOLD_OVERDUE_PCT % de tareas vencidas
    // ----------------------------------------------------------
    $q1 = $conn->query("
        SELECT
            b.id                                                                            AS board_id,
            b.nombre                                                                        AS tablero,
            b.team_id,
            b.owner_user_id,
            t.nombre                                                                        AS equipo,
            COUNT(tk.id)                                                                    AS tareas,
            COALESCE(SUM(tk.fecha_limite IS NOT NULL AND tk.fecha_limite < NOW()), 0)       AS vencidas
        FROM boards b
        LEFT JOIN teams t   ON t.id = b.team_id
        LEFT JOIN tasks  tk ON tk.board_id = b.id AND tk.completed_at IS NULL
        WHERE b.archived_at IS NULL
        GROUP BY b.id, b.nombre, b.team_id, b.owner_user_id, t.nombre
        HAVING tareas > 0 AND (vencidas / tareas * 100) > " . THRESHOLD_OVERDUE_PCT . "
    ");
    if ($q1) {
        while ($row = $q1->fetch_assoc()) {
            $boardId = (int)$row['board_id'];
            $teamId  = $row['team_id'] !== null ? (int)$row['team_id'] : null;
            $tareas  = (int)$row['tareas'];
            $venc    = (int)$row['vencidas'];
            $payload = [
                'board_id'          => $boardId,
                'board_name'        => $row['tablero'],
                'team_id'           => $teamId,
                'team_name'         => $row['equipo'],
                'vencidas'          => $venc,
                'tareas'            => $tareas,
                'pct'               => (int)round($venc / $tareas * 100),
                'top_assignee_name' => board_top_assignee($conn, $boardId),
            ];
            $extra      = $teamId !== null ? team_admin_ids($conn, $teamId) : [(int)$row['owner_user_id']];
            $recipients = array_unique(array_merge($sysAdminIds, $extra));
            foreach ($recipients as $uid) {
                maybe_insert($conn, (int)$uid, 'alert_team_overdue', $payload, '$.board_id', $boardId, $inserted, $skipped, $newIds);
            }
        }
    }

    // ----------------------------------------------------------
    // Alerta 2 — alert_team_stale
    // Tableros con más de THRESHOLD_STALE_COUNT tareas sin movimiento en +5 días
    // ----------------------------------------------------------
    $q2 = $conn->query("
        SELECT
            b.id                                                                            AS board_id,
            b.nombre                                                                        AS tablero,
            b.team_id,
            b.owner_user_id,
            t.nombre                                                                        AS equipo,
            COUNT(tk.id)                                                                    AS tareas,
            COALESCE(SUM(COALESCE(tk.updated_at, tk.creado_en) < DATE_SUB(NOW(), INTERVAL 5 DAY)), 0) AS stale
        FROM boards b
        LEFT JOIN teams t   ON t.id = b.team_id
        LEFT JOIN tasks  tk ON tk.board_id = b.id AND tk.completed_at IS NULL
        WHERE b.archived_at IS NULL
        GROUP BY b.id, b.nombre, b.team_id, b.owner_user_id, t.nombre
        HAVING stale > " . THRESHOLD_STALE_COUNT . "
    ");
    if ($q2) {
        while ($row = $q2->fetch_assoc()) {
            $boardId = (int)$row['board_id'];
            $teamId  = $row['team_id'] !== null ? (int)$row['team_id'] : null;
            $payload = [
                'board_id'          => $boardId,
                'board_name'        => $row['tablero'],
                'team_id'           => $teamId,
                'team_name'         => $row['equipo'],
                'tareas'            => (int)$row['tareas'],
                'stale_count'       => (int)$row['stale'],
                'dias'              => 5,
                'top_assignee_name' => board_top_assignee($conn, $boardId),
            ];
            $extra      = $teamId !== null ? team_admin_ids($conn, $teamId) : [(int)$row['owner_user_id']];
            $recipients = array_unique(array_merge($sysAdminIds, $extra));
            foreach ($recipients as $uid) {
                maybe_insert($conn, (int)$uid, 'alert_team_stale', $payload, '$.board_id', $boardId, $inserted, $skipped, $newIds);
            }
        }
    }

    // ----------------------------------------------------------
    // Alerta 3 — alert_team_unassigned
    // Tableros con más del THRESHOLD_UNASSIGNED_PCT % de tareas sin responsable
    // ----------------------------------------------------------
    $q3 = $conn->query("
        SELECT
            b.id                                                                            AS board_id,
            b.nombre                                                                        AS tablero,
            b.team_id,
            b.owner_user_id,
            t.nombre                                                                        AS equipo,
            COUNT(tk.id)                                                                    AS tareas,
            COALESCE(SUM(tk.assignee_id IS NULL), 0)                                        AS sin_resp
        FROM boards b
        LEFT JOIN teams t   ON t.id = b.team_id
        LEFT JOIN tasks  tk ON tk.board_id = b.id AND tk.completed_at IS NULL
        WHERE b.archived_at IS NULL
        GROUP BY b.id, b.nombre, b.team_id, b.owner_user_id, t.nombre
        HAVING tareas > 0 AND (sin_resp / tareas * 100) > " . THRESHOLD_UNASSIGNED_PCT . "
    ");
    if ($q3) {
        while ($row = $q3->fetch_assoc()) {
            $boardId = (int)$row['board_id'];
            $teamId  = $row['team_id'] !== null ? (int)$row['team_id'] : null;
            $tareas  = (int)$row['tareas'];
            $sinResp = (int)$row['sin_resp'];
            $payload = [
                'board_id'   => $boardId,
                'board_name' => $row['tablero'],
                'team_id'    => $teamId,
                'team_name'  => $row['equipo'],
                'sin_resp'   => $sinResp,
                'tareas'     => $tareas,
                'pct'        => (int)round($sinResp / $tareas * 100),
            ];
            $extra      = $teamId !== null ? team_admin_ids($conn, $teamId) : [(int)$row['owner_user_id']];
            $recipients = array_unique(array_merge($sysAdminIds, $extra));
            foreach ($recipients as $uid) {
                maybe_insert($conn, (int)$uid, 'alert_team_unassigned', $payload, '$.board_id', $boardId, $inserted, $skipped, $newIds);
            }
        }
    }

    // ----------------------------------------------------------
    // Alerta 4 — alert_user_overload
    // Usuarios con más de THRESHOLD_OVERLOAD tareas asignadas
    // ----------------------------------------------------------
    $q4 = $conn->query("
        SELECT
            u.id,
            u.nombre,
            COUNT(tk.id)                                                                    AS asignadas,
            COALESCE(SUM(tk.fecha_limite IS NOT NULL AND tk.fecha_limite < NOW()), 0)       AS vencidas
        FROM users u
        JOIN tasks tk ON tk.assignee_id = u.id AND tk.completed_at IS NULL
        WHERE u.deleted_at IS NULL AND u.estado = 'activo'
        GROUP BY u.id, u.nombre
        HAVING asignadas > " . THRESHOLD_OVERLOAD . "
    ");
    if ($q4) {
        while ($row = $q4->fetch_assoc()) {
            $overloadedUserId = (int)$row['id'];
            $payload = [
                'user_id'   => $overloadedUserId,
                'user_name' => $row['nombre'],
                'asignadas' => (int)$row['asignadas'],
                'vencidas'  => (int)$row['vencidas'],
            ];

            // Notificar al propio usuario
            maybe_insert($conn, $overloadedUserId, 'alert_user_overload', $payload, '$.user_id', $overloadedUserId, $inserted, $skipped, $newIds);

            // Notificar a sus admin_equipo + sys admins (excluyendo al propio usuario)
            $teamStmt = $conn->prepare(
                "SELECT DISTINCT tm2.user_id
                 FROM team_members tm1
                 JOIN team_members tm2 ON tm2.team_id = tm1.team_id AND tm2.rol = 'admin_equipo'
                 JOIN users u2 ON u2.id = tm2.user_id
                 WHERE tm1.user_id = ? AND u2.deleted_at IS NULL AND u2.estado = 'activo'"
            );
            $teamStmt->bind_param('i', $overloadedUserId);
            $teamStmt->execute();
            $teamAdmins = array_column($teamStmt->get_result()->fetch_all(MYSQLI_ASSOC), 'user_id');

            $managers = array_unique(array_merge($sysAdminIds, $teamAdmins));
            foreach ($managers as $uid) {
                if ((int)$uid === $overloadedUserId) continue;
                maybe_insert($conn, (int)$uid, 'alert_user_overload', $payload, '$.user_id', $overloadedUserId, $inserted, $skipped, $newIds);
            }
        }
    }

    return ['inserted' => $inserted, 'skipped' => $skipped, 'new_ids' => $newIds];
}
