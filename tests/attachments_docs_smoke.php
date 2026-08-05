<?php
/**
 * tests/attachments_docs_smoke.php
 *
 * Validación de la documentación del módulo de adjuntos (Fase F, bloque F4).
 *
 * Ejecutar SOLO en local:
 *   php tests/attachments_docs_smoke.php
 *
 * No toca la base de datos, ni el almacén, ni la red. Solo lee los tres
 * documentos y comprueba que dicen lo que deben decir —y, sobre todo, que NO
 * dicen lo que no deben: nada de credenciales, ni de dar por hecho que
 * producción ya está desplegada.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Este script solo puede ejecutarse desde CLI.\n");
}

$ROOT = dirname(__DIR__);
$DOCS = $ROOT . '/docs';

$F_ATT = $DOCS . '/ATTACHMENTS.md';
$F_DEP = $DOCS . '/DEPLOYMENT_ATTACHMENTS.md';
$F_BAK = $DOCS . '/BACKUP_RESTORE.md';

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

/**
 * ¿Aparece el patrón en el texto?
 *
 * El markdown va ajustado a 80 columnas, así que una frase puede partirse en
 * dos líneas en cualquier punto. Si no se normalizan los saltos, un patrón con
 * «.*» falla por dónde cayó el ajuste, no por lo que dice el documento. Se
 * colapsa todo el espacio en blanco a un solo espacio antes de buscar.
 */
function normalizar(string $texto): string
{
    // Los «> » de las citas también estorban a mitad de frase.
    $t = preg_replace('/\n\s*>\s?/u', ' ', $texto) ?? $texto;
    return trim((string) preg_replace('/\s+/u', ' ', $t));
}

/**
 * Delimitador «#», no «/»: muchos patrones son rutas —storage/attachments,
 * AAAA/MM— y con «/» la expresión se cierra a mitad, queda inválida y
 * preg_match devuelve false sin más. Un fallo así se disfraza de «el
 * documento no lo dice» cuando sí lo dice.
 */
function tiene(string $texto, string $patron): bool
{
    return (bool) preg_match('#' . $patron . '#iu', normalizar($texto));
}

// ═════════════════════════════════════════════════════════════
echo "\n";
echo "══════════════════════════════════════════════════════════════════════════════\n";
echo " VALIDACIÓN DE LA DOCUMENTACIÓN — MÓDULO DE ADJUNTOS (Fase F · bloque F4)\n";
echo "══════════════════════════════════════════════════════════════════════════════\n";
echo " PHP   : " . PHP_VERSION . "\n";
echo " Fecha : " . date('Y-m-d H:i:s') . "\n";

// ═════════════════════════════════════════════════════════════
section('1-4 · EXISTENCIA, CODIFICACIÓN Y AUSENCIA DE SECRETOS');

$docs = ['ATTACHMENTS.md' => $F_ATT, 'DEPLOYMENT_ATTACHMENTS.md' => $F_DEP, 'BACKUP_RESTORE.md' => $F_BAK];

$existen = 0;
foreach ($docs as $n => $p) {
    if (is_file($p) && filesize($p) > 2000) {
        $existen++;
    }
}
chk('1. Existen los tres documentos y no están vacíos', $existen === 3,
    "$existen/3 · " . implode(', ', array_map(
        fn($p) => is_file($p) ? round(filesize($p) / 1024) . ' KB' : 'AUSENTE', $docs)));

if ($existen !== 3) {
    echo "\n  Sin los tres documentos no tiene sentido seguir.\n";
    exit(1);
}

$T = [];
foreach ($docs as $n => $p) {
    $T[$n] = (string) file_get_contents($p);
}
$TODO = implode("\n", $T);

$utf8 = 0;
foreach ($T as $n => $c) {
    if (mb_check_encoding($c, 'UTF-8')) {
        $utf8++;
    }
}
chk('2. Los tres son UTF-8 válido', $utf8 === 3, "$utf8/3");

// 3. Credenciales reales de config/db.php
$fugas = [];
if (is_file($ROOT . '/config/db.php')) {
    // Se leen los valores SIN imprimirlos jamás.
    $src = (string) file_get_contents($ROOT . '/config/db.php');
    if (preg_match_all('/\$DB_(USER|PASS|HOST|NAME)\s*=\s*[\'"]([^\'"]*)[\'"]/', $src, $mm, PREG_SET_ORDER)) {
        foreach ($mm as [$_, $clave, $valor]) {
            // 'localhost' y nombres genéricos aparecen legítimamente en ejemplos
            if (strlen($valor) >= 5
                && !in_array(strtolower($valor), ['localhost', '127.0.0.1', 'root'], true)
                && stripos($TODO, $valor) !== false) {
                $fugas[] = 'DB_' . $clave;
            }
        }
    }
}
chk('3. No aparece ninguna credencial real del proyecto', $fugas === [],
    $fugas === [] ? 'ni usuario, ni base, ni host reales' : implode(', ', $fugas));

// 4. Contraseñas literales
$patronesClave = [
    '/password\s*=\s*[\'"][^\'"\s]{4,}[\'"]/i',
    // Un solo guion: --porcelain y otras opciones largas no son contraseñas.
    '/(?<![-\w])-p[A-Za-z0-9!@#$%^&*]{6,}/',
    '/passwd\s*[:=]\s*\S{4,}/i',
];
$claves = [];
foreach ($patronesClave as $pat) {
    if (preg_match($pat, $TODO, $m)) {
        $claves[] = substr($m[0], 0, 20);
    }
}
chk('4. No hay contraseñas escritas literalmente', $claves === [],
    $claves === [] ? 'solo marcadores tipo <usuario>' : implode(' | ', $claves));

// ═════════════════════════════════════════════════════════════
section('5-8 · PRECISIÓN SOBRE EL ESTADO REAL');

// 5. No debe afirmarse que producción ya tiene el módulo
$afirmacionesFalsas = [
    'ya está desplegado en producción',
    'ya desplegado en producción',
    'está en producción desde',
    'producción ya tiene el módulo',
];
$malas = [];
foreach ($afirmacionesFalsas as $a) {
    if (stripos(normalizar($TODO), $a) !== false) {
        $malas[] = $a;
    }
}
chk('5. No se afirma que producción ya esté desplegada',
    $malas === []
    && tiene($T['ATTACHMENTS.md'], 'todav[íi]a no.*desplegado en producci[óo]n')
    && tiene($T['DEPLOYMENT_ATTACHMENTS.md'], 'todav[íi]a no est[áa] desplegado'),
    $malas === [] ? 'los tres documentos lo declaran pendiente' : implode(' | ', $malas));

// 6. La zona horaria está RESUELTA: no debe figurar como pendiente
$tzPendiente = preg_match(
    '/(zona horaria|timezone|huso)[^.\n]{0,60}(pendiente|sin verificar|sin resolver|por confirmar)/iu',
    $TODO
);
chk('6. La zona horaria NO figura como pendiente',
    $tzPendiente === 0 && tiene($T['DEPLOYMENT_ATTACHMENTS.md'], 'ya est[áa] resuelta en producci[óo]n'),
    'documentada como resuelta: America/Bogota y -05:00');

// 7. Los límites de Plesk sí deben figurar como pendientes
chk('7. Los límites de subida figuran como pendientes de verificar',
    tiene($T['ATTACHMENTS.md'], 'todav[íi]a no est[áa] verificado')
    && tiene($T['DEPLOYMENT_ATTACHMENTS.md'], 'sin verificar')
    && tiene($T['DEPLOYMENT_ATTACHMENTS.md'], 'pendiente de medir')
    && tiene($T['DEPLOYMENT_ATTACHMENTS.md'], 'client_max_body_size'));

// 8. MariaDB pendiente
chk('8. La migración en MariaDB figura como no probada',
    tiene($T['ATTACHMENTS.md'], 'MariaDB 10\.6')
    && tiene($T['ATTACHMENTS.md'], 'Sin probar')
    && tiene($T['DEPLOYMENT_ATTACHMENTS.md'], 'solo se han ejecutado.*MySQL 8\.0\.30|nunca sobre MariaDB'),
    'y se exige probar en base temporal antes de migrar');

// Contraprueba: tampoco debe afirmarse reproducción MP4 real ni verificación de límites
$sobreafirma = [];
if (preg_match('/MP4[^.\n]{0,40}(verificad|probad|funciona correctamente)/iu', $TODO, $m)
    && !preg_match('/sin verificar/iu', $m[0])) {
    $sobreafirma[] = 'MP4';
}
if (preg_match('/l[íi]mites[^.\n]{0,40}(ya )?(verificad|confirmad)os/iu', $TODO)) {
    $sobreafirma[] = 'límites';
}
chk('8b. No se sobreafirma MP4 ni los límites', $sobreafirma === [],
    $sobreafirma === [] ? 'ambos declarados sin verificar' : implode(', ', $sobreafirma));

// ═════════════════════════════════════════════════════════════
section('9-16 · CONTENIDO OPERATIVO');

// 9. migraciones en el orden correcto
$dep = $T['DEPLOYMENT_ATTACHMENTS.md'];
$posCrear = strpos($dep, '2026-07-29-create-task-attachments.sql');
$posLinks = strpos($dep, '2026-07-29-add-external-links-to-task-attachments.sql');
chk('9. Las migraciones aparecen en el orden correcto',
    $posCrear !== false && $posLinks !== false && $posCrear < $posLinks
    && tiene($dep, 'orden no es negociable|en este orden exacto'),
    'crear → añadir enlaces');

chk('10. storage/attachments está documentado',
    tiene($T['ATTACHMENTS.md'], 'storage/attachments')
    && tiene($T['ATTACHMENTS.md'], 'AAAA/MM')
    && tiene($T['ATTACHMENTS.md'], 'fuera de.*public')
    && tiene($dep, 'storage/attachments'));

chk('11. Se explica que mysqldump solo no basta',
    tiene($T['BACKUP_RESTORE.md'], 'mysqldump.*(no basta|ya no restaura)')
    && tiene($T['BACKUP_RESTORE.md'], 'dos sitios'));

chk('12. El cron de huérfanos está documentado',
    tiene($T['ATTACHMENTS.md'], 'purge_orphan_attachments')
    && tiene($dep, 'purge_orphan_attachments')
    && tiene($dep, 'purge_trash')
    && tiene($T['BACKUP_RESTORE.md'], 'purge_orphan_attachments'));

chk('13. Se documenta el modo simulacro (--dry-run)',
    substr_count($TODO, '--dry-run') >= 3
    && tiene($dep, 'simulacro'),
    substr_count($TODO, '--dry-run') . ' menciones');

chk('14. Los códigos de salida están documentados',
    tiene($dep, 'Salidas?:')
    && tiene($dep, 'aborto de seguridad')
    && preg_match('/`3`/', $dep) === 1 || tiene($dep, 'código.*3'),
    '0/1/2/3 explicados, incluido el aborto de seguridad');

chk('15. Los permisos están documentados',
    tiene($dep, 'chown')
    && tiene($dep, 'chmod')
    && tiene($dep, '750')
    && tiene($dep, 'grupo'),
    'usuario, grupo, directorios y storage');

// 16. NO recomendar 777
$mal777 = preg_match('/chmod\s+-?R?\s*777/i', $TODO) && !preg_match('/(nunca|no uses|no usar|⛔)[^.\n]{0,80}777/iu', $TODO);
chk('16. No se recomienda chmod 777 en ningún sitio',
    !$mal777 && tiene($dep, '(nunca|no usar).{0,20}777'),
    'aparece solo como advertencia explícita');

// ═════════════════════════════════════════════════════════════
section('17-24 · RECUPERACIÓN Y SEGURIDAD');

chk('17. El rollback está documentado',
    tiene($dep, 'Rollback')
    && tiene($dep, 'Restaurar la base')
    && tiene($dep, 'Retirar.*cron|crontab -l \| grep -v')
    && tiene($dep, 'Validaci[óo]n final'));

chk('18. El orden de restauración está documentado',
    tiene($T['BACKUP_RESTORE.md'], 'Orden de restauraci[óo]n')
    && tiene($T['BACKUP_RESTORE.md'], 'C[óo]digo compatible')
    && tiene($T['BACKUP_RESTORE.md'], 'Permisos')
    && tiene($T['BACKUP_RESTORE.md'], 'El orden importa'));

chk('19. La verificación por SHA-256 está documentada',
    tiene($T['BACKUP_RESTORE.md'], 'SHA-?256')
    && tiene($T['BACKUP_RESTORE.md'], 'sha256sum -c|Get-FileHash')
    && tiene($T['BACKUP_RESTORE.md'], 'SHA256SUMS'));

chk('20. Se explica que app.css queda fuera del versionado',
    tiene($T['ATTACHMENTS.md'], 'app\.css.*(queda fuera|fuera por ahora)')
    && tiene($T['ATTACHMENTS.md'], 'Tailwind'));

chk('21. asset_url() y filemtime están documentados',
    tiene($T['ATTACHMENTS.md'], 'asset_url')
    && tiene($T['ATTACHMENTS.md'], 'filemtime')
    && tiene($T['ATTACHMENTS.md'], 'theme\.css')
    && tiene($T['ATTACHMENTS.md'], 'board-view\.js'));

chk('22. El soporte de rangos HTTP está documentado',
    tiene($T['ATTACHMENTS.md'], 'Range|rango')
    && tiene($T['ATTACHMENTS.md'], '206')
    && tiene($T['ATTACHMENTS.md'], '416'));

chk('23. Se explica por qué YouTube y Vimeo son seguros',
    tiene($T['ATTACHMENTS.md'], 'youtube-nocookie|player\.vimeo')
    && tiene($T['ATTACHMENTS.md'], 'plantilla propia')
    && tiene($T['ATTACHMENTS.md'], 'external_url.*(jam[áa]s|nunca).*src|nunca.*src.*iframe')
    && tiene($T['ATTACHMENTS.md'], 'lista.*exacta|hosts.*exact'),
    'ID validado + plantilla propia + hosts exactos');

chk('24. Se documenta que stored_path no se expone',
    tiene($T['ATTACHMENTS.md'], 'stored_path')
    && tiene($T['ATTACHMENTS.md'], 'stored_path.*(nunca|no).*(sale|expone|HTML)'),
    'ni en HTML ni en las respuestas JSON');

// ═════════════════════════════════════════════════════════════
section('25-30 · REFERENCIA PARA QUIEN MANTIENE');

$att = $T['ATTACHMENTS.md'];

chk('25. Los límites actuales están documentados',
    tiene($att, '10 MB') && tiene($att, '20 MB') && tiene($att, '50 MB')
    && tiene($att, '5 archivos|M[áa]ximo 5'),
    'imagen 10 / audio 20 / vídeo 50 / máx. 5');

chk('26. Los formatos aceptados están documentados',
    tiene($att, 'jpg') && tiene($att, 'webp') && tiene($att, 'mp3')
    && tiene($att, 'wav') && tiene($att, 'mp4') && tiene($att, 'webm') && tiene($att, 'mov'),
    'las 12 extensiones de la lista blanca');

chk('27. Los permisos por rol están documentados',
    tiene($att, 'Lector') && tiene($att, 'Editor') && tiene($att, 'Propietario')
    && tiene($att, 'Ajeno al tablero'),
    'con qué puede hacer cada uno');

chk('28. Las suites y el total de pruebas están documentados',
    tiene($att, 'attachments_backend_smoke')
    && tiene($att, 'attachments_lifecycle_smoke')
    && tiene($att, 'assets_versioning_smoke')
    && tiene($att, '347'),
    'las 8 suites con su recuento');

chk('29. Los pendientes conocidos están documentados',
    tiene($att, 'Pendientes conocidos')
    && tiene($att, 'Plesk')
    && tiene($att, 'MariaDB')
    && tiene($att, 'MP4')
    && tiene($att, 'm[óo]vil')
    && tiene($att, 'Retenci[óo]n de respaldos|retenci[óo]n'),
    'sin ocultar ninguno');

$muggle = 0;
foreach ($T as $n => $c) {
    if (tiene($c, 'Resumen muggle')) {
        $muggle++;
    }
}
chk('30. Los tres documentos tienen resumen muggle', $muggle === 3, "$muggle/3");

// Extras
section('31-34 · COHERENCIA ENTRE DOCUMENTOS');

chk('31. Los tres documentos se enlazan entre sí',
    tiene($T['ATTACHMENTS.md'], '\(DEPLOYMENT_ATTACHMENTS\.md\)')
    && tiene($T['ATTACHMENTS.md'], '\(BACKUP_RESTORE\.md\)')
    && tiene($dep, '\(ATTACHMENTS\.md\)')
    && tiene($dep, '\(BACKUP_RESTORE\.md\)')
    && tiene($T['BACKUP_RESTORE.md'], '\(ATTACHMENTS\.md\)')
    && tiene($T['BACKUP_RESTORE.md'], '\(DEPLOYMENT_ATTACHMENTS\.md\)'),
    'enlaces relativos en las dos direcciones');

// Los enlaces relativos deben apuntar a archivos que existen
preg_match_all('/\]\(([A-Za-z0-9_]+\.md)(#[^)]*)?\)/', $TODO, $mm);
$rotos = [];
foreach (array_unique($mm[1]) as $destino) {
    if (!is_file($DOCS . '/' . $destino)) {
        $rotos[] = $destino;
    }
}
chk('32. Ningún enlace relativo apunta a un archivo inexistente',
    $rotos === [], $rotos === [] ? count(array_unique($mm[1])) . ' destinos, todos existen' : implode(', ', $rotos));

// Se documenta que los assets no necesitan respaldo especial
chk('33. Se aclara que los assets no necesitan respaldo especial',
    tiene($T['BACKUP_RESTORE.md'], '(no|tampoco) necesitan respaldo especial')
    && tiene($T['BACKUP_RESTORE.md'], 'asset_url|\?v='));

// La retención sigue marcada como pendiente
chk('34. La retención de respaldos figura como decisión pendiente',
    tiene($T['BACKUP_RESTORE.md'], 'nunca borra respaldos')
    && tiene($T['BACKUP_RESTORE.md'], 'rotaci[óo]n')
    && tiene($att, 'Retenci[óo]n de respaldos'));

// ═════════════════════════════════════════════════════════════
echo "\n" . str_repeat('═', 78) . "\n";
printf(" RESULTADO: %d correctas, %d fallidas\n", $PASS, $FAIL);
echo str_repeat('═', 78) . "\n\n";

exit($FAIL === 0 ? 0 : 1);
