<?php
// public/boards/workspace.php
require_once __DIR__ . '/../_auth.php';
require_login();
require_once __DIR__ . '/../../config/db.php';

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

// Detectar columna de archivado (igual que en index)
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
?>
<!doctype html>
<html lang="es">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>F&C Planner · Workspace</title>
    <link rel="stylesheet" href="../assets/app.css">
    <style>
        .iconBtn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 34px;
            height: 34px;
            border-radius: 14px;
            border: 1px solid rgba(211, 47, 87, .20);
            background: rgba(249, 238, 241, .9);
            color: #942934;
            transition: transform .2s, box-shadow .2s, background .2s
        }

        .iconBtn:hover {
            transform: scale(1.03);
            box-shadow: 0 10px 25px rgba(148, 41, 52, .12);
            background: rgba(249, 238, 241, 1)
        }

        .iconBtn:active {
            transform: scale(.98)
        }

        .iconBtnDanger {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 34px;
            height: 34px;
            border-radius: 14px;
            border: 1px solid rgba(220, 38, 38, .20);
            background: rgba(254, 242, 242, .9);
            color: rgb(185, 28, 28);
            transition: transform .2s, box-shadow .2s, background .2s
        }

        .iconBtnDanger:hover {
            transform: scale(1.03);
            box-shadow: 0 10px 25px rgba(185, 28, 28, .10);
            background: rgba(254, 242, 242, 1)
        }

        .iconBtnDanger:active {
            transform: scale(.98)
        }

        .sidebarScroll::-webkit-scrollbar {
            width: 10px
        }

        .sidebarScroll::-webkit-scrollbar-thumb {
            background: rgba(148, 41, 52, .18);
            border-radius: 999px
        }
    </style>
    <script>
        window.FCPlannerCurrentUserName = <?= json_encode($_SESSION['user_nombre'] ?? 'Usuario') ?>;
    </script>
    <script src="../assets/board-view.js?v=1" defer></script>
</head>

<body class="h-screen overflow-hidden bg-[#f7f4f5] text-gray-900">
    <!-- manchas suaves -->
    <!-- HEADER GLOBAL -->
    <div class="w-full bg-white border-b border-gray-200 px-6 py-3 flex items-center justify-between">
        <div>
            <div class="text-xs text-gray-500">Bienvenido</div>
            <div class="text-sm font-black text-[#942934]">
                <?= h($_SESSION['nombre'] ?? 'Usuario') ?>
            </div>
        </div>
        <div class="flex items-center gap-3">
            <a href="./index.php"
                class="rounded-2xl bg-[#f9eef1] px-4 py-2 text-xs font-black text-[#942934] border border-[#d32f57]/30 hover:scale-[1.01] transition">
                ⚙ Administrar tableros de
            </a>

            <a href="../logout.php"
                class="rounded-2xl bg-red-600 px-4 py-2 text-xs font-black text-white hover:scale-[1.01] transition">
                Cerrar sesión
            </a>
        </div>
    </div>
    <div class="pointer-events-none fixed inset-0 -z-10">
        <div class="absolute -top-24 -left-24 h-72 w-72 rounded-full blur-3xl opacity-25 bg-[#d32f57]"></div>
        <div class="absolute top-40 -right-24 h-72 w-72 rounded-full blur-3xl opacity-20 bg-[#942934]"></div>
    </div>

    <div class="h-[calc(100%-64px)] grid grid-cols-12">
        <!-- SIDEBAR -->
        <aside
            class="col-span-12 md:col-span-4 lg:col-span-3 h-full border-r border-gray-200/80 bg-white/70 backdrop-blur">
            <div class="h-full flex flex-col">
                <!-- Header sidebar -->
                <div class="p-5 border-b border-gray-200/70">
                    <div class="flex items-start justify-between gap-3">
                        <div>
                            <h1 class="text-lg font-black">Tableros · <span class="text-[#942934]">F&amp;C
                                    Planner</span></h1>
                            <p class="text-xs text-gray-600 mt-1">Todo en una sola página (estilo Notion).</p>
                        </div>
                    </div>

                    <!-- Crear tablero INLINE (lo que “se perdió” de tu captura 1) -->
                    <form class="mt-4 grid grid-cols-12 gap-2" method="POST" action="./create.php?return=workspace">
                        <input type="hidden" name="csrf" value="<?= h($_SESSION['csrf']) ?>">
                        <div class="col-span-7">
                            <label class="block text-[11px] font-black text-gray-700">Nombre</label>
                            <input name="nombre" required
                                class="mt-1 w-full rounded-2xl border border-gray-300 bg-white p-2.5 text-xs placeholder:text-gray-500 placeholder:font-medium transition-all duration-300 focus:ring-2 focus:ring-[#d32f57]"
                                placeholder="Ej. Comercial, Personal, TI..." />
                        </div>
                        <div class="col-span-3">
                            <label class="block text-[11px] font-black text-gray-700">Color</label>
                            <input name="color_hex" value="#d32f57"
                                class="mt-1 w-full rounded-2xl border border-gray-300 bg-white p-2.5 text-xs transition-all duration-300 focus:ring-2 focus:ring-[#d32f57]" />
                        </div>
                        <div class="col-span-2 flex items-end">
                            <button type="submit"
                                class="w-full rounded-2xl bg-[#d32f57] px-3 py-2.5 text-xs font-black text-white shadow-lg shadow-[#d32f57]/20 transition-all duration-300 hover:scale-[1.01] active:scale-[0.98]">Crear</button>
                        </div>
                    </form>
                </div>

                <!-- Listado -->
                <div class="sidebarScroll flex-1 overflow-auto p-4 space-y-6">
                    <!-- Personales -->
                    <section>
                        <div class="flex items-center justify-between">
                            <h2 class="text-sm font-black">Personales</h2>
                            <span class="text-[11px] text-gray-500">
                                <?= (int) count($personalActive) ?>
                            </span>
                        </div>

                        <div class="mt-3 space-y-3">
                            <?php foreach ($personalActive as $b): ?>
                                <?php [$roleTxt, $roleCls] = badgeRole($b['my_role']); ?>
                                <div class="rounded-3xl border border-gray-200 bg-white p-4 shadow-sm">
                                    <div class="flex items-start justify-between gap-3">
                                        <button type="button" class="min-w-0 text-left flex-1"
                                            data-open-board="<?= (int) $b['id'] ?>" data-title="<?= h($b['nombre']) ?>"
                                            title="Abrir en el panel derecho">
                                            <div class="flex items-center gap-2">
                                                <span class="inline-block h-3.5 w-3.5 rounded-full"
                                                    style="background: <?= h($b['color_hex'] ?: '#d32f57') ?>;"></span>
                                                <div class="truncate text-sm font-black">
                                                    <?= h($b['nombre']) ?>
                                                </div>
                                            </div>
                                            <div class="mt-2 flex items-center gap-2">
                                                <span
                                                    class="inline-flex items-center rounded-full px-2.5 py-1 text-[11px] font-black <?= h($roleCls) ?>">
                                                    <?= h($roleTxt) ?>
                                                </span>
                                                <span class="text-[11px] text-gray-500">Personal</span>
                                            </div>
                                        </button>

                                        <div class="flex items-center gap-1">
                                            <button class="iconBtn" type="button" title="Editar" data-action="edit"
                                                data-id="<?= (int) $b['id'] ?>" data-name="<?= h($b['nombre']) ?>"
                                                data-color="<?= h($b['color_hex'] ?: '#d32f57') ?>">
                                                <svg viewBox="0 0 24 24" fill="none" class="h-4 w-4" stroke="currentColor"
                                                    stroke-width="2">
                                                    <path d="M12 20h9" stroke-linecap="round" />
                                                    <path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z"
                                                        stroke-linejoin="round" />
                                                </svg>
                                            </button>
                                            <button class="iconBtn" type="button" title="Duplicar" data-action="dup"
                                                data-id="<?= (int) $b['id'] ?>" data-name="<?= h($b['nombre']) ?>">
                                                <svg viewBox="0 0 24 24" fill="none" class="h-4 w-4" stroke="currentColor"
                                                    stroke-width="2">
                                                    <path d="M8 8h12v12H8z" stroke-linejoin="round" />
                                                    <path d="M4 16H3a1 1 0 0 1-1-1V3a1 1 0 0 1 1-1h12a1 1 0 0 1 1 1v1"
                                                        stroke-linecap="round" />
                                                </svg>
                                            </button>
                                            <button class="iconBtn" type="button" title="Archivar" data-action="arc"
                                                data-id="<?= (int) $b['id'] ?>" data-name="<?= h($b['nombre']) ?>">
                                                <svg viewBox="0 0 24 24" fill="none" class="h-4 w-4" stroke="currentColor"
                                                    stroke-width="2">
                                                    <path d="M21 8v13H3V8" stroke-linejoin="round" />
                                                    <path d="M1 3h22v5H1z" stroke-linejoin="round" />
                                                    <path d="M10 12h4" stroke-linecap="round" />
                                                </svg>
                                            </button>
                                            <button class="iconBtnDanger" type="button" title="Eliminar" data-action="del"
                                                data-id="<?= (int) $b['id'] ?>" data-name="<?= h($b['nombre']) ?>">
                                                <svg viewBox="0 0 24 24" fill="none" class="h-4 w-4" stroke="currentColor"
                                                    stroke-width="2">
                                                    <path d="M3 6h18" stroke-linecap="round" />
                                                    <path d="M8 6V4h8v2" stroke-linejoin="round" />
                                                    <path d="M6 6l1 16h10l1-16" stroke-linejoin="round" />
                                                </svg>
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                            <?php if (!count($personalActive)): ?>
                                <div class="rounded-3xl border border-gray-200 bg-white p-4 text-sm text-gray-600">No hay
                                    tableros personales activos.</div>
                            <?php endif; ?>
                        </div>

                        <details class="mt-3 rounded-3xl border border-gray-200 bg-white p-4 shadow-sm">
                            <summary class="cursor-pointer select-none text-sm font-black text-gray-800">
                                Archivados personales (
                                <?= (int) count($personalArchived) ?>)
                            </summary>
                            <div class="mt-3 space-y-2">
                                <?php foreach ($personalArchived as $b): ?>
                                    <div class="rounded-2xl border border-gray-200 bg-white p-3">
                                        <div class="flex items-center justify-between gap-2">
                                            <button type="button" class="min-w-0 flex-1 text-left"
                                                data-open-board="<?= (int) $b['id'] ?>" data-title="<?= h($b['nombre']) ?>"
                                                title="Abrir (solo lectura si aplica)">
                                                <div class="truncate text-sm font-black">
                                                    <?= h($b['nombre']) ?>
                                                </div>
                                                <div class="text-[11px] text-gray-500">Archivado</div>
                                            </button>
                                            <div class="flex items-center gap-1">
                                                <button class="iconBtn" type="button" title="Restaurar" data-action="res"
                                                    data-id="<?= (int) $b['id'] ?>" data-name="<?= h($b['nombre']) ?>">
                                                    <svg viewBox="0 0 24 24" fill="none" class="h-4 w-4"
                                                        stroke="currentColor" stroke-width="2">
                                                        <path d="M3 12a9 9 0 0 1 15.5-6.5" stroke-linecap="round" />
                                                        <path d="M18 3v6h-6" stroke-linejoin="round" />
                                                        <path d="M21 12a9 9 0 0 1-15.5 6.5" stroke-linecap="round" />
                                                        <path d="M6 21v-6h6" stroke-linejoin="round" />
                                                    </svg>
                                                </button>
                                                <button class="iconBtnDanger" type="button" title="Eliminar"
                                                    data-action="del" data-id="<?= (int) $b['id'] ?>"
                                                    data-name="<?= h($b['nombre']) ?>">
                                                    <svg viewBox="0 0 24 24" fill="none" class="h-4 w-4"
                                                        stroke="currentColor" stroke-width="2">
                                                        <path d="M3 6h18" stroke-linecap="round" />
                                                        <path d="M8 6V4h8v2" stroke-linejoin="round" />
                                                        <path d="M6 6l1 16h10l1-16" stroke-linejoin="round" />
                                                    </svg>
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                                <?php if (!count($personalArchived)): ?>
                                    <div class="text-sm text-gray-600">Sin archivados.</div>
                                <?php endif; ?>
                            </div>
                        </details>
                    </section>

                    <!-- Equipos -->
                    <section>
                        <div class="flex items-center justify-between">
                            <h2 class="text-sm font-black">Equipos</h2>
                            <span class="text-[11px] text-gray-500">
                                <?= (int) count($teamActive) ?>
                            </span>
                        </div>

                        <div class="mt-3 space-y-3">
                            <?php foreach ($teamActive as $b): ?>
                                <?php [$roleTxt, $roleCls] = badgeRole($b['my_role']); ?>
                                <?php $teamName = $b['team_id'] ? ($teamsById[(int) $b['team_id']] ?? ('Equipo #' . (int) $b['team_id'])) : 'Equipo'; ?>
                                <div class="rounded-3xl border border-gray-200 bg-white p-4 shadow-sm">
                                    <div class="flex items-start justify-between gap-3">
                                        <button type="button" class="min-w-0 text-left flex-1"
                                            data-open-board="<?= (int) $b['id'] ?>" data-title="<?= h($b['nombre']) ?>"
                                            title="Abrir en el panel derecho">
                                            <div class="flex items-center gap-2">
                                                <span class="inline-block h-3.5 w-3.5 rounded-full"
                                                    style="background: <?= h($b['color_hex'] ?: '#d32f57') ?>;"></span>
                                                <div class="truncate text-sm font-black">
                                                    <?= h($b['nombre']) ?>
                                                </div>
                                            </div>
                                            <div class="mt-2 flex flex-wrap items-center gap-2">
                                                <span
                                                    class="inline-flex items-center rounded-full px-2.5 py-1 text-[11px] font-black <?= h($roleCls) ?>">
                                                    <?= h($roleTxt) ?>
                                                </span>
                                                <span class="text-[11px] text-gray-500">
                                                    <?= h($teamName) ?>
                                                </span>
                                            </div>
                                        </button>

                                        <div class="flex items-center gap-1">
                                            <button class="iconBtn" type="button" title="Editar" data-action="edit"
                                                data-id="<?= (int) $b['id'] ?>" data-name="<?= h($b['nombre']) ?>"
                                                data-color="<?= h($b['color_hex'] ?: '#d32f57') ?>">
                                                <svg viewBox="0 0 24 24" fill="none" class="h-4 w-4" stroke="currentColor"
                                                    stroke-width="2">
                                                    <path d="M12 20h9" stroke-linecap="round" />
                                                    <path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z"
                                                        stroke-linejoin="round" />
                                                </svg>
                                            </button>
                                            <button class="iconBtn" type="button" title="Duplicar" data-action="dup"
                                                data-id="<?= (int) $b['id'] ?>" data-name="<?= h($b['nombre']) ?>">
                                                <svg viewBox="0 0 24 24" fill="none" class="h-4 w-4" stroke="currentColor"
                                                    stroke-width="2">
                                                    <path d="M8 8h12v12H8z" stroke-linejoin="round" />
                                                    <path d="M4 16H3a1 1 0 0 1-1-1V3a1 1 0 0 1 1-1h12a1 1 0 0 1 1 1v1"
                                                        stroke-linecap="round" />
                                                </svg>
                                            </button>
                                            <button class="iconBtn" type="button" title="Archivar" data-action="arc"
                                                data-id="<?= (int) $b['id'] ?>" data-name="<?= h($b['nombre']) ?>">
                                                <svg viewBox="0 0 24 24" fill="none" class="h-4 w-4" stroke="currentColor"
                                                    stroke-width="2">
                                                    <path d="M21 8v13H3V8" stroke-linejoin="round" />
                                                    <path d="M1 3h22v5H1z" stroke-linejoin="round" />
                                                    <path d="M10 12h4" stroke-linecap="round" />
                                                </svg>
                                            </button>
                                            <button class="iconBtnDanger" type="button" title="Eliminar" data-action="del"
                                                data-id="<?= (int) $b['id'] ?>" data-name="<?= h($b['nombre']) ?>">
                                                <svg viewBox="0 0 24 24" fill="none" class="h-4 w-4" stroke="currentColor"
                                                    stroke-width="2">
                                                    <path d="M3 6h18" stroke-linecap="round" />
                                                    <path d="M8 6V4h8v2" stroke-linejoin="round" />
                                                    <path d="M6 6l1 16h10l1-16" stroke-linejoin="round" />
                                                </svg>
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                            <?php if (!count($teamActive)): ?>
                                <div class="rounded-3xl border border-gray-200 bg-white p-4 text-sm text-gray-600">No hay
                                    tableros de equipo activos.</div>
                            <?php endif; ?>
                        </div>

                        <details class="mt-3 rounded-3xl border border-gray-200 bg-white p-4 shadow-sm">
                            <summary class="cursor-pointer select-none text-sm font-black text-gray-800">
                                Archivados de equipos (
                                <?= (int) count($teamArchived) ?>)
                            </summary>
                            <div class="mt-3 space-y-2">
                                <?php foreach ($teamArchived as $b): ?>
                                    <div class="rounded-2xl border border-gray-200 bg-white p-3">
                                        <div class="flex items-center justify-between gap-2">
                                            <button type="button" class="min-w-0 flex-1 text-left"
                                                data-open-board="<?= (int) $b['id'] ?>" data-title="<?= h($b['nombre']) ?>">
                                                <div class="truncate text-sm font-black">
                                                    <?= h($b['nombre']) ?>
                                                </div>
                                                <div class="text-[11px] text-gray-500">Archivado</div>
                                            </button>
                                            <div class="flex items-center gap-1">
                                                <button class="iconBtn" type="button" title="Restaurar" data-action="res"
                                                    data-id="<?= (int) $b['id'] ?>" data-name="<?= h($b['nombre']) ?>">
                                                    <svg viewBox="0 0 24 24" fill="none" class="h-4 w-4"
                                                        stroke="currentColor" stroke-width="2">
                                                        <path d="M3 12a9 9 0 0 1 15.5-6.5" stroke-linecap="round" />
                                                        <path d="M18 3v6h-6" stroke-linejoin="round" />
                                                        <path d="M21 12a9 9 0 0 1-15.5 6.5" stroke-linecap="round" />
                                                        <path d="M6 21v-6h6" stroke-linejoin="round" />
                                                    </svg>
                                                </button>
                                                <button class="iconBtnDanger" type="button" title="Eliminar"
                                                    data-action="del" data-id="<?= (int) $b['id'] ?>"
                                                    data-name="<?= h($b['nombre']) ?>">
                                                    <svg viewBox="0 0 24 24" fill="none" class="h-4 w-4"
                                                        stroke="currentColor" stroke-width="2">
                                                        <path d="M3 6h18" stroke-linecap="round" />
                                                        <path d="M8 6V4h8v2" stroke-linejoin="round" />
                                                        <path d="M6 6l1 16h10l1-16" stroke-linejoin="round" />
                                                    </svg>
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                                <?php if (!count($teamArchived)): ?>
                                    <div class="text-sm text-gray-600">Sin archivados.</div>
                                <?php endif; ?>
                            </div>
                        </details>
                    </section>
                </div>
            </div>
        </aside>

        <!-- PANEL DERECHO -->
        <main class="col-span-12 md:col-span-8 lg:col-span-9 h-full">
            <div class="h-full flex flex-col">
                <div
                    class="px-6 py-4 border-b border-gray-200/70 bg-white/60 backdrop-blur flex items-center justify-between">
                    <div class="min-w-0">
                        <div class="text-xs text-gray-500">Tablero actual</div>
                        <div id="boardTitle" class="truncate text-lg font-black text-[#942934]">Selecciona un tablero
                        </div>
                    </div>
                    <div class="text-xs text-gray-500">Cargando sin recargar (modo embed)</div>
                </div>

                <div class="flex-1 overflow-auto bg-[#f7f4f5]">
                    <!-- aquí inyectamos el tablero -->
                    <div id="boardMount" class="min-h-full">
                        <div class="p-8 text-sm text-gray-600">
                            Selecciona un tablero en la izquierda para cargarlo aquí.
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <!-- MODALES (reusa tus mismos modales) -->
    <!-- DRAWER (FIJO en workspace para que NO se pierda al recargar el boardMount) -->
    <div id="taskDrawerOverlay" class="fixed inset-0 z-40 hidden bg-black/30 backdrop-blur-[2px]"></div>

    <aside id="taskDrawer"
        class="fixed right-0 top-0 z-50 h-full w-full max-w-[520px] translate-x-full bg-white shadow-2xl border-l border-gray-200 transition-transform duration-300 flex flex-col">
        <div
            class="sticky top-0 bg-white/90 backdrop-blur border-b border-gray-200 p-4 flex items-center justify-between">
            <div class="flex items-center gap-2">
                <div class="h-2.5 w-2.5 rounded-full bg-[#d32f57]"></div>
                <p class="text-sm font-semibold text-slate-900">Detalle de tarea</p>
            </div>

            <button type="button" data-drawer-close
                class="rounded-xl border border-gray-300 p-2 hover:bg-slate-50 active:scale-[0.98] transition">
                ✕
            </button>
        </div>

        <div id="taskDrawerBody" class="p-4 overflow-y-auto">
            <div class="text-sm text-slate-600">Selecciona una tarea…</div>
        </div>
    </aside>

    <!-- TOAST (FIJO) -->
    <div id="toast" class="fixed bottom-6 left-1/2 -translate-x-1/2 hidden z-[60]">
        <div class="rounded-2xl bg-[#0F172A] text-white px-4 py-3 shadow-xl text-sm font-semibold">
            ✅ Listo
        </div>
    </div>

    <script>
        (function () {
            'use strict';
            function byId(id) { return document.getElementById(id); }

            // Cargar tablero dentro del panel (embed)
            function loadBoard(boardId, title) {
                if (!boardId) return;

                byId('boardTitle').textContent = title || ('Tablero #' + boardId);

                var mount = byId('boardMount');
                mount.innerHTML = '<div class="p-8 text-sm text-gray-600">Cargando tablero...</div>';

                // 👇 AQUÍ está el fetch completo
                fetch('./view.php?id=' + encodeURIComponent(boardId) + '&embed=1', {
                    headers: { 'X-Requested-With': 'fetch' }
                })
                    .then(function (r) {
                        return r.text();
                    })
                    .then(function (html) {
                        mount.innerHTML = html;

                        // ✅ importante: permitir re-init en el mismo mount (cuando cambias de tablero)
                        mount.__fc_board_inited = false;

                        if (window.FCPlannerBoard && typeof window.FCPlannerBoard.destroy === 'function') {
                            window.FCPlannerBoard.destroy();
                        }

                        // Enganchar funcionalidad al nuevo DOM inyectado
                        if (window.FCPlannerBoard && typeof window.FCPlannerBoard.init === 'function') {
                            window.FCPlannerBoard.init(mount);
                        }
                    })
                    .catch(function () {
                        mount.innerHTML = '<div class="p-8 text-sm text-red-700">No se pudo cargar el tablero.</div>';
                    });
            }

            // Click en sidebar para abrir tablero
            document.addEventListener('click', function (ev) {
                var btn = ev.target.closest('[data-open-board]');
                if (!btn) return;
                var id = btn.getAttribute('data-open-board');
                var title = btn.getAttribute('data-title') || '';
                loadBoard(id, title);
            });

            // Auto-cargar uno al entrar (opcional)
            var firstId = <?= (int) $firstBoardId ?>;
            if (firstId) {
                // busca el botón para sacar el título bonito
                var el = document.querySelector('[data-open-board="' + firstId + '"]');
                var title = el ? (el.getAttribute('data-title') || '') : '';
                loadBoard(firstId, title);
            }

            // Acciones iconos (aquí reusa tus openEdit/openDelete/etc si ya existen)
            // Si no existen, deja estas líneas y pegas tus funciones/modal wiring como en tu index:
            document.addEventListener('click', function (ev) {
                var a = ev.target.closest('button[data-action]');
                if (!a) return;

                var action = a.getAttribute('data-action');
                var id = a.getAttribute('data-id');
                var name = a.getAttribute('data-name') || '';
                var color = a.getAttribute('data-color') || '#d32f57';

                // Si en tu proyecto ya existen:
                if (action === 'edit' && window.openEdit) window.openEdit(id, name, color);
                else if (action === 'del' && window.openDelete) window.openDelete(id, name);
                else if (action === 'dup' && window.openDuplicate) window.openDuplicate(id, name);
                else if (action === 'arc' && window.openArchive) window.openArchive(id, name);
                else if (action === 'res' && window.openRestore) window.openRestore(id, name);
            });
        })();
    </script>
</body>

</html>