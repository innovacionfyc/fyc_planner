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

?>
<div class="task" data-task-id="<?= (int) $t['id'] ?>" draggable="true"
    title="Arrastra para mover • Doble clic para renombrar • Clic derecho para opciones">
    <div class="title task-title"><?= htmlspecialchars($t['titulo']) ?></div>
    <div class="row" style="display:flex;gap:6px;flex-wrap:wrap;margin-top:6px">
        <span class="badge badge-<?= $prio ?>"><?= tr_priority_label($prio, true) ?></span>
        <?php if ($due): ?>
            <span class="due-chip due-<?= $due['state'] ?>">Vence:
                <?= htmlspecialchars($due['label'], ENT_QUOTES, 'UTF-8') ?></span>
        <?php endif; ?>
        <?php if ($asig_first): ?>
            <span class="chip-resp" title="Responsable">👤 <?= htmlspecialchars($asig_first, ENT_QUOTES, 'UTF-8') ?></span>
        <?php endif; ?>
    </div>
    <div style="margin-top:6px">
        <a draggable="false" href="../tasks/view.php?id=<?= (int) $t['id'] ?>">Abrir detalle →</a>
    </div>
</div>