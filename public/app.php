<?php
// public/app.php
require_once __DIR__ . '/_auth.php';
require_login();

// Home oficial del sistema (modo Notion / todo en una sola página)
header('Location: boards/workspace.php');
exit;