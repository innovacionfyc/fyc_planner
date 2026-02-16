<?php
// public/_auth.php
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

function require_admin(): void
{
    require_login();
    if (!is_admin()) {
        header('Location: /fyc_planner/public/app.php');
        exit;
    }
}
