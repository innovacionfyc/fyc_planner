<?php
// public/_auth.php
session_start();

function require_login()
{
    if (empty($_SESSION['user_id'])) {
        header('Location: /fyc_planner/public/login.php');
        exit;
    }
}

function is_admin()
{
    return isset($_SESSION['rol']) && $_SESSION['rol'] === 'admin';
}

function require_admin()
{
    require_login();
    if (!is_admin()) {
        header('Location: /fyc_planner/public/boards/index.php');
        exit;
    }
}
