<?php
require_once __DIR__ . '/../_auth.php';
require_login();
require_once __DIR__ . '/../../config/db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php');
    exit;
}
if (!isset($_POST['csrf'], $_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], $_POST['csrf'])) {
    header('Location: index.php');
    exit;
}

$board_id = (int) ($_POST['board_id'] ?? 0);
$user_id = (int) ($_SESSION['user_id'] ?? 0);
if ($board_id <= 0 || $user_id <= 0) {
    header('Location: index.php');
    exit;
}

// solo propietario
$chk = $conn->prepare("SELECT rol FROM board_members WHERE board_id=? AND user_id=? LIMIT 1");
$chk->bind_param('ii', $board_id, $user_id);
$chk->execute();
$row = $chk->get_result()->fetch_assoc();
if (!$row || ($row['rol'] ?? '') !== 'propietario') {
    $_SESSION['flash'] = ['type' => 'err', 'msg' => 'Solo el propietario puede duplicar.'];
    header('Location: index.php');
    exit;
}

// traer board
$bs = $conn->prepare("SELECT id, nombre, color_hex, team_id FROM boards WHERE id=? LIMIT 1");
$bs->bind_param('i', $board_id);
$bs->execute();
$board = $bs->get_result()->fetch_assoc();
if (!$board) {
    $_SESSION['flash'] = ['type' => 'err', 'msg' => 'Tablero no encontrado.'];
    header('Location:index.php');
    exit;
}

try {
    $conn->begin_transaction();

    $newName = 'Copia — ' . $board['nombre'];
    $color = $board['color_hex'] ?: '#d32f57';
    $team_id = $board['team_id']; // puede ser null

    // insertar nuevo board (compat)
    $cols = [];
    $rc = $conn->query("SHOW COLUMNS FROM boards");
    while ($rc && ($c = $rc->fetch_assoc()))
        $cols[$c['Field']] = true;

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
        $vals[] = (int) $team_id;
    }

    // si team_id es NULL, toca bind como i igual pero mandando 0 no sirve. Entonces: insert diferente
    // Solución simple: dos casos.
    if (isset($cols['team_id']) && $team_id === null) {
        // insert sin team_id
        $fields = array_values(array_filter($fields, fn($f) => $f !== 'team_id'));
        $ph = array_values(array_filter($ph, fn($x) => true)); // recalculamos abajo
        $types = '';
        $vals = [];

        if (isset($cols['nombre'])) {
            $types .= 's';
            $vals[] = $newName;
        }
        if (isset($cols['color_hex'])) {
            $types .= 's';
            $vals[] = $color;
        }

        $sqlIns = "INSERT INTO boards (" . implode(',', $fields) . ") VALUES (" . implode(',', array_fill(0, count($fields), '?')) . ")";
    } else {
        $sqlIns = "INSERT INTO boards (" . implode(',', $fields) . ") VALUES (" . implode(',', $ph) . ")";
    }

    $ins = $conn->prepare($sqlIns);
    $refs = [];
    $refs[] = $types;
    foreach ($vals as $k => $v) {
        $refs[] = &$vals[$k];
    }
    if ($types !== '')
        call_user_func_array([$ins, 'bind_param'], $refs);
    $ins->execute();

    $new_board_id = (int) $ins->insert_id;

    // copiar miembros del board
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

    // copiar columnas y mapear ids
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

    // copiar tareas (solo columnas comunes)
    $taskCols = [];
    $rt = $conn->query("SHOW COLUMNS FROM tasks");
    while ($rt && ($tc = $rt->fetch_assoc()))
        $taskCols[$tc['Field']] = true;

    $selectFields = ["id", "column_id"];
    if (isset($taskCols['titulo']))
        $selectFields[] = "titulo";
    if (isset($taskCols['title']))
        $selectFields[] = "title";
    if (isset($taskCols['prioridad']))
        $selectFields[] = "prioridad";
    if (isset($taskCols['fecha_limite']))
        $selectFields[] = "fecha_limite";
    if (isset($taskCols['assignee_id']))
        $selectFields[] = "assignee_id";
    if (isset($taskCols['descripcion_md']))
        $selectFields[] = "descripcion_md";

    $q = "SELECT " . implode(',', $selectFields) . " FROM tasks WHERE board_id=?";
    $ts = $conn->prepare($q);
    $ts->bind_param('i', $board_id);
    $ts->execute();
    $tasks = $ts->get_result()->fetch_all(MYSQLI_ASSOC);

    // armar insert dinámico
    $fieldsI = [];
    $phI = [];
    $typesI = ''; // base
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
    if (isset($taskCols['assignee_id'])) {
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
        $newCol = $map[(int) $t['column_id']] ?? null;
        if (!$newCol)
            continue;

        $vals = [];
        $vals[] = $new_board_id;
        $vals[] = $newCol;

        $titleVal = $useTitulo ? ($t['titulo'] ?? '') : ($t['title'] ?? '');
        $vals[] = (string) $titleVal;

        if (isset($taskCols['prioridad']))
            $vals[] = (string) ($t['prioridad'] ?? 'med');

        if (isset($taskCols['fecha_limite'])) {
            $vals[] = ($t['fecha_limite'] ?? null) ? (string) $t['fecha_limite'] : null;
        }

        if (isset($taskCols['assignee_id']))
            $vals[] = (int) ($t['assignee_id'] ?? 0);

        if (isset($taskCols['descripcion_md']))
            $vals[] = (string) ($t['descripcion_md'] ?? '');

        // bind dinámico
        $refs = [];
        $refs[] = $typesI;
        foreach ($vals as $k => $v) {
            $refs[] = &$vals[$k];
        }
        call_user_func_array([$insT, 'bind_param'], $refs);
        $insT->execute();
    }

    $conn->commit();

    $_SESSION['flash'] = ['type' => 'ok', 'msg' => 'Tablero duplicado correctamente.'];
    header('Location: view.php?id=' . $new_board_id);
    exit;

} catch (Throwable $e) {
    try {
        $conn->rollback();
    } catch (Throwable $x) {
    }
    $_SESSION['flash'] = ['type' => 'err', 'msg' => 'No pude duplicar: ' . $e->getMessage()];
    header('Location: index.php');
    exit;
}