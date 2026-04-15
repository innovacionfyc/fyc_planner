<?php
// public/admin/_email_helpers.php — Envío de emails HTML para alertas del sistema
// Solo definiciones: no ejecuta nada al incluirse.
// Requiere que config/mail.php ya esté cargado.

// ============================================================
// Cliente SMTP mínimo (sin dependencias externas)
// ============================================================

/**
 * Envía un email HTML a través de SMTP.
 *
 * No implementa TLS ni autenticación (suficiente para Mailpit en dev).
 * Devuelve true si el servidor aceptó el mensaje, false en cualquier error.
 */
function smtp_send(string $toAddr, string $toName, string $subject, string $body): bool
{
    if (!MAIL_ENABLED) {
        return true;
    }

    $sock = @fsockopen(MAIL_SMTP_HOST, MAIL_SMTP_PORT, $errno, $errstr, 5);
    if (!$sock) {
        error_log("smtp_send: no se pudo conectar a " . MAIL_SMTP_HOST . ":" . MAIL_SMTP_PORT . " — $errstr ($errno)");
        return false;
    }

    $readCode = function () use ($sock): int {
        $code = 0;
        while (!feof($sock)) {
            $line = fgets($sock, 512);
            if ($line === false) break;
            $code = (int)substr($line, 0, 3);
            if (isset($line[3]) && $line[3] === ' ') break;
        }
        return $code;
    };

    $write = function (string $cmd) use ($sock): void {
        fputs($sock, $cmd . "\r\n");
    };

    $ok = false;
    try {
        $readCode();                                        // 220 greeting
        $write('EHLO fyc-planner.local');
        $readCode();                                        // 250-...

        $from = MAIL_FROM_ADDR;
        $write("MAIL FROM:<{$from}>");
        if ($readCode() !== 250) { fclose($sock); return false; }

        $write("RCPT TO:<{$toAddr}>");
        if ($readCode() !== 250) { fclose($sock); return false; }

        $write('DATA');
        if ($readCode() !== 354) { fclose($sock); return false; }

        // Escapar líneas que empiezan con punto (RFC 5321 §4.5.2)
        $safeBody = preg_replace('/^\.$/m', '..', $body);

        $msg = "From: " . MAIL_FROM_NAME . " <{$from}>\r\n"
             . "To: {$toName} <{$toAddr}>\r\n"
             . "Subject: =?UTF-8?B?" . base64_encode($subject) . "?=\r\n"
             . "MIME-Version: 1.0\r\n"
             . "Content-Type: text/html; charset=UTF-8\r\n"
             . "\r\n"
             . $safeBody
             . "\r\n.";
        $write($msg);
        $code = $readCode();                                // 250 queued
        $ok   = ($code >= 200 && $code < 300);

        $write('QUIT');
        $readCode();
    } finally {
        fclose($sock);
    }

    return $ok;
}

// ============================================================
// Helpers de plantilla HTML
// ============================================================

/**
 * Construye la URL al tablero dentro de la aplicación.
 */
function _email_board_url(int $boardId): string
{
    return rtrim(MAIL_APP_URL, '/') . '/boards/view.php?id=' . $boardId;
}

/**
 * Línea HTML de contexto de equipo (vacía si el tablero es personal).
 */
function _email_team_line(?string $teamName): string
{
    if (empty($teamName)) return '';
    $t = htmlspecialchars($teamName, ENT_QUOTES, 'UTF-8');
    return '<p style="margin:0 0 20px;font-size:13px;color:#a07080;">'
         . 'Equipo: <strong style="color:#1a0a0e;">' . $t . '</strong></p>';
}

/**
 * Línea HTML del responsable principal (vacía si no hay datos).
 */
function _email_assignee_line(?string $assigneeName): string
{
    if (empty($assigneeName)) return '';
    $n = htmlspecialchars($assigneeName, ENT_QUOTES, 'UTF-8');
    return '<p style="margin:0 0 20px;font-size:13px;color:#a07080;">'
         . 'Responsable principal: <strong style="color:#1a0a0e;">' . $n . '</strong></p>';
}

/**
 * Envuelve el contenido en el HTML completo del email (estructura tabla-compatible).
 *
 * @param string $headerBg    Color de fondo del header (hex)
 * @param string $headerTitle Título principal (texto plano, se escapará)
 * @param string $headerSub   Subtítulo del header (texto plano)
 * @param string $bodyHtml    Contenido interior ya formateado como HTML
 * @param int    $boardId     ID del tablero para el botón CTA
 */
function _email_envelope(
    string $headerBg,
    string $headerTitle,
    string $headerSub,
    string $bodyHtml,
    int $boardId
): string {
    $title  = htmlspecialchars($headerTitle, ENT_QUOTES, 'UTF-8');
    $sub    = htmlspecialchars($headerSub,   ENT_QUOTES, 'UTF-8');
    $url    = _email_board_url($boardId);
    $now    = date('j \d\e F \d\e Y \a \l\a\s H:i');

    // Meses en español
    static $meses = [
        'January'=>'enero','February'=>'febrero','March'=>'marzo',
        'April'=>'abril','May'=>'mayo','June'=>'junio',
        'July'=>'julio','August'=>'agosto','September'=>'septiembre',
        'October'=>'octubre','November'=>'noviembre','December'=>'diciembre',
    ];
    $now = str_replace(array_keys($meses), array_values($meses), $now);

    return <<<HTML
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width,initial-scale=1.0">
</head>
<body style="margin:0;padding:0;background:#f5eef0;
             font-family:system-ui,-apple-system,Segoe UI,sans-serif;">

  <table width="100%" cellpadding="0" cellspacing="0" border="0"
         style="background:#f5eef0;padding:32px 16px;">
    <tr><td align="center">

      <table width="600" cellpadding="0" cellspacing="0" border="0"
             style="max-width:600px;width:100%;border-radius:12px;
                    overflow:hidden;box-shadow:0 2px 10px rgba(0,0,0,.14);">

        <!-- HEADER -->
        <tr>
          <td style="background:{$headerBg};padding:24px 32px;">
            <p style="margin:0 0 6px;color:#f8d0d8;font-size:11px;
                      letter-spacing:.09em;text-transform:uppercase;">
              FYC Planner &middot; Sistema de Alertas
            </p>
            <h1 style="margin:0;color:#ffffff;font-size:20px;
                       font-weight:800;line-height:1.3;">
              {$title}
            </h1>
            <p style="margin:6px 0 0;color:#f0c0ca;font-size:13px;">
              {$sub}
            </p>
          </td>
        </tr>

        <!-- BODY -->
        <tr>
          <td style="background:#ffffff;padding:28px 32px;">
            {$bodyHtml}

            <!-- CTA -->
            <table cellpadding="0" cellspacing="0" border="0" style="margin-top:28px;">
              <tr>
                <td style="background:#e85070;border-radius:8px;">
                  <a href="{$url}"
                     style="display:inline-block;padding:12px 28px;
                            color:#ffffff;font-size:14px;font-weight:700;
                            text-decoration:none;letter-spacing:.02em;">
                    Ir al tablero &rarr;
                  </a>
                </td>
              </tr>
            </table>
          </td>
        </tr>

        <!-- FOOTER -->
        <tr>
          <td style="background:#f5eef0;padding:14px 32px;
                     border-top:1px solid #e8c8cc;">
            <p style="margin:0;font-size:11px;color:#a07080;line-height:1.6;">
              Generado automáticamente el <strong>{$now}</strong>.
              No respondas a este mensaje.
            </p>
          </td>
        </tr>

      </table>
    </td></tr>
  </table>

</body>
</html>
HTML;
}

/**
 * Bloque de tres métricas en tarjetas (tabla 3 columnas).
 *
 * @param array $metrics  Array de hasta 3 ['value'=>'75%','label'=>'Vencidas','color'=>'#c82040']
 */
function _email_metrics(array $metrics): string
{
    $cols = '';
    $first = true;
    foreach (array_slice($metrics, 0, 3) as $m) {
        $v   = htmlspecialchars((string)($m['value'] ?? '—'), ENT_QUOTES, 'UTF-8');
        $lbl = htmlspecialchars((string)($m['label'] ?? ''),  ENT_QUOTES, 'UTF-8');
        $col = $m['color'] ?? '#942934';
        $sep = $first ? '' : '<td width="3%"></td>';
        $first = false;
        $cols .= $sep
               . '<td style="background:#fff8f9;border:1px solid #e8c8cc;'
               . 'border-radius:8px;padding:16px;text-align:center;">'
               . '<p style="margin:0;font-size:30px;font-weight:800;color:' . $col . ';">' . $v . '</p>'
               . '<p style="margin:5px 0 0;font-size:10px;color:#9a6878;'
               . 'text-transform:uppercase;letter-spacing:.06em;">' . $lbl . '</p>'
               . '</td>';
    }

    return '<table width="100%" cellpadding="0" cellspacing="0" border="0"'
         . ' style="margin-bottom:24px;"><tr>' . $cols . '</tr></table>';
}

/**
 * Bloque de texto descriptivo con acento lateral vinotinto.
 */
function _email_callout(string $html): string
{
    return '<p style="margin:0 0 0;font-size:14px;color:#3a1a22;line-height:1.65;'
         . 'border-left:3px solid #e85070;padding-left:14px;">'
         . $html . '</p>';
}

// ============================================================
// Plantillas por tipo de alerta
// ============================================================

/**
 * Convierte un tipo de alerta + payload en ['subject' => ..., 'body' => ...].
 * El body es HTML completo listo para enviar.
 * Devuelve null si el tipo no tiene plantilla.
 */
function format_alert_email(string $tipo, array $payload): ?array
{
    $h = fn(string $s) => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');

    switch ($tipo) {

        // --------------------------------------------------------
        // Tareas vencidas — rojo crítico
        // --------------------------------------------------------
        case 'alert_team_overdue':
            $board   = $payload['board_name']        ?? '?';
            $pct     = $payload['pct']               ?? 0;
            $venc    = $payload['vencidas']           ?? 0;
            $total   = $payload['tareas']             ?? 0;
            $boardId = (int)($payload['board_id']     ?? 0);
            $team    = $payload['team_name']          ?? null;
            $assignee = $payload['top_assignee_name'] ?? null;

            $ctx = $team ? "«{$board}» · Equipo {$team}" : "«{$board}»";

            $body = _email_team_line($team)
                  . _email_assignee_line($assignee)
                  . _email_metrics([
                        ['value' => $pct . '%', 'label' => 'Vencidas',    'color' => '#c82040'],
                        ['value' => $venc,       'label' => 'Tareas venc.','color' => '#942934'],
                        ['value' => $total,      'label' => 'Total tareas','color' => '#4a2030'],
                    ])
                  . _email_callout(
                        '<strong>' . $pct . '% de las tareas están vencidas</strong>, '
                        . 'superando el umbral del 20%. Revisa las fechas límite y reasigna '
                        . 'o cierra las tareas pendientes antes de que el retraso escale.'
                    );

            return [
                'subject' => "[Alerta] {$ctx}: {$pct}% de tareas vencidas",
                'body'    => _email_envelope('#942934', '⚠ Tareas vencidas detectadas',
                                 'Acción requerida — ' . $h($board), $body, $boardId),
            ];

        // --------------------------------------------------------
        // Tareas sin movimiento — ámbar
        // --------------------------------------------------------
        case 'alert_team_stale':
            $board    = $payload['board_name']        ?? '?';
            $stale    = $payload['stale_count']       ?? 0;
            $dias     = $payload['dias']              ?? 5;
            $total    = $payload['tareas']            ?? 0;
            $boardId  = (int)($payload['board_id']    ?? 0);
            $team     = $payload['team_name']         ?? null;
            $assignee = $payload['top_assignee_name'] ?? null;

            $ctx = $team ? "«{$board}» · Equipo {$team}" : "«{$board}»";

            $body = _email_team_line($team)
                  . _email_assignee_line($assignee)
                  . _email_metrics([
                        ['value' => $stale, 'label' => 'Sin movimiento', 'color' => '#b07010'],
                        ['value' => $dias . 'd', 'label' => 'Sin actividad', 'color' => '#8a5800'],
                        ['value' => $total, 'label' => 'Total tareas',    'color' => '#4a3010'],
                    ])
                  . _email_callout(
                        '<strong>' . $stale . ' tarea' . ($stale !== 1 ? 's' : '') . ' llevan '
                        . 'más de ' . $dias . ' días sin actividad.</strong> '
                        . 'Evalúa si siguen vigentes, actualiza su estado o reasígnalas '
                        . 'para mantener el ritmo del equipo.'
                    );

            return [
                'subject' => "[Alerta] {$ctx}: {$stale} tareas sin movimiento",
                'body'    => _email_envelope('#7a5010', '▲ Tareas estancadas',
                                 'Requiere revisión — ' . $h($board), $body, $boardId),
            ];

        // --------------------------------------------------------
        // Tareas sin responsable — ámbar
        // --------------------------------------------------------
        case 'alert_team_unassigned':
            $board   = $payload['board_name'] ?? '?';
            $pct     = $payload['pct']        ?? 0;
            $sinResp = $payload['sin_resp']   ?? 0;
            $total   = $payload['tareas']     ?? 0;
            $boardId = (int)($payload['board_id'] ?? 0);
            $team    = $payload['team_name']  ?? null;

            $ctx = $team ? "«{$board}» · Equipo {$team}" : "«{$board}»";

            $body = _email_team_line($team)
                  . _email_metrics([
                        ['value' => $pct . '%',  'label' => 'Sin responsable', 'color' => '#b07010'],
                        ['value' => $sinResp,     'label' => 'Tareas huérfanas','color' => '#8a5800'],
                        ['value' => $total,       'label' => 'Total tareas',    'color' => '#4a3010'],
                    ])
                  . _email_callout(
                        '<strong>' . $sinResp . ' tarea' . ($sinResp !== 1 ? 's' : '')
                        . ' (' . $pct . '%) no tienen responsable asignado.</strong> '
                        . 'Sin un propietario claro, estas tareas no se completarán. '
                        . 'Asigna un responsable a cada una desde el tablero.'
                    );

            return [
                'subject' => "[Alerta] {$ctx}: {$pct}% de tareas sin responsable",
                'body'    => _email_envelope('#7a5010', '▲ Tareas sin responsable',
                                 'Asignación requerida — ' . $h($board), $body, $boardId),
            ];

        // --------------------------------------------------------
        // Sobrecarga de usuario — rojo
        // --------------------------------------------------------
        case 'alert_user_overload':
            $nombre   = $payload['user_name'] ?? '?';
            $asig     = $payload['asignadas'] ?? 0;
            $vencidas = $payload['vencidas']  ?? 0;
            // Para overload no hay board_id directo; usamos 0 → enlace al dashboard
            $boardId  = (int)($payload['board_id'] ?? 0);

            $ctaUrl = $boardId > 0
                ? _email_board_url($boardId)
                : rtrim(MAIL_APP_URL, '/') . '/admin/stats.php';

            $body = _email_metrics([
                        ['value' => $asig,     'label' => 'Asignadas',  'color' => '#c82040'],
                        ['value' => $vencidas, 'label' => 'Vencidas',   'color' => '#942934'],
                    ])
                  . _email_callout(
                        '<strong>' . $h($nombre) . ' tiene ' . $asig . ' tareas asignadas'
                        . ($vencidas > 0 ? ', de las cuales ' . $vencidas . ' están vencidas' : '')
                        . '.</strong> Esta carga supera el umbral recomendado. '
                        . 'Redistribuye las tareas menos urgentes para proteger el rendimiento del equipo.'
                    );

            // Sobreescribir URL del CTA para overload (va al dashboard)
            $envHtml = _email_envelope('#942934', '⚠ Sobrecarga de tareas',
                           'Intervención recomendada — ' . $h($nombre), $body, 0);

            // Reemplazar el href generado (board_id=0) por la URL del dashboard
            $dashUrl = rtrim(MAIL_APP_URL, '/') . '/admin/stats.php';
            $envHtml = str_replace(
                _email_board_url(0),
                $dashUrl,
                $envHtml
            );

            return [
                'subject' => "[Alerta] Sobrecarga: {$nombre} ({$asig} tareas asignadas)",
                'body'    => $envHtml,
            ];

        default:
            return null;
    }
}

// ============================================================
// Envío por lote
// ============================================================

/**
 * Envía emails HTML para cada notification ID recién insertada.
 * Devuelve el número de emails enviados correctamente.
 */
function send_alert_emails(mysqli $conn, array $newIds): int
{
    if (empty($newIds) || !MAIL_ENABLED) {
        return 0;
    }

    $ids          = array_map('intval', $newIds);
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $types        = str_repeat('i', count($ids));

    $stmt = $conn->prepare(
        "SELECT n.id, n.tipo, n.payload_json, u.email, u.nombre
         FROM notifications n
         JOIN users u ON u.id = n.user_id
         WHERE n.id IN ({$placeholders})"
    );
    $stmt->bind_param($types, ...$ids);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

    $sent = 0;
    foreach ($rows as $row) {
        $payload = json_decode($row['payload_json'], true) ?? [];
        $tpl     = format_alert_email($row['tipo'], $payload);
        if ($tpl === null) continue;

        $ok = smtp_send($row['email'], $row['nombre'], $tpl['subject'], $tpl['body']);
        if ($ok) {
            $sent++;
        } else {
            error_log("send_alert_emails: fallo al enviar notif #{$row['id']} a {$row['email']}");
        }
    }

    return $sent;
}
