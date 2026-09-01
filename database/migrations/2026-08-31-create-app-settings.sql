-- =====================================================================
-- Migración incremental: tabla de ajustes de la aplicación
-- Fecha    : 2026-08-31
-- Fase     : Política de correo — arranque seguro
-- Base     : schema_fyc_planner_db.sql
--
-- Objetivo
--   Guardar el instante EXACTO en que la política de correo hizo su primera
--   pasada, para que las alertas creadas en esa pasada no se envíen nunca.
--
-- El problema que resuelve
--   EMAIL_POLICY_START (una constante) se pensó como corte de activación. No
--   sirve para eso: las alertas que crea la PRIMERA pasada tienen created_at
--   posterior a esa constante, así que entran en el filtro y se enviarían.
--   Medido en la base QA: 6 correos en un escenario mínimo, y 13 sobre la
--   copia de producción. La constante funciona como interruptor, no como
--   corte.
--
--   El corte tiene que tomarse DESPUÉS de crear las alertas iniciales, y por
--   tanto no puede vivir en una constante escrita a mano: hay que anotarlo en
--   el momento, automáticamente.
--
-- Por qué en la base y no en un archivo
--   La marca tiene que ser coherente con las notificaciones a las que se
--   refiere. Si se guardara en disco y alguien restaurara un respaldo de la
--   base anterior al arranque, el archivo diría «ya arrancado» mientras los
--   datos dirían lo contrario, y se enviarían alertas viejas. En la base, la
--   marca viaja con el respaldo y las dos cosas vuelven juntas al mismo punto.
--
-- Por qué una tabla y no otra columna
--   No hay ninguna fila a la que este dato pertenezca: no es de un usuario, ni
--   de un tablero, ni de una notificación. Es del sistema. Una tabla clave y
--   valor cubre este caso y los que vengan, sin inventar una columna huérfana
--   en una tabla que no tiene que ver.
--
-- Compatibilidad
--   CREATE TABLE IF NOT EXISTS: válido en MySQL 8 y en MariaDB 10.6, y hace la
--   migración repetible sin error.
--
-- Conservación de datos
--   Tabla nueva y vacía. No toca absolutamente nada de lo existente.
-- =====================================================================

CREATE TABLE IF NOT EXISTS `app_settings` (
  `clave`      varchar(64)  COLLATE utf8mb4_unicode_ci NOT NULL,
  `valor`      varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`clave`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Claves que usa el módulo de correo
--
--   email_bootstrap_at
--     Instante de la primera pasada con la política activa. Las alertas con
--     created_at <= este valor NO se envían nunca: son el retrato del estado
--     que ya existía al encender el sistema.
--
--     La escribe el propio cron, una sola vez. No hay que ponerla a mano, y
--     por eso reiniciar el cron no vuelve a arrancar el proceso.
-- ---------------------------------------------------------------------

-- Verificación
SELECT clave, valor, updated_at FROM app_settings ORDER BY clave;
-- Esperado tras aplicar: 0 filas. La marca aparece en la primera pasada real.

-- =====================================================================
-- REVERSIÓN
--
--   DROP TABLE IF EXISTS `app_settings`;
--
--   Perder la marca hace que la siguiente pasada se considere un arranque:
--   creará alertas y no enviará nada. Es el fallo seguro que se buscaba, pero
--   supone perder un día de avisos.
-- =====================================================================
