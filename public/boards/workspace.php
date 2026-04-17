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

$isSuperAdmin = is_super_admin($conn);

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

$hasDeletedAt = in_array('deleted_at', $cols, true);
$wNotDeleted  = $hasDeletedAt ? 'b.deleted_at IS NULL' : '1';

$hasCreatedBy = in_array('created_by', $cols, true);
$personalWhereMember = "EXISTS (SELECT 1 FROM board_members bm WHERE bm.board_id=b.id AND bm.user_id={$user_id})";
$creatorClause = $hasCreatedBy ? " OR b.created_by={$user_id}" : "";
$personalBaseWhere = "(b.team_id IS NULL AND ({$personalWhereMember}{$creatorClause}))";

// Tableros de equipo: acceso por membresía de equipo (no por board_members).
// Super admin ve todos los tableros de equipo sin restricción.
if ($isSuperAdmin) {
    $teamBaseWhere = "(b.team_id IS NOT NULL)";
} else {
    $teamBaseWhere = "(b.team_id IS NOT NULL AND EXISTS (SELECT 1 FROM team_members tm WHERE tm.team_id=b.team_id AND tm.user_id={$user_id}))";
}

function fetchBoards($conn, $whereBase, $whereArchive, $user_id, $whereNotDeleted = '1')
{
    $sql = "SELECT b.id, b.nombre, b.color_hex, b.team_id,
                   COALESCE(bm.rol,'')  AS my_role,
                   COALESCE(tm.rol,'')  AS team_role
            FROM boards b
            LEFT JOIN board_members bm ON bm.board_id=b.id AND bm.user_id={$user_id}
            LEFT JOIN team_members  tm ON tm.team_id=b.team_id AND tm.user_id={$user_id}
            WHERE {$whereBase} AND {$whereArchive} AND {$whereNotDeleted}
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
$personalActive   = fetchBoards($conn, $personalBaseWhere, $wActive,   $user_id, $wNotDeleted);
$personalArchived = fetchBoards($conn, $personalBaseWhere, $wArchived,  $user_id, $wNotDeleted);
$teamActive       = fetchBoards($conn, $teamBaseWhere,     $wActive,    $user_id, $wNotDeleted);
$teamArchived     = fetchBoards($conn, $teamBaseWhere,     $wArchived,  $user_id, $wNotDeleted);

$trashCount = 0;
if ($hasDeletedAt) {
    $trashSql = "SELECT COUNT(*) FROM boards b
                 WHERE b.deleted_at IS NOT NULL
                   AND ({$personalBaseWhere} OR {$teamBaseWhere})";
    $trashRes = $conn->query($trashSql);
    if ($trashRes) {
        $trashCount = (int) $trashRes->fetch_row()[0];
        $trashRes->free();
    }
}

$teamsById = [];
$resT = $conn->query("SELECT id,nombre FROM teams");
if ($resT) {
    while ($r = $resT->fetch_assoc())
        $teamsById[(int) $r['id']] = $r['nombre'];
    $resT->free();
}

// Agrupar tableros de equipo por team_id, ordenados por nombre del equipo
$teamActiveByGroup = [];
foreach ($teamActive as $b) {
    $tid = (int) ($b['team_id'] ?? 0);
    $teamActiveByGroup[$tid][] = $b;
}
uksort($teamActiveByGroup, function ($a, $b) use ($teamsById) {
    return strcmp($teamsById[$a] ?? '', $teamsById[$b] ?? '');
});

$teamArchivedByGroup = [];
foreach ($teamArchived as $b) {
    $tid = (int) ($b['team_id'] ?? 0);
    $teamArchivedByGroup[$tid][] = $b;
}
$allTeamIds = array_unique(array_merge(array_keys($teamActiveByGroup), array_keys($teamArchivedByGroup)));
sort($allTeamIds);

/**
 * Devuelve [label, inlineStyle] para el badge de rol de una card de tablero.
 * Usa board_role si está presente; si no, cae en team_role.
 * Devuelve ['', ''] cuando no hay rol que mostrar.
 */
function badgeRole($boardRole, $teamRole = '')
{
    $role = strtolower(trim((string) $boardRole));
    if ($role === '') $role = strtolower(trim((string) $teamRole));

    if ($role === 'propietario' || $role === 'owner')
        return ['Propietario', 'background:var(--badge-overdue-bg);color:var(--badge-overdue-tx);'];
    if ($role === 'admin_equipo')
        return ['Admin equipo', 'background:var(--fyc-red);color:#fff;'];
    if ($role === 'editor')
        return ['Editor', 'background:var(--bg-hover);color:var(--text-muted);border:1px solid var(--border-accent);'];
    if ($role === 'miembro' || $role === 'member')
        return ['Miembro', 'background:var(--bg-hover);color:var(--text-muted);border:1px solid var(--border-accent);'];
    if ($role === 'lector')
        return ['Lector', 'background:var(--bg-hover);color:var(--text-ghost);border:1px solid var(--border-main);'];
    return ['', ''];
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
        <button class="sb-star board-card-btn" type="button" title="Marcar como favorito"
                data-fav-id="' . $id . '" aria-pressed="false">
            <svg viewBox="0 0 24 24" fill="none" style="width:11px;height:11px;" stroke="currentColor" stroke-width="2">
                <polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/>
            </svg>
        </button>
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
        /* Sidebar: buscador */
        #sbSearch {
            width:100%;box-sizing:border-box;padding:6px 10px 6px 28px;
            font-size:12px;border-radius:7px;
            border:1px solid var(--border-accent);
            background:var(--bg-input);color:var(--text-primary);
            outline:none;
        }
        #sbSearch:focus { border-color:var(--fyc-red); }
        /* Sidebar: estrella favorito (hover-only, se muestra con acciones) */
        .sb-star { color:var(--text-ghost); }
        .sb-star[aria-pressed="true"] { color:var(--fyc-red); }
        .sb-star:hover { color:var(--fyc-red); }
        /* Sidebar: chevron de colapso */
        .sb-chevron { transition:transform .18s ease; flex-shrink:0; }
        /* Sidebar: indicador permanente de favorito en card original */
        .board-card.is-fav { border-left:2px solid var(--fyc-red); }
        /* Sidebar: pin strip de favoritos (compacto, no duplica cards) */
        .sb-pin-strip { padding:2px 0 4px; }
        .sb-pin-row {
            display:flex;align-items:center;gap:7px;
            padding:4px 6px;border-radius:7px;cursor:default;
            transition:background .12s;
        }
        .sb-pin-row:hover { background:var(--bg-hover); }
        .sb-pin-dot { width:7px;height:7px;border-radius:50%;flex-shrink:0; }
        .sb-pin-btn {
            flex:1;min-width:0;display:flex;flex-direction:column;
            background:none;border:none;padding:0;cursor:pointer;text-align:left;
        }
        .sb-pin-name {
            font-size:12px;font-weight:600;color:var(--text-primary);
            font-family:'Sora',sans-serif;
            white-space:nowrap;overflow:hidden;text-overflow:ellipsis;display:block;
            transition:color .12s;
        }
        .sb-pin-row:hover .sb-pin-name { color:var(--fyc-red); }
        .sb-pin-team {
            font-size:10px;color:var(--text-ghost);
            white-space:nowrap;flex-shrink:0;max-width:70px;
            overflow:hidden;text-overflow:ellipsis;
        }
        .sb-pin-remove {
            width:18px;height:18px;border-radius:5px;
            border:none;background:none;color:var(--text-ghost);
            cursor:pointer;font-size:14px;line-height:1;
            display:inline-flex;align-items:center;justify-content:center;
            opacity:0;transition:opacity .12s,color .12s;
            padding:0;flex-shrink:0;
        }
        .sb-pin-row:hover .sb-pin-remove { opacity:1; }
        .sb-pin-remove:hover { color:var(--fyc-red); }

        /* ---- Sección Favoritos: bloque premium destacado ---- */
        #sbFavSection:not(:empty) {
            background: linear-gradient(180deg, #2a141c 0%, #1f0f15 100%);
            border-left: 4px solid var(--fyc-red);
            border-radius: 8px;
            padding: 8px 8px 2px 10px;
            margin-bottom: 12px;
            box-shadow: 0 6px 18px rgba(232, 80, 112, 0.25);
        }
        [data-theme="light"] #sbFavSection:not(:empty) {
            background: linear-gradient(180deg, #fff7f9 0%, #ffeef2 100%);
            box-shadow: 0 6px 18px rgba(232, 80, 112, 0.15);
        }
        /* Título "Favoritos" en vinotinto */
        #sbFavSection .fyc-sidebar-label {
            color: var(--fyc-red);
            font-weight: 600;
        }
        /* Icono ⭐ con glow vía pseudo-elemento (sin tocar JS) */
        #sbFavSection .fyc-sidebar-label::before {
            content: '⭐\00a0';
            letter-spacing: 0;
            filter: drop-shadow(0 0 4px rgba(232, 80, 112, 0.5));
        }
        /* Cards dentro de favoritos */
        #sbFavSection .sb-pin-row {
            background: rgba(255, 255, 255, 0.04);
            border: 1px solid rgba(232, 80, 112, 0.35);
            border-radius: 6px;
            margin-bottom: 2px;
        }
        [data-theme="light"] #sbFavSection .sb-pin-row {
            background: rgba(255, 255, 255, 0.7);
            border: 1px solid rgba(232, 80, 112, 0.25);
        }
        /* Hover específico dentro del bloque de favoritos */
        #sbFavSection .sb-pin-row:hover {
            background: #3d1e2a;
        }
        [data-theme="light"] #sbFavSection .sb-pin-row:hover {
            background: #ffe4ea;
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

            <!-- ===== CAMPANA DE NOTIFICACIONES ===== -->
            <div id="notifWrapper" style="position:relative;">
                <button id="notifBtn" title="Notificaciones"
                    style="position:relative;display:flex;align-items:center;justify-content:center;
                           width:34px;height:34px;border-radius:50%;border:1px solid var(--border-accent);
                           background:var(--bg-input);cursor:pointer;color:var(--text-primary);
                           font-size:16px;padding:0;transition:background 0.15s;">
                    🔔
                    <span id="notifBadge"
                        style="display:none;position:absolute;top:-3px;right:-3px;
                               min-width:17px;height:17px;padding:0 4px;border-radius:9px;
                               background:var(--fyc-red);color:#fff;font-size:10px;font-weight:700;
                               font-family:'DM Sans',sans-serif;line-height:17px;text-align:center;
                               border:2px solid var(--bg-main);">0</span>
                </button>

                <!-- Panel dropdown -->
                <div id="notifPanel"
                    style="display:none;position:absolute;top:calc(100% + 8px);right:0;width:320px;
                           background:var(--bg-surface);border:1px solid var(--border-accent);
                           border-radius:14px;box-shadow:0 8px 32px rgba(0,0,0,0.45);
                           z-index:200;overflow:hidden;">
                    <!-- Header del panel -->
                    <div style="display:flex;align-items:center;justify-content:space-between;
                                padding:12px 16px 10px;border-bottom:1px solid var(--border-main);">
                        <span style="font-family:'Sora',sans-serif;font-size:13px;font-weight:700;
                                     color:var(--text-primary);">Notificaciones</span>
                        <button id="notifMarkAll"
                            style="display:none;font-size:11px;color:var(--fyc-red);background:none;
                                   border:none;cursor:pointer;font-family:'DM Sans',sans-serif;
                                   font-weight:600;padding:0;">Marcar todas leídas</button>
                    </div>
                    <!-- Lista -->
                    <div id="notifList"
                        style="max-height:360px;overflow-y:auto;padding:6px 0;">
                        <div style="padding:28px 16px;text-align:center;font-size:12px;color:var(--text-ghost);">
                            Cargando…
                        </div>
                    </div>
                </div>
            </div>
            <!-- ===== / CAMPANA ===== -->

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

            <!-- Buscador (bloque fijo, fuera del scroll) -->
            <div style="padding:8px 12px;border-bottom:1px solid var(--border-main);">
                <div style="position:relative;">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                         style="width:13px;height:13px;position:absolute;left:8px;top:50%;
                                transform:translateY(-50%);color:var(--text-ghost);pointer-events:none;">
                        <circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/>
                    </svg>
                    <input id="sbSearch" type="search" placeholder="Buscar tablero o equipo…" autocomplete="off">
                </div>
            </div>

            <!-- Lista tableros -->
            <div class="sidebarScroll" style="flex:1;overflow-y:auto;padding:10px 12px;">

                <!-- Sección Favoritos (renderizada por JS) -->
                <div id="sbFavSection"></div>

                <!-- ── Personales ────────────────────────── -->
                <div data-sb-group="personal">
                    <button type="button" data-sb-toggle="personal"
                        style="display:flex;align-items:center;justify-content:space-between;
                               width:100%;background:none;border:none;cursor:pointer;
                               padding:0;margin-bottom:6px;">
                        <div style="display:flex;align-items:center;gap:6px;">
                            <span style="width:3px;height:13px;background:var(--border-accent);border-radius:2px;display:inline-block;"></span>
                            <span class="fyc-sidebar-label" style="margin-bottom:0;">Personales</span>
                        </div>
                        <div style="display:flex;align-items:center;gap:5px;">
                            <span style="font-size:10px;color:var(--text-ghost);font-weight:600;"><?= count($personalActive) ?></span>
                            <svg class="sb-chevron" viewBox="0 0 24 24" fill="none" style="width:10px;height:10px;color:var(--text-ghost);" stroke="currentColor" stroke-width="2.5"><polyline points="6 9 12 15 18 9"/></svg>
                        </div>
                    </button>

                    <div id="sbCards-personal">
                        <?php foreach ($personalActive as $b): ?>
                            <?php [$roleTxt, $roleStyle] = badgeRole($b['my_role'], $b['team_role'] ?? ''); ?>
                            <div class="board-card"
                                 data-board-id="<?= (int)$b['id'] ?>"
                                 data-board-name="<?= h($b['nombre']) ?>"
                                 data-team-name=""
                                 data-color="<?= h($b['color_hex'] ?: '#d32f57') ?>">
                                <div class="board-card-row">
                                    <button type="button" class="board-card-info" data-open-board="<?= (int) $b['id'] ?>"
                                        data-title="<?= h($b['nombre']) ?>">
                                        <div style="display:flex;align-items:center;gap:6px;min-width:0;">
                                            <span style="width:8px;height:8px;border-radius:50%;flex-shrink:0;display:inline-block;background:<?= h($b['color_hex'] ?: '#d32f57') ?>;"></span>
                                            <span class="board-card-name"><?= h($b['nombre']) ?></span>
                                        </div>
                                        <div style="display:flex;align-items:center;gap:4px;margin-top:3px;">
                                            <?php if ($roleTxt): ?>
                                                <span class="fyc-badge" style="font-size:9px;<?= $roleStyle ?>"><?= h($roleTxt) ?></span>
                                            <?php endif; ?>
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
                    </div><!-- /sbCards-personal -->
                </div><!-- /sb-group-personal -->

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

                <!-- Equipos — agrupados por equipo -->
                <?php if (count($allTeamIds)): ?>
                    <div class="fyc-sidebar-divider" style="margin:12px 0 8px;"></div>

                    <?php foreach ($allTeamIds as $tid): ?>
                        <?php
                            $groupActive   = $teamActiveByGroup[$tid]   ?? [];
                            $groupArchived = $teamArchivedByGroup[$tid] ?? [];
                            $teamLabel     = $teamsById[$tid] ?? 'Equipo';
                            $groupCount    = count($groupActive);
                            $colKey        = 'team-' . $tid;
                        ?>
                        <!-- Grupo: <?= h($teamLabel) ?> -->
                        <div data-sb-group="<?= $colKey ?>" style="margin-bottom:14px;">
                            <!-- Encabezado de equipo (toggle colapso) -->
                            <button type="button" data-sb-toggle="<?= $colKey ?>"
                                style="display:flex;align-items:center;justify-content:space-between;
                                       width:100%;background:var(--bg-hover);border:none;cursor:pointer;
                                       border-radius:6px;padding:5px 8px;margin-bottom:6px;gap:6px;">
                                <div style="display:flex;align-items:center;gap:6px;min-width:0;">
                                    <span style="width:3px;height:13px;background:var(--fyc-red);border-radius:2px;flex-shrink:0;display:inline-block;"></span>
                                    <span style="font-size:10px;font-weight:700;color:var(--text-main);
                                                 text-transform:uppercase;letter-spacing:.6px;
                                                 white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">
                                        <?= h($teamLabel) ?>
                                    </span>
                                </div>
                                <div style="display:flex;align-items:center;gap:5px;flex-shrink:0;">
                                    <span style="font-size:10px;color:var(--text-ghost);font-weight:600;"><?= $groupCount ?></span>
                                    <svg class="sb-chevron" viewBox="0 0 24 24" fill="none" style="width:10px;height:10px;color:var(--text-ghost);" stroke="currentColor" stroke-width="2.5"><polyline points="6 9 12 15 18 9"/></svg>
                                </div>
                            </button>

                            <!-- Tableros activos del equipo -->
                            <div id="sbCards-<?= $colKey ?>">
                                <?php foreach ($groupActive as $b): ?>
                                    <?php [$roleTxt, $roleStyle] = badgeRole($b['my_role'], $b['team_role'] ?? ''); ?>
                                    <div class="board-card"
                                         data-board-id="<?= (int)$b['id'] ?>"
                                         data-board-name="<?= h($b['nombre']) ?>"
                                         data-team-name="<?= h($teamLabel) ?>"
                                         data-color="<?= h($b['color_hex'] ?: '#d32f57') ?>">
                                        <div class="board-card-row">
                                            <button type="button" class="board-card-info" data-open-board="<?= (int) $b['id'] ?>"
                                                data-title="<?= h($b['nombre']) ?>">
                                                <div style="display:flex;align-items:center;gap:6px;min-width:0;">
                                                    <span style="width:8px;height:8px;border-radius:50%;flex-shrink:0;display:inline-block;background:<?= h($b['color_hex'] ?: '#d32f57') ?>;"></span>
                                                    <span class="board-card-name"><?= h($b['nombre']) ?></span>
                                                </div>
                                                <?php if ($roleTxt): ?>
                                                    <div style="margin-top:3px;">
                                                        <span class="fyc-badge" style="font-size:9px;<?= $roleStyle ?>"><?= h($roleTxt) ?></span>
                                                    </div>
                                                <?php endif; ?>
                                            </button>
                                            <?= boardActionBtns($b) ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                                <?php if (!$groupCount): ?>
                                    <div style="font-size:11px;color:var(--text-ghost);padding:2px 2px 6px;">Sin tableros activos.</div>
                                <?php endif; ?>
                            </div><!-- /sbCards-<?= $colKey ?> -->

                            <!-- Archivados del equipo -->
                            <?php if (count($groupArchived)): ?>
                                <details style="margin-top:4px;">
                                    <summary style="font-size:10px;font-weight:700;color:var(--text-ghost);cursor:pointer;
                                                    text-transform:uppercase;letter-spacing:1px;list-style:none;padding:3px 0;">
                                        ▸ Archivados (<?= count($groupArchived) ?>)
                                    </summary>
                                    <div style="margin-top:4px;display:flex;flex-direction:column;gap:4px;">
                                        <?php foreach ($groupArchived as $b): ?>
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
                        </div><!-- /grupo equipo -->
                    <?php endforeach; ?>
                <?php endif; ?>

            </div><!-- /sidebarScroll -->

            <!-- Footer sidebar: enlace a papelera -->
            <div style="padding:8px 12px;border-top:1px solid var(--border-main);flex-shrink:0;">
                <a href="./trash.php"
                   style="display:flex;align-items:center;gap:7px;padding:6px 8px;border-radius:6px;
                          text-decoration:none;color:var(--text-muted);font-size:12px;
                          transition:background 0.15s;"
                   onmouseover="this.style.background='var(--bg-hover)'"
                   onmouseout="this.style.background=''">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                         style="width:13px;height:13px;flex-shrink:0;">
                        <polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14H6L5 6"/>
                        <path d="M10 11v6"/><path d="M14 11v6"/>
                        <path d="M9 6V4h6v2"/>
                    </svg>
                    <span>Papelera</span>
                    <?php if ($trashCount > 0): ?>
                        <span style="margin-left:auto;background:var(--fyc-red);color:#fff;
                                     font-size:10px;font-weight:700;border-radius:999px;
                                     padding:1px 6px;line-height:16px;">
                            <?= $trashCount ?>
                        </span>
                    <?php endif; ?>
                </a>
            </div>
        </aside>

        <!-- PANEL PRINCIPAL -->
        <main class="fyc-main-area">
            <div
                style="padding:11px 18px;border-bottom:1px solid var(--border-main);background:var(--bg-sidebar);display:flex;align-items:center;justify-content:space-between;">
                <div id="boardTitle">Selecciona un tablero</div>
                <div style="display:flex;align-items:center;gap:10px;">
                    <!-- Avatares de presencia en tiempo real -->
                    <div id="presence" style="display:flex;align-items:center;gap:4px;"></div>
                    <button type="button" id="btnBoardMembers"
                        style="display:none;font-size:11px;"
                        class="fyc-btn fyc-btn-ghost">👥 Miembros</button>

                </div>
            </div>
            <div style="flex:1;overflow:auto;background:var(--bg-app);">
                <div id="boardMount" style="min-height:100%;">
                    <div style="display:flex;flex-direction:column;align-items:center;justify-content:center;min-height:320px;gap:10px;padding:40px 24px;text-align:center;">
                        <svg width="40" height="40" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" style="color:var(--text-ghost);opacity:0.45;"><rect x="3" y="3" width="7" height="9" rx="1.5" stroke="currentColor" stroke-width="1.5"/><rect x="14" y="3" width="7" height="5" rx="1.5" stroke="currentColor" stroke-width="1.5"/><rect x="14" y="12" width="7" height="9" rx="1.5" stroke="currentColor" stroke-width="1.5"/><rect x="3" y="16" width="7" height="5" rx="1.5" stroke="currentColor" stroke-width="1.5"/></svg>
                        <span style="font-size:15px;font-weight:700;color:var(--text-secondary);">Selecciona un tablero</span>
                        <span style="font-size:12px;color:var(--text-ghost);max-width:220px;line-height:1.5;">Elige un tablero en la barra izquierda para empezar.</span>
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
        style="max-width:520px;background:var(--bg-surface);border-left:1px solid var(--border-main);box-shadow:var(--shadow-drawer);">
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
    <div id="toast" class="fixed bottom-6 left-1/2 z-[60]">
        <div id="toast-msg">Listo</div>
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

    <!-- ===== SIDEBAR UX: Search · Favorites · Collapse ===== -->
    <script>
    (function () {
        'use strict';

        var FAV_KEY    = 'fyc-fav-boards';
        var COL_PREFIX = 'fyc-sidebar-collapsed-';

        /* ── Helpers ─────────────────────────────────────────── */
        function getFavIds() {
            try { return JSON.parse(localStorage.getItem(FAV_KEY) || '[]'); } catch(e) { return []; }
        }
        function saveFavIds(ids) { localStorage.setItem(FAV_KEY, JSON.stringify(ids)); }
        function isFav(id) { return getFavIds().indexOf(String(id)) !== -1; }

        function isCollapsed(key) { return localStorage.getItem(COL_PREFIX + key) === '1'; }
        function saveCollapsed(key, val) { localStorage.setItem(COL_PREFIX + key, val ? '1' : '0'); }

        function esc(s) {
            return String(s)
                .replace(/&/g,'&amp;').replace(/</g,'&lt;')
                .replace(/>/g,'&gt;').replace(/"/g,'&quot;');
        }

        function starSvg(filled) {
            var fill   = filled ? 'var(--fyc-red)' : 'none';
            var stroke = filled ? 'var(--fyc-red)' : 'currentColor';
            return '<svg viewBox="0 0 24 24" style="width:11px;height:11px;" fill="' + fill + '"'
                 + ' stroke="' + stroke + '" stroke-width="2">'
                 + '<polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/>'
                 + '</svg>';
        }

        /* ── Board map (construido desde el DOM al init) ─────── */
        var boardMap = {};
        function buildBoardMap() {
            boardMap = {};
            document.querySelectorAll('.board-card[data-board-id]').forEach(function(el) {
                var id = el.dataset.boardId;
                if (id && !boardMap[id]) {
                    boardMap[id] = {
                        id:    id,
                        name:  el.dataset.boardName  || '',
                        team:  el.dataset.teamName   || '',
                        color: el.dataset.color      || '#d32f57'
                    };
                }
            });
        }

        /* ── Favorites ───────────────────────────────────────── */
        function toggleFav(id) {
            id = String(id);
            var ids = getFavIds();
            var idx = ids.indexOf(id);
            if (idx !== -1) ids.splice(idx, 1); else ids.push(id);
            saveFavIds(ids);
            renderFavSection();
            refreshStars();
        }

        function renderFavSection() {
            var sec = document.getElementById('sbFavSection');
            if (!sec) return;
            var ids = getFavIds();
            if (!ids.length) { sec.innerHTML = ''; return; }

            // Cabecera de sección
            var html = '<div style="margin-bottom:4px;display:flex;align-items:center;justify-content:space-between;">'
                     + '<div style="display:flex;align-items:center;gap:6px;">'
                     + '<span style="width:3px;height:13px;background:var(--fyc-red);border-radius:2px;display:inline-block;"></span>'
                     + '<span class="fyc-sidebar-label" style="margin-bottom:0;">Favoritos</span>'
                     + '</div>'
                     + '<span style="font-size:10px;color:var(--text-ghost);font-weight:600;">' + ids.length + '</span>'
                     + '</div>';

            // Pin strip — filas compactas (no duplica cards completas)
            html += '<div class="sb-pin-strip">';
            ids.forEach(function(id) {
                var b = boardMap[id];
                if (!b) return; // tablero no visible para este usuario
                var teamPart = b.team
                    ? '<span class="sb-pin-team">' + esc(b.team) + '</span>'
                    : '';
                html += '<div class="sb-pin-row">'
                      + '<span class="sb-pin-dot" style="background:' + esc(b.color) + ';"></span>'
                      + '<button class="sb-pin-btn" type="button"'
                      + ' data-open-board="' + esc(id) + '" data-title="' + esc(b.name) + '">'
                      + '<span class="sb-pin-name">' + esc(b.name) + '</span>'
                      + '</button>'
                      + teamPart
                      + '<button class="sb-pin-remove" type="button"'
                      + ' data-fav-id="' + esc(id) + '" title="Quitar de favoritos">&times;</button>'
                      + '</div>';
            });
            html += '</div>';
            html += '<div style="height:1px;background:var(--border-main);margin:8px 0 10px;"></div>';

            sec.innerHTML = html;

            // Abrir tablero al hacer clic en la fila del pin strip
            sec.querySelectorAll('.sb-pin-btn[data-open-board]').forEach(function(btn) {
                btn.addEventListener('click', function() {
                    // Delegar al mecanismo existente: disparar click en una card real
                    var realCard = document.querySelector(
                        '.board-card[data-board-id="' + btn.dataset.openBoard + '"] .board-card-info'
                    );
                    if (realCard) realCard.click();
                });
            });

            // Quitar favorito desde el pin strip
            sec.querySelectorAll('.sb-pin-remove').forEach(function(btn) {
                btn.addEventListener('click', function(e) {
                    e.stopPropagation();
                    toggleFav(btn.dataset.favId);
                });
            });
        }

        function refreshStars() {
            document.querySelectorAll('.board-card[data-board-id]').forEach(function(card) {
                var id   = card.dataset.boardId;
                var star = card.querySelector('.sb-star[data-fav-id]');
                var active = isFav(id);

                // Indicador permanente: borde izquierdo vinotinto
                card.classList.toggle('is-fav', active);

                // Actualizar estado del botón estrella
                if (star) {
                    star.setAttribute('aria-pressed', active ? 'true' : 'false');
                    star.innerHTML = starSvg(active);
                    star.title = active ? 'Quitar de favoritos' : 'Marcar como favorito';
                }
            });
        }

        /* ── Collapse ─────────────────────────────────────────── */
        function initCollapse() {
            document.querySelectorAll('[data-sb-toggle]').forEach(function(btn) {
                var key     = btn.dataset.sbToggle;
                var target  = document.getElementById('sbCards-' + key);
                var chevron = btn.querySelector('.sb-chevron');
                if (!target) return;

                function applyState(collapsed) {
                    target.style.display = collapsed ? 'none' : '';
                    if (chevron) chevron.style.transform = collapsed ? 'rotate(-90deg)' : 'rotate(0deg)';
                }

                applyState(isCollapsed(key));

                btn.addEventListener('click', function() {
                    var next = !isCollapsed(key);
                    saveCollapsed(key, next);
                    applyState(next);
                });
            });
        }

        /* ── Search ───────────────────────────────────────────── */
        function initSearch() {
            var input = document.getElementById('sbSearch');
            if (!input) return;

            input.addEventListener('input', function() {
                var q      = this.value.trim().toLowerCase();
                var favSec = document.getElementById('sbFavSection');

                if (!q) {
                    // Resetear: mostrar todo y restaurar estado de colapso
                    document.querySelectorAll('.board-card[data-board-id]').forEach(function(el) {
                        el.style.display = '';
                    });
                    document.querySelectorAll('[data-sb-group]').forEach(function(el) {
                        el.style.display = '';
                    });
                    if (favSec) favSec.style.display = '';
                    document.querySelectorAll('[data-sb-toggle]').forEach(function(btn) {
                        var key    = btn.dataset.sbToggle;
                        var target = document.getElementById('sbCards-' + key);
                        if (target) target.style.display = isCollapsed(key) ? 'none' : '';
                    });
                    return;
                }

                // Durante la búsqueda: ocultar favoritos (evita duplicados) y expandir todo
                if (favSec) favSec.style.display = 'none';
                document.querySelectorAll('[data-sb-toggle]').forEach(function(btn) {
                    var target = document.getElementById('sbCards-' + btn.dataset.sbToggle);
                    if (target) target.style.display = '';
                });

                // Filtrar tarjetas por nombre de tablero o nombre de equipo
                document.querySelectorAll('.board-card[data-board-id]').forEach(function(el) {
                    var name = (el.dataset.boardName || '').toLowerCase();
                    var team = (el.dataset.teamName  || '').toLowerCase();
                    el.style.display = (name.indexOf(q) !== -1 || team.indexOf(q) !== -1) ? '' : 'none';
                });

                // Ocultar secciones enteras si no tienen ninguna tarjeta visible
                document.querySelectorAll('[data-sb-group]').forEach(function(grp) {
                    var key   = grp.dataset.sbGroup;
                    var cards = document.querySelectorAll('#sbCards-' + key + ' .board-card[data-board-id]');
                    var hasVis = Array.from(cards).some(function(c) { return c.style.display !== 'none'; });
                    grp.style.display = hasVis ? '' : 'none';
                });
            });
        }

        /* ── Init ─────────────────────────────────────────────── */
        buildBoardMap();
        renderFavSection();
        refreshStars();
        initCollapse();
        initSearch();

        // Bind clics en estrellas de tarjetas estáticas
        document.querySelectorAll('.board-card[data-board-id] .sb-star').forEach(function(btn) {
            btn.addEventListener('click', function(e) {
                e.stopPropagation();
                toggleFav(btn.dataset.favId);
            });
        });

    })();
    </script>

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

            // Sincroniza el botón "Miembros" del header con los metadatos
            // que view.php (embed) publica en #board-meta.
            function syncMembersBtn() {
                var meta = document.querySelector('#boardMount #board-meta');
                var btn  = byId('btnBoardMembers');
                if (!btn) return;
                if (meta && meta.dataset.canManage === '1') {
                    btn.textContent = '👥 Miembros (' + (meta.dataset.memberCount || '0') + ')';
                    btn.style.display = '';
                } else {
                    btn.style.display = 'none';
                }
            }

            // Abrir el modal que vive dentro del embed cuando se hace clic en el botón del workspace.
            var btnMembers = byId('btnBoardMembers');
            if (btnMembers) {
                btnMembers.addEventListener('click', function () {
                    var modal = document.querySelector('#boardMount #membersModal');
                    if (modal) modal.style.display = 'flex';
                });
            }

            // Re-sincronizar el botón cuando board-view.js recarga el embed
            // (tras mover/editar/borrar tareas).
            document.addEventListener('fcplanner:board-reloaded', syncMembersBtn);

            // ---- PRESENCIA EN TIEMPO REAL ----
            var presenceInterval = null;
            var currentBoardId   = 0;   // actualizado por loadBoard, usado para navegación desde campana
            var presenceBoardId  = null;
            var PRESENCE_COLORS  = ['#e85070','#4090e8','#40a060','#d4a040','#9070e8','#e870b0','#50b0a0','#e87050'];
            var MY_USER_ID       = <?= (int) ($_SESSION['user_id'] ?? 0) ?>;
            var MAX_AVATARS      = 4;

            function renderPresence(active) {
                var container = byId('presence');
                if (!container) return;
                container.innerHTML = '';
                if (!active || !active.length) return;
                var shown = active.slice(0, MAX_AVATARS);
                var extra = active.length - shown.length;
                shown.forEach(function (u) {
                    var isMe   = u.id === MY_USER_ID;
                    var color  = PRESENCE_COLORS[u.id % PRESENCE_COLORS.length];
                    var initial = (u.nombre || '?').charAt(0).toUpperCase();
                    var av = document.createElement('div');
                    av.title = isMe ? u.nombre + ' (tú)' : u.nombre;
                    av.style.cssText = 'width:26px;height:26px;border-radius:50%;'
                        + 'background:' + color + ';'
                        + 'display:flex;align-items:center;justify-content:center;'
                        + 'font-size:11px;font-weight:700;color:#fff;flex-shrink:0;cursor:default;'
                        + 'border:2px solid ' + (isMe ? 'var(--fyc-red)' : 'var(--bg-sidebar)') + ';'
                        + 'box-shadow:0 1px 4px rgba(0,0,0,0.3);';
                    av.textContent = initial;
                    container.appendChild(av);
                });
                if (extra > 0) {
                    var more = document.createElement('div');
                    more.style.cssText = 'width:26px;height:26px;border-radius:50%;'
                        + 'background:var(--border-accent);'
                        + 'display:flex;align-items:center;justify-content:center;'
                        + 'font-size:10px;font-weight:700;color:var(--text-ghost);'
                        + 'cursor:default;flex-shrink:0;';
                    more.textContent = '+' + extra;
                    container.appendChild(more);
                }
            }

            function startPresence(boardId, csrf) {
                clearInterval(presenceInterval);
                presenceBoardId = boardId;

                function doPing() {
                    if (!presenceBoardId) return;
                    var fd = new FormData();
                    fd.set('csrf', csrf);
                    fd.set('board_id', presenceBoardId);
                    fetch('./presence_ping.php', {
                        method: 'POST', body: fd,
                        headers: { 'X-Requested-With': 'fetch' }
                    })
                    .then(function (r) { return r.json(); })
                    .then(function (data) { renderPresence(data.active || []); })
                    .catch(function () { /* falla silenciosamente */ });
                }

                doPing(); // primer ping inmediato
                presenceInterval = setInterval(doPing, 20000);
            }

            function stopPresence() {
                clearInterval(presenceInterval);
                presenceInterval = null;
                presenceBoardId  = null;
                renderPresence([]);
            }

            function loadBoard(boardId, title, onLoaded) {
                if (!boardId) return;
                stopPresence();
                // Detener el polling de eventos del tablero anterior
                if (window.FCPlannerBoard && typeof window.FCPlannerBoard.stopEventsPoll === 'function') {
                    window.FCPlannerBoard.stopEventsPoll();
                }
                byId('boardTitle').textContent = title || ('Tablero #' + boardId);
                var mount = byId('boardMount');
                mount.innerHTML = '<div style="display:flex;flex-direction:column;align-items:center;justify-content:center;min-height:320px;gap:14px;">'
                    + '<div style="width:34px;height:34px;border:3px solid var(--border-accent);border-top-color:var(--fyc-red);border-radius:50%;animation:fyc-spin 0.75s linear infinite;"></div>'
                    + '<span style="font-size:13px;color:var(--text-ghost);">Cargando tablero…</span>'
                    + '</div>';
                fetch('./view.php?id=' + encodeURIComponent(boardId) + '&embed=1', { headers: { 'X-Requested-With': 'fetch' } })
                    .then(function (r) { return r.text(); })
                    .then(function (html) {
                        mount.innerHTML = html;
                        if (window.FCPlannerBoard && typeof window.FCPlannerBoard.destroy === 'function') window.FCPlannerBoard.destroy();
                        if (window.FCPlannerBoard && typeof window.FCPlannerBoard.init === 'function') window.FCPlannerBoard.init(mount);
                        if (window.FCPlannerBoard && typeof window.FCPlannerBoard.runEmbedScripts === 'function') window.FCPlannerBoard.runEmbedScripts(mount);
                        syncMembersBtn();
                        // Persistir el tablero actual en la URL y en variable local
                        history.replaceState(null, '', '?board=' + boardId);
                        currentBoardId = parseInt(boardId, 10);
                        // Arrancar presencia con boardId y csrf del embed recién inyectado
                        var kanban = mount.querySelector('#kanban');
                        var bCsrf  = kanban ? kanban.dataset.csrf : null;
                        if (kanban && bCsrf) startPresence(boardId, bCsrf);
                        // Arrancar el polling de eventos desde el último id conocido
                        var meta = mount.querySelector('#board-meta');
                        var lastEventId = meta ? parseInt(meta.dataset.lastEventId || '0', 10) : 0;
                        if (window.FCPlannerBoard && typeof window.FCPlannerBoard.startEventsPoll === 'function') {
                            window.FCPlannerBoard.startEventsPoll(lastEventId);
                        }
                        // Callback opcional (usado por la campana para abrir tarea)
                        if (typeof onLoaded === 'function') onLoaded();
                    })
                    .catch(function () { mount.innerHTML = '<div style="padding:32px;font-size:13px;color:var(--badge-overdue-tx);">No se pudo cargar el tablero.</div>'; });
            }

            document.addEventListener('click', function (ev) {
                var btn = ev.target.closest('[data-open-board]');
                if (!btn) return;
                loadBoard(btn.getAttribute('data-open-board'), btn.getAttribute('data-title') || '');
            });

            // Abrir tablero solicitado vía ?board=X (redirect desde view.php u otros),
            // con fallback al primer tablero de la barra lateral.
            var requestedBoard = <?= isset($_GET['board']) ? (int) $_GET['board'] : 0 ?>;
            var firstId = requestedBoard || <?= (int) $firstBoardId ?>;
            if (firstId) {
                var el = document.querySelector('[data-open-board="' + firstId + '"]');
                loadBoard(firstId, el ? (el.getAttribute('data-title') || '') : '');
            }

            // Puente de scope: expone loadBoard y currentBoardId para la IIFE de notificaciones
            window.FCPlannerWorkspace = {
                loadBoard:  function (id, title, cb) { loadBoard(id, title, cb); },
                getBoardId: function () { return currentBoardId; }
            };
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

        // ============================================================
        // NOTIFICACIONES
        // ============================================================
        (function () {
            'use strict';
            var CSRF         = <?= json_encode($_SESSION['csrf'] ?? '') ?>;
            var notifUnread       = [];    // no leídas
            var notifRecent       = [];    // leídas (solo las marcadas en esta sesión)
            var sessionHasRecent  = false; // true solo cuando el usuario marca algo en esta sesión
            var panelOpen         = false;
            var pollTimer         = null;

            var wrapper  = document.getElementById('notifWrapper');
            var btn      = document.getElementById('notifBtn');
            var panel    = document.getElementById('notifPanel');
            var badge    = document.getElementById('notifBadge');
            var list     = document.getElementById('notifList');
            var markAll  = document.getElementById('notifMarkAll');
            if (!btn || !panel || !badge || !list) return;

            // ---- Tiempo relativo ----
            function timeAgo(dateStr) {
                if (!dateStr) return '';
                var diff = Math.floor((Date.now() - new Date(dateStr).getTime()) / 1000);
                if (diff < 60)   return 'hace ' + diff + 's';
                if (diff < 3600) return 'hace ' + Math.floor(diff / 60) + 'min';
                if (diff < 86400) return 'hace ' + Math.floor(diff / 3600) + 'h';
                return 'hace ' + Math.floor(diff / 86400) + 'd';
            }

            // ---- HTML de un ítem ----
            function itemHtml(item, isUnread) {
                var dot = isUnread
                    ? '<div style="flex-shrink:0;width:8px;height:8px;border-radius:50%;background:var(--fyc-red);margin-top:5px;"></div>'
                    : '<div style="flex-shrink:0;width:8px;height:8px;border-radius:50%;background:var(--border-accent);margin-top:5px;"></div>';
                var titleColor  = isUnread ? 'var(--text-primary)' : 'var(--text-ghost)';
                var cursor      = isUnread ? 'cursor:pointer;' : 'cursor:default;';
                var dataAction  = isUnread ? ' data-unread="1"' : '';
                return '<div class="fyc-notif-item" data-id="' + item.id + '"' + dataAction
                    + (item.board_id ? ' data-board-id="' + item.board_id + '"' : '')
                    + (item.task_id  ? ' data-task-id="'  + item.task_id  + '"' : '')
                    + ' style="display:flex;align-items:flex-start;gap:10px;padding:10px 16px;'
                    + cursor + 'border-bottom:1px solid var(--border-main);transition:background 0.12s;"'
                    + (isUnread ? ' onmouseenter="this.style.background=\'var(--bg-hover)\'" onmouseleave="this.style.background=\'\'"' : '')
                    + '>'
                    + dot
                    + '<div style="flex:1;min-width:0;">'
                    + '<div style="font-size:12px;color:' + titleColor + ';line-height:1.4;'
                    + 'white-space:nowrap;overflow:hidden;text-overflow:ellipsis;" title="'
                    + item.title.replace(/"/g, '&quot;') + '">' + item.title + '</div>'
                    + '<div style="font-size:10px;color:var(--text-ghost);margin-top:2px;">' + timeAgo(item.when) + '</div>'
                    + '</div></div>';
            }

            // ---- Renderizar badge ----
            function renderBadge() {
                var n = notifUnread.length;
                if (n === 0) {
                    badge.style.display = 'none';
                } else {
                    badge.style.display = 'inline-block';
                    badge.textContent = n >= 10 ? '9+' : String(n);
                }
            }

            // ---- Renderizar lista (dos grupos) ----
            function renderList() {
                var html = '';
                var hasAny = notifUnread.length > 0 || notifRecent.length > 0;

                if (!hasAny) {
                    list.innerHTML = '<div style="padding:24px 16px 20px;text-align:center;display:flex;flex-direction:column;align-items:center;gap:6px;">'
                        + '<img src="../assets/ovi/ovi-feliz.svg" alt="" width="48" height="48" class="ovi-float" style="opacity:0.8;pointer-events:none;" draggable="false">'
                        + '<span style="font-size:13px;font-weight:600;color:var(--text-faint);">Todo al día</span>'
                        + '<span style="font-size:11px;color:var(--text-ghost);">No tienes notificaciones pendientes.</span>'
                        + '</div>';
                    if (markAll) markAll.style.display = 'none';
                    return;
                }

                // Grupo: no leídas
                if (notifUnread.length > 0) {
                    if (markAll) markAll.style.display = 'inline';
                    notifUnread.forEach(function (item) { html += itemHtml(item, true); });
                } else {
                    if (markAll) markAll.style.display = 'none';
                }

                // Separador + grupo recientes
                if (notifRecent.length > 0) {
                    html += '<div style="padding:6px 16px 4px;font-size:10px;font-weight:700;'
                        + 'color:var(--text-ghost);text-transform:uppercase;letter-spacing:0.8px;'
                        + 'background:var(--bg-main);border-top:1px solid var(--border-main);'
                        + 'border-bottom:1px solid var(--border-main);">Recientes</div>';
                    notifRecent.forEach(function (item) { html += itemHtml(item, false); });
                }

                list.innerHTML = html;
            }

            // ---- Fetch notificaciones ----
            function fetchNotifs() {
                fetch('../notifications/feed.php', { headers: { 'X-Requested-With': 'fetch' } })
                    .then(function (r) { return r.ok ? r.json() : null; })
                    .then(function (data) {
                        if (!data) return;
                        notifUnread = Array.isArray(data.unread) ? data.unread : [];
                        // Solo sincronizar recientes del servidor si el usuario ya
                        // marcó algo en esta sesión. De lo contrario el historial
                        // del DB impediría que aparezca el empty state con OVI.
                        if (sessionHasRecent) {
                            notifRecent = Array.isArray(data.recent) ? data.recent : [];
                        }
                        renderBadge();
                        if (panelOpen) renderList();
                    })
                    .catch(function () {});
            }

            // ---- Marcar una como leída (optimista: baja a recientes) ----
            function markRead(noteId) {
                var idx = -1;
                for (var i = 0; i < notifUnread.length; i++) {
                    if (notifUnread[i].id === noteId) { idx = i; break; }
                }
                if (idx === -1) return;

                var item = notifUnread.splice(idx, 1)[0];
                sessionHasRecent = true;           // activar historial en sesión
                notifRecent.unshift(item);         // aparece primero en recientes
                if (notifRecent.length > 10) notifRecent.pop(); // mantener límite visual

                var fd = new FormData();
                fd.append('csrf', CSRF);
                fd.append('note_id', noteId);
                fetch('../notifications/mark_read.php', { method: 'POST', body: fd })
                    .catch(function () {});

                renderBadge();
                renderList();
            }

            // ---- Marcar todas como leídas ----
            function markAllRead() {
                // Mover todas las no leídas a recientes (al principio, respetando límite)
                sessionHasRecent = true;           // activar historial en sesión
                var moved = notifUnread.splice(0);
                notifRecent = moved.concat(notifRecent).slice(0, 10);
                notifUnread = [];

                var fd = new FormData();
                fd.append('csrf', CSRF);
                fd.append('all', '1');
                fetch('../notifications/mark_read.php', { method: 'POST', body: fd })
                    .catch(function () {});

                renderBadge();
                renderList();
            }

            // ---- Abrir / cerrar panel ----
            function openPanel() {
                panelOpen = true;
                panel.style.display = 'block';
                renderList();
            }
            function closePanel() {
                panelOpen = false;
                panel.style.display = 'none';
            }

            // Toggle en el botón campana
            btn.addEventListener('click', function (e) {
                e.stopPropagation();
                if (panelOpen) { closePanel(); } else { openPanel(); }
            });

            // Clic en ítem no leído → marcar leída + navegar al contexto
            list.addEventListener('click', function (e) {
                var item = e.target.closest('.fyc-notif-item[data-unread="1"]');
                if (!item) return;
                var noteId  = parseInt(item.dataset.id, 10);
                var boardId = item.dataset.boardId ? parseInt(item.dataset.boardId, 10) : 0;
                var taskId  = item.dataset.taskId  ? parseInt(item.dataset.taskId,  10) : 0;

                markRead(noteId);
                closePanel();

                if (!boardId) return; // sin contexto de tablero, solo marcar leída

                var currentBoard = window.FCPlannerWorkspace ? window.FCPlannerWorkspace.getBoardId() : 0;

                function openTaskIfNeeded() {
                    if (taskId && window.FCPlannerBoard && typeof window.FCPlannerBoard.openTask === 'function') {
                        window.FCPlannerBoard.openTask(taskId);
                    }
                }

                if (currentBoard === boardId) {
                    // Ya estamos en el tablero correcto → solo abrir la tarea
                    openTaskIfNeeded();
                } else {
                    // Cambiar al tablero y luego abrir la tarea
                    var el = document.querySelector('[data-open-board="' + boardId + '"]');
                    var title = el ? (el.getAttribute('data-title') || '') : '';
                    if (window.FCPlannerWorkspace) window.FCPlannerWorkspace.loadBoard(boardId, title, openTaskIfNeeded);
                }
            });

            // Marcar todas
            if (markAll) {
                markAll.addEventListener('click', function (e) {
                    e.stopPropagation();
                    markAllRead();
                });
            }

            // Cerrar al hacer clic fuera
            document.addEventListener('click', function (e) {
                if (panelOpen && !wrapper.contains(e.target)) closePanel();
            });

            // Cerrar con Escape
            document.addEventListener('keydown', function (e) {
                if (e.key === 'Escape' && panelOpen) closePanel();
            });

            // ---- Polling cada 30s ----
            fetchNotifs(); // primera carga inmediata
            pollTimer = setInterval(fetchNotifs, 30000);
        })();
    </script>

    <script src="../assets/boards-actions.js?v=1" defer></script>
</body>

</html>