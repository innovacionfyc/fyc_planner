<?php
// public/_perm.php
require_once __DIR__ . '/../config/db.php';

function is_super_admin(mysqli $conn): bool
{
    $uid = (int) ($_SESSION['user_id'] ?? 0);
    if ($uid <= 0)
        return false;

    $q = $conn->prepare("SELECT is_admin, rol FROM users WHERE id = ? LIMIT 1");
    if (!$q)
        return false;

    $q->bind_param('i', $uid);
    $q->execute();
    $row = $q->get_result()->fetch_assoc();

    return ((int) ($row['is_admin'] ?? 0) === 1) && (($row['rol'] ?? '') === 'super_admin');
}

function is_admin_user(mysqli $conn): bool
{
    $uid = (int) ($_SESSION['user_id'] ?? 0);
    if ($uid <= 0)
        return false;

    $q = $conn->prepare("SELECT is_admin FROM users WHERE id = ? LIMIT 1");
    if (!$q)
        return false;

    $q->bind_param('i', $uid);
    $q->execute();
    $row = $q->get_result()->fetch_assoc();

    return ((int) ($row['is_admin'] ?? 0) === 1);
}