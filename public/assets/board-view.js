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
    searchText:     '',
    // Ventana de fechas de la columna de «hecho».
    //   mode: '7d' | '30d' | 'all' | 'custom'
    //   from/to: 'YYYY-MM-DD', solo en modo custom
    // Vive aquí, junto a los otros cuatro, para sobrevivir a reloadBoard():
    // el estado es del módulo, no del DOM que se reemplaza.
    doneWindow: { mode: '7d', from: '', to: '' }
  };

  // ---- AUTOSAVE STATE (drawer) ----
  var drawerSaveTimer  = null;
  var drawerIsSaving   = false;
  var drawerNeedsSave  = false;

  // ---- EVENTS POLL STATE ----
  var eventsAfterId  = 0;
  var eventsInterval = null;

  function qs(root, sel) { return (root || document).querySelector(sel); }

  function syncFromDOM(root) {
    var kanban = qs(root, '#kanban');
    if (!kanban) return;
    state.boardId = kanban.getAttribute('data-board-id');
    state.csrf    = kanban.getAttribute('data-csrf');
  }

  // Localiza el contenedor de avisos SIN depender del orden del DOM.
  //
  // Antes esto era getElementById('toast'), y había dos elementos con ese id:
  // uno en workspace.php y otro dentro del HTML que view.php?embed=1 inyecta
  // en #boardMount. getElementById devuelve el primero del documento, que era
  // justamente el que vive dentro del contenido dinámico —y reloadBoard() lo
  // reemplaza entero cada vez que refresca el tablero—. El aviso podía
  // desaparecer a media animación y el temporizador de ocultado se quedaba
  // apuntando a un nodo ya desechado.
  //
  // Ahora el contenedor se marca con data-toast-global. El respaldo sirve por
  // si alguna vista añadiera otro #toast: se descarta cualquiera que esté
  // dentro de la zona que se reemplaza.
  function toastEl() {
    var el = document.querySelector('[data-toast-global]');
    if (el) return el;
    var todos = document.querySelectorAll('#toast');
    for (var i = 0; i < todos.length; i++) {
      if (!todos[i].closest('#boardMount')) return todos[i];
    }
    return null;
  }

  function showToast(msg, type) {
    var t = toastEl();
    if (!t) return;
    // Auto-detectar tipo por emoji si no se indica explícitamente
    if (!type && typeof msg === 'string' && (msg.charAt(0) === '⚠' || msg.indexOf('Error') !== -1 || msg.indexOf('error') !== -1)) type = 'error';
    var inner = t.querySelector('div') || t;
    // El mensaje se busca DENTRO del contenedor elegido, no por id global:
    // si algún día vuelve a haber un #toast-msg suelto, no se escribe en él.
    var msgEl = t.querySelector('#toast-msg') || inner;
    msgEl.textContent = msg || 'Listo';
    // Un error interrumpe; el resto espera turno. Cambiarlo antes de mostrar
    // es lo que hace que el lector de pantalla lo anuncie con la urgencia
    // correcta.
    t.setAttribute('aria-live', type === 'error' ? 'assertive' : 'polite');
    // Colores por tipo — se aplican al inner (pill), no al wrapper posicionador
    if (type === 'error') {
      inner.style.background  = 'var(--badge-overdue-bg, #3a1010)';
      inner.style.borderColor = 'var(--badge-overdue-tx, #e85070)';
      inner.style.color       = 'var(--badge-overdue-tx, #e85070)';
    } else {
      inner.style.background  = '';
      inner.style.borderColor = '';
      inner.style.color       = '';
    }
    // Slide in
    clearTimeout(t._hideTimer);
    t.style.opacity       = '1';
    t.style.transform     = 'translateX(-50%) translateY(0)';
    t.style.pointerEvents = 'auto';
    // Slide out after 2.8s
    t._hideTimer = setTimeout(function () {
      t.style.opacity       = '0';
      t.style.transform     = 'translateX(-50%) translateY(20px)';
      t.style.pointerEvents = 'none';
    }, 2800);
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
    // El visor de imagen vive dentro del HTML del drawer, así que al
    // reemplazarlo desaparece. Lo que NO desaparece solo es el bloqueo de
    // scroll del body: se libera aquí para no dejar la página trabada.
    document.body.classList.remove('fyc-viewer-open');
    state.drawer.taskId = taskId;
    openDrawerShell();
    setDrawerLoading();
    fetch('../tasks/drawer.php?id=' + encodeURIComponent(taskId), { headers: { 'X-Requested-With': 'fetch' } })
      .then(function (r) { if (!r.ok) throw new Error('HTTP ' + r.status); return r.text(); })
      .then(function (html) {
        var d = drawerEls();
        if (!d.body) return;
        d.body.innerHTML = html;
        // innerHTML inserta los <script> pero NO los ejecuta: es una defensa
        // del navegador, no un fallo. Sin esta llamada, todo el JS que viaja
        // dentro del cajón queda muerto (etiquetas, Ctrl+Enter). Ver la nota
        // de runEmbedScripts sobre por qué repetirlo no duplica manejadores.
        runEmbedScripts(d.body);
      })
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

  // ---- VENTANA DE FECHAS DE LA COLUMNA «HECHO» ----

  // La referencia de «hoy» la pone el servidor en #kanban[data-today].
  // completed_at lo escribe PHP con la hora del servidor; calcular la ventana
  // con el reloj del navegador desplazaría el corte un día entero cuando el
  // usuario está en otro huso o tiene la hora mal puesta. El reloj local solo
  // se usa si el atributo falta (vistas antiguas).
  function serverToday() {
    var k = document.querySelector('#kanban');
    var d = k && k.getAttribute('data-today');
    if (d && /^\d{4}-\d{2}-\d{2}$/.test(d)) return d;
    var n = new Date();
    return n.getFullYear() + '-' + dosDig(n.getMonth() + 1) + '-' + dosDig(n.getDate());
  }

  function dosDig(n) { return (n < 10 ? '0' : '') + n; }

  // Suma (o resta) días a una fecha 'YYYY-MM-DD'. En UTC a propósito: hacerlo
  // en hora local haría que un cambio de horario de verano moviera el límite.
  function sumarDias(iso, dias) {
    var p = iso.split('-');
    var d = new Date(Date.UTC(+p[0], +p[1] - 1, +p[2]));
    d.setUTCDate(d.getUTCDate() + dias);
    return d.getUTCFullYear() + '-' + dosDig(d.getUTCMonth() + 1) + '-' + dosDig(d.getUTCDate());
  }

  // Límites de la ventana, o null si no debe ocultarse nada.
  // «Últimos 7 días» = hoy y los seis anteriores, ambos extremos incluidos.
  // Un rango personalizado incompleto o al revés devuelve null: se prefiere
  // enseñar de más a vaciar la columna sin explicación.
  function doneWindowBounds() {
    var w = filterState.doneWindow || {};
    if (w.mode === 'all') return null;
    if (w.mode === 'custom') {
      if (!w.from || !w.to || w.from > w.to) return null;
      return { from: w.from, to: w.to };
    }
    var dias  = (w.mode === '30d') ? 30 : 7;
    var hoy   = serverToday();
    return { from: sumarDias(hoy, -(dias - 1)), to: hoy };
  }

  function rangoPersonalizadoInvalido() {
    var w = filterState.doneWindow || {};
    if (w.mode !== 'custom') return false;
    if (!w.from || !w.to) return false;   // a medio escribir no es un error
    return w.from > w.to;
  }

  function esColumnaHecho(col) {
    return !!col && col.getAttribute('data-is-done') === '1';
  }

  function etiquetaVentana() {
    var w = filterState.doneWindow || {};
    if (w.mode === '30d')    return 'en los últimos 30 días';
    if (w.mode === 'all')    return '';
    if (w.mode === 'custom') return 'en el rango elegido';
    return 'en los últimos 7 días';
  }

  function applyFilters() {
    var tasks   = document.querySelectorAll('.task.fyc-card');
    var visible = 0;
    var bounds  = doneWindowBounds();
    // Tarjetas que pasan todos los filtros pero caen fuera de la ventana.
    // Solo se usan cuando hay búsqueda de texto, que es cuando se muestran.
    var fueraDeRango = [];

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

      // Ventana de fechas — solo en la columna de «hecho».
      //
      // El texto manda sobre la fecha: si el usuario está buscando, la ventana
      // se aparta. Buscar un título que existe y no ver nada se lee como que
      // la tarea se perdió, y sería mentira. En su lugar aparece, y el
      // contador explica por qué hay tarjetas antiguas a la vista.
      //
      // Una tarea SIN completed_at no se oculta jamás: no sabemos cuándo se
      // terminó, y esconder lo que no se puede clasificar es cómo se pierden
      // las cosas de vista.
      if (show && bounds && !filterState.searchText && esColumnaHecho(card.closest('.col'))) {
        var fin = card.getAttribute('data-completed') || '';
        if (fin && (fin < bounds.from || fin > bounds.to)) show = false;
      } else if (show && bounds && filterState.searchText && esColumnaHecho(card.closest('.col'))) {
        var fin2 = card.getAttribute('data-completed') || '';
        if (fin2 && (fin2 < bounds.from || fin2 > bounds.to)) fueraDeRango.push(card);
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
      var totalCol = col.querySelectorAll('.task.fyc-card').length;
      var cnt = col.querySelector('.cnt');
      if (cnt) {
        cnt.textContent = hasActiveFilter() ? visibleInCol + '/' + totalCol : totalCol;
      }

      // Contador y vacío propios de la columna de «hecho»
      var caja = col.querySelector('[data-done-filter]');
      if (caja && esColumnaHecho(col)) {
        var lbl = caja.querySelector('[data-done-count]');
        if (lbl) {
          var txt = 'viendo ' + visibleInCol + ' de ' + totalCol;
          // El aviso de «fuera del rango» solo aparece cuando hay algo fuera.
          // Enseñarlo con un cero sería ruido permanente en la cabecera.
          var fuera = fueraDeRango.filter(function (c) { return c.closest('.col') === col; }).length;
          if (fuera > 0) txt += ' · ' + fuera + ' fuera del rango';
          lbl.textContent = txt;
        }
        // Aviso de rango al revés: se marca el campo, no se vacía la columna.
        var malo = rangoPersonalizadoInvalido();
        caja.querySelectorAll('[data-done-from], [data-done-to]').forEach(function (inp) {
          inp.style.borderColor = malo ? 'var(--badge-overdue-tx, #e85070)' : '';
        });
        var msg = col.querySelector('[data-done-empty-msg]');
        if (msg) {
          var suf = etiquetaVentana();
          msg.textContent = suf ? ('Sin tareas terminadas ' + suf) : 'Sin tareas terminadas';
        }
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

    // Ventana de la columna «hecho». reloadBoard() reemplaza la cabecera
    // entera, así que el selector vuelve siempre a su primera opción; hay que
    // repintarlo desde filterState, igual que los otros cuatro filtros.
    var w = filterState.doneWindow || { mode: '7d', from: '', to: '' };
    document.querySelectorAll('[data-done-filter]').forEach(function (caja) {
      var sel = caja.querySelector('[data-done-window]');
      if (sel) sel.value = w.mode;
      var fila = caja.querySelector('[data-done-range]');
      if (fila) fila.style.display = (w.mode === 'custom') ? '' : 'none';
      var from = caja.querySelector('[data-done-from]');
      var to   = caja.querySelector('[data-done-to]');
      if (from) from.value = w.from || '';
      if (to)   to.value   = w.to   || '';
    });
  }

  // Re-ejecuta los <script> del HTML inyectado vía innerHTML.
  // Los navegadores no ejecutan scripts insertados con innerHTML por seguridad,
  // así que hay que clonarlos como elementos nuevos para que el motor JS los corra.
  // Solo procesa scripts JS (excluye type="application/json" y similares).
  //
  // Se ejecuta ÚNICAMENTE código en línea de nuestras propias plantillas
  // (view.php y drawer.php). Los <script src> se saltan a propósito: nada de
  // este mecanismo debe poder traer código de un tercero. Antes tampoco los
  // cargaba —solo se copia textContent, nunca el atributo src— pero convenía
  // que la intención estuviera escrita y no fuese un efecto secundario.
  //
  // Sobre repetirlo: es seguro porque los scripts de nuestras plantillas son
  // funciones anónimas autoejecutadas que solo se enlazan a elementos que
  // viven DENTRO del contenedor que se acaba de reemplazar. Al sustituir el
  // HTML, esos elementos —y con ellos sus manejadores— dejan de existir, así
  // que la ejecución siguiente parte de cero. Si algún día una plantilla se
  // enlazase a document o a window, ese script sí acumularía manejadores en
  // cada carga; la suite de F8.2.1 vigila justamente eso.
  function runEmbedScripts(container) {
    container.querySelectorAll('script:not([type]),script[type="text/javascript"]').forEach(function (s) {
      if (s.src) return;
      var n = document.createElement('script');
      n.textContent = s.textContent;
      document.head.appendChild(n);
      document.head.removeChild(n);
    });
  }

  // ---- AUTOSAVE HELPERS ----
  function setSaveBtnState(s) {
    var btn = document.querySelector('[data-action="drawer-save"]');
    if (!btn) return;
    if (s === 'saving') {
      btn.textContent = 'Guardando…';
      btn.disabled = true;
      btn.style.opacity = '0.65';
    } else if (s === 'saved') {
      btn.textContent = '✓ Guardado';
      btn.disabled = false;
      btn.style.opacity = '';
      setTimeout(function () {
        var b = document.querySelector('[data-action="drawer-save"]');
        if (b && b.textContent.indexOf('Guardado') !== -1) { b.textContent = 'Guardar cambios'; }
      }, 2200);
    } else if (s === 'error') {
      btn.textContent = '⚠ Error al guardar';
      btn.disabled = false;
      btn.style.opacity = '';
    } else {
      btn.textContent = 'Guardar cambios';
      btn.disabled = false;
      btn.style.opacity = '';
    }
  }

  function doDrawerSave(reloadAfter) {
    if (drawerIsSaving) { drawerNeedsSave = true; return; }
    var taskIdEl  = document.getElementById('drawer_task_id');
    var boardIdEl = document.getElementById('drawer_board_id');
    var csrfEl    = document.getElementById('drawer_csrf');
    var selPrio   = document.getElementById('drawer_prioridad');
    var inpFecha  = document.getElementById('drawer_fecha');
    var selAss    = document.getElementById('drawer_assignee');
    var taDesc    = document.getElementById('drawer_desc');
    var taskId  = taskIdEl  ? taskIdEl.value  : '';
    var boardId = boardIdEl ? boardIdEl.value : '';
    if (!taskId || !boardId) return;
    var csrf = csrfEl ? csrfEl.value : (state.csrf || '');
    var fd = new FormData();
    fd.set('csrf', csrf);
    fd.set('task_id', taskId);
    fd.set('board_id', boardId);
    fd.set('prioridad',      selPrio  ? selPrio.value  : 'med');
    fd.set('fecha_limite',   inpFecha ? inpFecha.value : '');
    fd.set('assignee_id',    selAss   ? selAss.value   : '');
    fd.set('descripcion_md', taDesc   ? taDesc.value   : '');
    drawerIsSaving = true;
    setSaveBtnState('saving');
    fetch('../tasks/update.php', { method: 'POST', body: fd, headers: { 'X-Requested-With': 'fetch', 'Accept': 'application/json' } })
      .then(function (r) { return r.json().catch(function () { return null; }); })
      .then(function (data) {
        drawerIsSaving = false;
        if (!data || data.ok !== true) {
          setSaveBtnState('error');
          showToast('⚠️ No se pudo guardar', 'error');
        } else {
          setSaveBtnState('saved');
          if (reloadAfter) reloadBoard({ reloadDrawer: false });
        }
        if (drawerNeedsSave) { drawerNeedsSave = false; scheduleDrawerSave(); }
      })
      .catch(function () {
        drawerIsSaving = false;
        setSaveBtnState('error');
        if (drawerNeedsSave) { drawerNeedsSave = false; }
      });
  }

  function scheduleDrawerSave() {
    clearTimeout(drawerSaveTimer);
    drawerSaveTimer = setTimeout(function () { doDrawerSave(false); }, 1500);
  }

  function reloadBoard(opts) {
    if (!state.root || !state.boardId) return;
    var reloadDrawer = true;
    if (opts && typeof opts.reloadDrawer === 'boolean') reloadDrawer = opts.reloadDrawer;
    // Barra de progreso fina mientras recarga (desaparece sola con el innerHTML)
    state.root.style.position = 'relative';
    var pb = document.createElement('div');
    pb.style.cssText = 'position:absolute;top:0;left:0;width:100%;height:3px;z-index:20;'
      + 'background:var(--fyc-red);border-radius:0 2px 2px 0;'
      + 'transform:scaleX(0);transform-origin:left;'
      + 'animation:fyc-progress 0.9s ease-out forwards;pointer-events:none;';
    state.root.appendChild(pb);
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
        // Avanzar el cursor de eventos para evitar recargar por nuestras propias mutaciones
        var meta = state.root.querySelector('#board-meta');
        if (meta) {
          var newLastId = parseInt(meta.dataset.lastEventId || '0', 10);
          if (newLastId > eventsAfterId) eventsAfterId = newLastId;
        }
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

    // ---- Drawer save (manual: cancela autosave pendiente, guarda ahora + reload) ----
    // Usa document porque el drawer vive fuera de #boardMount (en workspace.php)
    document.addEventListener('click', function (ev) {
      var btnSave = ev.target.closest && ev.target.closest('[data-action="drawer-save"]');
      if (!btnSave) return;
      ev.preventDefault(); ev.stopPropagation();
      clearTimeout(drawerSaveTimer);
      doDrawerSave(true);
    });

    // ---- Drawer autosave: campos que disparan guardado automático ----
    // Usa document porque los campos del drawer viven fuera de #boardMount
    var AUTOSAVE_FIELDS = { drawer_prioridad: 1, drawer_fecha: 1, drawer_assignee: 1 };
    document.addEventListener('change', function (ev) {
      if (ev.target && AUTOSAVE_FIELDS[ev.target.id]) scheduleDrawerSave();
    });
    document.addEventListener('input', function (ev) {
      if (ev.target && ev.target.id === 'drawer_desc') scheduleDrawerSave();
    });

    // ---- Drawer cancelar ----
    // Estaba delegado en root, que es el contenedor del tablero; el cajón vive
    // FUERA de él, en workspace.php, así que el clic no llegaba nunca y el
    // botón no cerraba nada. Se pasa a document, igual que guardar y comentar.
    document.addEventListener('click', function (ev) {
      var btn = ev.target.closest && ev.target.closest('[data-action="drawer-cancel"]');
      if (!btn) return;
      ev.preventDefault(); ev.stopPropagation(); closeDrawer();
    });

    // ---- Drawer comentario ----
    // Usa document porque el botón vive dentro de #taskDrawer, fuera de #boardMount
    document.addEventListener('click', function (ev) {
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
            // Mismas clases que las tarjetas que pinta drawer.php: si esto
            // vuelve a llevar estilos en línea, el comentario recién puesto
            // se ve distinto a los demás hasta recargar el cajón.
            var div = document.createElement('div');
            div.className = 'fyc-comment';
            div.innerHTML = '<div class="fyc-comment-meta"><span class="fyc-comment-who"></span>'
              + '<span class="fyc-comment-when"></span></div><div class="fyc-comment-body"></div>';
            // textContent, nunca innerHTML: ni el nombre ni el texto pueden
            // inyectar marcado.
            div.querySelector('.fyc-comment-who').textContent  = window.FCPlannerCurrentUserName || 'Tú';
            div.querySelector('.fyc-comment-when').textContent = fecha;
            div.querySelector('.fyc-comment-body').textContent = body;
            wrapper.appendChild(div);
          }
          if (ta) {
            ta.value = '';
            // Devuelve el campo a su altura compacta: lo escucha el script
            // que viaja dentro del cajón, que es quien lo hace crecer.
            ta.dispatchEvent(new CustomEvent('fyc-reset'));
          }
          showToast('💬 Comentario publicado');
        })
        .catch(function () { showToast('⚠️ Error publicando'); });
    });

    // ============================================================
    // ADJUNTOS (Fase B: selector, subida, listado y borrado)
    // Se delega en document porque el drawer se inyecta con innerHTML,
    // igual que el resto de acciones del drawer.
    // ============================================================

    // Límites: espejo del backend, solo para dar aviso inmediato.
    // La autoridad sigue siendo attachment_upload.php.
    //
    // Los dos topes son iguales (14 MB) pero NO son lo mismo:
    //   · POR ARCHIVO   descarta un archivo suelto demasiado pesado;
    //   · POR PETICIÓN  descarta un conjunto que sumado se pasa.
    // Dos archivos de 8 MB pasan el primero y fallan el segundo, y es
    // justamente ese caso el que el servidor de producción no admite.
    //
    // Si estos valores dejan de coincidir con las constantes de
    // public/_attachments.php, el usuario recibirá rechazos del servidor que
    // el cliente no supo anticipar. Se cambian juntos.
    var ATTACH_MAX_FILES         = 5;
    var ATTACH_MAX_FILE_BYTES    = 14 * 1024 * 1024;   // ATTACH_MAX_FILE_BYTES
    var ATTACH_MAX_REQUEST_BYTES = 14 * 1024 * 1024;   // ATTACH_MAX_REQUEST_BYTES

    // Los tres tipos comparten techo: lo que manda es el límite del servidor,
    // no la naturaleza del archivo.
    var ATTACH_LIMITS = {
      image: { bytes: ATTACH_MAX_FILE_BYTES, exts: ['jpg', 'jpeg', 'png', 'webp', 'gif'] },
      audio: { bytes: ATTACH_MAX_FILE_BYTES, exts: ['mp3', 'm4a', 'ogg', 'wav'] },
      video: { bytes: ATTACH_MAX_FILE_BYTES, exts: ['mp4', 'webm', 'mov'] }
    };
    var attachBusy = false;

    /** Bytes a MB con un decimal, para mensajes legibles. */
    function attachMb(bytes) {
      return (Math.round((Number(bytes) || 0) / 1048576 * 10) / 10) + ' MB';
    }

    function attachKindOf(filename) {
      var ext = String(filename || '').split('.').pop().toLowerCase();
      for (var k in ATTACH_LIMITS) {
        if (ATTACH_LIMITS[k].exts.indexOf(ext) !== -1) return k;
      }
      return null;
    }

    function attachSetStatus(msg, kind) {
      var box = document.getElementById('drawer_attach_status');
      if (!box) return;
      if (!msg) { box.style.display = 'none'; box.textContent = ''; return; }
      box.className = 'fyc-attach-status-' + (kind || 'info');
      box.textContent = msg;
      box.style.display = 'block';
    }

    function attachSetBusy(busy) {
      attachBusy = busy;
      var btn = document.querySelector('[data-action="attach-pick"]');
      if (btn) {
        btn.disabled = busy;
        btn.style.opacity = busy ? '0.6' : '';
        btn.style.pointerEvents = busy ? 'none' : '';
      }
    }

    // ¿Está el drawer abierto y el usuario puede escribir?
    // El selector de archivos solo se pinta con can_write_board(), así que su
    // presencia es la señal de permiso. El backend revalida igualmente.
    function attachCanWriteHere() {
      return !!document.getElementById('drawer_attach_input');
    }

    function attachContext() {
      var taskIdEl = document.getElementById('drawer_task_id');
      var csrfEl   = document.getElementById('drawer_csrf');
      return {
        taskId: taskIdEl ? String(taskIdEl.value || '') : '',
        csrf:   csrfEl ? csrfEl.value : (state.csrf || '')
      };
    }

    // ¿El foco está en un campo donde pegar texto es lo normal?
    function attachIsEditableTarget(el) {
      if (!el || !el.closest) return false;
      if (el.closest('input, textarea, select')) return true;
      if (el.closest('[contenteditable=""], [contenteditable="true"]')) return true;
      return false;
    }

    // Nombre legible para capturas del portapapeles, en hora local del equipo.
    function attachScreenshotName(file, index) {
      var d = new Date();
      var p = function (n, w) { return String(n).padStart(w || 2, '0'); };
      var stamp = d.getFullYear() + '-' + p(d.getMonth() + 1) + '-' + p(d.getDate())
        + '-' + p(d.getHours()) + p(d.getMinutes()) + p(d.getSeconds());
      var ext = 'png';
      var m = /^image\/(png|jpeg|webp|gif)$/.exec(String(file.type || ''));
      if (m) ext = (m[1] === 'jpeg') ? 'jpg' : m[1];
      return 'captura-' + stamp + (index > 0 ? '-' + (index + 1) : '') + '.' + ext;
    }

    // Los navegadores entregan las capturas como "image.png", "blob" o sin nombre.
    function attachNameLooksGeneric(name) {
      if (!name) return true;
      return /^(image|imagen|blob|captura|screenshot|unknown)(\.[a-z0-9]+)?$/i.test(String(name).trim());
    }

    // ============================================================
    // FLUJO ÚNICO DE SUBIDA
    // Lo usan por igual: el selector, el pegado y el arrastre.
    // ============================================================
    function uploadTaskAttachments(fileList, source) {
      var files = Array.prototype.slice.call(fileList || []);
      if (!files.length) return Promise.resolve(false);

      if (attachBusy) {
        attachSetStatus('Espera a que termine la subida en curso.', 'info');
        return Promise.resolve(false);
      }
      if (!attachCanWriteHere()) return Promise.resolve(false);

      var ctx = attachContext();
      if (!ctx.taskId || !ctx.csrf) {
        attachSetStatus('No se pudo identificar la tarea.', 'error');
        return Promise.resolve(false);
      }

      if (files.length > ATTACH_MAX_FILES) {
        attachSetStatus('Máximo ' + ATTACH_MAX_FILES + ' archivos por vez. Intentaste ' + files.length + '.', 'error');
        return Promise.resolve(false);
      }

      // Validación de cliente: solo para avisar antes de gastar la subida.
      // No sustituye al backend, que vuelve a comprobarlo todo.
      var problemas = [];
      var totalBytes = 0;

      files.forEach(function (f) {
        // El tamaño se suma SIEMPRE, aunque el archivo tenga otro problema:
        // así el aviso del total refleja lo que el usuario seleccionó.
        var size = Number(f && f.size) || 0;
        if (size > 0) totalBytes += size;

        var kind = attachKindOf(f.name);
        if (!kind) { problemas.push('«' + f.name + '»: formato no permitido.'); return; }
        if (size > ATTACH_LIMITS[kind].bytes) {
          problemas.push('«' + f.name + '»: pesa ' + attachMb(size)
            + ' y el máximo por archivo es ' + attachMb(ATTACH_LIMITS[kind].bytes) + '.');
        }
      });
      if (problemas.length) {
        attachSetStatus(problemas.join(' '), 'error');
        return Promise.resolve(false);
      }

      // Tope del conjunto. Se rechaza el lote ENTERO, igual que hace el
      // backend: subir «los que quepan» dejaría al usuario sin saber qué
      // llegó y qué no.
      if (totalBytes > ATTACH_MAX_REQUEST_BYTES) {
        attachSetStatus(
          'El conjunto pesa ' + attachMb(totalBytes) + ' y el máximo por envío es '
          + attachMb(ATTACH_MAX_REQUEST_BYTES) + '. No se ha subido nada: '
          + 'reduce la selección o comparte los archivos grandes como enlace externo '
          + '(para vídeos, YouTube o Vimeo).',
          'error'
        );
        return Promise.resolve(false);
      }

      var fd = new FormData();
      fd.set('csrf', ctx.csrf);
      fd.set('task_id', ctx.taskId);
      files.forEach(function (f) { fd.append('files[]', f, f.name); });

      var verbo = (source === 'paste') ? 'Pegando ' : (source === 'drop' ? 'Adjuntando ' : 'Subiendo ');
      attachSetBusy(true);
      attachSetStatus(verbo + files.length + (files.length === 1 ? ' archivo…' : ' archivos…'), 'info');

      return fetch('../tasks/attachment_upload.php', {
        method: 'POST', body: fd,
        headers: { 'X-Requested-With': 'fetch', 'Accept': 'application/json' }
      })
        .then(function (r) {
          return r.json().catch(function () { return null; })
            .then(function (data) { return { status: r.status, data: data }; });
        })
        .then(function (res) {
          var d = res.data;

          // 413: demasiado grande. Hay dos variantes y conviene distinguirlas.
          //   · request_too_large  el backend sumó y el conjunto se pasa;
          //   · payload_too_large  PHP descartó el cuerpo entero y $_POST llegó
          //                        vacío, así que puede NO haber JSON que leer.
          // Se prefiere el mensaje del backend cuando existe: ya viene redactado
          // y con el límite real. El texto de reserva cubre el caso sin JSON.
          if (res.status === 413) {
            var msg413 = (d && typeof d.message === 'string' && d.message) ? d.message : '';
            if (!msg413) {
              msg413 = 'El envío supera el tamaño máximo que admite el servidor ('
                + attachMb(ATTACH_MAX_REQUEST_BYTES) + '). Reduce la selección o '
                + 'comparte los archivos grandes como enlace externo '
                + '(para vídeos, YouTube o Vimeo).';
            }
            attachSetStatus(msg413, 'error');
            return false;
          }
          if (res.status === 403) {
            attachSetStatus('No tienes permiso para adjuntar archivos en esta tarea.', 'error');
            return false;
          }
          if (res.status === 422 || (d && d.ok !== true && d.rejected && d.rejected.length)) {
            var msgs = (d && d.rejected ? d.rejected : []).map(function (x) {
              return '«' + (x.name || 'archivo') + '»: ' + (x.error || 'no válido');
            });
            attachSetStatus(msgs.length ? msgs.join(' ') : 'Ningún archivo pudo adjuntarse.', 'error');
            return false;
          }
          if (!d || d.ok !== true) {
            attachSetStatus('No se pudo subir. Inténtalo de nuevo.', 'error');
            return false;
          }

          var n = (d.attachments || []).length;
          showToast('📎 ' + n + (n === 1 ? ' adjunto añadido' : ' adjuntos añadidos'));

          if (d.rejected && d.rejected.length) {
            var rej = d.rejected.map(function (x) {
              return '«' + (x.name || 'archivo') + '»: ' + (x.error || 'no válido');
            });
            attachSetStatus('Algunos no se pudieron adjuntar. ' + rej.join(' '), 'error');
          }

          loadDrawer(ctx.taskId);   // refresca solo el drawer
          return true;
        })
        .catch(function () {
          attachSetStatus('Error de conexión al subir los archivos.', 'error');
          return false;
        })
        .then(function (okResult) {
          // Equivalente a finally: el estado SIEMPRE se limpia
          attachSetBusy(false);
          attachDropSetActive(false);
          var input = document.getElementById('drawer_attach_input');
          if (input) input.value = '';
          return okResult;
        });
    }

    // Expuesto para las pruebas automatizadas de la Fase C
    window.FCPlannerUploadAttachments = uploadTaskAttachments;

    // ---- Abrir el selector de archivos ----
    document.addEventListener('click', function (ev) {
      var btn = ev.target.closest && ev.target.closest('[data-action="attach-pick"]');
      if (!btn || attachBusy) return;
      ev.preventDefault(); ev.stopPropagation();
      var input = document.getElementById('drawer_attach_input');
      if (input) { input.value = ''; input.click(); }
    });

    // ---- Origen 1: selector de archivos ----
    document.addEventListener('change', function (ev) {
      var input = ev.target;
      if (!input || input.id !== 'drawer_attach_input') return;
      uploadTaskAttachments(input.files, 'picker');
    });

    // ============================================================
    // Origen 2: PEGAR CON CTRL+V
    // ============================================================
    document.addEventListener('paste', function (ev) {
      // Si el foco está en un campo de texto, el pegado es del usuario:
      // no se toca. Esto protege descripción, comentarios y buscadores.
      if (attachIsEditableTarget(ev.target) || attachIsEditableTarget(document.activeElement)) return;

      // Solo con el drawer abierto y permiso de escritura
      if (!attachCanWriteHere()) return;
      var ctx = attachContext();
      if (!ctx.taskId) return;

      var cd = ev.clipboardData || window.clipboardData;
      if (!cd) return;

      var imgs = [];
      var items = cd.items || [];
      for (var i = 0; i < items.length; i++) {
        var it = items[i];
        if (it.kind !== 'file') continue;
        if (String(it.type || '').indexOf('image/') !== 0) continue;
        var f = it.getAsFile();
        if (f) imgs.push(f);
      }
      if (!imgs.length) return;   // texto u otro contenido: pegado normal

      ev.preventDefault();

      if (imgs.length > ATTACH_MAX_FILES) {
        attachSetStatus('Solo puedes pegar hasta ' + ATTACH_MAX_FILES + ' imágenes a la vez. Pegaste ' + imgs.length + '.', 'error');
        return;
      }

      // Renombrar solo si el navegador no dio un nombre útil
      var renamed = imgs.map(function (f, idx) {
        if (!attachNameLooksGeneric(f.name)) return f;
        try {
          return new File([f], attachScreenshotName(f, idx), { type: f.type || 'image/png' });
        } catch (e) {
          return f;   // navegadores sin constructor File
        }
      });

      uploadTaskAttachments(renamed, 'paste');
    });

    // ============================================================
    // Origen 3: ARRASTRAR Y SOLTAR
    // ============================================================
    var dragDepth = 0;

    function attachDropZone() {
      return document.querySelector('[data-attachments-section]');
    }

    function attachDropSetActive(on) {
      var zone = attachDropZone();
      if (!zone) { dragDepth = 0; return; }
      if (on) {
        zone.classList.add('fyc-attach-dropping');
      } else {
        zone.classList.remove('fyc-attach-dropping');
        dragDepth = 0;
      }
    }

    // Solo interesan los arrastres que traen ARCHIVOS
    function attachDragHasFiles(ev) {
      var dt = ev.dataTransfer;
      if (!dt) return false;
      if (dt.types) {
        for (var i = 0; i < dt.types.length; i++) {
          if (dt.types[i] === 'Files') return true;
        }
      }
      return false;
    }

    document.addEventListener('dragenter', function (ev) {
      if (!attachDragHasFiles(ev)) return;
      var zone = attachDropZone();
      if (!zone || !zone.contains(ev.target)) return;
      if (!attachCanWriteHere()) return;
      ev.preventDefault();
      dragDepth++;                 // contador: evita el parpadeo con hijos anidados
      attachDropSetActive(true);
    });

    document.addEventListener('dragover', function (ev) {
      if (!attachDragHasFiles(ev)) return;
      var zone = attachDropZone();
      if (!zone || !zone.contains(ev.target)) return;
      if (!attachCanWriteHere()) return;
      ev.preventDefault();         // imprescindible para que 'drop' se dispare
      if (ev.dataTransfer) ev.dataTransfer.dropEffect = 'copy';
    });

    document.addEventListener('dragleave', function (ev) {
      if (!attachDragHasFiles(ev)) return;
      var zone = attachDropZone();
      if (!zone || !zone.contains(ev.target)) return;
      dragDepth--;
      if (dragDepth <= 0) attachDropSetActive(false);
    });

    document.addEventListener('drop', function (ev) {
      var zone = attachDropZone();
      if (!zone || !zone.contains(ev.target)) return;
      if (!attachDragHasFiles(ev)) return;   // texto o URL: se ignora en esta fase

      ev.preventDefault();
      attachDropSetActive(false);

      if (!attachCanWriteHere()) return;

      var dt = ev.dataTransfer;
      var files = Array.prototype.slice.call((dt && dt.files) || []);
      if (!files.length) return;

      // Las carpetas llegan sin tipo y con tamaño 0: se descartan con aviso.
      var carpetas = [];
      var validos  = [];
      files.forEach(function (f) {
        var pareceCarpeta = (!f.type && (!f.size || f.size % 4096 === 0) && f.name.indexOf('.') === -1);
        if (pareceCarpeta) { carpetas.push(f.name); } else { validos.push(f); }
      });

      if (carpetas.length) {
        attachSetStatus('No se pueden adjuntar carpetas: ' + carpetas.join(', ') + '.', 'error');
        if (!validos.length) return;
      }

      uploadTaskAttachments(validos, 'drop');
    });

    // Si el arrastre sale de la ventana, limpia el resaltado
    window.addEventListener('dragend', function () { attachDropSetActive(false); });
    document.addEventListener('mouseleave', function () { if (dragDepth > 0) attachDropSetActive(false); });

    // ============================================================
    // Origen 4: ENLACES EXTERNOS (Fase D)
    // Solo desde el campo específico. NO se interceptan URLs pegadas
    // globalmente: pegar texto en cualquier otro sitio sigue igual.
    // ============================================================
    function attachAddLink() {
      if (attachBusy) return;
      if (!attachCanWriteHere()) return;

      var input = document.getElementById('drawer_attach_url');
      if (!input) return;

      var url = String(input.value || '').trim();
      if (url === '') { attachSetStatus('Escribe o pega una URL.', 'error'); return; }
      if (url.length > 2048) { attachSetStatus('La URL es demasiado larga (máximo 2048 caracteres).', 'error'); return; }

      var ctx = attachContext();
      if (!ctx.taskId || !ctx.csrf) { attachSetStatus('No se pudo identificar la tarea.', 'error'); return; }

      var fd = new FormData();
      fd.set('csrf', ctx.csrf);
      fd.set('task_id', ctx.taskId);
      fd.set('url', url);

      attachSetBusy(true);
      attachSetStatus('Añadiendo enlace…', 'info');

      fetch('../tasks/attachment_link.php', {
        method: 'POST', body: fd,
        headers: { 'X-Requested-With': 'fetch', 'Accept': 'application/json' }
      })
        .then(function (r) {
          return r.json().catch(function () { return null; })
            .then(function (data) { return { status: r.status, data: data }; });
        })
        .then(function (res) {
          var d = res.data;

          if (res.status === 403) { attachSetStatus('No tienes permiso para añadir enlaces en esta tarea.', 'error'); return; }
          if (res.status === 404) { attachSetStatus('La tarea ya no existe.', 'error'); return; }
          if (res.status === 422) {
            attachSetStatus((d && d.message) ? d.message : 'La URL no es válida.', 'error');
            return;
          }
          if (!d || d.ok !== true) { attachSetStatus('No se pudo añadir el enlace.', 'error'); return; }

          var prov = d.attachment && d.attachment.provider;
          showToast(prov ? ('🎬 Video de ' + (prov === 'youtube' ? 'YouTube' : 'Vimeo') + ' añadido') : '🔗 Enlace añadido');
          input.value = '';
          loadDrawer(ctx.taskId);
        })
        .catch(function () { attachSetStatus('Error de conexión al añadir el enlace.', 'error'); })
        .then(function () { attachSetBusy(false); });
    }

    document.addEventListener('click', function (ev) {
      var btn = ev.target.closest && ev.target.closest('[data-action="attach-add-link"]');
      if (!btn) return;
      ev.preventDefault(); ev.stopPropagation();
      attachAddLink();
    });

    // Enter en el campo de URL añade el enlace. Solo en ese campo.
    document.addEventListener('keydown', function (ev) {
      if (ev.key !== 'Enter') return;
      var el = ev.target;
      if (!el || el.id !== 'drawer_attach_url') return;
      ev.preventDefault();
      attachAddLink();
    });

    // ============================================================
    // VISOR DE IMAGEN (Fase E)
    // Sin librerías. Solo abre imágenes YA renderizadas en el drawer:
    // la fuente se copia del <img> de la tarjeta, nunca se construye
    // una URL a partir de un identificador arbitrario.
    // ============================================================
    var viewerLastFocus = null;

    function viewerEl() { return document.getElementById('fycImgViewer'); }

    function closeImgViewer() {
      var v = viewerEl();
      if (v && !v.hasAttribute('hidden')) {
        v.setAttribute('hidden', '');
        var img = document.getElementById('fycImgViewerImg');
        if (img) { img.src = ''; img.alt = ''; }
      }
      // El bloqueo del scroll se libera SIEMPRE, exista o no el modal.
      document.body.classList.remove('fyc-viewer-open');

      if (viewerLastFocus && document.contains(viewerLastFocus)) {
        try { viewerLastFocus.focus(); } catch (e) { /* elemento ya no enfocable */ }
      }
      viewerLastFocus = null;
    }

    function openImgViewer(triggerBtn) {
      var v = viewerEl();
      if (!v || !triggerBtn) return;

      // La imagen debe existir dentro del propio botón: así es imposible
      // abrir un adjunto que no esté ya pintado en esta tarea.
      var src = triggerBtn.querySelector('img');
      if (!src || !src.getAttribute('src')) return;

      var card = triggerBtn.closest('.fyc-attach-card');
      var dl   = card ? card.querySelector('a[download]') : null;

      var img   = document.getElementById('fycImgViewerImg');
      var title = document.getElementById('fycImgViewerTitle');
      var link  = document.getElementById('fycImgViewerDl');

      if (img) {
        img.src = src.getAttribute('src');
        img.alt = src.getAttribute('alt') || '';
      }
      // textContent, nunca innerHTML: el nombre no puede inyectar marcado.
      if (title) title.textContent = src.getAttribute('alt') || 'Imagen';
      if (link) {
        if (dl && dl.getAttribute('href')) {
          link.setAttribute('href', dl.getAttribute('href'));
          link.style.display = '';
        } else {
          link.style.display = 'none';
        }
      }

      viewerLastFocus = triggerBtn;
      v.removeAttribute('hidden');
      document.body.classList.add('fyc-viewer-open');

      var x = v.querySelector('.fyc-imgviewer-x');
      if (x) { try { x.focus(); } catch (e) { /* ignorado */ } }
    }

    // Abrir
    document.addEventListener('click', function (ev) {
      var btn = ev.target.closest && ev.target.closest('[data-action="attach-open"]');
      if (!btn) return;
      ev.preventDefault(); ev.stopPropagation();
      openImgViewer(btn);
    });

    // Cerrar: botón y clic en el fondo
    document.addEventListener('click', function (ev) {
      var c = ev.target.closest && ev.target.closest('[data-action="viewer-close"]');
      if (!c) return;
      ev.preventDefault(); ev.stopPropagation();
      closeImgViewer();
    });

    // Cerrar con Escape
    document.addEventListener('keydown', function (ev) {
      if (ev.key !== 'Escape') return;
      var v = viewerEl();
      if (!v || v.hasAttribute('hidden')) return;
      ev.preventDefault(); ev.stopPropagation();
      closeImgViewer();
    });

    // Que el foco no se escape por detrás del modal
    document.addEventListener('focusin', function (ev) {
      var v = viewerEl();
      if (!v || v.hasAttribute('hidden')) return;
      if (v.contains(ev.target)) return;
      var x = v.querySelector('.fyc-imgviewer-x');
      if (x) { try { x.focus(); } catch (e) { /* ignorado */ } }
    });

    // ---- Estados de error de los medios ----
    // 'error' no burbujea, por eso se captura en fase de captura.
    document.addEventListener('error', function (ev) {
      var el = ev.target;
      if (!el || !el.tagName) return;

      if (el.tagName === 'IMG' && el.getAttribute('data-action') === 'attach-img') {
        var btn = el.closest('.fyc-attach-imgbtn');
        if (btn) btn.classList.add('is-broken');
        return;
      }
      if (el.tagName === 'AUDIO' || el.tagName === 'VIDEO' || el.tagName === 'SOURCE') {
        var media = el.closest('.fyc-attach-audio, .fyc-attach-video');
        if (media) media.classList.add('is-failed');
      }
    }, true);

    // ---- Eliminación ----
    document.addEventListener('click', function (ev) {
      var btn = ev.target.closest && ev.target.closest('[data-action="attach-delete"]');
      if (!btn || attachBusy) return;
      ev.preventDefault(); ev.stopPropagation();

      var attId  = btn.getAttribute('data-attachment-id');
      var csrfEl = document.getElementById('drawer_csrf');
      var taskIdEl = document.getElementById('drawer_task_id');
      var csrf   = csrfEl ? csrfEl.value : (state.csrf || '');
      var taskId = taskIdEl ? String(taskIdEl.value || '') : '';
      if (!attId || !csrf) return;

      if (!window.confirm('¿Eliminar este adjunto? Esta acción no se puede deshacer.')) return;

      // Evita el doble clic y marca la tarjeta como "eliminando"
      attachSetBusy(true);
      btn.disabled = true;
      btn.style.opacity = '0.5';
      var cardEl = btn.closest('.fyc-attach-card');
      if (cardEl) cardEl.classList.add('is-deleting');

      var fd = new FormData();
      fd.set('csrf', csrf);
      fd.set('attachment_id', attId);

      fetch('../tasks/attachment_delete.php', {
        method: 'POST', body: fd,
        headers: { 'X-Requested-With': 'fetch', 'Accept': 'application/json' }
      })
        .then(function (r) {
          return r.json().catch(function () { return null; })
            .then(function (data) { return { status: r.status, data: data }; });
        })
        .then(function (res) {
          var d = res.data;
          if (res.status === 403) { attachSetStatus('No tienes permiso para eliminar este adjunto.', 'error'); return; }
          if (res.status === 404) { attachSetStatus('El adjunto ya no existe.', 'error'); loadDrawer(taskId); return; }
          if (!d || d.ok !== true) { attachSetStatus('No se pudo eliminar el adjunto.', 'error'); return; }

          showToast('🗑️ Adjunto eliminado');
          loadDrawer(taskId);
        })
        .catch(function () { attachSetStatus('Error de conexión al eliminar.', 'error'); })
        .then(function () {
          attachSetBusy(false);
          btn.disabled = false;
          btn.style.opacity = '';
          if (cardEl) cardEl.classList.remove('is-deleting');
        });
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
      var fromCol = task.closest('.col');
      state.dragSrcIsDone = fromCol ? fromCol.dataset.isDone === '1' : false;
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

      // Detectar reapertura: viene de columna done → va a columna no-done
      var dstIsDone = col.dataset.isDone === '1';
      if (state.dragSrcIsDone && !dstIsDone) {
        removePlaceholder();
        state.pendingReopen = { taskId: taskId, columnId: columnId, beforeTaskId: beforeTaskId };
        // Precargar fecha mínima y limpiar campos
        var fechaInp = document.getElementById('inputReopenFecha');
        var motivoInp = document.getElementById('inputReopenMotivo');
        if (fechaInp) { fechaInp.value = ''; fechaInp.style.borderColor = ''; }
        if (motivoInp) motivoInp.value = '';
        openModal('modalReopenTask');
        return;
      }

      var fd = new FormData();
      fd.set('csrf', state.csrf); fd.set('task_id', taskId); fd.set('board_id', state.boardId); fd.set('column_id', columnId);
      if (beforeTaskId > 0) fd.set('before_task_id', String(beforeTaskId));
      removePlaceholder();

      var moveToastMsg = dstIsDone ? '✅ Tarea completada' : '✅ Movida';
      function doMove() {
        fetch('../tasks/move.php', { method: 'POST', body: fd, headers: { 'X-Requested-With': 'fetch', 'Accept': 'application/json' } })
          .then(function (r) { return r.json().catch(function () { return null; }); })
          .then(function (data) {
            if (!data || data.ok !== true) { showToast('⚠️ No se pudo mover'); return; }
            showToast(moveToastMsg); reloadBoard();
          })
          .catch(function () { showToast('⚠️ Error moviendo'); });
      }

      if (dstIsDone) {
        var cardEl = root.querySelector('.task[data-task-id="' + taskId + '"]');
        if (cardEl) {
          var dstBody = getTasksContainer(col);
          if (dstBody) {
            cardEl.style.opacity = '1';
            dstBody.appendChild(cardEl);
          }
          cardEl.style.pointerEvents = 'none';
          cardEl.classList.add('task-completing');
          setTimeout(doMove, 550);
        } else {
          doMove();
        }
      } else {
        doMove();
      }
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

    // ---- Click en tarjeta → abrir drawer (editor oficial) ----
    root.addEventListener('click', function (ev) {
      if (ev.detail && ev.detail > 1) return;                              // ignorar dblclick
      if (ev.target.closest('.task-title')) return;                        // rename inline
      if (ev.target && (ev.target.tagName === 'INPUT' || ev.target.closest('.task-title input'))) return;
      if (ev.target.closest('[data-action="delete-task"]') || ev.target.closest('[data-action="open-task"]') || ev.target.closest('[data-action="col-menu"]')) return;
      var taskEl = ev.target.closest('.task'); if (!taskEl) return;
      var taskId = taskEl.getAttribute('data-task-id'); if (!taskId) return;
      loadDrawer(taskId);
    });

    // ============================================================
    // GESTIÓN DE COLUMNAS
    // ============================================================

    var colMenuTargetId   = null;
    var colMenuTargetName = null;
    var colMenuTargetIsDone = false;

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
      colMenuTargetId     = btn.getAttribute('data-column-id');
      colMenuTargetName   = btn.getAttribute('data-column-name');
      colMenuTargetIsDone = btn.getAttribute('data-is-done') === '1';
      var menu = document.getElementById('colContextMenu');
      if (!menu) return;
      // Actualizar etiqueta del item según estado actual
      var doneLabel = document.getElementById('colMenuSetDoneLabel');
      if (doneLabel) doneLabel.textContent = colMenuTargetIsDone ? 'Quitar finalización' : 'Marcar como finalización';
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

    // Menú → Marcar / quitar finalización
    document.addEventListener('click', function (ev) {
      if (!(ev.target && ev.target.closest && ev.target.closest('#colMenuSetDone'))) return;
      var menu = document.getElementById('colContextMenu');
      if (menu) menu.style.display = 'none';
      if (!colMenuTargetId) return;
      var newDone = colMenuTargetIsDone ? 0 : 1;
      var label   = newDone ? '✓ Columna marcada como finalización' : 'Columna sin finalización';
      columnAction({ action: 'set_done', column_id: colMenuTargetId, is_done: newDone }, function () {
        showToast(label);
      });
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
    // MODAL REAPERTURA DE TAREA
    // ============================================================

    // Cancelar reapertura — el card no se movió, el board queda igual
    document.addEventListener('click', function (ev) {
      if (!(ev.target && ev.target.id === 'btnCancelReopenTask')) return;
      state.pendingReopen = null;
      closeModal('modalReopenTask');
    });

    // Confirmar reapertura
    document.addEventListener('click', function (ev) {
      if (!(ev.target && ev.target.id === 'btnConfirmReopenTask')) return;
      if (!state.pendingReopen) { closeModal('modalReopenTask'); return; }

      var fechaInp  = document.getElementById('inputReopenFecha');
      var motivoInp = document.getElementById('inputReopenMotivo');
      var fecha  = fechaInp  ? fechaInp.value.trim()  : '';
      var motivo = motivoInp ? motivoInp.value.trim() : '';

      // Validación frontend: fecha obligatoria y >= hoy
      if (!fecha) {
        if (fechaInp) { fechaInp.style.borderColor = 'var(--fyc-red)'; fechaInp.focus(); }
        showToast('⚠️ Debes ingresar una nueva fecha límite');
        return;
      }
      var today = new Date(); today.setHours(0,0,0,0);
      var chosen = new Date(fecha + 'T00:00:00');
      if (chosen < today) {
        if (fechaInp) { fechaInp.style.borderColor = 'var(--fyc-red)'; fechaInp.focus(); }
        showToast('⚠️ La fecha debe ser hoy o posterior');
        return;
      }
      if (fechaInp) fechaInp.style.borderColor = '';

      var pr = state.pendingReopen;
      state.pendingReopen = null;
      closeModal('modalReopenTask');

      var fd = new FormData();
      fd.set('csrf',           state.csrf);
      fd.set('task_id',        pr.taskId);
      fd.set('board_id',       state.boardId);
      fd.set('column_id',      pr.columnId);
      fd.set('reopen_fecha',   fecha);
      fd.set('reopen_motivo',  motivo);
      if (pr.beforeTaskId > 0) fd.set('before_task_id', String(pr.beforeTaskId));

      fetch('../tasks/move.php', {
        method: 'POST', body: fd,
        headers: { 'X-Requested-With': 'fetch', 'Accept': 'application/json' }
      })
        .then(function (r) { return r.json().catch(function () { return null; }); })
        .then(function (data) {
          if (!data || data.ok !== true) {
            var msg = data && data.error === 'reopen_fecha_past'
              ? '⚠️ La fecha no puede ser anterior a hoy'
              : '⚠️ No se pudo reabrir la tarea';
            showToast(msg);
            return;
          }
          showToast('↩ Tarea reabierta');
          reloadBoard();
        })
        .catch(function () { showToast('⚠️ Error al reabrir'); });
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

    // Ventana de fechas de la columna «hecho»
    //
    // Delegado en document y registrado una sola vez desde
    // installListenersOnce, como el resto. Los controles los dibuja view.php
    // dentro de #boardMount y reloadBoard() los sustituye; enlazarlos allí
    // dejaría manejadores muertos en cada recarga.
    document.addEventListener('change', function (ev) {
      var sel = ev.target && ev.target.closest && ev.target.closest('[data-done-window]');
      if (!sel) return;
      var modo = sel.value;
      if (modo !== '7d' && modo !== '30d' && modo !== 'all' && modo !== 'custom') modo = '7d';
      filterState.doneWindow.mode = modo;
      var caja = sel.closest('[data-done-filter]');
      var fila = caja && caja.querySelector('[data-done-range]');
      if (fila) fila.style.display = (modo === 'custom') ? '' : 'none';
      applyFilters();
    });

    document.addEventListener('change', function (ev) {
      var inp = ev.target && ev.target.closest &&
                (ev.target.closest('[data-done-from]') || ev.target.closest('[data-done-to]'));
      if (!inp) return;
      var esDesde = inp.hasAttribute('data-done-from');
      if (esDesde) filterState.doneWindow.from = inp.value || '';
      else         filterState.doneWindow.to   = inp.value || '';
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
  // EVENTS POLL (real-time board updates)
  // ============================================================
  function stopEventsPoll() {
    if (eventsInterval) {
      clearInterval(eventsInterval);
      eventsInterval = null;
    }
  }

  function startEventsPoll(afterId) {
    stopEventsPoll();
    eventsAfterId = (typeof afterId === 'number' && afterId >= 0) ? afterId : 0;
    eventsInterval = setInterval(function () {
      if (!state.boardId) return;
      var url = './events_poll.php?board_id=' + encodeURIComponent(state.boardId)
              + '&after_id=' + eventsAfterId;
      fetch(url, { headers: { 'X-Requested-With': 'fetch' } })
        .then(function (r) { return r.ok ? r.json() : null; })
        .then(function (data) {
          if (!data || !Array.isArray(data.events) || data.events.length === 0) return;
          // Advance cursor so we never replay these events
          var maxId = data.events[data.events.length - 1].id;
          if (maxId > eventsAfterId) eventsAfterId = maxId;
          // Reload the board to reflect remote changes
          reloadBoard();
        })
        .catch(function () { /* network hiccup — try again next tick */ });
    }, 8000);
  }

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
    // La primera pintada la hace el servidor, y hasta ahora no había nada que
    // aplicar: los cuatro filtros nacen vacíos. La ventana de la columna de
    // «hecho» no: nace en 7 días, así que sin esto el recorte no se notaría
    // hasta que el usuario tocara algún control. reloadBoard() ya hacía este
    // mismo par de llamadas; init() se había quedado sin ellas.
    restoreFilterUI();
    applyFilters();
    console.log('[FCPlannerBoard] init OK board=', state.boardId);
  };

  window.FCPlannerBoard.runEmbedScripts   = runEmbedScripts;
  window.FCPlannerBoard.startEventsPoll   = startEventsPoll;
  window.FCPlannerBoard.stopEventsPoll    = stopEventsPoll;

  // Se expone para que el código que viaja dentro del cajón pueda recargarlo
  // por el mismo camino que lo cargó. Antes el script de etiquetas hacía su
  // propio fetch + innerHTML, y esa segunda vía volvía a dejar los scripts
  // sin ejecutar: el cajón funcionaba al abrirlo y se quedaba muerto tras
  // crear una etiqueta. Una sola puerta de entrada evita repetir el fallo.
  window.FCPlannerBoard.loadDrawer        = loadDrawer;

  // Resalta visualmente una tarjeta y hace scroll hacia ella
  function highlightTask(el) {
    el.scrollIntoView({ behavior: 'smooth', block: 'nearest', inline: 'nearest' });
    var prev = el.style.boxShadow;
    el.style.transition  = 'box-shadow 0.3s ease';
    el.style.boxShadow   = '0 0 0 3px var(--fyc-red)';
    setTimeout(function () {
      el.style.boxShadow  = prev || '';
    }, 2500);
  }

  // Navega a una tarea: resalta la tarjeta (si está en el DOM) y abre el drawer
  window.FCPlannerBoard.openTask = function (taskId) {
    if (!taskId) return;
    if (state.root) {
      var card = state.root.querySelector('[data-task-id="' + taskId + '"]');
      if (card) highlightTask(card);
    }
    loadDrawer(taskId);
  };

})();
