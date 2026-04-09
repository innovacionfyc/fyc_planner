<?php
$EMBED = isset($_GET['embed']) && $_GET['embed'] == '1';

// Vista standalone retirada: redirigir a workspace como experiencia principal.
// El modo embed (view.php?embed=1) sigue funcionando con normalidad.
if (!$EMBED) {
    $bid = isset($_GET['id']) ? (int) $_GET['id'] : 0;
    $loc = $bid > 0 ? '../boards/workspace.php?board=' . $bid : '../boards/workspace.php';
    header('Location: ' . $loc);
    exit;
}

require_once __DIR__ . '/../_auth.php';
require_login();
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../_i18n.php';

function h($s)
{
    return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
}

$board_id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
if ($board_id <= 0) {
    header('Location: index.php');
    exit;
}

// Paso 1: obtener datos del tablero sin filtro de acceso
$sql = "SELECT b.id, b.nombre, b.color_hex, b.team_id, t.nombre AS team_nombre
        FROM boards b LEFT JOIN teams t ON t.id = b.team_id
        WHERE b.id = ? LIMIT 1";
$stmt = $conn->prepare($sql);
$stmt->bind_param('i', $board_id);
$stmt->execute();
$board = $stmt->get_result()->fetch_assoc();

// Paso 2: validar acceso con la regla equipo/personal/super_admin
require_once __DIR__ . '/../_perm.php';
if (!$board || !has_board_access($conn, $board_id, (int)$_SESSION['user_id'])) {
    header('Location: index.php');
    exit;
}

if (empty($_SESSION['csrf']))
    $_SESSION['csrf'] = bin2hex(random_bytes(32));

$stmt = $conn->prepare("SELECT id, nombre, orden FROM columns WHERE board_id = ? ORDER BY orden ASC");
$stmt->bind_param('i', $board_id);
$stmt->execute();
$columns = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

$mm = $conn->prepare("SELECT u.id, u.nombre FROM board_members bm JOIN users u ON u.id = bm.user_id WHERE bm.board_id = ? ORDER BY u.nombre ASC");
$mm->bind_param('i', $board_id);
$mm->execute();
$board_members = $mm->get_result()->fetch_all(MYSQLI_ASSOC);

// Gestión de miembros: roles, permisos y candidatos
$members_with_roles = [];
$mwr = $conn->prepare(
    "SELECT u.id, u.nombre, bm.rol
     FROM board_members bm
     JOIN users u ON u.id = bm.user_id
     WHERE bm.board_id = ?
     ORDER BY u.nombre ASC"
);
if ($mwr) {
    $mwr->bind_param('i', $board_id);
    $mwr->execute();
    $members_with_roles = $mwr->get_result()->fetch_all(MYSQLI_ASSOC);
}

$canManage = can_manage_board($conn, $board_id, (int) $_SESSION['user_id']);

$propietarioCount = 0;
foreach ($members_with_roles as $_m) {
    if ($_m['rol'] === 'propietario') $propietarioCount++;
}

// Candidatos a agregar (usuarios activos que aún no son miembros)
$candidates = [];
if ($canManage) {
    $existingIds = array_map('intval', array_column($members_with_roles, 'id'));
    if (!empty($board['team_id'])) {
        $cq = $conn->prepare(
            "SELECT u.id, u.nombre
             FROM users u
             JOIN team_members tm ON tm.user_id = u.id
             WHERE tm.team_id = ? AND u.estado = 'aprobado' AND u.activo = 1
             ORDER BY u.nombre ASC"
        );
        if ($cq) { $cq->bind_param('i', $board['team_id']); $cq->execute(); $allCand = $cq->get_result()->fetch_all(MYSQLI_ASSOC); }
    } else {
        $cq = $conn->prepare(
            "SELECT id, nombre FROM users WHERE estado = 'aprobado' AND activo = 1 ORDER BY nombre ASC"
        );
        if ($cq) { $cq->execute(); $allCand = $cq->get_result()->fetch_all(MYSQLI_ASSOC); }
    }
    foreach (($allCand ?? []) as $c) {
        if (!in_array((int) $c['id'], $existingIds, true)) {
            $candidates[] = $c;
        }
    }
}

// Tags del tablero (para la barra de filtros)
$boardTags = [];
$hasTags = false;
$tt = $conn->query("SHOW TABLES LIKE 'task_tags'");
if ($tt && $tt->fetch_row())
    $hasTags = true;
if ($hasTags) {
    $tg = $conn->prepare("SELECT id, nombre, color_hex FROM task_tags WHERE board_id=? ORDER BY nombre ASC");
    if ($tg) {
        $tg->bind_param('i', $board_id);
        $tg->execute();
        $boardTags = $tg->get_result()->fetch_all(MYSQLI_ASSOC);
    }
}

// Tags por tarea (mapa task_id => array de tags)
$taskTagsMap = [];
if ($hasTags) {
    $tpm = $conn->prepare("SELECT ttp.task_id, tt.id AS tag_id, tt.nombre, tt.color_hex
                           FROM task_tag_pivot ttp
                           JOIN task_tags tt ON tt.id = ttp.tag_id
                           WHERE tt.board_id = ?");
    if ($tpm) {
        $tpm->bind_param('i', $board_id);
        $tpm->execute();
        $rows = $tpm->get_result()->fetch_all(MYSQLI_ASSOC);
        foreach ($rows as $r) {
            $taskTagsMap[(int) $r['task_id']][] = ['id' => (int) $r['tag_id'], 'nombre' => $r['nombre'], 'color_hex' => $r['color_hex']];
        }
    }
}

function get_tasks_by_column($conn, $board_id, $column_id)
{
    static $orderMode = null, $timeCol = null;
    if ($orderMode === null) {
        $orderMode = 'time';
        $timeCol = 'id';
        $check = $conn->query("SHOW COLUMNS FROM tasks");
        $cols = [];
        if ($check) {
            while ($row = $check->fetch_assoc())
                $cols[$row['Field']] = true;
        }
        if (isset($cols['sort_order'])) {
            $orderMode = 'sort';
        } else {
            if (isset($cols['creado_en']))
                $timeCol = 'creado_en';
            elseif (isset($cols['created_at']))
                $timeCol = 'created_at';
        }
    }
    $sql = ($orderMode === 'sort')
        ? "SELECT t.id, t.titulo, t.prioridad, t.fecha_limite, t.assignee_id, u.nombre AS asignado_nombre FROM tasks t LEFT JOIN users u ON u.id = t.assignee_id WHERE t.board_id=? AND t.column_id=? ORDER BY t.sort_order ASC, t.id ASC"
        : "SELECT t.id, t.titulo, t.prioridad, t.fecha_limite, t.assignee_id, u.nombre AS asignado_nombre FROM tasks t LEFT JOIN users u ON u.id = t.assignee_id WHERE t.board_id=? AND t.column_id=? ORDER BY t.$timeCol DESC";
    $s = $conn->prepare($sql);
    if (!$s)
        return [];
    $s->bind_param('ii', $board_id, $column_id);
    if (!$s->execute())
        return [];
    $res = $s->get_result();
    return $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
}

function due_meta($dateStr)
{
    if (!$dateStr)
        return null;
    try {
        $today = new DateTime('today');
        $d = new DateTime($dateStr);
        $days = (int) $today->diff($d)->format('%r%a');
        $state = ($days < 0) ? 'overdue' : (($days <= 2) ? 'soon' : 'ok');
        return ['label' => $d->format('d/m/Y'), 'state' => $state];
    } catch (Throwable $e) {
        return null;
    }
}

function prio_class($prio)
{
    switch ($prio) {
        case 'urgent':
            return 'fyc-badge fyc-badge-urgent';
        case 'high':
            return 'fyc-badge fyc-badge-high';
        case 'low':
            return 'fyc-badge fyc-badge-low';
        default:
            return 'fyc-badge fyc-badge-med';
    }
}
?>
<?php if (!$EMBED): ?>
    <!doctype html>
    <html lang="es" data-theme="dark">

    <head>
        <meta charset="utf-8">
        <title><?= h($board['nombre']) ?> — F&amp;C Planner</title>
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <link rel="stylesheet" href="../assets/app.css?v=6">
        <link rel="stylesheet" href="../assets/theme.css">
        <script>(function () { var t = localStorage.getItem('fyc-theme') || 'dark'; document.documentElement.setAttribute('data-theme', t); })(); window.FCPlannerCurrentUserName = <?= json_encode($_SESSION['user_nombre'] ?? 'Usuario') ?>;</script>
        <script src="../assets/board-view.js?v=2" defer></script>
    </head>

    <body style="background:var(--bg-app);color:var(--text-primary);min-height:100vh;">
    <?php endif; ?>

    <div style="<?= $EMBED ? 'padding:0;' : 'max-width:1400px;margin:0 auto;padding:24px 16px;' ?>">

        <?php if (!$EMBED): ?>
            <div
                style="display:flex;flex-wrap:wrap;gap:12px;align-items:flex-start;justify-content:space-between;margin-bottom:16px;">
                <div>
                    <h1
                        style="font-family:'Sora',sans-serif;font-size:24px;font-weight:800;color:var(--fyc-red);margin:0;letter-spacing:-0.5px;">
                        <?= h($board['nombre']) ?>
                        <?php if (!empty($board['team_id'])): ?>
                            <span
                                style="margin-left:8px;display:inline-flex;align-items:center;border:1px solid var(--border-main);background:var(--bg-surface);padding:3px 10px;border-radius:999px;font-size:11px;font-weight:700;color:var(--text-muted);"><?= h($board['team_nombre'] ?? '—') ?></span>
                        <?php endif; ?>
                    </h1>
                    <div style="margin-top:6px;font-size:12px;color:var(--text-ghost);">Arrastra tareas · Doble clic para
                        renombrar · ⋯ para opciones de columna</div>
                </div>
                <div style="display:flex;gap:8px;align-items:center;">
                    <div id="presence" style="display:flex;gap:4px;"></div>
                    <?php if ($canManage): ?>
                        <button type="button" id="btnOpenMembersModal" class="fyc-btn fyc-btn-ghost"
                            style="font-size:12px;">👥 Miembros (<?= count($members_with_roles) ?>)</button>
                    <?php endif; ?>
                    <a href="index.php" class="fyc-btn fyc-btn-ghost" style="text-decoration:none;font-size:12px;">←
                        Tableros</a>
                    <a href="../logout.php" class="fyc-btn fyc-btn-danger"
                        style="text-decoration:none;font-size:12px;">Salir</a>
                </div>
            </div>
        <?php endif; ?>

        <!-- ============================================================
     BARRA DE FILTROS Y BÚSQUEDA
============================================================ -->
        <div id="filterBar" style="display:flex;flex-wrap:wrap;gap:8px;align-items:center;padding:10px 0 14px;">

            <!-- Búsqueda -->
            <div style="position:relative;flex-shrink:0;">
                <svg viewBox="0 0 24 24" fill="none"
                    style="position:absolute;left:10px;top:50%;transform:translateY(-50%);width:14px;height:14px;pointer-events:none;"
                    stroke="var(--text-ghost)" stroke-width="2">
                    <circle cx="11" cy="11" r="7" />
                    <path d="M21 21l-4.35-4.35" />
                </svg>
                <input type="text" id="filterSearch" placeholder="Buscar tareas..."
                    style="padding:6px 10px 6px 30px;border-radius:9px;border:1px solid var(--border-accent);background:var(--bg-input);color:var(--text-primary);font-size:12px;font-family:'DM Sans',sans-serif;outline:none;width:180px;transition:border-color .15s;"
                    onfocus="this.style.borderColor='var(--fyc-red)'"
                    onblur="this.style.borderColor='var(--border-accent)'">
            </div>

            <!-- Separador -->
            <div style="width:1px;height:20px;background:var(--border-main);flex-shrink:0;"></div>

            <!-- Filtro prioridad -->
            <div style="display:flex;gap:4px;align-items:center;">
                <span
                    style="font-size:10px;font-weight:700;color:var(--text-ghost);text-transform:uppercase;letter-spacing:0.8px;white-space:nowrap;">Prioridad:</span>
                <?php foreach (['urgent' => 'Urgente', 'high' => 'Alta', 'med' => 'Media', 'low' => 'Baja'] as $pv => $pl): ?>
                    <button type="button" class="filter-prio-btn fyc-badge" data-prio="<?= $pv ?>"
                        style="cursor:pointer;border:1.5px solid transparent;opacity:.5;transition:opacity .15s,border-color .15s;"
                        data-cls="fyc-badge-<?= $pv === 'med' ? 'med' : ($pv === 'low' ? 'low' : ($pv === 'high' ? 'high' : 'urgent')) ?>">
                        <?= $pl ?>
                    </button>
                <?php endforeach; ?>
            </div>

            <!-- Separador -->
            <div style="width:1px;height:20px;background:var(--border-main);flex-shrink:0;"></div>

            <!-- Filtro responsable -->
            <div style="display:flex;gap:6px;align-items:center;">
                <span
                    style="font-size:10px;font-weight:700;color:var(--text-ghost);text-transform:uppercase;letter-spacing:0.8px;white-space:nowrap;">Responsable:</span>
                <select id="filterAssignee"
                    style="padding:4px 8px;border-radius:8px;border:1px solid var(--border-accent);background:var(--bg-input);color:var(--text-muted);font-size:11px;font-family:'DM Sans',sans-serif;outline:none;cursor:pointer;">
                    <option value="">Todos</option>
                    <?php foreach ($board_members as $m): ?>
                        <option value="<?= (int) $m['id'] ?>"><?= h($m['nombre']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- Filtro tags (si hay tags) -->
            <?php if ($boardTags): ?>
                <div style="width:1px;height:20px;background:var(--border-main);flex-shrink:0;"></div>
                <div style="display:flex;gap:4px;align-items:center;flex-wrap:wrap;">
                    <span
                        style="font-size:10px;font-weight:700;color:var(--text-ghost);text-transform:uppercase;letter-spacing:0.8px;white-space:nowrap;">Tags:</span>
                    <?php foreach ($boardTags as $tag): ?>
                        <button type="button" class="filter-tag-btn" data-tag-id="<?= (int) $tag['id'] ?>"
                            style="display:inline-flex;align-items:center;gap:4px;padding:2px 8px;border-radius:999px;font-size:10px;font-weight:700;cursor:pointer;border:1.5px solid <?= h($tag['color_hex']) ?>;background:var(--bg-hover);color:var(--text-muted);opacity:.55;transition:all .15s;">
                            <span
                                style="width:6px;height:6px;border-radius:50%;background:<?= h($tag['color_hex']) ?>;display:inline-block;"></span>
                            <?= h($tag['nombre']) ?>
                        </button>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <!-- Limpiar filtros -->
            <button type="button" id="btnClearFilters"
                style="display:none;margin-left:auto;padding:5px 12px;border-radius:8px;border:1px solid var(--border-accent);background:transparent;color:var(--text-ghost);font-size:11px;font-weight:600;cursor:pointer;font-family:'DM Sans',sans-serif;transition:color .15s;"
                onmouseover="this.style.color='var(--fyc-red)'" onmouseout="this.style.color='var(--text-ghost)'">
                ✕ Limpiar filtros
            </button>

            <!-- Contador resultados -->
            <div id="filterCount" style="display:none;font-size:11px;font-weight:600;color:var(--text-ghost);"></div>
        </div>

        <!-- KANBAN -->
        <div style="overflow-x:auto;<?= $EMBED ? '' : 'margin-top:0;' ?>">
            <div class="kanban" id="kanban"
                style="display:flex;gap:14px;min-width:max-content;align-items:flex-start;padding:<?= $EMBED ? '12px' : '2px 0 16px' ?>;"
                data-board-id="<?= (int) $board_id ?>" data-csrf="<?= h($_SESSION['csrf']) ?>"
                data-embed="<?= $EMBED ? '1' : '0' ?>">

                <?php foreach ($columns as $c): ?>
                    <?php $tasks = get_tasks_by_column($conn, $board_id, (int) $c['id']);
                    $count = count($tasks); ?>
                    <div class="col fyc-col" data-column-id="<?= (int) $c['id'] ?>">
                        <div class="fyc-col-header">
                            <span class="fyc-col-name"><?= h($c['nombre']) ?></span>
                            <div class="fyc-col-controls">
                                <span class="fyc-col-count cnt"
                                    style="background:var(--col-cnt-todo-bg);color:var(--col-cnt-todo-tx);"><?= (int) $count ?></span>
                                <button type="button" class="fyc-col-menu-btn" data-action="col-menu"
                                    data-column-id="<?= (int) $c['id'] ?>" data-column-name="<?= h($c['nombre']) ?>"
                                    title="Opciones de columna">⋯</button>
                            </div>
                        </div>
                        <div style="padding:8px 8px 4px;">
                            <form method="post" action="../tasks/create.php">
                                <input type="hidden" name="csrf" value="<?= h($_SESSION['csrf']) ?>">
                                <input type="hidden" name="board_id" value="<?= (int) $board_id ?>">
                                <input type="hidden" name="column_id" value="<?= (int) $c['id'] ?>">
                                <div style="display:flex;gap:6px;">
                                    <input type="text" name="titulo" required placeholder="Nueva tarea..."
                                        class="fyc-col-add-input" style="flex:1;">
                                    <button type="submit"
                                        style="width:32px;height:32px;border-radius:9px;background:var(--fyc-red);color:#fff;border:none;font-size:18px;font-weight:700;cursor:pointer;display:flex;align-items:center;justify-content:center;flex-shrink:0;transition:opacity .15s;"
                                        onmouseover="this.style.opacity='.8'" onmouseout="this.style.opacity='1'">+</button>
                                </div>
                            </form>
                        </div>
                        <div class="tasks fyc-col-body">
                            <?php if (!$tasks): ?>
                                <div class="empty" style="font-size:12px;color:var(--text-ghost);padding:4px 2px;">No hay tareas
                                    aún.</div>
                            <?php else:
                                foreach ($tasks as $t):
                                    $prio = $t['prioridad'] ?? 'med';
                                    $due = !empty($t['fecha_limite']) ? due_meta($t['fecha_limite']) : null;
                                    $asig = trim((string) ($t['asignado_nombre'] ?? ''));
                                    $asig_init = $asig ? strtoupper(mb_substr($asig, 0, 2)) : '';
                                    $tTags = $taskTagsMap[(int) $t['id']] ?? [];
                                    // data-tags: JSON array de tag IDs para filtrar desde JS
                                    $tagIds = array_map(function ($tg) {
                                        return (int) $tg['id']; }, $tTags);
                                    ?>
                                    <div class="task fyc-card" data-task-id="<?= (int) $t['id'] ?>"
                                        data-titulo="<?= h($t['titulo'] ?? '') ?>" data-prioridad="<?= h($prio) ?>"
                                        data-fecha="<?= !empty($t['fecha_limite']) ? h(substr((string) $t['fecha_limite'], 0, 10)) : '' ?>"
                                        data-assignee="<?= !empty($t['assignee_id']) ? (int) $t['assignee_id'] : '' ?>"
                                        data-tags="<?= h(json_encode($tagIds)) ?>" draggable="true"
                                        title="Arrastra · Doble clic para renombrar">

                                        <div style="display:flex;align-items:flex-start;gap:6px;margin-bottom:8px;">
                                            <div class="task-title fyc-card-title" style="flex:1;padding-right:4px;">
                                                <?= h($t['titulo']) ?></div>
                                            <div style="display:flex;gap:4px;flex-shrink:0;">
                                                <button type="button" draggable="false"
                                                    style="width:26px;height:26px;border-radius:7px;border:1px solid var(--border-main);background:var(--bg-hover);color:var(--text-ghost);cursor:pointer;display:inline-flex;align-items:center;justify-content:center;transition:color .12s,background .12s;"
                                                    onmouseover="this.style.color='var(--fyc-red)';this.style.background='var(--bg-active)'"
                                                    onmouseout="this.style.color='var(--text-ghost)';this.style.background='var(--bg-hover)'"
                                                    title="Abrir" data-action="open-task" data-task-id="<?= (int) $t['id'] ?>">
                                                    <svg viewBox="0 0 24 24" fill="none" style="width:12px;height:12px;"
                                                        stroke="currentColor" stroke-width="2">
                                                        <path d="M14 3h7v7" />
                                                        <path d="M10 14L21 3" />
                                                        <path d="M21 14v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h6" />
                                                    </svg>
                                                </button>
                                                <button type="button"
                                                    style="width:26px;height:26px;border-radius:7px;border:1px solid var(--border-main);background:var(--bg-hover);color:var(--text-ghost);cursor:pointer;display:inline-flex;align-items:center;justify-content:center;transition:color .12s,background .12s;"
                                                    onmouseover="this.style.color='var(--fyc-red)';this.style.background='var(--badge-overdue-bg)'"
                                                    onmouseout="this.style.color='var(--text-ghost)';this.style.background='var(--bg-hover)'"
                                                    title="Eliminar" data-action="delete-task" data-task-id="<?= (int) $t['id'] ?>">
                                                    <svg viewBox="0 0 24 24" fill="none" style="width:12px;height:12px;"
                                                        stroke="currentColor" stroke-width="2">
                                                        <path d="M3 6h18" />
                                                        <path d="M8 6V4h8v2" />
                                                        <path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6" />
                                                        <path d="M10 11v6" />
                                                        <path d="M14 11v6" />
                                                    </svg>
                                                </button>
                                            </div>
                                        </div>

                                        <div class="fyc-card-footer">
                                            <span class="<?= prio_class($prio) ?>"><?= tr_priority_label(h($prio), true) ?></span>
                                            <?php if ($due): ?>
                                                <span
                                                    class="fyc-badge fyc-badge-<?= $due['state'] === 'overdue' ? 'overdue' : ($due['state'] === 'soon' ? 'soon' : 'ok') ?>"><?= h($due['label']) ?></span>
                                            <?php endif; ?>
                                            <?php foreach ($tTags as $tg): ?>
                                                <span class="fyc-badge"
                                                    style="background:<?= h($tg['color_hex']) ?>;color:#fff;font-size:9px;"><?= h($tg['nombre']) ?></span>
                                            <?php endforeach; ?>
                                            <?php if ($asig_init): ?>
                                                <div class="fyc-card-assignee">
                                                    <div class="fyc-mini-avatar" title="<?= h($asig) ?>"><?= h($asig_init) ?></div>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                <?php endforeach; endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>

                <button type="button" id="btnAddColumn" class="fyc-col-new" title="Agregar columna">
                    <svg viewBox="0 0 24 24" fill="none" style="width:20px;height:20px;" stroke="currentColor"
                        stroke-width="2">
                        <path d="M12 5v14" />
                        <path d="M5 12h14" />
                    </svg>
                </button>

            </div><!-- /kanban -->
        </div><!-- /overflow-x -->
    </div><!-- /root -->

    <!-- Script de filtros — corre tanto en embed como standalone -->
    <script>
        (function () {
            'use strict';

            var activePrios = {};   // { urgent: true, high: true, ... }
            var activeTagIds = {};  // { 3: true, 7: true }
            var activeAssignee = '';
            var searchText = '';

            function hasActiveFilter() {
                return searchText !== ''
                    || Object.keys(activePrios).length > 0
                    || Object.keys(activeTagIds).length > 0
                    || activeAssignee !== '';
            }

            function applyFilters() {
                var tasks = document.querySelectorAll('.task.fyc-card');
                var visible = 0;

                tasks.forEach(function (card) {
                    var show = true;

                    // -- Búsqueda por texto --
                    if (searchText) {
                        var titulo = (card.getAttribute('data-titulo') || '').toLowerCase();
                        if (titulo.indexOf(searchText) === -1) show = false;
                    }

                    // -- Prioridad (OR entre seleccionadas) --
                    if (show && Object.keys(activePrios).length > 0) {
                        var prio = card.getAttribute('data-prioridad') || '';
                        if (!activePrios[prio]) show = false;
                    }

                    // -- Responsable --
                    if (show && activeAssignee !== '') {
                        var assignee = card.getAttribute('data-assignee') || '';
                        if (assignee !== activeAssignee) show = false;
                    }

                    // -- Tags (OR entre seleccionados) --
                    if (show && Object.keys(activeTagIds).length > 0) {
                        var rawTags = card.getAttribute('data-tags') || '[]';
                        var cardTags = [];
                        try { cardTags = JSON.parse(rawTags); } catch (e) { }
                        var matchTag = false;
                        cardTags.forEach(function (tid) { if (activeTagIds[String(tid)]) matchTag = true; });
                        if (!matchTag) show = false;
                    }

                    card.style.display = show ? '' : 'none';
                    if (show) visible++;
                });

                // Actualizar "vacía" de cada columna
                document.querySelectorAll('.col.fyc-col').forEach(function (col) {
                    var visibleInCol = 0;
                    col.querySelectorAll('.task.fyc-card').forEach(function (c) { if (c.style.display !== 'none') visibleInCol++; });
                    var empty = col.querySelector('.empty');
                    if (empty) empty.style.display = visibleInCol === 0 ? '' : 'none';

                    // Actualizar contador de columna
                    var cnt = col.querySelector('.cnt');
                    if (cnt) {
                        var total = col.querySelectorAll('.task.fyc-card').length;
                        cnt.textContent = hasActiveFilter() ? visibleInCol + '/' + total : total;
                    }
                });

                // Botón limpiar + contador
                var btnClear = document.getElementById('btnClearFilters');
                var fCount = document.getElementById('filterCount');
                if (btnClear) btnClear.style.display = hasActiveFilter() ? 'inline-flex' : 'none';
                if (fCount) {
                    if (hasActiveFilter()) {
                        fCount.style.display = 'inline';
                        fCount.textContent = visible + ' resultado' + (visible !== 1 ? 's' : '');
                    } else {
                        fCount.style.display = 'none';
                    }
                }
            }

            // -- Búsqueda --
            var searchInp = document.getElementById('filterSearch');
            if (searchInp) {
                searchInp.addEventListener('input', function () {
                    searchText = this.value.toLowerCase().trim();
                    applyFilters();
                });
            }

            // -- Prioridad toggle --
            document.querySelectorAll('.filter-prio-btn').forEach(function (btn) {
                // Aplicar clase de color inicial
                var cls = btn.getAttribute('data-cls');
                if (cls) btn.classList.add(cls);

                btn.addEventListener('click', function () {
                    var prio = btn.getAttribute('data-prio');
                    if (activePrios[prio]) {
                        delete activePrios[prio];
                        btn.style.opacity = '.5';
                        btn.style.borderColor = 'transparent';
                    } else {
                        activePrios[prio] = true;
                        btn.style.opacity = '1';
                        btn.style.borderColor = 'var(--text-primary)';
                    }
                    applyFilters();
                });
            });

            // -- Responsable --
            var selAss = document.getElementById('filterAssignee');
            if (selAss) {
                selAss.addEventListener('change', function () {
                    activeAssignee = this.value;
                    applyFilters();
                });
            }

            // -- Tags toggle --
            document.querySelectorAll('.filter-tag-btn').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    var tid = btn.getAttribute('data-tag-id');
                    var color = btn.style.borderColor;
                    if (activeTagIds[tid]) {
                        delete activeTagIds[tid];
                        btn.style.opacity = '.55';
                        btn.style.background = 'var(--bg-hover)';
                        btn.style.color = 'var(--text-muted)';
                    } else {
                        activeTagIds[tid] = true;
                        btn.style.opacity = '1';
                        btn.style.background = color;
                        btn.style.color = '#fff';
                    }
                    applyFilters();
                });
            });

            // -- Limpiar todo --
            var btnClear = document.getElementById('btnClearFilters');
            if (btnClear) {
                btnClear.addEventListener('click', function () {
                    // Reset estado
                    activePrios = {};
                    activeTagIds = {};
                    activeAssignee = '';
                    searchText = '';

                    // Reset UI
                    if (searchInp) searchInp.value = '';
                    if (selAss) selAss.value = '';
                    document.querySelectorAll('.filter-prio-btn').forEach(function (b) {
                        b.style.opacity = '.5'; b.style.borderColor = 'transparent';
                    });
                    document.querySelectorAll('.filter-tag-btn').forEach(function (b) {
                        b.style.opacity = '.55';
                        b.style.background = 'var(--bg-hover)';
                        b.style.color = 'var(--text-muted)';
                    });

                    applyFilters();
                });
            }

            // Exponer para que board-view.js pueda re-aplicar filtros tras recargar
            window.FCPlannerFilters = { apply: applyFilters };
        })();
    </script>

    <?php if ($EMBED): ?>

        <!-- DRAWER -->
        <div id="taskDrawerOverlay" class="fixed inset-0 z-40 hidden"
            style="background:rgba(0,0,0,0.4);backdrop-filter:blur(2px);"></div>
        <aside id="taskDrawer"
            class="fixed right-0 top-0 z-50 h-full w-full translate-x-full transition-transform duration-300 flex flex-col"
            style="max-width:520px;background:var(--bg-surface);border-left:1px solid var(--border-main);box-shadow:var(--shadow-drawer);">
            <div
                style="padding:14px 16px;border-bottom:1px solid var(--border-main);background:var(--bg-sidebar);display:flex;align-items:center;justify-content:space-between;">
                <div style="display:flex;align-items:center;gap:8px;">
                    <div style="width:10px;height:10px;border-radius:50%;background:var(--fyc-red);"></div>
                    <p style="margin:0;font-size:13px;font-weight:600;color:var(--text-primary);">Detalle de tarea</p>
                </div>
                <button type="button" data-drawer-close
                    style="width:28px;height:28px;border-radius:8px;border:1px solid var(--border-main);background:transparent;color:var(--text-faint);cursor:pointer;font-size:14px;display:flex;align-items:center;justify-content:center;">✕</button>
            </div>
            <div id="taskDrawerBody" style="padding:16px;overflow-y:auto;flex:1;font-size:13px;color:var(--text-muted);">
                Selecciona una tarea…</div>
        </aside>

        <!-- MODAL: Eliminar tarea -->
        <div id="modalDeleteTask" class="fixed inset-0 hidden z-50 p-4"
            style="display:none;align-items:center;justify-content:center;background:rgba(0,0,0,0.5);backdrop-filter:blur(3px);">
            <div
                style="width:100%;max-width:420px;border-radius:18px;background:var(--bg-surface);border:1px solid var(--border-accent);padding:24px;box-shadow:var(--shadow-modal);">
                <h2
                    style="font-family:'Sora',sans-serif;font-size:16px;font-weight:800;color:var(--fyc-red);margin:0 0 8px;">
                    Eliminar tarea</h2>
                <p style="font-size:13px;color:var(--text-muted);margin:0 0 20px;">¿Estás seguro? Esta acción no se puede
                    deshacer.</p>
                <div style="display:flex;justify-content:flex-end;gap:10px;">
                    <button id="btnCancelDeleteTask" class="fyc-btn fyc-btn-ghost">Cancelar</button>
                    <button id="btnConfirmDeleteTask" class="fyc-btn fyc-btn-primary">Sí, eliminar</button>
                </div>
            </div>
        </div>

        <!-- MODAL: Editar tarea -->
        <div id="modalEditTask" class="fixed inset-0 hidden z-50 p-4"
            style="display:none;align-items:center;justify-content:center;background:rgba(0,0,0,0.5);backdrop-filter:blur(3px);">
            <div
                style="width:100%;max-width:420px;border-radius:18px;background:var(--bg-surface);border:1px solid var(--border-accent);padding:24px;box-shadow:var(--shadow-modal);">
                <h3
                    style="font-family:'Sora',sans-serif;font-size:16px;font-weight:800;color:var(--fyc-red);margin:0 0 6px;">
                    Editar tarea</h3>
                <p id="edit_task_title" style="font-size:12px;color:var(--text-ghost);margin:0 0 16px;"></p>
                <form id="formEditTask">
                    <input type="hidden" id="edit_task_id">
                    <div style="margin-bottom:12px;"><label class="fyc-label">Prioridad</label>
                        <select id="edit_prioridad" class="fyc-select">
                            <option value="low">Baja</option>
                            <option value="med">Media</option>
                            <option value="high">Alta</option>
                            <option value="urgent">Urgente</option>
                        </select>
                    </div>
                    <div style="margin-bottom:12px;"><label class="fyc-label">Fecha límite</label><input type="date"
                            id="edit_fecha" class="fyc-input"></div>
                    <div style="margin-bottom:20px;"><label class="fyc-label">Asignar a</label>
                        <select id="edit_assignee" class="fyc-select">
                            <option value="">Sin responsable</option>
                            <?php foreach ($board_members as $m): ?>
                                <option value="<?= (int) $m['id'] ?>"><?= h($m['nombre']) ?></option><?php endforeach; ?>
                        </select>
                    </div>
                    <div style="display:flex;justify-content:flex-end;gap:10px;">
                        <button type="button" id="btnCancelEditTask" class="fyc-btn fyc-btn-ghost">Cancelar</button>
                        <button type="button" id="btnSaveEditTask" class="fyc-btn fyc-btn-primary">Guardar</button>
                    </div>
                </form>
            </div>
        </div>

        <!-- MODAL: Nueva columna -->
        <div id="modalAddColumn" class="fixed inset-0 hidden z-[55] p-4"
            style="display:none;align-items:center;justify-content:center;background:rgba(0,0,0,0.5);backdrop-filter:blur(3px);">
            <div
                style="width:100%;max-width:380px;border-radius:18px;background:var(--bg-surface);border:1px solid var(--border-accent);padding:24px;box-shadow:var(--shadow-modal);">
                <h3
                    style="font-family:'Sora',sans-serif;font-size:16px;font-weight:800;color:var(--fyc-red);margin:0 0 16px;">
                    Nueva columna</h3>
                <label class="fyc-label">Nombre</label>
                <input type="text" id="inputNewColumnName" class="fyc-input" placeholder="Ej. En revisión, QA..."
                    maxlength="120" style="margin-bottom:18px;">
                <div style="display:flex;justify-content:flex-end;gap:10px;">
                    <button id="btnCancelAddColumn" class="fyc-btn fyc-btn-ghost">Cancelar</button>
                    <button id="btnConfirmAddColumn" class="fyc-btn fyc-btn-primary">Crear</button>
                </div>
            </div>
        </div>

        <!-- MODAL: Renombrar columna -->
        <div id="modalRenameColumn" class="fixed inset-0 hidden z-[55] p-4"
            style="display:none;align-items:center;justify-content:center;background:rgba(0,0,0,0.5);backdrop-filter:blur(3px);">
            <div
                style="width:100%;max-width:380px;border-radius:18px;background:var(--bg-surface);border:1px solid var(--border-accent);padding:24px;box-shadow:var(--shadow-modal);">
                <h3
                    style="font-family:'Sora',sans-serif;font-size:16px;font-weight:800;color:var(--fyc-red);margin:0 0 16px;">
                    Renombrar columna</h3>
                <input type="hidden" id="renameColumnId">
                <label class="fyc-label">Nuevo nombre</label>
                <input type="text" id="inputRenameColumn" class="fyc-input" maxlength="120" style="margin-bottom:18px;">
                <div style="display:flex;justify-content:flex-end;gap:10px;">
                    <button id="btnCancelRenameColumn" class="fyc-btn fyc-btn-ghost">Cancelar</button>
                    <button id="btnConfirmRenameColumn" class="fyc-btn fyc-btn-primary">Guardar</button>
                </div>
            </div>
        </div>

        <!-- MODAL: Eliminar columna -->
        <div id="modalDeleteColumn" class="fixed inset-0 hidden z-[55] p-4"
            style="display:none;align-items:center;justify-content:center;background:rgba(0,0,0,0.5);backdrop-filter:blur(3px);">
            <div
                style="width:100%;max-width:380px;border-radius:18px;background:var(--bg-surface);border:1px solid var(--border-accent);padding:24px;box-shadow:var(--shadow-modal);">
                <h3
                    style="font-family:'Sora',sans-serif;font-size:16px;font-weight:800;color:var(--fyc-red);margin:0 0 8px;">
                    Eliminar columna</h3>
                <input type="hidden" id="deleteColumnId">
                <p id="deleteColumnMsg" style="font-size:13px;color:var(--text-muted);margin:0 0 6px;"></p>
                <p style="font-size:11px;color:var(--text-ghost);margin:0 0 20px;">⚠️ También se eliminarán todas las tareas
                    de esta columna. No se puede deshacer.</p>
                <div style="display:flex;justify-content:flex-end;gap:10px;">
                    <button id="btnCancelDeleteColumn" class="fyc-btn fyc-btn-ghost">Cancelar</button>
                    <button id="btnConfirmDeleteColumn" class="fyc-btn"
                        style="background:#dc2626;color:#fff;">Eliminar</button>
                </div>
            </div>
        </div>

        <!-- DROPDOWN columna -->
        <div id="colContextMenu" class="fyc-col-dropdown" style="display:none;position:fixed;z-index:60;">
            <div class="fyc-col-dropdown-item" id="colMenuRename">
                <svg viewBox="0 0 24 24" fill="none" style="width:14px;height:14px;" stroke="currentColor" stroke-width="2">
                    <path d="M12 20h9" stroke-linecap="round" />
                    <path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z" />
                </svg>
                Renombrar
            </div>
            <div class="fyc-col-dropdown-item danger" id="colMenuDelete">
                <svg viewBox="0 0 24 24" fill="none" style="width:14px;height:14px;" stroke="currentColor" stroke-width="2">
                    <path d="M3 6h18" stroke-linecap="round" />
                    <path d="M8 6V4h8v2" />
                    <path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6" />
                </svg>
                Eliminar columna
            </div>
        </div>

        <!-- Metadatos del tablero para el shell de workspace -->
        <div id="board-meta"
            data-can-manage="<?= $canManage ? '1' : '0' ?>"
            data-member-count="<?= count($members_with_roles) ?>"
            style="display:none;"></div>

        <!-- MODAL: Miembros del tablero -->
        <?php if ($canManage): ?>
        <div id="membersModal"
            style="display:none;position:fixed;inset:0;z-index:70;align-items:center;justify-content:center;background:rgba(0,0,0,0.55);backdrop-filter:blur(3px);">
            <div style="width:100%;max-width:480px;border-radius:18px;background:var(--bg-surface);border:1px solid var(--border-accent);padding:28px;box-shadow:var(--shadow-modal);max-height:90vh;display:flex;flex-direction:column;">

                <!-- Header modal -->
                <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:20px;">
                    <h3 style="font-family:'Sora',sans-serif;font-size:16px;font-weight:800;color:var(--fyc-red);margin:0;">
                        👥 Miembros del tablero
                    </h3>
                    <button type="button" id="btnCloseMembersModal"
                        style="background:none;border:none;font-size:20px;color:var(--text-ghost);cursor:pointer;line-height:1;padding:0 4px;"
                        title="Cerrar">✕</button>
                </div>

                <!-- Lista de miembros -->
                <div id="membersList" style="flex:1;overflow-y:auto;display:flex;flex-direction:column;gap:8px;margin-bottom:20px;">
                    <?php foreach ($members_with_roles as $m):
                        $isProp  = $m['rol'] === 'propietario';
                        $isLastProp = $isProp && $propietarioCount === 1;
                    ?>
                    <div class="member-row" data-user-id="<?= (int) $m['id'] ?>" data-rol="<?= h($m['rol']) ?>"
                        style="display:flex;align-items:center;gap:10px;padding:10px 12px;border-radius:10px;background:var(--bg-hover);border:1px solid <?= $isProp ? 'var(--fyc-red)' : 'transparent' ?>;">

                        <!-- Avatar inicial -->
                        <div class="member-avatar" style="width:32px;height:32px;border-radius:50%;background:<?= $isProp ? 'var(--fyc-red)' : 'var(--border-accent)' ?>;display:flex;align-items:center;justify-content:center;flex-shrink:0;font-weight:700;font-size:13px;color:#fff;">
                            <?= h(mb_strtoupper(mb_substr($m['nombre'], 0, 1))) ?>
                        </div>

                        <!-- Nombre + badge propietario -->
                        <div class="member-name-wrap" style="flex:1;min-width:0;">
                            <div style="font-size:13px;font-weight:600;color:var(--text-main);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">
                                <?= h($m['nombre']) ?>
                            </div>
                            <?php if ($isProp): ?>
                                <div class="owner-badge" style="font-size:10px;font-weight:700;color:var(--fyc-red);text-transform:uppercase;letter-spacing:.5px;margin-top:1px;">
                                    ★ Propietario
                                </div>
                            <?php endif; ?>
                        </div>

                        <!-- Selector de rol -->
                        <select class="fyc-select member-rol-select" data-user-id="<?= (int) $m['id'] ?>"
                            style="font-size:11px;padding:4px 6px;width:110px;flex-shrink:0;<?= $isLastProp ? 'opacity:.45;cursor:not-allowed;' : '' ?>"
                            <?= $isLastProp ? 'disabled title="No se puede cambiar el rol del único propietario"' : '' ?>>
                            <option value="propietario" <?= $m['rol'] === 'propietario' ? 'selected' : '' ?>>Propietario</option>
                            <option value="editor"      <?= $m['rol'] === 'editor'      ? 'selected' : '' ?>>Editor</option>
                            <option value="lector"      <?= $m['rol'] === 'lector'      ? 'selected' : '' ?>>Lector</option>
                        </select>

                        <!-- Botón quitar -->
                        <button type="button" class="member-remove-btn fyc-btn fyc-btn-ghost"
                            data-user-id="<?= (int) $m['id'] ?>" data-name="<?= h($m['nombre']) ?>"
                            style="font-size:11px;padding:4px 8px;flex-shrink:0;color:var(--text-ghost);"
                            title="Quitar miembro">✕</button>
                    </div>
                    <?php endforeach; ?>
                    <?php if (!$members_with_roles): ?>
                        <div style="font-size:12px;color:var(--text-ghost);padding:8px 0;">Sin miembros registrados.</div>
                    <?php endif; ?>
                </div>

                <!-- Divider -->
                <div style="height:1px;background:var(--border-main);margin-bottom:16px;"></div>

                <!-- Agregar miembro -->
                <div>
                    <div style="font-size:11px;font-weight:700;color:var(--text-ghost);text-transform:uppercase;letter-spacing:.8px;margin-bottom:10px;">Agregar miembro</div>
                    <?php if ($candidates): ?>
                    <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
                        <select id="addMemberUser" class="fyc-select" style="flex:1;min-width:140px;font-size:12px;">
                            <option value="">— Seleccionar usuario —</option>
                            <?php foreach ($candidates as $c): ?>
                                <option value="<?= (int) $c['id'] ?>"><?= h($c['nombre']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <select id="addMemberRol" class="fyc-select" style="width:110px;font-size:12px;">
                            <option value="editor">Editor</option>
                            <option value="lector">Lector</option>
                            <option value="propietario">Propietario</option>
                        </select>
                        <button type="button" id="btnAddMember" class="fyc-btn fyc-btn-primary" style="font-size:12px;white-space:nowrap;">+ Agregar</button>
                    </div>
                    <?php else: ?>
                        <div style="font-size:12px;color:var(--text-ghost);">
                            <?= !empty($board['team_id']) ? 'Todos los miembros del equipo ya están en el tablero.' : 'No hay más usuarios disponibles para agregar.' ?>
                        </div>
                    <?php endif; ?>
                    <div id="membersModalError" style="display:none;margin-top:10px;font-size:12px;color:#dc2626;font-weight:600;"></div>
                </div>

            </div>
        </div>

        <script>
        (function () {
            var modal      = document.getElementById('membersModal');
            var boardId    = <?= (int) $board_id ?>;
            var csrf       = document.getElementById('kanban') ? document.getElementById('kanban').dataset.csrf : '<?= h($_SESSION['csrf']) ?>';
            var ENDPOINT   = '../boards/member_action.php';

            // ── Abrir / cerrar ──────────────────────────────────────────
            var btnOpen  = document.getElementById('btnOpenMembersModal');
            var btnClose = document.getElementById('btnCloseMembersModal');
            if (btnOpen)  btnOpen.addEventListener('click',  function () { modal.style.display = 'flex'; });
            if (btnClose) btnClose.addEventListener('click', closeMembersModal);
            modal.addEventListener('click', function (e) { if (e.target === modal) closeMembersModal(); });
            document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && modal.style.display === 'flex') closeMembersModal(); });
            function closeMembersModal() { modal.style.display = 'none'; clearError(); }
            function clearError() { var el = document.getElementById('membersModalError'); if (el) { el.style.display = 'none'; el.textContent = ''; } }
            function showError(msg) { var el = document.getElementById('membersModalError'); if (el) { el.textContent = msg; el.style.display = 'block'; } }

            // ── Contar propietarios y ajustar selects ───────────────────
            function refreshOwnerLock() {
                var rows  = document.querySelectorAll('#membersList .member-row');
                var count = 0;
                rows.forEach(function (r) { if (r.dataset.rol === 'propietario') count++; });
                rows.forEach(function (r) {
                    var sel = r.querySelector('.member-rol-select');
                    if (!sel) return;
                    var isProp = r.dataset.rol === 'propietario';
                    var lastProp = isProp && count === 1;
                    sel.disabled = lastProp;
                    sel.style.opacity  = lastProp ? '0.45' : '';
                    sel.style.cursor   = lastProp ? 'not-allowed' : '';
                    sel.title          = lastProp ? 'No se puede cambiar el rol del único propietario' : '';
                });
            }

            // ── Cambiar rol ─────────────────────────────────────────────
            document.getElementById('membersList').addEventListener('change', function (e) {
                var sel = e.target;
                if (!sel.classList.contains('member-rol-select')) return;
                var uid    = parseInt(sel.dataset.userId, 10);
                var newRol = sel.value;
                var row    = sel.closest('.member-row');
                var oldRol = row ? row.dataset.rol : '';

                post({ action: 'set_role', board_id: boardId, target_user_id: uid, rol: newRol, csrf: csrf })
                    .then(function (res) {
                        if (!res.ok) { showError(res.error || 'Error al cambiar rol'); sel.value = oldRol; return; }
                        clearError();
                        if (row) {
                            row.dataset.rol = newRol;
                            // Actualizar badge visual y borde de propietario
                            var badge  = row.querySelector('.owner-badge');
                            var avatar = row.querySelector('.member-avatar');
                            var isProp = newRol === 'propietario';
                            row.style.borderColor = isProp ? 'var(--fyc-red)' : 'transparent';
                            if (avatar) avatar.style.background = isProp ? 'var(--fyc-red)' : 'var(--border-accent)';
                            if (isProp && !badge) {
                                var nameDiv = row.querySelector('.member-name-wrap');
                                if (nameDiv) {
                                    var b = document.createElement('div');
                                    b.className = 'owner-badge';
                                    b.style.cssText = 'font-size:10px;font-weight:700;color:var(--fyc-red);text-transform:uppercase;letter-spacing:.5px;margin-top:1px;';
                                    b.textContent = '★ Propietario';
                                    nameDiv.appendChild(b);
                                }
                            } else if (!isProp && badge) {
                                badge.remove();
                            }
                        }
                        refreshOwnerLock();
                    })
                    .catch(function () { showError('Error de red'); sel.value = oldRol; });
            });

            // ── Quitar miembro ──────────────────────────────────────────
            document.getElementById('membersList').addEventListener('click', function (e) {
                var btn = e.target.closest('.member-remove-btn');
                if (!btn) return;
                var uid  = parseInt(btn.dataset.userId, 10);
                var name = btn.dataset.name || 'este miembro';
                if (!confirm('¿Quitar a ' + name + ' del tablero?')) return;

                post({ action: 'remove', board_id: boardId, target_user_id: uid, csrf: csrf })
                    .then(function (res) {
                        if (!res.ok) { showError(res.error || 'Error al quitar miembro'); return; }
                        clearError();
                        var row = document.querySelector('#membersList .member-row[data-user-id="' + uid + '"]');
                        if (row) row.remove();
                        // Mover usuario de vuelta al select de candidatos
                        var addSel = document.getElementById('addMemberUser');
                        if (addSel && btn.dataset.name) {
                            var opt = document.createElement('option');
                            opt.value       = uid;
                            opt.textContent = name;
                            addSel.appendChild(opt);
                        }
                        // Actualizar contador en el botón del header
                        updateHeaderCount(-1);
                        refreshOwnerLock();
                    })
                    .catch(function () { showError('Error de red'); });
            });

            // ── Agregar miembro ─────────────────────────────────────────
            var btnAdd = document.getElementById('btnAddMember');
            if (btnAdd) {
                btnAdd.addEventListener('click', function () {
                    var uidSel = document.getElementById('addMemberUser');
                    var rolSel = document.getElementById('addMemberRol');
                    var uid    = uidSel ? parseInt(uidSel.value, 10) : 0;
                    var rol    = rolSel ? rolSel.value : 'editor';
                    var name   = uidSel && uidSel.selectedIndex >= 0 ? uidSel.options[uidSel.selectedIndex].text : '';
                    if (!uid) { showError('Selecciona un usuario'); return; }

                    post({ action: 'add', board_id: boardId, target_user_id: uid, rol: rol, csrf: csrf })
                        .then(function (res) {
                            if (!res.ok) { showError(res.error || 'Error al agregar'); return; }
                            clearError();
                            // Quitar del select de candidatos
                            if (uidSel) { var opt = uidSel.querySelector('option[value="' + uid + '"]'); if (opt) opt.remove(); uidSel.value = ''; }
                            // Añadir fila a la lista
                            appendMemberRow(uid, name, rol);
                            updateHeaderCount(+1);
                            refreshOwnerLock();
                        })
                        .catch(function () { showError('Error de red'); });
                });
            }

            function appendMemberRow(uid, name, rol) {
                var list   = document.getElementById('membersList');
                var isProp = rol === 'propietario';
                var initial = name ? name.charAt(0).toUpperCase() : '?';
                var div    = document.createElement('div');
                div.className       = 'member-row';
                div.dataset.userId  = uid;
                div.dataset.rol     = rol;
                div.style.cssText   = 'display:flex;align-items:center;gap:10px;padding:10px 12px;border-radius:10px;background:var(--bg-hover);border:1px solid ' + (isProp ? 'var(--fyc-red)' : 'transparent') + ';';
                div.innerHTML =
                    '<div class="member-avatar" style="width:32px;height:32px;border-radius:50%;background:' + (isProp ? 'var(--fyc-red)' : 'var(--border-accent)') + ';display:flex;align-items:center;justify-content:center;flex-shrink:0;font-weight:700;font-size:13px;color:#fff;">' + initial + '</div>' +
                    '<div class="member-name-wrap" style="flex:1;min-width:0;">' +
                        '<div style="font-size:13px;font-weight:600;color:var(--text-main);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">' + escHtml(name) + '</div>' +
                        (isProp ? '<div class="owner-badge" style="font-size:10px;font-weight:700;color:var(--fyc-red);text-transform:uppercase;letter-spacing:.5px;margin-top:1px;">★ Propietario</div>' : '') +
                    '</div>' +
                    '<select class="fyc-select member-rol-select" data-user-id="' + uid + '" style="font-size:11px;padding:4px 6px;width:110px;flex-shrink:0;">' +
                        '<option value="propietario"' + (rol==='propietario'?' selected':'') + '>Propietario</option>' +
                        '<option value="editor"'      + (rol==='editor'     ?' selected':'') + '>Editor</option>' +
                        '<option value="lector"'      + (rol==='lector'     ?' selected':'') + '>Lector</option>' +
                    '</select>' +
                    '<button type="button" class="member-remove-btn fyc-btn fyc-btn-ghost" data-user-id="' + uid + '" data-name="' + escHtml(name) + '" style="font-size:11px;padding:4px 8px;flex-shrink:0;color:var(--text-ghost);" title="Quitar miembro">✕</button>';
                // Quitar mensaje "sin miembros" si existe
                var empty = list.querySelector('div:not(.member-row)');
                if (empty && empty.textContent.trim().startsWith('Sin')) empty.remove();
                list.appendChild(div);
            }

            function updateHeaderCount(delta) {
                var btn = document.getElementById('btnOpenMembersModal');
                if (!btn) return;
                var m = btn.textContent.match(/\((\d+)\)/);
                if (m) { btn.textContent = '👥 Miembros (' + (parseInt(m[1], 10) + delta) + ')'; }
            }

            function escHtml(s) {
                return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
            }

            function post(data) {
                return fetch(ENDPOINT, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(data)
                }).then(function (r) { return r.json(); });
            }
        })();
        </script>
        <?php endif; // canManage ?>

        <!-- TOAST -->
        <div id="toast" class="fixed bottom-6 left-1/2 -translate-x-1/2 hidden z-[60]">
            <div>✅ Guardado</div>
        </div>
        <script id="members-data"
            type="application/json"><?= json_encode($board_members, JSON_UNESCAPED_UNICODE) ?></script>

    <?php else: ?>
    </body>

    </html>
<?php endif; ?>