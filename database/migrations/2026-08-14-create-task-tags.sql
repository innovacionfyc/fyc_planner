-- =====================================================================
-- Migración: crear tablas task_tags y task_tag_pivot
-- Fecha    : 2026-08-14
-- Fase     : Etiquetas — regularización del esquema
--
-- POR QUÉ EXISTE ESTA MIGRACIÓN
--   El código de etiquetas (public/tags/tag_action.php, la interfaz del
--   cajón en public/tasks/drawer.php y el filtro de public/boards/view.php)
--   lleva tiempo en el repositorio, pero las dos tablas que necesita se
--   crearon A MANO en producción, sin dejar migración. Resultado: en
--   producción las etiquetas funcionaban y en local no existían, y una
--   instalación limpia desde el volcado del esquema nacía sin ellas.
--
--   Este archivo cierra ese hueco. El DDL se ha copiado del esquema REAL
--   de producción (MariaDB 10.6), no reconstruido de memoria, para que
--   ambos entornos converjan exactamente en la misma definición.
--
-- IDEMPOTENTE
--   Usa CREATE TABLE IF NOT EXISTS. Ejecutarla sobre producción —donde las
--   tablas ya existen— no cambia nada ni da error. Ejecutarla sobre local
--   o sobre una instalación nueva las crea.
--
--   Ojo: IF NOT EXISTS solo comprueba el NOMBRE. Si una instalación tuviera
--   una tabla task_tags con otra forma, esta migración la daría por buena
--   sin tocarla. Comprobar la estructura antes de darla por aplicada.
--
-- Compatible con MySQL 8.0 (local) y MariaDB 10.6 (producción).
--   · Sin CHECK constraints ni índices funcionales.
--   · `bigint unsigned` sin ancho de visualización: MariaDB lo acepta y
--     MySQL 8 lo ignoraría de todos modos.
--   · `current_timestamp()` se escribe como CURRENT_TIMESTAMP, que ambos
--     motores entienden y normalizan a su propia forma.
--
-- Convenciones tomadas del esquema real del proyecto:
--   · ENGINE=InnoDB, utf8mb4 / utf8mb4_unicode_ci
--   · claves foráneas con el patrón <tabla>_ibfk_<n>
--   · índices idx_*
--
-- Reversión al final del archivo (comentada).
-- =====================================================================

-- ---------------------------------------------------------------------
-- 1) Catálogo de etiquetas, una lista por tablero
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `task_tags` (
  `id`         bigint unsigned NOT NULL AUTO_INCREMENT,
  `board_id`   bigint unsigned NOT NULL,
  `nombre`     varchar(60) NOT NULL,
  `color_hex`  varchar(12) NOT NULL DEFAULT '#9070e8',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_task_tags_board` (`board_id`),
  CONSTRAINT `task_tags_ibfk_1`
    FOREIGN KEY (`board_id`) REFERENCES `boards` (`id`)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 2) Relación N:N entre tareas y etiquetas
--
-- La clave primaria compuesta (task_id, tag_id) es imprescindible, no
-- decorativa: public/tags/tag_action.php asigna con INSERT IGNORE y
-- confía en ella para no duplicar la asociación. Sin esa clave, pulsar
-- dos veces la misma etiqueta crearía dos filas.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `task_tag_pivot` (
  `task_id` bigint unsigned NOT NULL,
  `tag_id`  bigint unsigned NOT NULL,
  PRIMARY KEY (`task_id`,`tag_id`),
  KEY `idx_ttp_tag` (`tag_id`),
  CONSTRAINT `ttp_ibfk_1`
    FOREIGN KEY (`task_id`) REFERENCES `tasks` (`id`)
    ON DELETE CASCADE,
  CONSTRAINT `ttp_ibfk_2`
    FOREIGN KEY (`tag_id`) REFERENCES `task_tags` (`id`)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================
-- NOTAS DE DISEÑO
--
-- board_id ON DELETE CASCADE
--   Al borrar un tablero desaparecen sus etiquetas, y con ellas —por la
--   segunda cascada, ttp_ibfk_2— sus asignaciones. No quedan huérfanos.
--
-- task_id ON DELETE CASCADE
--   Al borrar una tarea se van sus asignaciones, pero NO las etiquetas:
--   el catálogo pertenece al tablero, no a la tarea.
--
-- Las etiquetas no tocan el disco: son solo filas. A diferencia de los
-- adjuntos, aquí la cascada limpia por completo y no hace falta cron.
--
-- El índice idx_ttp_tag sirve al camino inverso (¿qué tareas llevan esta
-- etiqueta?), que es el que usa el filtro del tablero. La clave primaria
-- ya cubre el camino directo (¿qué etiquetas tiene esta tarea?).
-- =====================================================================

-- =====================================================================
-- REVERSIÓN (no ejecutar salvo que se quiera deshacer la migración)
--
--   Orden inverso obligatorio: task_tag_pivot referencia a task_tags,
--   así que soltarla primero. Al revés, la clave foránea lo impide.
--
--   DROP TABLE IF EXISTS `task_tag_pivot`;
--   DROP TABLE IF EXISTS `task_tags`;
--
-- Advertencia: revertir BORRA todas las etiquetas y sus asignaciones.
-- No hay archivos que limpiar aparte, pero el dato se pierde: respaldar
-- antes si esas filas importan.
-- =====================================================================
