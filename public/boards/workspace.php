<?php
// public/boards/workspace.php
require_once __DIR__ . '/../_auth.php';
require_login();
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../_perm.php';

if (session_status() !== PHP_SESSION_ACTIVE)
    session_start();
if (empty($_SESSION['csrf']))
    $_SESSION['csrf'] = bin2hex(random_bytes(16));
$user_id = (int) ($_SESSION['user_id'] ?? 0);
if ($user_id <= 0) {
    header('Location: ../login.php');
    exit;
}

function h($s)
{
    return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
}

$cols = [];
$res = $conn->query("SHOW COLUMNS FROM boards");
if ($res) {
    while ($r = $res->fetch_assoc())
        $cols[] = strtolower($r['Field']);
    $res->free();
}

$archiveMode = 'none';
$archiveCol = null;
if (in_array('archived_at', $cols, true)) {
    $archiveMode = 'archived_at';
    $archiveCol = 'archived_at';
} elseif (in_array('is_archived', $cols, true)) {
    $archiveMode = 'flag';
    $archiveCol = 'is_archived';
} elseif (in_array('archived', $cols, true)) {
    $archiveMode = 'flag';
    $archiveCol = 'archived';
}

function archiveWhere($mode, $col, $wantArchived)
{
    if ($mode === 'archived_at')
        return $wantArchived ? "b.`$col` IS NOT NULL" : "b.`$col` IS NULL";
    if ($mode === 'flag')
        return $wantArchived ? "b.`$col`=1" : "(b.`$col`=0 OR b.`$col` IS NULL)";
    return $wantArchived ? "1=0" : "1=1";
}
$wActive = archiveWhere($archiveMode, $archiveCol, false);
$wArchived = archiveWhere($archiveMode, $archiveCol, true);

$hasCreatedBy = in_array('created_by', $cols, true);
$personalWhereMember = "EXISTS (SELECT 1 FROM board_members bm WHERE bm.board_id=b.id AND bm.user_id={$user_id})";
$creatorClause = $hasCreatedBy ? " OR b.created_by={$user_id}" : "";
$personalBaseWhere = "(b.team_id IS NULL AND ({$personalWhereMember}{$creatorClause}))";
$teamBaseWhere = "(b.team_id IS NOT NULL AND EXISTS (SELECT 1 FROM board_members bm WHERE bm.board_id=b.id AND bm.user_id={$user_id}))";

function fetchBoards($conn, $whereBase, $whereArchive, $user_id)
{
    $sql = "SELECT b.id,b.nombre,b.color_hex,b.team_id,COALESCE(bm.rol,'') AS my_role
        FROM boards b
        LEFT JOIN board_members bm ON bm.board_id=b.id AND bm.user_id={$user_id}
        WHERE {$whereBase} AND {$whereArchive}
        ORDER BY b.created_at DESC, b.id DESC";
    $out = [];
    $res = $conn->query($sql);
    if ($res) {
        while ($r = $res->fetch_assoc())
            $out[] = $r;
        $res->free();
    }
    return $out;
}
$personalActive = fetchBoards($conn, $personalBaseWhere, $wActive, $user_id);
$personalArchived = fetchBoards($conn, $personalBaseWhere, $wArchived, $user_id);
$teamActive = fetchBoards($conn, $teamBaseWhere, $wActive, $user_id);
$teamArchived = fetchBoards($conn, $teamBaseWhere, $wArchived, $user_id);

$teamsById = [];
$resT = $conn->query("SELECT id,nombre FROM teams");
if ($resT) {
    while ($r = $resT->fetch_assoc())
        $teamsById[(int) $r['id']] = $r['nombre'];
    $resT->free();
}

function badgeRole($roleRaw)
{
    $role = strtolower(trim((string) $roleRaw));
    if ($role === 'propietario' || $role === 'owner')
        return ['Propietario', 'bg-[#942934] text-white'];
    if ($role === 'admin_equipo' || $role === 'admin')
        return ['Admin', 'bg-[#d32f57] text-white'];
    if ($role === 'miembro' || $role === 'member')
        return ['Miembro', 'bg-[#f3e3e7] text-[#942934] border border-[#d32f57]/30'];
    return ['Mi rol', 'bg-gray-100 text-gray-700 border border-gray-200'];
}

$firstBoardId = 0;
if (!empty($personalActive))
    $firstBoardId = (int) $personalActive[0]['id'];
elseif (!empty($teamActive))
    $firstBoardId = (int) $teamActive[0]['id'];

$userId = (int) ($_SESSION['user_id'] ?? 0);
$myTeams = [];
$q = $conn->prepare("SELECT t.id, t.nombre FROM teams t JOIN team_members tm ON tm.team_id=t.id WHERE tm.user_id=? AND tm.rol='admin_equipo' ORDER BY t.nombre ASC");
$q->bind_param('i', $userId);
$q->execute();
$myTeams = $q->get_result()->fetch_all(MYSQLI_ASSOC);

// Helper: renderiza los botones de acción de una tarjeta de tablero
function boardActionBtns($b)
{
    $id = (int) $b['id'];
    $name = htmlspecialchars($b['nombre'], ENT_QUOTES, 'UTF-8');
    $color = htmlspecialchars($b['color_hex'] ?: '#d32f57', ENT_QUOTES, 'UTF-8');
    return '
    <div class="board-card-actions">
        <button class="board-card-btn" type="button" title="Editar"    data-action="edit" data-id="' . $id . '" data-name="' . $name . '" data-color="' . $color . '">
            <svg viewBox="0 0 24 24" fill="none" style="width:11px;height:11px;" stroke="currentColor" stroke-width="2.5"><path d="M12 20h9" stroke-linecap="round"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z" stroke-linejoin="round"/></svg>
        </button>
        <button class="board-card-btn" type="button" title="Duplicar"  data-action="dup"  data-id="' . $id . '" data-name="' . $name . '">
            <svg viewBox="0 0 24 24" fill="none" style="width:11px;height:11px;" stroke="currentColor" stroke-width="2.5"><path d="M8 8h12v12H8z" stroke-linejoin="round"/><path d="M4 16H3a1 1 0 0 1-1-1V3a1 1 0 0 1 1-1h12a1 1 0 0 1 1 1v1" stroke-linecap="round"/></svg>
        </button>
        <button class="board-card-btn" type="button" title="Archivar"  data-action="arc"  data-id="' . $id . '" data-name="' . $name . '">
            <svg viewBox="0 0 24 24" fill="none" style="width:11px;height:11px;" stroke="currentColor" stroke-width="2.5"><path d="M21 8v13H3V8" stroke-linejoin="round"/><path d="M1 3h22v5H1z" stroke-linejoin="round"/><path d="M10 12h4" stroke-linecap="round"/></svg>
        </button>
        <button class="board-card-btn danger" type="button" title="Eliminar" data-action="del" data-id="' . $id . '" data-name="' . $name . '">
            <svg viewBox="0 0 24 24" fill="none" style="width:11px;height:11px;" stroke="currentColor" stroke-width="2.5"><path d="M3 6h18" stroke-linecap="round"/><path d="M8 6V4h8v2" stroke-linejoin="round"/><path d="M6 6l1 16h10l1-16" stroke-linejoin="round"/></svg>
        </button>
    </div>';
}

function boardRestoreDeleteBtns($b)
{
    $id = (int) $b['id'];
    $name = htmlspecialchars($b['nombre'], ENT_QUOTES, 'UTF-8');
    return '
    <div class="board-card-actions" style="opacity:1;">
        <button class="board-card-btn" type="button" title="Restaurar" data-action="res" data-id="' . $id . '" data-name="' . $name . '">
            <svg viewBox="0 0 24 24" fill="none" style="width:11px;height:11px;" stroke="currentColor" stroke-width="2.5"><path d="M3 12a9 9 0 0 1 15.5-6.5" stroke-linecap="round"/><path d="M18 3v6h-6" stroke-linejoin="round"/><path d="M21 12a9 9 0 0 1-15.5 6.5" stroke-linecap="round"/><path d="M6 21v-6h6" stroke-linejoin="round"/></svg>
        </button>
        <button class="board-card-btn danger" type="button" title="Eliminar" data-action="del" data-id="' . $id . '" data-name="' . $name . '">
            <svg viewBox="0 0 24 24" fill="none" style="width:11px;height:11px;" stroke="currentColor" stroke-width="2.5"><path d="M3 6h18" stroke-linecap="round"/><path d="M8 6V4h8v2" stroke-linejoin="round"/><path d="M6 6l1 16h10l1-16" stroke-linejoin="round"/></svg>
        </button>
    </div>';
}
?>
<!doctype html>
<html lang="es" data-theme="dark">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>F&amp;C Planner · Workspace</title>
    <link rel="stylesheet" href="../assets/app.css">
    <link rel="stylesheet" href="../assets/theme.css">
    <script>
        (function () {
            var t = localStorage.getItem('fyc-theme') || 'dark';
            document.documentElement.setAttribute('data-theme', t);
        })();
    </script>
    <style>
        .sidebarScroll::-webkit-scrollbar {
            width: 4px;
        }

        .sidebarScroll::-webkit-scrollbar-thumb {
            background: var(--scrollbar-thumb);
            border-radius: 999px;
        }

        .modalBackdrop {
            cursor: pointer;
        }

        #boardTitle {
            font-family: 'Sora', sans-serif;
            font-size: 17px;
            font-weight: 800;
            color: var(--fyc-red);
        }

        .fyc-modal-surface {
            background: var(--bg-surface);
            border: 1px solid var(--border-accent);
            color: var(--text-primary);
        }

        .fyc-modal-surface input,
        .fyc-modal-surface select {
            background: var(--bg-input);
            border-color: var(--border-accent);
            color: var(--text-primary);
        }

        #taskDrawer {
            background: var(--bg-surface);
            border-left-color: var(--border-main);
        }
    </style>
    <script>
        window.FCPlannerCurrentUserName = <?= json_encode($_SESSION['user_nombre'] ?? 'Usuario') ?>;
    </script>
    <script src="../assets/board-view.js?v=1" defer></script>
</head>

<body style="margin:0; overflow-x:hidden;">

    <!-- ===== FLASH ===== -->
    <?php if (!empty($_SESSION['flash']) && is_array($_SESSION['flash'])): ?>
        <?php $ft = $_SESSION['flash']['type'] ?? 'ok';
        $fm = $_SESSION['flash']['msg'] ?? '';
        unset($_SESSION['flash']); ?>
        <div id="flashToast"
            style="position:fixed;top:16px;left:50%;transform:translateX(-50%);z-index:200;width:92%;max-width:680px;">
            <div class="fyc-flash <?= ($ft === 'ok' || $ft === 'success') ? 'fyc-flash-ok' : 'fyc-flash-error' ?>">
                <?= htmlspecialchars($fm, ENT_QUOTES, 'UTF-8') ?>
            </div>
        </div>
        <script>setTimeout(function () { var el = document.getElementById('flashToast'); if (el) el.remove(); }, 5200);</script>
    <?php endif; ?>

    <!-- ===== HEADER ===== -->
    <header class="fyc-header">
        <div style="display:flex;align-items:center;gap:16px;">
            <a href="workspace.php" class="fyc-logo">F&amp;C <span>Planner</span></a>
            <div style="width:1px;height:18px;background:var(--border-main);"></div>
            <span style="font-size:12px;color:var(--text-ghost);font-family:'Sora',sans-serif;">Workspace</span>
        </div>
        <div style="display:flex;align-items:center;gap:10px;">
            <button id="themeToggle" title="Cambiar tema">
                <span id="themeIcon">🌙</span>
                <span id="themeLabel">Oscuro</span>
            </button>
            <?php if (can_see_admin_panel($conn)): ?>
                <a href="../admin/index.php" class="fyc-btn fyc-btn-ghost" style="text-decoration:none;">⚙ Admin</a>
            <?php endif; ?>
            <a href="../logout.php" class="fyc-btn fyc-btn-danger" style="text-decoration:none;">Salir</a>
            <div class="fyc-avatar"><?= strtoupper(mb_substr($_SESSION['nombre'] ?? 'U', 0, 2)) ?></div>
        </div>
    </header>

    <!-- ===== WORKSPACE ===== -->
    <div class="fyc-workspace">

        <!-- SIDEBAR -->
        <aside class="fyc-sidebar">

            <!-- Bienvenida -->
            <div style="padding:12px 14px 10px;border-bottom:1px solid var(--border-main);">
                <div style="font-size:10px;color:var(--text-ghost);text-transform:uppercase;letter-spacing:1px;">
                    Bienvenido</div>
                <div
                    style="font-family:'Sora',sans-serif;font-size:14px;font-weight:800;color:var(--fyc-red);margin-top:2px;">
                    <?= h($_SESSION['nombre'] ?? 'Usuario') ?>
                </div>
            </div>

            <!-- Crear tablero -->
            <div style="padding:10px 12px;border-bottom:1px solid var(--border-main);">
                <div class="fyc-sidebar-label">Nuevo tablero</div>
                <form method="POST" action="./create.php?return=workspace">
                    <input type="hidden" name="csrf" value="<?= h($_SESSION['csrf']) ?>">
                    <input name="nombre" required class="fyc-input" style="margin-bottom:7px;"
                        placeholder="Ej. Comercial, TI...">
                    <div style="display:grid;grid-template-columns:1fr auto auto;gap:5px;align-items:center;">
                        <select name="team_id" class="fyc-select" style="font-size:11px;">
                            <option value="">Personal</option>
                            <?php foreach ($myTeams as $t): ?>
                                <option value="<?= (int) $t['id'] ?>"><?= h($t['nombre']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <div>
                            <input type="hidden" name="color_hex" id="create_color_hex" value="#d32f57">
                            <button type="button" id="btnOpenColorPicker"
                                style="width:30px;height:30px;border-radius:50%;border:2px solid var(--border-accent);background:var(--bg-input);cursor:pointer;padding:0;display:flex;align-items:center;justify-content:center;">
                                <span id="createColorPreview"
                                    style="display:block;width:16px;height:16px;border-radius:50%;background:#d32f57;"></span>
                            </button>
                        </div>
                        <button type="submit" class="fyc-btn fyc-btn-primary"
                            style="height:30px;padding:0 12px;font-size:11px;">Crear</button>
                    </div>
                </form>
            </div>

            <!-- Lista tableros -->
            <div class="sidebarScroll" style="flex:1;overflow-y:auto;padding:10px 12px;">

                <!-- Personales activos -->
                <div class="fyc-sidebar-label">Personales (<?= count($personalActive) ?>)</div>

                <?php foreach ($personalActive as $b): ?>
                    <?php [$roleTxt] = badgeRole($b['my_role']); ?>
                    <div class="board-card">
                        <div class="board-card-row">
                            <button type="button" class="board-card-info" data-open-board="<?= (int) $b['id'] ?>"
                                data-title="<?= h($b['nombre']) ?>">
                                <div style="display:flex;align-items:center;gap:6px;min-width:0;">
                                    <span
                                        style="width:8px;height:8px;border-radius:50%;flex-shrink:0;display:inline-block;background:<?= h($b['color_hex'] ?: '#d32f57') ?>;"></span>
                                    <span class="board-card-name"><?= h($b['nombre']) ?></span>
                                </div>
                                <div style="display:flex;align-items:center;gap:4px;margin-top:3px;">
                                    <span class="fyc-badge fyc-badge-overdue"
                                        style="font-size:9px;"><?= h($roleTxt) ?></span>
                                    <span class="board-card-sub">Personal</span>
                                </div>
                            </button>
                            <?= boardActionBtns($b) ?>
                        </div>
                    </div>
                <?php endforeach; ?>
                <?php if (!count($personalActive)): ?>
                    <div style="font-size:11px;color:var(--text-ghost);padding:4px 2px 10px;">Sin tableros personales.</div>
                <?php endif; ?>

                <!-- Personales archivados -->
                <?php if (count($personalArchived)): ?>
                    <details style="margin-bottom:10px;">
                        <summary
                            style="font-size:10px;font-weight:700;color:var(--text-ghost);cursor:pointer;text-transform:uppercase;letter-spacing:1px;list-style:none;padding:4px 0;">
                            ▸ Archivados (<?= count($personalArchived) ?>)
                        </summary>
                        <div style="margin-top:5px;display:flex;flex-direction:column;gap:4px;">
                            <?php foreach ($personalArchived as $b): ?>
                                <div class="board-card" style="opacity:.65;">
                                    <div class="board-card-row">
                                        <button type="button" class="board-card-info" data-open-board="<?= (int) $b['id'] ?>"
                                            data-title="<?= h($b['nombre']) ?>">
                                            <span class="board-card-name" style="font-size:11px;"><?= h($b['nombre']) ?></span>
                                            <span class="board-card-sub">Archivado</span>
                                        </button>
                                        <?= boardRestoreDeleteBtns($b) ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </details>
                <?php endif; ?>

                <!-- Equipos activos -->
                <?php if (count($teamActive) || count($teamArchived)): ?>
                    <div class="fyc-sidebar-divider" style="margin:8px 0;"></div>
                    <div class="fyc-sidebar-label">Equipos (<?= count($teamActive) ?>)</div>

                    <?php foreach ($teamActive as $b): ?>
                        <?php [$roleTxt] = badgeRole($b['my_role']); ?>
                        <?php $teamName = $b['team_id'] ? ($teamsById[(int) $b['team_id']] ?? 'Equipo') : 'Equipo'; ?>
                        <div class="board-card">
                            <div class="board-card-row">
                                <button type="button" class="board-card-info" data-open-board="<?= (int) $b['id'] ?>"
                                    data-title="<?= h($b['nombre']) ?>">
                                    <div style="display:flex;align-items:center;gap:6px;min-width:0;">
                                        <span
                                            style="width:8px;height:8px;border-radius:50%;flex-shrink:0;display:inline-block;background:<?= h($b['color_hex'] ?: '#d32f57') ?>;"></span>
                                        <span class="board-card-name"><?= h($b['nombre']) ?></span>
                                    </div>
                                    <div style="display:flex;align-items:center;gap:4px;margin-top:3px;">
                                        <span class="fyc-badge fyc-badge-overdue"
                                            style="font-size:9px;"><?= h($roleTxt) ?></span>
                                        <span class="board-card-sub"><?= h($teamName) ?></span>
                                    </div>
                                </button>
                                <?= boardActionBtns($b) ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                    <?php if (!count($teamActive)): ?>
                        <div style="font-size:11px;color:var(--text-ghost);padding:4px 2px 10px;">Sin tableros de equipo.</div>
                    <?php endif; ?>

                    <?php if (count($teamArchived)): ?>
                        <details style="margin-bottom:10px;">
                            <summary
                                style="font-size:10px;font-weight:700;color:var(--text-ghost);cursor:pointer;text-transform:uppercase;letter-spacing:1px;list-style:none;padding:4px 0;">
                                ▸ Archivados equipo (<?= count($teamArchived) ?>)
                            </summary>
                            <div style="margin-top:5px;display:flex;flex-direction:column;gap:4px;">
                                <?php foreach ($teamArchived as $b): ?>
                                    <div class="board-card" style="opacity:.65;">
                                        <div class="board-card-row">
                                            <button type="button" class="board-card-info" data-open-board="<?= (int) $b['id'] ?>"
                                                data-title="<?= h($b['nombre']) ?>">
                                                <span class="board-card-name" style="font-size:11px;"><?= h($b['nombre']) ?></span>
                                                <span class="board-card-sub">Archivado</span>
                                            </button>
                                            <?= boardRestoreDeleteBtns($b) ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </details>
                    <?php endif; ?>
                <?php endif; ?>

            </div><!-- /sidebarScroll -->
        </aside>

        <!-- PANEL PRINCIPAL -->
        <main class="fyc-main-area">
            <div
                style="padding:11px 18px;border-bottom:1px solid var(--border-main);background:var(--bg-sidebar);display:flex;align-items:center;justify-content:space-between;">
                <div id="boardTitle">Selecciona un tablero</div>
                <a href="./index.php" class="fyc-btn fyc-btn-ghost" style="text-decoration:none;font-size:11px;">⚙
                    Administrar</a>
            </div>
            <div style="flex:1;overflow:auto;background:var(--bg-app);">
                <div id="boardMount" style="min-height:100%;">
                    <div style="padding:32px;font-size:13px;color:var(--text-ghost);">
                        Selecciona un tablero en la izquierda para cargarlo aquí.
                    </div>
                </div>
            </div>
        </main>

    </div><!-- /fyc-workspace -->

    <!-- ===== DRAWER ===== -->
    <div id="taskDrawerOverlay" class="fixed inset-0 z-40 hidden"
        style="background:rgba(0,0,0,0.4);backdrop-filter:blur(2px);"></div>
    <aside id="taskDrawer"
        class="fixed right-0 top-0 z-50 h-full w-full translate-x-full transition-transform duration-300 flex flex-col"
        style="max-width:520px;box-shadow:var(--shadow-drawer);">
        <div
            style="padding:14px 16px;border-bottom:1px solid var(--border-main);background:var(--bg-sidebar);display:flex;align-items:center;justify-content:space-between;">
            <div style="display:flex;align-items:center;gap:8px;">
                <div style="width:10px;height:10px;border-radius:50%;background:var(--fyc-red);"></div>
                <p style="margin:0;font-size:13px;font-weight:600;color:var(--text-primary);">Detalle de tarea</p>
            </div>
            <button type="button" data-drawer-close class="fyc-modal-close">✕</button>
        </div>
        <div id="taskDrawerBody" style="padding:16px;overflow-y:auto;flex:1;font-size:13px;color:var(--text-muted);">
            Selecciona una tarea…
        </div>
    </aside>

    <!-- ===== TOAST ===== -->
    <div id="toast" class="fixed bottom-6 left-1/2 -translate-x-1/2 hidden z-[60]">
        <div>✅ Listo</div>
    </div>

    <!-- ===== MODAL: Color Picker ===== -->
    <div id="colorPickerModal" class="fixed inset-0 z-[80] hidden">
        <div id="colorPickerBackdrop" class="absolute inset-0"
            style="background:rgba(0,0,0,0.55);backdrop-filter:blur(3px);"></div>
        <div class="relative mx-auto mt-10 fyc-modal-surface"
            style="width:92%;max-width:420px;border-radius:18px;overflow:hidden;">
            <div
                style="display:flex;align-items:center;justify-content:space-between;padding:14px 18px;border-bottom:1px solid var(--border-main);">
                <p
                    style="margin:0;font-size:14px;font-weight:700;color:var(--text-primary);font-family:'Sora',sans-serif;">
                    Selector de color</p>
                <button type="button" id="btnCloseColorPicker" class="fyc-modal-close">✕</button>
            </div>
            <div style="padding:20px;">
                <div style="display:flex;justify-content:center;">
                    <canvas id="colorWheel" width="260" height="260"
                        style="border-radius:50%;cursor:crosshair;touch-action:none;user-select:none;"></canvas>
                </div>
                <div style="margin-top:14px;display:flex;align-items:center;justify-content:space-between;gap:10px;">
                    <div style="display:flex;align-items:center;gap:10px;">
                        <span id="modalColorPreview"
                            style="display:block;width:32px;height:32px;border-radius:50%;background:#d32f57;border:2px solid var(--border-accent);"></span>
                        <div>
                            <div style="font-size:10px;color:var(--text-ghost);">Seleccionado</div>
                            <div id="modalHexText"
                                style="font-size:13px;font-weight:700;color:var(--text-primary);font-family:'Sora',sans-serif;">
                                #d32f57</div>
                        </div>
                    </div>
                    <div style="display:flex;gap:8px;">
                        <button type="button" id="btnCancelColor" class="fyc-btn fyc-btn-ghost">Cancelar</button>
                        <button type="button" id="btnApplyColor" class="fyc-btn fyc-btn-primary">Aplicar</button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- ===== MODALES DE ACCIONES ===== -->

    <!-- EDIT -->
    <div id="modalEdit" class="fixed inset-0 z-[90] hidden" aria-hidden="true">
        <div class="modalBackdrop absolute inset-0" style="background:rgba(0,0,0,0.55);backdrop-filter:blur(3px);">
        </div>
        <div class="relative mx-auto mt-16 fyc-modal-surface"
            style="width:92%;max-width:500px;border-radius:18px;overflow:hidden;">
            <div
                style="display:flex;align-items:center;justify-content:space-between;padding:14px 18px;border-bottom:1px solid var(--border-main);">
                <p style="margin:0;font-size:14px;font-weight:700;color:var(--fyc-red);font-family:'Sora',sans-serif;">
                    Editar tablero</p>
                <button type="button" onclick="closeModal('modalEdit')" class="fyc-modal-close">✕</button>
            </div>
            <form method="POST" action="./update.php?return=workspace" style="padding:18px;">
                <input type="hidden" name="csrf" value="<?= h($_SESSION['csrf']) ?>">
                <input type="hidden" name="board_id" id="edit_board_id" value="">
                <div style="margin-bottom:13px;"><label class="fyc-label">Nombre</label><input id="edit_nombre"
                        name="nombre" required class="fyc-input" placeholder="Nombre del tablero"></div>
                <div style="margin-bottom:13px;">
                    <label class="fyc-label">Color</label>
                    <div style="display:flex;align-items:center;gap:10px;margin-top:5px;">
                        <input id="edit_color_hex" name="color_hex" type="color"
                            style="width:44px;height:30px;border-radius:8px;border:1px solid var(--border-accent);background:var(--bg-input);cursor:pointer;padding:2px 4px;"
                            value="#d32f57">
                        <span style="font-size:11px;color:var(--text-ghost);">Color del tablero</span>
                    </div>
                </div>
                <div style="display:flex;justify-content:flex-end;gap:8px;">
                    <button type="button" onclick="closeModal('modalEdit')"
                        class="fyc-btn fyc-btn-ghost">Cancelar</button>
                    <button type="submit" class="fyc-btn fyc-btn-primary">Guardar</button>
                </div>
            </form>
        </div>
    </div>

    <!-- DELETE -->
    <div id="modalDelete" class="fixed inset-0 z-[90] hidden" aria-hidden="true">
        <div class="modalBackdrop absolute inset-0" style="background:rgba(0,0,0,0.55);backdrop-filter:blur(3px);">
        </div>
        <div class="relative mx-auto mt-16 fyc-modal-surface"
            style="width:92%;max-width:500px;border-radius:18px;overflow:hidden;">
            <div
                style="display:flex;align-items:center;justify-content:space-between;padding:14px 18px;border-bottom:1px solid var(--border-main);">
                <p style="margin:0;font-size:14px;font-weight:700;color:#dc2626;font-family:'Sora',sans-serif;">Eliminar
                    tablero</p>
                <button type="button" onclick="closeModal('modalDelete')" class="fyc-modal-close">✕</button>
            </div>
            <form method="POST" action="./delete.php?return=workspace" style="padding:18px;">
                <input type="hidden" name="csrf" value="<?= h($_SESSION['csrf']) ?>">
                <input type="hidden" name="board_id" id="del_board_id" value="">
                <p style="font-size:13px;color:var(--text-muted);margin:0 0 4px;">Vas a eliminar <strong
                        id="del_board_name" style="color:var(--text-primary);"></strong>.</p>
                <p style="font-size:11px;color:var(--text-ghost);margin:0 0 18px;">Esta acción no se puede deshacer.</p>
                <div style="display:flex;justify-content:flex-end;gap:8px;">
                    <button type="button" onclick="closeModal('modalDelete')"
                        class="fyc-btn fyc-btn-ghost">Cancelar</button>
                    <button type="submit" class="fyc-btn" style="background:#dc2626;color:#fff;">Eliminar</button>
                </div>
            </form>
        </div>
    </div>

    <!-- DUPLICATE -->
    <div id="modalDuplicate" class="fixed inset-0 z-[90] hidden" aria-hidden="true">
        <div class="modalBackdrop absolute inset-0" style="background:rgba(0,0,0,0.55);backdrop-filter:blur(3px);">
        </div>
        <div class="relative mx-auto mt-16 fyc-modal-surface"
            style="width:92%;max-width:500px;border-radius:18px;overflow:hidden;">
            <div
                style="display:flex;align-items:center;justify-content:space-between;padding:14px 18px;border-bottom:1px solid var(--border-main);">
                <p style="margin:0;font-size:14px;font-weight:700;color:var(--fyc-red);font-family:'Sora',sans-serif;">
                    Duplicar tablero</p>
                <button type="button" onclick="closeModal('modalDuplicate')" class="fyc-modal-close">✕</button>
            </div>
            <form method="POST" action="./duplicate.php?return=workspace" style="padding:18px;">
                <input type="hidden" name="csrf" value="<?= h($_SESSION['csrf']) ?>">
                <input type="hidden" name="board_id" id="dup_board_id" value="">
                <p style="font-size:13px;color:var(--text-muted);margin:0 0 18px;">Vas a duplicar <strong
                        id="dup_board_name" style="color:var(--text-primary);"></strong>.</p>
                <div style="display:flex;justify-content:flex-end;gap:8px;">
                    <button type="button" onclick="closeModal('modalDuplicate')"
                        class="fyc-btn fyc-btn-ghost">Cancelar</button>
                    <button type="submit" class="fyc-btn fyc-btn-primary">Duplicar</button>
                </div>
            </form>
        </div>
    </div>

    <!-- ARCHIVE -->
    <div id="modalArchive" class="fixed inset-0 z-[90] hidden" aria-hidden="true">
        <div class="modalBackdrop absolute inset-0" style="background:rgba(0,0,0,0.55);backdrop-filter:blur(3px);">
        </div>
        <div class="relative mx-auto mt-16 fyc-modal-surface"
            style="width:92%;max-width:500px;border-radius:18px;overflow:hidden;">
            <div
                style="display:flex;align-items:center;justify-content:space-between;padding:14px 18px;border-bottom:1px solid var(--border-main);">
                <p style="margin:0;font-size:14px;font-weight:700;color:var(--fyc-red);font-family:'Sora',sans-serif;">
                    Archivar tablero</p>
                <button type="button" onclick="closeModal('modalArchive')" class="fyc-modal-close">✕</button>
            </div>
            <form method="POST" action="./archive.php?return=workspace" style="padding:18px;">
                <input type="hidden" name="csrf" value="<?= h($_SESSION['csrf']) ?>">
                <input type="hidden" name="board_id" id="arc_board_id" value="">
                <p style="font-size:13px;color:var(--text-muted);margin:0 0 18px;">Vas a archivar <strong
                        id="arc_board_name" style="color:var(--text-primary);"></strong>.</p>
                <div style="display:flex;justify-content:flex-end;gap:8px;">
                    <button type="button" onclick="closeModal('modalArchive')"
                        class="fyc-btn fyc-btn-ghost">Cancelar</button>
                    <button type="submit" class="fyc-btn fyc-btn-primary">Archivar</button>
                </div>
            </form>
        </div>
    </div>

    <!-- RESTORE -->
    <div id="modalRestore" class="fixed inset-0 z-[90] hidden" aria-hidden="true">
        <div class="modalBackdrop absolute inset-0" style="background:rgba(0,0,0,0.55);backdrop-filter:blur(3px);">
        </div>
        <div class="relative mx-auto mt-16 fyc-modal-surface"
            style="width:92%;max-width:500px;border-radius:18px;overflow:hidden;">
            <div
                style="display:flex;align-items:center;justify-content:space-between;padding:14px 18px;border-bottom:1px solid var(--border-main);">
                <p style="margin:0;font-size:14px;font-weight:700;color:var(--fyc-red);font-family:'Sora',sans-serif;">
                    Restaurar tablero</p>
                <button type="button" onclick="closeModal('modalRestore')" class="fyc-modal-close">✕</button>
            </div>
            <form method="POST" action="./restore.php?return=workspace" style="padding:18px;">
                <input type="hidden" name="csrf" value="<?= h($_SESSION['csrf']) ?>">
                <input type="hidden" name="board_id" id="res_board_id" value="">
                <p style="font-size:13px;color:var(--text-muted);margin:0 0 18px;">Vas a restaurar <strong
                        id="res_board_name" style="color:var(--text-primary);"></strong>.</p>
                <div style="display:flex;justify-content:flex-end;gap:8px;">
                    <button type="button" onclick="closeModal('modalRestore')"
                        class="fyc-btn fyc-btn-ghost">Cancelar</button>
                    <button type="submit" class="fyc-btn fyc-btn-primary">Restaurar</button>
                </div>
            </form>
        </div>
    </div>

    <!-- ===== SCRIPTS ===== -->
    <script>
        (function () {
            'use strict';

            // ---- TEMA ----
            var html = document.documentElement;
            var btnT = document.getElementById('themeToggle');
            var iconEl = document.getElementById('themeIcon');
            var labelEl = document.getElementById('themeLabel');

            function applyTheme(t) {
                html.setAttribute('data-theme', t);
                localStorage.setItem('fyc-theme', t);
                if (iconEl) iconEl.textContent = t === 'dark' ? '🌙' : '☀️';
                if (labelEl) labelEl.textContent = t === 'dark' ? 'Oscuro' : 'Claro';
            }
            applyTheme(localStorage.getItem('fyc-theme') || 'dark');
            if (btnT) btnT.addEventListener('click', function () {
                applyTheme(html.getAttribute('data-theme') === 'dark' ? 'light' : 'dark');
            });

            // ---- CARGAR TABLERO ----
            function byId(id) { return document.getElementById(id); }

            function loadBoard(boardId, title) {
                if (!boardId) return;
                byId('boardTitle').textContent = title || ('Tablero #' + boardId);
                var mount = byId('boardMount');
                mount.innerHTML = '<div style="padding:32px;font-size:13px;color:var(--text-ghost);">Cargando tablero...</div>';
                fetch('./view.php?id=' + encodeURIComponent(boardId) + '&embed=1', { headers: { 'X-Requested-With': 'fetch' } })
                    .then(function (r) { return r.text(); })
                    .then(function (html) {
                        mount.innerHTML = html;
                        if (window.FCPlannerBoard && typeof window.FCPlannerBoard.destroy === 'function') window.FCPlannerBoard.destroy();
                        if (window.FCPlannerBoard && typeof window.FCPlannerBoard.init === 'function') window.FCPlannerBoard.init(mount);
                    })
                    .catch(function () { mount.innerHTML = '<div style="padding:32px;font-size:13px;color:var(--badge-overdue-tx);">No se pudo cargar el tablero.</div>'; });
            }

            document.addEventListener('click', function (ev) {
                var btn = ev.target.closest('[data-open-board]');
                if (!btn) return;
                loadBoard(btn.getAttribute('data-open-board'), btn.getAttribute('data-title') || '');
            });

            var firstId = <?= (int) $firstBoardId ?>;
            if (firstId) {
                var el = document.querySelector('[data-open-board="' + firstId + '"]');
                loadBoard(firstId, el ? (el.getAttribute('data-title') || '') : '');
            }
        })();

        // ---- COLOR PICKER ----
        (function () {
            'use strict';
            function byId(id) { return document.getElementById(id); }
            var modal = byId('colorPickerModal'), backdrop = byId('colorPickerBackdrop'), btnOpen = byId('btnOpenColorPicker'), btnClose = byId('btnCloseColorPicker'), btnCancel = byId('btnCancelColor'), btnApply = byId('btnApplyColor'), inputHex = byId('create_color_hex'), previewSmall = byId('createColorPreview'), canvas = byId('colorWheel'), ctx = canvas ? canvas.getContext('2d') : null, modalPreview = byId('modalColorPreview'), modalHexText = byId('modalHexText');
            if (!modal || !btnOpen || !canvas || !ctx || !inputHex) return;
            var W = canvas.width, H = canvas.height, cx = W / 2, cy = H / 2, outerR = Math.min(W, H) / 2 - 6, ringWidth = 26, innerR = outerR - ringWidth - 6, hsv = { h: 340, s: 0.78, v: 0.83 };
            function clamp(x, a, b) { return Math.max(a, Math.min(b, x)); }
            function hsvToRgb(h, s, v) { var c = v * s, x = c * (1 - Math.abs(((h / 60) % 2) - 1)), m = v - c, r = 0, g = 0, b = 0; if (h < 60) { r = c; g = x; } else if (h < 120) { r = x; g = c; } else if (h < 180) { g = c; b = x; } else if (h < 240) { g = x; b = c; } else if (h < 300) { r = x; b = c; } else { r = c; b = x; } return { r: Math.round((r + m) * 255), g: Math.round((g + m) * 255), b: Math.round((b + m) * 255) }; }
            function rgbToHex(r, g, b) { function t(n) { var s = n.toString(16); return s.length === 1 ? '0' + s : s; } return '#' + t(r) + t(g) + t(b); }
            function hexToRgb(hex) { var m = /^#([0-9a-fA-F]{6})$/.exec(hex || ''); if (!m) return null; var n = parseInt(m[1], 16); return { r: (n >> 16) & 255, g: (n >> 8) & 255, b: n & 255 }; }
            function rgbToHsv(r, g, b) { r /= 255; g /= 255; b /= 255; var mx = Math.max(r, g, b), mn = Math.min(r, g, b), d = mx - mn, h = 0; if (d === 0) h = 0; else if (mx === r) h = 60 * (((g - b) / d) % 6); else if (mx === g) h = 60 * (((b - r) / d) + 2); else h = 60 * (((r - g) / d) + 4); if (h < 0) h += 360; return { h: h, s: mx === 0 ? 0 : d / mx, v: mx }; }
            function setPreviewsFromHSV() { var rgb = hsvToRgb(hsv.h, hsv.s, hsv.v), hex = rgbToHex(rgb.r, rgb.g, rgb.b); modalPreview.style.background = hex; modalHexText.textContent = hex; btnApply.style.background = hex; }
            function drawWheel() { ctx.clearRect(0, 0, W, H); for (var a = 0; a < 360; a++) { var r1 = (a - 1) * Math.PI / 180, r2 = a * Math.PI / 180; ctx.beginPath(); ctx.arc(cx, cy, outerR, r1, r2, false); ctx.strokeStyle = 'hsl(' + a + ',100%,50%)'; ctx.lineWidth = ringWidth; ctx.stroke(); } ctx.save(); ctx.beginPath(); ctx.arc(cx, cy, innerR, 0, Math.PI * 2); ctx.clip(); var hr = hsvToRgb(hsv.h, 1, 1); ctx.fillStyle = rgbToHex(hr.r, hr.g, hr.b); ctx.fillRect(cx - innerR, cy - innerR, innerR * 2, innerR * 2); var gw = ctx.createRadialGradient(cx, cy, 0, cx, cy, innerR); gw.addColorStop(0, 'rgba(255,255,255,1)'); gw.addColorStop(1, 'rgba(255,255,255,0)'); ctx.fillStyle = gw; ctx.fillRect(cx - innerR, cy - innerR, innerR * 2, innerR * 2); var gb = ctx.createLinearGradient(cx, cy - innerR, cx, cy + innerR); gb.addColorStop(0, 'rgba(0,0,0,0)'); gb.addColorStop(1, 'rgba(0,0,0,0.95)'); ctx.fillStyle = gb; ctx.fillRect(cx - innerR, cy - innerR, innerR * 2, innerR * 2); ctx.restore(); var hr2 = (hsv.h - 90) * Math.PI / 180, hx = cx + Math.cos(hr2) * (outerR - ringWidth / 2), hy = cy + Math.sin(hr2) * (outerR - ringWidth / 2); ctx.beginPath(); ctx.arc(hx, hy, 7, 0, Math.PI * 2); ctx.fillStyle = '#fff'; ctx.fill(); ctx.lineWidth = 3; ctx.strokeStyle = 'rgba(0,0,0,0.25)'; ctx.stroke(); var sx = cx + (hsv.s - 0.5) * innerR * 1.6, sy = cy + (0.5 - hsv.v) * innerR * 1.6, dx = sx - cx, dy = sy - cy, dist = Math.sqrt(dx * dx + dy * dy); if (dist > innerR) { dx = dx * (innerR / dist); dy = dy * (innerR / dist); sx = cx + dx; sy = cy + dy; } ctx.beginPath(); ctx.arc(sx, sy, 9, 0, Math.PI * 2); ctx.fillStyle = 'rgba(255,255,255,0.95)'; ctx.fill(); ctx.lineWidth = 3; ctx.strokeStyle = 'rgba(0,0,0,0.25)'; ctx.stroke(); }
            function openModal() { modal.classList.remove('hidden'); var rgb = hexToRgb(inputHex.value || '#d32f57'); if (rgb) hsv = rgbToHsv(rgb.r, rgb.g, rgb.b); setPreviewsFromHSV(); drawWheel(); }
            function closeModal() { modal.classList.add('hidden'); }
            function applyColor() { var rgb = hsvToRgb(hsv.h, hsv.s, hsv.v), hex = rgbToHex(rgb.r, rgb.g, rgb.b); inputHex.value = hex; if (previewSmall) previewSmall.style.background = hex; closeModal(); }
            function handlePick(ev) { var rect = canvas.getBoundingClientRect(), x = (ev.clientX - rect.left) * (W / rect.width), y = (ev.clientY - rect.top) * (H / rect.height), dx = x - cx, dy = y - cy, r = Math.sqrt(dx * dx + dy * dy); if (r <= outerR + ringWidth / 2 && r >= outerR - ringWidth) { var ang = Math.atan2(dy, dx) * 180 / Math.PI + 90; if (ang < 0) ang += 360; hsv.h = ang; setPreviewsFromHSV(); drawWheel(); return; } if (r <= innerR) { hsv.s = clamp((dx / (innerR * 0.8) + 1) / 2, 0, 1); hsv.v = clamp(1 - (dy / (innerR * 0.8) + 1) / 2, 0, 1); setPreviewsFromHSV(); drawWheel(); } }
            btnOpen.addEventListener('click', openModal); btnClose.addEventListener('click', closeModal); btnCancel.addEventListener('click', closeModal); backdrop.addEventListener('click', closeModal); btnApply.addEventListener('click', applyColor);
            document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && !modal.classList.contains('hidden')) closeModal(); });
            canvas.addEventListener('mousedown', handlePick);
            canvas.addEventListener('mousemove', function (ev) { if (ev.buttons !== 1) return; handlePick(ev); });
            if (previewSmall) previewSmall.style.background = (inputHex.value || '#d32f57');
        })();
    </script>

    <script src="../assets/boards-actions.js?v=1" defer></script>
</body>

</html>