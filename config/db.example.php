<?php
// config/db.example.php
// Copia este archivo a config/db.php y ajusta credenciales en cada PC.

$DB_HOST = '127.0.0.1';
$DB_USER = 'root';
$DB_PASS = '';
$DB_NAME = 'fyc_planner_db';
$DB_PORT = 3306;

$mysqli = new mysqli($DB_HOST, $DB_USER, $DB_PASS, $DB_NAME, $DB_PORT);
if ($mysqli->connect_errno) {
    die("Error de conexión MySQL: " . $mysqli->connect_error);
}
$mysqli->set_charset("utf8mb4");
