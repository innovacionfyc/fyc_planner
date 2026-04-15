<?php
require_once __DIR__ . '/../_auth.php';
require_login();
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../_perm.php';
// ===== Return helper (workspace o index) =====
$return = $_GET['return'] ?? $_POST['return'] ?? '';
$return = strtolower(trim($return));

$RETURN_URL = ($return === 'workspace')
    ? './workspace.php'
    : './index.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . $RETURN_URL);
    exit;
}
if (!isset($_POST['csrf'], $_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], $_POST['csrf'])) {
    header('Location: ' . $RETURN_URL);
    exit;
}

$board_id = (int) ($_POST['board_id'] ?? 0);
$user_id = (int) ($_SESSION['user_id'] ?? 0);
if ($board_id <= 0 || $user_id <= 0) {
    header('Location: ' . $RETURN_URL);
    exit;
}

// Verificar permisos de administración (propietario, admin_equipo o super_admin)
if (!can_manage_board($conn, $board_id, $user_id)) {
    $_SESSION['flash'] = ['type' => 'err', 'msg' => 'No tienes permisos para archivar este tablero.'];
    header('Location: ' . $RETURN_URL);
    exit;
}

// detectar columna
$cols = [];
$rc = $conn->query("SHOW COLUMNS FROM boards");
while ($rc && ($c = $rc->fetch_assoc()))
    $cols[$c['Field']] = true;

try {
    if (isset($cols['archived_at'])) {
        $up = $conn->prepare("UPDATE boards SET archived_at = NOW() WHERE id=? LIMIT 1");
        $up->bind_param('i', $board_id);
        $up->execute();
        $_SESSION['flash'] = ['type' => 'ok', 'msg' => 'Tablero archivado.'];
    } elseif (isset($cols['is_archived'])) {
        $up = $conn->prepare("UPDATE boards SET is_archived = 1 WHERE id=? LIMIT 1");
        $up->bind_param('i', $board_id);
        $up->execute();
        $_SESSION['flash'] = ['type' => 'ok', 'msg' => 'Tablero archivado.'];
    } elseif (isset($cols['archived'])) {
        $up = $conn->prepare("UPDATE boards SET archived = 1 WHERE id=? LIMIT 1");
        $up->bind_param('i', $board_id);
        $up->execute();
        $_SESSION['flash'] = ['type' => 'ok', 'msg' => 'Tablero archivado.'];
    } else {
        $_SESSION['flash'] = ['type' => 'err', 'msg' => 'Tu BD aún no tiene columna para archivar (archived_at / is_archived).'];
    }
} catch (Throwable $e) {
    $_SESSION['flash'] = ['type' => 'err', 'msg' => 'No pude archivar: ' . $e->getMessage()];
}

header('Location: ' . $RETURN_URL);
exit;
