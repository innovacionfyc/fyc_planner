<?php
// public/_auth.php
require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../config/db.php';
app_sync_db_timezone($conn ?? null);
session_start();

function require_login(): void
{
    if (empty($_SESSION['user_id'])) {
        redirect_to('login.php');
    }

    // Verificar estado real en BD cada 5 minutos.
    // Detecta suspensiones o eliminaciones ocurridas después del login.
    $now = time();
    if (empty($_SESSION['_auth_ts']) || ($now - (int) $_SESSION['_auth_ts']) > 300) {
        global $conn;
        $uid = (int) ($_SESSION['user_id'] ?? 0);
        $q = $conn->prepare("SELECT activo, deleted_at, estado FROM users WHERE id = ? LIMIT 1");
        if ($q) {
            $q->bind_param('i', $uid);
            $q->execute();
            $activo = null;
            $deletedAt = null;
            $estado = null;
            $q->bind_result($activo, $deletedAt, $estado);
            $found = $q->fetch();
            $q->close();

            if (
                !$found
                || (int) $activo !== 1
                || !empty($deletedAt)
                || $estado !== 'aprobado'
            ) {
                session_destroy();
                redirect_to('login.php?e=5');
            }
        }
        $_SESSION['_auth_ts'] = $now;
    }
}

function require_admin()
{
    require_login();
    global $conn;

    $uid = (int) ($_SESSION['user_id'] ?? 0);
    if ($uid <= 0) {
        redirect_to('login.php');
    }

    $q = $conn->prepare("SELECT is_admin FROM users WHERE id = ? LIMIT 1");
    $q->bind_param('i', $uid);
    $q->execute();
    $row = $q->get_result()->fetch_assoc();

    $isAdmin = ((int) ($row['is_admin'] ?? 0) === 1);
    if (!$isAdmin) {
        // no revelar ruta admin; manda al workspace
        redirect_to('boards/workspace.php');
    }
}
