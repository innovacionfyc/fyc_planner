<?php
/**
 * tests/drawer_width_smoke.php
 *
 * Ancho responsivo del cajón de tarea (Fase F8, bloque F8.1).
 *
 * Ejecutar SOLO en local:
 *   php tests/drawer_width_smoke.php
 *
 * Comprueba que el control del ancho vive en CSS y no en un estilo en línea,
 * que existen los cuatro comportamientos responsivos y que el móvil conserva
 * el 100 % que ya funcionaba.
 *
 * No toca la base de datos ni el almacén.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Este script solo puede ejecutarse desde CLI.\n");
}

$ROOT = dirname(__DIR__);
$WS   = $ROOT . '/public/boards/workspace.php';
$CSS  = $ROOT . '/public/assets/theme.css';
$JS   = $ROOT . '/public/assets/board-view.js';

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
echo " ANCHO RESPONSIVO DEL CAJÓN DE TAREA (bloque F8.1)\n";
echo "══════════════════════════════════════════════════════════════════════════════\n";
echo " PHP   : " . PHP_VERSION . "\n";
echo " Fecha : " . date('Y-m-d H:i:s') . "\n";

$ws  = (string) file_get_contents($WS);
$css = (string) file_get_contents($CSS);

// Se aísla la etiqueta <aside> del cajón para no confundirla con otros nodos.
$aside = '';
if (preg_match('/<aside id="taskDrawer".*?>/s', $ws, $m)) {
    $aside = $m[0];
}
$bodyTag = '';
if (preg_match('/<div id="taskDrawerBody".*?>/s', $ws, $m)) {
    $bodyTag = $m[0];
}

// ═════════════════════════════════════════════════════════════
section('1-5 · EL ANCHO SALIÓ DEL MARCADO');

chk('1. La etiqueta del cajón se localiza', $aside !== '' && $bodyTag !== '');

chk('2. Ya NO hay max-width en el estilo en línea',
    !str_contains($aside, 'max-width'),
    'el estilo en línea ganaba siempre a la hoja de estilos');

chk('3. Ya NO hay padding en línea en el cuerpo',
    !preg_match('/style="[^"]*padding\s*:/', $bodyTag),
    'el padding pasa a la clase');

chk('4. Existe la clase del cajón y la del cuerpo',
    str_contains($aside, 'fyc-task-drawer')
    && str_contains($bodyTag, 'fyc-task-drawer-body'));

// Lo que NO debía tocarse
$conserva = ['fixed', 'right-0', 'top-0', 'z-50', 'h-full', 'w-full',
    'translate-x-full', 'transition-transform', 'duration-300', 'flex', 'flex-col'];
$perdidas = array_values(array_filter($conserva, fn($c) => !str_contains($aside, $c)));
chk('5. Se conservan posición, anclaje, transición y w-full',
    $perdidas === [],
    $perdidas === [] ? count($conserva) . '/' . count($conserva) : 'faltan: ' . implode(', ', $perdidas));

// ═════════════════════════════════════════════════════════════
section('6-11 · LOS CUATRO COMPORTAMIENTOS');

// Base (móvil primero)
$base = '';
if (preg_match('/\.fyc-task-drawer\s*\{(.*?)\}/s', $css, $m)) {
    $base = $m[1];
}
chk('6. Móvil conserva el 100 % y no supera la ventana',
    preg_match('/width:\s*100%/', $base) === 1
    && preg_match('/max-width:\s*100vw/', $base) === 1,
    'width:100% · max-width:100vw');

/** Extrae la anchura declarada para el cajón dentro de una consulta de medios. */
function anchoEn(string $css, string $condicion): string
{
    $re = '/@media\s*\(\s*min-width:\s*' . preg_quote($condicion, '/')
        . '\s*\)\s*\{\s*\.fyc-task-drawer\s*\{([^}]*)\}/s';
    if (preg_match($re, $css, $m) && preg_match('/width:\s*([^;]+);/', $m[1], $w)) {
        return trim($w[1]);
    }
    return '';
}

$tablet = anchoEn($css, '768px');
$porta  = anchoEn($css, '1280px');
$grande = anchoEn($css, '1536px');

chk('7. Tablet (≥768) usa min(72vw, 620px)', $tablet === 'min(72vw, 620px)', $tablet ?: 'ausente');
chk('8. Portátil (≥1280) usa min(52vw, 720px)', $porta === 'min(52vw, 720px)', $porta ?: 'ausente');
chk('9. Escritorio grande (≥1536) llega a 860px', $grande === 'min(46vw, 860px)', $grande ?: 'ausente');

chk('10. Las tres consultas están en orden ascendente',
    strpos($css, 'min-width: 768px') < strpos($css, 'min-width: 1280px')
    && strpos($css, 'min-width: 1280px') < strpos($css, 'min-width: 1536px'),
    'una anchura mayor debe poder sobrescribir a la menor');

chk('11. El cuerpo conserva su padding de 16px',
    preg_match('/\.fyc-task-drawer-body\s*\{[^}]*padding:\s*16px/s', $css) === 1);

// ═════════════════════════════════════════════════════════════
section('12-16 · SIN EFECTOS COLATERALES');

// !important: solo cuenta como uso real si es una DECLARACIÓN, no si aparece
// dentro de un comentario que explica por qué NO hace falta.
$sinComentarios = (string) preg_replace('#/\*.*?\*/#s', '', $css);
$importantes = preg_match_all('/!important\s*;/', $sinComentarios);
$importantesEnBloque = 0;
if (preg_match('/CAJÓN DE TAREA.*$/s', $css, $m)) {
    $bloqueSin = (string) preg_replace('#/\*.*?\*/#s', '', $m[0]);
    $importantesEnBloque = preg_match_all('/!important\s*;/', $bloqueSin);
}
chk('12. El bloque nuevo no usa !important', $importantesEnBloque === 0,
    "0 en el bloque · {$importantes} declaraciones en todo el archivo (preexistentes)");

chk('13. No se tocó la lógica del cajón en JS',
    str_contains((string) file_get_contents($JS), 'function openDrawerShell')
    && str_contains((string) file_get_contents($JS), 'function closeDrawer')
    && !str_contains((string) file_get_contents($JS), 'fyc-task-drawer'),
    'el JS no conoce las clases nuevas: solo CSS');

// El orden de carga es lo que hace innecesario el !important. Si se invirtiera,
// .w-full de Tailwind ganaría y el cajón mediría 100% en todas las pantallas.
$posApp   = strpos($ws, 'assets/app.css');
$posTheme = strpos($ws, "asset_url('assets/theme.css')");
chk('14. theme.css se carga DESPUÉS de app.css',
    $posApp !== false && $posTheme !== false && $posApp < $posTheme,
    'de ahí que la clase gane a .w-full sin !important');

chk('15. El cajón sigue anclado a la derecha y por encima del fondo',
    str_contains($aside, 'right-0') && str_contains($aside, 'z-50'));

// app.css es generado por Tailwind y queda fuera del alcance de este bloque.
$hashArbol = trim((string) shell_exec('git -C ' . escapeshellarg($ROOT)
    . ' hash-object public/assets/app.css 2>&1'));
$hashHead = trim((string) shell_exec('git -C ' . escapeshellarg($ROOT)
    . ' rev-parse HEAD:public/assets/app.css 2>&1'));
chk('16. app.css no fue modificado', $hashArbol === $hashHead && $hashArbol !== '',
    substr($hashArbol, 0, 16) . '…');

// ═════════════════════════════════════════════════════════════
echo "\n" . str_repeat('═', 78) . "\n";
printf(" RESULTADO: %d correctas, %d fallidas\n", $PASS, $FAIL);
echo str_repeat('═', 78) . "\n\n";

exit($FAIL === 0 ? 0 : 1);
