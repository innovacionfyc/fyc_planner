<?php
require_once __DIR__ . '/../_auth.php';
require_login();
require_once __DIR__ . '/../../config/db.php';

// Validar CSRF
if (!isset($_POST['csrf'], $_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], $_POST['csrf'])) {
    header('Location: index.php');
    exit;
}

$nombre = trim($_POST['nombre'] ?? '');
$team_id = isset($_POST['team_id']) && $_POST['team_id'] !== '' ? (int) $_POST['team_id'] : null;
if ($nombre === '') {
    header('Location: index.php');
    exit;
}

// Si viene team_id, validar que el usuario es OWNER del equipo
if ($team_id) {
    $chk = $conn->prepare("SELECT rol_en_team FROM team_members WHERE team_id = ? AND user_id = ? LIMIT 1");
    $chk->bind_param('ii', $team_id, $_SESSION['user_id']);
    $chk->execute();
    $row = $chk->get_result()->fetch_assoc();
    if (!$row || $row['rol_en_team'] !== 'owner') {
        // No eres owner → no puede crear tablero en ese equipo
        header('Location: index.php');
        exit;
    }
}


// 1) Crear board (con o sin team_id)
if ($team_id) {
    $stmt = $conn->prepare("INSERT INTO boards (nombre, owner_id, team_id) VALUES (?, ?, ?)");
    $stmt->bind_param('sii', $nombre, $_SESSION['user_id'], $team_id);
} else {
    $stmt = $conn->prepare("INSERT INTO boards (nombre, owner_id) VALUES (?, ?)");
    $stmt->bind_param('si', $nombre, $_SESSION['user_id']);
}
$stmt->execute();
$board_id = $stmt->insert_id;

// 2) Agregarme como miembro (owner)
$stmt = $conn->prepare("INSERT INTO board_members (board_id, user_id, rol_en_board) VALUES (?, ?, 'owner')");
$stmt->bind_param('ii', $board_id, $_SESSION['user_id']);
$stmt->execute();

// 3) Si es de equipo, invitar a todos los miembros (editores), excepto al creador para no duplicar
if ($team_id) {
    $tm = $conn->prepare("SELECT user_id FROM team_members WHERE team_id = ?");
    $tm->bind_param('i', $team_id);
    $tm->execute();
    $mems = $tm->get_result()->fetch_all(MYSQLI_ASSOC);

    $ins = $conn->prepare("INSERT IGNORE INTO board_members (board_id, user_id, rol_en_board) VALUES (?, ?, 'editor')");
    foreach ($mems as $m) {
        $uid = (int) $m['user_id'];
        if ($uid === (int) $_SESSION['user_id'])
            continue;
        $ins->bind_param('ii', $board_id, $uid);
        $ins->execute();
    }
}

// 4) Crear columnas por defecto
$cols = [['Pendiente', 1], ['En curso', 2], ['Hecho', 3]];
$stmt = $conn->prepare("INSERT INTO columns (board_id, nombre, orden) VALUES (?, ?, ?)");
foreach ($cols as [$n, $o]) {
    $stmt->bind_param('isi', $board_id, $n, $o);
    $stmt->execute();
}

header('Location: view.php?id=' . $board_id);
exit;
