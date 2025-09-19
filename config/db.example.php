<?php
// config/db.example.php
$DB_HOST = 'localhost';
$DB_NAME = 'fyc_planner_db';
$DB_USER = 'tu_usuario';
$DB_PASS = 'tu_password';

$conn = new mysqli($DB_HOST, $DB_USER, $DB_PASS, $DB_NAME);
if ($conn->connect_error) {
    die('DB error');
}
$conn->set_charset('utf8mb4');
