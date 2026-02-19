<?php
require_once __DIR__ . '/../_auth.php';
require_login();
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../_i18n.php';

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

$task_id = (int) ($_GET['id'] ?? 0);
if ($task_id <= 0) {
    http_response_code(400);
    exit;
}

// traer tarea + validar permiso por board_members
$sql = "SELECT t.id, t.titulo, t.prioridad, t.fecha_limite, t.board_id, t.column_id,
               t.assignee_id, u.nombre AS asignado_nombre
        FROM tasks t
        LEFT JOIN users u ON u.id = t.assignee_id
        WHERE t.id = ? LIMIT 1";
$s = $conn->prepare($sql);
$s->bind_param('i', $task_id);
$s->execute();
$t = $s->get_result()->fetch_assoc();
if (!$t) {
    http_response_code(404);
    exit;
}

$chk = $conn->prepare("SELECT 1 FROM board_members WHERE board_id=? AND user_id=? LIMIT 1");
$chk->bind_param('ii', $t['board_id'], $_SESSION['user_id']);
$chk->execute();
if (!$chk->get_result()->fetch_row()) {
    http_response_code(403);
    exit;
}

$prio = htmlspecialchars($t['prioridad'] ?? 'med', ENT_QUOTES, 'UTF-8');
$due = !empty($t['fecha_limite']) ? due_meta($t['fecha_limite']) : null;
$asig = trim((string) ($t['asignado_nombre'] ?? ''));
$asig_first = $asig ? explode(' ', $asig)[0] : '';

// clases prioridad (igual que en view.php)
$prioClass = 'bg-slate-100 text-slate-600';
switch ($prio) {
    case 'low':
        $prioClass = 'bg-slate-100 text-slate-600';
        break;
    case 'med':
        $prioClass = 'bg-yellow-100 text-yellow-800';
        break;
    case 'high':
        $prioClass = 'bg-orange-100 text-orange-700';
        break;
    case 'urgent':
        $prioClass = 'bg-[#942934] text-white';
        break;
    default:
        $prioClass = 'bg-slate-100 text-slate-600';
}

// clases vencimiento (igual que en view.php)
$dueClass = 'bg-slate-100 text-slate-600';
if ($due) {
    if ($due['state'] === 'overdue')
        $dueClass = 'bg-rose-100 text-rose-700';
    elseif ($due['state'] === 'soon')
        $dueClass = 'bg-orange-100 text-orange-700';
    else
        $dueClass = 'bg-slate-100 text-slate-600';
}
?>

<div class="task relative rounded-2xl border border-slate-200 bg-white p-3 shadow-sm hover:shadow-md transition cursor-grab group"
    data-task-id="<?= (int) $t['id'] ?>" draggable="true"
    title="Arrastra para mover • Doble clic para renombrar • Clic derecho para opciones">

    <!-- Título -->
    <div class="pr-10 font-bold text-sm text-slate-800 task-title">
        <?= htmlspecialchars($t['titulo']) ?>
    </div>

    <!-- Botón detalle (solo hover, NO montado) -->
    <div class="pointer-events-none absolute top-2.5 right-2.5">
        <a href="../tasks/view.php?id=<?= (int) $t['id'] ?>" draggable="false" class="pointer-events-auto opacity-0 group-hover:opacity-100 transition-opacity duration-200
                  inline-flex items-center justify-center
                  h-8 w-8 rounded-xl border border-slate-200 bg-white/90 shadow-sm
                  text-slate-400 hover:text-[#942934] hover:border-[#942934]/30 hover:bg-white">

            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="h-4 w-4">
                <path d="M14 3h7v7" />
                <path d="M10 14L21 3" />
                <path d="M21 14v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h6" />
            </svg>
        </a>
    </div>

    <!-- Chips -->
    <div class="row flex flex-wrap gap-2 mt-2">
        <span class="text-[11px] font-bold rounded-full px-2 py-1 <?= $prioClass ?>">
            <?= tr_priority_label($prio, true) ?>
        </span>

        <?php if ($due): ?>
            <span class="text-[11px] font-semibold rounded-full px-2 py-1 <?= $dueClass ?>">
                <?= htmlspecialchars($due['label'], ENT_QUOTES, 'UTF-8') ?>
            </span>
        <?php endif; ?>

        <?php if ($asig_first): ?>
            <span class="text-[11px] font-semibold rounded-full bg-slate-100 px-2 py-1 text-slate-600 chip-resp">
                👤 <?= htmlspecialchars($asig_first, ENT_QUOTES, 'UTF-8') ?>
            </span>
        <?php endif; ?>
    </div>
</div>