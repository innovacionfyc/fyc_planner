<?php
/**
 * tests/assets_versioning_smoke.php
 *
 * Pruebas del versionado de recursos estáticos (Fase F, bloque F3):
 * el helper asset_url() y su aplicación en las plantillas.
 *
 * Ejecutar SOLO en local:
 *   php tests/assets_versioning_smoke.php
 *
 * Toca la fecha de modificación de theme.css para comprobar que la versión
 * cambia, y la restaura siempre — incluso si una prueba falla.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Este script solo puede ejecutarse desde CLI.\n");
}

require_once __DIR__ . '/../config/bootstrap.php';

const BASE_URL    = 'http://localhost/fyc_planner/public';
const BASE_PATH   = '/fyc_planner/public';
const SESSION_DIR = 'C:/laragon/tmp';

$ROOT   = dirname(__DIR__);
$PUBLIC = $ROOT . '/public';

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

function http_get(string $url, ?string $sid = null): array
{
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    if ($sid !== null) {
        curl_setopt($ch, CURLOPT_COOKIE, 'PHPSESSID=' . $sid);
    }
    $body = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$code, (string) $body];
}

function make_session(int $userId, string $csrf): string
{
    $sid  = bin2hex(random_bytes(16));
    $data = 'user_id|i:' . $userId . ';csrf|s:' . strlen($csrf) . ':"' . $csrf . '";'
        . '_auth_ts|i:' . time() . ';';
    file_put_contents(SESSION_DIR . DIRECTORY_SEPARATOR . 'sess_' . $sid, $data);
    return $sid;
}

// ═════════════════════════════════════════════════════════════
echo "\n";
echo "══════════════════════════════════════════════════════════════════════════════\n";
echo " PRUEBAS DE VERSIONADO DE RECURSOS ESTÁTICOS (Fase F · bloque F3)\n";
echo "══════════════════════════════════════════════════════════════════════════════\n";
echo " PHP   : " . PHP_VERSION . "\n";
echo " Fecha : " . date('Y-m-d H:i:s') . "\n";

$THEME = $PUBLIC . '/assets/theme.css';
$mtimeOriginal = filemtime($THEME);
echo " theme.css mtime original: {$mtimeOriginal} (" . date('Y-m-d H:i:s', (int) $mtimeOriginal) . ")\n";

// Red de seguridad: la fecha original se restaura pase lo que pase.
register_shutdown_function(static function () use ($THEME, $mtimeOriginal) {
    if (is_int($mtimeOriginal) && filemtime($THEME) !== $mtimeOriginal) {
        touch($THEME, $mtimeOriginal);
        clearstatcache(true, $THEME);
        echo "\n  [red de seguridad] mtime de theme.css restaurado.\n";
    }
});

// ═════════════════════════════════════════════════════════════
section('1-9 · EL HELPER');

chk('1. El helper asset_url() existe', function_exists('asset_url'));

$uTheme = asset_url('assets/theme.css');
chk('2. theme.css devuelve una URL', $uTheme !== '' && str_contains($uTheme, 'assets/theme.css'), $uTheme);

$uJs = asset_url('assets/board-view.js');
chk('3. board-view.js devuelve una URL', $uJs !== '' && str_contains($uJs, 'assets/board-view.js'), $uJs);

chk('4. La URL incluye ?v=',
    (bool) preg_match('/\?v=\d+$/', $uTheme) && (bool) preg_match('/\?v=\d+$/', $uJs));

preg_match('/\?v=(\d+)$/', $uTheme, $m);
chk('5. La versión es el filemtime real del archivo',
    (int) ($m[1] ?? 0) === $mtimeOriginal, 'v=' . ($m[1] ?? '?') . ' mtime=' . $mtimeOriginal);

// 6. sin cambios, misma URL (se usa un proceso nuevo para evitar la caché estática)
function helper_en_proceso_nuevo(string $ruta): string
{
    $php = escapeshellarg(PHP_BINARY);
    $cod = 'require ' . var_export(dirname(__DIR__) . '/config/bootstrap.php', true)
        . '; echo asset_url(' . var_export($ruta, true) . ');';
    return trim((string) shell_exec($php . ' -r ' . escapeshellarg($cod)));
}

$a1 = helper_en_proceso_nuevo('assets/theme.css');
$a2 = helper_en_proceso_nuevo('assets/theme.css');
chk('6. Sin cambios en el archivo, la URL no cambia', $a1 === $a2 && $a1 !== '', $a1);

// 7. tocar el archivo cambia la versión
$nuevoMtime = $mtimeOriginal + 12345;
touch($THEME, $nuevoMtime);
clearstatcache(true, $THEME);
$a3 = helper_en_proceso_nuevo('assets/theme.css');
chk('7. Al cambiar la fecha del archivo, cambia la versión',
    $a3 !== $a1 && str_contains($a3, '?v=' . $nuevoMtime), $a3);

// 8. restaurar
touch($THEME, $mtimeOriginal);
clearstatcache(true, $THEME);
$a4 = helper_en_proceso_nuevo('assets/theme.css');
chk('8. La fecha original se restaura tras la prueba',
    filemtime($THEME) === $mtimeOriginal && $a4 === $a1,
    'mtime=' . filemtime($THEME) . ' = ' . $mtimeOriginal);

// 9. query previa
$conQuery = asset_url('assets/theme.css?foo=bar');
chk('9. Si ya hay query string, la versión se añade con &',
    str_contains($conQuery, '?foo=bar&v=') && !str_contains($conQuery, '?foo=bar?v='), $conQuery);

// ═════════════════════════════════════════════════════════════
section('10-20 · SEGURIDAD DEL HELPER');

$rechazos = [
    '10. Ruta con ../ rechazada'                => '../config/db.php',
    '11. Ruta con ..\\ rechazada'               => '..\\config\\db.php',
    '12. Ruta absoluta de Windows rechazada'    => 'C:\\Windows\\win.ini',
    '13. Ruta absoluta de Linux rechazada'      => '/etc/passwd',
    '14. URL http:// rechazada'                 => 'http://evil.example/x.js',
    '15. URL https:// rechazada'                => 'https://evil.example/x.js',
    '16. URL sin protocolo (//) rechazada'      => '//evil.example/x.js',
    '17. Byte nulo rechazado'                   => "assets/theme.css\0.png",
];
foreach ($rechazos as $nombre => $entrada) {
    chk($nombre, asset_url($entrada) === '', 'devuelve cadena vacía');
}

// Variantes adicionales de escape
$masRechazos = ['....//config/db.php', 'assets/../../config/db.php', '\\\\servidor\\recurso',
    'file:///etc/passwd', 'C:/laragon/www/fyc_planner/config/db.php'];
$nRech = 0;
foreach ($masRechazos as $r) {
    if (asset_url($r) === '') {
        $nRech++;
    }
}
chk('17b. Otras variantes de escape también rechazadas',
    $nRech === count($masRechazos), "$nRech/" . count($masRechazos));

// 18-19. archivo inexistente: URL sin versión y sin rutas físicas
$inexistente = asset_url('assets/no-existe-jamas.css');
chk('18. Un archivo inexistente no revela la ruta física',
    $inexistente !== ''
    && !str_contains($inexistente, 'C:')
    && !str_contains($inexistente, 'laragon')
    && !str_contains($inexistente, '?v='),
    $inexistente);

$todasLasSalidas = implode(' ', [
    $uTheme, $uJs, $conQuery, $inexistente,
    asset_url('assets/app.css'), asset_url('assets/boards-actions.js'),
]);
chk('19. Ninguna salida expone C:\\ ni /var/www/',
    !str_contains($todasLasSalidas, 'C:')
    && !str_contains($todasLasSalidas, '/var/www/')
    && !str_contains($todasLasSalidas, 'laragon')
    && !str_contains($todasLasSalidas, '\\'));

chk('20. Las barras duplicadas se normalizan',
    asset_url('assets//theme.css') === $uTheme, asset_url('assets//theme.css'));

// ═════════════════════════════════════════════════════════════
section('21-24 · APLICACIÓN EN LAS PLANTILLAS');

// 21. la base local se resuelve bien por HTTP (en CLI app_base() es '')
[$codeLogin, $htmlLogin] = http_get(BASE_URL . '/login.php');
chk('21. La ruta base local es correcta al servirse por HTTP',
    $codeLogin === 200 && str_contains($htmlLogin, BASE_PATH . '/assets/theme.css?v='),
    "http=$codeLogin");

// 22-23. una sola inclusión por archivo y sin restos sin versionar
$plantillas = [
    'public/admin/_layout_top.php', 'public/boards/trash.php', 'public/boards/view.php',
    'public/boards/workspace.php', 'public/login.php', 'public/reports/my_team.php',
];
$temaVersionado = 0;
$temaCrudo = 0;
foreach ($plantillas as $f) {
    $s = (string) file_get_contents($ROOT . '/' . $f);
    $temaVersionado += substr_count($s, "asset_url('assets/theme.css')");
    $temaCrudo += preg_match_all('#href="\.{0,2}/?assets/theme\.css#', $s);
}
chk('22. theme.css se incluye una sola vez por plantilla, versionado',
    $temaVersionado === 6 && $temaCrudo === 0,
    "$temaVersionado versionadas, $temaCrudo sin versionar");

$jsVersionado = 0;
$jsCrudo = 0;
foreach ($plantillas as $f) {
    $s = (string) file_get_contents($ROOT . '/' . $f);
    $jsVersionado += substr_count($s, "asset_url('assets/board-view.js')");
    $jsCrudo += preg_match_all('#src="\.{0,2}/?assets/board-view\.js#', $s);
}
chk('23. board-view.js se incluye versionado y sin duplicados',
    $jsVersionado === 2 && $jsCrudo === 0,
    "$jsVersionado versionadas, $jsCrudo sin versionar");

// 24. no queda ninguna inclusión antigua con ?v= escrito a mano
$manual = [];
foreach ($plantillas as $f) {
    $s = (string) file_get_contents($ROOT . '/' . $f);
    if (preg_match_all('#(?:href|src)="[^"]*assets/(theme\.css|board-view\.js|boards-actions\.js)\?v=\d+#', $s, $mm)) {
        $manual[] = $f . ': ' . implode(',', $mm[1]);
    }
}
chk('24. No queda ninguna versión escrita a mano',
    $manual === [], $manual === [] ? 'todas calculadas por el helper' : implode(' | ', $manual));

// ═════════════════════════════════════════════════════════════
section('25-30 · NO REGRESIÓN Y ALCANCE');

$hashActual = trim((string) shell_exec('git -C ' . escapeshellarg($ROOT) . ' hash-object public/assets/app.css 2>&1'));
$hashHead   = trim((string) shell_exec('git -C ' . escapeshellarg($ROOT) . ' rev-parse HEAD:public/assets/app.css 2>&1'));
chk('25. app.css no ha cambiado', $hashActual === $hashHead && $hashActual !== '',
    substr($hashActual, 0, 16) . '…');

$appCssCrudo = 0;
foreach ($plantillas as $f) {
    $appCssCrudo += preg_match_all('#href="[^"]*assets/app\.css#', (string) file_get_contents($ROOT . '/' . $f));
}
chk('26. Tailwind no se regenera: app.css sigue enlazado como antes',
    $appCssCrudo === 6 && !str_contains(
        implode('', array_map(fn($f) => (string) file_get_contents($ROOT . '/' . $f), $plantillas)),
        "asset_url('assets/app.css')"
    ),
    "$appCssCrudo inclusiones intactas, fuera del alcance");

// 27-28. el HTML servido lleva versión numérica
$csrf = bin2hex(random_bytes(32));
$sid  = make_session(2, $csrf);
[$codeWs, $htmlWs] = http_get(BASE_URL . '/boards/workspace.php', $sid);
@unlink(SESSION_DIR . DIRECTORY_SEPARATOR . 'sess_' . $sid);

preg_match('#assets/theme\.css\?v=(\d+)#', $htmlWs, $vTheme);
preg_match('#assets/board-view\.js\?v=(\d+)#', $htmlWs, $vJs);
chk('27. El HTML servido contiene la versión en ambos recursos',
    $codeWs === 200 && isset($vTheme[1], $vJs[1]),
    "http=$codeWs theme=" . ($vTheme[1] ?? '?') . ' js=' . ($vJs[1] ?? '?'));

chk('28. La versión es numérica y coincide con el archivo',
    ctype_digit((string) ($vTheme[1] ?? '')) && (int) ($vTheme[1] ?? 0) === $mtimeOriginal
    && (int) ($vJs[1] ?? 0) === filemtime($PUBLIC . '/assets/board-view.js'),
    'theme=' . ($vTheme[1] ?? '?'));

// 29. no se rompen las query strings existentes de otras URLs del HTML
chk('29. El helper no rompe otras query strings del HTML',
    !str_contains($htmlWs, '?v=?') && !str_contains($htmlWs, '&v=&')
    && !preg_match('#assets/[a-z.-]+\?v=\d+\?#', $htmlWs));

// 30. los endpoints protegidos siguen sin versionar
$afectados = [];
foreach (['tasks/attachment.php', 'tasks/drawer.php', 'boards/events_poll.php'] as $ep) {
    if (preg_match('#' . preg_quote($ep, '#') . '[^"\']*[?&]v=\d+#', $htmlWs)) {
        $afectados[] = $ep;
    }
}
$srcAdj = (string) file_get_contents($ROOT . '/public/_attachments.php');
chk('30. attachment.php y las URLs protegidas no se versionan',
    $afectados === [] && !str_contains($srcAdj, 'asset_url('),
    $afectados === [] ? 'endpoints intactos' : implode(', ', $afectados));

// ═════════════════════════════════════════════════════════════
section('EVIDENCIA FINAL');

clearstatcache(true, $THEME);
$mtimeFinal = filemtime($THEME);
printf("  theme.css mtime: original=%d final=%d\n", $mtimeOriginal, $mtimeFinal);
chk('31. La fecha de theme.css queda exactamente como estaba',
    $mtimeFinal === $mtimeOriginal, 'sin residuo temporal');

// ═════════════════════════════════════════════════════════════
echo "\n" . str_repeat('═', 78) . "\n";
printf(" RESULTADO: %d correctas, %d fallidas\n", $PASS, $FAIL);
echo str_repeat('═', 78) . "\n\n";

exit($FAIL === 0 ? 0 : 1);
