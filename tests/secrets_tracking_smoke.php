<?php
/**
 * tests/secrets_tracking_smoke.php
 *
 * Qué archivos de configuración viajan en Git y cuáles no.
 *
 * Ejecutar SOLO en local:
 *   php tests/secrets_tracking_smoke.php
 *
 * Desde que el despliegue es «Plesk Git → Pull now», al servidor llega
 * exactamente lo que Git rastrea. Ya no hay un paquete ZIP que aplique
 * exclusiones por el camino: el índice de Git ES la lista de lo que se
 * publica. Por eso el contrato que vigila esta suite dejó de ser «qué
 * contiene el paquete» y pasó a ser «qué rastrea Git».
 *
 * El caso que la motiva: config/mail.php figuraba en .gitignore Y estaba
 * rastreado a la vez. Una regla de .gitignore no deja de seguir un archivo
 * que ya se seguía, así que la regla no hacía nada y el archivo viajaba
 * igual. Parecía protegido y no lo estaba.
 *
 * Las comprobaciones miran el ÍNDICE, no HEAD. El índice es lo que se va a
 * confirmar; HEAD es lo que ya se confirmó. Mirando HEAD, esta suite daría
 * rojo entre el `git rm --cached` y su commit —justo cuando más falta hace
 * que diga la verdad— y verde con el índice sucio.
 *
 * No toca la base de datos, no escribe nada y no envía correo.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Este script solo puede ejecutarse desde CLI.\n");
}

$ROOT = dirname(__DIR__);

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

/** Ejecuta git en la raíz del proyecto y devuelve la salida en bruto. */
function git(string $root, string $args): string
{
    return (string) shell_exec('git -C ' . escapeshellarg($root) . ' ' . $args . ' 2>&1');
}

/** Líneas no vacías de una salida de git. */
function git_lineas(string $root, string $args): array
{
    return array_values(array_filter(
        array_map('trim', explode("\n", git($root, $args))),
        fn($l) => $l !== ''
    ));
}

echo "\n";
echo "══════════════════════════════════════════════════════════════════════════════\n";
echo " CONFIGURACIÓN SENSIBLE FUERA DE GIT\n";
echo "══════════════════════════════════════════════════════════════════════════════\n";
echo " PHP   : " . PHP_VERSION . "\n";
echo " Raíz  : " . $ROOT . "\n";

// Lista completa de lo rastreado. Es la fuente de todas las comprobaciones
// de esta suite: lo que esté aquí es lo que llega al servidor.
$indice = git_lineas($ROOT, 'ls-files');

/** ¿Git rastrea esta ruta ahora mismo? */
function rastreado(array $indice, string $ruta): bool
{
    return in_array($ruta, $indice, true);
}

// ═════════════════════════════════════════════════════════════
section('1-6 · QUÉ RASTREA GIT');

chk('1. Git NO rastrea config/mail.php',
    !rastreado($indice, 'config/mail.php'),
    'cada entorno tiene el suyo; el del repositorio apuntaba a Mailpit');

chk('2. Git NO rastrea config/db.php',
    !rastreado($indice, 'config/db.php'),
    'credenciales de base de datos');

chk('3. Git SÍ rastrea config/mail.example.php',
    rastreado($indice, 'config/mail.example.php'),
    'la plantilla debe viajar: sin ella un clon nuevo no sabe qué crear');

chk('4. Git SÍ rastrea config/db.example.php',
    rastreado($indice, 'config/db.example.php'));

chk('5. Git SÍ rastrea config/bootstrap.php',
    rastreado($indice, 'config/bootstrap.php'),
    'zona horaria y arranque: no contiene secretos');

// Un listado explícito evita que mañana entre otro archivo de credenciales
// sin que nadie se entere.
$permitidos = ['config/bootstrap.php', 'config/db.example.php', 'config/mail.example.php'];
$enConfig   = array_values(array_filter($indice, fn($f) => str_starts_with($f, 'config/')));
sort($enConfig);
chk('6. En config/ no se rastrea nada más que lo previsto',
    $enConfig === $permitidos,
    implode(', ', $enConfig));

// ═════════════════════════════════════════════════════════════
section('7-11 · LAS REGLAS DE .gitignore SURTEN EFECTO');

$gitignore = (string) @file_get_contents($ROOT . '/.gitignore');
$reglas    = array_map(fn($l) => trim($l), explode("\n", $gitignore));

chk('7. .gitignore contiene /config/mail.php',
    in_array('/config/mail.php', $reglas, true));

chk('8. .gitignore contiene /config/db.php',
    in_array('/config/db.php', $reglas, true));

// La comprobación que de verdad importa. `git check-ignore` calla sobre un
// archivo rastreado aunque exista la regla: es la forma de distinguir «la
// regla se aplica» de «la regla está escrita pero no hace nada».
$aplicaMail = trim(git($ROOT, 'check-ignore -v config/mail.php'));
chk('9. La regla se APLICA de verdad a config/mail.php',
    str_contains($aplicaMail, '/config/mail.php'),
    $aplicaMail !== '' ? $aplicaMail : 'check-ignore calla: seguiría rastreado');

$aplicaDb = trim(git($ROOT, 'check-ignore -v config/db.php'));
chk('10. La regla se APLICA de verdad a config/db.php',
    str_contains($aplicaDb, '/config/db.php'),
    $aplicaDb !== '' ? $aplicaDb : 'check-ignore calla: seguiría rastreado');

// Ninguno de los dos debe aparecer como «sin seguimiento»: si apareciera,
// significaría que la regla no lo cubre y un `git add .` lo devolvería al
// repositorio sin que nadie lo notase.
$sinSeguimiento = git_lineas($ROOT, 'ls-files --others --exclude-standard');
chk('11. Ninguno figura como archivo sin seguimiento',
    !in_array('config/mail.php', $sinSeguimiento, true)
    && !in_array('config/db.php', $sinSeguimiento, true),
    'un git add . no podría recuperarlos');

// ═════════════════════════════════════════════════════════════
section('12-15 · LOS ARCHIVOS SIGUEN EN DISCO');

// Sacar algo de Git no debe borrarlo: el entorno local tiene que seguir
// funcionando igual que antes.
chk('12. config/mail.php sigue existiendo físicamente',
    is_file($ROOT . '/config/mail.php'),
    'dejar de rastrearlo no es borrarlo');

chk('13. config/db.php sigue existiendo físicamente',
    is_file($ROOT . '/config/db.php'));

// cron/run_alerts.php lo carga con require_once, sin red de seguridad. Un
// clon recién hecho no trae config/mail.php, así que hay que crearlo antes
// de ejecutar nada. Esto solo comprueba que la dependencia es la que
// creemos; el aviso a quien clona vive en la plantilla y en la
// documentación, que se comprueban más abajo.
$cron = (string) @file_get_contents($ROOT . '/cron/run_alerts.php');
chk('14. El cron carga config/mail.php de forma obligatoria',
    str_contains($cron, "/../config/mail.php'"),
    'un clon sin ese archivo aborta: por eso la plantilla es obligatoria');

$ejemplo = (string) @file_get_contents($ROOT . '/config/mail.example.php');
$usadas  = ['MAIL_ENABLED', 'MAIL_SMTP_HOST', 'MAIL_SMTP_PORT',
            'MAIL_FROM_ADDR', 'MAIL_FROM_NAME', 'MAIL_APP_URL'];
$faltan  = array_values(array_filter($usadas, fn($c) => !str_contains($ejemplo, $c)));
chk('15. La plantilla define todas las constantes que usa el código',
    $faltan === [],
    $faltan === [] ? count($usadas) . '/' . count($usadas) : 'faltan: ' . implode(', ', $faltan));

// ═════════════════════════════════════════════════════════════
section('16-19 · NINGÚN SECRETO EN LO RASTREADO');

// Barrido del CONTENIDO de todo lo versionado. Solo cuenta una credencial
// ASIGNADA con un valor literal: un host o un puerto no son secretos, y una
// variable mencionada no es un valor.
//
// Los dos patrones van anclados a una sola línea a propósito. Una versión
// anterior usaba [^'"] para el valor, que también acepta saltos de línea:
// ante una contraseña vacía ('') el patrón se comía el cierre y capturaba el
// resto del archivo como si fuera el valor. Marcaba seis archivos limpios.
//
// DB_USER queda fuera del barrido a propósito: la plantilla trae 'root',
// que es el valor por defecto de Laragon y un dato documentado, no un
// secreto. El db.php de verdad no se rastrea, y eso lo vigila la número 2.
$nombres = 'MAIL_SMTP_PASS|MAIL_SMTP_USER|SMTP_PASSWORD|DB_PASS';
$pDefine = '/define\s*\(\s*[\'"](' . $nombres . ')[\'"]\s*,\s*[\'"]([^\'"\n]+)[\'"]/i';
$pVar    = '/\$(' . $nombres . ')\s*=\s*[\'"]([^\'"\n]+)[\'"]/i';

$sospechosos = [];
$revisados   = 0;
foreach ($indice as $rel) {
    if (!preg_match('/\.(php|sql|md|json|js|ya?ml|ini|sh)$/i', $rel)) {
        continue;
    }
    $abs = $ROOT . '/' . $rel;
    if (!is_file($abs) || filesize($abs) > 2_000_000) {
        continue;
    }
    $revisados++;
    foreach (explode("\n", (string) file_get_contents($abs)) as $nLinea => $linea) {
        $limpia = ltrim($linea);
        if ($limpia === '' || str_starts_with($limpia, '//')
            || str_starts_with($limpia, '*') || str_starts_with($limpia, '#')) {
            continue;
        }
        if (preg_match($pDefine, $linea, $m) === 1 || preg_match($pVar, $linea, $m) === 1) {
            // Se registra la constante y la línea, nunca el valor.
            $sospechosos[] = $rel . ':' . ($nLinea + 1) . ' → ' . $m[1];
        }
    }
}
chk('16. Ningún archivo rastreado asigna una credencial con valor',
    $sospechosos === [],
    $sospechosos === [] ? $revisados . ' archivos revisados'
        : implode(' · ', array_unique($sospechosos)));

// Una comprobación que sale limpia puede significar dos cosas muy distintas:
// que no hay secretos, o que el detector no detecta nada. Las de abajo
// separan los dos casos, y seguirán separándolos si alguien afloja el patrón.
//
// Los nombres van partidos —'MAIL_SMTP_' . 'PASS'— a propósito: escritos de
// una pieza, el barrido de arriba encontraría estas líneas en este mismo
// archivo, que sí está rastreado, y se acusaría a sí mismo.
$P = 'MAIL_SMTP_' . 'PASS';
$U = 'MAIL_SMTP_' . 'USER';
$V = 'DB_' . 'PASS';

$debeDetectar = [
    "define('$P', 'clave-de-prueba');",
    "define('$U', 'buzon@dominio.invalido');",
    "\$$V = 'otra-clave';",
];
$debeIgnorar = [
    "\$$V = '';",                                   // plantilla, vacío
    "defined('$P') || define('$P', '');",           // plantilla, vacío
    "        . 'password=' . \$$V . \"\\n\";",      // concatenación, sin literal
    "foreach (['u' => \$DB_USER, 'p' => \$$V] as \$k => \$v) {",
    "// $P = 'ejemplo' en un comentario",
];

/** Aplica el mismo criterio que el barrido de arriba a una sola línea. */
$detecta = function (string $linea) use ($pDefine, $pVar): bool {
    $limpia = ltrim($linea);
    if ($limpia === '' || str_starts_with($limpia, '//')
        || str_starts_with($limpia, '*') || str_starts_with($limpia, '#')) {
        return false;
    }
    return preg_match($pDefine, $linea) === 1 || preg_match($pVar, $linea) === 1;
};

$escapados = array_values(array_filter($debeDetectar, fn($l) => !$detecta($l)));
chk('16b. El detector SÍ encuentra un secreto de verdad',
    $escapados === [],
    $escapados === [] ? count($debeDetectar) . '/' . count($debeDetectar) . ' casos reconocidos'
        : count($escapados) . ' se le escapan: el barrido pasaría en vacío');

$falsos = array_values(array_filter($debeIgnorar, fn($l) => $detecta($l)));
chk('16c. Y no confunde plantillas ni menciones con secretos',
    $falsos === [],
    $falsos === [] ? count($debeIgnorar) . '/' . count($debeIgnorar) . ' descartados'
        : count($falsos) . ' falsos positivos');

// La plantilla es el sitio donde más fácil se cuela un secreto real, porque
// se edita copiando el archivo de verdad.
chk('17. La plantilla deja MAIL_SMTP_PASS vacío',
    (bool) preg_match("/MAIL_SMTP_PASS[^\n]*''/", $ejemplo)
    || (bool) preg_match('/MAIL_SMTP_PASS[^\n]*""/', $ejemplo),
    'se rellena en cada entorno, nunca en el repositorio');

chk('17b. La plantilla deja MAIL_SMTP_USER vacío',
    (bool) preg_match("/MAIL_SMTP_USER[^\n]*''/", $ejemplo)
    || (bool) preg_match('/MAIL_SMTP_USER[^\n]*""/', $ejemplo),
    'el remitente autenticado también identifica la cuenta');

chk('18. No se rastrea ningún .env ni config/app.local.php',
    !rastreado($indice, '.env') && !rastreado($indice, 'config/app.local.php')
    && [] === array_values(array_filter($indice, fn($f) => str_starts_with(basename($f), '.env'))));

// Respaldos y paquetes de release contienen volcados completos de la base.
$pesados = array_values(array_filter($indice,
    fn($f) => str_starts_with($f, '_backups/') || str_starts_with($f, '_releases/')
           || str_ends_with($f, '.zip')));
chk('19. No se rastrea ningún respaldo ni paquete de release',
    $pesados === [],
    $pesados === [] ? '' : implode(', ', $pesados));

// ═════════════════════════════════════════════════════════════
section('20-22 · LA SALIDA DE mail.php ES EL ÚNICO CAMBIO');

// Lo que hay que vigilar no es cuántos archivos cambian —añadir código es
// trabajo normal— sino que no desaparezca del repositorio nada más que
// config/mail.php. Un `git rm --cached` con la ruta equivocada, o de más,
// dejaría de publicar un archivo que el servidor necesita, y eso no se nota
// hasta el despliegue.
$bajas = [];
foreach (git_lineas($ROOT, 'diff --cached --name-status HEAD') as $l) {
    if (str_starts_with($l, 'D')) {
        $bajas[] = trim(substr($l, 1));
    }
}
$bajasEsperadas = array_values(array_diff($bajas, ['config/mail.php']));
chk('20. No se deja de rastrear ningún archivo salvo config/mail.php',
    $bajasEsperadas === [],
    $bajas === [] ? 'ninguna baja pendiente: ya confirmado'
        : 'bajas: ' . implode(', ', $bajas));

// Nada de esto debe haber tocado el CSS compilado ni el resto del árbol.
$hashArbol = trim(git($ROOT, 'hash-object public/assets/app.css'));
$hashHead  = trim(git($ROOT, 'rev-parse HEAD:public/assets/app.css'));
chk('21. app.css no fue modificado',
    $hashArbol === $hashHead && $hashArbol !== '',
    substr($hashArbol, 0, 16) . '…');

// La documentación tiene que decir que cada entorno crea el suyo, porque a
// partir de ahora un clon nuevo no lo trae.
$docs = '';
foreach (glob($ROOT . '/docs/*.md') ?: [] as $d) {
    $docs .= (string) file_get_contents($d);
}
$docs .= (string) @file_get_contents($ROOT . '/README.md');
chk('22. La documentación explica que cada entorno crea su mail.php',
    str_contains($docs, 'mail.example.php'),
    'quien clone el repositorio necesita saberlo');

// ═════════════════════════════════════════════════════════════
echo "\n" . str_repeat('═', 78) . "\n";
printf(" RESULTADO: %d correctas, %d fallidas\n", $PASS, $FAIL);
echo str_repeat('═', 78) . "\n\n";

exit($FAIL === 0 ? 0 : 1);
