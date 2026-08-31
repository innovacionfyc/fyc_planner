<?php
/**
 * tests/attachments_lifecycle_smoke.php
 *
 * Pruebas del ciclo de vida de los adjuntos (Fase F, bloque F1):
 * borrado físico al eliminar tareas y purgar tableros, y cron de huérfanos.
 *
 * Ejecutar SOLO en local:
 *   php tests/attachments_lifecycle_smoke.php
 *
 * Qué hace:
 *   1. Crea datos temporales (usuario, tablero, columna, tareas QA).
 *   2. Ejercita por HTTP el borrado real de tareas y la purga de tableros.
 *   3. Prueba el cron de huérfanos con archivos fabricados a propósito.
 *   4. Comprueba que NADA legítimo se pierde por el camino.
 *   5. Limpia absolutamente todo.
 *
 * Si el script se interrumpe, volver a ejecutarlo limpia los restos.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Este script solo puede ejecutarse desde CLI.\n");
}

require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/_qa_users.php';
require_once __DIR__ . '/../public/_attachments.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
app_sync_db_timezone($conn);

const QA_TAG      = 'QA LIFECYCLE 2026-07-31';
// Etiqueta corta de esta suite. Entra en el correo de sus usuarios QA
// (qa.<suite>.<aleatorio>@local.test) y permite retirar restos de una
// ejecucion que se interrumpiera antes de limpiar.
const QA_SUITE    = 'attachlife';
const BASE_URL    = 'http://localhost/fyc_planner/public';
const SESSION_DIR = 'C:/laragon/tmp';

$CRON_ORPHAN = dirname(__DIR__) . '/cron/purge_orphan_attachments.php';
$CRON_TRASH  = dirname(__DIR__) . '/cron/purge_trash.php';

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

// ─────────────────────────────────────────────────────────────
// HTTP y sesiones (mismo mecanismo que las suites A-E)
// ─────────────────────────────────────────────────────────────
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
        $e = curl_error($ch);
        curl_close($ch);
        return [0, '', 'CURL_ERROR: ' . $e];
    }
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $hSize  = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);
    return [$status, substr($raw, 0, $hSize), substr($raw, $hSize)];
}

function json_of(string $b): array
{
    $d = json_decode($b, true);
    return is_array($d) ? $d : [];
}

function make_session(int $userId, string $csrf): string
{
    $sid  = bin2hex(random_bytes(16));
    $data = 'user_id|i:' . $userId . ';csrf|s:' . strlen($csrf) . ':"' . $csrf . '";'
        . '_auth_ts|i:' . time() . ';';
    file_put_contents(SESSION_DIR . DIRECTORY_SEPARATOR . 'sess_' . $sid, $data);
    return $sid;
}

// ─────────────────────────────────────────────────────────────
// Utilidades de almacén
// ─────────────────────────────────────────────────────────────
function abs_of(string $rel): string
{
    return attach_storage_root() . DIRECTORY_SEPARATOR
        . str_replace('/', DIRECTORY_SEPARATOR, $rel);
}

/**
 * ¿Existe el archivo AHORA MISMO?
 *
 * Imprescindible limpiar la caché de stat antes de mirar: aquí quien borra
 * es Apache o un cron, es decir OTRO proceso. PHP recuerda el resultado de
 * la primera consulta y seguiría afirmando que el archivo sigue ahí mucho
 * después de que haya desaparecido.
 */
function existe(string $abs): bool
{
    clearstatcache(true, $abs);
    return is_file($abs);
}

/** Igual que existe(), pero partiendo de la ruta relativa. */
function existe_rel(string $rel): bool
{
    return existe(abs_of($rel));
}

/** Crea un archivo físico suelto (sin fila) con contenido y fecha dados. */
function make_loose_file(string $rel, int $mtime = 0): string
{
    $abs = abs_of($rel);
    @mkdir(dirname($abs), 0775, true);
    file_put_contents($abs, 'contenido de prueba ' . bin2hex(random_bytes(4)));
    if ($mtime > 0) {
        touch($abs, $mtime);
    }
    return $abs;
}

/** Ruta relativa nueva con el patrón real del módulo. */
function fake_rel(string $ext = 'jpg'): string
{
    return date('Y') . '/' . date('m') . '/' . bin2hex(random_bytes(16)) . '.' . $ext;
}

// ─────────────────────────────────────────────────────────────
// Almacen QA aislado para el cron de huerfanos
//
// El cron recorre un almacen y borra lo que no tenga fila en base. Apuntandolo
// al almacen REAL, una ejecucion sobre datos ricos podria llevarse archivos
// que no son de la prueba. Aqui se le da su propia carpeta temporal con el
// mismo formato AAAA/MM, de modo que las mismas garantias se comprueban sin
// que el almacen de verdad entre siquiera en el recorrido.
// ─────────────────────────────────────────────────────────────
$QA_STORAGE = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'fyc_qa_storage_' . bin2hex(random_bytes(6));
@mkdir($QA_STORAGE . DIRECTORY_SEPARATOR . date('Y') . DIRECTORY_SEPARATOR . date('m'), 0775, true);
file_put_contents($QA_STORAGE . DIRECTORY_SEPARATOR . '.gitkeep', '');
file_put_contents($QA_STORAGE . DIRECTORY_SEPARATOR . '.htaccess', "Require all denied\n");

function abs_qa(string $rel): string
{
    global $QA_STORAGE;
    return $QA_STORAGE . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel);
}

function existe_qa(string $rel): bool
{
    return existe(abs_qa($rel));
}

/** Archivo suelto dentro del almacen QA, con fecha de modificacion opcional. */
function make_qa_file(string $rel, int $mtime = 0): string
{
    $abs = abs_qa($rel);
    @mkdir(dirname($abs), 0775, true);
    file_put_contents($abs, 'contenido de prueba ' . bin2hex(random_bytes(4)));
    if ($mtime > 0) {
        touch($abs, $mtime);
    }
    return $abs;
}

/** Cuantos archivos hay en el almacen REAL, para probar que no se toca. */
function contar_almacen_real(): int
{
    $root = attach_storage_root();
    $n = 0;
    foreach (@scandir($root) ?: [] as $y) {
        if (!preg_match('/^\d{4}$/', $y)) {
            continue;
        }
        foreach (@scandir("$root/$y") ?: [] as $m) {
            if (!preg_match('/^\d{2}$/', $m)) {
                continue;
            }
            $n += count(array_diff(@scandir("$root/$y/$m") ?: [], ['.', '..']));
        }
    }
    return $n;
}

function run_cron(string $script, array $args = []): array
{
    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script);
    foreach ($args as $a) {
        $cmd .= ' ' . escapeshellarg($a);
    }
    $out = [];
    $code = 0;
    exec($cmd . ' 2>&1', $out, $code);
    return [$code, implode("\n", $out)];
}

function cron_num(string $out, string $campo): int
{
    return preg_match('/^\s*' . $campo . '\s*:\s*(\d+)/m', $out, $m) ? (int) $m[1] : -1;
}

function build_fixtures(string $dir): array
{
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }
    $f = [];

    $im = imagecreatetruecolor(40, 30);
    imagefill($im, 0, 0, imagecolorallocate($im, 40, 160, 90));
    $f['jpg'] = $dir . '/ciclo.jpg';
    imagejpeg($im, $f['jpg'], 85);
    imagedestroy($im);

    $im = imagecreatetruecolor(24, 24);
    $f['png'] = $dir . '/ciclo.png';
    imagepng($im, $f['png']);
    imagedestroy($im);

    // MP3 mínimo. El relleno NO puede ir entre la cabecera ID3 y el primer
    // frame: libmagic busca el sync justo detrás y con basura en medio
    // devuelve application/octet-stream, que el módulo rechaza con razón.
    $f['mp3'] = $dir . '/ciclo.mp3';
    file_put_contents($f['mp3'], "ID3\x04\x00\x00\x00\x00\x00\x00"
        . str_repeat("\xFF\xFB\x90\x00" . str_repeat("\x00", 100), 8));

    // MP4 mínimo con ftyp real (finfo lo reconoce como video/mp4)
    $f['mp4'] = $dir . '/ciclo.mp4';
    $ftyp = "\x00\x00\x00\x20" . 'ftyp' . 'isom' . "\x00\x00\x02\x00"
        . 'isomiso2avc1mp41';
    file_put_contents($f['mp4'], $ftyp . str_repeat("\x00", 1200));

    return $f;
}

function drop_fixtures(string $dir): void
{
    foreach (glob($dir . '/*') ?: [] as $p) {
        @unlink($p);
    }
    @rmdir($dir);
}

// ─────────────────────────────────────────────────────────────
// Limpieza idempotente
// ─────────────────────────────────────────────────────────────
function cleanup(mysqli $conn): array
{
    $files = 0;
    $q = $conn->query(
        "SELECT a.stored_path FROM task_attachments a
          JOIN tasks t ON t.id = a.task_id
          JOIN boards b ON b.id = t.board_id
         WHERE b.nombre LIKE '" . QA_TAG . "%' AND a.stored_path IS NOT NULL"
    );
    foreach ($q->fetch_all(MYSQLI_ASSOC) as $r) {
        if (attach_delete_file((string) $r['stored_path'])) {
            $files++;
        }
    }
    $conn->query("DELETE FROM boards WHERE nombre LIKE '" . QA_TAG . "%'");
    $boards = $conn->affected_rows;
    // Usuarios QA de esta suite: se retiran por identificador junto con sus
    // tableros, equipos y avisos. Cubre tambien restos de una ejecucion
    // anterior que se interrumpiera antes de limpiar.
    qa_users_cleanup_stale($conn, QA_SUITE);
    $conn->query("DELETE FROM users WHERE email LIKE 'qa.life.%@local.test'");
    $users = $conn->affected_rows;
    return ['files' => $files, 'boards' => $boards, 'users' => $users];
}

/** Inventario completo del almacén: rutas relativas de todos los archivos. */
function scan_storage(): array
{
    $root = attach_storage_root();
    $out  = [];
    foreach (@scandir($root) ?: [] as $y) {
        if ($y === '.' || $y === '..' || !is_dir($root . '/' . $y)) {
            continue;
        }
        foreach (@scandir($root . '/' . $y) ?: [] as $m) {
            if ($m === '.' || $m === '..' || !is_dir("$root/$y/$m")) {
                continue;
            }
            foreach (@scandir("$root/$y/$m") ?: [] as $f) {
                if ($f === '.' || $f === '..') {
                    continue;
                }
                $out[] = "$y/$m/$f";
            }
        }
    }
    sort($out);
    return $out;
}

// ═════════════════════════════════════════════════════════════
echo "\n";
echo "══════════════════════════════════════════════════════════════════════════════\n";
echo " PRUEBAS DE CICLO DE VIDA Y HUÉRFANOS — ADJUNTOS (Fase F · bloque F1)\n";
echo "══════════════════════════════════════════════════════════════════════════════\n";
echo " Base  : " . $conn->query("SELECT DATABASE()")->fetch_row()[0] . "\n";
echo " PHP   : " . PHP_VERSION . "\n";
echo " Fecha : " . date('Y-m-d H:i:s') . "\n";

section('LIMPIEZA PREVIA');
$pre = cleanup($conn);
printf("  restos anteriores: %d tableros, %d usuarios, %d archivos\n",
    $pre['boards'], $pre['users'], $pre['files']);

$storageAntes = scan_storage();
$filasAntes   = (int) $conn->query("SELECT COUNT(*) FROM task_attachments")->fetch_row()[0];
printf("  almacén al empezar: %d archivos | task_attachments: %d filas\n",
    count($storageAntes), $filasAntes);

// ─────────────────────────────────────────────────────────────
section('PREPARACIÓN');

$csrf  = bin2hex(random_bytes(32));
// Propietario QA propio en lugar del usuario 2 de la base.
$U_OWN = qa_user($conn, QA_SUITE, ['rol' => 'user', 'is_admin' => 0]);

$st = $conn->prepare("INSERT INTO boards (nombre,color_hex,owner_user_id,team_id) VALUES (?, '#2a9d8f', ?, NULL)");
$bn = QA_TAG;
$st->bind_param('si', $bn, $U_OWN);
$st->execute();
$BOARD = (int) $conn->insert_id;
$st->close();

$st = $conn->prepare("INSERT INTO board_members (board_id,user_id,rol) VALUES (?,?, 'propietario')");
$st->bind_param('ii', $BOARD, $U_OWN);
$st->execute();
$st->close();

$st = $conn->prepare("INSERT INTO `columns` (board_id,nombre,orden,is_done) VALUES (?, 'QA Col', 1, 0)");
$st->bind_param('i', $BOARD);
$st->execute();
$COL = (int) $conn->insert_id;
$st->close();

function nueva_tarea(mysqli $conn, int $board, int $col, string $titulo): int
{
    $st = $conn->prepare("INSERT INTO tasks (board_id,column_id,titulo,prioridad) VALUES (?,?,?, 'med')");
    $st->bind_param('iis', $board, $col, $titulo);
    $st->execute();
    $id = (int) $conn->insert_id;
    $st->close();
    return $id;
}

$SESS = make_session($U_OWN, $csrf);
$FIXDIR = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'fyc_lifecycle_fx';
$FX = build_fixtures($FIXDIR);

$UP    = BASE_URL . '/tasks/attachment_upload.php';
$LINK  = BASE_URL . '/tasks/attachment_link.php';
$TDEL  = BASE_URL . '/tasks/delete.php';
$PURGE = BASE_URL . '/boards/trash_purge.php';
$AJAX  = ['X-Requested-With: fetch', 'Accept: application/json'];

printf("  board=%d  column=%d  usuario=%d\n", $BOARD, $COL, $U_OWN);
echo "  fixtures: " . implode(', ', array_keys($FX)) . "\n";

/** Sube un archivo a una tarea y devuelve [id, stored_path] leyendo la base. */
function subir(mysqli $conn, string $UP, string $sess, array $AJAX, string $csrf, int $task, string $file): array
{
    [$s, $h, $b] = http_request($UP, ['sessionId' => $sess, 'headers' => $AJAX,
        'post' => ['csrf' => $csrf, 'task_id' => $task], 'files' => [$file]]);
    $j = json_of($b);
    if (($j['ok'] ?? false) !== true || empty($j['attachments'][0]['id'])) {
        return [0, '', "http=$s " . substr($b, 0, 140)];
    }
    $id = (int) $j['attachments'][0]['id'];
    $r = $conn->query("SELECT stored_path FROM task_attachments WHERE id = $id")->fetch_row();
    return [$id, (string) ($r[0] ?? ''), ''];
}

// ═════════════════════════════════════════════════════════════
section('1-6 · BORRADO DE TAREA: LOS ARCHIVOS SE VAN CON ELLA');

// 1. imagen
$T1 = nueva_tarea($conn, $BOARD, $COL, 'QA borrar imagen');
[$a1, $p1, $e1] = subir($conn, $UP, $SESS, $AJAX, $csrf, $T1, $FX['jpg']);
$existia1 = ($p1 !== '' && existe_rel(($p1)));
[$s, $h, $b] = http_request($TDEL, ['sessionId' => $SESS, 'headers' => $AJAX,
    'post' => ['csrf' => $csrf, 'task_id' => $T1, 'board_id' => $BOARD]]);
$j = json_of($b);
chk('1. Borrar tarea con imagen elimina el archivo',
    $existia1 && ($j['ok'] ?? false) === true && !existe_rel(($p1)),
    'existía=' . var_export($existia1, true) . ' borrados=' . ($j['attachments']['deleted'] ?? '?'));

// 2. audio
$T2 = nueva_tarea($conn, $BOARD, $COL, 'QA borrar audio');
[$a2, $p2, $e2] = subir($conn, $UP, $SESS, $AJAX, $csrf, $T2, $FX['mp3']);
$existia2 = ($p2 !== '' && existe_rel(($p2)));
[$s, $h, $b] = http_request($TDEL, ['sessionId' => $SESS, 'headers' => $AJAX,
    'post' => ['csrf' => $csrf, 'task_id' => $T2, 'board_id' => $BOARD]]);
chk('2. Borrar tarea con audio elimina el archivo',
    $existia2 && !existe_rel(($p2)), $e2 ?: 'ruta=' . substr($p2, 0, 20) . '…');

// 3. video
$T3 = nueva_tarea($conn, $BOARD, $COL, 'QA borrar video');
[$a3, $p3, $e3] = subir($conn, $UP, $SESS, $AJAX, $csrf, $T3, $FX['mp4']);
$existia3 = ($p3 !== '' && existe_rel(($p3)));
[$s, $h, $b] = http_request($TDEL, ['sessionId' => $SESS, 'headers' => $AJAX,
    'post' => ['csrf' => $csrf, 'task_id' => $T3, 'board_id' => $BOARD]]);
chk('3. Borrar tarea con video elimina el archivo',
    $existia3 && !existe_rel(($p3)), $e3 ?: 'ruta=' . substr($p3, 0, 20) . '…');

// 4. enlace: no hay archivo que borrar y no debe fallar
$T4 = nueva_tarea($conn, $BOARD, $COL, 'QA borrar enlace');
[$s, $h, $b] = http_request($LINK, ['sessionId' => $SESS, 'headers' => $AJAX,
    'post' => ['csrf' => $csrf, 'task_id' => $T4, 'url' => 'https://example.com/documento']]);
$linkOk = (json_of($b)['ok'] ?? false) === true;
[$s, $h, $b] = http_request($TDEL, ['sessionId' => $SESS, 'headers' => $AJAX,
    'post' => ['csrf' => $csrf, 'task_id' => $T4, 'board_id' => $BOARD]]);
$j = json_of($b);
chk('4. Borrar tarea con enlace no toca el disco',
    $linkOk && ($j['ok'] ?? false) === true && (int) ($j['attachments']['total'] ?? -1) === 0,
    'total=' . ($j['attachments']['total'] ?? '?'));

// 5. tarea mixta: solo desaparece el archivo, el enlace no da guerra
$T5 = nueva_tarea($conn, $BOARD, $COL, 'QA borrar mixta');
[$a5, $p5, $e5] = subir($conn, $UP, $SESS, $AJAX, $csrf, $T5, $FX['png']);
http_request($LINK, ['sessionId' => $SESS, 'headers' => $AJAX,
    'post' => ['csrf' => $csrf, 'task_id' => $T5, 'url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ']]);
$existia5 = ($p5 !== '' && existe_rel(($p5)));
[$s, $h, $b] = http_request($TDEL, ['sessionId' => $SESS, 'headers' => $AJAX,
    'post' => ['csrf' => $csrf, 'task_id' => $T5, 'board_id' => $BOARD]]);
$j = json_of($b);
chk('5. Tarea con archivo + embed: solo se borra el archivo',
    $existia5 && !existe_rel(($p5)) && (int) ($j['attachments']['total'] ?? -1) === 1,
    'total=' . ($j['attachments']['total'] ?? '?') . ' (el embed no cuenta)');

// 6. las filas también desaparecen (cascada)
$quedan = (int) $conn->query(
    "SELECT COUNT(*) FROM task_attachments WHERE task_id IN ($T1,$T2,$T3,$T4,$T5)"
)->fetch_row()[0];
chk('6. Las filas de adjuntos se van en cascada', $quedan === 0, "quedan=$quedan");

// ═════════════════════════════════════════════════════════════
section('7-11 · OPERACIONES QUE NO DEBEN BORRAR NADA');

$T6 = nueva_tarea($conn, $BOARD, $COL, 'QA conservar');
[$a6, $p6, $e6] = subir($conn, $UP, $SESS, $AJAX, $csrf, $T6, $FX['jpg']);
$abs6 = abs_of($p6);

// 7. mover la tarea de columna
$st = $conn->prepare("INSERT INTO `columns` (board_id,nombre,orden,is_done) VALUES (?, 'QA Col 2', 2, 0)");
$st->bind_param('i', $BOARD);
$st->execute();
$COL2 = (int) $conn->insert_id;
$st->close();
$conn->query("UPDATE tasks SET column_id = $COL2 WHERE id = $T6");
chk('7. Mover la tarea no borra el archivo', existe($abs6));

// 8. editar la tarea
$conn->query("UPDATE tasks SET titulo = 'QA conservar editada' WHERE id = $T6");
chk('8. Editar la tarea no borra el archivo', existe($abs6));

// 9. archivar el tablero
$colArch = $conn->query("SHOW COLUMNS FROM boards LIKE 'archived_at'")->num_rows > 0;
if ($colArch) {
    $conn->query("UPDATE boards SET archived_at = NOW() WHERE id = $BOARD");
    chk('9. Archivar el tablero no borra archivos', existe($abs6));
    $conn->query("UPDATE boards SET archived_at = NULL WHERE id = $BOARD");
} else {
    chk('9. Archivar el tablero no borra archivos', true, 'sin columna archived_at: no aplica');
}

// 10. soft delete (papelera)
$conn->query("UPDATE boards SET deleted_at = NOW() WHERE id = $BOARD");
chk('10. Enviar el tablero a la papelera no borra archivos', existe($abs6));

// 11. restaurar de la papelera
$conn->query("UPDATE boards SET deleted_at = NULL WHERE id = $BOARD");
chk('11. Restaurar de la papelera deja el archivo intacto', existe($abs6));

// ═════════════════════════════════════════════════════════════
section('12-14 · PURGA DEFINITIVA DE TABLERO');

// Tablero aparte con varias tareas y varios archivos
$st = $conn->prepare("INSERT INTO boards (nombre,color_hex,owner_user_id,team_id) VALUES (?, '#e76f51', ?, NULL)");
$bn2 = QA_TAG . ' PURGA';
$st->bind_param('si', $bn2, $U_OWN);
$st->execute();
$BOARD2 = (int) $conn->insert_id;
$st->close();

$st = $conn->prepare("INSERT INTO board_members (board_id,user_id,rol) VALUES (?,?, 'propietario')");
$st->bind_param('ii', $BOARD2, $U_OWN);
$st->execute();
$st->close();

$st = $conn->prepare("INSERT INTO `columns` (board_id,nombre,orden,is_done) VALUES (?, 'QA Col', 1, 0)");
$st->bind_param('i', $BOARD2);
$st->execute();
$COL_B2 = (int) $conn->insert_id;
$st->close();

$rutasPurga = [];
foreach (['jpg', 'png', 'mp3'] as $i => $k) {
    $t = nueva_tarea($conn, $BOARD2, $COL_B2, 'QA purga ' . $i);
    [$aid, $rel, $err] = subir($conn, $UP, $SESS, $AJAX, $csrf, $t, $FX[$k]);
    if ($rel !== '') {
        $rutasPurga[] = $rel;
    }
}
// más un enlace, que no debe estorbar
$tl = nueva_tarea($conn, $BOARD2, $COL_B2, 'QA purga enlace');
http_request($LINK, ['sessionId' => $SESS, 'headers' => $AJAX,
    'post' => ['csrf' => $csrf, 'task_id' => $tl, 'url' => 'https://vimeo.com/123456789']]);

$todosAntes = count($rutasPurga) === 3
    && count(array_filter($rutasPurga, fn($r) => existe_rel(($r)))) === 3;
chk('12. Tablero de purga preparado con 3 archivos + 1 enlace', $todosAntes,
    count($rutasPurga) . ' archivos en disco');

// 13. purgar sin estar en papelera: no debe borrar nada
[$s, $h, $b] = http_request($PURGE, ['sessionId' => $SESS,
    'post' => ['csrf' => $csrf, 'board_id' => $BOARD2]]);
$sigueVivo = (int) $conn->query("SELECT COUNT(*) FROM boards WHERE id = $BOARD2")->fetch_row()[0] === 1;
$archivosIntactos = count(array_filter($rutasPurga, fn($r) => existe_rel(($r)))) === 3;
chk('13. Purgar un tablero que no está en papelera no borra nada',
    $sigueVivo && $archivosIntactos, "tablero vivo=" . var_export($sigueVivo, true));

// 14. purga real
$conn->query("UPDATE boards SET deleted_at = NOW() WHERE id = $BOARD2");
[$s, $h, $b] = http_request($PURGE, ['sessionId' => $SESS,
    'post' => ['csrf' => $csrf, 'board_id' => $BOARD2]]);
$boardFuera = (int) $conn->query("SELECT COUNT(*) FROM boards WHERE id = $BOARD2")->fetch_row()[0] === 0;
$archivosFuera = count(array_filter($rutasPurga, fn($r) => existe_rel(($r)))) === 0;
chk('14. Purgar el tablero borra los archivos de todas sus tareas',
    $boardFuera && $archivosFuera,
    'tablero borrado=' . var_export($boardFuera, true)
    . ' archivos restantes=' . count(array_filter($rutasPurga, fn($r) => existe_rel(($r)))));

// ═════════════════════════════════════════════════════════════
section('15-20 · ROBUSTEZ DE attach_delete_files()');

// 15. archivo ya inexistente
$relFantasma = fake_rel('jpg');
$r = attach_delete_files([$relFantasma]);
chk('15. Archivo ya inexistente no es un error',
    $r['total'] === 1 && $r['missing'] === 1 && $r['failed'] === 0, json_encode($r));

// 16. stored_path con formato inválido
$r = attach_delete_files(['no-es-una-ruta.jpg']);
chk('16. stored_path inválido se descarta sin tocar el disco',
    $r['invalid'] === 1 && $r['deleted'] === 0, json_encode($r));

// 17. path traversal
$victima = dirname(__DIR__) . '/config/bootstrap.php';
$existiaVictima = existe($victima);
$r = attach_delete_files([
    '../../config/bootstrap.php',
    '2026/07/../../../../config/bootstrap.php',
    '....//....//config/bootstrap.php',
]);
chk('17. Path traversal rechazado y el objetivo intacto',
    $r['invalid'] === 3 && $r['deleted'] === 0 && $existiaVictima && existe($victima),
    json_encode($r));

// 18. rutas absolutas y raras
$r = attach_delete_files(['C:/Windows/System32/drivers/etc/hosts', '/etc/passwd', '', '2026/13/' . str_repeat('a', 32) . '.jpg']);
chk('18. Rutas absolutas y meses imposibles rechazados',
    $r['deleted'] === 0 && $r['invalid'] === 3, json_encode($r) . ' (la cadena vacía se ignora)');

// 19. borrado real de un archivo legítimo
$relReal = fake_rel('jpg');
make_loose_file($relReal);
$r = attach_delete_files([$relReal]);
chk('19. Borra de verdad una ruta válida existente',
    $r['deleted'] === 1 && !existe_rel(($relReal)), json_encode($r));

// 20. idempotencia
$r2 = attach_delete_files([$relReal]);
chk('20. Repetir el borrado es inocuo', $r2['missing'] === 1 && $r2['failed'] === 0, json_encode($r2));

// ═════════════════════════════════════════════════════════════
section('21-31 · CRON DE HUÉRFANOS');

// Archivo legítimo vivo (con fila): el cron NO debe tocarlo.
// Vive en el almacén QA y tiene su fila en base, así que el cron lo encuentra
// en el inventario y debe respetarlo. Es la misma garantía que antes, ahora
// comprobada sin tocar el almacén real.
$T7 = nueva_tarea($conn, $BOARD, $COL, 'QA cron legitimo');
[$a7, $p7, $e7] = subir($conn, $UP, $SESS, $AJAX, $csrf, $T7, $FX['jpg']);
$absLegitimo = abs_of($p7);

$relRef = fake_rel('jpg');
make_qa_file($relRef, time() - 72 * 3600);
$st = $conn->prepare("INSERT INTO task_attachments (task_id,uploaded_by,kind,original_name,stored_path,mime,size_bytes) VALUES (?,?, 'image','qa_referenciado.jpg',?, 'image/jpeg', 99)");
$st->bind_param('iis', $T7, $U_OWN, $relRef);
$st->execute();
$st->close();

$archivosRealAntes = contar_almacen_real();

// Huérfanos fabricados, todos dentro del almacén QA
$viejo   = fake_rel('jpg');   // > 24 h
$reciente = fake_rel('png');  // < 24 h
$raro    = date('Y') . '/' . date('m') . '/no-es-el-patron.jpg';
make_qa_file($viejo, time() - 72 * 3600);
make_qa_file($reciente, time() - 3600);
make_qa_file($raro, time() - 72 * 3600);

// 21. dry-run no borra nada
[$code, $out] = run_cron($CRON_ORPHAN, ['--dry-run', '--verbose', '--storage-root=' . $QA_STORAGE]);
chk('21. Simulacro no borra ningún archivo',
    $code === 0 && existe_qa($viejo) && existe_qa($reciente),
    'exit=' . $code . ' eliminados=' . cron_num($out, 'eliminados'));

// 22. el simulacro sí identifica al huérfano viejo
chk('22. El simulacro detecta el huérfano caducado',
    cron_num($out, 'huérfanos') >= 1 && str_contains($out, 'SE BORRARÍA'),
    'huérfanos=' . cron_num($out, 'huérfanos'));

// 23. margen de gracia
chk('23. El huérfano reciente queda en gracia',
    cron_num($out, 'en gracia') >= 1 && str_contains($out, basename($reciente)),
    'en gracia=' . cron_num($out, 'en gracia'));

// 24. nombre fuera de patrón: omitido, nunca borrado
chk('24. Nombre fuera de patrón se omite',
    str_contains($out, 'nombre fuera de patrón') && existe_qa($raro),
    'omitidos=' . cron_num($out, 'omitidos'));

// 25. ejecución real
[$code, $out] = run_cron($CRON_ORPHAN, ['--verbose', '--storage-root=' . $QA_STORAGE]);
chk('25. Ejecución real borra el huérfano caducado',
    $code === 0 && !existe_qa($viejo), 'exit=' . $code . ' eliminados=' . cron_num($out, 'eliminados'));

// 26. respeta la gracia también en real
chk('26. La ejecución real respeta el margen de gracia', existe_qa($reciente));

// 27. no toca el archivo legítimo
chk('27. El archivo con fila en base sigue intacto', existe_qa($relRef));

// El almacén REAL ni siquiera entró en el recorrido: esa es la propiedad que
// impedirá que una ejecución sobre datos de producción borre adjuntos ajenos.
chk('27b. El almacén real no fue tocado',
    contar_almacen_real() === $archivosRealAntes && existe($absLegitimo),
    'archivos antes=' . $archivosRealAntes . ' ahora=' . contar_almacen_real());

// 28. no toca lo que está fuera de patrón
chk('28. El archivo fuera de patrón sigue intacto', existe_qa($raro));

// 29. idempotencia
[$code2, $out2] = run_cron($CRON_ORPHAN, ['--storage-root=' . $QA_STORAGE]);
chk('29. Segunda ejecución no encuentra nada nuevo',
    $code2 === 0 && cron_num($out2, 'eliminados') === 0,
    'eliminados=' . cron_num($out2, 'eliminados'));

// 30. gracia configurable: con 0 horas el reciente ya es purgable
[$code3, $out3] = run_cron($CRON_ORPHAN, ['--grace-hours=0', '--storage-root=' . $QA_STORAGE]);
chk('30. --grace-hours=0 borra también el reciente',
    $code3 === 0 && !existe_qa($reciente),
    'eliminados=' . cron_num($out3, 'eliminados'));

// 31. .gitkeep y .htaccess intactos
chk('31. .gitkeep y .htaccess siguen en su sitio',
    existe($QA_STORAGE . '/.gitkeep') && existe($QA_STORAGE . '/.htaccess')
    && existe(attach_storage_root() . '/.gitkeep') && existe(attach_storage_root() . '/.htaccess'),
    'comprobado en el almacén QA y en el real');

// ═════════════════════════════════════════════════════════════
section('32-35 · ENLACES SIMBÓLICOS Y SALVAGUARDAS');

// 32. Un enlace dentro del almacén no debe seguirse ni borrarse, y sobre todo
//     su destino tiene que sobrevivir intacto.
//
//     En Windows symlink() exige privilegios que aquí no hay, así que se usa
//     una unión de directorio (mklink /J), que no los pide. Ojo al detalle:
//     PHP NO reconoce las uniones con is_link() — devuelve false —, pero
//     tampoco las ve como directorio, así que el cron las descarta igual por
//     la comprobación de carpeta. Lo que se mide aquí es la propiedad que
//     importa: el destino sigue vivo.
$dirDestino = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'fyc_link_target';
@mkdir($dirDestino, 0775, true);
$archivoDestino = $dirDestino . DIRECTORY_SEPARATOR . 'no_me_toques.txt';
file_put_contents($archivoDestino, 'contenido ajeno al almacen');

$dirEnlace = $QA_STORAGE . DIRECTORY_SEPARATOR . '2999';
if (is_dir($dirEnlace)) {
    @rmdir($dirEnlace);
}
clearstatcache(true);

$salida = [];
$codeMk = 0;
exec('mklink /J "' . $dirEnlace . '" "' . $dirDestino . '" 2>&1', $salida, $codeMk);

if ($codeMk === 0) {
    [$codeL, $outL] = run_cron($CRON_ORPHAN, ['--verbose', '--grace-hours=0', '--storage-root=' . $QA_STORAGE]);
    chk('32. Un enlace en el almacén se omite y su destino sobrevive',
        $codeL === 0 && existe($archivoDestino) && cron_num($outL, 'eliminados') === 0,
        'omitidos=' . cron_num($outL, 'omitidos') . ' eliminados=' . cron_num($outL, 'eliminados'));
    @rmdir($dirEnlace);
} else {
    ko('32. Un enlace en el almacén se omite y su destino sobrevive',
        'NO SE PUDO CREAR EL ENLACE: ' . trim(implode(' ', $salida)));
}
clearstatcache(true);
chk('32b. El destino del enlace conserva su contenido',
    existe($archivoDestino) && file_get_contents($archivoDestino) === 'contenido ajeno al almacen');
@unlink($archivoDestino);
@rmdir($dirDestino);

// 32c. La guarda de enlaces simbólicos existe para Linux, donde is_link() sí
//      funciona. En producción (Plesk/Linux) es la que actúa.
// Se cuentan las GUARDAS reales, no las apariciones del texto: los
// comentarios que explican la decisión también nombran is_link().
$guardas = preg_match_all('/^\s*if \(is_link\(/m', file_get_contents($CRON_ORPHAN));
chk('32c. El cron comprueba is_link() en los tres niveles',
    $guardas === 3, "guardas encontradas={$guardas} (año, mes y archivo)");

// 33. la salvaguarda de inventario existe en el código
$cronSrc = file_get_contents($CRON_ORPHAN);
$posAbort = strpos($cronSrc, 'exit(3)');
$posUnlink = strpos($cronSrc, '@unlink($ruta)');
chk('33. El cron aborta antes de borrar si no puede leer la base',
    str_contains($cronSrc, 'ABORTA: no se pudo leer task_attachments')
    && $posAbort !== false && $posUnlink !== false && $posAbort < $posUnlink,
    'la salida 3 está antes del primer unlink');

// 34. argumento inválido
[$codeA, $outA] = run_cron($CRON_ORPHAN, ['--parametro-inexistente']);
chk('34. Argumento inválido termina con código 2', $codeA === 2, 'exit=' . $codeA);

// 35. el cron no es accesible por web
chk('35. El cron rechaza ejecución fuera de CLI',
    str_contains($cronSrc, "PHP_SAPI !== 'cli'"));

// ═════════════════════════════════════════════════════════════
section('36-40 · CRON DE PAPELERA Y CONTRATO DE CÓDIGO');

// 36. purge_trash recoge las rutas antes de borrar
$trashSrc = file_get_contents($CRON_TRASH);
chk('36. cron/purge_trash recoge rutas antes del DELETE',
    strpos($trashSrc, 'attach_stored_paths_of_board') < strpos($trashSrc, 'DELETE FROM boards WHERE id IN')
    && str_contains($trashSrc, 'attach_delete_files'));

// 37. purga por cron con tablero caducado
$st = $conn->prepare("INSERT INTO boards (nombre,color_hex,owner_user_id,team_id,deleted_at) VALUES (?, '#264653', ?, NULL, NOW() - INTERVAL 40 DAY)");
$bn3 = QA_TAG . ' CRON';
$st->bind_param('si', $bn3, $U_OWN);
$st->execute();
$BOARD3 = (int) $conn->insert_id;
$st->close();

$st = $conn->prepare("INSERT INTO `columns` (board_id,nombre,orden,is_done) VALUES (?, 'QA Col', 1, 0)");
$st->bind_param('i', $BOARD3);
$st->execute();
$COL_B3 = (int) $conn->insert_id;
$st->close();

$T8 = nueva_tarea($conn, $BOARD3, $COL_B3, 'QA cron papelera');
// El archivo se crea a mano con su fila: el endpoint exige membresía y aquí
// lo que se prueba es el cron, no los permisos.
$relCron = fake_rel('jpg');
make_loose_file($relCron);
$st = $conn->prepare("INSERT INTO task_attachments (task_id,uploaded_by,kind,original_name,stored_path,mime,size_bytes) VALUES (?,?, 'image','cron.jpg',?, 'image/jpeg', 123)");
$st->bind_param('iis', $T8, $U_OWN, $relCron);
$st->execute();
$st->close();

// Testigo: otro tablero QA, también en papelera y también caducado. El cron
// va acotado al primero, así que este DEBE sobrevivir. Sin esta comprobación
// no habría forma de notar que el alcance dejó de aplicarse.
$st = $conn->prepare("INSERT INTO boards (nombre,color_hex,owner_user_id,team_id,deleted_at) VALUES (?, '#777777', ?, NULL, NOW() - INTERVAL 90 DAY)");
$bnT = QA_TAG . ' TESTIGO';
$st->bind_param('si', $bnT, $U_OWN);
$st->execute();
$BOARD_TESTIGO = (int) $conn->insert_id;
$st->close();

$existiaCron = existe_rel(($relCron));
// Acotado al tablero de la prueba. Sin --board-id este cron purga TODOS los
// tableros en papelera de más de 30 días: ejecutándolo a ciegas sobre una
// copia de producción borró un tablero real. La regla de antigüedad sigue
// aplicándose; lo único que cambia es sobre qué opera.
[$codeT, $outT] = run_cron($CRON_TRASH, ['--board-id=' . $BOARD3]);
$b3Fuera = (int) $conn->query("SELECT COUNT(*) FROM boards WHERE id = $BOARD3")->fetch_row()[0] === 0;
chk('37. El cron de papelera purga y borra sus archivos',
    $existiaCron && $b3Fuera && !existe_rel(($relCron)),
    'exit=' . $codeT . ' | ' . trim($outT));

chk('37b. El alcance protege a otro tablero caducado',
    (int) $conn->query("SELECT COUNT(*) FROM boards WHERE id = $BOARD_TESTIGO")->fetch_row()[0] === 1,
    'lleva 90 días en papelera y sobrevive porque no se pidió');

// 38. delete.php recoge rutas antes del DELETE
$delSrc = file_get_contents(dirname(__DIR__) . '/public/tasks/delete.php');
chk('38. tasks/delete.php recoge rutas antes del DELETE',
    strpos($delSrc, 'attach_stored_paths_of_task') < strpos($delSrc, 'DELETE FROM tasks')
    && str_contains($delSrc, 'begin_transaction')
    && strpos($delSrc, '$conn->commit()') < strpos($delSrc, 'attach_delete_files'));

// 39. trash_purge.php solo borra archivos si la transacción se confirmó
$purgeSrc = file_get_contents(dirname(__DIR__) . '/public/boards/trash_purge.php');
chk('39. trash_purge.php solo borra archivos tras confirmar',
    strpos($purgeSrc, 'attach_stored_paths_of_board') < strpos($purgeSrc, 'DELETE FROM boards')
    && str_contains($purgeSrc, 'if ($purgado && $attachPaths !== [])'));

// 40. ninguna ruta se filtra al usuario
chk('40. Las respuestas no exponen rutas internas',
    !str_contains($delSrc, "'stored_path'")
    && str_contains($delSrc, "// Solo contadores: nunca rutas ni nombres de archivo."));

// ═════════════════════════════════════════════════════════════
section('41-43 · NADA LEGÍTIMO SE PERDIÓ');

// 41. el archivo de la tarea T6 (que solo se movió y editó) sigue vivo
chk('41. El adjunto de la tarea conservada sigue en disco', existe($abs6));
chk('42. Su fila sigue en la base',
    (int) $conn->query("SELECT COUNT(*) FROM task_attachments WHERE id = $a6")->fetch_row()[0] === 1);

// 43. inventario: ningún archivo ajeno a la prueba desapareció
$storageAhora = scan_storage();
$perdidos = array_diff($storageAntes, $storageAhora);
chk('43. Ningún archivo preexistente fue eliminado',
    count($perdidos) === 0,
    count($perdidos) === 0 ? 'los ' . count($storageAntes) . ' de partida siguen ahí'
        : implode(', ', $perdidos));

// ═════════════════════════════════════════════════════════════
section('LIMPIEZA FINAL');

@unlink(abs_of($raro));
@unlink(SESSION_DIR . DIRECTORY_SEPARATOR . 'sess_' . $SESS);
drop_fixtures($FIXDIR);

$post = cleanup($conn);
printf("  eliminados: %d tableros, %d usuarios, %d archivos\n",
    $post['boards'], $post['users'], $post['files']);

// Almacén QA temporal: se retira entero, está fuera del proyecto.
if (isset($QA_STORAGE) && is_dir($QA_STORAGE)) {
    foreach (@scandir($QA_STORAGE) ?: [] as $y) {
        $py = $QA_STORAGE . DIRECTORY_SEPARATOR . $y;
        if ($y === '.' || $y === '..') {
            continue;
        }
        if (is_dir($py)) {
            foreach (@scandir($py) ?: [] as $m) {
                $pm = $py . DIRECTORY_SEPARATOR . $m;
                if ($m === '.' || $m === '..') {
                    continue;
                }
                if (is_dir($pm)) {
                    foreach (@scandir($pm) ?: [] as $f) {
                        if ($f !== '.' && $f !== '..') {
                            @unlink($pm . DIRECTORY_SEPARATOR . $f);
                        }
                    }
                    @rmdir($pm);
                } else {
                    @unlink($pm);
                }
            }
            @rmdir($py);
        } else {
            @unlink($py);
        }
    }
    @rmdir($QA_STORAGE);
}

// Carpetas AAAA/MM que hayan quedado vacías por culpa de la prueba.
// Solo se borran si están vacías: nunca arrastran archivos con ellas.
$rootDir = attach_storage_root();
foreach (@scandir($rootDir) ?: [] as $y) {
    if ($y === '.' || $y === '..' || !preg_match('/^\d{4}$/', $y)) {
        continue;
    }
    foreach (@scandir("$rootDir/$y") ?: [] as $m) {
        if ($m === '.' || $m === '..' || !is_dir("$rootDir/$y/$m")) {
            continue;
        }
        if (count(array_diff(@scandir("$rootDir/$y/$m") ?: [], ['.', '..'])) === 0) {
            @rmdir("$rootDir/$y/$m");
        }
    }
    if (count(array_diff(@scandir("$rootDir/$y") ?: [], ['.', '..'])) === 0) {
        @rmdir("$rootDir/$y");
    }
}
clearstatcache(true);

$storageFinal = scan_storage();
$filasFinal   = (int) $conn->query("SELECT COUNT(*) FROM task_attachments")->fetch_row()[0];
$qaBoards     = (int) $conn->query("SELECT COUNT(*) FROM boards WHERE nombre LIKE '" . QA_TAG . "%'")->fetch_row()[0];

printf("  almacén: %d archivos (al empezar: %d)\n", count($storageFinal), count($storageAntes));
printf("  task_attachments: %d filas (al empezar: %d)\n", $filasFinal, $filasAntes);
printf("  tableros QA restantes: %d\n", $qaBoards);

chk('44. El almacén queda como estaba', $storageFinal === $storageAntes,
    count($storageFinal) . ' vs ' . count($storageAntes));
chk('45. task_attachments queda como estaba', $filasFinal === $filasAntes,
    "$filasFinal vs $filasAntes");
chk('46. No quedan tableros QA', $qaBoards === 0);

// ═════════════════════════════════════════════════════════════
echo "\n" . str_repeat('═', 78) . "\n";
printf(" RESULTADO: %d correctas, %d fallidas\n", $PASS, $FAIL);
echo str_repeat('═', 78) . "\n\n";

exit($FAIL === 0 ? 0 : 1);
