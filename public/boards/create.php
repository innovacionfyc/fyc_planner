<?php
// public/boards/create.php
require_once __DIR__ . '/../_auth.php';
require_login();
require_once __DIR__ . '/../../config/db.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

/**
 * Decide a dónde redirigir al final.
 * Si viene ?return=workspace => workspace.php
 * Si no => index.php
 */
function goBack()
{
    $ret = '';
    if (isset($_GET['return']))
        $ret = (string) $_GET['return'];
    if ($ret === '' && isset($_POST['return']))
        $ret = (string) $_POST['return'];

    if ($ret === 'workspace') {
        header('Location: workspace.php');
        exit;
    }

    header('Location: index.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    goBack();
}

// CSRF
if (!isset($_POST['csrf'], $_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], $_POST['csrf'])) {
    $_SESSION['flash'] = ['type' => 'err', 'msg' => 'Token CSRF inválido. Intenta de nuevo.'];
    goBack();
}

$userId = (int) ($_SESSION['user_id'] ?? 0);
$nombre = trim($_POST['nombre'] ?? '');
$color_hex = trim($_POST['color_hex'] ?? '#d32f57');
$team_raw = trim($_POST['team_id'] ?? '');
$team_id = ($team_raw === '') ? null : (int) $team_raw;

// Validaciones básicas
if ($userId <= 0 || $nombre === '') {
    $_SESSION['flash'] = ['type' => 'err', 'msg' => 'Datos incompletos para crear el tablero.'];
    goBack();
}

// Sanitizar color (hex #RRGGBB)
if (!preg_match('/^#[0-9a-fA-F]{6}$/', $color_hex)) {
    $color_hex = '#d32f57';
}

// Si viene team_id, validar que el usuario sea admin_equipo de ese team
if ($team_id !== null) {
    $chk = $conn->prepare("
        SELECT 1
        FROM team_members
        WHERE team_id = ? AND user_id = ? AND rol = 'admin_equipo'
        LIMIT 1
    ");
    $chk->bind_param('ii', $team_id, $userId);
    $chk->execute();
    if (!$chk->get_result()->fetch_row()) {
        $_SESSION['flash'] = ['type' => 'err', 'msg' => 'Solo un admin del equipo puede crear tableros en ese equipo.'];
        goBack();
    }
}

// Transacción: crear board + miembro propietario + columnas por defecto
$conn->begin_transaction();

try {
    // 1) Insert en boards
    if ($team_id === null) {
        $ins = $conn->prepare("INSERT INTO boards (nombre, color_hex, owner_user_id, team_id) VALUES (?,?,?,NULL)");
        $ins->bind_param('ssi', $nombre, $color_hex, $userId);
    } else {
        $ins = $conn->prepare("INSERT INTO boards (nombre, color_hex, owner_user_id, team_id) VALUES (?,?,?,?)");
        $ins->bind_param('ssii', $nombre, $color_hex, $userId, $team_id);
    }
    $ins->execute();
    $boardId = (int) $ins->insert_id;

    // 2) Insert en board_members como propietario (para que aparezca en el listado)
    $bm = $conn->prepare("INSERT INTO board_members (board_id, user_id, rol) VALUES (?,?, 'propietario')");
    $bm->bind_param('ii', $boardId, $userId);
    $bm->execute();

    // 3) Crear columnas por defecto si existe tabla columns
    $hasColumns = false;
    $t = $conn->query("SHOW TABLES LIKE 'columns'");
    if ($t && $t->fetch_row())
        $hasColumns = true;

    if ($hasColumns) {
        // Detectar columnas reales de la tabla columns
        $colFields = [];
        $rc = $conn->query("SHOW COLUMNS FROM columns");
        while ($rc && ($r = $rc->fetch_assoc())) {
            $colFields[$r['Field']] = true;
        }

        // Solo insertamos si existen board_id, nombre, orden (según tu esquema confirmado sí)
        if (isset($colFields['board_id'], $colFields['nombre'], $colFields['orden'])) {
            $defaults = [
                ['Por hacer', 1],
                ['En proceso', 2],
                ['Hecho', 3],
            ];
            $cins = $conn->prepare("INSERT INTO columns (board_id, nombre, orden) VALUES (?,?,?)");
            foreach ($defaults as [$cn, $ord]) {
                $cins->bind_param('isi', $boardId, $cn, $ord);
                $cins->execute();
            }
        }
    }

    // 4) (Opcional) registrar evento si existe board_events
    $hasEvents = false;
    $te = $conn->query("SHOW TABLES LIKE 'board_events'");
    if ($te && $te->fetch_row())
        $hasEvents = true;

    if ($hasEvents) {
        // Insert mínimo compatible con tu esquema: board_id, kind, payload_json
        $payload = json_encode([
            'nombre' => $nombre,
            'color_hex' => $color_hex,
            'team_id' => $team_id,
        ], JSON_UNESCAPED_UNICODE);

        $evCols = [];
        $rc2 = $conn->query("SHOW COLUMNS FROM board_events");
        while ($rc2 && ($r = $rc2->fetch_assoc()))
            $evCols[$r['Field']] = true;

        $fields = [];
        $ph = [];
        $types = '';
        $vals = [];

        if (isset($evCols['board_id'])) {
            $fields[] = 'board_id';
            $ph[] = '?';
            $types .= 'i';
            $vals[] = $boardId;
        }
        if (isset($evCols['kind'])) {
            $fields[] = 'kind';
            $ph[] = '?';
            $types .= 's';
            $vals[] = 'board_created';
        }
        if (isset($evCols['payload_json'])) {
            $fields[] = 'payload_json';
            $ph[] = '?';
            $types .= 's';
            $vals[] = $payload;
        }

        if ($fields) {
            $sqlEv = "INSERT INTO board_events (" . implode(',', $fields) . ") VALUES (" . implode(',', $ph) . ")";
            $ev = $conn->prepare($sqlEv);

            // bind dinámico
            $refs = [];
            foreach ($vals as $k => &$v)
                $refs[$k] = &$v;
            array_unshift($refs, $types);
            call_user_func_array([$ev, 'bind_param'], $refs);

            $ev->execute();
        }
    }

    $conn->commit();
    $_SESSION['flash'] = ['type' => 'ok', 'msg' => 'Tablero creado correctamente.'];
    goBack();

} catch (Throwable $e) {
    $conn->rollback();
    $_SESSION['flash'] = ['type' => 'err', 'msg' => 'No se pudo crear el tablero: ' . $e->getMessage()];
    goBack();
}