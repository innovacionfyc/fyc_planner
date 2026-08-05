<?php
/**
 * tests/attachments_backend_smoke.php
 *
 * Pruebas de backend para los adjuntos de tareas (Fase A).
 *
 * Ejecutar SOLO en local:
 *   php tests/attachments_backend_smoke.php
 *
 * Qué hace:
 *   1. Crea datos temporales (usuario, tablero, columna, tarea y membresías QA).
 *   2. Fabrica sesiones PHP para cuatro perfiles: propietario, editor,
 *      lector y ajeno al tablero.
 *   3. Ejercita los endpoints reales por HTTP con curl.
 *   4. Limpia absolutamente todo y comprueba que no quedan residuos.
 *
 * No deja archivos, tareas, usuarios ni filas QA. Si el script se
 * interrumpe, volver a ejecutarlo limpia los restos de la vez anterior.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Este script solo puede ejecutarse desde CLI.\n");
}

require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../public/_attachments.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
app_sync_db_timezone($conn);

// ─────────────────────────────────────────────────────────────
// Configuración
// ─────────────────────────────────────────────────────────────
const QA_TAG      = 'QA ATTACH 2026-07-29';
const BASE_URL    = 'http://localhost/fyc_planner/public';
const SESSION_DIR = 'C:/laragon/tmp';

$FIXTURES = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'fyc_attach_fixtures';

// ─────────────────────────────────────────────────────────────
// Utilidades de salida
// ─────────────────────────────────────────────────────────────
$PASS = 0;
$FAIL = 0;
$RESULTS = [];

function ok(string $name, string $detail = ''): void
{
    global $PASS, $RESULTS;
    $PASS++;
    $RESULTS[] = ['ok', $name, $detail];
    printf("  [OK]    %-52s %s\n", $name, $detail);
}

function ko(string $name, string $detail = ''): void
{
    global $FAIL, $RESULTS;
    $FAIL++;
    $RESULTS[] = ['ko', $name, $detail];
    printf("  [FALLO] %-52s %s\n", $name, $detail);
}

function expect(string $name, $got, $want, string $extra = ''): void
{
    if ((string) $got === (string) $want) {
        ok($name, "obtenido=$got" . ($extra ? " | $extra" : ''));
    } else {
        ko($name, "obtenido=$got esperado=$want" . ($extra ? " | $extra" : ''));
    }
}

function section(string $t): void
{
    echo "\n" . str_repeat('─', 74) . "\n " . $t . "\n" . str_repeat('─', 74) . "\n";
}

// ─────────────────────────────────────────────────────────────
// HTTP
// ─────────────────────────────────────────────────────────────

/**
 * Petición HTTP con curl. Devuelve [status, headersCrudas, cuerpo].
 *
 * @param array $opts  sessionId, post (array), files (array de rutas),
 *                     headers (array), method
 */
function http_request(string $url, array $opts = []): array
{
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HEADER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 60);

    if (!empty($opts['sessionId'])) {
        curl_setopt($ch, CURLOPT_COOKIE, 'PHPSESSID=' . $opts['sessionId']);
    }
    if (!empty($opts['headers'])) {
        curl_setopt($ch, CURLOPT_HTTPHEADER, $opts['headers']);
    }

    if (isset($opts['post']) || isset($opts['files'])) {
        curl_setopt($ch, CURLOPT_POST, true);
        $fields = $opts['post'] ?? [];
        if (!empty($opts['files'])) {
            foreach ($opts['files'] as $i => $f) {
                $path = is_array($f) ? $f['path'] : $f;
                $name = is_array($f) ? $f['name'] : basename($f);
                $fields['files[' . $i . ']'] = new CURLFile($path, 'application/octet-stream', $name);
            }
        }
        curl_setopt($ch, CURLOPT_POSTFIELDS, $fields);
    }

    $raw = curl_exec($ch);
    if ($raw === false) {
        $err = curl_error($ch);
        curl_close($ch);
        return [0, '', 'CURL_ERROR: ' . $err];
    }
    $status  = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $hSize   = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);

    return [$status, substr($raw, 0, $hSize), substr($raw, $hSize)];
}

function header_value(string $headers, string $name): string
{
    if (preg_match('/^' . preg_quote($name, '/') . ':\s*(.+)$/mi', $headers, $m)) {
        return trim($m[1]);
    }
    return '';
}

function json_of(string $body): array
{
    $d = json_decode($body, true);
    return is_array($d) ? $d : [];
}

// ─────────────────────────────────────────────────────────────
// Sesiones fabricadas
// ─────────────────────────────────────────────────────────────

/** Crea un archivo de sesión PHP válido para un usuario y devuelve su id. */
function make_session(int $userId, string $csrf): string
{
    $sid  = bin2hex(random_bytes(16));
    $data = 'user_id|i:' . $userId . ';csrf|s:' . strlen($csrf) . ':"' . $csrf . '";'
        . '_auth_ts|i:' . time() . ';';
    file_put_contents(SESSION_DIR . DIRECTORY_SEPARATOR . 'sess_' . $sid, $data);
    return $sid;
}

function drop_session(string $sid): void
{
    @unlink(SESSION_DIR . DIRECTORY_SEPARATOR . 'sess_' . $sid);
}

// ─────────────────────────────────────────────────────────────
// Fixtures
// ─────────────────────────────────────────────────────────────
function build_fixtures(string $dir): array
{
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }
    $f = [];

    // JPEG real
    $im = imagecreatetruecolor(40, 30);
    imagefill($im, 0, 0, imagecolorallocate($im, 200, 40, 60));
    $f['jpg'] = $dir . '/valida.jpg';
    imagejpeg($im, $f['jpg'], 85);
    imagedestroy($im);

    // PNG real
    $im = imagecreatetruecolor(30, 20);
    imagefill($im, 0, 0, imagecolorallocate($im, 20, 120, 220));
    $f['png'] = $dir . '/valida.png';
    imagepng($im, $f['png']);
    imagedestroy($im);

    // GIF real
    $im = imagecreatetruecolor(16, 16);
    $f['gif'] = $dir . '/valida.gif';
    imagegif($im, $f['gif']);
    imagedestroy($im);

    // PHP disfrazado de jpg
    $f['php_as_jpg'] = $dir . '/malicioso.jpg';
    file_put_contents($f['php_as_jpg'], "<?php echo 'pwned'; ?>\n");

    // Texto disfrazado de png (MIME falso)
    $f['txt_as_png'] = $dir . '/falsa.png';
    file_put_contents($f['txt_as_png'], "esto no es una imagen, es texto plano\n");

    // SVG (prohibido)
    $f['svg'] = $dir . '/vector.svg';
    file_put_contents($f['svg'], '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>');

    // Extensión no permitida
    $f['exe'] = $dir . '/programa.exe';
    file_put_contents($f['exe'], "MZ\x90\x00binario");

    // Imagen por encima del limite fisico por archivo. El tamano se
    // deriva de la constante: si el contrato cambia, el fixture sigue
    // siendo "demasiado grande" sin tener que tocarlo.
    $f['big'] = $dir . '/enorme.jpg';
    $fh = fopen($f['big'], 'wb');
    fwrite($fh, file_get_contents($f['jpg']));
    fwrite($fh, str_repeat("\x00", ATTACH_MAX_FILE_BYTES + 1048576));
    fclose($fh);

    // Nombre con tildes, espacios, comillas y HTML
    $f['weird'] = $dir . '/rarito.jpg';
    copy($f['jpg'], $f['weird']);

    return $f;
}

function drop_fixtures(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    foreach (glob($dir . '/*') ?: [] as $p) {
        @unlink($p);
    }
    @rmdir($dir);
}

// ─────────────────────────────────────────────────────────────
// Limpieza (idempotente: se ejecuta al principio y al final)
// ─────────────────────────────────────────────────────────────
function cleanup(mysqli $conn): array
{
    $removedFiles = 0;

    // Archivos físicos de adjuntos QA antes de borrar las filas
    $q = $conn->query(
        "SELECT a.stored_path FROM task_attachments a
         JOIN tasks t ON t.id = a.task_id
         JOIN boards b ON b.id = t.board_id
         WHERE b.nombre LIKE '" . QA_TAG . "%'"
    );
    foreach ($q->fetch_all(MYSQLI_ASSOC) as $r) {
        if (attach_delete_file((string) $r['stored_path'])) {
            $removedFiles++;
        }
    }

    // Tableros QA (cascada borra columnas, tareas, adjuntos y miembros)
    $conn->query("DELETE FROM boards WHERE nombre LIKE '" . QA_TAG . "%'");
    $boards = $conn->affected_rows;

    // Usuario QA
    $conn->query("DELETE FROM users WHERE email LIKE 'qa.attach.%@local.test'");
    $users = $conn->affected_rows;

    return ['files' => $removedFiles, 'boards' => $boards, 'users' => $users];
}

// ═════════════════════════════════════════════════════════════
// INICIO
// ═════════════════════════════════════════════════════════════
echo "\n";
echo "══════════════════════════════════════════════════════════════════════════\n";
echo " PRUEBAS DE BACKEND — ADJUNTOS DE TAREAS (Fase A)\n";
echo "══════════════════════════════════════════════════════════════════════════\n";
echo " Base   : " . $conn->query("SELECT DATABASE()")->fetch_row()[0] . "\n";
echo " URL    : " . BASE_URL . "\n";
echo " PHP    : " . PHP_VERSION . "\n";
echo " Fecha  : " . date('Y-m-d H:i:s') . "\n";

section('LIMPIEZA PREVIA');
$pre = cleanup($conn);
printf("  restos anteriores: %d tableros, %d usuarios, %d archivos\n",
    $pre['boards'], $pre['users'], $pre['files']);

$countBefore = [];
foreach (['boards', 'columns', 'tasks', 'users', 'board_members', 'task_attachments'] as $t) {
    $countBefore[$t] = (int) $conn->query("SELECT COUNT(*) FROM `$t`")->fetch_row()[0];
}
echo "  línea base: " . json_encode($countBefore) . "\n";

// ─────────────────────────────────────────────────────────────
section('PREPARACIÓN DE DATOS TEMPORALES');

$csrf = bin2hex(random_bytes(32));

// Usuario ajeno (nuevo, sin acceso a ningún tablero)
$hash = password_hash(bin2hex(random_bytes(12)), PASSWORD_DEFAULT);
$email = 'qa.attach.' . bin2hex(random_bytes(4)) . '@local.test';
$st = $conn->prepare("INSERT INTO users (nombre,email,pass_hash,estado,rol,is_admin,activo) VALUES (?,?,?, 'aprobado','user',0,1)");
$nom = 'QA Ajeno';
$st->bind_param('sss', $nom, $email, $hash);
$st->execute();
$U_AJENO = (int) $conn->insert_id;
$st->close();

$U_PROP   = 2;  // coordinador, is_admin=0
$U_EDITOR = 3;  // coordinador, is_admin=1 pero NO super_admin: sin atajos
$U_LECTOR = 4;  // user, is_admin=0

// Tablero personal QA
$st = $conn->prepare("INSERT INTO boards (nombre,color_hex,owner_user_id,team_id) VALUES (?, '#d32f57', ?, NULL)");
$bn = QA_TAG;
$st->bind_param('si', $bn, $U_PROP);
$st->execute();
$BOARD = (int) $conn->insert_id;
$st->close();

// Membresías
$st = $conn->prepare("INSERT INTO board_members (board_id,user_id,rol) VALUES (?,?,?)");
foreach ([[$U_PROP, 'propietario'], [$U_EDITOR, 'editor'], [$U_LECTOR, 'lector']] as [$uid, $rol]) {
    $st->bind_param('iis', $BOARD, $uid, $rol);
    $st->execute();
}
$st->close();

// Columna y tarea
$st = $conn->prepare("INSERT INTO `columns` (board_id,nombre,orden,is_done) VALUES (?, 'QA Col', 1, 0)");
$st->bind_param('i', $BOARD);
$st->execute();
$COL = (int) $conn->insert_id;
$st->close();

$st = $conn->prepare("INSERT INTO tasks (board_id,column_id,titulo,prioridad) VALUES (?,?, 'QA Tarea', 'med')");
$st->bind_param('ii', $BOARD, $COL);
$st->execute();
$TASK = (int) $conn->insert_id;
$st->close();

printf("  board=%d  column=%d  task=%d\n", $BOARD, $COL, $TASK);
printf("  propietario=%d  editor=%d  lector=%d  ajeno=%d\n", $U_PROP, $U_EDITOR, $U_LECTOR, $U_AJENO);

$S_PROP   = make_session($U_PROP, $csrf);
$S_EDITOR = make_session($U_EDITOR, $csrf);
$S_LECTOR = make_session($U_LECTOR, $csrf);
$S_AJENO  = make_session($U_AJENO, $csrf);
echo "  4 sesiones de prueba creadas\n";

$FX = build_fixtures($FIXTURES);
echo "  fixtures generados: " . count($FX) . "\n";

$UP  = BASE_URL . '/tasks/attachment_upload.php';
$DEL = BASE_URL . '/tasks/attachment_delete.php';
$GET = BASE_URL . '/tasks/attachment.php';
$AJAX = ['X-Requested-With: fetch', 'Accept: application/json'];

$createdIds = [];

// ═════════════════════════════════════════════════════════════
section('1-6 · VALIDACIÓN DE ARCHIVOS');

// 1. extensión válida + MIME válido
[$s, $h, $b] = http_request($UP, ['sessionId' => $S_EDITOR, 'headers' => $AJAX,
    'post' => ['csrf' => $csrf, 'task_id' => $TASK], 'files' => [$FX['jpg']]]);
$j = json_of($b);
if ($s === 200 && ($j['ok'] ?? false) === true && count($j['attachments'] ?? []) === 1) {
    $createdIds[] = (int) $j['attachments'][0]['id'];
    ok('1. JPG válido aceptado', 'id=' . $j['attachments'][0]['id'] . ' mime=' . $j['attachments'][0]['mime']);
} else {
    ko('1. JPG válido aceptado', "http=$s body=" . substr($b, 0, 160));
}

// 2. extensión falsa (PHP renombrado a .jpg)
[$s, $h, $b] = http_request($UP, ['sessionId' => $S_EDITOR, 'headers' => $AJAX,
    'post' => ['csrf' => $csrf, 'task_id' => $TASK], 'files' => [$FX['php_as_jpg']]]);
$j = json_of($b);
expect('2. PHP renombrado a .jpg rechazado', ($j['ok'] ?? true) === false ? 'rechazado' : 'ACEPTADO', 'rechazado', "http=$s");

// 3. MIME falso (texto con extensión .png)
[$s, $h, $b] = http_request($UP, ['sessionId' => $S_EDITOR, 'headers' => $AJAX,
    'post' => ['csrf' => $csrf, 'task_id' => $TASK], 'files' => [$FX['txt_as_png']]]);
$j = json_of($b);
expect('3. MIME falso rechazado', ($j['ok'] ?? true) === false ? 'rechazado' : 'ACEPTADO', 'rechazado', "http=$s");

// 4. SVG
[$s, $h, $b] = http_request($UP, ['sessionId' => $S_EDITOR, 'headers' => $AJAX,
    'post' => ['csrf' => $csrf, 'task_id' => $TASK], 'files' => [$FX['svg']]]);
$j = json_of($b);
expect('4. SVG rechazado', ($j['ok'] ?? true) === false ? 'rechazado' : 'ACEPTADO', 'rechazado', "http=$s");

// 5. extensión no permitida (.exe)
[$s, $h, $b] = http_request($UP, ['sessionId' => $S_EDITOR, 'headers' => $AJAX,
    'post' => ['csrf' => $csrf, 'task_id' => $TASK], 'files' => [$FX['exe']]]);
$j = json_of($b);
expect('5. Ejecutable .exe rechazado', ($j['ok'] ?? true) === false ? 'rechazado' : 'ACEPTADO', 'rechazado', "http=$s");

// 6. archivo demasiado grande
[$s, $h, $b] = http_request($UP, ['sessionId' => $S_EDITOR, 'headers' => $AJAX,
    'post' => ['csrf' => $csrf, 'task_id' => $TASK], 'files' => [$FX['big']]]);
$j = json_of($b);
expect('6. Imagen por encima del limite fisico rechazada', ($j['ok'] ?? true) === false ? 'rechazado' : 'ACEPTADO', 'rechazado', "http=$s");

// ═════════════════════════════════════════════════════════════
section('7-10 · NOMBRES, RUTAS Y LÍMITES');

// 7. Nombre con tildes, espacios, ampersand, porcentaje y ángulos.
//
//    Sin barras ni comillas a propósito: PHP aplica basename() a
//    $_FILES['name'] antes de que nuestro código lo vea, así que una barra
//    dentro del texto (por ejemplo "</b>") se recorta en el propio parser
//    de PHP y no hay forma de recuperarla. Las comillas, además, rompen la
//    cabecera Content-Disposition del multipart. Ambos son límites del
//    transporte, no de la aplicación: se cubren en 7b sobre la función.
$weirdName = 'día ñandú <informe> & 100% final.jpg';
[$s, $h, $b] = http_request($UP, ['sessionId' => $S_EDITOR, 'headers' => $AJAX,
    'post' => ['csrf' => $csrf, 'task_id' => $TASK],
    'files' => [['path' => $FX['weird'], 'name' => $weirdName]]]);
$j = json_of($b);
if (($j['ok'] ?? false) === true && !empty($j['attachments'])) {
    $a = $j['attachments'][0];
    $createdIds[] = (int) $a['id'];
    $sp = $conn->query("SELECT stored_path, original_name FROM task_attachments WHERE id=" . (int) $a['id'])->fetch_assoc();
    $stored = (string) $sp['original_name'];
    $pathOk = attach_is_valid_stored_path((string) $sp['stored_path']);

    if ($stored === $weirdName && $pathOk) {
        ok('7. Nombre con tildes y símbolos se conserva íntegro', 'guardado=' . $stored);
    } else {
        ko('7. Nombre con tildes y símbolos', "guardado='$stored' esperado='$weirdName' ruta_ok=" . var_export($pathOk, true));
    }
} else {
    ko('7. Nombre con tildes y símbolos aceptado', "http=$s body=" . substr($b, 0, 160));
}

// 7b. La función de saneado: neutraliza separadores SIN truncar el nombre.
//     Es la garantía de que un cliente no estándar no pueda colar una ruta.
$casos = [
    'a/b\\c.jpg'                    => 'a_b_c.jpg',
    '../../config/db.php.jpg'       => '.._.._config_db.php.jpg',
    'día ñandú "cotización".jpg'    => 'día ñandú "cotización".jpg',
    "salto\nlinea.jpg"              => 'saltolinea.jpg',
    '   espacios.jpg   '            => 'espacios.jpg',
];
$malos = [];
foreach ($casos as $in => $want) {
    $got = attach_sanitize_original_name($in);
    if ($got !== $want) {
        $malos[] = "'$in' -> '$got' (esperado '$want')";
    }
}
if (count($malos) === 0) {
    ok('7b. Saneado de nombres: separadores neutralizados sin truncar', count($casos) . ' casos');
} else {
    ko('7b. Saneado de nombres', implode(' | ', $malos));
}

// 8. stored_path válido para todos los adjuntos creados
$bad = 0;
foreach ($conn->query("SELECT stored_path FROM task_attachments")->fetch_all(MYSQLI_ASSOC) as $r) {
    if (!attach_is_valid_stored_path((string) $r['stored_path'])) {
        $bad++;
    }
}
expect('8. Todos los stored_path cumplen el patrón', $bad, 0);

// 9. path traversal rechazado por el validador
$traversals = [
    '../../../config/db.php',
    '2026/07/../../../../windows/win.ini',
    '..\\..\\config\\db.php',
    '/etc/passwd',
    '2026/07/archivo.php',
    '2026/13/aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa.jpg',
];
$rejected = 0;
foreach ($traversals as $t) {
    if (!attach_is_valid_stored_path($t) && attach_absolute_path($t) === null) {
        $rejected++;
    }
}
expect('9. Path traversal rechazado', $rejected, count($traversals));

// 10. máximo 5 archivos
$six = array_fill(0, 6, $FX['png']);
[$s, $h, $b] = http_request($UP, ['sessionId' => $S_EDITOR, 'headers' => $AJAX,
    'post' => ['csrf' => $csrf, 'task_id' => $TASK], 'files' => $six]);
$j = json_of($b);
expect('10. Más de 5 archivos rechazado', ($j['error'] ?? '') === 'too_many_files' ? 'too_many_files' : ($j['error'] ?? 'aceptado'), 'too_many_files', "http=$s");

// ═════════════════════════════════════════════════════════════
section('11-14 · PERMISOS');

// 11. lector no puede subir
[$s, $h, $b] = http_request($UP, ['sessionId' => $S_LECTOR, 'headers' => $AJAX,
    'post' => ['csrf' => $csrf, 'task_id' => $TASK], 'files' => [$FX['png']]]);
$j = json_of($b);
expect('11. Lector NO puede subir', $s . '/' . ($j['error'] ?? '-'), '403/forbidden');

// 12. editor sí puede subir
[$s, $h, $b] = http_request($UP, ['sessionId' => $S_EDITOR, 'headers' => $AJAX,
    'post' => ['csrf' => $csrf, 'task_id' => $TASK], 'files' => [$FX['png']]]);
$j = json_of($b);
if (($j['ok'] ?? false) === true && !empty($j['attachments'])) {
    $createdIds[] = (int) $j['attachments'][0]['id'];
    ok('12. Editor SÍ puede subir', 'id=' . $j['attachments'][0]['id']);
} else {
    ko('12. Editor SÍ puede subir', "http=$s body=" . substr($b, 0, 160));
}

$readId = $createdIds[0] ?? 0;

// 13. ajeno no puede leer
[$s, $h, $b] = http_request($GET . '?id=' . $readId, ['sessionId' => $S_AJENO]);
expect('13. Ajeno al tablero NO puede leer', $s, 403);

// 14. lector sí puede leer
[$s, $h, $b] = http_request($GET . '?id=' . $readId, ['sessionId' => $S_LECTOR]);
$ct = header_value($h, 'Content-Type');
expect('14. Lector SÍ puede leer', $s, 200, "content-type=$ct");

// ═════════════════════════════════════════════════════════════
section('15-16 · PETICIONES RANGE');

// tamaño real del adjunto
$sz = (int) $conn->query("SELECT size_bytes FROM task_attachments WHERE id=$readId")->fetch_row()[0];

// 15. Range válido -> 206
[$s, $h, $b] = http_request($GET . '?id=' . $readId,
    ['sessionId' => $S_LECTOR, 'headers' => ['Range: bytes=0-99']]);
$cr = header_value($h, 'Content-Range');
$cl = header_value($h, 'Content-Length');
$ar = header_value($h, 'Accept-Ranges');
if ($s === 206 && $cl === '100' && $cr === "bytes 0-99/$sz" && strlen($b) === 100) {
    ok('15. Range válido devuelve 206', "Content-Range=$cr bytes=" . strlen($b) . " Accept-Ranges=$ar");
} else {
    ko('15. Range válido devuelve 206', "http=$s CL=$cl CR=$cr recibidos=" . strlen($b));
}

// 15b. Range de sufijo
[$s2, $h2, $b2] = http_request($GET . '?id=' . $readId,
    ['sessionId' => $S_LECTOR, 'headers' => ['Range: bytes=-50']]);
expect('15b. Range de sufijo devuelve 206', $s2 . '/' . strlen($b2), '206/50');

// 16. Range inválido -> 416
[$s3, $h3, $b3] = http_request($GET . '?id=' . $readId,
    ['sessionId' => $S_LECTOR, 'headers' => ['Range: bytes=' . ($sz + 5000) . '-' . ($sz + 6000)]]);
$cr3 = header_value($h3, 'Content-Range');
expect('16. Range no satisfactible devuelve 416', $s3, 416, "Content-Range=$cr3");

[$s4, , ] = http_request($GET . '?id=' . $readId,
    ['sessionId' => $S_LECTOR, 'headers' => ['Range: bytes=abc-xyz']]);
expect('16b. Range mal formado devuelve 416', $s4, 416);

// 16c. descarga
[$s5, $h5, ] = http_request($GET . '?id=' . $readId . '&download=1', ['sessionId' => $S_LECTOR]);
$cd = header_value($h5, 'Content-Disposition');
expect('16c. ?download=1 fuerza attachment', str_starts_with($cd, 'attachment') ? 'attachment' : $cd, 'attachment');

// 16d. cabeceras de seguridad
[$s6, $h6, ] = http_request($GET . '?id=' . $readId, ['sessionId' => $S_LECTOR]);
$nos = header_value($h6, 'X-Content-Type-Options');
$cc  = header_value($h6, 'Cache-Control');
$et  = header_value($h6, 'ETag');
if ($nos === 'nosniff' && str_contains($cc, 'private') && $et !== '') {
    ok('16d. Cabeceras de seguridad presentes', "nosniff · $cc · ETag ok");
} else {
    ko('16d. Cabeceras de seguridad', "nosniff=$nos cache=$cc etag=$et");
}

// ═════════════════════════════════════════════════════════════
section('17-19 · ELIMINACIÓN');

$delId = $createdIds[count($createdIds) - 1] ?? 0;

// 17. CSRF inválido
[$s, $h, $b] = http_request($DEL, ['sessionId' => $S_EDITOR, 'headers' => $AJAX,
    'post' => ['csrf' => 'token_invalido', 'attachment_id' => $delId]]);
$j = json_of($b);
expect('17. Eliminar con CSRF inválido rechazado', $s . '/' . ($j['error'] ?? '-'), '403/csrf');

// 18. lector no puede eliminar
[$s, $h, $b] = http_request($DEL, ['sessionId' => $S_LECTOR, 'headers' => $AJAX,
    'post' => ['csrf' => $csrf, 'attachment_id' => $delId]]);
$j = json_of($b);
expect('18. Lector NO puede eliminar', $s . '/' . ($j['error'] ?? '-'), '403/forbidden');

// comprobar que sigue existiendo tras los intentos fallidos
$still = (int) $conn->query("SELECT COUNT(*) FROM task_attachments WHERE id=$delId")->fetch_row()[0];
expect('18b. El adjunto sobrevivió a los intentos fallidos', $still, 1);

// 19. editor sí puede eliminar
$spBefore = (string) $conn->query("SELECT stored_path FROM task_attachments WHERE id=$delId")->fetch_row()[0];
$fileBefore = attach_absolute_path($spBefore) !== null;
[$s, $h, $b] = http_request($DEL, ['sessionId' => $S_EDITOR, 'headers' => $AJAX,
    'post' => ['csrf' => $csrf, 'attachment_id' => $delId]]);
$j = json_of($b);
$rowGone  = (int) $conn->query("SELECT COUNT(*) FROM task_attachments WHERE id=$delId")->fetch_row()[0] === 0;
$fileGone = attach_absolute_path($spBefore) === null;
if (($j['ok'] ?? false) === true && $rowGone && $fileGone && $fileBefore) {
    ok('19. Editor elimina: fila y archivo fuera', 'id=' . $delId);
    $createdIds = array_values(array_diff($createdIds, [$delId]));
} else {
    ko('19. Editor elimina', "http=$s ok=" . var_export($j['ok'] ?? null, true)
        . " fila_fuera=" . var_export($rowGone, true) . " archivo_fuera=" . var_export($fileGone, true));
}

// ═════════════════════════════════════════════════════════════
section('20 · COMPENSACIÓN ANTE FALLO DE INSERT');

// Se fuerza el fallo del INSERT rompiendo temporalmente la FK:
// se usa un task_id inexistente. El endpoint debe responder error
// y NO dejar ningún archivo suelto en storage.
$before = count(glob(attach_storage_root() . '/' . date('Y') . '/' . date('m') . '/*') ?: []);
[$s, $h, $b] = http_request($UP, ['sessionId' => $S_EDITOR, 'headers' => $AJAX,
    'post' => ['csrf' => $csrf, 'task_id' => 999999999], 'files' => [$FX['jpg']]]);
$j = json_of($b);
$after = count(glob(attach_storage_root() . '/' . date('Y') . '/' . date('m') . '/*') ?: []);
if (($j['ok'] ?? true) === false && $after === $before) {
    ok('20. Fallo de INSERT no deja archivo huérfano', 'error=' . ($j['error'] ?? '?') . " archivos antes=$before después=$after");
} else {
    ko('20. Fallo de INSERT no deja archivo huérfano', "ok=" . var_export($j['ok'] ?? null, true) . " antes=$before después=$after");
}

// Extra: sin sesión no se puede leer
[$s, , ] = http_request($GET . '?id=' . $readId);
expect('20b. Sin sesión no se puede leer', in_array($s, [302, 401, 403], true) ? 'bloqueado' : "http=$s", 'bloqueado');

// ═════════════════════════════════════════════════════════════
section('21-27 · LÍMITE TOTAL DE LA PETICIÓN (bloque G2)');

/** Fabrica un JPEG válido de aproximadamente los bytes pedidos. */
function fixture_de_tamano(string $dir, string $nombre, int $bytes): string
{
    $ruta = $dir . '/' . $nombre;
    $im = imagecreatetruecolor(64, 64);
    imagefill($im, 0, 0, imagecolorallocate($im, 10, 90, 160));
    imagejpeg($im, $ruta, 90);
    $faltan = $bytes - (int) filesize($ruta);
    if ($faltan > 0) {
        // El relleno va DESPUÉS del JPEG: sigue siendo una imagen válida
        // para finfo y getimagesize, solo que más pesada.
        file_put_contents($ruta, str_repeat("\x00", $faltan), FILE_APPEND);
    }
    imagedestroy($im);
    return $ruta;
}

$MB = 1048576;
$LIM = ATTACH_MAX_REQUEST_BYTES;

// 21. El control del cuerpo descartado está ANTES del CSRF
$srcUp = (string) file_get_contents(dirname(__DIR__) . '/public/tasks/attachment_upload.php');
$posPayload = strpos($srcUp, 'payload_too_large');
$posCsrf    = strpos($srcUp, 'attach_csrf_ok()');
$posTotal   = strpos($srcUp, 'request_too_large');
$posTrx     = strpos($srcUp, 'begin_transaction');
$posMove    = strpos($srcUp, 'move_uploaded_file');
if ($posPayload !== false && $posCsrf !== false && $posPayload < $posCsrf) {
    ok('21. La detección de cuerpo descartado precede al CSRF', "413 en $posPayload, csrf en $posCsrf");
} else {
    ko('21. La detección de cuerpo descartado precede al CSRF', "413=$posPayload csrf=$posCsrf");
}

// 22. La condición es estrecha: exige multipart y AMBOS superglobales vacíos
$condicionEstrecha = str_contains($srcUp, "str_starts_with(\$contentType, 'multipart/form-data')")
    && str_contains($srcUp, '$_POST === []')
    && str_contains($srcUp, '$_FILES === []')
    && str_contains($srcUp, '$contentLength > 0');
expect('22. La detección exige multipart, cuerpo y ambos vacíos',
    $condicionEstrecha ? 'estrecha' : 'LAXA', 'estrecha');

// 23. El control del total ocurre antes de tocar base de datos o disco
if ($posTotal !== false && $posTotal < $posTrx && $posTotal < $posMove) {
    ok('23. El límite total se aplica antes de cualquier mutación', 'sin transacción ni move previos');
} else {
    ko('23. El límite total se aplica antes de cualquier mutación', "total=$posTotal trx=$posTrx move=$posMove");
}

// 24. El límite sale de la constante, no de un número mágico
$derivado = str_contains($srcUp, 'ATTACH_MAX_REQUEST_BYTES')
    && !preg_match('/\$totalBytes\s*>\s*\d{6,}/', $srcUp);
expect('24. El límite deriva de ATTACH_MAX_REQUEST_BYTES',
    $derivado ? 'derivado' : 'NUMERO MAGICO', 'derivado',
    'límite=' . (int) round($LIM / $MB) . ' MB');

// 25. Dos archivos válidos por separado pero que juntos se pasan
$filasAntes = (int) $conn->query("SELECT COUNT(*) FROM task_attachments WHERE task_id=$TASK")->fetch_row()[0];
$discoAntes = count(glob(attach_storage_root() . '/*/*/*') ?: []);

$g1 = fixture_de_tamano($FIXTURES, 'total_a.jpg', (int) ($LIM * 0.6));
$g2 = fixture_de_tamano($FIXTURES, 'total_b.jpg', (int) ($LIM * 0.6));
$sumaEnviada = filesize($g1) + filesize($g2);

[$s, , $b] = http_request($UP, ['sessionId' => $S_EDITOR, 'headers' => $AJAX,
    'post' => ['csrf' => $csrf, 'task_id' => $TASK], 'files' => [$g1, $g2]]);
$j = json_of($b);
expect('25. Suma por encima del límite devuelve 413 request_too_large',
    ($j['error'] ?? '') === 'request_too_large' && $s === 413 ? 'rechazado' : "http=$s/" . ($j['error'] ?? '?'),
    'rechazado',
    'cada uno ' . round(filesize($g1) / $MB, 1) . ' MB, suma ' . round($sumaEnviada / $MB, 1) . ' MB');

// 26. Todo o nada: ni filas ni archivos
$filasDespues = (int) $conn->query("SELECT COUNT(*) FROM task_attachments WHERE task_id=$TASK")->fetch_row()[0];
clearstatcache(true);
$discoDespues = count(glob(attach_storage_root() . '/*/*/*') ?: []);
expect('26. No se guarda nada: ni filas ni archivos',
    ($filasDespues === $filasAntes && $discoDespues === $discoAntes) ? 'todo o nada' : 'PARCIAL',
    'todo o nada', "filas $filasAntes→$filasDespues · disco $discoAntes→$discoDespues");

// 27. Un conjunto por debajo del límite sigue funcionando
$p1 = fixture_de_tamano($FIXTURES, 'total_c.jpg', 256 * 1024);
$p2 = fixture_de_tamano($FIXTURES, 'total_d.jpg', 256 * 1024);
[$s, , $b] = http_request($UP, ['sessionId' => $S_EDITOR, 'headers' => $AJAX,
    'post' => ['csrf' => $csrf, 'task_id' => $TASK], 'files' => [$p1, $p2]]);
$j = json_of($b);
$aceptados = count($j['attachments'] ?? []);
expect('27. Un conjunto pequeño sigue aceptándose',
    (($j['ok'] ?? false) === true && $aceptados === 2) ? 'aceptado' : "http=$s aceptados=$aceptados",
    'aceptado', 'suma ' . round((filesize($p1) + filesize($p2)) / $MB, 2) . ' MB');
foreach ($j['attachments'] ?? [] as $a) {
    $createdIds[] = (int) $a['id'];
}

// 27b. El CSRF sigue siendo obligatorio en una petición normal
[$s, , $b] = http_request($UP, ['sessionId' => $S_EDITOR, 'headers' => $AJAX,
    'post' => ['csrf' => 'token-invalido', 'task_id' => $TASK], 'files' => [$p1]]);
$j = json_of($b);
expect('27b. CSRF inválido sigue devolviendo 403',
    ($s === 403 && ($j['error'] ?? '') === 'csrf') ? 'bloqueado' : "http=$s/" . ($j['error'] ?? '?'),
    'bloqueado');

foreach (['total_a.jpg', 'total_b.jpg', 'total_c.jpg', 'total_d.jpg'] as $tmpFx) {
    @unlink($FIXTURES . '/' . $tmpFx);
}

// ═════════════════════════════════════════════════════════════
section('LIMPIEZA');

$storedPaths = [];
foreach ($conn->query(
    "SELECT a.stored_path FROM task_attachments a
     JOIN tasks t ON t.id=a.task_id JOIN boards b ON b.id=t.board_id
     WHERE b.nombre LIKE '" . QA_TAG . "%'"
)->fetch_all(MYSQLI_ASSOC) as $r) {
    $storedPaths[] = (string) $r['stored_path'];
}

$post = cleanup($conn);
printf("  eliminados: %d tableros, %d usuarios, %d archivos\n", $post['boards'], $post['users'], $post['files']);

foreach ([$S_PROP, $S_EDITOR, $S_LECTOR, $S_AJENO] as $sid) {
    drop_session($sid);
}
drop_fixtures($FIXTURES);
echo "  sesiones y fixtures eliminados\n";

// Verificación de residuos
$orphans = 0;
foreach ($storedPaths as $sp) {
    if (attach_absolute_path($sp) !== null) {
        $orphans++;
    }
}
expect('LIMPIEZA · archivos huérfanos', $orphans, 0);
expect('LIMPIEZA · tableros QA', (int) $conn->query("SELECT COUNT(*) FROM boards WHERE nombre LIKE '" . QA_TAG . "%'")->fetch_row()[0], 0);
expect('LIMPIEZA · usuarios QA', (int) $conn->query("SELECT COUNT(*) FROM users WHERE email LIKE 'qa.attach.%@local.test'")->fetch_row()[0], 0);

$countAfter = [];
foreach (array_keys($countBefore) as $t) {
    $countAfter[$t] = (int) $conn->query("SELECT COUNT(*) FROM `$t`")->fetch_row()[0];
}
foreach ($countBefore as $t => $n) {
    expect("LIMPIEZA · filas en $t", $countAfter[$t], $n);
}

// Carpetas AAAA/MM vacías creadas por las pruebas
$monthDir = attach_storage_root() . '/' . date('Y') . '/' . date('m');
if (is_dir($monthDir) && count(glob($monthDir . '/*') ?: []) === 0) {
    @rmdir($monthDir);
    @rmdir(dirname($monthDir));
}

// ═════════════════════════════════════════════════════════════
echo "\n" . str_repeat('═', 74) . "\n";
printf(" RESULTADO: %d correctas, %d fallidas\n", $PASS, $FAIL);
echo str_repeat('═', 74) . "\n";
exit($FAIL === 0 ? 0 : 1);
