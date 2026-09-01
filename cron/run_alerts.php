<?php
// cron/run_alerts.php — Ejecución automática de alertas (CLI únicamente)
//
// Uso:
//   php cron/run_alerts.php            evalúa, inserta y (si procede) envía
//   php cron/run_alerts.php --dry-run  enseña qué haría, sin tocar nada
//   php cron/run_alerts.php --help
//
// Este archivo está fuera de public/ y no es accesible por HTTP.
// Si por algún motivo el servidor lo sirviera, la guardia SAPI lo detiene.
//
// POLÍTICA DE CORREO
//   La decide public/admin/_email_policy.php, no este archivo:
//     · actividad normal        -> nunca por correo
//     · alert_user_overload     -> correo inmediato
//     · alertas de tablero      -> resumen diario, uno por persona
//     · nada que contar         -> ningún correo
//
//   Mientras EMAIL_POLICY_START valga null NO se envía nada en absoluto,
//   aunque MAIL_ENABLED esté activo. Es el interruptor general.

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit(1);
}

// ─────────────────────────────────────────────────────────────
// Argumentos
//
// Estricto a propósito: un argumento mal escrito aborta con código 2 en vez de
// interpretarse como «modo normal». Un --dry-runn no puede acabar enviando
// correos de verdad.
// ─────────────────────────────────────────────────────────────
$dryRun = false;

foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--dry-run' || $arg === '-n') {
        $dryRun = true;
    } elseif ($arg === '--help' || $arg === '-h') {
        echo "Uso: php run_alerts.php [--dry-run]\n";
        echo "  sin argumentos : evalua las alertas, las inserta y envia segun la politica\n";
        echo "  --dry-run      : enseña que crearia y que enviaria, sin escribir ni enviar\n";
        exit(0);
    } else {
        fwrite(STDERR, "Argumento no reconocido: {$arg}\n");
        exit(2);
    }
}

require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../config/db.php';
app_sync_db_timezone($conn);
require_once __DIR__ . '/../config/mail.php';
require_once __DIR__ . '/../public/admin/_alerts_core.php';
require_once __DIR__ . '/../public/admin/_email_policy.php';
require_once __DIR__ . '/../public/admin/_email_helpers.php';

$start = microtime(true);

try {
    if ($dryRun) {
        // ── SIMULACRO ────────────────────────────────────────────
        // Ni un INSERT, ni un UPDATE, ni un envío. Se evalúan las mismas
        // reglas y se cuenta qué habrían producido.
        $sim = simular_alertas($conn);

        echo '[' . date('Y-m-d H:i:s') . "] SIMULACRO — no se ha escrito ni enviado nada\n";
        echo "  politica activa      : " . (email_policy_activa() ? 'si (desde ' . EMAIL_POLICY_START . ')' : 'NO — EMAIL_POLICY_START esta sin fijar, no saldria ningun correo') . "\n";
        $marca = email_bootstrap_marca($conn);
        echo "  marca de arranque    : " . ($marca ?? 'sin anotar — la proxima pasada real seria el ARRANQUE y no enviaria nada') . PHP_EOL;
        echo "  MAIL_ENABLED         : " . (MAIL_ENABLED ? 'true' : 'false') . "\n";
        echo "  dia de referencia    : " . email_dia_actual() . ' (' . APP_TIMEZONE . ")\n";
        echo "\n  NOTIFICACIONES QUE SE CREARIAN\n";
        $totalNuevas = 0;
        foreach ($sim['por_tipo'] as $tipo => $n) {
            printf("    %-26s %4d   modo=%s\n", $tipo, $n, email_delivery_mode($tipo));
            $totalNuevas += $n;
        }
        if ($totalNuevas === 0) {
            echo "    (ninguna: no se cumple ningun umbral)\n";
        }
        printf("    %-26s %4d\n", 'TOTAL', $totalNuevas);

        echo "\n  CORREOS INMEDIATOS\n";
        if ($sim['inmediatos'] === []) {
            echo "    (ninguno)\n";
        }
        foreach ($sim['inmediatos'] as $i) {
            printf("    WOULD_SEND_IMMEDIATE  usuario=%d  tipo=%s  %s\n",
                $i['user_id'], $i['tipo'], email_dia_actual());
        }

        echo "\n  RESUMENES DIARIOS\n";
        if ($sim['digests'] === []) {
            echo "    (ninguno)\n";
        }
        foreach ($sim['digests'] as $uid => $n) {
            printf("    WOULD_SEND_DIGEST     usuario=%d  alertas=%d\n", $uid, $n);
        }

        printf("\n  RESUMEN: %d notificacion(es), %d correo(s) inmediato(s), %d resumen(es) = %d correos\n",
            $totalNuevas, count($sim['inmediatos']), count($sim['digests']),
            count($sim['inmediatos']) + count($sim['digests']));
        printf("  (%d ms)\n", round((microtime(true) - $start) * 1000));
        exit(0);
    }

    // ── EJECUCIÓN REAL ───────────────────────────────────────────
    $result = run_all_alerts($conn);

    $inmediatos = 0;
    $resumenes  = 0;
    $arranque   = false;

    if (email_policy_activa()) {
        // ARRANQUE
        //
        // Si aun no hay marca, esta es la primera pasada con la politica
        // encendida. Las alertas que se acaban de crear describen el estado
        // que YA existia —tableros vencidos desde hace meses—, y avisar de eso
        // por correo seria ruido puro. Se anota el instante DESPUES de
        // crearlas: a partir de ahi solo cuenta lo creado estrictamente
        // despues, asi que esta tanda queda fuera para siempre.
        //
        // No hay que ejecutar nada en un orden concreto ni recordar ningun
        // paso: lo hace el propio cron, una sola vez.
        if (email_bootstrap_marca($conn) === null) {
            $arranque = true;
            if (!email_bootstrap_registrar($conn, date('Y-m-d H:i:s'))) {
                fwrite(STDERR, date('Y-m-d H:i:s')
                    . ' — ERROR: no se pudo anotar la marca de arranque. No se envia nada.' . PHP_EOL);
                exit(1);
            }
        } else {
            $inmediatos = enviar_inmediatos($conn, $result['new_ids'] ?? []);
            $resumenes  = enviar_digests($conn);
        }
    }

    $elapsed = round((microtime(true) - $start) * 1000);
    echo '[' . date('Y-m-d H:i:s') . '] OK — '
       . 'inserted='   . $result['inserted'] . ' '
       . 'skipped='    . $result['skipped']  . ' '
       . 'inmediatos=' . $inmediatos         . ' '
       . 'resumenes='  . $resumenes          . ' '
       . ($arranque ? '(ARRANQUE: marca anotada, 0 correos por diseno) ' : '')
       . (email_policy_activa() ? '' : '(politica de correo SIN activar) ')
       . '(' . $elapsed . 'ms)' . PHP_EOL;
    exit(0);
} catch (Throwable $e) {
    echo '[' . date('Y-m-d H:i:s') . '] ERROR — ' . $e->getMessage() . PHP_EOL;
    exit(1);
}
