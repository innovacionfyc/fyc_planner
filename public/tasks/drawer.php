<?php
// public/tasks/drawer.php
require_once __DIR__ . '/../_auth.php';
require_login();
require_once __DIR__ . '/../../config/db.php';

function h($s)
{
    return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
}

$task_id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
if ($task_id <= 0) {
    http_response_code(400);
    echo '<div class="text-sm text-rose-700 font-semibold">ID de tarea inválido.</div>';
    exit;
}

// CSRF (por si el drawer luego envía POST)
if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}

$user_id = (int) ($_SESSION['user_id'] ?? 0);

// 1) Traer tarea + board + asignado
$sql = "
  SELECT
    t.id,
    t.board_id,
    t.column_id,
    t.titulo,
    t.prioridad,
    t.fecha_limite,
    t.assignee_id,
    b.nombre AS board_nombre,
    u.nombre AS asignado_nombre
  FROM tasks t
  JOIN boards b ON b.id = t.board_id
  LEFT JOIN users u ON u.id = t.assignee_id
  WHERE t.id = ?
  LIMIT 1
";
$stmt = $conn->prepare($sql);
if (!$stmt) {
    http_response_code(500);
    echo '<div class="text-sm text-rose-700 font-semibold">Error preparando consulta de tarea.</div>';
    exit;
}
$stmt->bind_param('i', $task_id);
$stmt->execute();
$task = $stmt->get_result()->fetch_assoc();

if (!$task) {
    http_response_code(404);
    echo '<div class="text-sm text-rose-700 font-semibold">Tarea no encontrada.</div>';
    exit;
}

$board_id = (int) $task['board_id'];

// 2) Validar membresía al board
$chk = $conn->prepare("SELECT 1 FROM board_members WHERE board_id = ? AND user_id = ? LIMIT 1");
if (!$chk) {
    http_response_code(500);
    echo '<div class="text-sm text-rose-700 font-semibold">Error validando acceso.</div>';
    exit;
}
$chk->bind_param('ii', $board_id, $user_id);
$chk->execute();
if (!$chk->get_result()->fetch_row()) {
    http_response_code(403);
    echo '<div class="text-sm text-rose-700 font-semibold">No tienes acceso a esta tarea.</div>';
    exit;
}

// 3) Miembros (para combo asignar)
$members = [];
$mm = $conn->prepare("
  SELECT u.id, u.nombre
  FROM board_members bm
  JOIN users u ON u.id = bm.user_id
  WHERE bm.board_id = ?
  ORDER BY u.nombre ASC
");
if ($mm) {
    $mm->bind_param('i', $board_id);
    $mm->execute();
    $members = $mm->get_result()->fetch_all(MYSQLI_ASSOC);
}

// 4) Comentarios (si existe tabla)
$comments = [];
$hasComments = false;
$t = $conn->query("SHOW TABLES LIKE 'comments'");
if ($t && $t->fetch_row())
    $hasComments = true;

if ($hasComments) {
    // Detectar columnas típicas (body/texto, created_at/creado_en)
    $cols = [];
    $rc = $conn->query("SHOW COLUMNS FROM comments");
    if ($rc) {
        while ($r = $rc->fetch_assoc())
            $cols[$r['Field']] = true;
    }

    $bodyCol = isset($cols['body']) ? 'body' : (isset($cols['texto']) ? 'texto' : null);
    $dateCol = isset($cols['created_at']) ? 'created_at' : (isset($cols['creado_en']) ? 'creado_en' : (isset($cols['created']) ? 'created' : null));

    if ($bodyCol) {
        $order = $dateCol ? "ORDER BY c.$dateCol ASC" : "ORDER BY c.id ASC";

        $q = "
      SELECT c.id,
             c.user_id,
             c.$bodyCol AS body,
             " . ($dateCol ? "c.$dateCol AS created_at" : "NULL AS created_at") . ",
             u.nombre AS user_nombre
      FROM comments c
      LEFT JOIN users u ON u.id = c.user_id
      WHERE c.task_id = ?
      $order
    ";
        $cs = $conn->prepare($q);
        if ($cs) {
            $cs->bind_param('i', $task_id);
            $cs->execute();
            $comments = $cs->get_result()->fetch_all(MYSQLI_ASSOC);
        }
    }
}

// Normalizar fecha (input type=date)
$fecha_val = '';
if (!empty($task['fecha_limite'])) {
    $fecha_val = substr((string) $task['fecha_limite'], 0, 10);
}

$prio = trim((string) ($task['prioridad'] ?? 'med'));
if ($prio === '')
    $prio = 'med';

$asig_id = !empty($task['assignee_id']) ? (int) $task['assignee_id'] : 0;
$asig_name = trim((string) ($task['asignado_nombre'] ?? ''));

?>
<div class="space-y-5">

    <!-- Título + metadata -->
    <div>
        <div class="text-xs font-extrabold text-slate-500">
            Tablero: <span class="text-slate-700">
                <?= h($task['board_nombre'] ?? '—') ?>
            </span>
        </div>

        <h2 class="mt-2 text-xl font-black text-slate-900 leading-snug">
            <?= h($task['titulo'] ?? 'Tarea') ?>
        </h2>

        <div class="mt-2 flex flex-wrap gap-2">
            <span
                class="inline-flex items-center rounded-full border border-slate-200 bg-white px-3 py-1 text-xs font-extrabold text-slate-700">
                ID:
                <?= (int) $task_id ?>
            </span>

            <?php if ($asig_name): ?>
                <span
                    class="inline-flex items-center rounded-full border border-slate-200 bg-white px-3 py-1 text-xs font-extrabold text-slate-700">
                    👤
                    <?= h(explode(' ', $asig_name)[0]) ?>
                </span>
            <?php endif; ?>
        </div>
    </div>

    <!-- Acciones rápidas (por ahora visuales, se conectan en Paso 4) -->
    <div class="rounded-2xl border border-slate-200 bg-slate-50 p-4">
        <div class="grid grid-cols-1 gap-4">

            <input type="hidden" id="drawer_task_id" value="<?= (int) $task_id ?>">
            <input type="hidden" id="drawer_board_id" value="<?= (int) $board_id ?>">
            <input type="hidden" id="drawer_csrf" value="<?= h($_SESSION['csrf']) ?>">

            <!-- Prioridad -->
            <div>
                <label class="block text-xs font-extrabold text-slate-600">Prioridad</label>
                <select id="drawer_prioridad"
                    class="mt-1 w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm font-semibold focus:ring-2 focus:ring-[#d32f57]/20 focus:border-[#d32f57]/40 outline-none">
                    <option value="low" <?= $prio === 'low' ? 'selected' : ''; ?>>Baja</option>
                    <option value="med" <?= $prio === 'med' ? 'selected' : ''; ?>>Media</option>
                    <option value="high" <?= $prio === 'high' ? 'selected' : ''; ?>>Alta</option>
                    <option value="urgent" <?= $prio === 'urgent' ? 'selected' : ''; ?>>Urgente</option>
                </select>
            </div>

            <!-- Fecha -->
            <div>
                <label class="block text-xs font-extrabold text-slate-600">Fecha límite</label>
                <input id="drawer_fecha" type="date" value="<?= h($fecha_val) ?>"
                    class="mt-1 w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm font-semibold focus:ring-2 focus:ring-[#d32f57]/20 focus:border-[#d32f57]/40 outline-none">
            </div>

            <!-- Asignado -->
            <div>
                <label class="block text-xs font-extrabold text-slate-600">Asignar a</label>
                <select id="drawer_assignee"
                    class="mt-1 w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm font-semibold focus:ring-2 focus:ring-[#d32f57]/20 focus:border-[#d32f57]/40 outline-none">
                    <option value="" <?= $asig_id ? '' : 'selected'; ?>>Sin responsable</option>
                    <?php foreach ($members as $m): ?>
                        <option value="<?= (int) $m['id'] ?>" <?= ((int) $m['id'] === $asig_id) ? 'selected' : ''; ?>>
                            <?= h($m['nombre']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="flex items-center justify-end gap-3 pt-1">
                <button type="button" data-action="drawer-cancel"
                    class="rounded-xl border border-slate-200 bg-white px-4 py-2 text-sm font-extrabold text-slate-700 hover:bg-slate-100 transition">
                    Cancelar
                </button>

                <button type="button" data-action="drawer-save"
                    class="rounded-xl bg-gradient-to-br from-[#d32f57] to-[#942934] px-4 py-2 text-sm font-extrabold text-white shadow hover:shadow-md transition">
                    Guardar cambios
                </button>
            </div>

            <p class="text-[12px] text-slate-500 font-semibold">
                * En el siguiente paso conectamos “Guardar cambios” con <code
                    class="font-black">tasks/update.php</code>.
            </p>
        </div>
    </div>

    <!-- Descripción (placeholder por ahora) -->
    <div class="rounded-2xl border border-slate-200 bg-white p-4">
        <div class="flex items-center justify-between">
            <h3 class="text-sm font-black text-slate-900">Descripción</h3>
            <span class="text-[11px] font-extrabold text-slate-400">Próximo paso</span>
        </div>

        <textarea id="drawer_desc" rows="5"
            placeholder="(En el Paso 5 agregamos descripción real: columna descripcion_md o tabla aparte)"
            class="mt-3 w-full rounded-2xl border border-slate-200 bg-slate-50 px-3 py-3 text-sm font-semibold text-slate-700 focus:ring-2 focus:ring-[#d32f57]/20 focus:border-[#d32f57]/40 outline-none"></textarea>
    </div>

    <!-- Comentarios -->
    <div class="rounded-2xl border border-slate-200 bg-white p-4">
        <div class="flex items-center justify-between">
            <h3 class="text-sm font-black text-slate-900">Comentarios</h3>
            <span class="text-[11px] font-extrabold text-slate-400">
                <?= $hasComments ? '' : '(tabla comments no detectada)' ?>
            </span>
        </div>

        <div class="mt-3 space-y-3">
            <?php if (!$hasComments): ?>
                <div class="text-sm text-slate-500 font-semibold">
                    No puedo mostrar comentarios porque no detecté la tabla <code class="font-black">comments</code>.
                </div>
            <?php elseif (!$comments): ?>
                <div class="text-sm text-slate-500 font-semibold">
                    Aún no hay comentarios.
                </div>
            <?php else: ?>
                <?php foreach ($comments as $c): ?>
                    <?php
                    $who = trim((string) ($c['user_nombre'] ?? 'Usuario'));
                    $when = '';
                    if (!empty($c['created_at'])) {
                        $when = substr((string) $c['created_at'], 0, 16);
                    }
                    ?>
                    <div class="rounded-2xl border border-slate-200 bg-slate-50 p-3">
                        <div class="flex items-center justify-between">
                            <div class="text-xs font-extrabold text-slate-700">
                                <?= h($who) ?>
                            </div>
                            <div class="text-[11px] font-bold text-slate-400">
                                <?= h($when) ?>
                            </div>
                        </div>
                        <div class="mt-2 text-sm font-semibold text-slate-700 whitespace-pre-wrap break-words">
                            <?= h($c['body'] ?? '') ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <div class="mt-4 border-t border-slate-200 pt-4">
            <label class="block text-xs font-extrabold text-slate-600">Agregar comentario</label>
            <textarea id="drawer_comment" rows="3" placeholder="Escribe un comentario…"
                class="mt-2 w-full rounded-2xl border border-slate-200 bg-white px-3 py-3 text-sm font-semibold text-slate-700 focus:ring-2 focus:ring-[#d32f57]/20 focus:border-[#d32f57]/40 outline-none"></textarea>

            <div class="mt-3 flex justify-end">
                <button type="button" data-action="drawer-add-comment"
                    class="rounded-xl border border-slate-200 bg-white px-4 py-2 text-sm font-extrabold text-slate-700 hover:bg-slate-100 transition">
                    Publicar
                </button>
            </div>

            <p class="mt-2 text-[12px] text-slate-500 font-semibold">
                * En el Paso 6 conectamos “Publicar” a un endpoint nuevo de comentarios.
            </p>
        </div>
    </div>

</div>