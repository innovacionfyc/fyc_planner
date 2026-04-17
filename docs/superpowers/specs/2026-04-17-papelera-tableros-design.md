# Spec: Papelera de tableros

**Fecha:** 2026-04-17
**Alcance:** Solo tableros (`boards`). Tareas y otros objetos quedan fuera de este spec.
**Estado:** Aprobado — listo para plan de implementación.

---

## Objetivo

Al eliminar un tablero, en lugar de borrarlo definitivamente de la base de datos, se mueve a una papelera. Desde ahí puede restaurarse o eliminarse de forma definitiva. Los tableros en papelera se purgan automáticamente a los 30 días.

---

## Decisiones de diseño

| Decisión | Elegida | Razón |
|---|---|---|
| Estrategia de soft delete | Columna `deleted_at` en `boards` | Sigue el patrón ya existente con `archived_at`; mínimo cambio de schema |
| Tabla separada `board_trash` | Descartada | Rompe FKs con CASCADE; restaurar sería complejo y frágil |
| Limpieza lazy en cada request | Descartada | No determinista; añade latencia en cada carga |
| Limpieza automática | Script CLI + cron de Plesk | Determinista, sin impacto en requests de usuario |

---

## Cambios de base de datos

### Migración

```sql
ALTER TABLE `boards`
  ADD COLUMN `deleted_at`  DATETIME     NULL DEFAULT NULL AFTER `updated_at`,
  ADD COLUMN `deleted_by`  BIGINT UNSIGNED NULL DEFAULT NULL AFTER `deleted_at`,
  ADD INDEX  `idx_boards_deleted` (`deleted_at`),
  ADD CONSTRAINT `boards_ibfk_deleted_by`
      FOREIGN KEY (`deleted_by`) REFERENCES `users` (`id`)
      ON DELETE SET NULL ON UPDATE CASCADE;
```

### Columnas nuevas

| Columna | Tipo | Propósito |
|---|---|---|
| `deleted_at` | `DATETIME NULL` | Timestamp del soft delete. `NULL` = activo. |
| `deleted_by` | `BIGINT UNSIGNED NULL` | FK a `users.id`. Quién envió a papelera. `NULL` si el usuario fue eliminado. |

### Regla de filtrado universal

Todos los filtros de tableros activos usan exactamente:

```sql
b.deleted_at IS NULL
```

No se usa `OR deleted_at = ''` ni variantes. El campo es `DATETIME` — solo puede ser `NULL` o una fecha válida.

---

## Archivos modificados

### `public/boards/delete.php`

**Antes:** `DELETE FROM boards WHERE id = ? LIMIT 1`

**Después:**
```sql
UPDATE boards
SET deleted_at = NOW(), deleted_by = ?
WHERE id = ? LIMIT 1
```

- Misma validación CSRF existente.
- Misma verificación `can_manage_board()` existente.
- Flash de éxito cambia a: _"Tablero movido a la papelera. Se eliminará definitivamente en 30 días."_
- Redirección: sin cambios (`workspace.php` o `index.php` según `return`).

### `public/boards/workspace.php`

**Detección runtime** (igual que `archived_at`):

```php
$hasDeletedAt = in_array('deleted_at', $cols, true);
```

**Función `fetchBoards()`** — añadir condición al `WHERE`:

```sql
AND (b.deleted_at IS NULL)
```

Si la columna aún no existe (schema viejo), se omite la condición. Esto garantiza compatibilidad con el patrón de resiliencia del proyecto.

**Sidebar — enlace a papelera:**

Añadir al pie del sidebar un enlace a `trash.php`. Se muestra con un badge numérico si el usuario tiene tableros en su papelera:

```sql
SELECT COUNT(*)
FROM boards b
WHERE b.deleted_at IS NOT NULL
  AND (/* misma condición de membresía personal o de equipo */)
```

Si el contador es 0, el enlace se muestra igualmente (icono de papelera, badge oculto). Nunca se oculta por completo para que sea descubrible.

### `public/boards/view.php`

Añadir guard al inicio (después de obtener el `$boardId`):

```php
// Guard: tablero en papelera no es visualizable
$r = $conn->prepare("SELECT deleted_at FROM boards WHERE id = ? LIMIT 1");
$r->bind_param('i', $boardId);
$r->execute();
$brow = $r->get_result()->fetch_assoc();
if (!$brow || $brow['deleted_at'] !== null) {
    $_SESSION['flash'] = ['type' => 'err', 'msg' => 'Este tablero está en la papelera y no puede visualizarse.'];
    header('Location: ./workspace.php');
    exit;
}
```

---

## Archivos nuevos

### `public/boards/trash.php`

Página standalone de la papelera. No usa el layout del workspace.

**Acceso:** Cualquier usuario autenticado. Solo ve los tableros de su papelera (los que puede gestionar según `can_manage_board()`).

**Query principal:**

```sql
SELECT b.id, b.nombre, b.color_hex, b.deleted_at, b.deleted_by,
       u.nombre AS deleted_by_name,
       TIMESTAMPDIFF(DAY, b.deleted_at, NOW()) AS days_elapsed,
       (30 - TIMESTAMPDIFF(DAY, b.deleted_at, NOW())) AS days_remaining
FROM boards b
LEFT JOIN users u ON u.id = b.deleted_by
WHERE b.deleted_at IS NOT NULL
  AND (/* condición de membresía: personal o equipo */)
ORDER BY b.deleted_at DESC
```

**Contenido de la tarjeta de cada tablero:**
- Nombre del tablero (con chip de color)
- "Eliminado por: [nombre]" (si `deleted_by` tiene valor)
- "Eliminado el: [fecha]"
- "Se eliminará definitivamente en **X días**" (rojo si `days_remaining <= 7`)
- Botón **Restaurar** — visible para `can_manage_board()` (propietario + admin_equipo + super_admin)
- Botón **Eliminar definitivamente** — visible solo para propietario y super_admin

**Nota de UX:** El botón de eliminación definitiva abre un modal de confirmación antes de ejecutar. No hay acción directa.

### `public/boards/trash_restore.php`

Endpoint POST para restaurar un tablero desde la papelera.

```sql
UPDATE boards
SET deleted_at = NULL, deleted_by = NULL
WHERE id = ? AND deleted_at IS NOT NULL
LIMIT 1
```

- Permisos: `can_manage_board()` (propietario + admin_equipo + super_admin).
- La condición `AND deleted_at IS NOT NULL` protege contra manipulación: no puede "restaurar" un tablero activo.
- Flash: _"Tablero restaurado correctamente."_
- Redirige a `trash.php` (no a workspace, para que el usuario vea la papelera actualizada).

### `public/boards/trash_purge.php`

Endpoint POST para eliminación definitiva manual desde la UI.

```sql
DELETE FROM boards
WHERE id = ? AND deleted_at IS NOT NULL
LIMIT 1
```

- Permisos: función nueva `can_purge_board()` — solo propietario (`bm.rol = 'propietario'`) o super_admin.
- La condición `AND deleted_at IS NOT NULL` es un seguro absoluto: nunca borra un tablero activo.
- El CASCADE de las FKs existentes elimina automáticamente columnas, tareas, comentarios, miembros, eventos y presencia.
- Flash: _"Tablero eliminado definitivamente."_
- Redirige a `trash.php`.

**Función de permiso:**

```php
function can_purge_board(mysqli $conn, int $boardId, int $userId): bool {
    if (is_super_admin_user($conn, $userId)) return true;
    $st = $conn->prepare(
        "SELECT rol FROM board_members WHERE board_id = ? AND user_id = ? LIMIT 1"
    );
    $st->bind_param('ii', $boardId, $userId);
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    return ($row['rol'] ?? '') === 'propietario';
}
```

Esta función vive en `public/_perm.php` junto a las demás funciones de permisos.

### `cron/purge_trash.php`

Script CLI. Solo ejecutable desde línea de comandos (no desde navegador).

```php
<?php
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Solo ejecutable desde CLI.\n");
}

require_once __DIR__ . '/../config/db.php';

$deleted = 0;
do {
    $result = $conn->query(
        "DELETE FROM boards
         WHERE deleted_at IS NOT NULL
           AND deleted_at < NOW() - INTERVAL 30 DAY
         LIMIT 100"
    );
    $batch = $conn->affected_rows;
    $deleted += $batch;
} while ($batch === 100);

echo date('Y-m-d H:i:s') . " — Purged {$deleted} board(s) from trash.\n";
```

**Configuración en Plesk (producción):**

```
0 3 * * *  php /var/www/vhosts/dominio.com/fyc_planner/cron/purge_trash.php >> /var/log/fyc_purge_trash.log 2>&1
```

**En desarrollo (Laragon/Windows):** Ejecutar manualmente desde terminal cuando se necesite probar:

```
php C:\laragon\www\fyc_planner\cron\purge_trash.php
```

---

## Resumen de permisos

| Acción | Propietario | Admin equipo | Super admin |
|---|---|---|---|
| Ver papelera (propios tableros) | ✅ | ✅ | ✅ (todos) |
| Restaurar tablero | ✅ | ✅ | ✅ |
| Eliminar definitivamente (manual) | ✅ | ❌ | ✅ |
| Purge automático (cron) | — | — | ✅ (script) |

---

## Archivos afectados — resumen

| Archivo | Tipo de cambio |
|---|---|
| `schema_fyc_planner_db.sql` | Actualizar con la migración |
| `public/boards/delete.php` | Modificar: soft delete en lugar de DELETE |
| `public/boards/workspace.php` | Modificar: filtro `deleted_at IS NULL` + enlace papelera en sidebar |
| `public/boards/view.php` | Modificar: guard contra tableros eliminados |
| `public/_perm.php` | Modificar: añadir `can_purge_board()` |
| `public/boards/trash.php` | Nuevo: vista de papelera |
| `public/boards/trash_restore.php` | Nuevo: endpoint de restauración |
| `public/boards/trash_purge.php` | Nuevo: endpoint de eliminación definitiva manual |
| `cron/purge_trash.php` | Nuevo: script CLI de limpieza automática |

---

## Lo que queda fuera de este spec

- Papelera para tareas, columnas, comentarios u otros objetos.
- Carpetas para organizar tableros (spec separado).
- Notificaciones al propietario cuando un tablero se acerca a los 30 días.
- UI de papelera dentro del workspace (se diseñó como página separada por simplicidad y mantenibilidad).
