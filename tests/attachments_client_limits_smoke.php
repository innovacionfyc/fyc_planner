<?php
/**
 * tests/attachments_client_limits_smoke.php
 *
 * Límites del cliente (Fase F7-ALT, bloque G3).
 *
 * Ejecutar SOLO en local:
 *   php tests/attachments_client_limits_smoke.php
 *
 * Dos capas de comprobación:
 *
 *   1. ESTÁTICA sobre public/assets/board-view.js: que las constantes y el
 *      orden de validación sean los esperados.
 *
 *   2. DE COMPORTAMIENTO: se extrae la función real uploadTaskAttachments()
 *      del archivo y se ejecuta en Node con el DOM, FormData y fetch
 *      simulados. Así se comprueba lo que de verdad importa —que NO se
 *      llegue a llamar a fetch cuando la validación falla— en lugar de
 *      limitarse a buscar cadenas de texto.
 *
 * Requiere Node. No toca la base de datos, ni el almacén, ni la red.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Este script solo puede ejecutarse desde CLI.\n");
}

$ROOT = dirname(__DIR__);
$JS   = $ROOT . '/public/assets/board-view.js';
$MB   = 1048576;

$PASS = 0;
$FAIL = 0;

function ok(string $n, string $d = ''): void
{
    global $PASS;
    $PASS++;
    printf("  [OK]    %-56s %s\n", $n, $d);
}

function ko(string $n, string $d = ''): void
{
    global $FAIL;
    $FAIL++;
    printf("  [FALLO] %-56s %s\n", $n, $d);
}

function chk(string $n, bool $c, string $d = ''): void
{
    $c ? ok($n, $d) : ko($n, $d);
}

function section(string $t): void
{
    echo "\n" . str_repeat('─', 78) . "\n " . $t . "\n" . str_repeat('─', 78) . "\n";
}

echo "\n";
echo "══════════════════════════════════════════════════════════════════════════════\n";
echo " LÍMITES DEL CLIENTE — 14 MB POR ARCHIVO Y POR ENVÍO (bloque G3)\n";
echo "══════════════════════════════════════════════════════════════════════════════\n";
echo " PHP   : " . PHP_VERSION . "\n";
echo " Node  : " . trim((string) shell_exec('node --version 2>&1')) . "\n";
echo " Fecha : " . date('Y-m-d H:i:s') . "\n";

$src = (string) file_get_contents($JS);

// ═════════════════════════════════════════════════════════════
section('1-6 · CONTRATO DECLARADO EN EL CLIENTE');

chk('1. ATTACH_MAX_FILE_BYTES vale 14 MB',
    (bool) preg_match('/var ATTACH_MAX_FILE_BYTES\s*=\s*14 \* 1024 \* 1024/', $src));

chk('2. ATTACH_MAX_REQUEST_BYTES vale 14 MB',
    (bool) preg_match('/var ATTACH_MAX_REQUEST_BYTES\s*=\s*14 \* 1024 \* 1024/', $src));

chk('3. ATTACH_MAX_FILES sigue en 5',
    (bool) preg_match('/var ATTACH_MAX_FILES\s*=\s*5;/', $src));

chk('4. Los tres tipos reutilizan el límite físico',
    substr_count($src, 'bytes: ATTACH_MAX_FILE_BYTES') === 3);

chk('5. No quedan los valores antiguos de 10/20/50 MB',
    !str_contains($src, '10 * 1024 * 1024')
    && !str_contains($src, '20 * 1024 * 1024')
    && !str_contains($src, '50 * 1024 * 1024'));

// El orden importa: todo rechazo debe ocurrir antes de construir el envío.
//
// Se mide DENTRO de la función, no sobre el archivo entero: hay nueve
// «new FormData()» repartidos por board-view.js y una búsqueda global
// encontraría el de otra función, dando un resultado sin sentido.
$cuerpoUpload = extraer_funcion($src, 'uploadTaskAttachments');
$pCant  = strpos($cuerpoUpload, 'files.length > ATTACH_MAX_FILES');
$pTotal = strpos($cuerpoUpload, 'totalBytes > ATTACH_MAX_REQUEST_BYTES');
$pForm  = strpos($cuerpoUpload, 'var fd = new FormData()');
$pFetch = strpos($cuerpoUpload, "return fetch('../tasks/attachment_upload.php'");
chk('6. Las validaciones preceden a FormData y a fetch',
    $cuerpoUpload !== ''
    && $pCant !== false && $pTotal !== false && $pForm !== false && $pFetch !== false
    && $pCant < $pTotal && $pTotal < $pForm && $pForm < $pFetch,
    'cantidad → total → FormData → fetch, dentro de la función');

// ═════════════════════════════════════════════════════════════
section('7-18 · COMPORTAMIENTO REAL DE uploadTaskAttachments()');

/** Extrae una función completa contando llaves desde su declaración. */
function extraer_funcion(string $src, string $nombre): string
{
    $ini = strpos($src, 'function ' . $nombre . '(');
    if ($ini === false) {
        return '';
    }
    $llave = strpos($src, '{', $ini);
    if ($llave === false) {
        return '';
    }
    $n = 0;
    $len = strlen($src);
    for ($i = $llave; $i < $len; $i++) {
        if ($src[$i] === '{') {
            $n++;
        } elseif ($src[$i] === '}') {
            $n--;
            if ($n === 0) {
                return substr($src, $ini, $i - $ini + 1);
            }
        }
    }
    return '';
}

$fnUpload = extraer_funcion($src, 'uploadTaskAttachments');
$fnKind   = extraer_funcion($src, 'attachKindOf');
$fnMb     = extraer_funcion($src, 'attachMb');

chk('7. Se pudo extraer la función real del archivo',
    $fnUpload !== '' && $fnKind !== '' && $fnMb !== '',
    strlen($fnUpload) . ' bytes de uploadTaskAttachments');

if ($fnUpload === '') {
    echo "\n  Sin la función no se puede seguir.\n";
    exit(1);
}

// Constantes tal y como están declaradas en el archivo
preg_match('/var ATTACH_MAX_FILES\s*=\s*[^;]+;/', $src, $c1);
preg_match('/var ATTACH_MAX_FILE_BYTES\s*=\s*[^;]+;/', $src, $c2);
preg_match('/var ATTACH_MAX_REQUEST_BYTES\s*=\s*[^;]+;/', $src, $c3);
preg_match('/var ATTACH_LIMITS = \{.*?\};/s', $src, $c4);
$constantes = ($c1[0] ?? '') . "\n" . ($c2[0] ?? '') . "\n" . ($c3[0] ?? '') . "\n" . ($c4[0] ?? '');

// ── Banco de pruebas en Node ─────────────────────────────────
$harness = <<<'JS'
'use strict';
// Simulacros mínimos. Si el código intentara tocar algo no simulado, fallaría
// de forma ruidosa en vez de pasar por casualidad.
let FETCH_LLAMADAS = 0;
let ULTIMO_ESTADO  = null;
let RESPUESTA      = { status: 200, json: { ok: true, attachments: [] } };

function attachSetStatus(msg, kind) { ULTIMO_ESTADO = { msg: msg, kind: kind }; }
function attachSetBusy() {}
function attachDropSetActive() {}
function showToast() {}
function loadDrawer() {}
function attachCanWriteHere() { return true; }
function attachContext() { return { taskId: '42', csrf: 'tok' }; }
let attachBusy = false;

const document = { getElementById: function () { return null; } };

function FormData() { this._d = []; }
FormData.prototype.set    = function () {};
FormData.prototype.append = function () {};

function fetch() {
  FETCH_LLAMADAS++;
  const r = RESPUESTA;
  return Promise.resolve({
    status: r.status,
    json: function () {
      if (r.json === null) return Promise.reject(new Error('sin json'));
      return Promise.resolve(r.json);
    }
  });
}

__CONSTANTES__
__FN_MB__
__FN_KIND__
__FN_UPLOAD__

/** Archivo simulado: solo hacen falta name y size. */
function F(name, mb) { return { name: name, size: Math.round(mb * 1048576) }; }

const casos = [];
function correr(id, files, respuesta) {
  FETCH_LLAMADAS = 0;
  ULTIMO_ESTADO  = null;
  attachBusy     = false;
  RESPUESTA = respuesta || { status: 200, json: { ok: true, attachments: [] } };
  return Promise.resolve(uploadTaskAttachments(files, 'picker')).then(function (r) {
    casos.push({
      id: id,
      resultado: r,
      fetch: FETCH_LLAMADAS,
      mensaje: ULTIMO_ESTADO ? ULTIMO_ESTADO.msg : null,
      tipo: ULTIMO_ESTADO ? ULTIMO_ESTADO.kind : null
    });
  });
}

const MB = 1;
Promise.resolve()
  // Rechazos que NO deben llegar a fetch
  .then(() => correr('cantidad', [F('a.jpg',1),F('b.jpg',1),F('c.jpg',1),F('d.jpg',1),F('e.jpg',1),F('f.jpg',1)]))
  .then(() => correr('individual', [F('grande.jpg', 15)]))
  .then(() => correr('total', [F('a.jpg', 8), F('b.jpg', 8)]))
  .then(() => correr('total_tres', [F('a.jpg', 5), F('b.jpg', 5), F('c.jpg', 5)]))
  .then(() => correr('formato', [F('malo.exe', 1)]))
  // Aceptados
  .then(() => correr('justo_debajo', [F('a.jpg', 7), F('b.jpg', 6.9)]))
  .then(() => correr('uno_valido', [F('a.jpg', 2)]))
  // Respuestas del servidor
  .then(() => correr('r413_request', [F('a.jpg', 1)], { status: 413,
        json: { ok:false, error:'request_too_large', message:'El envío completo ocupa 16 MB y el máximo son 14 MB. No se ha adjuntado ningún archivo: reduce la selección o comparte los más grandes como enlace externo (YouTube, Vimeo o una URL).' } }))
  .then(() => correr('r413_payload', [F('a.jpg', 1)], { status: 413,
        json: { ok:false, error:'payload_too_large', message:'El envío supera el tamaño máximo que admite el servidor. Reduce la selección o comparte el archivo como enlace externo (YouTube, Vimeo o una URL).' } }))
  .then(() => correr('r413_sin_json', [F('a.jpg', 1)], { status: 413, json: null }))
  .then(() => correr('r403', [F('a.jpg', 1)], { status: 403, json: { ok:false, error:'csrf' } }))
  .then(() => { console.log(JSON.stringify(casos)); })
  .catch((e) => { console.log(JSON.stringify([{ id:'ERROR', error:String(e) }])); });
JS;

$harness = str_replace(
    ['__CONSTANTES__', '__FN_MB__', '__FN_KIND__', '__FN_UPLOAD__'],
    [$constantes, $fnMb, $fnKind, $fnUpload],
    $harness
);

$tmpJs = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'fyc_g3_harness_' . bin2hex(random_bytes(4)) . '.js';
file_put_contents($tmpJs, $harness);

$salida = (string) shell_exec('node ' . escapeshellarg($tmpJs) . ' 2>&1');
@unlink($tmpJs);

$casos = json_decode(trim($salida), true);
if (!is_array($casos) || isset($casos[0]['error'])) {
    ko('8. El banco de pruebas se ejecuta', substr(trim($salida), 0, 160));
    echo "\n  RESULTADO: $PASS correctas, " . ($FAIL) . " fallidas\n\n";
    exit(1);
}
ok('8. El banco de pruebas se ejecuta', count($casos) . ' casos');

$por = [];
foreach ($casos as $c) {
    $por[$c['id']] = $c;
}

/** ¿Hubo rechazo sin llegar a la red? */
function rechazado_sin_fetch(array $c): bool
{
    return $c['fetch'] === 0 && $c['resultado'] === false && $c['tipo'] === 'error';
}

chk('9. Más de 5 archivos se rechaza sin llamar a fetch',
    rechazado_sin_fetch($por['cantidad'] ?? []),
    'fetch=' . ($por['cantidad']['fetch'] ?? '?'));

chk('10. Un archivo de 15 MB se rechaza sin llamar a fetch',
    rechazado_sin_fetch($por['individual'] ?? [])
    && str_contains((string) ($por['individual']['mensaje'] ?? ''), 'máximo por archivo'),
    'fetch=' . ($por['individual']['fetch'] ?? '?'));

chk('11. Dos archivos válidos que suman 16 MB se rechazan sin fetch',
    rechazado_sin_fetch($por['total'] ?? []),
    'fetch=' . ($por['total']['fetch'] ?? '?') . ' · 8 + 8 MB');

chk('12. Tres archivos que suman 15 MB también se rechazan',
    rechazado_sin_fetch($por['total_tres'] ?? []),
    'fetch=' . ($por['total_tres']['fetch'] ?? '?') . ' · 5 + 5 + 5 MB');

chk('13. El mensaje del total guía hacia el enlace externo',
    str_contains((string) ($por['total']['mensaje'] ?? ''), 'máximo por envío')
    && str_contains((string) ($por['total']['mensaje'] ?? ''), 'enlace externo')
    && str_contains((string) ($por['total']['mensaje'] ?? ''), 'YouTube')
    && str_contains((string) ($por['total']['mensaje'] ?? ''), 'Vimeo'),
    '"' . mb_substr((string) ($por['total']['mensaje'] ?? ''), 0, 52) . '…"');

chk('14. No hay aceptación parcial: el lote entero se descarta',
    ($por['total']['resultado'] ?? null) === false && ($por['total']['fetch'] ?? -1) === 0
    && ($por['total_tres']['resultado'] ?? null) === false,
    'ningún archivo viaja');

chk('15. Un conjunto de 13,9 MB sí llega a enviarse',
    ($por['justo_debajo']['fetch'] ?? 0) === 1 && ($por['justo_debajo']['resultado'] ?? null) === true,
    'fetch=' . ($por['justo_debajo']['fetch'] ?? '?') . ' · 7 + 6,9 MB');

chk('16. Un archivo pequeño sigue subiéndose con normalidad',
    ($por['uno_valido']['fetch'] ?? 0) === 1 && ($por['uno_valido']['resultado'] ?? null) === true);

chk('17. Un formato no permitido se rechaza sin fetch',
    rechazado_sin_fetch($por['formato'] ?? []),
    'fetch=' . ($por['formato']['fetch'] ?? '?'));

// ── Respuestas 413 y 403 ─────────────────────────────────────
chk('18. 413 request_too_large muestra el mensaje del backend',
    str_contains((string) ($por['r413_request']['mensaje'] ?? ''), 'No se ha adjuntado ningún archivo')
    && str_contains((string) ($por['r413_request']['mensaje'] ?? ''), 'enlace externo')
    && ($por['r413_request']['tipo'] ?? '') === 'error',
    '"' . mb_substr((string) ($por['r413_request']['mensaje'] ?? ''), 0, 46) . '…"');

section('19-22 · RESPUESTAS DEL SERVIDOR');

chk('19. 413 payload_too_large muestra el mensaje del backend',
    str_contains((string) ($por['r413_payload']['mensaje'] ?? ''), 'supera el tamaño máximo')
    && str_contains((string) ($por['r413_payload']['mensaje'] ?? ''), 'YouTube'));

chk('20. 413 sin JSON usa un texto de reserva claro',
    str_contains((string) ($por['r413_sin_json']['mensaje'] ?? ''), '14 MB')
    && str_contains((string) ($por['r413_sin_json']['mensaje'] ?? ''), 'enlace externo')
    && ($por['r413_sin_json']['resultado'] ?? null) === false,
    '"' . mb_substr((string) ($por['r413_sin_json']['mensaje'] ?? ''), 0, 46) . '…"');

chk('21. Un 413 NUNCA se presenta como problema de permisos',
    !str_contains((string) ($por['r413_request']['mensaje'] ?? ''), 'permiso')
    && !str_contains((string) ($por['r413_payload']['mensaje'] ?? ''), 'permiso')
    && !str_contains((string) ($por['r413_sin_json']['mensaje'] ?? ''), 'permiso'),
    'ninguno de los tres menciona permisos');

chk('22. Un 403 sí se sigue tratando como permisos',
    str_contains((string) ($por['r403']['mensaje'] ?? ''), 'permiso')
    && ($por['r403']['resultado'] ?? null) === false);

// ═════════════════════════════════════════════════════════════
section('23-25 · PEGAR, ARRASTRAR Y ESPEJO DEL BACKEND');

chk('23. Pegar y arrastrar comparten la misma validación',
    substr_count($src, 'uploadTaskAttachments(') >= 4
    && str_contains($src, "uploadTaskAttachments(renamed, 'paste')")
    && str_contains($src, "uploadTaskAttachments(validos, 'drop')")
    && str_contains($src, "uploadTaskAttachments(input.files, 'picker')"),
    'un único flujo para los tres orígenes');

// Espejo real: las constantes del cliente deben coincidir con las del backend
require_once $ROOT . '/public/_attachments.php';
chk('24. El cliente refleja exactamente las constantes del backend',
    (bool) preg_match('/var ATTACH_MAX_FILE_BYTES\s*=\s*(\d+) \* 1024 \* 1024/', $src, $m1)
    && (int) $m1[1] * $MB === ATTACH_MAX_FILE_BYTES
    && (bool) preg_match('/var ATTACH_MAX_REQUEST_BYTES\s*=\s*(\d+) \* 1024 \* 1024/', $src, $m2)
    && (int) $m2[1] * $MB === ATTACH_MAX_REQUEST_BYTES
    && (bool) preg_match('/var ATTACH_MAX_FILES\s*=\s*(\d+);/', $src, $m3)
    && (int) $m3[1] === ATTACH_MAX_FILES,
    'cliente y servidor en ' . (ATTACH_MAX_FILE_BYTES / $MB) . ' MB · ' . ATTACH_MAX_FILES . ' archivos');

chk('25. El banco de pruebas no dejó archivos temporales',
    !is_file($tmpJs) && count(glob(sys_get_temp_dir() . '/fyc_g3_harness_*.js') ?: []) === 0);

// ═════════════════════════════════════════════════════════════
echo "\n" . str_repeat('═', 78) . "\n";
printf(" RESULTADO: %d correctas, %d fallidas\n", $PASS, $FAIL);
echo str_repeat('═', 78) . "\n\n";

exit($FAIL === 0 ? 0 : 1);
