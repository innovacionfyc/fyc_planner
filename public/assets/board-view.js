// public/assets/board-view.js
(function () {
  'use strict';

  var state = {
    root: null,
    boardId: null,
    csrf: null,
    listenersInstalled: false,
    drawer: { open: false, taskId: null }
  };

  // ---- FILTER STATE (module-scoped, survives board reloads) ----
  var filterState = {
    activePrios:    {},
    activeTagIds:   {},
    activeAssignee: '',
    searchText:     ''
  };

  function qs(root, sel) { return (root || document).querySelector(sel); }

  function syncFromDOM(root) {
    var kanban = qs(root, '#kanban');
    if (!kanban) return;
    state.boardId = kanban.getAttribute('data-board-id');
    state.csrf    = kanban.getAttribute('data-csrf');
  }

  function showToast(msg) {
    var t = document.getElementById('toast');
    if (!t) return;
    var box = t.querySelector('div');
    if (box) box.textContent = msg || '✅ Listo';
    t.classList.remove('hidden');
    setTimeout(function () { t.classList.add('hidden'); }, 2600);
  }

  function drawerEls() {
    return {
      overlay: document.getElementById('taskDrawerOverlay'),
      drawer:  document.getElementById('taskDrawer'),
      body:    document.getElementById('taskDrawerBody')
    };
  }

  function drawerExists() {
    var d = drawerEls();
    return !!(d.overlay && d.drawer && d.body);
  }

  function openDrawerShell() {
    var d = drawerEls();
    if (!d.overlay || !d.drawer) return;
    d.overlay.classList.remove('hidden');
    d.drawer.classList.remove('translate-x-full');
    state.drawer.open = true;
  }

  function closeDrawer() {
    var d = drawerEls();
    if (!d.overlay || !d.drawer) return;
    d.drawer.classList.add('translate-x-full');
    setTimeout(function () { d.overlay.classList.add('hidden'); }, 220);
    state.drawer.open   = false;
    state.drawer.taskId = null;
  }

  function setDrawerLoading() {
    var d = drawerEls();
    if (!d.body) return;
    d.body.innerHTML = '<div style="font-size:13px;color:var(--text-ghost);">Cargando…</div>';
  }

  function setDrawerError(msg) {
    var d = drawerEls();
    if (!d.body) return;
    d.body.innerHTML = '<div style="font-size:13px;color:var(--badge-overdue-tx);padding:12px;border-radius:10px;border:1px solid var(--border-accent);background:var(--badge-overdue-bg);">' + (msg || 'No se pudo cargar el detalle.') + '</div>';
  }

  function loadDrawer(taskId) {
    if (!drawerExists() || !taskId) return;
    state.drawer.taskId = taskId;
    openDrawerShell();
    setDrawerLoading();
    fetch('../tasks/drawer.php?id=' + encodeURIComponent(taskId), { headers: { 'X-Requested-With': 'fetch' } })
      .then(function (r) { if (!r.ok) throw new Error('HTTP ' + r.status); return r.text(); })
      .then(function (html) { var d = drawerEls(); if (d.body) d.body.innerHTML = html; })
      .catch(function (e) { console.error('[FCPlannerBoard] drawer load error', e); setDrawerError('No se pudo cargar el detalle.'); });
  }

  // ============================================================
  // FILTROS — estado y lógica (independiente del DOM inicial)
  // ============================================================

  function hasActiveFilter() {
    return filterState.searchText !== ''
      || Object.keys(filterState.activePrios).length  > 0
      || Object.keys(filterState.activeTagIds).length > 0
      || filterState.activeAssignee !== '';
  }

  function applyFilters() {
    var tasks   = document.querySelectorAll('.task.fyc-card');
    var visible = 0;

    tasks.forEach(function (card) {
      var show = true;

      // Búsqueda por texto
      if (filterState.searchText) {
        var titulo = (card.getAttribute('data-titulo') || '').toLowerCase();
        if (titulo.indexOf(filterState.searchText) === -1) show = false;
      }

      // Prioridad (OR entre seleccionadas)
      if (show && Object.keys(filterState.activePrios).length > 0) {
        var prio = card.getAttribute('data-prioridad') || '';
        if (!filterState.activePrios[prio]) show = false;
      }

      // Responsable
      if (show && filterState.activeAssignee !== '') {
        var assignee = card.getAttribute('data-assignee') || '';
        if (assignee !== filterState.activeAssignee) show = false;
      }

      // Tags (OR entre seleccionados)
      if (show && Object.keys(filterState.activeTagIds).length > 0) {
        var rawTags  = card.getAttribute('data-tags') || '[]';
        var cardTags = [];
        try { cardTags = JSON.parse(rawTags); } catch (e) {}
        var matchTag = false;
        cardTags.forEach(function (tid) { if (filterState.activeTagIds[String(tid)]) matchTag = true; });
        if (!matchTag) show = false;
      }

      card.style.display = show ? '' : 'none';
      if (show) visible++;
    });

    // Contador y mensaje vacío por columna
    document.querySelectorAll('.col.fyc-col').forEach(function (col) {
      var visibleInCol = 0;
      col.querySelectorAll('.task.fyc-card').forEach(function (c) { if (c.style.display !== 'none') visibleInCol++; });
      var empty = col.querySelector('.empty');
      if (empty) empty.style.display = visibleInCol === 0 ? '' : 'none';
      var cnt = col.querySelector('.cnt');
      if (cnt) {
        var total = col.querySelectorAll('.task.fyc-card').length;
        cnt.textContent = hasActiveFilter() ? visibleInCol + '/' + total : total;
      }
    });

    // Botón limpiar + contador
    var btnClear = document.getElementById('btnClearFilters');
    var fCount   = document.getElementById('filterCount');
    if (btnClear) btnClear.style.display = hasActiveFilter() ? 'inline-flex' : 'none';
    if (fCount) {
      if (hasActiveFilter()) {
        fCount.style.display = 'inline';
        fCount.textContent   = visible + ' resultado' + (visible !== 1 ? 's' : '');
      } else {
        fCount.style.display = 'none';
      }
    }
  }

  // Aplica la clase de color (data-cls) a los botones de prioridad
  function initPrioBtnClasses() {
    document.querySelectorAll('.filter-prio-btn').forEach(function (btn) {
      var cls = btn.getAttribute('data-cls');
      if (cls && !btn.classList.contains(cls)) btn.classList.add(cls);
    });
  }

  // Restaura estados visuales de botones de filtro tras un reload del board
  function restoreFilterUI() {
    document.querySelectorAll('.filter-prio-btn').forEach(function (btn) {
      var prio = btn.getAttribute('data-prio');
      if (filterState.activePrios[prio]) {
        btn.style.opacity     = '1';
        btn.style.borderColor = 'var(--text-primary)';
      } else {
        btn.style.opacity     = '.5';
        btn.style.borderColor = 'transparent';
      }
    });

    document.querySelectorAll('.filter-tag-btn').forEach(function (btn) {
      var tid = btn.getAttribute('data-tag-id');
      if (filterState.activeTagIds[tid]) {
        btn.style.opacity    = '1';
        btn.style.background = btn.style.borderColor; // color del tag, definido en el inline style
        btn.style.color      = '#fff';
      } else {
        btn.style.opacity    = '.55';
        btn.style.background = 'var(--bg-hover)';
        btn.style.color      = 'var(--text-muted)';
      }
    });

    var searchInp = document.getElementById('filterSearch');
    if (searchInp) searchInp.value = filterState.searchText;

    var selAss = document.getElementById('filterAssignee');
    if (selAss) selAss.value = filterState.activeAssignee;
  }

  // Re-ejecuta los <script> del HTML inyectado vía innerHTML.
  // Los navegadores no ejecutan scripts insertados con innerHTML por seguridad,
  // así que hay que clonarlos como elementos nuevos para que el motor JS los corra.
  // Solo procesa scripts JS (excluye type="application/json" y similares).
  function runEmbedScripts(container) {
    container.querySelectorAll('script:not([type]),script[type="text/javascript"]').forEach(function (s) {
      var n = document.createElement('script');
      n.textContent = s.textContent;
      document.head.appendChild(n);
      document.head.removeChild(n);
    });
  }

  function reloadBoard(opts) {
    if (!state.root || !state.boardId) return;
    var reloadDrawer = true;
    if (opts && typeof opts.reloadDrawer === 'boolean') reloadDrawer = opts.reloadDrawer;
    fetch('./view.php?id=' + encodeURIComponent(state.boardId) + '&embed=1', { headers: { 'X-Requested-With': 'fetch' } })
      .then(function (r) { return r.text(); })
      .then(function (html) {
        state.root.innerHTML = html;
        syncFromDOM(state.root);
        initPrioBtnClasses();
        restoreFilterUI();
        applyFilters();
        if (reloadDrawer && state.drawer.open && state.drawer.taskId) loadDrawer(state.drawer.taskId);
        runEmbedScripts(state.root);
        // Notificar al shell del workspace para re-sincronizar el botón de miembros.
        document.dispatchEvent(new CustomEvent('fcplanner:board-reloaded'));
      })
      .catch(function () { console.warn('[FCPlannerBoard] No se pudo recargar el tablero'); });
  }

  // ============================================================
  // HELPERS MODAL
  // ============================================================
  function openModal(id) {
    var m = document.getElementById(id);
    if (!m) return;
    m.classList.remove('hidden');
    m.style.display = 'flex';
  }

  function closeModal(id) {
    var m = document.getElementById(id);
    if (!m) return;
    m.classList.add('hidden');
    m.style.display = 'none';
  }

  function closeAllColumnModals() {
    closeModal('modalAddColumn');
    closeModal('modalRenameColumn');
    closeModal('modalDeleteColumn');
    var menu = document.getElementById('colContextMenu');
    if (menu) menu.style.display = 'none';
  }

  // ============================================================
  // COLUMN ACTIONS — llamada al backend
  // ============================================================
  function columnAction(payload, onSuccess) {
    if (!state.boardId || !state.csrf) return;
    payload.board_id = state.boardId;
    payload.csrf     = state.csrf;

    fetch('../columns/column_action.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'fetch' },
      body: JSON.stringify(payload)
    })
      .then(function (r) { return r.json().catch(function () { return null; }); })
      .then(function (data) {
        if (!data || !data.ok) {
          showToast('⚠️ ' + (data && data.error ? data.error : 'Error'));
          return;
        }
        if (onSuccess) onSuccess(data);
        reloadBoard({ reloadDrawer: false });
      })
      .catch(function (e) {
        console.error('[FCPlannerBoard] columnAction error', e);
        showToast('⚠️ Error de conexión');
      });
  }

  function installListenersOnce(root) {
    if (state.listenersInstalled) return;
    state.listenersInstalled = true;

    // ---- Drawer cerrar ----
    document.addEventListener('click', function (ev) {
      if (ev.target && ev.target.closest && ev.target.closest('[data-drawer-close]')) { closeDrawer(); return; }
      if (ev.target && ev.target.id === 'taskDrawerOverlay') { closeDrawer(); return; }
    });
    document.addEventListener('keydown', function (ev) {
      if (ev.key !== 'Escape') return;
      if (state.drawer.open && drawerExists()) { closeDrawer(); return; }
      closeAllColumnModals();
    });

    // ---- Abrir drawer ----
    root.addEventListener('click', function (ev) {
      var btn = ev.target.closest && ev.target.closest('[data-action="open-task"]');
      if (!btn) return;
      ev.preventDefault(); ev.stopPropagation();
      var taskId = btn.getAttribute('data-task-id');
      if (!taskId) return;
      if (!drawerExists()) { console.warn('[FCPlannerBoard] Drawer no existe.'); return; }
      loadDrawer(taskId);
    });

    // ---- Drawer save ----
    root.addEventListener('click', function (ev) {
      var btnSave = ev.target.closest && ev.target.closest('[data-action="drawer-save"]');
      if (!btnSave) return;
      ev.preventDefault(); ev.stopPropagation();
      var taskIdEl  = document.getElementById('drawer_task_id');
      var boardIdEl = document.getElementById('drawer_board_id');
      var csrfEl    = document.getElementById('drawer_csrf');
      var selPrio   = document.getElementById('drawer_prioridad');
      var inpFecha  = document.getElementById('drawer_fecha');
      var selAss    = document.getElementById('drawer_assignee');
      var taDesc    = document.getElementById('drawer_desc');
      var taskId  = taskIdEl  ? String(taskIdEl.value  || '') : '';
      var boardId = boardIdEl ? String(boardIdEl.value || '') : '';
      var csrf    = csrfEl    ? String(csrfEl.value    || '') : '';
      if (!taskId || !boardId || !csrf) return;
      var fd = new FormData();
      fd.set('csrf', csrf); fd.set('task_id', taskId); fd.set('board_id', boardId);
      fd.set('prioridad', selPrio ? selPrio.value : 'med');
      fd.set('fecha_limite', inpFecha ? inpFecha.value : '');
      fd.set('assignee_id', selAss ? selAss.value : '');
      fd.set('descripcion_md', taDesc ? String(taDesc.value || '') : '');
      fetch('../tasks/update.php', { method: 'POST', body: fd, headers: { 'X-Requested-With': 'fetch', 'Accept': 'application/json' } })
        .then(function (r) { return r.json().catch(function () { return null; }); })
        .then(function (data) {
          if (!data || data.ok !== true) { showToast('⚠️ No se pudo guardar'); return; }
          showToast('✅ Guardado'); reloadBoard({ reloadDrawer: false });
        })
        .catch(function () { showToast('⚠️ Error guardando'); });
    });

    root.addEventListener('click', function (ev) {
      var btn = ev.target.closest && ev.target.closest('[data-action="drawer-cancel"]');
      if (!btn) return;
      ev.preventDefault(); ev.stopPropagation(); closeDrawer();
    });

    // ---- Drawer comentario ----
    root.addEventListener('click', function (ev) {
      var btn = ev.target.closest && ev.target.closest('[data-action="drawer-add-comment"]');
      if (!btn) return;
      ev.preventDefault(); ev.stopPropagation();
      var taskIdEl  = document.getElementById('drawer_task_id');
      var boardIdEl = document.getElementById('drawer_board_id');
      var csrfEl    = document.getElementById('drawer_csrf');
      var ta        = document.getElementById('drawer_comment');
      var taskId  = taskIdEl  ? String(taskIdEl.value  || '') : '';
      var boardId = boardIdEl ? String(boardIdEl.value || '') : '';
      var csrf    = csrfEl    ? String(csrfEl.value    || '') : '';
      var body    = ta        ? String(ta.value || '').trim() : '';
      if (!taskId || !boardId || !csrf) return;
      if (!body) { showToast('✍️ Escribe un comentario'); if (ta) ta.focus(); return; }
      var fd = new FormData();
      fd.set('csrf', csrf); fd.set('task_id', taskId); fd.set('board_id', boardId); fd.set('body', body);
      fetch('../tasks/comment_create.php', { method: 'POST', body: fd, headers: { 'X-Requested-With': 'fetch', 'Accept': 'application/json' } })
        .then(function (r) { return r.json().catch(function () { return null; }); })
        .then(function (data) {
          if (!data || data.ok !== true) { showToast('⚠️ No se pudo publicar'); return; }
          var wrapper = document.querySelector('#taskDrawerBody .space-y-3');
          if (wrapper) {
            var now = new Date();
            var fecha = now.getFullYear() + '-' + String(now.getMonth()+1).padStart(2,'0') + '-' + String(now.getDate()).padStart(2,'0') + ' ' + String(now.getHours()).padStart(2,'0') + ':' + String(now.getMinutes()).padStart(2,'0');
            var div = document.createElement('div');
            div.style.cssText = 'border-radius:10px;border:1px solid var(--border-main);background:var(--bg-hover);padding:10px;';
            div.innerHTML = '<div style="display:flex;justify-content:space-between;margin-bottom:4px;"><span style="font-size:12px;font-weight:700;color:var(--text-primary);">' + (window.FCPlannerCurrentUserName||'Tú') + '</span><span style="font-size:11px;color:var(--text-ghost);">' + fecha + '</span></div><div style="font-size:13px;color:var(--text-muted);white-space:pre-wrap;word-break:break-word;"></div>';
            div.querySelector('div:last-child').textContent = body;
            wrapper.appendChild(div);
          }
          if (ta) ta.value = '';
          showToast('💬 Comentario publicado');
        })
        .catch(function () { showToast('⚠️ Error publicando'); });
    });

    // ---- Drag & Drop ----
    var draggingTaskId = null;
    var placeholder = document.createElement('div');
    placeholder.id = 'fc-drop-placeholder';
    placeholder.style.pointerEvents = 'none';

    function removePlaceholder() { if (placeholder && placeholder.parentNode) placeholder.parentNode.removeChild(placeholder); }
    function clearColRings() { root.querySelectorAll('.col').forEach(function (c) { c.style.boxShadow = ''; }); }
    function getTasksContainer(colEl) { return colEl ? colEl.querySelector('.tasks') : null; }

    function updatePlaceholderPosition(colEl, clientY) {
      var container = getTasksContainer(colEl); if (!container) return;
      var tasks = Array.prototype.slice.call(container.querySelectorAll('.task'));
      if (!tasks.length) { container.appendChild(placeholder); return; }
      var inserted = false;
      for (var i = 0; i < tasks.length; i++) {
        var t = tasks[i];
        if (draggingTaskId && String(t.getAttribute('data-task-id')) === String(draggingTaskId)) continue;
        var rect = t.getBoundingClientRect();
        if (clientY < rect.top + rect.height / 2) { container.insertBefore(placeholder, t); inserted = true; break; }
      }
      if (!inserted) container.appendChild(placeholder);
    }

    function computeBeforeTaskIdFromPlaceholder(colEl) {
      var container = getTasksContainer(colEl); if (!container) return 0;
      if (placeholder.parentNode !== container) return 0;
      var next = placeholder.nextElementSibling;
      while (next && !next.classList.contains('task')) next = next.nextElementSibling;
      if (next && next.getAttribute) { var nid = next.getAttribute('data-task-id'); return nid ? (parseInt(nid, 10) || 0) : 0; }
      return 0;
    }

    placeholder.style.border       = '2px dashed var(--fyc-red)';
    placeholder.style.opacity      = '0.35';
    placeholder.style.borderRadius = '11px';
    placeholder.style.height       = '48px';

    root.addEventListener('dragstart', function (ev) {
      var task = ev.target.closest('.task'); if (!task) return;
      draggingTaskId = task.getAttribute('data-task-id');
      try { ev.dataTransfer.setData('text/plain', draggingTaskId); } catch (e) {}
      ev.dataTransfer.effectAllowed = 'move';
      task.style.opacity = '0.5';
    });

    root.addEventListener('dragend', function (ev) {
      var task = ev.target.closest('.task'); if (task) task.style.opacity = '';
      draggingTaskId = null; clearColRings(); removePlaceholder();
    });

    root.addEventListener('dragover', function (ev) {
      var col = ev.target.closest('.col'); if (!col) return;
      ev.preventDefault(); ev.dataTransfer.dropEffect = 'move';
      col.style.boxShadow = '0 0 0 2px var(--fyc-red)';
      updatePlaceholderPosition(col, ev.clientY);
    });

    root.addEventListener('dragleave', function (ev) {
      var col = ev.target.closest('.col'); if (!col) return;
      col.style.boxShadow = '';
    });

    root.addEventListener('drop', function (ev) {
      var col = ev.target.closest('.col'); if (!col) return;
      ev.preventDefault(); col.style.boxShadow = '';
      var columnId = col.getAttribute('data-column-id');
      var taskId   = draggingTaskId;
      if (!taskId) { try { taskId = ev.dataTransfer.getData('text/plain'); } catch (e) {} }
      if (!taskId || !columnId || !state.boardId || !state.csrf) { removePlaceholder(); return; }
      var beforeTaskId = computeBeforeTaskIdFromPlaceholder(col);
      if (beforeTaskId && String(beforeTaskId) === String(taskId)) beforeTaskId = 0;
      var fd = new FormData();
      fd.set('csrf', state.csrf); fd.set('task_id', taskId); fd.set('board_id', state.boardId); fd.set('column_id', columnId);
      if (beforeTaskId > 0) fd.set('before_task_id', String(beforeTaskId));
      removePlaceholder();
      fetch('../tasks/move.php', { method: 'POST', body: fd, headers: { 'X-Requested-With': 'fetch', 'Accept': 'application/json' } })
        .then(function (r) { return r.json().catch(function () { return null; }); })
        .then(function (data) {
          if (!data || data.ok !== true) { showToast('⚠️ No se pudo mover'); return; }
          showToast('✅ Movida'); reloadBoard();
        })
        .catch(function () { showToast('⚠️ Error moviendo'); });
    });

    // ---- Submit comentario (form) ----
    root.addEventListener('submit', function (ev) {
      var form = ev.target; if (!form || form.tagName !== 'FORM') return;
      var action = String(form.getAttribute('action') || '');
      if (action.indexOf('../tasks/comment_create.php') === -1) return;
      ev.preventDefault();
      var fd = new FormData(form);
      fetch(action, { method: 'POST', body: fd, headers: { 'X-Requested-With': 'fetch', 'Accept': 'application/json' } })
        .then(function (r) { return r.json().catch(function () { return null; }); })
        .then(function (data) {
          if (!data || data.ok !== true) { showToast('⚠️ No se pudo comentar'); return; }
          var ta = form.querySelector('textarea[name="body"]'); if (ta) ta.value = '';
          showToast('💬 Comentario enviado');
          if (state.drawer && state.drawer.open && state.drawer.taskId) loadDrawer(state.drawer.taskId);
        })
        .catch(function () { showToast('⚠️ Error comentando'); });
    });

    // ---- Create tarea (submit) ----
    root.addEventListener('submit', function (ev) {
      var form = ev.target; if (!form || form.tagName !== 'FORM') return;
      var action = form.getAttribute('action') || '';
      if (action.indexOf('../tasks/create.php') === -1) return;
      ev.preventDefault();
      var fd = new FormData(form);
      if (!fd.get('csrf') && state.csrf) fd.set('csrf', state.csrf);
      if (!fd.get('board_id') && state.boardId) fd.set('board_id', state.boardId);
      fetch(action, { method: 'POST', body: fd, headers: { 'X-Requested-With': 'fetch' } })
        .then(function (r) { return r.text(); })
        .then(function () {
          var input = form.querySelector('input[name="titulo"]'); if (input) input.value = '';
          showToast('✅ Tarea creada'); reloadBoard();
        })
        .catch(function (e) { console.error('[FCPlannerBoard] Error creando tarea', e); });
    });

    // ---- Rename tarea (dblclick) ----
    root.addEventListener('dblclick', function (ev) {
      var titleEl = ev.target.closest('.task-title'); if (!titleEl) return;
      var taskEl  = titleEl.closest('.task'); if (!taskEl) return;
      var taskId  = taskEl.getAttribute('data-task-id');
      if (!taskId || !state.boardId || !state.csrf || titleEl.__editing) return;
      titleEl.__editing = true;
      var oldTitle = (titleEl.textContent || '').trim();
      var prevDraggable = taskEl.getAttribute('draggable');
      taskEl.setAttribute('draggable', 'false');
      var input = document.createElement('input');
      input.type = 'text'; input.value = oldTitle;
      input.style.cssText = 'width:100%;border-radius:7px;border:1px solid var(--fyc-red);background:var(--bg-input);color:var(--text-primary);padding:4px 8px;font-size:13px;font-weight:600;outline:none;box-sizing:border-box;';
      input.setAttribute('maxlength', '180');
      titleEl.innerHTML = ''; titleEl.appendChild(input);
      input.focus(); input.select();
      function cleanup(txt) {
        titleEl.__editing = false; titleEl.innerHTML = ''; titleEl.textContent = txt;
        if (prevDraggable === null) taskEl.removeAttribute('draggable'); else taskEl.setAttribute('draggable', prevDraggable);
      }
      function save(newTitle) {
        newTitle = (newTitle || '').trim(); if (!newTitle) { cleanup(oldTitle); return; }
        var fd = new FormData();
        fd.set('csrf', state.csrf); fd.set('task_id', taskId); fd.set('board_id', state.boardId); fd.set('titulo', newTitle);
        fetch('../tasks/rename.php', { method: 'POST', body: fd, headers: { 'X-Requested-With': 'fetch' } })
          .then(function () { showToast('✅ Renombrada'); reloadBoard(); })
          .catch(function () { cleanup(oldTitle); });
      }
      input.addEventListener('keydown', function (e) {
        if (e.key === 'Enter') { e.preventDefault(); save(input.value); }
        if (e.key === 'Escape') { e.preventDefault(); cleanup(oldTitle); }
      });
      input.addEventListener('blur', function () { save(input.value); });
    });

    // ---- Delete tarea ----
    var deleteTaskId = null;
    function openDeleteModal()  { openModal('modalDeleteTask'); }
    function closeDeleteModal() { closeModal('modalDeleteTask'); deleteTaskId = null; }

    root.addEventListener('click', function (ev) {
      var btn = ev.target.closest('[data-action="delete-task"]'); if (!btn) return;
      deleteTaskId = btn.getAttribute('data-task-id'); if (!deleteTaskId) return;
      openDeleteModal();
    });
    document.addEventListener('click', function (ev) { if (ev.target && ev.target.id === 'btnCancelDeleteTask') closeDeleteModal(); });
    document.addEventListener('click', function (ev) {
      if (!(ev.target && ev.target.id === 'btnConfirmDeleteTask')) return;
      if (!deleteTaskId || !state.boardId || !state.csrf) return;
      var fd = new FormData();
      fd.set('csrf', state.csrf); fd.set('task_id', deleteTaskId); fd.set('board_id', state.boardId);
      fetch('../tasks/delete.php', { method: 'POST', body: fd, headers: { 'X-Requested-With': 'fetch' } })
        .then(function () { closeDeleteModal(); showToast('🗑️ Eliminada'); reloadBoard(); })
        .catch(function () {});
    });

    // ---- Edit tarea (modal) ----
    var editTaskId = null;
    function openEditModal()  { openModal('modalEditTask'); }
    function closeEditModal() { closeModal('modalEditTask'); editTaskId = null; }

    root.addEventListener('click', function (ev) {
      if (ev.detail && ev.detail > 1) return;
      if (ev.target.closest('.task-title')) return;
      if (ev.target && (ev.target.tagName === 'INPUT' || ev.target.closest('.task-title input'))) return;
      if (ev.target.closest('[data-action="delete-task"]') || ev.target.closest('[data-action="open-task"]') || ev.target.closest('[data-action="col-menu"]')) return;
      var taskEl = ev.target.closest('.task'); if (!taskEl) return;
      editTaskId = taskEl.getAttribute('data-task-id'); if (!editTaskId) return;
      var titleEl = document.getElementById('edit_task_title'); if (titleEl) titleEl.textContent = taskEl.getAttribute('data-titulo') || '';
      var sel = document.getElementById('edit_prioridad'); if (sel) sel.value = taskEl.getAttribute('data-prioridad') || 'med';
      var inp = document.getElementById('edit_fecha');     if (inp) inp.value = taskEl.getAttribute('data-fecha') || '';
      var ass = document.getElementById('edit_assignee');  if (ass) ass.value = taskEl.getAttribute('data-assignee') || '';
      var iid = document.getElementById('edit_task_id');   if (iid) iid.value = editTaskId;
      openEditModal();
    });
    document.addEventListener('click', function (ev) { if (ev.target && ev.target.id === 'btnCancelEditTask') closeEditModal(); });
    document.addEventListener('click', function (ev) {
      if (!(ev.target && ev.target.id === 'btnSaveEditTask')) return;
      if (!editTaskId || !state.boardId || !state.csrf) return;
      var fd = new FormData();
      fd.set('csrf', state.csrf); fd.set('task_id', editTaskId); fd.set('board_id', state.boardId);
      fd.set('prioridad', (document.getElementById('edit_prioridad') || {}).value || 'med');
      fd.set('fecha_limite', (document.getElementById('edit_fecha')    || {}).value || '');
      fd.set('assignee_id',  (document.getElementById('edit_assignee') || {}).value || '');
      fetch('../tasks/update.php', { method: 'POST', body: fd, headers: { 'X-Requested-With': 'fetch', 'Accept': 'application/json' } })
        .then(function (r) { return r.json().catch(function () { return null; }); })
        .then(function () { closeEditModal(); showToast('✅ Guardado'); reloadBoard(); })
        .catch(function () {});
    });

    // ============================================================
    // GESTIÓN DE COLUMNAS
    // ============================================================

    var colMenuTargetId   = null;
    var colMenuTargetName = null;

    // Cerrar dropdown al hacer clic fuera
    document.addEventListener('click', function (ev) {
      var menu = document.getElementById('colContextMenu');
      if (!menu) return;
      if (menu.style.display === 'none') return;
      if (!ev.target.closest('[data-action="col-menu"]') && !ev.target.closest('#colContextMenu')) {
        menu.style.display = 'none';
      }
    });

    // Abrir dropdown ⋯
    document.addEventListener('click', function (ev) {
      var btn = ev.target.closest('[data-action="col-menu"]'); if (!btn) return;
      ev.stopPropagation();
      colMenuTargetId   = btn.getAttribute('data-column-id');
      colMenuTargetName = btn.getAttribute('data-column-name');
      var menu = document.getElementById('colContextMenu');
      if (!menu) return;
      var rect = btn.getBoundingClientRect();
      menu.style.top     = (rect.bottom + 4) + 'px';
      menu.style.left    = Math.max(4, rect.left - 80) + 'px';
      menu.style.display = 'block';
    });

    // Menú → Renombrar
    document.addEventListener('click', function (ev) {
      if (!(ev.target && ev.target.closest && ev.target.closest('#colMenuRename'))) return;
      var menu = document.getElementById('colContextMenu');
      if (menu) menu.style.display = 'none';
      if (!colMenuTargetId) return;
      document.getElementById('renameColumnId').value       = colMenuTargetId;
      document.getElementById('inputRenameColumn').value    = colMenuTargetName || '';
      openModal('modalRenameColumn');
      setTimeout(function () {
        var inp = document.getElementById('inputRenameColumn');
        if (inp) { inp.focus(); inp.select(); }
      }, 80);
    });

    // Menú → Eliminar
    document.addEventListener('click', function (ev) {
      if (!(ev.target && ev.target.closest && ev.target.closest('#colMenuDelete'))) return;
      var menu = document.getElementById('colContextMenu');
      if (menu) menu.style.display = 'none';
      if (!colMenuTargetId) return;
      document.getElementById('deleteColumnId').value = colMenuTargetId;
      var msg = document.getElementById('deleteColumnMsg');
      if (msg) msg.textContent = 'Vas a eliminar la columna "' + (colMenuTargetName || '') + '".';
      openModal('modalDeleteColumn');
    });

    // Botón "+ Columna"
    document.addEventListener('click', function (ev) {
      if (!(ev.target && ev.target.closest && ev.target.closest('#btnAddColumn'))) return;
      var inp = document.getElementById('inputNewColumnName');
      if (inp) inp.value = '';
      openModal('modalAddColumn');
      setTimeout(function () { var i = document.getElementById('inputNewColumnName'); if (i) i.focus(); }, 80);
    });

    // Confirmar crear
    document.addEventListener('click', function (ev) {
      if (!(ev.target && ev.target.id === 'btnConfirmAddColumn')) return;
      var inp    = document.getElementById('inputNewColumnName');
      var nombre = inp ? inp.value.trim() : '';
      if (!nombre) { if (inp) { inp.focus(); inp.style.borderColor = 'var(--fyc-red)'; } return; }
      closeModal('modalAddColumn');
      columnAction({ action: 'create', nombre: nombre }, function () { showToast('✅ Columna creada'); });
    });

    // Cancelar crear
    document.addEventListener('click', function (ev) {
      if (ev.target && ev.target.id === 'btnCancelAddColumn') closeModal('modalAddColumn');
    });

    // Enter en input nueva columna
    document.addEventListener('keydown', function (ev) {
      if (ev.key !== 'Enter') return;
      var m = document.getElementById('modalAddColumn');
      if (!m || m.classList.contains('hidden')) return;
      document.getElementById('btnConfirmAddColumn').click();
    });

    // Confirmar renombrar
    document.addEventListener('click', function (ev) {
      if (!(ev.target && ev.target.id === 'btnConfirmRenameColumn')) return;
      var colId  = (document.getElementById('renameColumnId') || {}).value;
      var inp    = document.getElementById('inputRenameColumn');
      var nombre = inp ? inp.value.trim() : '';
      if (!nombre || !colId) { if (inp) inp.focus(); return; }
      closeModal('modalRenameColumn');
      columnAction({ action: 'rename', column_id: colId, nombre: nombre }, function () { showToast('✅ Columna renombrada'); });
    });

    // Cancelar renombrar
    document.addEventListener('click', function (ev) {
      if (ev.target && ev.target.id === 'btnCancelRenameColumn') closeModal('modalRenameColumn');
    });

    // Enter en input renombrar
    document.addEventListener('keydown', function (ev) {
      if (ev.key !== 'Enter') return;
      var m = document.getElementById('modalRenameColumn');
      if (!m || m.classList.contains('hidden')) return;
      document.getElementById('btnConfirmRenameColumn').click();
    });

    // Confirmar eliminar columna
    document.addEventListener('click', function (ev) {
      if (!(ev.target && ev.target.id === 'btnConfirmDeleteColumn')) return;
      var colId = (document.getElementById('deleteColumnId') || {}).value;
      if (!colId) return;
      closeModal('modalDeleteColumn');
      columnAction({ action: 'delete', column_id: colId }, function () { showToast('🗑️ Columna eliminada'); });
    });

    // Cancelar eliminar columna
    document.addEventListener('click', function (ev) {
      if (ev.target && ev.target.id === 'btnCancelDeleteColumn') closeModal('modalDeleteColumn');
    });

    // ============================================================
    // FILTROS — event listeners delegados
    // ============================================================

    // Aplicar clase de color inicial a botones de prioridad
    initPrioBtnClasses();

    // Búsqueda por texto
    document.addEventListener('input', function (ev) {
      if (!ev.target || ev.target.id !== 'filterSearch') return;
      filterState.searchText = ev.target.value.toLowerCase().trim();
      applyFilters();
    });

    // Filtro responsable
    document.addEventListener('change', function (ev) {
      if (!ev.target || ev.target.id !== 'filterAssignee') return;
      filterState.activeAssignee = ev.target.value;
      applyFilters();
    });

    // Filtro prioridad (toggle)
    document.addEventListener('click', function (ev) {
      var btn = ev.target.closest && ev.target.closest('.filter-prio-btn');
      if (!btn) return;
      var prio = btn.getAttribute('data-prio');
      if (filterState.activePrios[prio]) {
        delete filterState.activePrios[prio];
        btn.style.opacity     = '.5';
        btn.style.borderColor = 'transparent';
      } else {
        filterState.activePrios[prio] = true;
        btn.style.opacity     = '1';
        btn.style.borderColor = 'var(--text-primary)';
      }
      applyFilters();
    });

    // Filtro tags (toggle)
    document.addEventListener('click', function (ev) {
      var btn = ev.target.closest && ev.target.closest('.filter-tag-btn');
      if (!btn) return;
      var tid         = btn.getAttribute('data-tag-id');
      var borderColor = btn.style.borderColor;
      if (filterState.activeTagIds[tid]) {
        delete filterState.activeTagIds[tid];
        btn.style.opacity    = '.55';
        btn.style.background = 'var(--bg-hover)';
        btn.style.color      = 'var(--text-muted)';
      } else {
        filterState.activeTagIds[tid] = true;
        btn.style.opacity    = '1';
        btn.style.background = borderColor;
        btn.style.color      = '#fff';
      }
      applyFilters();
    });

    // Limpiar todos los filtros
    document.addEventListener('click', function (ev) {
      if (!ev.target || ev.target.id !== 'btnClearFilters') return;
      filterState.activePrios    = {};
      filterState.activeTagIds   = {};
      filterState.activeAssignee = '';
      filterState.searchText     = '';
      var searchInp = document.getElementById('filterSearch');
      var selAss    = document.getElementById('filterAssignee');
      if (searchInp) searchInp.value = '';
      if (selAss)    selAss.value    = '';
      document.querySelectorAll('.filter-prio-btn').forEach(function (b) {
        b.style.opacity = '.5'; b.style.borderColor = 'transparent';
      });
      document.querySelectorAll('.filter-tag-btn').forEach(function (b) {
        b.style.opacity    = '.55';
        b.style.background = 'var(--bg-hover)';
        b.style.color      = 'var(--text-muted)';
      });
      applyFilters();
    });

  } // end installListenersOnce

  // ============================================================
  // API PÚBLICA
  // ============================================================
  window.FCPlannerBoard = window.FCPlannerBoard || {};

  window.FCPlannerBoard.destroy = function () {
    state.boardId = null;
    state.csrf    = null;
  };

  window.FCPlannerBoard.init = function (root) {
    if (!root) return;
    state.root = root;
    installListenersOnce(root);
    syncFromDOM(root);
    console.log('[FCPlannerBoard] init OK board=', state.boardId);
  };

  window.FCPlannerBoard.runEmbedScripts = runEmbedScripts;

})();
