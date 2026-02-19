// public/assets/board-view.js
(function () {
  'use strict';

  var state = {
    root: null,
    boardId: null,
    csrf: null,
    listenersInstalled: false
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
        console.log('[FCPlannerBoard] reloaded board=', state.boardId);
      })
      .catch(function () {
        console.warn('[FCPlannerBoard] No se pudo recargar el tablero');
      });
  }

  function installListenersOnce(root) {
    if (state.listenersInstalled) return;
    state.listenersInstalled = true;

    // Click de prueba en tareas (deja esto, es útil)
    root.addEventListener('click', function (ev) {
      var task = ev.target.closest('.task');
      if (task) {
        console.log('[FCPlannerBoard] click task id =', task.getAttribute('data-task-id'));
      }
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

      // Si clickeo en botones (detalle, delete), no abrir modal
      if (ev.target.closest('[data-action="delete-task"]')) return;
      if (ev.target.closest('a[href*="../tasks/view.php"]')) return;

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
        headers: { 'X-Requested-With': 'fetch' }
      })
        .then(function (r) { return r.text(); })
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
  };

  window.FCPlannerBoard.init = function (root) {
    if (!root) return;

    state.root = root;

    installListenersOnce(root);

    syncFromDOM(root);

    console.log('[FCPlannerBoard] init OK board=', state.boardId);
  };
})();
