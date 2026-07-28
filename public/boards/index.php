<?php
// public/boards/index.php — Retirado como pantalla de uso.
// El flujo oficial del producto vive en workspace.php.
require_once __DIR__ . '/../_auth.php';
require_login();
redirect_to('boards/workspace.php');
