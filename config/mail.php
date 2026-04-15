<?php
// config/mail.php — Configuración SMTP para FYC Planner
// En desarrollo apunta a Mailpit (puerto 1025, sin autenticación).
// Para producción, cambia los valores y configura MAIL_SMTP_USER / MAIL_SMTP_PASS.

defined('MAIL_ENABLED')   || define('MAIL_ENABLED',    true);
defined('MAIL_SMTP_HOST') || define('MAIL_SMTP_HOST',  'localhost');
defined('MAIL_SMTP_PORT') || define('MAIL_SMTP_PORT',  1025);
defined('MAIL_SMTP_USER') || define('MAIL_SMTP_USER',  '');   // vacío = sin autenticación
defined('MAIL_SMTP_PASS') || define('MAIL_SMTP_PASS',  '');
defined('MAIL_FROM_ADDR') || define('MAIL_FROM_ADDR',  'noreply@fyc-planner.local');
defined('MAIL_FROM_NAME') || define('MAIL_FROM_NAME',  'FYC Planner');
// URL base de la aplicación — se usa para construir enlaces en los correos.
// En producción cambia a la URL pública real (sin barra final).
defined('MAIL_APP_URL')   || define('MAIL_APP_URL',    'http://localhost/fyc_planner/public');
