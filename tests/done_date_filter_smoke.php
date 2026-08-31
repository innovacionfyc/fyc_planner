<?php
/**
 * tests/done_date_filter_smoke.php
 *
 * Ventana de fechas de la columna de «hecho» (Fase F9).
 *
 * Ejecutar SOLO en local:
 *   php tests/done_date_filter_smoke.php
 *
 * La columna de finalización acumula todo lo terminado desde siempre. Por
 * defecto ahora enseña solo los últimos 7 días. Esta suite vigila que ese
 * recorte no pierda nada por el camino:
 *
 *   · las tarjetas se ESCONDEN, no se dejan de cargar (el cálculo de posición
 *     al arrastrar mira el DOM y necesita la lista completa);
 *   · una tarea sin completed_at no desaparece nunca;
 *   · el buscador encuentra lo que la ventana esconde;
 *   · el contador dice la verdad en cada combinación;
 *   · un rango al revés no vacía la columna;
 *   · el estado sobrevive a reloadBoard().
 *
 * El comportamiento se comprueba ejecutando las funciones REALES extraídas de
 * board-view.js sobre un DOM simulado, no leyendo el fuente. Las partes de
 * HTTP necesitan Apache y MySQL. No deja residuos.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Este script solo puede ejecutarse desde CLI.\n");
}

$ROOT = dirname(__DIR__);
$JS   = $ROOT . '/public/assets/board-view.js';
$VIEW = $ROOT . '/public/boards/view.php';
$CSS  = $ROOT . '/public/assets/theme.css';

const BASE_URL    = 'http://localhost/fyc_planner/public';
const SESSION_DIR = 'C:/laragon/tmp';
const QA_NOMBRE   = 'QA F9 VENTANA';
// Etiqueta corta de esta suite. Entra en el correo de sus usuarios QA
// (qa.<suite>.<aleatorio>@local.test) y permite retirar restos de una
// ejecucion que se interrumpiera antes de limpiar.
const QA_SUITE    = 'donefilter';

$PASS = 0;
$FAIL = 0;

function ok(string $n, string $d = ''): void
{
    global $PASS;
    $PASS++;
    printf("  [OK]    %-58s %s\n", $n, $d);
}

function ko(string $n, string $d = ''): void
{
    global $FAIL;
    $FAIL++;
    printf("  [FALLO] %-58s %s\n", $n, $d);
}

function chk(string $n, bool $c, string $d = ''): void
{
    $c ? ok($n, $d) : ko($n, $d);
}

function section(string $t): void
{
    echo "\n──────────────────────────────────────────────────────────────────────────────\n";
    echo " $t\n";
    echo "──────────────────────────────────────────────────────────────────────────────\n";
}

/** Lee un archivo normalizando CRLF: los patrones se escriben con \n. */
function leer(string $ruta): string
{
    return str_replace("\r\n", "\n", (string) file_get_contents($ruta));
}

/**
 * Extrae una función completa por conteo de llaves.
 *
 * Con expresiones regulares no se puede: el cuerpo tiene llaves anidadas y
 * cualquier patrón perezoso corta en la primera. Así la prueba ejecuta el
 * código de verdad y no una copia que podría quedarse atrás.
 */
function extraer_funcion(string $src, string $nombre): string
{
    $ini = strpos($src, 'function ' . $nombre . '(');
    if ($ini === false)
        return '';
    $llave = strpos($src, '{', $ini);
    if ($llave === false)
        return '';
    $prof = 0;
    $len  = strlen($src);
    for ($i = $llave; $i < $len; $i++) {
        if ($src[$i] === '{')
            $prof++;
        elseif ($src[$i] === '}') {
            $prof--;
            if ($prof === 0)
                return substr($src, $ini, $i - $ini + 1);
        }
    }
    return '';
}

echo "══════════════════════════════════════════════════════════════════════════════\n";
echo " F9 · VENTANA DE FECHAS DE LA COLUMNA «HECHO»\n";
echo "══════════════════════════════════════════════════════════════════════════════\n";
echo " PHP   : " . PHP_VERSION . "\n";
echo " Node  : " . trim((string) shell_exec('node --version 2>&1')) . "\n";
echo " Fecha : " . date('Y-m-d H:i:s') . "\n";

$src  = leer($JS);
$view = leer($VIEW);
$css  = leer($CSS);

// ═════════════════════════════════════════════════════════════
section('1-9 · EL DATO LLEGA AL NAVEGADOR');

chk('1. get_tasks_by_column pide completed_at',
    str_contains($view, '$extra = tiene_completed_at($conn)')
    && substr_count($view, 'asignado_nombre$extra') === 2,
    'en las dos variantes de orden');

chk('2. Solo lo pide si la columna existe',
    str_contains($view, "SHOW COLUMNS FROM tasks LIKE 'completed_at'"),
    'esquema antiguo: no rompe la consulta');

chk('3. La tarjeta publica data-completed',
    (bool) preg_match('/data-completed="<\?=\s*!empty\(\$t\[\'completed_at\'\]\)/', $view));

chk('4. Se publica solo la fecha, sin la hora',
    (bool) preg_match('/data-completed=.*substr\(\(string\) \$t\[\'completed_at\'\], 0, 10\)/', $view),
    'YYYY-MM-DD');

chk('5. El servidor manda su «hoy» en #kanban',
    str_contains($view, 'data-today="<?= h(date(\'Y-m-d\')) ?>"'),
    'no se usa el reloj del navegador');

chk('6. El selector se dibuja solo en la columna de hecho',
    (bool) preg_match('/if \(\$c\[\'is_done\'\] && tiene_completed_at\(\$conn\)\)/', $view));

chk('7. Las cuatro opciones están',
    substr_count($view, 'value="7d"') === 1 && substr_count($view, 'value="30d"') === 1
    && substr_count($view, 'value="all"') === 1 && substr_count($view, 'value="custom"') === 1);

chk('8. El rango personalizado tiene dos fechas',
    str_contains($view, 'data-done-from') && str_contains($view, 'data-done-to'));

// Lección de F8.2.1: la cabecera vive dentro de #boardMount y reloadBoard()
// la reemplaza. Un listener enlazado en una plantilla dinámica se pierde —o
// se acumula— en cada recarga.
$bloqueVentana = '';
if (preg_match('/<div class="fyc-done-filter".*?<\/div>\s*<\?php endif; \?>/s', $view, $m)) {
    $bloqueVentana = $m[0];
}
chk('9. El bloque no trae JS propio',
    $bloqueVentana !== '' && !str_contains($bloqueVentana, '<script')
    && !str_contains($bloqueVentana, 'onchange') && !str_contains($bloqueVentana, 'onclick'),
    'todo delegado en board-view.js');

// ═════════════════════════════════════════════════════════════
section('10-16 · ESTADO Y CONTROLES');

chk('10. doneWindow vive en filterState',
    (bool) preg_match('/filterState = \{.*?doneWindow: \{ mode: \'7d\'/s', $src),
    'junto a los otros cuatro');

chk('11. Arranca en 7 días',
    (bool) preg_match('/doneWindow: \{ mode: \'7d\', from: \'\', to: \'\' \}/', $src));

chk('12. Los listeners están delegados en document',
    substr_count($src, "document.addEventListener('change', function (ev) {") >= 3
    && str_contains($src, "ev.target.closest('[data-done-window]')"));

$instalar = extraer_funcion($src, 'installListenersOnce');
chk('13. Y se registran una sola vez',
    str_contains($instalar, '[data-done-window]') && str_contains($instalar, '[data-done-from]'),
    'dentro de installListenersOnce');

$restore = extraer_funcion($src, 'restoreFilterUI');
chk('14. restoreFilterUI repinta el selector',
    str_contains($restore, '[data-done-filter]') && str_contains($restore, 'sel.value = w.mode'));

// Los otros cuatro filtros nacen vacíos, así que init() podía permitirse no
// aplicar nada. La ventana nace en 7 días: sin esta llamada, la primera
// pintada del servidor se vería entera y el recorte solo aparecería al tocar
// un control. Se detectó probando en el navegador, no en el DOM simulado.
$init = '';
if (preg_match('/window\.FCPlannerBoard\.init = function \(root\) \{.*?\n  \};/s', $src, $mi)) {
    $init = $mi[0];
}
chk('15. init() aplica los filtros en la primera pintada',
    $init !== '' && str_contains($init, 'restoreFilterUI();') && str_contains($init, 'applyFilters();'),
    'igual que reloadBoard()');

chk('16. La ventana NO cuenta como filtro activo',
    !str_contains(extraer_funcion($src, 'hasActiveFilter'), 'doneWindow'),
    '«Limpiar filtros» no la toca');

// ═════════════════════════════════════════════════════════════
section('17-20 · CONTROLES TÁCTILES Y ESTILO (F8)');

chk('17. El selector llega a 44 px',
    (bool) preg_match('/\.fyc-done-window \{[^}]*min-height: 44px;/s', $css));

chk('18. Las dos fechas también',
    (bool) preg_match('/\.fyc-done-date \{[^}]*min-height: 44px;/s', $css));

chk('19. El vacío se maqueta por clase, no en línea',
    (bool) preg_match('/\.fyc-done-empty \{[^}]*flex-direction: column;/s', $css)
    && str_contains($view, 'class="empty fyc-done-empty" data-done-empty style="display:none;"'),
    'style.display = \'\' no debe romperlo');

$nuevos = 0;
if (preg_match('/F9 · VENTANA DE FECHAS.*$/s', $css, $m9)) {
    $nuevos = substr_count($m9[0], '!important');
}
chk('20. El bloque nuevo no usa !important', $nuevos === 0, "encontrados=$nuevos");

// ═════════════════════════════════════════════════════════════
section('21-58 · COMPORTAMIENTO REAL (funciones extraídas del fuente)');

// Se ejecutan las funciones tal cual están en board-view.js sobre un DOM
// simulado. Si alguien las cambia, esta parte lo nota.
$fns = [];
foreach ([
    'dosDig', 'sumarDias', 'serverToday', 'doneWindowBounds', 'rangoPersonalizadoInvalido',
    'esColumnaHecho', 'etiquetaVentana', 'hasActiveFilter', 'applyFilters', 'restoreFilterUI',
    'getTasksContainer', 'updatePlaceholderPosition', 'computeBeforeTaskIdFromPlaceholder',
    'initPrioBtnClasses'
] as $n) {
    $f = extraer_funcion($src, $n);
    if ($f === '') {
        ko('21. Se extraen las funciones del fuente', "falta: $n");
        echo "\n  RESULTADO: $PASS correctas, " . ($FAIL + 1) . " fallidas\n\n";
        exit(1);
    }
    $fns[$n] = $f;
}
preg_match('/var filterState = \{.*?\n  \};/s', $src, $mFs);
$declFilterState = $mFs[0] ?? '';
if ($declFilterState === '') {
    ko('21. Se extraen las funciones del fuente', 'no se pudo aislar filterState');
    echo "\n  RESULTADO: $PASS correctas, " . ($FAIL + 1) . " fallidas\n\n";
    exit(1);
}
ok('21. Se extraen las funciones del fuente', count($fns) . ' funciones + filterState');

$harness = <<<'JS'
'use strict';
// ── DOM simulado, mínimo pero honesto ────────────────────────
// Solo implementa lo que el código bajo prueba usa de verdad. Si tocara algo
// no simulado, reventaría de forma ruidosa en vez de pasar por casualidad.
function El(tag, attrs, classes) {
  this.tag = tag; this.attrs = attrs || {}; this.classes = classes || [];
  this.children = []; this.parent = null; this.style = {}; this.textContent = '';
  this._rect = { top: 0, height: 0 };
}
El.prototype.add = function (h) { h.parent = this; this.children.push(h); return h; };
El.prototype.getAttribute = function (n) {
  return Object.prototype.hasOwnProperty.call(this.attrs, n) ? this.attrs[n] : null;
};
El.prototype.setAttribute = function (n, v) { this.attrs[n] = v; };
El.prototype.hasAttribute = function (n) { return Object.prototype.hasOwnProperty.call(this.attrs, n); };
El.prototype.getBoundingClientRect = function () {
  // Una tarjeta con display:none mide 0x0. Es justo lo que hace el navegador,
  // y de ello depende que el arrastre no se enganche en las ocultas.
  if (this.style.display === 'none') return { top: 0, height: 0, bottom: 0 };
  return { top: this._rect.top, height: this._rect.height, bottom: this._rect.top + this._rect.height };
};
Object.defineProperty(El.prototype, 'classList', {
  get: function () {
    var self = this;
    return { contains: function (c) { return self.classes.indexOf(c) !== -1; },
             add: function (c) { if (self.classes.indexOf(c) === -1) self.classes.push(c); } };
  }
});
Object.defineProperty(El.prototype, 'dataset', {
  get: function () {
    var self = this;
    return { get isDone() { return self.getAttribute('data-is-done'); } };
  }
});
// computeBeforeTaskIdFromPlaceholder comprueba placeholder.parentNode, que es
// como se llama en el DOM de verdad. Sin este alias la prueba mediría el
// simulacro, no el código.
Object.defineProperty(El.prototype, 'parentNode', {
  get: function () { return this.parent; }
});
Object.defineProperty(El.prototype, 'nextElementSibling', {
  get: function () {
    if (!this.parent) return null;
    var i = this.parent.children.indexOf(this);
    return (i >= 0 && i + 1 < this.parent.children.length) ? this.parent.children[i + 1] : null;
  }
});

function coincide(el, sel) {
  return sel.split(',').some(function (parte) {
    parte = parte.trim();
    var toks = parte.match(/(\[[^\]]+\]|\.[A-Za-z0-9_-]+|#[A-Za-z0-9_-]+)/g) || [];
    return toks.every(function (t) {
      if (t.charAt(0) === '.') return el.classes.indexOf(t.slice(1)) !== -1;
      if (t.charAt(0) === '#') return el.getAttribute('id') === t.slice(1);
      var m = t.slice(1, -1).split('=');
      if (m.length === 1) return el.hasAttribute(m[0]);
      return el.getAttribute(m[0]) === m[1].replace(/^["']|["']$/g, '');
    });
  });
}
function descendientes(el, acc) {
  acc = acc || [];
  el.children.forEach(function (h) { acc.push(h); descendientes(h, acc); });
  return acc;
}
El.prototype.querySelectorAll = function (sel) {
  return descendientes(this).filter(function (e) { return coincide(e, sel); });
};
El.prototype.querySelector = function (sel) { return this.querySelectorAll(sel)[0] || null; };
El.prototype.closest = function (sel) {
  var n = this;
  while (n) { if (coincide(n, sel)) return n; n = n.parent; }
  return null;
};
El.prototype.insertBefore = function (nodo, ref) {
  if (nodo.parent) { var j = nodo.parent.children.indexOf(nodo); if (j >= 0) nodo.parent.children.splice(j, 1); }
  var i = this.children.indexOf(ref);
  nodo.parent = this;
  this.children.splice(i < 0 ? this.children.length : i, 0, nodo);
};
El.prototype.appendChild = function (nodo) {
  if (nodo.parent) { var j = nodo.parent.children.indexOf(nodo); if (j >= 0) nodo.parent.children.splice(j, 1); }
  nodo.parent = this; this.children.push(nodo);
};

var raiz = null;
var document = {
  querySelectorAll: function (sel) { return raiz ? raiz.querySelectorAll(sel) : []; },
  querySelector:    function (sel) { return raiz ? raiz.querySelector(sel) : null; },
  getElementById:   function (id)  { return raiz ? raiz.querySelector('#' + id) : null; },
  createElement:    function (t)   { return new El(t, {}, []); }
};

__FILTERSTATE__
__FNS__

// ── Constructor del tablero de pruebas ───────────────────────
// Una columna normal y una de «hecho» con tarjetas de fechas variadas.
// HOY se fija a 2026-08-15 desde data-today, para que la prueba no dependa
// del día en que se ejecute.
function construirDOM(tareasHecho) {
  raiz = new El('body', {}, []);
  var kanban = raiz.add(new El('div', { id: 'kanban', 'data-today': '2026-08-15' }, []));

  var colTodo = kanban.add(new El('div', { 'data-column-id': '1', 'data-is-done': '0' }, ['col', 'fyc-col']));
  colTodo.add(new El('span', {}, ['cnt']));
  var cuerpoTodo = colTodo.add(new El('div', {}, ['tasks']));
  cuerpoTodo.add(new El('div', { 'data-task-id': '900', 'data-titulo': 'pendiente uno',
    'data-prioridad': 'med', 'data-assignee': '', 'data-tags': '[]', 'data-completed': '' }, ['task', 'fyc-card']));

  var colDone = kanban.add(new El('div', { 'data-column-id': '2', 'data-is-done': '1' }, ['col', 'fyc-col']));
  colDone.add(new El('span', {}, ['cnt']));
  var caja = colDone.add(new El('div', { 'data-done-filter': '' }, ['fyc-done-filter']));
  var sel  = caja.add(new El('select', { 'data-done-window': '' }, ['fyc-done-window']));
  sel.value = '7d';
  var fila = caja.add(new El('div', { 'data-done-range': '' }, ['fyc-done-range']));
  fila.style.display = 'none';
  var desde = fila.add(new El('input', { 'data-done-from': '' }, ['fyc-done-date'])); desde.value = '';
  var hasta = fila.add(new El('input', { 'data-done-to': '' },   ['fyc-done-date'])); hasta.value = '';
  caja.add(new El('div', { 'data-done-count': '' }, ['fyc-done-count']));

  var cuerpoDone = colDone.add(new El('div', {}, ['tasks']));
  var vacio = cuerpoDone.add(new El('div', { 'data-done-empty': '' }, ['empty', 'fyc-done-empty']));
  vacio.style.display = 'none';
  vacio.add(new El('span', { 'data-done-empty-msg': '' }, []));

  var y = 100;
  tareasHecho.forEach(function (t) {
    var c = cuerpoDone.add(new El('div', {
      'data-task-id': String(t.id), 'data-titulo': t.titulo, 'data-prioridad': t.prio || 'med',
      'data-assignee': '', 'data-tags': '[]', 'data-completed': t.fin
    }, ['task', 'fyc-card']));
    c._rect = { top: y, height: 40 }; y += 50;
  });
  return { raiz: raiz, colDone: colDone, cuerpoDone: cuerpoDone, sel: sel, desde: desde, hasta: hasta, fila: fila };
}

var TAREAS = [
  { id: 1, titulo: 'reciente uno',  fin: '2026-08-15' },   // hoy
  { id: 2, titulo: 'reciente dos',  fin: '2026-08-10' },   // dentro de 7
  { id: 3, titulo: 'media',         fin: '2026-08-01' },   // dentro de 30
  { id: 4, titulo: 'antigua',       fin: '2026-05-20' },   // fuera de todo
  { id: 5, titulo: 'sin fecha',     fin: '' }              // nunca se oculta
];

function estado(d) {
  var cards = d.cuerpoDone.querySelectorAll('.task.fyc-card');
  return {
    visibles: cards.filter(function (c) { return c.style.display !== 'none'; })
                   .map(function (c) { return Number(c.getAttribute('data-task-id')); }),
    ocultas:  cards.filter(function (c) { return c.style.display === 'none'; })
                   .map(function (c) { return Number(c.getAttribute('data-task-id')); }),
    contador: d.colDone.querySelector('[data-done-count]').textContent,
    cnt:      d.colDone.querySelector('.cnt').textContent,
    vacio:    d.colDone.querySelector('[data-done-empty]').style.display,
    mensaje:  d.colDone.querySelector('[data-done-empty-msg]').textContent,
    bordeDesde: d.desde.style.borderColor || ''
  };
}

function reset() {
  filterState.activePrios = {}; filterState.activeTagIds = {};
  filterState.activeAssignee = ''; filterState.searchText = '';
  filterState.doneWindow = { mode: '7d', from: '', to: '' };
}

var R = {};

// 7 días por defecto
reset();
var d = construirDOM(TAREAS); applyFilters(); R.def = estado(d);

// 30 días
reset(); filterState.doneWindow.mode = '30d';
d = construirDOM(TAREAS); applyFilters(); R.d30 = estado(d);

// Todo
reset(); filterState.doneWindow.mode = 'all';
d = construirDOM(TAREAS); applyFilters(); R.todo = estado(d);

// Rango personalizado válido
reset(); filterState.doneWindow = { mode: 'custom', from: '2026-07-25', to: '2026-08-05' };
d = construirDOM(TAREAS); applyFilters(); R.rango = estado(d);

// Rango al revés
reset(); filterState.doneWindow = { mode: 'custom', from: '2026-08-30', to: '2026-08-01' };
d = construirDOM(TAREAS); applyFilters(); R.malo = estado(d);

// Rango a medio escribir
reset(); filterState.doneWindow = { mode: 'custom', from: '2026-08-01', to: '' };
d = construirDOM(TAREAS); applyFilters(); R.medio = estado(d);

// Búsqueda que cae FUERA de la ventana
reset(); filterState.searchText = 'antigua';
d = construirDOM(TAREAS); applyFilters(); R.buscaFuera = estado(d);

// Búsqueda que cae DENTRO de la ventana
reset(); filterState.searchText = 'reciente';
d = construirDOM(TAREAS); applyFilters(); R.buscaDentro = estado(d);

// Ventana vacía: todo fuera de rango
reset(); filterState.doneWindow = { mode: 'custom', from: '2026-01-01', to: '2026-01-02' };
d = construirDOM(TAREAS); applyFilters(); R.vacia = estado(d);

// Combinado: ventana + filtro de prioridad
reset(); filterState.activePrios = { urgent: true };
d = construirDOM([
  { id: 1, titulo: 'urgente reciente', fin: '2026-08-14', prio: 'urgent' },
  { id: 2, titulo: 'normal reciente',  fin: '2026-08-14', prio: 'med' },
  { id: 3, titulo: 'urgente antigua',  fin: '2026-01-01', prio: 'urgent' }
]);
applyFilters(); R.combi = estado(d);

// ── Supervivencia a reloadBoard ──────────────────────────────
// reloadBoard reemplaza el HTML: se reconstruye el DOM desde cero (el selector
// vuelve a su primera opción) sin tocar filterState, y se llama a
// restoreFilterUI como hace el código real.
reset(); filterState.doneWindow = { mode: 'custom', from: '2026-07-25', to: '2026-08-05' };
d = construirDOM(TAREAS); applyFilters();
var antes = estado(d);
d = construirDOM(TAREAS);              // <- el DOM nuevo nace en '7d'
var selAntesDeRestaurar = d.sel.value;
restoreFilterUI(); applyFilters();
R.recarga = {
  selAntes: selAntesDeRestaurar,
  selDespues: d.sel.value,
  desde: d.desde.value, hasta: d.hasta.value,
  filaVisible: d.fila.style.display,
  visiblesAntes: antes.visibles, visiblesDespues: estado(d).visibles
};

// ── Arrastre con tarjetas ocultas ────────────────────────────
// El riesgo real: si el cálculo de posición se enganchara en una tarjeta
// oculta, el orden al soltar saldría mal. Se ejecutan las funciones reales.
reset();
d = construirDOM(TAREAS); applyFilters();       // 3, 4 y 5 quedan ocultas con 7d
var placeholder = new El('div', {}, ['fyc-placeholder']);
var draggingTaskId = null;

function soltarEn(clientY) {
  updatePlaceholderPosition(d.colDone, clientY);
  return computeBeforeTaskIdFromPlaceholder(d.colDone);
}
var visiblesDom = d.cuerpoDone.querySelectorAll('.task.fyc-card')
                   .filter(function (c) { return c.style.display !== 'none'; });
R.arrastre = {
  cardsEnDom: d.cuerpoDone.querySelectorAll('.task.fyc-card').length,
  visibles: visiblesDom.length,
  // Soltar por encima de la primera visible (top=100)
  arriba: soltarEn(105),
  // Soltar entre la primera (100-140) y la segunda (150-190)
  enMedio: soltarEn(155),
  // Soltar al final del todo
  abajo: soltarEn(9999)
};
// ¿El hueco quedó dentro del contenedor y NO delante de una oculta?
var sig = placeholder.nextElementSibling;
R.arrastre.siguienteEsOculta = !!(sig && sig.classes.indexOf('task') !== -1 && sig.style.display === 'none');

// Comprobación de que las ocultas SIGUEN en el DOM (requisito 1)
R.arrastre.ocultasSiguenEnDom = d.cuerpoDone.querySelectorAll('.task.fyc-card')
  .filter(function (c) { return c.style.display === 'none'; }).length;

// ── Ayudantes de fecha ───────────────────────────────────────
reset();
construirDOM(TAREAS);
R.fechas = {
  hoy: serverToday(),
  menos6: sumarDias('2026-08-15', -6),
  cruzaMes: sumarDias('2026-03-01', -1),
  bisiesto: sumarDias('2028-03-01', -1),
  b7: (function () { filterState.doneWindow = { mode: '7d' };  return doneWindowBounds(); })(),
  b30: (function () { filterState.doneWindow = { mode: '30d' }; return doneWindowBounds(); })(),
  bAll: (function () { filterState.doneWindow = { mode: 'all' }; return doneWindowBounds(); })(),
  bMalo: (function () { filterState.doneWindow = { mode: 'custom', from: '2026-08-30', to: '2026-08-01' }; return doneWindowBounds(); })()
};

console.log(JSON.stringify(R));
JS;

$harness = str_replace(
    ['__FILTERSTATE__', '__FNS__'],
    [$declFilterState, implode("\n", $fns)],
    $harness
);

$tmpJs = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'fyc_f9_harness_' . bin2hex(random_bytes(4)) . '.js';
file_put_contents($tmpJs, $harness);
$salida = (string) shell_exec('node ' . escapeshellarg($tmpJs) . ' 2>&1');
@unlink($tmpJs);

$R = json_decode(trim($salida), true);
if (!is_array($R)) {
    ko('22. El banco de pruebas se ejecuta', substr(trim($salida), 0, 200));
    echo "\n  RESULTADO: $PASS correctas, $FAIL fallidas\n\n";
    exit(1);
}
ok('22. El banco de pruebas se ejecuta', 'DOM simulado + funciones reales');

// ---- Ayudantes de fecha ----
$f = $R['fechas'];
chk('23. «Hoy» sale de data-today, no del reloj local',
    $f['hoy'] === '2026-08-15', 'hoy=' . $f['hoy']);

chk('24. 7 días = hoy y los seis anteriores',
    $f['b7']['from'] === '2026-08-09' && $f['b7']['to'] === '2026-08-15',
    $f['b7']['from'] . ' → ' . $f['b7']['to']);

chk('25. 30 días cuenta igual',
    $f['b30']['from'] === '2026-07-17' && $f['b30']['to'] === '2026-08-15',
    $f['b30']['from'] . ' → ' . $f['b30']['to']);

chk('26. «Todo» no pone límites',
    $f['bAll'] === null, 'bounds=null');

chk('27. El cálculo cruza meses y años bisiestos',
    $f['cruzaMes'] === '2026-02-28' && $f['bisiesto'] === '2028-02-29',
    'mar→feb=' . $f['cruzaMes'] . '  bisiesto=' . $f['bisiesto']);

// ---- La ventana ----
chk('28. Por defecto solo se ven los últimos 7 días',
    $R['def']['visibles'] === [1, 2, 5], 'visibles=' . json_encode($R['def']['visibles']));

chk('29. 30 días amplía sin traerlo todo',
    $R['d30']['visibles'] === [1, 2, 3, 5], 'visibles=' . json_encode($R['d30']['visibles']));

chk('30. «Todo» las enseña todas',
    $R['todo']['visibles'] === [1, 2, 3, 4, 5] && $R['todo']['ocultas'] === [],
    'ocultas=' . count($R['todo']['ocultas']));

chk('31. El rango personalizado recorta por ambos extremos',
    $R['rango']['visibles'] === [3, 5], 'visibles=' . json_encode($R['rango']['visibles']));

// ---- Requisito 4: sin completed_at ----
chk('32. La tarea SIN fecha aparece en las cinco ventanas',
    in_array(5, $R['def']['visibles'], true) && in_array(5, $R['d30']['visibles'], true)
    && in_array(5, $R['todo']['visibles'], true) && in_array(5, $R['rango']['visibles'], true)
    && in_array(5, $R['vacia']['visibles'], true),
    'nunca desaparece');

chk('33. Ni siquiera cuando la ventana no deja nada más',
    $R['vacia']['visibles'] === [5],
    'visibles=' . json_encode($R['vacia']['visibles']));

// ---- Rango inválido ----
chk('34. Un rango al revés no vacía la columna',
    count($R['malo']['visibles']) === 5, 'visibles=' . count($R['malo']['visibles']));

chk('35. Y se marca el campo en rojo',
    $R['malo']['bordeDesde'] !== '', 'borde=' . $R['malo']['bordeDesde']);

chk('36. Un rango a medio escribir tampoco oculta nada',
    count($R['medio']['visibles']) === 5 && $R['medio']['bordeDesde'] === '',
    'sin marcar en rojo: aún lo está escribiendo');

// ---- Requisito 2: el texto manda ----
chk('37. Buscar encuentra lo que la ventana esconde',
    $R['buscaFuera']['visibles'] === [4],
    'visibles=' . json_encode($R['buscaFuera']['visibles']));

chk('38. Y el contador avisa de cuántas vienen de fuera',
    str_contains($R['buscaFuera']['contador'], '1 fuera del rango'),
    $R['buscaFuera']['contador']);

chk('39. Si no hay ninguna fuera, no se dice nada',
    !str_contains($R['buscaDentro']['contador'], 'fuera del rango'),
    $R['buscaDentro']['contador']);

chk('40. Sin buscar tampoco aparece el aviso',
    !str_contains($R['def']['contador'], 'fuera del rango'),
    $R['def']['contador']);

// ---- El contador dice la verdad ----
chk('41. Contador con la ventana por defecto',
    $R['def']['contador'] === 'viendo 3 de 5', $R['def']['contador']);

chk('42. Contador con «Todo»',
    $R['todo']['contador'] === 'viendo 5 de 5', $R['todo']['contador']);

chk('43. Contador con rango personalizado',
    $R['rango']['contador'] === 'viendo 2 de 5', $R['rango']['contador']);

chk('44. Contador buscando fuera de la ventana',
    $R['buscaFuera']['contador'] === 'viendo 1 de 5 · 1 fuera del rango',
    $R['buscaFuera']['contador']);

chk('45. Contador combinando ventana y prioridad',
    $R['combi']['contador'] === 'viendo 1 de 3', $R['combi']['contador']);

chk('46. La ventana y los filtros se combinan con Y',
    $R['combi']['visibles'] === [1], 'visibles=' . json_encode($R['combi']['visibles']));

// ---- Mensaje de vacío ----
chk('47. Con la ventana vacía sale el mensaje correcto',
    str_contains($R['vacia']['mensaje'], 'Sin tareas terminadas en el rango elegido'),
    $R['vacia']['mensaje']);

chk('48. Y con 7 días lo dice con esas palabras',
    str_contains($R['def']['mensaje'], 'en los últimos 7 días'),
    $R['def']['mensaje']);

// ---- Requisito: sobrevive a reloadBoard ----
$rec = $R['recarga'];
chk('49. Tras recargar, el DOM nuevo nace en «7d»',
    $rec['selAntes'] === '7d', 'selector recién dibujado=' . $rec['selAntes']);

chk('50. restoreFilterUI devuelve el modo guardado',
    $rec['selDespues'] === 'custom', 'selector=' . $rec['selDespues']);

chk('51. Y también las dos fechas',
    $rec['desde'] === '2026-07-25' && $rec['hasta'] === '2026-08-05',
    $rec['desde'] . ' → ' . $rec['hasta']);

chk('52. La fila de fechas reaparece visible',
    $rec['filaVisible'] === '', 'display=' . var_export($rec['filaVisible'], true));

chk('53. Se ven exactamente las mismas tarjetas que antes',
    $rec['visiblesAntes'] === $rec['visiblesDespues'],
    json_encode($rec['visiblesAntes']) . ' = ' . json_encode($rec['visiblesDespues']));

// ---- Requisito 1: arrastrar con tarjetas ocultas ----
$a = $R['arrastre'];
chk('54. Las tarjetas ocultas SIGUEN en el DOM',
    $a['cardsEnDom'] === 5 && $a['ocultasSiguenEnDom'] === 2,
    'en el DOM=' . $a['cardsEnDom'] . ' ocultas=' . $a['ocultasSiguenEnDom']);

chk('55. Soltar arriba apunta a la primera visible',
    $a['arriba'] === 1, 'before_task_id=' . $a['arriba']);

chk('56. Soltar en medio apunta a la segunda visible',
    $a['enMedio'] === 2, 'before_task_id=' . $a['enMedio']);

chk('57. Soltar al final no apunta a ninguna',
    $a['abajo'] === 0, 'before_task_id=' . $a['abajo'] . ' (se añade al final)');

chk('58. El hueco nunca queda delante de una tarjeta oculta',
    $a['siguienteEsOculta'] === false,
    'una oculta mide 0x0 y jamás se elige como punto de inserción');

// ═════════════════════════════════════════════════════════════
section('59-65 · COMPROBACIÓN REAL POR HTTP');

$dbCfg = $ROOT . '/config/db.php';
if (!is_file($dbCfg)) {
    ko('59. Hay configuración de base de datos', 'falta config/db.php');
} else {
    require $dbCfg;
    require_once __DIR__ . '/_qa_users.php';
    /** @var mysqli $conn */
    @$conn->query("DELETE FROM boards WHERE nombre LIKE '" . QA_NOMBRE . "%'");
    // Usuarios QA de esta suite: se retiran por identificador junto con sus
    // tableros, equipos y avisos. Cubre tambien restos de una ejecucion
    // anterior que se interrumpiera antes de limpiar.
    qa_users_cleanup_stale($conn, QA_SUITE);
    foreach (glob(SESSION_DIR . '/sess_qaf9*') ?: [] as $ff) {
        @unlink($ff);
    }

    // Usuario QA propio. Antes se tomaba el primero de la base, que en una
    // copia de produccion es una persona real y cuyo rol podia variar.
    $owner = qa_user($conn, QA_SUITE, ['rol' => 'user', 'is_admin' => 0]);
    if ($owner <= 0) {
        ko('59. Hay un usuario para la prueba', 'la base está vacía');
    } else {
        $conn->query("INSERT INTO boards (nombre, owner_user_id, visibility, created_at)
                      VALUES ('" . QA_NOMBRE . "', $owner, 'private', NOW())");
        $board = (int) $conn->insert_id;
        $conn->query("INSERT INTO board_members (board_id,user_id,rol) VALUES ($board,$owner,'propietario')");
        $conn->query("INSERT INTO `columns` (board_id,nombre,orden,is_done,created_at)
                      VALUES ($board,'Por hacer',0,0,NOW())");
        $colTodo = (int) $conn->insert_id;
        $conn->query("INSERT INTO `columns` (board_id,nombre,orden,is_done,created_at)
                      VALUES ($board,'Hecho',1,1,NOW())");
        $colDone = (int) $conn->insert_id;

        $hoy    = date('Y-m-d');
        $viejo  = date('Y-m-d', strtotime('-90 days'));
        $ins = $conn->prepare("INSERT INTO tasks (board_id,column_id,titulo,prioridad,sort_order,creado_en,completed_at)
                               VALUES (?,?,?,'med',?,NOW(),?)");
        $filas = [
            [QA_NOMBRE . ' reciente', 0, $hoy . ' 10:00:00'],
            [QA_NOMBRE . ' antigua',  1, $viejo . ' 10:00:00'],
            [QA_NOMBRE . ' sin fecha', 2, null],
        ];
        foreach ($filas as $fila) {
            $ins->bind_param('iisis', $board, $colDone, $fila[0], $fila[1], $fila[2]);
            $ins->execute();
        }

        $csrf = bin2hex(random_bytes(32));
        $sid  = 'qaf9' . bin2hex(random_bytes(8));
        file_put_contents(SESSION_DIR . '/sess_' . $sid,
            'user_id|i:' . $owner . ';csrf|s:' . strlen($csrf) . ':"' . $csrf . '";_auth_ts|i:' . time() . ';');

        $ch = curl_init(BASE_URL . '/boards/view.php?id=' . $board . '&embed=1');
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => 1, CURLOPT_TIMEOUT => 30,
            CURLOPT_COOKIE => 'PHPSESSID=' . $sid, CURLOPT_HTTPHEADER => ['X-Requested-With: fetch']]);
        $html = (string) curl_exec($ch);
        $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        chk('59. El tablero responde', $http === 200, "http=$http");

        chk('60. #kanban trae la fecha del servidor',
            str_contains($html, 'data-today="' . $hoy . '"'), $hoy);

        chk('61. La tarjeta reciente publica su fecha',
            (bool) preg_match('/data-completed="' . preg_quote($hoy, '/') . '"/', $html));

        chk('62. La tarjeta antigua publica la suya',
            (bool) preg_match('/data-completed="' . preg_quote($viejo, '/') . '"/', $html));

        chk('63. La tarjeta sin fecha sale con el atributo vacío',
            substr_count($html, 'data-completed=""') >= 1,
            'existe y está vacío: el filtro sabrá no ocultarla');

        chk('64. El selector aparece en la columna de hecho',
            substr_count($html, 'data-done-window') === 1
            && substr_count($html, 'data-done-filter') === 1,
            'una sola vez: solo hay una columna de finalización');

        // Arranque limpio: el HTML del servidor no debe fijar ninguna opción.
        // Si alguna llevara «selected», una carga nueva empezaría en un modo
        // que el usuario no eligió. Lo que decide es filterState, y arranca en
        // 7 días; restoreFilterUI corrige incluso el valor que el navegador
        // restaura por su cuenta al pulsar F5.
        preg_match('/<select class="fyc-done-window".*?<\/select>/s', $html, $mSel);
        chk('65. Ninguna opción viene marcada desde el servidor',
            isset($mSel[0]) && !str_contains($mSel[0], 'selected'),
            'el modo lo decide filterState, no el HTML');

        // ---- limpieza ----
        $conn->query("DELETE FROM boards WHERE nombre LIKE '" . QA_NOMBRE . "%'");
        // Usuarios QA de esta suite: se retiran por identificador junto con sus
        // tableros, equipos y avisos. Cubre tambien restos de una ejecucion
        // anterior que se interrumpiera antes de limpiar.
        qa_users_cleanup_stale($conn, QA_SUITE);
        foreach (glob(SESSION_DIR . '/sess_qaf9*') ?: [] as $ff) {
            @unlink($ff);
        }
        section('LIMPIEZA');
        $resto = (int) $conn->query("SELECT COUNT(*) FROM boards WHERE nombre LIKE '" . QA_NOMBRE . "%'")->fetch_row()[0];
        chk('LIMPIEZA · no quedan tableros QA', $resto === 0, "restantes=$resto");
        $huer = (int) $conn->query("SELECT COUNT(*) FROM tasks WHERE titulo LIKE '" . QA_NOMBRE . "%'")->fetch_row()[0];
        chk('LIMPIEZA · no quedan tareas QA', $huer === 0, "restantes=$huer");
        chk('LIMPIEZA · no quedan sesiones QA', count(glob(SESSION_DIR . '/sess_qaf9*') ?: []) === 0);
    }
}

echo "\n══════════════════════════════════════════════════════════════════════════════\n";
printf(" RESULTADO: %d correctas, %d fallidas\n", $PASS, $FAIL);
echo "══════════════════════════════════════════════════════════════════════════════\n\n";
exit($FAIL === 0 ? 0 : 1);
