<?php
$EMBED = isset($_GET['embed']) && $_GET['embed'] == '1';

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

// Verificar acceso (soy miembro)
$sql = "SELECT b.id, b.nombre, b.color_hex, b.team_id, t.nombre AS team_nombre
            FROM boards b
            LEFT JOIN teams t ON t.id = b.team_id
            JOIN board_members bm ON bm.board_id = b.id
            WHERE b.id = ? AND bm.user_id = ?
            LIMIT 1";
$stmt = $conn->prepare($sql);
$stmt->bind_param('ii', $board_id, $_SESSION['user_id']);
$stmt->execute();
$board = $stmt->get_result()->fetch_assoc();

if (!$board) {
    header('Location: index.php');
    exit;
}

// CSRF para formularios y fetch
if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}

// Traer columnas
$stmt = $conn->prepare("SELECT id, nombre, orden FROM columns WHERE board_id = ? ORDER BY orden ASC");
$stmt->bind_param('i', $board_id);
$stmt->execute();
$columns = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Miembros del tablero (para el picker de asignar)
$mm = $conn->prepare("SELECT u.id, u.nombre
                          FROM board_members bm
                          JOIN users u ON u.id = bm.user_id
                          WHERE bm.board_id = ?
                          ORDER BY u.nombre ASC");
$mm->bind_param('i', $board_id);
$mm->execute();
$board_members = $mm->get_result()->fetch_all(MYSQLI_ASSOC);

// Helper: tareas por columna (compatible created_at / creado_en)
function get_tasks_by_column($conn, $board_id, $column_id)
{
    static $orderCol = null;

    if ($orderCol === null) {
        $orderCol = 'id'; // fallback
        $check = $conn->query("SHOW COLUMNS FROM tasks");
        if ($check) {
            $cols = [];
            while ($row = $check->fetch_assoc()) {
                $cols[$row['Field']] = true;
            }
            if (isset($cols['creado_en']))
                $orderCol = 'creado_en';
            elseif (isset($cols['created_at']))
                $orderCol = 'created_at';
            elseif (isset($cols['created']))
                $orderCol = 'created';
        }
    }

    $sql = "SELECT t.id, t.titulo, t.prioridad, t.fecha_limite, t.assignee_id,
                       u.nombre AS asignado_nombre
                FROM tasks t
                LEFT JOIN users u ON u.id = t.assignee_id
                WHERE t.board_id = ? AND t.column_id = ?
                ORDER BY t.$orderCol DESC";

    $s = $conn->prepare($sql);
    if (!$s)
        return [];

    $s->bind_param('ii', $board_id, $column_id);
    if (!$s->execute())
        return [];

    $res = $s->get_result();
    return $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
}

// Vencimiento (chip)
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
?>

<?php if (!$EMBED): ?>
    <!doctype html>
    <html lang="es">

    <head>
        <meta charset="utf-8">
        <title><?= h($board['nombre']) ?> — F&amp;C Planner</title>
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <link rel="stylesheet" href="../assets/app.css?v=6">
        <script>
            window.FCPlannerCurrentUserName = <?= json_encode($_SESSION['user_nombre'] ?? 'Usuario') ?>;
        </script>
        <script src="../assets/board-view.js?v=1" defer></script>
    </head>

    <body class="min-h-screen bg-slate-50 text-slate-900">
    <?php endif; ?>

    <!-- ROOT DEL TABLERO (normal + embed) -->
    <div class="<?= $EMBED ? 'p-4' : 'mx-auto max-w-7xl px-4 py-6' ?>">

        <?php if (!$EMBED): ?>
            <!-- HEADER (solo modo normal) -->
            <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">

                <div>
                    <h1 class="text-2xl sm:text-3xl font-black tracking-tight text-[#942934]">
                        <?= h($board['nombre']) ?>
                        <?php if (!empty($board['team_id'])): ?>
                            <span
                                class="ml-2 inline-flex items-center rounded-full border border-slate-200 bg-white px-3 py-1 text-xs font-extrabold text-slate-700 shadow-sm">
                                Equipo: <?= h($board['team_nombre'] ?? '—') ?>
                            </span>
                        <?php endif; ?>
                    </h1>

                    <div class="mt-2 text-sm font-semibold text-slate-500">
                        Arrastra tareas entre columnas • Doble clic para renombrar
                    </div>
                </div>

                <div class="flex flex-wrap items-center gap-2 sm:justify-end">

                    <div id="presence" class="flex items-center gap-1" title="Conectados en este tablero"></div>

                    <div class="relative">
                        <button id="bell" type="button"
                            class="relative inline-flex items-center gap-2 rounded-2xl border border-slate-200 bg-white px-4 py-2 text-sm font-extrabold text-slate-700 shadow-sm hover:bg-slate-50 hover:shadow transition">
                            🔔
                            <span id="bellN"
                                class="hidden absolute -top-2 -right-2 rounded-full bg-[#d32f57] px-2 py-0.5 text-[11px] font-black text-white shadow">
                                0
                            </span>
                        </button>

                        <div id="bellPanel"
                            class="hidden absolute right-0 mt-2 w-[340px] max-w-[90vw] overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-xl z-50">
                            <div id="notes" class="max-h-[320px] overflow-auto"></div>
                            <div class="border-t border-slate-200 p-3 text-right">
                                <form id="markAllForm" method="post" action="../notifications/mark_read.php" class="inline">
                                    <input type="hidden" name="csrf" value="<?= h($_SESSION['csrf']) ?>">
                                    <input type="hidden" name="all" value="1">
                                    <button type="submit"
                                        class="rounded-xl bg-gradient-to-br from-[#d32f57] to-[#942934] px-4 py-2 text-xs font-extrabold text-white shadow hover:shadow-md transition">
                                        Marcar como leídas
                                    </button>
                                </form>
                            </div>
                        </div>
                    </div>

                    <a href="index.php"
                        class="rounded-2xl border border-slate-200 bg-white px-4 py-2 text-sm font-extrabold text-slate-700 shadow-sm hover:bg-slate-50 hover:shadow transition">
                        ← Mis tableros
                    </a>

                    <a href="../logout.php"
                        class="rounded-2xl bg-gradient-to-br from-[#d32f57] to-[#942934] px-4 py-2 text-sm font-extrabold text-white shadow-md hover:shadow-lg transition">
                        Cerrar sesión
                    </a>

                </div>
            </div>
        <?php endif; ?>

        <!-- KANBAN -->
        <div class="<?= $EMBED ? '' : 'mt-8' ?> overflow-x-auto">
            <div class="flex gap-6 min-w-max kanban" id="kanban" data-board-id="<?= (int) $board_id ?>"
                data-csrf="<?= h($_SESSION['csrf']) ?>" data-embed="<?= $EMBED ? '1' : '0' ?>">

                <?php foreach ($columns as $c): ?>
                    <?php
                    $tasks = get_tasks_by_column($conn, $board_id, (int) $c['id']);
                    $count = count($tasks);
                    ?>

                    <div class="col w-80 shrink-0 rounded-3xl border border-slate-200 bg-white shadow-sm p-4"
                        data-column-id="<?= (int) $c['id'] ?>">

                        <div class="flex items-center justify-between mb-3">
                            <h3 class="text-sm font-black text-slate-800">
                                <?= h($c['nombre']) ?>
                            </h3>
                            <span class="text-xs font-bold rounded-full bg-slate-100 px-2 py-1 text-slate-600 cnt">
                                <?= (int) $count ?>
                            </span>
                        </div>

                        <form class="mb-3" method="post" action="../tasks/create.php">
                            <input type="hidden" name="csrf" value="<?= h($_SESSION['csrf']) ?>">
                            <input type="hidden" name="board_id" value="<?= (int) $board_id ?>">
                            <input type="hidden" name="column_id" value="<?= (int) $c['id'] ?>">

                            <div class="flex gap-2">
                                <input type="text" name="titulo" required placeholder="Nueva tarea..."
                                    class="w-full rounded-xl border border-slate-200 px-3 py-2 text-sm font-semibold focus:ring-2 focus:ring-[#d32f57]/20 focus:border-[#d32f57]/40 outline-none">

                                <button type="submit"
                                    class="rounded-xl bg-[#d32f57] px-3 py-2 text-sm font-extrabold text-white hover:bg-[#942934] transition">
                                    +
                                </button>
                            </div>
                        </form>

                        <div class="tasks space-y-3">

                            <?php if (!$tasks): ?>
                                <div class="empty text-sm text-slate-400">No hay tareas aún.</div>
                            <?php else:
                                foreach ($tasks as $t):

                                    $prio = h($t['prioridad'] ?? 'med');
                                    $due = !empty($t['fecha_limite']) ? due_meta($t['fecha_limite']) : null;
                                    $asig = trim((string) ($t['asignado_nombre'] ?? ''));
                                    $asig_first = $asig ? explode(' ', $asig)[0] : '';
                                    ?>

                                    <div class="task relative rounded-2xl border border-slate-200 bg-white p-3 shadow-sm hover:shadow-md transition cursor-grab group"
                                        data-task-id="<?= (int) $t['id'] ?>"
                                        data-titulo="<?= htmlspecialchars($t['titulo'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                                        data-prioridad="<?= htmlspecialchars($t['prioridad'] ?? 'med', ENT_QUOTES, 'UTF-8') ?>"
                                        data-fecha="<?= !empty($t['fecha_limite']) ? htmlspecialchars(substr((string) $t['fecha_limite'], 0, 10), ENT_QUOTES, 'UTF-8') : '' ?>"
                                        data-assignee="<?= !empty($t['assignee_id']) ? (int) $t['assignee_id'] : '' ?>"
                                        draggable="true" title="Arrastra para mover • Doble clic para renombrar">

                                        <div
                                            class="pr-10 font-bold text-sm text-slate-800 task-title break-words whitespace-normal">
                                            <?= htmlspecialchars($t['titulo']) ?>
                                        </div>

                                        <div class="pointer-events-none absolute top-2.5 right-2.5 flex gap-2">

                                            <button type="button" draggable="false"
                                                class="pointer-events-auto opacity-100 transition
                                                     inline-flex items-center justify-center
                                                     h-8 w-8 rounded-xl border border-slate-200 bg-white/90 shadow-sm
                                                     text-slate-300 hover:text-[#942934] hover:border-[#942934]/30 hover:bg-white" title="Abrir"
                                                data-action="open-task" data-task-id="<?= (int) $t['id'] ?>">
                                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
                                                    stroke="currentColor" stroke-width="2" stroke-linecap="round"
                                                    stroke-linejoin="round" class="h-4 w-4">
                                                    <path d="M14 3h7v7" />
                                                    <path d="M10 14L21 3" />
                                                    <path d="M21 14v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h6" />
                                                </svg>
                                            </button>

                                            <button type="button"
                                                class="pointer-events-auto opacity-100 transition
                                                     inline-flex items-center justify-center
                                                     h-8 w-8 rounded-xl border border-slate-200 bg-white/90 shadow-sm
                                                     text-slate-300 hover:text-rose-600 hover:border-rose-200 hover:bg-white"
                                                title="Eliminar tarea" data-action="delete-task"
                                                data-task-id="<?= (int) $t['id'] ?>">
                                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
                                                    stroke="currentColor" stroke-width="2" stroke-linecap="round"
                                                    stroke-linejoin="round" class="h-4 w-4">
                                                    <path d="M3 6h18" />
                                                    <path d="M8 6V4h8v2" />
                                                    <path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6" />
                                                    <path d="M10 11v6" />
                                                    <path d="M14 11v6" />
                                                </svg>
                                            </button>
                                        </div>

                                        <div class="row flex flex-wrap gap-2 mt-2">
                                            <span class="text-[11px] font-bold rounded-full px-2 py-1
                                            <?php
                                            switch ($prio) {
                                                case 'low':
                                                    echo 'bg-slate-100 text-slate-600';
                                                    break;
                                                case 'med':
                                                    echo 'bg-yellow-100 text-yellow-800';
                                                    break;
                                                case 'high':
                                                    echo 'bg-orange-100 text-orange-700';
                                                    break;
                                                case 'urgent':
                                                    echo 'bg-[#942934] text-white';
                                                    break;
                                                default:
                                                    echo 'bg-slate-100 text-slate-600';
                                            }
                                            ?>">
                                                <?= tr_priority_label($prio, true) ?>
                                            </span>

                                            <?php if ($due): ?>
                                                <span class="text-[11px] font-semibold rounded-full px-2 py-1
                                                <?= $due['state'] === 'overdue' ? 'bg-rose-100 text-rose-700'
                                                    : ($due['state'] === 'soon' ? 'bg-orange-100 text-orange-700'
                                                        : 'bg-slate-100 text-slate-600') ?>">
                                                    <?= h($due['label']) ?>
                                                </span>
                                            <?php endif; ?>

                                            <?php if ($asig_first): ?>
                                                <span
                                                    class="text-[11px] font-semibold rounded-full bg-slate-100 px-2 py-1 text-slate-600 chip-resp">
                                                    👤 <?= h($asig_first) ?>
                                                </span>
                                            <?php endif; ?>
                                        </div>

                                    </div>

                                <?php endforeach;
                            endif; ?>

                        </div>

                    </div>

                <?php endforeach; ?>

            </div>
        </div>

    </div>

    <?php if ($EMBED): ?>
        <!-- Drawer + modales + toast SOLO en modo normal -->
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

        <div id="modalDeleteTask"
            class="fixed inset-0 hidden flex items-center justify-center bg-black/40 backdrop-blur-sm z-50 p-4">
            <div class="w-full max-w-md rounded-3xl bg-white shadow-2xl p-6">
                <h2 class="text-lg font-black text-[#942934] mb-2">Eliminar tarea</h2>
                <p class="text-sm text-slate-600 mb-6">¿Estás seguro de que deseas eliminar esta tarea? Esta acción no se
                    puede deshacer.</p>
                <div class="flex justify-end gap-3">
                    <button id="btnCancelDeleteTask"
                        class="rounded-xl border border-slate-200 px-4 py-2 text-sm font-bold text-slate-600 hover:bg-slate-100 transition">
                        Cancelar
                    </button>
                    <button id="btnConfirmDeleteTask"
                        class="rounded-xl bg-gradient-to-br from-[#d32f57] to-[#942934] px-4 py-2 text-sm font-bold text-white shadow hover:shadow-md transition">
                        Sí, eliminar
                    </button>
                </div>
            </div>
        </div>

        <div id="modalEditTask"
            class="fixed inset-0 hidden flex items-center justify-center bg-black/40 backdrop-blur-sm z-50 p-4">
            <div class="bg-white rounded-3xl shadow-2xl w-full max-w-md p-6">
                <h3 class="text-lg font-black text-[#942934] mb-4">Editar tarea</h3>
                <p id="edit_task_title" class="text-sm font-semibold text-slate-500 mb-4"></p>

                <form id="formEditTask" class="space-y-4">
                    <input type="hidden" id="edit_task_id">

                    <div>
                        <label class="text-sm font-semibold text-slate-600">Prioridad</label>
                        <select id="edit_prioridad"
                            class="w-full mt-1 rounded-xl border border-slate-200 px-3 py-2 text-sm">
                            <option value="low">Baja</option>
                            <option value="med">Media</option>
                            <option value="high">Alta</option>
                            <option value="urgent">Urgente</option>
                        </select>
                    </div>

                    <div>
                        <label class="text-sm font-semibold text-slate-600">Fecha límite</label>
                        <input type="date" id="edit_fecha"
                            class="w-full mt-1 rounded-xl border border-slate-200 px-3 py-2 text-sm">
                    </div>

                    <div>
                        <label class="text-sm font-semibold text-slate-600">Asignar a</label>
                        <select id="edit_assignee" class="w-full mt-1 rounded-xl border border-slate-200 px-3 py-2 text-sm">
                            <option value="">Sin responsable</option>
                            <?php foreach ($board_members as $m): ?>
                                <option value="<?= (int) $m['id'] ?>"><?= htmlspecialchars($m['nombre']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="flex justify-end gap-3 pt-4">
                        <button type="button" id="btnCancelEditTask"
                            class="px-4 py-2 rounded-xl bg-slate-100 text-slate-700 font-semibold hover:bg-slate-200">
                            Cancelar
                        </button>
                        <button type="button" id="btnSaveEditTask"
                            class="px-4 py-2 rounded-xl bg-gradient-to-br from-[#d32f57] to-[#942934] text-white font-bold shadow hover:shadow-lg">
                            Guardar
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <div id="toast" class="fixed bottom-6 left-1/2 -translate-x-1/2 hidden z-[60]">
            <div class="rounded-2xl bg-[#0F172A] text-white px-4 py-3 shadow-xl text-sm font-semibold">✅ Guardado</div>
        </div>

        <script id="members-data" type="application/json">
                    <?= json_encode($board_members, JSON_UNESCAPED_UNICODE) ?>
                    </script>

        <!-- Tu script legacy (solo modo normal) -->
        <script>
            // (se queda EXACTAMENTE como lo tenías, pero solo aplica en modo normal)
        </script>

    </body>

    </html>
<?php endif; ?>