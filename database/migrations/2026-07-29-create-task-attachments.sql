-- =====================================================================
-- Migración: crear tabla task_attachments
-- Fecha    : 2026-07-29
-- Fase     : Adjuntos multimedia — Fase A (esquema y backend)
--
-- Compatible con MySQL 8.0 (local) y MariaDB 10.6 (producción).
-- No usa CHECK constraints ni índices funcionales, para no depender
-- de diferencias entre ambos motores.
--
-- Convenciones tomadas del esquema real del proyecto:
--   · ENGINE=InnoDB, utf8mb4 / utf8mb4_unicode_ci, ROW_FORMAT dinámico
--   · claves foráneas con el patrón <tabla>_ibfk_<referencia>
--     (igual que boards_ibfk_deleted_by)
--   · índices idx_* y únicos uq_*
--   · columnas JSON como en board_events.payload_json
--     (en MariaDB, JSON es alias de LONGTEXT: soportado igualmente)
--
-- El tablero NO se almacena aquí. Se resuelve con JOIN sobre tasks
-- para evitar duplicación e inconsistencias:
--     JOIN tasks t ON t.id = task_attachments.task_id  -> t.board_id
--
-- Reversión al final del archivo (comentada).
-- =====================================================================

CREATE TABLE `task_attachments` (
  `id`            bigint unsigned NOT NULL AUTO_INCREMENT,
  `task_id`       bigint unsigned NOT NULL,
  `uploaded_by`   bigint unsigned DEFAULT NULL,
  `kind`          enum('image','audio','video') COLLATE utf8mb4_unicode_ci NOT NULL,
  `original_name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `stored_path`   varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `mime`          varchar(120) COLLATE utf8mb4_unicode_ci NOT NULL,
  `size_bytes`    bigint unsigned NOT NULL,
  `meta_json`     json DEFAULT NULL,
  `created_at`    datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_task_attachments_stored_path` (`stored_path`),
  KEY `idx_task_attachments_task` (`task_id`,`id`),
  KEY `idx_task_attachments_uploaded_by` (`uploaded_by`),
  CONSTRAINT `task_attachments_ibfk_task`
    FOREIGN KEY (`task_id`) REFERENCES `tasks` (`id`)
    ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `task_attachments_ibfk_user`
    FOREIGN KEY (`uploaded_by`) REFERENCES `users` (`id`)
    ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================
-- NOTAS DE DISEÑO
--
-- stored_path
--   Ruta RELATIVA a storage/attachments/, con el formato AAAA/MM/<32 hex>.<ext>
--   Nunca se guarda una ruta absoluta del servidor.
--   El UNIQUE hace imposible una colisión de nombres aleatorios.
--
-- original_name
--   Nombre que puso el usuario, solo para mostrarlo y descargarlo.
--   Nunca se usa para construir rutas físicas.
--
-- mime
--   MIME REAL detectado con finfo en el momento de la subida,
--   no el que envía el navegador. Es el valor que se emite como
--   Content-Type al servir el archivo.
--
-- kind
--   Se deduce de la extensión ya validada contra la whitelist.
--   Sirve para elegir el reproductor en fases posteriores.
--
-- uploaded_by ON DELETE SET NULL
--   Mismo criterio que boards.deleted_by y tasks.assignee_id:
--   si el usuario desaparece, el adjunto sobrevive sin autor.
--
-- task_id ON DELETE CASCADE
--   Al borrar la tarea (o el tablero, que ya cascadea a tasks) la fila
--   desaparece sola. OJO: la cascada NO borra el archivo físico;
--   de eso se encargan los endpoints y, más adelante, el cron de huérfanos.
-- =====================================================================

-- =====================================================================
-- REVERSIÓN (no ejecutar salvo que se quiera deshacer la migración)
--
--   DROP TABLE IF EXISTS `task_attachments`;
--
-- Advertencia: eliminar la tabla NO borra los archivos de
-- storage/attachments/. Habría que limpiarlos aparte.
-- =====================================================================
