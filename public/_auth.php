<?php
// public/_auth.php
require_once __DIR__ . '/../config/db.php';
session_start();

function require_login(): void
{
    if (empty($_SESSION['user_id'])) {
        header('Location: /login.php');
        exit;
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
                header('Location: /login.php?e=5');
                exit;
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
        header('Location: ../login.php');
        exit;
    }

    $q = $conn->prepare("SELECT is_admin FROM users WHERE id = ? LIMIT 1");
    $q->bind_param('i', $uid);
    $q->execute();
    $row = $q->get_result()->fetch_assoc();

    $isAdmin = ((int) ($row['is_admin'] ?? 0) === 1);
    if (!$isAdmin) {
        // no revelar ruta admin; manda al workspace
        header('Location: ../boards/workspace.php');
        exit;
    }
}
