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
    $_SESSION['flash'] = ['type' => 'err', 'msg' => 'CSRF inválido.'];
    header('Location: ' . $RETURN_URL);
    exit;
}

$userId = (int) ($_SESSION['user_id'] ?? 0);
$boardId = (int) ($_POST['board_id'] ?? 0);

if ($boardId <= 0) {
    $_SESSION['flash'] = ['type' => 'err', 'msg' => 'Tablero inválido.'];
    header('Location: ' . $RETURN_URL);
    exit;
}

// Verificar permisos de administración (propietario, admin_equipo o super_admin)
if (!can_manage_board($conn, $boardId, $userId)) {
    $_SESSION['flash'] = ['type' => 'err', 'msg' => 'No tienes permisos para eliminar este tablero.'];
    header('Location: ' . $RETURN_URL);
    exit;
}

$conn->begin_transaction();
try {
    $del = $conn->prepare(
        "UPDATE boards SET deleted_at = NOW(), deleted_by = ? WHERE id = ? LIMIT 1"
    );
    $del->bind_param('ii', $userId, $boardId);
    $del->execute();

    $conn->commit();
    $_SESSION['flash'] = [
        'type' => 'ok',
        'msg'  => 'Tablero movido a la papelera. Se eliminará definitivamente en 30 días.'
    ];
} catch (Throwable $e) {
    $conn->rollback();
    $_SESSION['flash'] = ['type' => 'err', 'msg' => 'No se pudo mover el tablero a la papelera.'];
}

header('Location: ' . $RETURN_URL);
exit;
