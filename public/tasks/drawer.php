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
// El nombre de la columna es el estado real de la tarea (Por hacer / En proceso
// / Hecho). Se trae con LEFT JOIN para que una tarea sin columna asignada siga
// mostrándose: el cajón nunca debe dejar de abrirse por un dato decorativo.
$sql = "SELECT t.id, t.board_id, t.column_id, t.titulo, t.prioridad, t.fecha_limite, t.assignee_id"
    . ($hasDescCol ? ", t.descripcion_md" : "")
    . ", b.nombre AS board_nombre, u.nombre AS asignado_nombre,
         c.nombre AS estado_nombre, c.is_done AS estado_done
       FROM tasks t
       JOIN boards b ON b.id = t.board_id
       LEFT JOIN users u ON u.id = t.assignee_id
       LEFT JOIN `columns` c ON c.id = t.column_id
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

// Estado (columna del tablero). Si la tarea no tiene columna, no se pinta nada.
$estado_nombre = trim((string) ($task['estado_nombre'] ?? ''));
$estado_done   = !empty($task['estado_done']);

// La descripción crece con su contenido: el alto mínimo son 3 líneas y se
// amplía hasta 14 según los saltos de línea guardados. Antes eran 5 fijas,
// que sobraban en las tareas vacías y se quedaban cortas en las largas.
$descLineas = $desc_val === '' ? 0 : substr_count($desc_val, "\n") + 1;
$descRows   = max(3, min(14, $descLineas));

// Colores predefinidos para crear tags
$tagColors = ['#e85070', '#e87050', '#d4a040', '#40a060', '#4090e8', '#9070e8', '#e070b0', '#50b0a0'];

// 6) Adjuntos (resiliencia de esquema: si la tabla no existe, la sección no se muestra)
require_once __DIR__ . '/../_attachments.php';
$hasAttachments = attach_table_exists($conn);
$attachments    = $hasAttachments ? attach_list_by_task($conn, $task_id) : [];

// ¿Puede subir y eliminar? El backend revalida siempre; esto solo decide qué se pinta.
$canWrite = can_write_board($conn, $board_id, $user_id);
?>

<div style="display:flex;flex-direction:column;gap:16px;">

    <!-- TÍTULO + META -->
    <?php
    // El distintivo del responsable que había aquí se ha retirado: repetía el
    // dato del selector «Responsable», que está tres centímetros más abajo.
    // En su lugar se muestra el estado, que antes no aparecía en ningún sitio
    // del cajón: había que cerrarlo para saber en qué columna estaba la tarea.
    ?>
    <div>
        <div class="fyc-drawer-meta">
            <span class="fyc-drawer-board"><?= h($task['board_nombre'] ?? '—') ?></span>
            <span class="fyc-drawer-chip">#<?= (int) $task_id ?></span>
            <?php if ($estado_nombre !== ''): ?>
                <span class="fyc-drawer-chip<?= $estado_done ? ' fyc-drawer-chip-done' : '' ?>"
                    data-drawer-estado><?= h($estado_nombre) ?></span>
            <?php endif; ?>
        </div>
        <h2 class="fyc-drawer-title"><?= h($task['titulo'] ?? 'Tarea') ?></h2>
    </div>

    <!-- CAMPOS RÁPIDOS + ETIQUETAS -->
    <?php
    // Antes eran dos tarjetas separadas, cada una con su fondo, su borde y sus
    // 14px de relleno. Ahora comparten panel y las separa un filete: se ahorra
    // un contorno entero, un hueco de 16px y dos rellenos, sin perder jerarquía.
    ?>
    <div class="fyc-drawer-panel">
        <input type="hidden" id="drawer_task_id"  value="<?= (int) $task_id ?>">
        <input type="hidden" id="drawer_board_id" value="<?= (int) $board_id ?>">
        <input type="hidden" id="drawer_csrf"     value="<?= h($_SESSION['csrf']) ?>">

        <div class="fyc-drawer-fields" data-fields-grid>
            <div>
                <label class="fyc-drawer-label" for="drawer_prioridad">Prioridad</label>
                <select id="drawer_prioridad" class="fyc-select">
                    <option value="low"    <?= $prio === 'low' ? 'selected' : '' ?>>Baja</option>
                    <option value="med"    <?= $prio === 'med' ? 'selected' : '' ?>>Media</option>
                    <option value="high"   <?= $prio === 'high' ? 'selected' : '' ?>>Alta</option>
                    <option value="urgent" <?= $prio === 'urgent' ? 'selected' : '' ?>>Urgente</option>
                </select>
            </div>

            <div>
                <label class="fyc-drawer-label" for="drawer_fecha">Fecha límite</label>
                <input id="drawer_fecha" type="date" value="<?= h($fecha_val) ?>" class="fyc-input">
            </div>

            <div>
                <label class="fyc-drawer-label" for="drawer_assignee">Responsable</label>
                <select id="drawer_assignee" class="fyc-select">
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
            <div class="fyc-drawer-sep"></div>

            <div class="fyc-drawer-tagrow">
                <span class="fyc-drawer-label fyc-drawer-label-inline">Etiquetas</span>

                <!-- Lista de tags del tablero -->
                <div id="tagList" class="fyc-drawer-taglist">
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

                <button type="button" id="btnShowCreateTag" class="fyc-drawer-newtag">
                    + Nueva etiqueta
                </button>
            </div>

            <!-- Crear tag (oculto por defecto) -->
            <div id="createTagForm" style="display:none;margin-top:10px;padding:10px;border-radius:10px;background:var(--bg-surface);border:1px solid var(--border-dashed);">
                <label class="fyc-drawer-label" for="newTagName">Nombre</label>
                <input type="text" id="newTagName" class="fyc-input" style="margin-bottom:8px;font-size:12px;" placeholder="Ej. Bug, Feature, Urgente..." maxlength="60">
                <label class="fyc-drawer-label">Color</label>
                <div style="display:flex;gap:6px;flex-wrap:wrap;margin-bottom:10px;">
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
        <?php endif; ?>
    </div>

    <?php if ($hasTags): ?>
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
                        // Recargar el cajón para mostrar la etiqueta nueva.
                        // Se usa la misma puerta que lo cargó: hacer aquí un
                        // fetch propio con innerHTML volvía a dejar sin
                        // ejecutar este mismo script, y las etiquetas dejaban
                        // de responder justo después de crear una.
                        if(window.FCPlannerBoard && typeof window.FCPlannerBoard.loadDrawer === 'function'){
                            window.FCPlannerBoard.loadDrawer(taskId);
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
    <?php
    // Sin tarjeta: el rótulo ya marca dónde empieza el bloque, así que el
    // borde, el fondo y los 14px de relleno solo añadían altura. El alto fijo
    // de 5 líneas pasa a un mínimo de 3 que crece con el contenido.
    ?>
    <div>
        <label class="fyc-drawer-label" for="drawer_desc">Descripción</label>
        <textarea id="drawer_desc" rows="<?= (int) $descRows ?>"
            placeholder="Escribe una descripción, notas o pasos a seguir…"
            class="fyc-textarea fyc-drawer-desc"><?= h($desc_val) ?></textarea>

        <div class="fyc-drawer-actions">
            <button type="button" data-action="drawer-cancel" class="fyc-btn fyc-btn-ghost" style="font-size:12px;">
                Cancelar
            </button>
            <button type="button" data-action="drawer-save" class="fyc-btn fyc-btn-primary" style="font-size:12px;">
                Guardar cambios
            </button>
        </div>
    </div>

    <!-- ADJUNTOS -->
    <?php if ($hasAttachments): ?>
        <?php
        // Las cifras y las extensiones se derivan de las constantes y de la
        // lista blanca: si el contrato de tamaño cambia, este texto se
        // actualiza solo. Antes estaban escritas a mano y acabaron prometiendo
        // 50 MB cuando el servidor solo admite 14.
        $maxArchivoMb = (int) round(ATTACH_MAX_FILE_BYTES / 1048576);
        $maxTotalMb   = (int) round(ATTACH_MAX_REQUEST_BYTES / 1048576);

        $porTipo = [];
        foreach (attach_whitelist() as $ext => $def) {
            $porTipo[$def[0]][] = strtoupper($ext);
        }
        ?>
        <div class="fyc-attach-section" data-attachments-section>
            <div class="fyc-attach-head">
                <span class="fyc-attach-title">
                    Adjuntos (<?= count($attachments) ?>)
                </span>
                <?php if ($canWrite): ?>
                    <button type="button" data-action="attach-pick"
                        class="fyc-btn fyc-btn-ghost fyc-attach-action">
                        + Añadir archivos
                    </button>
                <?php endif; ?>
            </div>

            <?php if ($canWrite): ?>
                <input type="file" id="drawer_attach_input"
                    accept="<?= h(attach_accept_attribute()) ?>"
                    multiple
                    style="position:absolute;width:1px;height:1px;opacity:0;pointer-events:none;">

                <?php
                // Los errores viven FUERA del desplegable, y por encima de él:
                // un aviso que hay que abrir para verlo no es un aviso.
                ?>
                <div id="drawer_attach_status" role="status" aria-live="polite"
                    style="display:none;font-size:12px;border-radius:8px;padding:8px 10px;margin-bottom:10px;word-break:break-word;"></div>

                <?php
                // Ayuda bajo demanda con <details>: cerrada por defecto, se abre
                // con teclado sin una línea de JavaScript. Antes estos mismos
                // datos ocupaban un bloque permanente de ~100px que la mayoría
                // de las veces nadie necesitaba leer.
                ?>
                <details class="fyc-attach-help">
                    <summary>Formatos y límites</summary>
                    <div class="fyc-attach-help-body">
                        <p><strong>Imágenes</strong> <?= h(implode(', ', $porTipo['image'] ?? [])) ?></p>
                        <p><strong>Audio</strong> <?= h(implode(', ', $porTipo['audio'] ?? [])) ?></p>
                        <p><strong>Video</strong> <?= h(implode(', ', $porTipo['video'] ?? [])) ?></p>
                        <p>
                            Hasta <?= (int) ATTACH_MAX_FILES ?> archivos por vez ·
                            máx. <?= $maxArchivoMb ?>&nbsp;MB cada uno
                            y <?= $maxTotalMb ?>&nbsp;MB entre todos.
                        </p>
                        <p>
                            Si un archivo no cumple, no se guarda ninguno:
                            el envío se rechaza completo.
                        </p>
                        <p>
                            <strong>¿Algo más grande?</strong> Comparte los videos con
                            YouTube o Vimeo, y el resto como enlace externo.
                        </p>
                    </div>
                </details>

                <?php
                // La zona de arrastre hace también de estado vacío cuando no hay
                // nada: eran dos bloques diciendo lo mismo con distintas palabras.
                ?>
                <div class="fyc-attach-hint">
                    <?php if (!$attachments): ?>Todavía no hay adjuntos.<?php endif; ?>
                    Arrastra archivos aquí o pega una captura con
                    <kbd class="fyc-kbd">Ctrl</kbd>&nbsp;+&nbsp;<kbd class="fyc-kbd">V</kbd>
                </div>

                <div class="fyc-attach-dropmsg">Suelta aquí para adjuntar</div>

                <div class="fyc-attach-linkbar">
                    <input type="url" id="drawer_attach_url" class="fyc-input"
                        placeholder="Pega una URL, YouTube o Vimeo"
                        maxlength="2048" autocomplete="off" spellcheck="false"
                        style="font-size:12px;">
                    <button type="button" data-action="attach-add-link"
                        class="fyc-btn fyc-btn-ghost fyc-attach-action">
                        Añadir enlace
                    </button>
                </div>
            <?php endif; ?>

            <?php if (!$attachments): ?>
                <?php if (!$canWrite): ?>
                    <p class="fyc-attach-empty">Esta tarea no tiene archivos adjuntos.</p>
                <?php endif; ?>
            <?php else: ?>
                <div class="fyc-attach-grid">
                    <?php foreach ($attachments as $a): ?>
                        <?php
                        $nombre   = (string) $a['original_name'];
                        $esExtern = ($a['kind'] === 'link' || $a['kind'] === 'embed');
                        // El tipo ya lo dice el distintivo sobre la miniatura:
                        // repetirlo aquí gastaba una línea por tarjeta sin
                        // aportar nada. Queda tamaño y fecha.
                        $meta = ($esExtern ? '' : $a['size_human'] . ' · ')
                            . substr((string) $a['created_at'], 0, 16);
                        // En un enlace, el nombre ya es el dominio o el título;
                        // la dirección completa vive en el title, accesible sin
                        // gastar una tercera línea.
                        $titulo = $esExtern
                            ? (string) ($a['external_url'] ?? $nombre)
                            : $nombre;
                        ?>
                        <div class="fyc-attach-card fyc-attach-k-<?= h($a['kind']) ?>"
                             data-attachment-id="<?= (int) $a['id'] ?>"
                             data-kind="<?= h($a['kind']) ?>">

                            <span class="fyc-attach-badge"><?= h(attach_kind_label($a['kind'])) ?></span>

                            <div class="fyc-attach-media">
                                <?php if ($a['kind'] === 'embed' && !empty($a['embed_url'])): ?>
                                    <?php
                                    // El src SIEMPRE viene de attach_build_embed_url(), construido
                                    // desde plantilla propia con el video_id ya validado.
                                    // external_url NUNCA se usa aquí.
                                    $prov = $a['provider'] === 'youtube' ? 'YouTube' : 'Vimeo';
                                    ?>
                                    <div class="fyc-attach-embed">
                                        <iframe src="<?= h($a['embed_url']) ?>"
                                            title="<?= h('Video de ' . $prov . ': ' . $nombre) ?>"
                                            loading="lazy"
                                            referrerpolicy="strict-origin-when-cross-origin"
                                            allow="accelerometer; clipboard-write; encrypted-media; gyroscope; picture-in-picture"
                                            allowfullscreen></iframe>
                                    </div>
                                    <?php if (!empty($a['watch_url'])): ?>
                                        <div class="fyc-attach-embedfail">
                                            Si el video no se muestra,
                                            <a href="<?= h((string) $a['watch_url']) ?>"
                                               target="_blank" rel="noopener noreferrer nofollow">
                                                verlo en <?= h($prov) ?>
                                            </a>.
                                        </div>
                                    <?php endif; ?>
                                <?php elseif ($a['kind'] === 'link' || $a['kind'] === 'embed'): ?>
                                    <div class="fyc-attach-linkicon" aria-hidden="true">
                                        <svg width="26" height="26" viewBox="0 0 24 24" fill="none"
                                             stroke="currentColor" stroke-width="1.8"
                                             stroke-linecap="round" stroke-linejoin="round">
                                            <path d="M10 13a5 5 0 0 0 7.5.5l3-3a5 5 0 0 0-7-7l-1.7 1.7"/>
                                            <path d="M14 11a5 5 0 0 0-7.5-.5l-3 3a5 5 0 0 0 7 7l1.7-1.7"/>
                                        </svg>
                                    </div>
                                <?php elseif ($a['kind'] === 'image'): ?>
                                    <button type="button" class="fyc-attach-imgbtn"
                                        data-action="attach-open"
                                        data-attachment-id="<?= (int) $a['id'] ?>"
                                        aria-label="<?= h('Ampliar imagen ' . $nombre) ?>">
                                        <img src="<?= h($a['url']) ?>" alt="<?= h($nombre) ?>" loading="lazy"
                                             data-action="attach-img" class="fyc-attach-thumb">
                                        <span class="fyc-attach-imgfail" aria-hidden="true">
                                            No se pudo cargar la imagen
                                        </span>
                                    </button>
                                <?php elseif ($a['kind'] === 'audio'): ?>
                                    <div class="fyc-attach-audio">
                                        <audio controls preload="metadata" data-action="attach-media"
                                               aria-label="<?= h('Audio ' . $nombre) ?>" style="width:100%;">
                                            <source src="<?= h($a['url']) ?>" type="<?= h($a['mime']) ?>">
                                            Tu navegador no puede reproducir este audio.
                                        </audio>
                                        <div class="fyc-attach-mediafail" role="status">
                                            Este navegador no puede reproducir este audio. Puedes descargarlo.
                                        </div>
                                    </div>
                                <?php else: ?>
                                    <div class="fyc-attach-video">
                                        <video controls preload="metadata" playsinline data-action="attach-media"
                                               aria-label="<?= h('Video ' . $nombre) ?>">
                                            <source src="<?= h($a['url']) ?>" type="<?= h($a['mime']) ?>">
                                            Tu navegador no puede reproducir este video.
                                        </video>
                                        <div class="fyc-attach-mediafail" role="status">
                                            Este navegador no puede reproducir este video. Puedes descargarlo.
                                        </div>
                                    </div>
                                <?php endif; ?>
                            </div>

                            <?php
                            // Metadatos y acciones comparten fila: antes las
                            // acciones gastaban una línea entera por tarjeta.
                            ?>
                            <div class="fyc-attach-info">
                                <div class="fyc-attach-name" title="<?= h($titulo) ?>">
                                    <?= h($nombre) ?>
                                </div>

                                <div class="fyc-attach-row">
                                    <span class="fyc-attach-meta"><?= h($meta) ?></span>

                                    <span class="fyc-attach-acts">
                                        <?php if ($esExtern): ?>
                                            <?php if (!empty($a['external_url'])): ?>
                                                <a href="<?= h((string) $a['external_url']) ?>"
                                                   target="_blank" rel="noopener noreferrer nofollow"
                                                   class="fyc-attach-act">
                                                    Abrir enlace
                                                </a>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <a href="<?= h((string) $a['download_url']) ?>" download
                                               class="fyc-attach-act">
                                                Descargar
                                            </a>
                                        <?php endif; ?>
                                        <?php if ($canWrite): ?>
                                            <button type="button" data-action="attach-delete"
                                                data-attachment-id="<?= (int) $a['id'] ?>"
                                                data-attachment-name="<?= h($nombre) ?>"
                                                class="fyc-attach-act fyc-attach-act-del">
                                                Eliminar
                                            </button>
                                        <?php endif; ?>
                                    </span>
                                </div>

                                <?php if ($a['kind'] === 'video' && $a['mime'] === 'video/quicktime'): ?>
                                    <div class="fyc-attach-note">
                                        Si el video no se reproduce, tu navegador no admite este formato.
                                        Usa el enlace de descarga.
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <!-- VISOR DE IMAGEN (lightbox propio, sin librerías) -->
            <div id="fycImgViewer" class="fyc-imgviewer" role="dialog" aria-modal="true"
                 aria-labelledby="fycImgViewerTitle" hidden>
                <div class="fyc-imgviewer-backdrop" data-action="viewer-close"></div>
                <div class="fyc-imgviewer-box">
                    <div class="fyc-imgviewer-head">
                        <span id="fycImgViewerTitle" class="fyc-imgviewer-title"></span>
                        <button type="button" class="fyc-imgviewer-x"
                                data-action="viewer-close" aria-label="Cerrar visor">✕</button>
                    </div>
                    <div class="fyc-imgviewer-body">
                        <img id="fycImgViewerImg" src="" alt="">
                    </div>
                    <div class="fyc-imgviewer-foot">
                        <a id="fycImgViewerDl" href="#" download
                           class="fyc-imgviewer-dl">Descargar</a>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <!-- COMENTARIOS -->
    <?php
    // El rótulo «Agregar comentario» desapareció: el marcador de posición del
    // campo ya dice qué escribir y cómo enviarlo. Y el estado vacío pasa de un
    // bloque con ilustración de 121px a una frase, igual que se hizo con los
    // adjuntos en F8.3.
    ?>
    <div class="fyc-comments">
        <div class="fyc-comments-head">
            <span class="fyc-comments-title">
                Comentarios <?= $hasComments ? '(' . count($comments) . ')' : '' ?>
            </span>
        </div>

        <div class="space-y-3 fyc-comments-list">
            <?php if (!$hasComments): ?>
                    <p class="fyc-comments-empty">No se detectó la tabla de comentarios.</p>
            <?php elseif (!$comments): ?>
                    <p class="fyc-comments-empty">Sin comentarios aún. Escribe el primero.</p>
            <?php else: ?>
                    <?php foreach ($comments as $c): ?>
                            <?php $who = trim((string) ($c['user_nombre'] ?? 'Usuario'));
                            $when = !empty($c['created_at']) ? substr((string) $c['created_at'], 0, 16) : ''; ?>
                            <div class="fyc-comment">
                                <div class="fyc-comment-meta">
                                    <span class="fyc-comment-who"><?= h($who) ?></span>
                                    <span class="fyc-comment-when"><?= h($when) ?></span>
                                </div>
                                <div class="fyc-comment-body"><?= h($c['body'] ?? '') ?></div>
                            </div>
                    <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <div class="fyc-comment-form">
            <textarea id="drawer_comment" rows="2"
                placeholder="Escribe un comentario… (Ctrl+Enter para enviar)"
                aria-label="Escribe un comentario"
                class="fyc-textarea fyc-comment-input"></textarea>
            <div class="fyc-comment-actions">
                <button type="button" data-action="drawer-add-comment"
                    class="fyc-btn fyc-btn-primary fyc-comment-send">
                    Publicar
                </button>
            </div>
        </div>
    </div>

</div>

<script>
// Ctrl+Enter para publicar comentario, y crecimiento del campo con el texto.
//
// Todos los enlaces son al propio <textarea>, que el cajón destruye y recrea
// en cada carga. Por eso volver a ejecutar este script no acumula manejadores
// —la condición de la que depende el arreglo de F8.2.1—.
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

    // Arranca en dos líneas y crece hasta un tope, para que un comentario
    // largo no obligue a escribir por una rendija ni empuje la página entera.
    var MAX = 220;
    function ajustar(){
        ta.style.height = 'auto';
        ta.style.height = Math.min(ta.scrollHeight, MAX) + 'px';
        ta.style.overflowY = ta.scrollHeight > MAX ? 'auto' : 'hidden';
    }
    ta.addEventListener('input', ajustar);
    // Tras publicar, el campo se vacía desde el JS del tablero: hay que
    // devolverlo a su altura compacta o se quedaría estirado.
    ta.addEventListener('fyc-reset', ajustar);
})();
</script>