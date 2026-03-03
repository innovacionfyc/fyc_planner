<?php
// public/_auth.php
require_once __DIR__ . '/../config/db.php';
session_start();

function require_login(): void
{
    if (empty($_SESSION['user_id'])) {
        header('Location: /fyc_planner/public/login.php');
        exit;
    }
}

function is_admin(): bool
{
    return isset($_SESSION['rol']) && $_SESSION['rol'] === 'admin';
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
