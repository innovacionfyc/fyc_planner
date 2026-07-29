-- =====================================================================
-- Migración incremental: enlaces externos y embeds en task_attachments
-- Fecha    : 2026-07-29
-- Fase     : Adjuntos multimedia — Fase D
-- Base     : 2026-07-29-create-task-attachments.sql (NO se modifica)
--
-- Objetivo
--   Permitir que una fila represente UNA de dos fuentes, nunca ambas:
--     A) Archivo físico  -> stored_path NOT NULL, external_url NULL
--     B) Enlace externo  -> stored_path NULL,     external_url NOT NULL
--
-- Compatibilidad
--   Probada en MySQL 8.0.30 (local). Pensada para MariaDB 10.6 (producción):
--   solo se usan ALTER estándar, sin CHECK ni índices funcionales.
--   El contrato "una fuente u otra" se valida en PHP (attach_validate_source),
--   precisamente porque no podemos depender de CHECK en MariaDB.
--
-- Conservación de datos
--   Todas las columnas pasan de NOT NULL a NULL: es una ampliación, nunca
--   una restricción. Ninguna fila existente se ve afectada ni se pierde.
--
--   El índice UNIQUE sobre stored_path se mantiene: tanto MySQL como MariaDB
--   permiten múltiples NULL en un índice único, así que los enlaces (con
--   stored_path NULL) no colisionan entre sí.
-- =====================================================================

-- 1) Ampliar el tipo de adjunto
ALTER TABLE `task_attachments`
  MODIFY COLUMN `kind`
    enum('image','audio','video','link','embed')
    COLLATE utf8mb4_unicode_ci NOT NULL;

-- 2) Los campos propios de un archivo pasan a ser opcionales:
--    un enlace no tiene ruta física, ni MIME, ni tamaño.
ALTER TABLE `task_attachments`
  MODIFY COLUMN `stored_path`   varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  MODIFY COLUMN `original_name` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  MODIFY COLUMN `mime`          varchar(120) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  MODIFY COLUMN `size_bytes`    bigint unsigned DEFAULT NULL;

-- 3) Campos propios de un enlace
--    external_url no se indexa: 2048 caracteres en utf8mb4 exceden el
--    tamaño máximo de clave y tampoco hace falta buscar por URL.
ALTER TABLE `task_attachments`
  ADD COLUMN `external_url` varchar(2048) COLLATE utf8mb4_unicode_ci DEFAULT NULL AFTER `size_bytes`,
  ADD COLUMN `provider`     varchar(40)   COLLATE utf8mb4_unicode_ci DEFAULT NULL AFTER `external_url`;

-- 4) Índice para poder listar por proveedor sin recorrer la tabla
ALTER TABLE `task_attachments`
  ADD KEY `idx_task_attachments_provider` (`provider`);

-- =====================================================================
-- NOTAS DE DISEÑO
--
-- provider
--   Solo 'youtube' o 'vimeo' por ahora, o NULL para enlaces normales.
--   Se guarda como varchar y no como enum para poder añadir proveedores
--   sin otra migración.
--
-- meta_json
--   Para embeds guarda { "video_id": "..." } ya validado.
--   Para enlaces normales guarda { "host": "ejemplo.com" }.
--   NUNCA guarda HTML ni datos traídos del sitio remoto.
--
-- external_url
--   Se guarda la URL saneada del usuario, pero NUNCA se usa como src de
--   un iframe. El src del embed se construye desde plantilla propia con
--   el video_id validado (ver attach_build_embed_url).
--
-- size_bytes
--   Pasa a NULL en enlaces. Para archivos sigue siendo obligatorio por
--   contrato de PHP, aunque la columna ya no lo imponga.
-- =====================================================================

-- =====================================================================
-- REVERSIÓN (no ejecutar salvo que se quiera deshacer la migración)
--
--   -- Antes de revertir hay que eliminar las filas de enlaces, porque
--   -- volver a NOT NULL fallaría con stored_path NULL:
--   -- DELETE FROM `task_attachments` WHERE `stored_path` IS NULL;
--
--   ALTER TABLE `task_attachments` DROP KEY `idx_task_attachments_provider`;
--   ALTER TABLE `task_attachments`
--     DROP COLUMN `provider`,
--     DROP COLUMN `external_url`;
--   ALTER TABLE `task_attachments`
--     MODIFY COLUMN `stored_path`   varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
--     MODIFY COLUMN `original_name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
--     MODIFY COLUMN `mime`          varchar(120) COLLATE utf8mb4_unicode_ci NOT NULL,
--     MODIFY COLUMN `size_bytes`    bigint unsigned NOT NULL;
--   ALTER TABLE `task_attachments`
--     MODIFY COLUMN `kind` enum('image','audio','video')
--       COLLATE utf8mb4_unicode_ci NOT NULL;
-- =====================================================================
