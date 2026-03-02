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

        /* ✅ Esto lo usa boards-actions.js para cerrar por backdrop */
        .modalBackdrop {
            cursor: pointer;
        }
    </style>

    <script>
        window.FCPlannerCurrentUserName = <?= json_encode($_SESSION['user_nombre'] ?? 'Usuario') ?>;
    </script>
    <script src="../assets/board-view.js?v=1" defer></script>
</head>

<body class="min-h-screen bg-[#f7f4f5] text-gray-900 overflow-x-hidden">


    <?php if (!empty($_SESSION['flash']) && is_array($_SESSION['flash'])): ?>
        <?php
        $ft = $_SESSION['flash']['type'] ?? 'ok';
        $fm = $_SESSION['flash']['msg'] ?? '';
        unset($_SESSION['flash']);

        $isOk = ($ft === 'ok' || $ft === 'success');
        $wrapCls = $isOk
            ? 'border-emerald-200 bg-emerald-50 text-emerald-800'
            : 'border-rose-200 bg-rose-50 text-rose-800';
        ?>
        <div id="flashToast" class="fixed top-5 left-1/2 -translate-x-1/2 z-[200] w-[92%] max-w-[720px]">
            <div class="rounded-3xl border <?= $wrapCls ?> px-5 py-4 shadow-2xl">
                <div class="text-sm font-black">
                    <?= htmlspecialchars($fm, ENT_QUOTES, 'UTF-8') ?>
                </div>
            </div>
        </div>
        <script>
            setTimeout(function () {
                var el = document.getElementById('flashToast');
                if (el) el.remove();
            }, 5200);
        </script>
    <?php endif; ?>

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
                ⚙ Administrar tableros
            </a>

            <?php if (is_super_admin($conn)): ?>
                <a href="../admin/users_pending.php"
                    class="rounded-2xl bg-[#f9eef1] px-4 py-2 text-xs font-black text-[#942934] border border-[#d32f57]/30 hover:scale-[1.01] transition">
                    👥 Usuarios
                </a>
            <?php endif; ?>

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
                        </div>
                    </div>

                    <!-- Crear tablero INLINE -->
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

                            <input type="hidden" name="color_hex" id="create_color_hex" value="#d32f57" />

                            <div class="mt-1 flex items-center gap-2">
                                <div
                                    class="h-9 w-10 rounded-xl border border-gray-200 bg-white flex items-center justify-center">
                                    <span id="createColorPreview"
                                        class="h-5 w-5 rounded-full ring-2 ring-white shadow-sm"
                                        style="background:#d32f57;"></span>
                                </div>

                                <button type="button" id="btnOpenColorPicker"
                                    class="h-9 flex-1 rounded-xl border border-gray-300 bg-white px-3 text-xs font-black text-gray-700 transition-all duration-200 hover:scale-[1.01] active:scale-[0.98]">
                                    Elegir…
                                </button>
                            </div>

                            <div class="mt-1 text-[10px] text-gray-500">Abre el selector tipo rueda.</div>
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
                            <span class="text-[11px] text-gray-500"><?= (int) count($personalActive) ?></span>
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
                                                <div class="truncate text-sm font-black"><?= h($b['nombre']) ?></div>
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
                                Archivados personales (<?= (int) count($personalArchived) ?>)
                            </summary>
                            <div class="mt-3 space-y-2">
                                <?php foreach ($personalArchived as $b): ?>
                                    <div class="rounded-2xl border border-gray-200 bg-white p-3">
                                        <div class="flex items-center justify-between gap-2">
                                            <button type="button" class="min-w-0 flex-1 text-left"
                                                data-open-board="<?= (int) $b['id'] ?>" data-title="<?= h($b['nombre']) ?>"
                                                title="Abrir (solo lectura si aplica)">
                                                <div class="truncate text-sm font-black"><?= h($b['nombre']) ?></div>
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
                            <span class="text-[11px] text-gray-500"><?= (int) count($teamActive) ?></span>
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
                                                <div class="truncate text-sm font-black"><?= h($b['nombre']) ?></div>
                                            </div>
                                            <div class="mt-2 flex flex-wrap items-center gap-2">
                                                <span
                                                    class="inline-flex items-center rounded-full px-2.5 py-1 text-[11px] font-black <?= h($roleCls) ?>">
                                                    <?= h($roleTxt) ?>
                                                </span>
                                                <span class="text-[11px] text-gray-500"><?= h($teamName) ?></span>
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
                                Archivados de equipos (<?= (int) count($teamArchived) ?>)
                            </summary>
                            <div class="mt-3 space-y-2">
                                <?php foreach ($teamArchived as $b): ?>
                                    <div class="rounded-2xl border border-gray-200 bg-white p-3">
                                        <div class="flex items-center justify-between gap-2">
                                            <button type="button" class="min-w-0 flex-1 text-left"
                                                data-open-board="<?= (int) $b['id'] ?>" data-title="<?= h($b['nombre']) ?>">
                                                <div class="truncate text-sm font-black"><?= h($b['nombre']) ?></div>
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
                        <div id="boardTitle" class="truncate text-lg font-black text-[#942934]">Selecciona un tablero
                        </div>
                    </div>
                </div>

                <div class="flex-1 overflow-auto bg-[#f7f4f5]">
                    <div id="boardMount" class="min-h-full">
                        <div class="p-8 text-sm text-gray-600">
                            Selecciona un tablero en la izquierda para cargarlo aquí.
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <!-- DRAWER (FIJO) -->
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

    <!-- TOAST -->
    <div id="toast" class="fixed bottom-6 left-1/2 -translate-x-1/2 hidden z-[60]">
        <div class="rounded-2xl bg-[#0F172A] text-white px-4 py-3 shadow-xl text-sm font-semibold">
            ✅ Listo
        </div>
    </div>

    <!-- =========================
         MODAL: Color Picker (rueda)
    ========================== -->
    <div id="colorPickerModal" class="fixed inset-0 z-[80] hidden">
        <div id="colorPickerBackdrop" class="absolute inset-0 bg-black/40 backdrop-blur-[2px]"></div>

        <div
            class="relative mx-auto mt-10 w-[92%] max-w-[420px] rounded-3xl border border-gray-200 bg-white shadow-2xl">
            <div class="flex items-center justify-between gap-3 border-b border-gray-200 px-5 py-4">
                <div class="flex items-center gap-2">
                    <div class="h-2.5 w-2.5 rounded-full bg-[#d32f57]"></div>
                    <p class="text-sm font-black text-gray-900">Selector de color</p>
                </div>

                <button type="button" id="btnCloseColorPicker"
                    class="h-9 w-9 rounded-xl border border-gray-300 bg-white font-black text-gray-800 hover:bg-gray-50 active:scale-[0.98] transition">
                    ✕
                </button>
            </div>

            <div class="p-5">
                <div class="flex items-center justify-center">
                    <canvas id="colorWheel" width="280" height="280"
                        class="rounded-full select-none touch-none"></canvas>
                </div>

                <div class="mt-5 flex items-center justify-between gap-3">
                    <div class="flex items-center gap-3">
                        <div
                            class="h-11 w-11 rounded-2xl border border-gray-200 bg-white flex items-center justify-center">
                            <span id="modalColorPreview" class="h-7 w-7 rounded-full ring-2 ring-white shadow-sm"
                                style="background:#d32f57;"></span>
                        </div>
                        <div>
                            <div class="text-[11px] text-gray-500">Color seleccionado</div>
                            <div id="modalHexText" class="text-sm font-black text-gray-900">#d32f57</div>
                        </div>
                    </div>

                    <div class="flex items-center gap-2">
                        <button type="button" id="btnCancelColor"
                            class="rounded-2xl border border-gray-300 bg-white px-4 py-2 text-sm font-black text-gray-700 hover:scale-[1.01] active:scale-[0.98] transition">
                            Cancelar
                        </button>
                        <button type="button" id="btnApplyColor"
                            class="rounded-2xl bg-[#d32f57] px-4 py-2 text-sm font-black text-white shadow-lg shadow-[#d32f57]/20 hover:scale-[1.01] active:scale-[0.98] transition">
                            Aplicar
                        </button>
                    </div>
                </div>

                <p class="mt-3 text-[11px] text-gray-500">
                    Tip: clic en el aro para el tono, clic dentro para saturación/brillo.
                </p>
            </div>
        </div>
    </div>

    <!-- =========================
         ✅ MODALES DE ACCIONES (para que funcione el sidebar)
         IDs exactos que usa boards-actions.js
    ========================== -->

    <!-- EDIT -->
    <div id="modalEdit" class="fixed inset-0 z-[90] hidden" aria-hidden="true">
        <div class="modalBackdrop absolute inset-0 bg-black/40 backdrop-blur-[2px]"></div>
        <div
            class="relative mx-auto mt-16 w-[92%] max-w-[520px] rounded-3xl border border-gray-200 bg-white shadow-2xl">
            <div class="flex items-center justify-between border-b border-gray-200 px-5 py-4">
                <div class="flex items-center gap-2">
                    <div class="h-2.5 w-2.5 rounded-full bg-[#d32f57]"></div>
                    <p class="text-sm font-black text-gray-900">Editar tablero</p>
                </div>
                <button type="button" onclick="closeModal('modalEdit')"
                    class="h-9 w-9 rounded-xl border border-gray-300 bg-white font-black text-gray-800 hover:bg-gray-50 active:scale-[0.98] transition">✕</button>
            </div>

            <form method="POST" action="./update.php?return=workspace" class="p-5 space-y-4">
                <input type="hidden" name="csrf" value="<?= h($_SESSION['csrf']) ?>">
                <input type="hidden" name="board_id" id="edit_board_id" value="">

                <div>
                    <label class="block text-[11px] font-black text-gray-700">Nombre</label>
                    <input id="edit_nombre" name="nombre" required
                        class="mt-1 w-full rounded-2xl border border-gray-300 bg-white p-2.5 text-sm placeholder:text-gray-500 transition-all duration-300 focus:ring-2 focus:ring-[#d32f57]"
                        placeholder="Nombre del tablero" />
                </div>

                <div>
                    <label class="block text-[11px] font-black text-gray-700">Color</label>
                    <div class="mt-1 flex items-center gap-3">
                        <input id="edit_color_hex" name="color_hex" type="color"
                            class="h-10 w-16 rounded-xl border border-gray-300 bg-white p-1" value="#d32f57">
                        <div class="text-[11px] text-gray-500">Elige un color para identificar el tablero.</div>
                    </div>
                </div>

                <div class="flex items-center justify-end gap-2 pt-2">
                    <button type="button" onclick="closeModal('modalEdit')"
                        class="rounded-2xl border border-gray-300 bg-white px-4 py-2 text-sm font-black text-gray-700 hover:scale-[1.01] active:scale-[0.98] transition">
                        Cancelar
                    </button>
                    <button type="submit"
                        class="rounded-2xl bg-[#d32f57] px-4 py-2 text-sm font-black text-white shadow-lg shadow-[#d32f57]/20 hover:scale-[1.01] active:scale-[0.98] transition">
                        Guardar
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- DELETE -->
    <div id="modalDelete" class="fixed inset-0 z-[90] hidden" aria-hidden="true">
        <div class="modalBackdrop absolute inset-0 bg-black/40 backdrop-blur-[2px]"></div>
        <div
            class="relative mx-auto mt-16 w-[92%] max-w-[520px] rounded-3xl border border-gray-200 bg-white shadow-2xl">
            <div class="flex items-center justify-between border-b border-gray-200 px-5 py-4">
                <div class="flex items-center gap-2">
                    <div class="h-2.5 w-2.5 rounded-full bg-red-600"></div>
                    <p class="text-sm font-black text-gray-900">Eliminar tablero</p>
                </div>
                <button type="button" onclick="closeModal('modalDelete')"
                    class="h-9 w-9 rounded-xl border border-gray-300 bg-white font-black text-gray-800 hover:bg-gray-50 active:scale-[0.98] transition">✕</button>
            </div>

            <form method="POST" action="./delete.php?return=workspace" class="p-5">
                <input type="hidden" name="csrf" value="<?= h($_SESSION['csrf']) ?>">
                <input type="hidden" name="board_id" id="del_board_id" value="">

                <p class="text-sm text-gray-700">
                    Vas a eliminar <span id="del_board_name" class="font-black text-gray-900"></span>.
                    <span class="block mt-1 text-[12px] text-gray-500">Esta acción no se puede deshacer.</span>
                </p>

                <div class="mt-5 flex items-center justify-end gap-2">
                    <button type="button" onclick="closeModal('modalDelete')"
                        class="rounded-2xl border border-gray-300 bg-white px-4 py-2 text-sm font-black text-gray-700 hover:scale-[1.01] active:scale-[0.98] transition">
                        Cancelar
                    </button>
                    <button type="submit"
                        class="rounded-2xl bg-red-600 px-4 py-2 text-sm font-black text-white shadow-lg shadow-red-600/20 hover:scale-[1.01] active:scale-[0.98] transition">
                        Eliminar
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- DUPLICATE -->
    <div id="modalDuplicate" class="fixed inset-0 z-[90] hidden" aria-hidden="true">
        <div class="modalBackdrop absolute inset-0 bg-black/40 backdrop-blur-[2px]"></div>
        <div
            class="relative mx-auto mt-16 w-[92%] max-w-[520px] rounded-3xl border border-gray-200 bg-white shadow-2xl">
            <div class="flex items-center justify-between border-b border-gray-200 px-5 py-4">
                <div class="flex items-center gap-2">
                    <div class="h-2.5 w-2.5 rounded-full bg-[#d32f57]"></div>
                    <p class="text-sm font-black text-gray-900">Duplicar tablero</p>
                </div>
                <button type="button" onclick="closeModal('modalDuplicate')"
                    class="h-9 w-9 rounded-xl border border-gray-300 bg-white font-black text-gray-800 hover:bg-gray-50 active:scale-[0.98] transition">✕</button>
            </div>

            <form method="POST" action="./duplicate.php?return=workspace" class="p-5">
                <input type="hidden" name="csrf" value="<?= h($_SESSION['csrf']) ?>">
                <input type="hidden" name="board_id" id="dup_board_id" value="">

                <p class="text-sm text-gray-700">
                    Vas a duplicar <span id="dup_board_name" class="font-black text-gray-900"></span>.
                </p>

                <div class="mt-5 flex items-center justify-end gap-2">
                    <button type="button" onclick="closeModal('modalDuplicate')"
                        class="rounded-2xl border border-gray-300 bg-white px-4 py-2 text-sm font-black text-gray-700 hover:scale-[1.01] active:scale-[0.98] transition">
                        Cancelar
                    </button>
                    <button type="submit"
                        class="rounded-2xl bg-[#d32f57] px-4 py-2 text-sm font-black text-white shadow-lg shadow-[#d32f57]/20 hover:scale-[1.01] active:scale-[0.98] transition">
                        Duplicar
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- ARCHIVE -->
    <div id="modalArchive" class="fixed inset-0 z-[90] hidden" aria-hidden="true">
        <div class="modalBackdrop absolute inset-0 bg-black/40 backdrop-blur-[2px]"></div>
        <div
            class="relative mx-auto mt-16 w-[92%] max-w-[520px] rounded-3xl border border-gray-200 bg-white shadow-2xl">
            <div class="flex items-center justify-between border-b border-gray-200 px-5 py-4">
                <div class="flex items-center gap-2">
                    <div class="h-2.5 w-2.5 rounded-full bg-[#d32f57]"></div>
                    <p class="text-sm font-black text-gray-900">Archivar tablero</p>
                </div>
                <button type="button" onclick="closeModal('modalArchive')"
                    class="h-9 w-9 rounded-xl border border-gray-300 bg-white font-black text-gray-800 hover:bg-gray-50 active:scale-[0.98] transition">✕</button>
            </div>

            <form method="POST" action="./archive.php?return=workspace" class="p-5">
                <input type="hidden" name="csrf" value="<?= h($_SESSION['csrf']) ?>">
                <input type="hidden" name="board_id" id="arc_board_id" value="">

                <p class="text-sm text-gray-700">
                    Vas a archivar <span id="arc_board_name" class="font-black text-gray-900"></span>.
                </p>

                <div class="mt-5 flex items-center justify-end gap-2">
                    <button type="button" onclick="closeModal('modalArchive')"
                        class="rounded-2xl border border-gray-300 bg-white px-4 py-2 text-sm font-black text-gray-700 hover:scale-[1.01] active:scale-[0.98] transition">
                        Cancelar
                    </button>
                    <button type="submit"
                        class="rounded-2xl bg-[#d32f57] px-4 py-2 text-sm font-black text-white shadow-lg shadow-[#d32f57]/20 hover:scale-[1.01] active:scale-[0.98] transition">
                        Archivar
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- RESTORE -->
    <div id="modalRestore" class="fixed inset-0 z-[90] hidden" aria-hidden="true">
        <div class="modalBackdrop absolute inset-0 bg-black/40 backdrop-blur-[2px]"></div>
        <div
            class="relative mx-auto mt-16 w-[92%] max-w-[520px] rounded-3xl border border-gray-200 bg-white shadow-2xl">
            <div class="flex items-center justify-between border-b border-gray-200 px-5 py-4">
                <div class="flex items-center gap-2">
                    <div class="h-2.5 w-2.5 rounded-full bg-[#d32f57]"></div>
                    <p class="text-sm font-black text-gray-900">Restaurar tablero</p>
                </div>
                <button type="button" onclick="closeModal('modalRestore')"
                    class="h-9 w-9 rounded-xl border border-gray-300 bg-white font-black text-gray-800 hover:bg-gray-50 active:scale-[0.98] transition">✕</button>
            </div>

            <form method="POST" action="./restore.php?return=workspace" class="p-5">
                <input type="hidden" name="csrf" value="<?= h($_SESSION['csrf']) ?>">
                <input type="hidden" name="board_id" id="res_board_id" value="">

                <p class="text-sm text-gray-700">
                    Vas a restaurar <span id="res_board_name" class="font-black text-gray-900"></span>.
                </p>

                <div class="mt-5 flex items-center justify-end gap-2">
                    <button type="button" onclick="closeModal('modalRestore')"
                        class="rounded-2xl border border-gray-300 bg-white px-4 py-2 text-sm font-black text-gray-700 hover:scale-[1.01] active:scale-[0.98] transition">
                        Cancelar
                    </button>
                    <button type="submit"
                        class="rounded-2xl bg-[#d32f57] px-4 py-2 text-sm font-black text-white shadow-lg shadow-[#d32f57]/20 hover:scale-[1.01] active:scale-[0.98] transition">
                        Restaurar
                    </button>
                </div>
            </form>
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

                fetch('./view.php?id=' + encodeURIComponent(boardId) + '&embed=1', {
                    headers: { 'X-Requested-With': 'fetch' }
                })
                    .then(function (r) { return r.text(); })
                    .then(function (html) {
                        mount.innerHTML = html;

                        mount.__fc_board_inited = false;

                        if (window.FCPlannerBoard && typeof window.FCPlannerBoard.destroy === 'function') {
                            window.FCPlannerBoard.destroy();
                        }

                        if (window.FCPlannerBoard && typeof window.FCPlannerBoard.init === 'function') {
                            window.FCPlannerBoard.init(mount);
                        }
                    })
                    .catch(function () {
                        mount.innerHTML = '<div class="p-8 text-sm text-red-700">No se pudo cargar el tablero.</div>';
                    });
            }

            document.addEventListener('click', function (ev) {
                var btn = ev.target.closest('[data-open-board]');
                if (!btn) return;
                var id = btn.getAttribute('data-open-board');
                var title = btn.getAttribute('data-title') || '';
                loadBoard(id, title);
            });

            var firstId = <?= (int) $firstBoardId ?>;
            if (firstId) {
                var el = document.querySelector('[data-open-board="' + firstId + '"]');
                var title = el ? (el.getAttribute('data-title') || '') : '';
                loadBoard(firstId, title);
            }
        })();

        // =========================
        // Color Picker (rueda)
        // =========================
        (function () {
            'use strict';

            function byId(id) { return document.getElementById(id); }

            var modal = byId('colorPickerModal');
            var backdrop = byId('colorPickerBackdrop');
            var btnOpen = byId('btnOpenColorPicker');
            var btnClose = byId('btnCloseColorPicker');
            var btnCancel = byId('btnCancelColor');
            var btnApply = byId('btnApplyColor');

            var inputHex = byId('create_color_hex');
            var previewSmall = byId('createColorPreview');

            var canvas = byId('colorWheel');
            var ctx = canvas ? canvas.getContext('2d') : null;

            var modalPreview = byId('modalColorPreview');
            var modalHexText = byId('modalHexText');

            if (!modal || !btnOpen || !canvas || !ctx || !inputHex) return;

            var W = canvas.width, H = canvas.height;
            var cx = W / 2, cy = H / 2;

            var outerR = Math.min(W, H) / 2 - 6;
            var ringWidth = 26;
            var innerR = outerR - ringWidth - 6;

            var hsv = { h: 340, s: 0.78, v: 0.83 };

            function clamp(x, a, b) { return Math.max(a, Math.min(b, x)); }

            function hsvToRgb(h, s, v) {
                var c = v * s;
                var x = c * (1 - Math.abs(((h / 60) % 2) - 1));
                var m = v - c;

                var r = 0, g = 0, b = 0;
                if (h < 60) { r = c; g = x; b = 0; }
                else if (h < 120) { r = x; g = c; b = 0; }
                else if (h < 180) { r = 0; g = c; b = x; }
                else if (h < 240) { r = 0; g = x; b = c; }
                else if (h < 300) { r = x; g = 0; b = c; }
                else { r = c; g = 0; b = x; }

                return {
                    r: Math.round((r + m) * 255),
                    g: Math.round((g + m) * 255),
                    b: Math.round((b + m) * 255)
                };
            }

            function rgbToHex(r, g, b) {
                function to2(n) {
                    var s = n.toString(16);
                    return s.length === 1 ? '0' + s : s;
                }
                return '#' + to2(r) + to2(g) + to2(b);
            }

            function hexToRgb(hex) {
                var m = /^#([0-9a-fA-F]{6})$/.exec(hex || '');
                if (!m) return null;
                var n = parseInt(m[1], 16);
                return { r: (n >> 16) & 255, g: (n >> 8) & 255, b: n & 255 };
            }

            function rgbToHsv(r, g, b) {
                r /= 255; g /= 255; b /= 255;
                var max = Math.max(r, g, b), min = Math.min(r, g, b);
                var d = max - min;
                var h = 0;

                if (d === 0) h = 0;
                else if (max === r) h = 60 * (((g - b) / d) % 6);
                else if (max === g) h = 60 * (((b - r) / d) + 2);
                else h = 60 * (((r - g) / d) + 4);

                if (h < 0) h += 360;

                var s = max === 0 ? 0 : d / max;
                var v = max;

                return { h: h, s: s, v: v };
            }

            function setPreviewsFromHSV() {
                var rgb = hsvToRgb(hsv.h, hsv.s, hsv.v);
                var hex = rgbToHex(rgb.r, rgb.g, rgb.b);

                modalPreview.style.background = hex;
                modalHexText.textContent = hex;

                btnApply.style.background = hex;
                btnApply.style.boxShadow = '0 12px 28px rgba(0,0,0,.12)';
            }

            function drawWheel() {
                ctx.clearRect(0, 0, W, H);

                for (var a = 0; a < 360; a += 1) {
                    var rad1 = (a - 1) * Math.PI / 180;
                    var rad2 = a * Math.PI / 180;
                    ctx.beginPath();
                    ctx.arc(cx, cy, outerR, rad1, rad2, false);
                    ctx.strokeStyle = 'hsl(' + a + ',100%,50%)';
                    ctx.lineWidth = ringWidth;
                    ctx.stroke();
                }

                ctx.save();
                ctx.beginPath();
                ctx.arc(cx, cy, innerR, 0, Math.PI * 2);
                ctx.clip();

                var hueRgb = hsvToRgb(hsv.h, 1, 1);
                ctx.fillStyle = rgbToHex(hueRgb.r, hueRgb.g, hueRgb.b);
                ctx.fillRect(cx - innerR, cy - innerR, innerR * 2, innerR * 2);

                var gWhite = ctx.createRadialGradient(cx, cy, 0, cx, cy, innerR);
                gWhite.addColorStop(0, 'rgba(255,255,255,1)');
                gWhite.addColorStop(1, 'rgba(255,255,255,0)');
                ctx.fillStyle = gWhite;
                ctx.fillRect(cx - innerR, cy - innerR, innerR * 2, innerR * 2);

                var gBlack = ctx.createLinearGradient(cx, cy - innerR, cx, cy + innerR);
                gBlack.addColorStop(0, 'rgba(0,0,0,0)');
                gBlack.addColorStop(1, 'rgba(0,0,0,0.95)');
                ctx.fillStyle = gBlack;
                ctx.fillRect(cx - innerR, cy - innerR, innerR * 2, innerR * 2);

                ctx.restore();

                var hueRad = (hsv.h - 90) * Math.PI / 180;
                var hx = cx + Math.cos(hueRad) * (outerR - ringWidth / 2);
                var hy = cy + Math.sin(hueRad) * (outerR - ringWidth / 2);

                ctx.beginPath();
                ctx.arc(hx, hy, 7, 0, Math.PI * 2);
                ctx.fillStyle = '#fff';
                ctx.fill();
                ctx.lineWidth = 3;
                ctx.strokeStyle = 'rgba(0,0,0,0.25)';
                ctx.stroke();

                var sx = cx + (hsv.s - 0.5) * innerR * 1.6;
                var sy = cy + (0.5 - hsv.v) * innerR * 1.6;

                var dx = sx - cx, dy = sy - cy;
                var dist = Math.sqrt(dx * dx + dy * dy);
                if (dist > innerR) {
                    dx = dx * (innerR / dist);
                    dy = dy * (innerR / dist);
                    sx = cx + dx;
                    sy = cy + dy;
                }

                ctx.beginPath();
                ctx.arc(sx, sy, 9, 0, Math.PI * 2);
                ctx.fillStyle = 'rgba(255,255,255,0.95)';
                ctx.fill();
                ctx.lineWidth = 3;
                ctx.strokeStyle = 'rgba(0,0,0,0.25)';
                ctx.stroke();
            }

            function openModal() {
                modal.classList.remove('hidden');

                var rgb = hexToRgb(inputHex.value || '#d32f57');
                if (rgb) hsv = rgbToHsv(rgb.r, rgb.g, rgb.b);

                setPreviewsFromHSV();
                drawWheel();
            }

            function closeModal() { modal.classList.add('hidden'); }

            function applyColor() {
                var rgb = hsvToRgb(hsv.h, hsv.s, hsv.v);
                var hex = rgbToHex(rgb.r, rgb.g, rgb.b);

                inputHex.value = hex;
                if (previewSmall) previewSmall.style.background = hex;

                closeModal();
            }

            function handlePick(ev) {
                var rect = canvas.getBoundingClientRect();
                var x = (ev.clientX - rect.left) * (canvas.width / rect.width);
                var y = (ev.clientY - rect.top) * (canvas.height / rect.height);

                var dx = x - cx, dy = y - cy;
                var r = Math.sqrt(dx * dx + dy * dy);

                if (r <= outerR + ringWidth / 2 && r >= outerR - ringWidth) {
                    var ang = Math.atan2(dy, dx);
                    var deg = ang * 180 / Math.PI + 90;
                    if (deg < 0) deg += 360;
                    hsv.h = deg;
                    setPreviewsFromHSV();
                    drawWheel();
                    return;
                }

                if (r <= innerR) {
                    var sx = dx / (innerR * 0.8);
                    var vy = dy / (innerR * 0.8);

                    var s = clamp((sx + 1) / 2, 0, 1);
                    var v = clamp(1 - ((vy + 1) / 2), 0, 1);

                    hsv.s = s;
                    hsv.v = v;

                    setPreviewsFromHSV();
                    drawWheel();
                }
            }

            btnOpen.addEventListener('click', openModal);
            btnClose.addEventListener('click', closeModal);
            btnCancel.addEventListener('click', closeModal);
            backdrop.addEventListener('click', closeModal);
            btnApply.addEventListener('click', applyColor);

            document.addEventListener('keydown', function (e) {
                if (e.key === 'Escape' && !modal.classList.contains('hidden')) closeModal();
            });

            canvas.addEventListener('mousedown', function (ev) { handlePick(ev); });
            canvas.addEventListener('mousemove', function (ev) {
                if (ev.buttons !== 1) return;
                handlePick(ev);
            });

            if (previewSmall) previewSmall.style.background = (inputHex.value || '#d32f57');

        })();
    </script>

    <style>
        #colorWheel {
            image-rendering: auto;
        }
    </style>

    <script src="../assets/boards-actions.js?v=1" defer></script>
</body>

</html>