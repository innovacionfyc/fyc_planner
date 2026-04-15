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
    $_SESSION['flash'] = ['type' => 'err', 'msg' => 'No tienes permisos para duplicar este tablero.'];
    header('Location: ' . $RETURN_URL);
    exit;
}

// traer board
$bs = $conn->prepare("SELECT id, nombre, color_hex, team_id FROM boards WHERE id=? LIMIT 1");
$bs->bind_param('i', $board_id);
$bs->execute();
$board = $bs->get_result()->fetch_assoc();

if (!$board) {
    $_SESSION['flash'] = ['type' => 'err', 'msg' => 'Tablero no encontrado.'];
    header('Location: ' . $RETURN_URL);
    exit;
}

try {
    $conn->begin_transaction();

    $newName = 'Copia — ' . (string) $board['nombre'];
    $color = $board['color_hex'] ?: '#d32f57';
    $team_id = $board['team_id']; // puede ser null

    // ===== Detectar columnas en boards =====
    $cols = [];
    $rc = $conn->query("SHOW COLUMNS FROM boards");
    while ($rc && ($c = $rc->fetch_assoc())) {
        $cols[strtolower($c['Field'])] = true;
    }

    // ===== Insert nuevo board (incluye owner_user_id si existe) =====
    $fields = [];
    $ph = [];
    $types = '';
    $vals = [];

    if (isset($cols['nombre'])) {
        $fields[] = 'nombre';
        $ph[] = '?';
        $types .= 's';
        $vals[] = $newName;
    }

    if (isset($cols['color_hex'])) {
        $fields[] = 'color_hex';
        $ph[] = '?';
        $types .= 's';
        $vals[] = $color;
    }

    if (isset($cols['team_id'])) {
        $fields[] = 'team_id';
        $ph[] = '?';
        $types .= 'i';
        $vals[] = ($team_id === null) ? null : (int) $team_id;
    }

    // ✅ owner_user_id si existe (evita error "no default value")
    if (isset($cols['owner_user_id'])) {
        $fields[] = 'owner_user_id';
        $ph[] = '?';
        $types .= 'i';
        $vals[] = $user_id;
    }

    // (Compat) created_by si existe
    if (isset($cols['created_by'])) {
        $fields[] = 'created_by';
        $ph[] = '?';
        $types .= 'i';
        $vals[] = $user_id;
    }

    if (!$fields) {
        throw new Exception("No se detectaron columnas insertables en boards.");
    }

    $sqlIns = "INSERT INTO boards (" . implode(',', $fields) . ") VALUES (" . implode(',', $ph) . ")";
    $ins = $conn->prepare($sqlIns);

    $refs = [];
    $refs[] = $types;
    foreach ($vals as $k => $v) {
        $refs[] = &$vals[$k];
    }
    call_user_func_array([$ins, 'bind_param'], $refs);
    $ins->execute();

    $new_board_id = (int) $ins->insert_id;

    // ===== copiar miembros del board =====
    $m = $conn->prepare("SELECT user_id, rol FROM board_members WHERE board_id=?");
    $m->bind_param('i', $board_id);
    $m->execute();
    $members = $m->get_result()->fetch_all(MYSQLI_ASSOC);

    $insM = $conn->prepare("INSERT INTO board_members (board_id, user_id, rol) VALUES (?,?,?)");
    foreach ($members as $mm) {
        $uid = (int) $mm['user_id'];
        $rol = (string) $mm['rol'];
        $insM->bind_param('iis', $new_board_id, $uid, $rol);
        $insM->execute();
    }

    // ===== copiar columnas y mapear ids =====
    $c = $conn->prepare("SELECT id, nombre, orden FROM columns WHERE board_id=? ORDER BY orden ASC");
    $c->bind_param('i', $board_id);
    $c->execute();
    $colsOld = $c->get_result()->fetch_all(MYSQLI_ASSOC);

    $map = []; // old_col_id => new_col_id
    $insC = $conn->prepare("INSERT INTO columns (board_id, nombre, orden) VALUES (?,?,?)");
    foreach ($colsOld as $co) {
        $oldId = (int) $co['id'];
        $name = (string) $co['nombre'];
        $ord = (int) $co['orden'];
        $insC->bind_param('isi', $new_board_id, $name, $ord);
        $insC->execute();
        $map[$oldId] = (int) $insC->insert_id;
    }

    // ===== copiar tareas =====
    $taskCols = [];
    $rt = $conn->query("SHOW COLUMNS FROM tasks");
    while ($rt && ($tc = $rt->fetch_assoc())) {
        $taskCols[strtolower($tc['Field'])] = true;
    }

    $useAssignee = isset($taskCols['assignee_id']);

    // ✅ Pre-cargar IDs válidos de users para no violar FK al copiar assignee_id
    $validUserIds = [];
    if ($useAssignee) {
        $ids = [];

        $rq = $conn->prepare("SELECT DISTINCT assignee_id FROM tasks WHERE board_id=? AND assignee_id IS NOT NULL AND assignee_id > 0");
        $rq->bind_param('i', $board_id);
        $rq->execute();
        $rows = $rq->get_result()->fetch_all(MYSQLI_ASSOC);

        foreach ($rows as $rr) {
            $ids[] = (int) $rr['assignee_id'];
        }

        if (count($ids) > 0) {
            $place = implode(',', array_fill(0, count($ids), '?'));
            $typesU = str_repeat('i', count($ids));

            $sqlU = "SELECT id FROM users WHERE id IN ($place)";
            $stU = $conn->prepare($sqlU);

            $refsU = [];
            $refsU[] = $typesU;
            foreach ($ids as $k => $v) {
                $refsU[] = &$ids[$k];
            }
            call_user_func_array([$stU, 'bind_param'], $refsU);
            $stU->execute();

            $ok = $stU->get_result()->fetch_all(MYSQLI_ASSOC);
            foreach ($ok as $u) {
                $validUserIds[(int) $u['id']] = true;
            }
        }
    }

    $selectFields = ["id", "column_id"];
    if (isset($taskCols['titulo']))
        $selectFields[] = "titulo";
    if (!isset($taskCols['titulo']) && isset($taskCols['title']))
        $selectFields[] = "title";
    if (isset($taskCols['prioridad']))
        $selectFields[] = "prioridad";
    if (isset($taskCols['fecha_limite']))
        $selectFields[] = "fecha_limite";
    if ($useAssignee)
        $selectFields[] = "assignee_id";
    if (isset($taskCols['descripcion_md']))
        $selectFields[] = "descripcion_md";

    $q = "SELECT " . implode(',', $selectFields) . " FROM tasks WHERE board_id=?";
    $ts = $conn->prepare($q);
    $ts->bind_param('i', $board_id);
    $ts->execute();
    $tasks = $ts->get_result()->fetch_all(MYSQLI_ASSOC);

    $fieldsI = [];
    $phI = [];
    $typesI = '';

    $fieldsI[] = 'board_id';
    $phI[] = '?';
    $typesI .= 'i';
    $fieldsI[] = 'column_id';
    $phI[] = '?';
    $typesI .= 'i';

    $useTitulo = isset($taskCols['titulo']);
    $useTitle = (!$useTitulo && isset($taskCols['title']));

    if ($useTitulo) {
        $fieldsI[] = 'titulo';
        $phI[] = '?';
        $typesI .= 's';
    }
    if ($useTitle) {
        $fieldsI[] = 'title';
        $phI[] = '?';
        $typesI .= 's';
    }

    if (isset($taskCols['prioridad'])) {
        $fieldsI[] = 'prioridad';
        $phI[] = '?';
        $typesI .= 's';
    }
    if (isset($taskCols['fecha_limite'])) {
        $fieldsI[] = 'fecha_limite';
        $phI[] = '?';
        $typesI .= 's';
    }
    if ($useAssignee) {
        $fieldsI[] = 'assignee_id';
        $phI[] = '?';
        $typesI .= 'i';
    }
    if (isset($taskCols['descripcion_md'])) {
        $fieldsI[] = 'descripcion_md';
        $phI[] = '?';
        $typesI .= 's';
    }

    $sqlTaskIns = "INSERT INTO tasks (" . implode(',', $fieldsI) . ") VALUES (" . implode(',', $phI) . ")";
    $insT = $conn->prepare($sqlTaskIns);

    foreach ($tasks as $t) {
        $newCol = $map[(int) ($t['column_id'] ?? 0)] ?? null;
        if (!$newCol)
            continue;

        $valsT = [];
        $valsT[] = $new_board_id;
        $valsT[] = $newCol;

        $titleVal = $useTitulo ? ($t['titulo'] ?? '') : ($t['title'] ?? '');
        $valsT[] = (string) $titleVal;

        if (isset($taskCols['prioridad']))
            $valsT[] = (string) ($t['prioridad'] ?? 'med');
        if (isset($taskCols['fecha_limite']))
            $valsT[] = ($t['fecha_limite'] ?? null);

        if ($useAssignee) {
            $aid = (int) ($t['assignee_id'] ?? 0);
            // ✅ si no existe en users, lo dejamos NULL para no romper FK
            if ($aid > 0 && isset($validUserIds[$aid]))
                $valsT[] = $aid;
            else
                $valsT[] = null;
        }

        if (isset($taskCols['descripcion_md']))
            $valsT[] = (string) ($t['descripcion_md'] ?? '');

        $refsT = [];
        $refsT[] = $typesI;
        foreach ($valsT as $k => $v) {
            $refsT[] = &$valsT[$k];
        }
        call_user_func_array([$insT, 'bind_param'], $refsT);
        $insT->execute();
    }

    $conn->commit();

    $_SESSION['flash'] = ['type' => 'ok', 'msg' => 'Tablero duplicado correctamente.'];
    header('Location: ' . $RETURN_URL);
    exit;

} catch (Throwable $e) {
    try {
        $conn->rollback();
    } catch (Throwable $x) {
    }

    $_SESSION['flash'] = ['type' => 'err', 'msg' => 'No pude duplicar: ' . $e->getMessage()];
    header('Location: ' . $RETURN_URL);
    exit;
}