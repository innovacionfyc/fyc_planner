// public/assets/board-view.js
(function () {
  'use strict';

  var state = {
    root: null,
    boardId: null,
    csrf: null,
    listenersInstalled: false,
    drawer: {
      open: false,
      taskId: null
    }
  };

  function qs(root, sel) { return (root || document).querySelector(sel); }
  function syncFromDOM(root) {
    var kanban = qs(root, '#kanban');
    if (!kanban) return;
    state.boardId = kanban.getAttribute('data-board-id');
    state.csrf = kanban.getAttribute('data-csrf');
  }

  function showToast(msg) {
    var t = document.getElementById('toast');
    if (!t) return;
    var box = t.querySelector('div');
    if (box) box.textContent = msg || '✅ Listo';
    t.classList.remove('hidden');
    setTimeout(function () { t.classList.add('hidden'); }, 1600);
  }

  function drawerEls() {
    return {
      overlay: document.getElementById('taskDrawerOverlay'),
      drawer: document.getElementById('taskDrawer'),
      body: document.getElementById('taskDrawerBody')
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
    // slide in
    d.drawer.classList.remove('translate-x-full');
    state.drawer.open = true;
  }

  function closeDrawer() {
    var d = drawerEls();
    if (!d.overlay || !d.drawer) return;
    d.drawer.classList.add('translate-x-full');
    // delay hide overlay to match transition
    setTimeout(function () {
      d.overlay.classList.add('hidden');
    }, 220);
    state.drawer.open = false;
    state.drawer.taskId = null;
  }

  function setDrawerLoading() {
    var d = drawerEls();
    if (!d.body) return;
    d.body.innerHTML =
      '<div class="text-sm text-slate-600">' +
      'Cargando…' +
      '</div>';
  }

  function setDrawerError(msg) {
    var d = drawerEls();
    if (!d.body) return;
    d.body.innerHTML =
      '<div class="rounded-2xl border border-rose-200 bg-rose-50 p-4 text-sm text-rose-700 font-semibold">' +
      (msg || 'No se pudo cargar el detalle.') +
      '</div>';
  }

  function loadDrawer(taskId) {
    if (!drawerExists()) return;
    if (!taskId) return;

    state.drawer.taskId = taskId;

    openDrawerShell();
    setDrawerLoading();

    fetch('../tasks/drawer.php?id=' + encodeURIComponent(taskId), {
      headers: { 'X-Requested-With': 'fetch' }
    })
      .then(function (r) {
        if (!r.ok) throw new Error('HTTP ' + r.status);
        return r.text();
      })
      .then(function (html) {
        var d = drawerEls();
        if (!d.body) return;
        d.body.innerHTML = html;
      })
      .catch(function (e) {
        console.error('[FCPlannerBoard] drawer load error', e);
        setDrawerError('No se pudo cargar el detalle (revisa drawer.php).');
      });
  }

  function reloadBoard() {
    if (!state.root || !state.boardId) return;

    fetch('./view.php?id=' + encodeURIComponent(state.boardId) + '&embed=1', {
      headers: { 'X-Requested-With': 'fetch' }
    })
      .then(function (r) { return r.text(); })
      .then(function (html) {
        // Reemplaza solo el HTML (los listeners siguen existiendo 1 sola vez)
        state.root.innerHTML = html;
        syncFromDOM(state.root);

        // Si el drawer estaba abierto, intenta re-abrirlo con el mismo taskId
        if (state.drawer.open && state.drawer.taskId) {
          loadDrawer(state.drawer.taskId);
        }

        console.log('[FCPlannerBoard] reloaded board=', state.boardId);
      })
      .catch(function () {
        console.warn('[FCPlannerBoard] No se pudo recargar el tablero');
      });
  }

  function installListenersOnce(root) {
    if (state.listenersInstalled) return;
    state.listenersInstalled = true;

    // =========================
    // Drawer: cerrar (overlay, botón, ESC)
    // =========================
    document.addEventListener('click', function (ev) {
      // Cerrar por botón
      var closeBtn = ev.target && ev.target.closest && ev.target.closest('[data-drawer-close]');
      if (closeBtn) {
        closeDrawer();
        return;
      }

      // Cerrar por overlay
      if (ev.target && ev.target.id === 'taskDrawerOverlay') {
        closeDrawer();
        return;
      }
    });

    document.addEventListener('keydown', function (ev) {
      if (ev.key !== 'Escape') return;

      // Prioridad: si hay drawer abierto, cierra drawer primero
      if (state.drawer.open && drawerExists()) {
        closeDrawer();
        return;
      }
      // Si no hay drawer, el resto del sistema maneja ESC (modales)
    });

    // =========================
    // Abrir Drawer (flecha / open-task)
    // =========================
    root.addEventListener('click', function (ev) {
      var btn = ev.target.closest && ev.target.closest('[data-action="open-task"]');
      if (!btn) return;

      ev.preventDefault();
      ev.stopPropagation();

      var taskId = btn.getAttribute('data-task-id');
      if (!taskId) return;

      if (!drawerExists()) {
        console.warn('[FCPlannerBoard] Drawer no existe en el DOM (revisa view.php embed).');
        return;
      }

      loadDrawer(taskId);
    });

    // =========================
    // DRAWER SAVE / CANCEL (¡a nivel global, NO dentro de otros listeners!)
    // =========================
    root.addEventListener('click', function (ev) {
      var btnSave = ev.target.closest && ev.target.closest('[data-action="drawer-save"]');
      if (!btnSave) return;

      ev.preventDefault();
      ev.stopPropagation();

      var taskIdEl = document.getElementById('drawer_task_id');
      var boardIdEl = document.getElementById('drawer_board_id');
      var csrfEl = document.getElementById('drawer_csrf');

      var selPrio = document.getElementById('drawer_prioridad');
      var inpFecha = document.getElementById('drawer_fecha');
      var selAss = document.getElementById('drawer_assignee');

      var taskId = taskIdEl ? String(taskIdEl.value || '') : '';
      var boardId = boardIdEl ? String(boardIdEl.value || '') : '';
      var csrf = csrfEl ? String(csrfEl.value || '') : '';

      if (!taskId || !boardId || !csrf) {
        console.warn('[FCPlannerBoard] drawer-save: faltan ids/csrf');
        return;
      }

      var prio = selPrio ? selPrio.value : 'med';
      var fecha = inpFecha ? inpFecha.value : '';
      var assignee = selAss ? selAss.value : '';

      var fd = new FormData();
      fd.set('csrf', csrf);
      fd.set('task_id', taskId);
      fd.set('board_id', boardId);
      fd.set('prioridad', prio);
      fd.set('fecha_limite', fecha);
      fd.set('assignee_id', assignee);

      fetch('../tasks/update.php', {
        method: 'POST',
        body: fd,
        headers: { 'X-Requested-With': 'fetch', 'Accept': 'application/json' }
      })
        .then(function (r) {
          // update.php en fetch responde JSON
          return r.json().catch(function () { return null; });
        })
        .then(function (data) {
          if (!data || data.ok !== true) {
            console.error('[FCPlannerBoard] drawer-save: respuesta no ok', data);
            showToast('⚠️ No se pudo guardar');
            return;
          }

          showToast('✅ Guardado');

          // Recargar tablero y drawer
          reloadBoard();
          loadDrawer(taskId);
        })
        .catch(function (e) {
          console.error('[FCPlannerBoard] drawer-save error', e);
          showToast('⚠️ Error guardando');
        });
    });

    root.addEventListener('click', function (ev) {
      var btnCancel = ev.target.closest && ev.target.closest('[data-action="drawer-cancel"]');
      if (!btnCancel) return;

      ev.preventDefault();
      ev.stopPropagation();
      closeDrawer();
    });

    // Click de prueba en tareas (deja esto, es útil)
    root.addEventListener('click', function (ev) {
      var task = ev.target.closest('.task');
      if (task) {
        console.log('[FCPlannerBoard] click task id =', task.getAttribute('data-task-id'));
      }
    });

    // =========================
    // DRAWER: publicar comentario
    // =========================
    root.addEventListener('click', function (ev) {
      var btn = ev.target.closest && ev.target.closest('[data-action="drawer-add-comment"]');
      if (!btn) return;

      ev.preventDefault();
      ev.stopPropagation();

      var taskIdEl = document.getElementById('drawer_task_id');
      var boardIdEl = document.getElementById('drawer_board_id');
      var csrfEl = document.getElementById('drawer_csrf');
      var ta = document.getElementById('drawer_comment');

      var taskId = taskIdEl ? String(taskIdEl.value || '') : '';
      var boardId = boardIdEl ? String(boardIdEl.value || '') : '';
      var csrf = csrfEl ? String(csrfEl.value || '') : '';
      var body = ta ? String(ta.value || '').trim() : '';

      if (!taskId || !boardId || !csrf) {
        console.warn('[FCPlannerBoard] drawer-add-comment: faltan ids/csrf');
        return;
      }
      if (!body) {
        showToast('✍️ Escribe un comentario');
        if (ta) ta.focus();
        return;
      }

      var fd = new FormData();
      fd.set('csrf', csrf);
      fd.set('task_id', taskId);
      fd.set('board_id', boardId);
      fd.set('body', body);

      fetch('../tasks/comment_create.php', {
        method: 'POST',
        body: fd,
        headers: { 'X-Requested-With': 'fetch', 'Accept': 'application/json' }
      })
        .then(function (r) { return r.json().catch(function () { return null; }); })
        .then(function (data) {
          if (!data || data.ok !== true) {
            console.error('[FCPlannerBoard] comment_create no ok', data);
            showToast('⚠️ No se pudo publicar');
            return;
          }

          if (ta) ta.value = '';
          showToast('💬 Comentario publicado');

          // recargar drawer para ver el comentario
          if (state.drawer && state.drawer.open && state.drawer.taskId) {
            loadDrawer(state.drawer.taskId);
          }
        })
        .catch(function (e) {
          console.error('[FCPlannerBoard] comment_create error', e);
          showToast('⚠️ Error publicando');
        });
    });

    // =========================
    // DRAG & DROP (mover tarea)
    // =========================
    var draggingTaskId = null;

    root.addEventListener('dragstart', function (ev) {
      var task = ev.target.closest('.task');
      if (!task) return;

      draggingTaskId = task.getAttribute('data-task-id');
      try { ev.dataTransfer.setData('text/plain', draggingTaskId); } catch (e) {}
      ev.dataTransfer.effectAllowed = 'move';

      task.classList.add('opacity-60');
    });

    root.addEventListener('dragend', function (ev) {
      var task = ev.target.closest('.task');
      if (task) task.classList.remove('opacity-60');
      draggingTaskId = null;

      var cols = root.querySelectorAll('.col');
      cols.forEach(function (c) { c.classList.remove('ring-2', 'ring-[#d32f57]/30'); });
    });

    root.addEventListener('dragover', function (ev) {
      var col = ev.target.closest('.col');
      if (!col) return;
      ev.preventDefault();
      ev.dataTransfer.dropEffect = 'move';
      col.classList.add('ring-2', 'ring-[#d32f57]/30');
    });

    root.addEventListener('dragleave', function (ev) {
      var col = ev.target.closest('.col');
      if (!col) return;
      col.classList.remove('ring-2', 'ring-[#d32f57]/30');
    });

    root.addEventListener('drop', function (ev) {
      var col = ev.target.closest('.col');
      if (!col) return;

      ev.preventDefault();
      col.classList.remove('ring-2', 'ring-[#d32f57]/30');

      var columnId = col.getAttribute('data-column-id');
      var taskId = draggingTaskId;

      if (!taskId) {
        try { taskId = ev.dataTransfer.getData('text/plain'); } catch (e) {}
      }

      if (!taskId || !columnId) return;
      if (!state.boardId || !state.csrf) return;

      var fd = new FormData();
      fd.set('csrf', state.csrf);
      fd.set('task_id', taskId);
      fd.set('board_id', state.boardId);
      fd.set('column_id', columnId);

      fetch('../tasks/move.php', {
        method: 'POST',
        body: fd,
        headers: { 'X-Requested-With': 'fetch' }
      })
        .then(function (r) { return r.text(); })
        .then(function () {
          showToast('✅ Tarea movida');
          reloadBoard();
        })
        .catch(function (e) {
          console.error('[FCPlannerBoard] Error moviendo tarea', e);
        });
    });

    // =========================
    // DRAWER: enviar comentario (submit)
    // =========================
    root.addEventListener('submit', function (ev) {
      var form = ev.target;
      if (!form || form.tagName !== 'FORM') return;

      // Solo el form de comentarios del drawer
      // (drawer.php debe tener: action="../tasks/comment_create.php")
      var action = String(form.getAttribute('action') || '');
      if (action.indexOf('../tasks/comment_create.php') === -1) return;

      ev.preventDefault();

      var fd = new FormData(form);

      fetch(action, {
        method: 'POST',
        body: fd,
        headers: { 'X-Requested-With': 'fetch', 'Accept': 'application/json' }
      })
        .then(function (r) { return r.json().catch(function () { return null; }); })
        .then(function (data) {
          if (!data || data.ok !== true) {
            console.error('[FCPlannerBoard] comment_create no ok', data);
            showToast('⚠️ No se pudo comentar');
            return;
          }

          // limpiar textarea
          var ta = form.querySelector('textarea[name="body"]');
          if (ta) ta.value = '';

          showToast('💬 Comentario enviado');

          // refrescar drawer para ver el comentario
          if (state.drawer && state.drawer.open && state.drawer.taskId) {
            loadDrawer(state.drawer.taskId);
          }
        })
        .catch(function (e) {
          console.error('[FCPlannerBoard] comment_create error', e);
          showToast('⚠️ Error comentando');
        });
    });

    // =========================
    // CREATE (crear tarea)
    // =========================
    root.addEventListener('submit', function (ev) {
      var form = ev.target;
      if (!form || form.tagName !== 'FORM') return;

      var action = (form.getAttribute('action') || '');
      if (action.indexOf('../tasks/create.php') === -1) return;

      ev.preventDefault();

      var fd = new FormData(form);
      if (!fd.get('csrf') && state.csrf) fd.set('csrf', state.csrf);
      if (!fd.get('board_id') && state.boardId) fd.set('board_id', state.boardId);

      fetch(action, {
        method: 'POST',
        body: fd,
        headers: { 'X-Requested-With': 'fetch' }
      })
        .then(function (r) { return r.text(); })
        .then(function () {
          var input = form.querySelector('input[name="titulo"]');
          if (input) input.value = '';
          showToast('✅ Tarea creada');
          reloadBoard();
        })
        .catch(function (e) {
          console.error('[FCPlannerBoard] Error creando tarea', e);
        });
    });

    // =========================
    // RENAME (doble clic)
    // =========================
    root.addEventListener('dblclick', function (ev) {
      var titleEl = ev.target.closest('.task-title');
      if (!titleEl) return;

      var taskEl = titleEl.closest('.task');
      if (!taskEl) return;

      var taskId = taskEl.getAttribute('data-task-id');
      if (!taskId || !state.boardId || !state.csrf) return;

      if (titleEl.__editing) return;
      titleEl.__editing = true;

      var oldTitle = (titleEl.textContent || '').trim();

      var prevDraggable = taskEl.getAttribute('draggable');
      taskEl.setAttribute('draggable', 'false');

      var input = document.createElement('input');
      input.type = 'text';
      input.value = oldTitle;
      input.className =
        'w-full rounded-xl border border-slate-200 px-2.5 py-2 text-sm font-bold ' +
        'focus:ring-2 focus:ring-[#d32f57]/20 focus:border-[#d32f57]/40 outline-none';
      input.setAttribute('maxlength', '180');

      titleEl.innerHTML = '';
      titleEl.appendChild(input);
      input.focus();
      input.select();

      function cleanup(restoreText) {
        titleEl.__editing = false;
        titleEl.innerHTML = '';
        titleEl.textContent = restoreText;

        if (prevDraggable === null) taskEl.removeAttribute('draggable');
        else taskEl.setAttribute('draggable', prevDraggable);
      }

      function save(newTitle) {
        newTitle = (newTitle || '').trim();
        if (!newTitle) {
          cleanup(oldTitle);
          return;
        }

        var fd = new FormData();
        fd.set('csrf', state.csrf);
        fd.set('task_id', taskId);
        fd.set('board_id', state.boardId);
        fd.set('titulo', newTitle);

        fetch('../tasks/rename.php', {
          method: 'POST',
          body: fd,
          headers: { 'X-Requested-With': 'fetch' }
        })
          .then(function (r) { return r.text(); })
          .then(function () {
            showToast('✅ Renombrada');
            reloadBoard();
          })
          .catch(function (e) {
            console.error('[FCPlannerBoard] Error renombrando tarea', e);
            cleanup(oldTitle);
          });
      }

      input.addEventListener('keydown', function (e) {
        if (e.key === 'Enter') {
          e.preventDefault();
          save(input.value);
        }
        if (e.key === 'Escape') {
          e.preventDefault();
          cleanup(oldTitle);
        }
      });

      input.addEventListener('blur', function () {
        save(input.value);
      });
    });

    // =========================
    // DELETE (modal bonito)
    // =========================
    var deleteTaskId = null;

    function openDeleteModal() {
      var modal = document.getElementById('modalDeleteTask');
      if (!modal) return;
      modal.classList.remove('hidden');
    }

    function closeDeleteModal() {
      var modal = document.getElementById('modalDeleteTask');
      if (!modal) return;
      modal.classList.add('hidden');
      deleteTaskId = null;
    }

    root.addEventListener('click', function (ev) {
      var btn = ev.target.closest('[data-action="delete-task"]');
      if (!btn) return;

      deleteTaskId = btn.getAttribute('data-task-id');
      if (!deleteTaskId) return;

      openDeleteModal();
    });

    document.addEventListener('click', function (ev) {
      if (ev.target && ev.target.id === 'btnCancelDeleteTask') {
        closeDeleteModal();
      }
    });

    document.addEventListener('click', function (ev) {
      if (ev.target && ev.target.id === 'modalDeleteTask') {
        closeDeleteModal();
      }
    });

    document.addEventListener('keydown', function (ev) {
      if (ev.key !== 'Escape') return;
      var modal = document.getElementById('modalDeleteTask');
      if (modal && !modal.classList.contains('hidden')) {
        closeDeleteModal();
      }
    });

    document.addEventListener('click', function (ev) {
      if (!(ev.target && ev.target.id === 'btnConfirmDeleteTask')) return;

      if (!deleteTaskId || !state.boardId || !state.csrf) return;

      var fd = new FormData();
      fd.set('csrf', state.csrf);
      fd.set('task_id', deleteTaskId);
      fd.set('board_id', state.boardId);

      fetch('../tasks/delete.php', {
        method: 'POST',
        body: fd,
        headers: { 'X-Requested-With': 'fetch' }
      })
        .then(function (r) { return r.text(); })
        .then(function () {
          closeDeleteModal();
          showToast('🗑️ Eliminada');
          reloadBoard();
        })
        .catch(function (e) {
          console.error('[FCPlannerBoard] Error eliminando tarea', e);
        });
    });

    // =========================
    // UPDATE (modal editar tarea)
    // =========================
    var editTaskId = null;

    function openEditModal() {
      var modal = document.getElementById('modalEditTask');
      if (!modal) return;
      modal.classList.remove('hidden');
    }

    function closeEditModal() {
      var modal = document.getElementById('modalEditTask');
      if (!modal) return;
      modal.classList.add('hidden');
      editTaskId = null;
    }

    // Abrir modal al hacer click en una tarea (pero no si clickeaste en botones)
    root.addEventListener('click', function (ev) {
      // ✅ Si es doble clic (click #2), NO abrir modal (deja que el dblclick haga rename)
      if (ev.detail && ev.detail > 1) return;

      // ✅ Si estoy clickeando el título, NO abrir modal (el título es para renombrar)
      if (ev.target.closest('.task-title')) return;

      // Si está renombrando (input dentro del título), NO abrir modal
      if (ev.target && (ev.target.tagName === 'INPUT' || ev.target.closest('.task-title input'))) return;

      // Si clickeo en botones (open-task, delete), no abrir modal
      if (ev.target.closest('[data-action="delete-task"]')) return;
      if (ev.target.closest('[data-action="open-task"]')) return;

      var taskEl = ev.target.closest('.task');
      if (!taskEl) return;

      editTaskId = taskEl.getAttribute('data-task-id');
      if (!editTaskId) return;

      var title = taskEl.getAttribute('data-titulo') || 'Tarea';
      var titleEl = document.getElementById('edit_task_title');
      if (titleEl) titleEl.textContent = title;

      var prio = taskEl.getAttribute('data-prioridad') || 'med';
      var fecha = taskEl.getAttribute('data-fecha') || '';
      var assignee = taskEl.getAttribute('data-assignee') || '';

      var inputTaskId = document.getElementById('edit_task_id');
      var selPrio = document.getElementById('edit_prioridad');
      var inpFecha = document.getElementById('edit_fecha');
      var selAss = document.getElementById('edit_assignee');

      if (inputTaskId) inputTaskId.value = editTaskId;
      if (selPrio) selPrio.value = prio;
      if (inpFecha) inpFecha.value = fecha;
      if (selAss) selAss.value = assignee;

      openEditModal();
    });

    document.addEventListener('click', function (ev) {
      if (ev.target && ev.target.id === 'btnCancelEditTask') {
        closeEditModal();
      }
    });

    document.addEventListener('click', function (ev) {
      if (ev.target && ev.target.id === 'modalEditTask') {
        closeEditModal();
      }
    });

    document.addEventListener('keydown', function (ev) {
      if (ev.key !== 'Escape') return;
      var modal = document.getElementById('modalEditTask');
      if (modal && !modal.classList.contains('hidden')) {
        closeEditModal();
      }
    });

    document.addEventListener('click', function (ev) {
      if (!(ev.target && ev.target.id === 'btnSaveEditTask')) return;

      if (!editTaskId || !state.boardId || !state.csrf) return;

      var selPrio = document.getElementById('edit_prioridad');
      var inpFecha = document.getElementById('edit_fecha');
      var selAss = document.getElementById('edit_assignee');

      var prio = selPrio ? selPrio.value : 'med';
      var fecha = inpFecha ? inpFecha.value : '';
      var assignee = selAss ? selAss.value : '';

      var fd = new FormData();
      fd.set('csrf', state.csrf);
      fd.set('task_id', editTaskId);
      fd.set('board_id', state.boardId);
      fd.set('prioridad', prio);
      fd.set('fecha_limite', fecha);
      fd.set('assignee_id', assignee);

      fetch('../tasks/update.php', {
        method: 'POST',
        body: fd,
        headers: { 'X-Requested-With': 'fetch', 'Accept': 'application/json' }
      })
        .then(function (r) {
          // Si update.php responde JSON (modo fetch) intentamos parsearlo
          return r.json().catch(function () { return null; });
        })
        .then(function () {
          closeEditModal();
          showToast('✅ Guardado');
          reloadBoard();
        })
        .catch(function (e) {
          console.error('[FCPlannerBoard] Error update tarea', e);
        });
    });
  }

  window.FCPlannerBoard = window.FCPlannerBoard || {};

  window.FCPlannerBoard.destroy = function () {
    state.boardId = null;
    state.csrf = null;
    // no borramos state.drawer para que si el root se re-monta, pueda re-cargar
  };

  window.FCPlannerBoard.init = function (root) {
    if (!root) return;

    state.root = root;

    installListenersOnce(root);

    syncFromDOM(root);

    console.log('[FCPlannerBoard] init OK board=', state.boardId);
  };
})();