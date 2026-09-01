-- =====================================================================
-- Migración incremental: marca de envío por correo en notifications
-- Fecha    : 2026-08-31
-- Fase     : Política de correo — Fase 1 (base segura, sin SMTP todavía)
-- Base     : schema_fyc_planner_db.sql (tabla notifications ya existente)
--
-- Objetivo
--   Saber qué notificaciones ya salieron por correo, para no repetirlas y
--   para poder construir un resumen diario por persona.
--
--   Hoy no existe ninguna marca: cron/run_alerts.php envía un correo por cada
--   notificación recién insertada y no anota nada. Si el proceso muere entre
--   el INSERT y el envío, ese correo se pierde sin rastro; si algo reenviara
--   los mismos identificadores, se duplicaría sin que nada lo impidiera.
--
-- Contrato de la columna
--   NULL       -> nunca se envió por correo. Es el estado inicial de todas
--                 las filas, incluidas las 1.187 ya existentes.
--   timestamp  -> la notificación se incluyó en un correo que SALIÓ BIEN.
--
--   Se marca ÚNICAMENTE tras un envío con éxito. Nunca antes, nunca en
--   simulacro y nunca si el SMTP falla: si algo sale mal la fila se queda en
--   NULL y el siguiente intento la vuelve a recoger. Preferimos reintentar un
--   aviso a perderlo en silencio.
--
-- Por qué una sola columna y no una tabla aparte
--   Cubre las cuatro necesidades del modelo: evitar duplicados (WHERE
--   emailed_at IS NULL), saber qué ya salió, agrupar el resumen por persona y
--   acotar el histórico.
--
--   Lo único que NO distingue es «nunca se intentó» de «se intentó y falló»:
--   las dos quedan en NULL. Es deliberado. Contar intentos exigiría una
--   columna más y una tabla de registro que hoy nadie consultaría; los fallos
--   de envío ya se registran en error_log. Si algún día hace falta auditar
--   reintentos, se añade entonces con la información de uso real.
--
-- Compatibilidad
--   Solo ALTER estándar. Probada en MySQL 8.0.30 (local) y pensada para
--   MariaDB 10.6 (producción). No se usa ADD COLUMN IF NOT EXISTS porque esa
--   sintaxis existe en MariaDB pero NO en MySQL 8: la comprobación previa va
--   más abajo, y es válida en los dos motores.
--
-- Conservación de datos
--   Columna nueva, opcional y con valor por defecto NULL. Ninguna fila
--   existente cambia. Las 1.187 notificaciones actuales quedan como «nunca
--   enviadas», que es exactamente lo que son.
-- =====================================================================

-- ---------------------------------------------------------------------
-- 1) Comprobación previa: ¿ya existe la columna?
--    Ejecuta esto ANTES. Si devuelve 1, la migración ya se aplicó y el
--    ALTER de abajo daría error 1060 (Duplicate column name).
-- ---------------------------------------------------------------------
SELECT COUNT(*) AS ya_existe
  FROM information_schema.COLUMNS
 WHERE TABLE_SCHEMA = DATABASE()
   AND TABLE_NAME   = 'notifications'
   AND COLUMN_NAME  = 'emailed_at';

-- ---------------------------------------------------------------------
-- 2) La columna
--    Va después de read_at: las dos son marcas de estado de la misma fila y
--    conviene leerlas juntas.
-- ---------------------------------------------------------------------
ALTER TABLE `notifications`
  ADD COLUMN `emailed_at` datetime DEFAULT NULL AFTER `read_at`;

-- ---------------------------------------------------------------------
-- 3) Índice
--    La consulta que construye el resumen filtra por (user_id, emailed_at IS
--    NULL) y ordena por created_at. Ya existe idx_notes_user_unread sobre
--    (user_id, read_at), que no sirve para esto: son campos distintos.
--
--    Con 1.187 filas cualquier plan es rápido, pero esta consulta se ejecutará
--    en cada pasada del cron y la tabla solo crece.
-- ---------------------------------------------------------------------
ALTER TABLE `notifications`
  ADD KEY `idx_notes_user_pending_email` (`user_id`, `emailed_at`, `created_at`);

-- ---------------------------------------------------------------------
-- 4) Verificación posterior
-- ---------------------------------------------------------------------
SELECT
    COUNT(*)                              AS total,
    SUM(emailed_at IS NULL)               AS sin_enviar,
    SUM(emailed_at IS NOT NULL)           AS enviadas
  FROM notifications;
-- Esperado tras aplicar: sin_enviar = total, enviadas = 0.

-- =====================================================================
-- REVERSIÓN
--
--   ALTER TABLE `notifications` DROP KEY `idx_notes_user_pending_email`;
--   ALTER TABLE `notifications` DROP COLUMN `emailed_at`;
--
--   En orden inverso: primero el índice, que depende de la columna.
--
--   Revertir NO pierde datos de la aplicación: solo se descarta el registro de
--   qué se envió por correo. El efecto práctico es que todo vuelve a contar
--   como «no enviado», así que el siguiente envío podría repetir avisos ya
--   entregados. Conviene revertir solo si el correo aún no está en marcha.
-- =====================================================================
