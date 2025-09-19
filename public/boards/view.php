<?php
require_once __DIR__ . '/../_auth.php';
require_login();
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../_i18n.php';

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

// Helper: tareas por columna
function get_tasks_by_column($conn, $board_id, $column_id)
{
    $sql = "SELECT t.id, t.titulo, t.prioridad, t.fecha_limite, t.creado_en,
                   t.assignee_id, u.nombre AS asignado_nombre
            FROM tasks t
            LEFT JOIN users u ON u.id = t.assignee_id
            WHERE t.board_id = ? AND t.column_id = ?
            ORDER BY t.creado_en DESC";
    $s = $conn->prepare($sql);
    $s->bind_param('ii', $board_id, $column_id);
    $s->execute();
    return $s->get_result()->fetch_all(MYSQLI_ASSOC);
}

// Vencimiento (chip)
function due_meta($dateStr)
{
    if (!$dateStr)
        return null;
    try {
        $today = new DateTime('today');
        $d = new DateTime($dateStr);
        $days = (int) $today->diff($d)->format('%r%a'); // negativo = ya venció
        $state = ($days < 0) ? 'overdue' : (($days <= 2) ? 'soon' : 'ok');
        return ['label' => $d->format('d/m/Y'), 'state' => $state];
    } catch (Throwable $e) {
        return null;
    }
}
?>
<!doctype html>
<html lang="es">

<head>
    <meta charset="utf-8">
    <title><?= htmlspecialchars($board['nombre']) ?> — F&C Planner</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <style>
        :root {
            --fc-vino: #942934;
            --fc-vino-2: #d32f57;
            --gap: 12px;
            --col-min: 320px;
            --col-max: 1fr
        }

        body {
            font-family: system-ui;
            margin: 0;
            background: #f7f7f7
        }

        .wrap {
            max-width: 1200px;
            margin: 24px auto;
            padding: 0 16px
        }

        .top {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 16px
        }

        .h1 {
            margin: 0;
            color: var(--fc-vino)
        }

        .kanban {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(var(--col-min), var(--col-max)));
            gap: var(--gap);
            align-items: start
        }

        .col {
            background: #fff;
            border: 1px solid #ddd;
            border-radius: 16px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, .06);
            padding: 12px;
            min-height: 260px;
            transition: box-shadow .15s, border-color .15s
        }

        .col h3 {
            margin: 0 0 8px
        }

        .tasks {
            min-height: 20px
        }

        .task {
            background: #fff;
            border: 1px solid #e5e5e5;
            border-radius: 12px;
            padding: 10px;
            margin: 8px 0;
            box-shadow: 0 6px 18px rgba(0, 0, 0, .04);
            cursor: grab;
            transition: opacity .15s, transform .1s;
            position: relative;
            user-select: none
        }

        .task:active {
            cursor: grabbing
        }

        .task.dragging {
            opacity: .6
        }

        .col.over {
            border-color: var(--fc-vino-2);
            box-shadow: 0 0 0 3px rgba(211, 47, 87, .15)
        }

        .empty {
            color: #888;
            font-size: 14px;
            margin-top: 6px
        }

        .form {
            display: flex;
            gap: 6px;
            margin-top: 6px
        }

        .input {
            flex: 1;
            padding: 9px;
            border: 1px solid #ccc;
            border-radius: 10px
        }

        .btn {
            padding: 9px 12px;
            border: 0;
            border-radius: 10px;
            background: var(--fc-vino-2);
            color: #fff;
            font-weight: 700;
            cursor: pointer
        }

        .btn:hover {
            filter: brightness(1.08)
        }

        a {
            color: var(--fc-vino);
            text-decoration: none;
            font-weight: 600
        }

        .muted {
            color: #777;
            font-size: 12px
        }

        .due {
            font-size: 12px
        }

        .bar {
            height: 1px;
            background: #eee;
            margin: 8px 0
        }

        .title {
            font-weight: 700
        }

        .title-edit {
            width: 100%;
            box-sizing: border-box;
            padding: 8px;
            border: 1px solid #ccc;
            border-radius: 8px;
            font: inherit
        }

        .ctx {
            position: fixed;
            z-index: 9999;
            background: #fff;
            border: 1px solid #ddd;
            border-radius: 10px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, .08);
            min-width: 160px;
            overflow: hidden
        }

        .ctx button {
            display: block;
            width: 100%;
            text-align: left;
            padding: 10px 12px;
            background: #fff;
            border: 0;
            font: inherit;
            cursor: pointer
        }

        .ctx button:hover {
            background: #f7f7f7
        }

        .ctx .danger {
            color: #e96510;
            font-weight: 700
        }

        .badge {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 999px;
            font-size: 12px;
            font-weight: 700
        }

        .badge-low {
            background: #e5e7eb;
            color: #374151
        }

        .badge-med {
            background: #fde68a;
            color: #92400e
        }

        .badge-high {
            background: #fee7d6;
            color: #9a3412;
            border: 1px solid #f39322
        }

        .badge-urgent {
            background: #942934;
            color: #fff
        }

        .due-chip {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 999px;
            font-size: 12px;
            font-weight: 600;
            border: 1px solid #ddd;
            color: #555;
            background: #fafafa
        }

        .due-ok {
            border-color: #cbd5e1;
            background: #f8fafc;
            color: #334155
        }

        .due-soon {
            border-color: #f39322;
            background: #fff7ed;
            color: #9a3412
        }

        .due-overdue {
            border-color: #d32f57;
            background: #ffe8ea;
            color: #8b1c2b
        }

        .cnt {
            display: inline-block;
            margin-left: 8px;
            padding: 2px 8px;
            border-radius: 999px;
            font-size: 12px;
            font-weight: 700;
            background: #f8fafc;
            border: 1px solid #cbd5e1;
            color: #334155
        }

        .task.moved {
            animation: pop .18s ease-out
        }

        @keyframes pop {
            0% {
                transform: scale(.98);
                opacity: .8
            }

            100% {
                transform: scale(1);
                opacity: 1
            }
        }

        .actions {
            position: relative;
            display: flex;
            align-items: center;
            gap: 12px
        }

        .bell {
            position: relative;
            background: #fff;
            border: 1px solid #ddd;
            border-radius: 999px;
            padding: 8px 12px;
            cursor: pointer
        }

        .bell:hover {
            filter: brightness(1.04)
        }

        .bell .n {
            position: absolute;
            top: -6px;
            right: -6px;
            background: #d32f57;
            color: #fff;
            border-radius: 999px;
            padding: 2px 6px;
            font-size: 11px;
            font-weight: 700;
            display: none
        }

        .panel {
            position: absolute;
            right: 0;
            top: 100%;
            margin-top: 8px;
            width: 340px;
            background: #fff;
            border: 1px solid #ddd;
            border-radius: 12px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, .08);
            display: none;
            overflow: hidden;
            z-index: 12000
        }

        .panel.open {
            display: block
        }

        .note {
            padding: 10px 12px;
            border-bottom: 1px solid #f0f0f0;
            cursor: pointer
        }

        .note:hover {
            background: #f7f7f7
        }

        .note:last-child {
            border-bottom: 0
        }

        .note time {
            display: block;
            font-size: 12px;
            color: #777
        }

        .panel .foot {
            padding: 8px 12px;
            text-align: right
        }

        .team-chip {
            display: inline-block;
            margin-left: 8px;
            padding: 2px 8px;
            border-radius: 999px;
            font-size: 12px;
            border: 1px solid #ddd;
            background: #fafafa;
            color: #555
        }

        .chip-resp {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 999px;
            font-size: 12px;
            border: 1px solid #cbd5e1;
            background: #f1f5f9;
            color: #334155
        }

        .picker {
            position: fixed;
            z-index: 10000;
            background: #fff;
            border: 1px solid #ddd;
            border-radius: 10px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, .08);
            min-width: 220px;
            max-height: 260px;
            overflow: auto
        }

        .picker .item {
            padding: 8px 10px;
            cursor: pointer
        }

        .picker .item:hover {
            background: #f7f7f7
        }

        .picker .item.danger {
            color: #8b1c2b
        }

        .presence-wrap {
            display: flex;
            gap: 6px;
            align-items: center;
            margin-right: 6px
        }

        .avatar {
            width: 28px;
            height: 28px;
            border-radius: 999px;
            background: #e5e7eb;
            color: #111;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 13px;
            font-weight: 700;
            border: 2px solid #fff;
            box-shadow: 0 0 0 1px #ddd
        }

        .avatar.me {
            background: #dbeafe
        }
    </style>
</head>

<body>
    <div class="wrap">
        <div class="top">
            <h1 class="h1">
                <?= htmlspecialchars($board['nombre']) ?>
                <?php if (!empty($board['team_id'])): ?>
                    <span class="team-chip">Equipo: <?= htmlspecialchars($board['team_nombre'] ?? '—') ?></span>
                <?php endif; ?>
            </h1>

            <div class="actions">
                <button id="bell" class="bell" type="button">🔔 <span id="bellN" class="n">0</span></button>
                <div id="bellPanel" class="panel">
                    <div id="notes"></div>
                    <div class="foot">
                        <form id="markAllForm" method="post" action="../notifications/mark_read.php"
                            style="display:inline">
                            <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['csrf']) ?>">
                            <input type="hidden" name="all" value="1">
                            <button class="btn" type="submit">Marcar como leídas</button>
                        </form>
                    </div>
                </div>
                <a href="index.php">← Mis tableros</a> &nbsp;|&nbsp;
                <a href="../logout.php">Cerrar sesión</a>
                <div id="presence" class="presence-wrap" title="Conectados en este tablero"></div>
            </div>
        </div>

        <div class="kanban" id="kanban" data-board-id="<?= (int) $board_id ?>"
            data-csrf="<?= htmlspecialchars($_SESSION['csrf']) ?>">
            <?php foreach ($columns as $c): ?>
                <?php $tasks = get_tasks_by_column($conn, $board_id, (int) $c['id']);
                $count = count($tasks); ?>
                <div class="col" data-column-id="<?= (int) $c['id'] ?>">
                    <h3><?= htmlspecialchars($c['nombre']) ?> <span class="cnt"><?= (int) $count ?></span></h3>

                    <form class="form" method="post" action="../tasks/create.php">
                        <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['csrf']) ?>">
                        <input type="hidden" name="board_id" value="<?= (int) $board_id ?>">
                        <input type="hidden" name="column_id" value="<?= (int) $c['id'] ?>">
                        <input class="input" type="text" name="titulo" placeholder="Nueva tarea..." required>
                        <button class="btn" type="submit">Añadir</button>
                    </form>

                    <div class="tasks">
                        <?php if (!$tasks): ?>
                            <div class="empty">No hay tareas aún.</div>
                        <?php else:
                            foreach ($tasks as $t): ?>
                                <div class="task" data-task-id="<?= (int) $t['id'] ?>" draggable="true"
                                    title="Arrastra para mover • Doble clic para renombrar • Clic derecho para opciones">
                                    <div class="title task-title"><?= htmlspecialchars($t['titulo']) ?></div>
                                    <?php
                                    $prio = htmlspecialchars($t['prioridad'] ?? 'med', ENT_QUOTES, 'UTF-8');
                                    $due = !empty($t['fecha_limite']) ? due_meta($t['fecha_limite']) : null;
                                    $asig = trim((string) ($t['asignado_nombre'] ?? ''));
                                    $asig_first = $asig ? explode(' ', $asig)[0] : '';
                                    ?>
                                    <div class="row" style="display:flex;gap:6px;flex-wrap:wrap;margin-top:6px">
                                        <span class="badge badge-<?= $prio ?>"><?= tr_priority_label($prio, true) ?></span>
                                        <?php if ($due): ?>
                                            <span class="due-chip due-<?= $due['state'] ?>">Vence:
                                                <?= htmlspecialchars($due['label'], ENT_QUOTES, 'UTF-8') ?></span>
                                        <?php endif; ?>
                                        <?php if ($asig_first): ?>
                                            <span class="chip-resp" title="Responsable">👤
                                                <?= htmlspecialchars($asig_first, ENT_QUOTES, 'UTF-8') ?></span>
                                        <?php endif; ?>
                                    </div>
                                    <div style="margin-top:6px">
                                        <a draggable="false" href="../tasks/view.php?id=<?= (int) $t['id'] ?>">Abrir detalle →</a>
                                    </div>
                                </div>
                            <?php endforeach; endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

    <script id="members-data" type="application/json">
<?= json_encode($board_members, JSON_UNESCAPED_UNICODE) ?>
</script>

    <script>
        // POST helper
        function postForm(url, data) {
            return fetch(url, { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: new URLSearchParams(data) });
        }
        function closeCtx() { document.querySelectorAll('.ctx').forEach(el => el.remove()); }
        document.addEventListener('click', closeCtx);
        document.addEventListener('scroll', closeCtx, true);

        document.addEventListener('DOMContentLoaded', () => {
            const bell = document.getElementById('bell');
            const bellN = document.getElementById('bellN');
            const panel = document.getElementById('bellPanel');
            const notes = document.getElementById('notes');

            const kanban = document.getElementById('kanban');
            const csrf = kanban.dataset.csrf;
            const boardId = kanban.dataset.boardId;

            function esc(s) { return String(s).replace(/[&<>"']/g, m => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' }[m])); }

            // ===== Presencia =====
            const presenceBox = document.getElementById('presence');
            function initials(name) { name = String(name || '').trim(); if (!name) return '?'; const p = name.split(/\s+/); return (p[0][0] || '') + (p[1] ? p[1][0] : ''); }
            async function pingPresence() {
                try {
                    const fd = new URLSearchParams({ csrf, board_id: boardId });
                    const r = await fetch('../boards/presence_ping.php', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: fd });
                    if (!r.ok) return;
                    const data = await r.json();
                    const active = data.active || [];
                    presenceBox.innerHTML = active.map(u =>
                        `<div class="avatar ${u.id == <?= (int) $_SESSION['user_id'] ?> ? 'me' : ''}" title="${esc(u.nombre)}">${esc(initials(u.nombre))}</div>`
                    ).join('');
                } catch (_) { }
            }
            pingPresence();
            setInterval(pingPresence, 12000);

            // ===== Notificaciones =====
            async function loadNotes() {
                try {
                    const r = await fetch('../notifications/feed.php');
                    if (!r.ok) return;
                    const data = await r.json();
                    const arr = data.items || [];
                    bellN.textContent = arr.length;
                    bellN.style.display = arr.length ? 'inline-block' : 'none';
                    notes.innerHTML = arr.length
                        ? arr.map(n => `<div class="note" data-id="${n.id}" data-url="${esc(n.url || '')}"><strong>${esc(n.title)}</strong><time>${esc(n.when)}</time></div>`).join('')
                        : '<div class="note">Sin notificaciones</div>';
                } catch (_) { }
            }
            bell.addEventListener('click', async () => { panel.classList.toggle('open'); if (panel.classList.contains('open')) await loadNotes(); });
            document.addEventListener('click', (e) => { if (!panel.contains(e.target) && !bell.contains(e.target)) panel.classList.remove('open'); });
            document.getElementById('markAllForm').addEventListener('submit', async (e) => {
                e.preventDefault();
                const r = await fetch(e.currentTarget.action, { method: 'POST', body: new FormData(e.currentTarget) });
                if (r.ok) { bellN.style.display = 'none'; notes.innerHTML = '<div class="note">Sin notificaciones</div>'; }
            });
            loadNotes(); setInterval(loadNotes, 20000);

            // Click en notificación → marcar y navegar
            notes.addEventListener('click', async (e) => {
                const el = e.target.closest('.note'); if (!el) return;
                const url = el.dataset.url, nid = el.dataset.id; if (!url) return;
                try {
                    const fd = new FormData(); fd.append('csrf', csrf); fd.append('note_id', nid);
                    await fetch('../notifications/mark_read.php', { method: 'POST', body: fd });
                } catch (_) { }
                window.location = url;
            });

            // UX: enfocar primer input
            const firstInput = document.querySelector('.form .input'); if (firstInput) firstInput.focus();

            // ===== Drag & inline rename & menú =====
            function bindTaskDrag(task) {
                task.addEventListener('dragstart', (e) => { e.dataTransfer.setData('text/plain', task.dataset.taskId); e.dataTransfer.effectAllowed = 'move'; task.classList.add('dragging'); });
                task.addEventListener('dragend', () => task.classList.remove('dragging'));
            }
            document.querySelectorAll('.task[draggable="true"]').forEach(bindTaskDrag);

            function bindTaskInlineEdit(task) {
                const titleEl = task.querySelector('.task-title');
                titleEl.addEventListener('dblclick', () => {
                    const old = titleEl.textContent.trim();
                    const input = document.createElement('input');
                    input.type = 'text'; input.value = old; input.className = 'title-edit';
                    const save = async (commit) => {
                        const span = document.createElement('div'); span.className = 'title task-title'; span.textContent = commit ? (input.value.trim() || old) : old;
                        input.replaceWith(span); bindTaskInlineEdit(task);
                        if (commit) {
                            const newTitle = span.textContent.trim(); if (newTitle !== old) {
                                try { await postForm('../tasks/rename.php', { csrf, board_id: boardId, task_id: task.dataset.taskId, titulo: newTitle }); } catch (e) { alert('No pude renombrar. Se recargará la página.'); location.reload(); }
                            }
                        }
                    };
                    titleEl.replaceWith(input); input.focus(); input.select();
                    input.addEventListener('keydown', (e) => { if (e.key === 'Enter') save(true); if (e.key === 'Escape') save(false); });
                    input.addEventListener('blur', () => save(true));
                    task.setAttribute('draggable', 'false'); input.addEventListener('blur', () => task.setAttribute('draggable', 'true'), { once: true });
                });
            }
            document.querySelectorAll('.task').forEach(bindTaskInlineEdit);

            function bindTaskContextMenu(task) {
                task.addEventListener('contextmenu', (e) => {
                    e.preventDefault(); closeCtx();
                    const menu = document.createElement('div'); menu.className = 'ctx';
                    menu.innerHTML = `<button data-act="assign">Asignar a…</button><button data-act="delete" class="danger">Eliminar tarea…</button>`;
                    document.body.appendChild(menu);
                    const x = Math.min(e.clientX, window.innerWidth - menu.offsetWidth - 8), y = Math.min(e.clientY, window.innerHeight - menu.offsetHeight - 8);
                    menu.style.left = x + 'px'; menu.style.top = y + 'px';

                    menu.addEventListener('click', async (ev) => {
                        const act = ev.target.dataset.act;
                        if (act === 'assign') {
                            const membersJson = document.getElementById('members-data')?.textContent || '[]';
                            let members = []; try { members = JSON.parse(membersJson); } catch (_) { members = []; }
                            const picker = document.createElement('div'); picker.className = 'picker';
                            picker.innerHTML = `<div class="item" data-user="">Sin asignar</div>` +
                                members.map(m => `<div class="item" data-user="${m.id}">👤 ${m.nombre.replace(/[&<>"]/g, s => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[s]))}</div>`).join('');
                            document.body.appendChild(picker);
                            const rect = menu.getBoundingClientRect();
                            const px = Math.min(rect.right + 8, window.innerWidth - picker.offsetWidth - 8);
                            const py = Math.min(rect.top, window.innerHeight - picker.offsetHeight - 8);
                            picker.style.left = px + 'px'; picker.style.top = py + 'px';
                            const closePicker = () => { picker.remove(); }; setTimeout(() => document.addEventListener('click', closePicker, { once: true }), 0);
                            picker.addEventListener('click', async (e2) => {
                                const it = e2.target.closest('.item'); if (!it) return; const uid = it.dataset.user;
                                try {
                                    const res = await fetch('../tasks/assign.php', {
                                        method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                                        body: new URLSearchParams({ csrf, board_id: boardId, task_id: task.dataset.taskId, assignee_id: uid })
                                    });
                                    if (!res.ok) throw new Error('HTTP ' + res.status);
                                    const data = await res.json(); if (!data.ok) throw new Error('Asignación fallida');
                                    let chip = task.querySelector('.chip-resp');
                                    if (uid === '') { if (chip) chip.remove(); }
                                    else {
                                        if (!chip) { chip = document.createElement('span'); chip.className = 'chip-resp'; chip.title = 'Responsable'; task.querySelector('.row').appendChild(chip); }
                                        chip.textContent = '👤 ' + (data.assignee_first || '');
                                    }
                                    closePicker(); closeCtx();
                                } catch (err) { alert('No pude asignar. Se recargará la página.'); location.reload(); }
                            });
                            return;
                        }
                        if (act === 'delete') {
                            closeCtx(); if (!confirm('¿Eliminar esta tarea?')) return;
                            try {
                                await postForm('../tasks/delete.php', { csrf, board_id: boardId, task_id: task.dataset.taskId });
                                const list = task.closest('.tasks'); const col = task.closest('.col'); task.remove();
                                if (!list.querySelector('.task')) { if (!list.querySelector('.empty')) { const ph = document.createElement('div'); ph.className = 'empty'; ph.textContent = 'No hay tareas aún.'; list.appendChild(ph); } }
                                if (col) { const cnt = col.querySelector('.cnt'); if (cnt) { cnt.textContent = list.querySelectorAll('.task').length; } }
                            } catch (e) { alert('No pude eliminar. Se recargará la página.'); location.reload(); }
                        }
                    }, { once: true });
                });
            }
            document.querySelectorAll('.task').forEach(bindTaskContextMenu);

            // Zonas de drop
            document.querySelectorAll('.col[data-column-id]').forEach(col => {
                col.addEventListener('dragover', (e) => { e.preventDefault(); e.dataTransfer.dropEffect = 'move'; col.classList.add('over'); });
                col.addEventListener('dragleave', () => col.classList.remove('over'));
                col.addEventListener('drop', async (e) => {
                    e.preventDefault(); col.classList.remove('over');
                    const taskId = e.dataTransfer.getData('text/plain'); if (!taskId) return;
                    const taskEl = document.querySelector('.task[data-task-id="' + taskId + '"]');
                    const list = col.querySelector('.tasks'); if (!taskEl || !list) return;
                    const srcCol = taskEl.closest('.col');
                    const destEmpty = list.querySelector('.empty'); if (destEmpty) destEmpty.remove();
                    list.prepend(taskEl); taskEl.classList.add('moved'); taskEl.addEventListener('animationend', () => taskEl.classList.remove('moved'), { once: true });
                    const updateCount = (columnEl) => {
                        const cnt = columnEl.querySelector('.cnt'); const n = columnEl.querySelectorAll('.tasks .task').length; if (cnt) cnt.textContent = n;
                        if (n === 0 && !columnEl.querySelector('.empty')) { const ph = document.createElement('div'); ph.className = 'empty'; ph.textContent = 'No hay tareas aún.'; columnEl.querySelector('.tasks').appendChild(ph); }
                    };
                    updateCount(col); if (srcCol && srcCol !== col) updateCount(srcCol);
                    try {
                        const body = new URLSearchParams({ csrf, board_id: boardId, task_id: taskId, column_id: col.dataset.columnId });
                        const res = await fetch('../tasks/move.php', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body });
                        if (!res.ok) throw new Error('HTTP ' + res.status);
                    } catch (err) { alert('No pude mover la tarea. Se recargará la página.'); location.reload(); }
                });
            });

            // ===== Realtime (poll) =====
            let lastEventId = 0;

            function updateCounters(colEl) {
                const cnt = colEl.querySelector('.cnt');
                const n = colEl.querySelectorAll('.task').length;
                if (cnt) cnt.textContent = n;
                if (n === 0 && !colEl.querySelector('.empty')) {
                    const ph = document.createElement('div'); ph.className = 'empty'; ph.textContent = 'No hay tareas aún.';
                    colEl.querySelector('.tasks').appendChild(ph);
                }
            }

            async function createOrRefreshTask(taskId, colId) {
                let el = document.querySelector(`.task[data-task-id="${taskId}"]`);
                const colEl = document.querySelector(`.col[data-column-id="${colId}"]`);
                if (!colEl) return;
                const list = colEl.querySelector('.tasks');
                const empty = list.querySelector('.empty'); if (empty) empty.remove();

                if (!el) {
                    const r = await fetch(`../tasks/card_html.php?id=${taskId}`);
                    if (!r.ok) return;
                    const html = await r.text();
                    const tmp = document.createElement('div'); tmp.innerHTML = html.trim();
                    el = tmp.firstElementChild;
                    list.prepend(el);
                    bindTaskDrag(el); bindTaskInlineEdit(el); bindTaskContextMenu(el);
                } else {
                    list.prepend(el);
                }
                el.classList.add('moved'); el.addEventListener('animationend', () => el.classList.remove('moved'), { once: true });
                updateCounters(colEl);
            }

            function applyEvent(ev) {
                if (ev.kind === 'task_moved') {
                    if (!ev.column_id) return; // Ignora eventos viejos con NULL
                    const destCol = document.querySelector(`.col[data-column-id="${ev.column_id}"]`);
                    if (!destCol) return;
                    const list = destCol.querySelector('.tasks');
                    const empty = list.querySelector('.empty'); if (empty) empty.remove();
                    const taskEl = document.querySelector(`.task[data-task-id="${ev.task_id}"]`);
                    const srcCol = taskEl ? taskEl.closest('.col') : null;
                    if (taskEl) {
                        list.prepend(taskEl);
                        taskEl.classList.add('moved'); taskEl.addEventListener('animationend', () => taskEl.classList.remove('moved'), { once: true });
                    } else {
                        createOrRefreshTask(ev.task_id, ev.column_id);
                    }
                    updateCounters(destCol); if (srcCol) updateCounters(srcCol);
                } else if (ev.kind === 'task_renamed') {
                    const taskEl = document.querySelector(`.task[data-task-id="${ev.task_id}"]`);
                    if (taskEl) {
                        const t = taskEl.querySelector('.task-title');
                        if (t && ev.payload && ev.payload.title) t.textContent = ev.payload.title;
                    } else if (ev.column_id) {
                        createOrRefreshTask(ev.task_id, ev.column_id);
                    }
                } else if (ev.kind === 'task_created') {
                    if (ev.column_id) createOrRefreshTask(ev.task_id, ev.column_id);
                } else if (ev.kind === 'task_deleted') {
                    const taskEl = document.querySelector(`.task[data-task-id="${ev.task_id}"]`);
                    if (taskEl) {
                        const col = taskEl.closest('.col'); const list = taskEl.closest('.tasks');
                        taskEl.remove(); if (col) updateCounters(col);
                        if (!list.querySelector('.task') && !list.querySelector('.empty')) {
                            const ph = document.createElement('div'); ph.className = 'empty'; ph.textContent = 'No hay tareas aún.'; list.appendChild(ph);
                        }
                    }
                } else if (ev.kind === 'task_assigned') {
                    const taskEl = document.querySelector(`.task[data-task-id="${ev.task_id}"]`);
                    if (!taskEl) return;
                    let chip = taskEl.querySelector('.chip-resp');
                    const name = ev.payload ? (ev.payload.assignee_first || '') : '';
                    if (!name) { if (chip) chip.remove(); }
                    else {
                        if (!chip) {
                            chip = document.createElement('span'); chip.className = 'chip-resp'; chip.title = 'Responsable';
                            const row = taskEl.querySelector('.row'); if (row) row.appendChild(chip);
                        }
                        chip.textContent = '👤 ' + name;
                    }
                }
            }

            async function pollEvents() {
                try {
                    const r = await fetch(`../boards/events_poll.php?board_id=${boardId}&after_id=${lastEventId}`);
                    if (!r.ok) return;
                    const data = await r.json();
                    const events = data.events || [];
                    for (const ev of events) {
                        lastEventId = Math.max(lastEventId, ev.id);
                        applyEvent(ev);
                    }
                } catch (_) { }
            }
            setInterval(pollEvents, 3000);
            pollEvents();
        });
    </script>
</body>

</html>