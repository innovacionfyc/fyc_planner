<?php
// config/mail.example.php
// Copia este archivo a config/mail.php y ajusta los valores en cada entorno.
//
// ⚠️ config/mail.php NO viaja en el paquete de despliegue: cada entorno
// conserva el suyo. Ver scripts/build_release.php y
// docs/DEPLOYMENT_ATTACHMENTS.md §13.
//
// En desarrollo se suele apuntar a un captador local (Mailpit o MailHog) en el
// puerto 1025, sin autenticación. En producción hay que poner el SMTP real.

defined('MAIL_ENABLED')   || define('MAIL_ENABLED',    true);

// Desarrollo: 'localhost' con puerto 1025 (Mailpit/MailHog).
// Producción : el host SMTP del proveedor, normalmente puerto 587.
defined('MAIL_SMTP_HOST') || define('MAIL_SMTP_HOST', 'localhost');
defined('MAIL_SMTP_PORT') || define('MAIL_SMTP_PORT',  1025);

// Vacíos = sin autenticación (solo válido para el captador local).
// En producción hay que rellenarlos, y NUNCA se versionan.
defined('MAIL_SMTP_USER') || define('MAIL_SMTP_USER', '');
defined('MAIL_SMTP_PASS') || define('MAIL_SMTP_PASS', '');

defined('MAIL_FROM_ADDR') || define('MAIL_FROM_ADDR', 'noreply@ejemplo.local');
defined('MAIL_FROM_NAME') || define('MAIL_FROM_NAME', 'FYC Planner');

// URL base con la que se construyen los enlaces de los correos.
defined('MAIL_APP_URL')   || define('MAIL_APP_URL',   'http://localhost/fyc_planner');
