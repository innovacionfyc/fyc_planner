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
    echo '<div style="font-size:13px;color:var(--badge-overdue-tx);">ID de tarea inválido.</div>';
    exit;
}

if (empty($_SESSION['csrf']))
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
$user_id = (int) ($_SESSION['user_id'] ?? 0);
require_once __DIR__ . '/../_perm.php';

// Detectar columna descripcion_md
$hasDescCol = false;
$colsTasks = $conn->query("SHOW COLUMNS FROM tasks");
if ($colsTasks) {
    while ($r = $colsTasks->fetch_assoc()) {
        if ($r['Field'] === 'descripcion_md') {
            $hasDescCol = true;
            break;
        }
    }
}

// Detectar tabla task_tags
$hasTags = false;
$tt = $conn->query("SHOW TABLES LIKE 'task_tags'");
if ($tt && $tt->fetch_row())
    $hasTags = true;

// 1) Tarea
$sql = "SELECT t.id, t.board_id, t.column_id, t.titulo, t.prioridad, t.fecha_limite, t.assignee_id"
    . ($hasDescCol ? ", t.descripcion_md" : "")
    . ", b.nombre AS board_nombre, u.nombre AS asignado_nombre
       FROM tasks t
       JOIN boards b ON b.id = t.board_id
       LEFT JOIN users u ON u.id = t.assignee_id
       WHERE t.id = ? LIMIT 1";
$stmt = $conn->prepare($sql);
if (!$stmt) {
    http_response_code(500);
    echo '<div style="color:var(--badge-overdue-tx);font-size:13px;">Error DB.</div>';
    exit;
}
$stmt->bind_param('i', $task_id);
$stmt->execute();
$task = $stmt->get_result()->fetch_assoc();
if (!$task) {
    http_response_code(404);
    echo '<div style="color:var(--badge-overdue-tx);font-size:13px;">Tarea no encontrada.</div>';
    exit;
}

$board_id = (int) $task['board_id'];

// 2) Acceso
if (!has_board_access($conn, $board_id, $user_id)) {
    http_response_code(403);
    echo '<div style="color:var(--badge-overdue-tx);font-size:13px;">Sin acceso.</div>';
    exit;
}

// 3) Miembros (fuente correcta según tipo de tablero: equipo → team_members, personal → board_members)
$members = get_board_members_for_assignee($conn, $board_id);

// 4) Comentarios
$comments = [];
$hasComments = false;
$tc = $conn->query("SHOW TABLES LIKE 'comments'");
if ($tc && $tc->fetch_row())
    $hasComments = true;
if ($hasComments) {
    $cols = [];
    $rc = $conn->query("SHOW COLUMNS FROM comments");
    if ($rc) {
        while ($r = $rc->fetch_assoc())
            $cols[$r['Field']] = true;
    }
    $bodyCol = isset($cols['body']) ? 'body' : (isset($cols['texto']) ? 'texto' : null);
    $dateCol = isset($cols['created_at']) ? 'created_at' : (isset($cols['creado_en']) ? 'creado_en' : null);
    if ($bodyCol) {
        $order = $dateCol ? "ORDER BY c.$dateCol ASC" : "ORDER BY c.id ASC";
        $cs = $conn->prepare("SELECT c.id, c.user_id, c.$bodyCol AS body, " . ($dateCol ? "c.$dateCol AS created_at" : "NULL AS created_at") . ", u.nombre AS user_nombre FROM comments c LEFT JOIN users u ON u.id=c.user_id WHERE c.task_id=? $order");
        if ($cs) {
            $cs->bind_param('i', $task_id);
            $cs->execute();
            $comments = $cs->get_result()->fetch_all(MYSQLI_ASSOC);
        }
    }
}

// 5) Tags del tablero + tags asignados a esta tarea
$boardTags = [];
$taskTagIds = [];
if ($hasTags) {
    $tg = $conn->prepare("SELECT id, nombre, color_hex FROM task_tags WHERE board_id=? ORDER BY nombre ASC");
    if ($tg) {
        $tg->bind_param('i', $board_id);
        $tg->execute();
        $boardTags = $tg->get_result()->fetch_all(MYSQLI_ASSOC);
    }
    $tp = $conn->prepare("SELECT tag_id FROM task_tag_pivot WHERE task_id=?");
    if ($tp) {
        $tp->bind_param('i', $task_id);
        $tp->execute();
        $rows = $tp->get_result()->fetch_all(MYSQLI_ASSOC);
        foreach ($rows as $r)
            $taskTagIds[] = (int) $r['tag_id'];
    }
}

// Normalizar valores
$fecha_val = !empty($task['fecha_limite']) ? substr((string) $task['fecha_limite'], 0, 10) : '';
$prio = trim((string) ($task['prioridad'] ?? 'med')) ?: 'med';
$asig_id = !empty($task['assignee_id']) ? (int) $task['assignee_id'] : 0;
$asig_name = trim((string) ($task['asignado_nombre'] ?? ''));
$desc_val = ($hasDescCol && isset($task['descripcion_md'])) ? (string) $task['descripcion_md'] : '';

// Colores predefinidos para crear tags
$tagColors = ['#e85070', '#e87050', '#d4a040', '#40a060', '#4090e8', '#9070e8', '#e070b0', '#50b0a0'];
?>

<div style="display:flex;flex-direction:column;gap:16px;">

    <!-- TÍTULO + META -->
    <div>
        <div style="font-size:10px;font-weight:700;color:var(--text-ghost);text-transform:uppercase;letter-spacing:1px;">
            <?= h($task['board_nombre'] ?? '—') ?>
        </div>
        <h2 style="margin:6px 0 8px;font-family:'Sora',sans-serif;font-size:18px;font-weight:800;color:var(--text-primary);line-height:1.3;">
            <?= h($task['titulo'] ?? 'Tarea') ?>
        </h2>
        <div style="display:flex;flex-wrap:wrap;gap:6px;align-items:center;">
            <span style="font-size:10px;font-weight:700;padding:2px 8px;border-radius:999px;background:var(--bg-hover);color:var(--text-ghost);">
                #<?= (int) $task_id ?>
            </span>
            <?php if ($asig_name): ?>
                    <span style="font-size:10px;font-weight:700;padding:2px 8px;border-radius:999px;background:var(--bg-hover);color:var(--text-muted);display:inline-flex;align-items:center;gap:4px;">
                        <svg width="11" height="11" viewBox="0 0 16 16" fill="none" xmlns="http://www.w3.org/2000/svg" style="flex-shrink:0;"><circle cx="8" cy="5.5" r="3" stroke="currentColor" stroke-width="1.5"/><path d="M2 13.5c0-3 2-4.5 6-4.5s6 1.5 6 4.5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>
                        <?= h(explode(' ', $asig_name)[0]) ?>
                    </span>
            <?php endif; ?>
        </div>
    </div>

    <!-- CAMPOS RÁPIDOS -->
    <div style="background:var(--bg-hover);border-radius:12px;padding:14px;display:flex;flex-direction:column;gap:12px;">
        <input type="hidden" id="drawer_task_id"  value="<?= (int) $task_id ?>">
        <input type="hidden" id="drawer_board_id" value="<?= (int) $board_id ?>">
        <input type="hidden" id="drawer_csrf"     value="<?= h($_SESSION['csrf']) ?>">

        <div>
            <label style="display:block;font-size:10px;font-weight:700;color:var(--text-ghost);text-transform:uppercase;letter-spacing:0.8px;margin-bottom:5px;">Prioridad</label>
            <select id="drawer_prioridad" class="fyc-select" style="font-size:13px;">
                <option value="low"    <?= $prio === 'low' ? 'selected' : '' ?>>Baja</option>
                <option value="med"    <?= $prio === 'med' ? 'selected' : '' ?>>Media</option>
                <option value="high"   <?= $prio === 'high' ? 'selected' : '' ?>>Alta</option>
                <option value="urgent" <?= $prio === 'urgent' ? 'selected' : '' ?>>Urgente</option>
            </select>
        </div>

        <div>
            <label style="display:block;font-size:10px;font-weight:700;color:var(--text-ghost);text-transform:uppercase;letter-spacing:0.8px;margin-bottom:5px;">Fecha límite</label>
            <input id="drawer_fecha" type="date" value="<?= h($fecha_val) ?>" class="fyc-input" style="font-size:13px;">
        </div>

        <div>
            <label style="display:block;font-size:10px;font-weight:700;color:var(--text-ghost);text-transform:uppercase;letter-spacing:0.8px;margin-bottom:5px;">Responsable</label>
            <select id="drawer_assignee" class="fyc-select" style="font-size:13px;">
                <option value="" <?= $asig_id ? '' : 'selected' ?>>Sin responsable</option>
                <?php foreach ($members as $m): ?>
                        <option value="<?= (int) $m['id'] ?>" <?= ((int) $m['id'] === $asig_id) ? 'selected' : '' ?>>
                            <?= h($m['nombre']) ?>
                        </option>
                <?php endforeach; ?>
            </select>
        </div>
    </div>

    <!-- ETIQUETAS / TAGS -->
    <?php if ($hasTags): ?>
        <div style="background:var(--bg-surface);border:1px solid var(--border-main);border-radius:12px;padding:14px;">
            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:10px;">
                <span style="font-size:11px;font-weight:700;color:var(--text-faint);text-transform:uppercase;letter-spacing:0.8px;">Etiquetas</span>
                <button type="button" id="btnShowCreateTag"
                    style="font-size:11px;font-weight:600;color:var(--fyc-red);background:none;border:none;cursor:pointer;padding:0;">
                    + Nueva etiqueta
                </button>
            </div>

            <!-- Crear tag (oculto por defecto) -->
            <div id="createTagForm" style="display:none;margin-bottom:10px;padding:10px;border-radius:10px;background:var(--bg-hover);border:1px solid var(--border-dashed);">
                <label style="font-size:10px;font-weight:700;color:var(--text-ghost);text-transform:uppercase;letter-spacing:0.8px;">Nombre</label>
                <input type="text" id="newTagName" class="fyc-input" style="margin:5px 0 8px;font-size:12px;" placeholder="Ej. Bug, Feature, Urgente..." maxlength="60">
                <label style="font-size:10px;font-weight:700;color:var(--text-ghost);text-transform:uppercase;letter-spacing:0.8px;">Color</label>
                <div style="display:flex;gap:6px;flex-wrap:wrap;margin:5px 0 10px;">
                    <?php foreach ($tagColors as $i => $tc): ?>
                            <button type="button" class="tag-color-opt"
                                data-color="<?= h($tc) ?>"
                                style="width:22px;height:22px;border-radius:50%;background:<?= h($tc) ?>;border:2px solid transparent;cursor:pointer;transition:transform .1s;"
                                <?= $i === 0 ? 'data-selected="1"' : '' ?>></button>
                    <?php endforeach; ?>
                </div>
                <input type="hidden" id="newTagColor" value="<?= h($tagColors[0]) ?>">
                <div style="display:flex;gap:6px;justify-content:flex-end;">
                    <button type="button" id="btnCancelCreateTag" class="fyc-btn fyc-btn-ghost" style="font-size:11px;padding:4px 10px;">Cancelar</button>
                    <button type="button" id="btnConfirmCreateTag" class="fyc-btn fyc-btn-primary" style="font-size:11px;padding:4px 10px;">Crear</button>
                </div>
            </div>

            <!-- Lista de tags del tablero -->
            <div id="tagList" style="display:flex;flex-wrap:wrap;gap:6px;">
                <?php if (!$boardTags): ?>
                        <span style="font-size:12px;color:var(--text-ghost);">Este tablero no tiene etiquetas todavía.</span>
                <?php else: ?>
                        <?php foreach ($boardTags as $tag): ?>
                                <?php $isActive = in_array((int) $tag['id'], $taskTagIds, true); ?>
                                <button type="button"
                                    class="tag-toggle-btn"
                                    data-tag-id="<?= (int) $tag['id'] ?>"
                                    data-tag-name="<?= h($tag['nombre']) ?>"
                                    data-active="<?= $isActive ? '1' : '0' ?>"
                                    style="display:inline-flex;align-items:center;gap:5px;padding:3px 10px;border-radius:999px;font-size:11px;font-weight:700;cursor:pointer;transition:all .15s;
                               background:<?= $isActive ? h($tag['color_hex']) : 'var(--bg-hover)' ?>;
                               color:<?= $isActive ? '#fff' : 'var(--text-muted)' ?>;
                               border:1.5px solid <?= h($tag['color_hex']) ?>;">
                                    <span style="width:7px;height:7px;border-radius:50%;background:<?= h($tag['color_hex']) ?>;display:inline-block;<?= $isActive ? 'background:#fff;' : '' ?>"></span>
                                    <?= h($tag['nombre']) ?>
                                </button>
                        <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <!-- Script de tags (inline, carga junto con el drawer) -->
        <script>
        (function(){
            var taskId  = <?= (int) $task_id ?>;
            var boardId = <?= (int) $board_id ?>;
            var csrf    = <?= json_encode($_SESSION['csrf']) ?>;

            // Color picker
            document.querySelectorAll('.tag-color-opt').forEach(function(btn){
                btn.addEventListener('click', function(){
                    document.querySelectorAll('.tag-color-opt').forEach(function(b){
                        b.style.border = '2px solid transparent'; b.removeAttribute('data-selected');
                    });
                    btn.style.border = '2px solid var(--text-primary)';
                    btn.setAttribute('data-selected','1');
                    document.getElementById('newTagColor').value = btn.getAttribute('data-color');
                });
            });

            // Mostrar/ocultar form crear tag
            var btnShow = document.getElementById('btnShowCreateTag');
            var form    = document.getElementById('createTagForm');
            if(btnShow && form){
                btnShow.addEventListener('click', function(){ form.style.display = form.style.display==='none' ? 'block' : 'none'; });
            }

            var btnCancelTag = document.getElementById('btnCancelCreateTag');
            if(btnCancelTag) btnCancelTag.addEventListener('click', function(){ form.style.display='none'; });

            // Crear tag
            var btnCreate = document.getElementById('btnConfirmCreateTag');
            if(btnCreate){
                btnCreate.addEventListener('click', function(){
                    var nombre = (document.getElementById('newTagName').value||'').trim();
                    var color  = document.getElementById('newTagColor').value || '#9070e8';
                    if(!nombre){ document.getElementById('newTagName').focus(); return; }
                    fetch('../tags/tag_action.php', {
                        method:'POST',
                        headers:{'Content-Type':'application/json','X-Requested-With':'fetch'},
                        body: JSON.stringify({action:'create', board_id:boardId, nombre:nombre, color_hex:color, csrf:csrf})
                    })
                    .then(function(r){ return r.json(); })
                    .then(function(data){
                        if(!data.ok){ alert(data.error||'Error'); return; }
                        // Recargar drawer para mostrar el tag nuevo
                        var body = document.getElementById('taskDrawerBody');
                        if(body){
                            fetch('../tasks/drawer.php?id='+taskId,{headers:{'X-Requested-With':'fetch'}})
                            .then(function(r){return r.text();})
                            .then(function(html){ body.innerHTML=html; });
                        }
                    })
                    .catch(function(){ alert('Error de conexión'); });
                });
            }

            // Toggle tag en tarea
            document.querySelectorAll('.tag-toggle-btn').forEach(function(btn){
                btn.addEventListener('click', function(){
                    var tagId  = btn.getAttribute('data-tag-id');
                    var active = btn.getAttribute('data-active') === '1';
                    var action = active ? 'detach' : 'attach';
                    fetch('../tags/tag_action.php', {
                        method:'POST',
                        headers:{'Content-Type':'application/json','X-Requested-With':'fetch'},
                        body: JSON.stringify({action:action, task_id:taskId, tag_id:tagId, board_id:boardId, csrf:csrf})
                    })
                    .then(function(r){ return r.json(); })
                    .then(function(data){
                        if(!data.ok){ alert(data.error||'Error'); return; }
                        // Toggle visual inmediato
                        var color = btn.style.borderColor;
                        if(action === 'attach'){
                            btn.setAttribute('data-active','1');
                            btn.style.background = color;
                            btn.style.color = '#fff';
                            var dot = btn.querySelector('span');
                            if(dot) dot.style.background = '#fff';
                        } else {
                            btn.setAttribute('data-active','0');
                            btn.style.background = 'var(--bg-hover)';
                            btn.style.color = 'var(--text-muted)';
                            var dot = btn.querySelector('span');
                            if(dot) dot.style.background = color;
                        }
                    })
                    .catch(function(){ alert('Error de conexión'); });
                });
            });
        })();
        </script>
    <?php endif; ?>

    <!-- DESCRIPCIÓN -->
    <div style="background:var(--bg-surface);border:1px solid var(--border-main);border-radius:12px;padding:14px;">
        <label style="display:block;font-size:10px;font-weight:700;color:var(--text-ghost);text-transform:uppercase;letter-spacing:0.8px;margin-bottom:8px;">Descripción</label>
        <textarea id="drawer_desc" rows="5"
            placeholder="Escribe una descripción, notas o pasos a seguir…"
            class="fyc-textarea"
            style="resize:vertical;min-height:90px;font-size:13px;line-height:1.5;"><?= h($desc_val) ?></textarea>

        <div style="margin-top:12px;display:flex;align-items:center;justify-content:flex-end;gap:8px;">
            <button type="button" data-action="drawer-cancel" class="fyc-btn fyc-btn-ghost" style="font-size:12px;">
                Cancelar
            </button>
            <button type="button" data-action="drawer-save" class="fyc-btn fyc-btn-primary" style="font-size:12px;">
                Guardar cambios
            </button>
        </div>
    </div>

    <!-- COMENTARIOS -->
    <div style="background:var(--bg-surface);border:1px solid var(--border-main);border-radius:12px;padding:14px;">
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:12px;">
            <span style="font-size:11px;font-weight:700;color:var(--text-faint);text-transform:uppercase;letter-spacing:0.8px;">
                Comentarios <?= $hasComments ? '(' . count($comments) . ')' : '' ?>
            </span>
        </div>

        <div class="space-y-3" style="display:flex;flex-direction:column;gap:8px;">
            <?php if (!$hasComments): ?>
                    <div style="font-size:12px;color:var(--text-ghost);">No se detectó la tabla de comentarios.</div>
            <?php elseif (!$comments): ?>
                    <div style="display:flex;flex-direction:column;align-items:center;gap:6px;padding:16px 0 8px;text-align:center;">
                        <img src="../assets/ovi/ovi-saludo.svg" alt="" width="64" height="64" class="ovi-breathe" style="opacity:0.75;pointer-events:none;" draggable="false">
                        <span style="font-size:13px;font-weight:600;color:var(--text-faint);">Sin comentarios aún</span>
                        <span style="font-size:11px;color:var(--text-ghost);">Sé el primero en dejar un comentario.</span>
                    </div>
            <?php else: ?>
                    <?php foreach ($comments as $c): ?>
                            <?php $who = trim((string) ($c['user_nombre'] ?? 'Usuario'));
                            $when = !empty($c['created_at']) ? substr((string) $c['created_at'], 0, 16) : ''; ?>
                            <div style="border-radius:10px;border:1px solid var(--border-main);background:var(--bg-hover);padding:10px;">
                                <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:4px;">
                                    <span style="font-size:12px;font-weight:700;color:var(--text-primary);"><?= h($who) ?></span>
                                    <span style="font-size:10px;color:var(--text-ghost);"><?= h($when) ?></span>
                                </div>
                                <div style="font-size:13px;color:var(--text-muted);white-space:pre-wrap;word-break:break-word;"><?= h($c['body'] ?? '') ?></div>
                            </div>
                    <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <div style="margin-top:12px;padding-top:12px;border-top:1px solid var(--border-main);">
            <label style="display:block;font-size:10px;font-weight:700;color:var(--text-ghost);text-transform:uppercase;letter-spacing:0.8px;margin-bottom:6px;">Agregar comentario</label>
            <textarea id="drawer_comment" rows="3"
                placeholder="Escribe un comentario… (Ctrl+Enter para enviar)"
                class="fyc-textarea"
                style="resize:vertical;min-height:70px;font-size:13px;"></textarea>
            <div style="margin-top:8px;display:flex;justify-content:flex-end;">
                <button type="button" data-action="drawer-add-comment" class="fyc-btn fyc-btn-primary" style="font-size:12px;">
                    Publicar
                </button>
            </div>
        </div>
    </div>

</div>

<script>
// Ctrl+Enter para publicar comentario
(function(){
    var ta = document.getElementById('drawer_comment');
    if(!ta) return;
    ta.addEventListener('keydown', function(e){
        if(e.key === 'Enter' && (e.ctrlKey || e.metaKey)){
            e.preventDefault();
            var btn = document.querySelector('[data-action="drawer-add-comment"]');
            if(btn) btn.click();
        }
    });
})();
</script>