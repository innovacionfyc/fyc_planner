// public/assets/boards-actions.js
(function () {
  'use strict';

  function byId(id) { return document.getElementById(id); }

  function openModal(id) {
    var el = byId(id);
    if (!el) return;
    el.classList.remove('hidden');
    el.setAttribute('aria-hidden', 'false');
  }

  function closeModal(id) {
    var el = byId(id);
    if (!el) return;
    el.classList.add('hidden');
    el.setAttribute('aria-hidden', 'true');
  }

  function wireModalClose(modalId) {
    var m = byId(modalId);
    if (!m) return;
    var backdrop = m.querySelector('.modalBackdrop');
    if (backdrop) backdrop.addEventListener('click', function () { closeModal(modalId); });
  }

  // Exponer closeModal global (por onclick en HTML)
  window.closeModal = closeModal;

  // Crear
  window.openCreate = function () {
    openModal('modalCreate');
  };

  // Edit
  window.openEdit = function (id, name, colorHex) {
    var a = byId('edit_board_id');
    var b = byId('edit_nombre');
    var c = byId('edit_color_hex');
    if (!a || !b || !c) return;
    a.value = String(id || '');
    b.value = name || '';
    c.value = colorHex || '#d32f57';
    openModal('modalEdit');
  };

  // Delete
  window.openDelete = function (id, name) {
    var a = byId('del_board_id');
    var b = byId('del_board_name');
    if (!a || !b) return;
    a.value = String(id || '');
    b.textContent = name || '';
    openModal('modalDelete');
  };

  // Duplicate
  window.openDuplicate = function (id, name) {
    var a = byId('dup_board_id');
    var b = byId('dup_board_name');
    if (!a || !b) return;
    a.value = String(id || '');
    b.textContent = name || '';
    openModal('modalDuplicate');
  };

  // Archive
  window.openArchive = function (id, name) {
    var a = byId('arc_board_id');
    var b = byId('arc_board_name');
    if (!a || !b) return;
    a.value = String(id || '');
    b.textContent = name || '';
    openModal('modalArchive');
  };

  // Restore
  window.openRestore = function (id, name) {
    var a = byId('res_board_id');
    var b = byId('res_board_name');
    if (!a || !b) return;
    a.value = String(id || '');
    b.textContent = name || '';
    openModal('modalRestore');
  };

  // ✅ Delegación: botones iconos (UNO SOLO listener)
  document.addEventListener('click', function (ev) {
    var btn = ev.target.closest && ev.target.closest('button[data-action]');
    if (!btn) return;

    var action = btn.getAttribute('data-action');
    var id = btn.getAttribute('data-id');
    var name = btn.getAttribute('data-name') || '';
    var color = btn.getAttribute('data-color') || '#d32f57';

    if (action === 'edit') window.openEdit(id, name, color);
    else if (action === 'del') window.openDelete(id, name);
    else if (action === 'dup') window.openDuplicate(id, name);
    else if (action === 'arc') window.openArchive(id, name);
    else if (action === 'res') window.openRestore(id, name);
  });

  // Cierres por backdrop
  wireModalClose('modalCreate');
  wireModalClose('modalEdit');
  wireModalClose('modalDelete');
  wireModalClose('modalDuplicate');
  wireModalClose('modalArchive');
  wireModalClose('modalRestore');

  // Escape cierra todo
  document.addEventListener('keydown', function (e) {
    if (e.key !== 'Escape') return;
    closeModal('modalCreate');
    closeModal('modalEdit');
    closeModal('modalDelete');
    closeModal('modalDuplicate');
    closeModal('modalArchive');
    closeModal('modalRestore');
  });

})();