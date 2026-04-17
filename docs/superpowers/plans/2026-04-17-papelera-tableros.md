# Papelera de Tableros — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Implementar soft delete de tableros con papelera, restauración, eliminación definitiva y purge automático a 30 días.

**Architecture:** Se agrega `deleted_at` + `deleted_by` a la tabla `boards`. El endpoint `delete.php` pasa de `DELETE` a `UPDATE`. Las queries de `workspace.php` excluyen tableros eliminados con `b.deleted_at IS NULL`. Tres archivos nuevos manejan la UI de papelera, restauración y eliminación definitiva. Un script CLI en `cron/` se configura en Plesk para purge diario.

**Tech Stack:** PHP 8+, MySQLi, MySQL 8, Tailwind CSS v4, HTML/CSS vanilla. Sin framework, sin ORM, sin test suite automatizado. Verificación siempre manual en navegador + terminal MySQL.

**Spec de referencia:** `docs/superpowers/specs/2026-04-17-papelera-tableros-design.md`

---

## Mapa de archivos

| Archivo | Acción | Responsabilidad |
|---|---|---|
| *(MySQL)* | Migrar | Columnas `deleted_at`, `deleted_by` en `boards` |
| `schema_fyc_planner_db.sql` | Modificar | Reflejar la migración en el schema exportado |
| `public/boards/delete.php` | Modificar | Cambiar hard DELETE a soft delete (UPDATE) |
| `public/boards/workspace.php` | Modificar | Filtrar tableros eliminados; enlace de papelera en sidebar |
| `public/boards/view.php` | Modificar | Guard: tableros en papelera no visualizables en embed |
| `public/_perm.php` | Modificar | Añadir función `can_purge_board()` |
| `public/boards/trash.php` | Crear | Vista de papelera con lista, días restantes y acciones |
| `public/boards/trash_restore.php` | Crear | Endpoint POST de restauración |
| `public/boards/trash_purge.php` | Crear | Endpoint POST de eliminación definitiva manual |
| `cron/purge_trash.php` | Crear | Script CLI de purge automático con LIMIT 100/batch |

---

## Task 1: Migración de base de datos

**Archivos:** MySQL directo (Laragon HeidiSQL o CLI) + `schema_fyc_planner_db.sql`
**Objetivo:** Añadir `deleted_at` y `deleted_by` a `boards` sin romper nada existente.
**Riesgo:** Bajo. Son columnas nullable; ninguna query existente se rompe porque `NULL` no activa ningún filtro nuevo todavía.

- [ ] **Paso 1.1: Ejecutar la migración en la base de datos local**

Abrir HeidiSQL (o MySQL CLI en Laragon) y ejecutar:

```sql
ALTER TABLE `boards`
  ADD COLUMN `deleted_at` DATETIME NULL DEFAULT NULL AFTER `updated_at`,
  ADD COLUMN `deleted_by` BIGINT UNSIGNED NULL DEFAULT NULL AFTER `deleted_at`,
  ADD INDEX  `idx_boards_deleted` (`deleted_at`),
  ADD CONSTRAINT `boards_ibfk_deleted_by`
      FOREIGN KEY (`deleted_by`) REFERENCES `users` (`id`)
      ON DELETE SET NULL ON UPDATE CASCADE;
```

- [ ] **Paso 1.2: Verificar que las columnas existen**

```sql
SHOW COLUMNS FROM boards;
```

Resultado esperado: dos filas nuevas — `deleted_at` (datetime, YES, NULL) y `deleted_by` (bigint unsigned, YES, NULL). El índice `idx_boards_deleted` aparece en `SHOW INDEX FROM boards`.

- [ ] **Paso 1.3: Verificar que los tableros existentes no se ven afectados**

```sql
SELECT id, nombre, deleted_at, deleted_by FROM boards LIMIT 5;
```

Resultado esperado: `deleted_at` y `deleted_by` son `NULL` en todos los registros.

- [ ] **Paso 1.4: Actualizar `schema_fyc_planner_db.sql`**

Exportar el schema actualizado desde HeidiSQL (`Tools > Export Database as SQL > Structure only`) y reemplazar el archivo. O editar manualmente el bloque `CREATE TABLE boards` para añadir las dos columnas después de `updated_at`:

```sql
  `deleted_at` datetime DEFAULT NULL,
  `deleted_by` bigint unsigned DEFAULT NULL,
```

Y añadir en la sección de índices y constraints del bloque:
```sql
  KEY `idx_boards_deleted` (`deleted_at`),
  CONSTRAINT `boards_ibfk_deleted_by` FOREIGN KEY (`deleted_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
```

- [ ] **Paso 1.5: Commit**

```bash
git add schema_fyc_planner_db.sql
git commit -m "feat: add deleted_at and deleted_by columns to boards"
```

---

## Task 2: Cambiar `delete.php` a soft delete

**Archivos:** `public/boards/delete.php`
**Objetivo:** Reemplazar el `DELETE` destructivo por un `UPDATE` que registra quién y cuándo eliminó.
**Riesgo:** Bajo. El flujo de permisos (CSRF + `can_manage_board`) no cambia. El único cambio es la query SQL y el mensaje flash.

- [ ] **Paso 2.1: Modificar la transacción en `delete.php`**

Localizar el bloque `$conn->begin_transaction()` (líneas 41-54) y reemplazarlo completamente:

```php
$conn->begin_transaction();
try {
    $del = $conn->prepare(
        "UPDATE boards SET deleted_at = NOW(), deleted_by = ? WHERE id = ? LIMIT 1"
    );
    $del->bind_param('ii', $userId, $boardId);
    $del->execute();

    $conn->commit();
    $_SESSION['flash'] = [
        'type' => 'ok',
        'msg'  => 'Tablero movido a la papelera. Se eliminará definitivamente en 30 días.'
    ];
} catch (Throwable $e) {
    $conn->rollback();
    $_SESSION['flash'] = ['type' => 'err', 'msg' => 'No se pudo mover el tablero a la papelera.'];
}
```

- [ ] **Paso 2.2: Verificar manualmente**

1. Ir a `http://localhost/fyc_planner/public/boards/workspace.php`.
2. Usar el menú de acciones de cualquier tablero y hacer clic en **Eliminar**.
3. Confirmar que aparece el flash: _"Tablero movido a la papelera. Se eliminará definitivamente en 30 días."_
4. Confirmar que el tablero ya no aparece en el sidebar ni en el panel principal.
5. En HeidiSQL verificar:
   ```sql
   SELECT id, nombre, deleted_at, deleted_by FROM boards WHERE deleted_at IS NOT NULL;
   ```
   El tablero eliminado debe aparecer con `deleted_at` y `deleted_by` poblados.

- [ ] **Paso 2.3: Commit**

```bash
git add public/boards/delete.php
git commit -m "feat: soft delete boards instead of hard DELETE"
```

---

## Task 3: Filtros en `workspace.php` para excluir tableros en papelera

**Archivos:** `public/boards/workspace.php`
**Objetivo:** Los tableros con `deleted_at IS NOT NULL` no aparecen en el sidebar ni en el panel. Añadir enlace de papelera al footer del sidebar con badge numérico.
**Riesgo:** Medio. `workspace.php` es el archivo más grande del proyecto (~1200 líneas). Los cambios son quirúrgicos: modificar `fetchBoards()` y añadir HTML al sidebar. Leer el archivo completo antes de editar.

- [ ] **Paso 3.1: Detectar la columna `deleted_at` tras el bloque SHOW COLUMNS existente**

En `workspace.php`, el bloque `SHOW COLUMNS` existe alrededor de las líneas 30-43 y llena `$cols` como array. Añadir inmediatamente después de ese bloque:

```php
$hasDeletedAt = in_array('deleted_at', $cols, true);
$wNotDeleted  = $hasDeletedAt ? 'b.deleted_at IS NULL' : '1';
```

- [ ] **Paso 3.2: Modificar la firma de `fetchBoards()` para aceptar el filtro de papelera**

Localizar la función `fetchBoards` (~línea 70). Cambiar la firma y la query:

```php
function fetchBoards($conn, $whereBase, $whereArchive, $user_id, $whereNotDeleted = '1')
{
    $sql = "SELECT b.id, b.nombre, b.color_hex, b.team_id,
                   COALESCE(bm.rol,'')  AS my_role,
                   COALESCE(tm.rol,'')  AS team_role
            FROM boards b
            LEFT JOIN board_members bm ON bm.board_id=b.id AND bm.user_id={$user_id}
            LEFT JOIN team_members  tm ON tm.team_id=b.team_id AND tm.user_id={$user_id}
            WHERE {$whereBase} AND {$whereArchive} AND {$whereNotDeleted}
            ORDER BY b.created_at DESC, b.id DESC";
    $out = [];
    $res = $conn->query($sql);
    if ($res) {
        while ($r = $res->fetch_assoc())
            $out[] = $r;
        $res->free();
    }
    return $out;
}
```

- [ ] **Paso 3.3: Actualizar las 4 llamadas a `fetchBoards()`**

Localizar las líneas ~89-92 donde se llama a `fetchBoards` cuatro veces. Añadir `$wNotDeleted` como quinto argumento en todas:

```php
$personalActive   = fetchBoards($conn, $personalBaseWhere, $wActive,   $user_id, $wNotDeleted);
$personalArchived = fetchBoards($conn, $personalBaseWhere, $wArchived,  $user_id, $wNotDeleted);
$teamActive       = fetchBoards($conn, $teamBaseWhere,     $wActive,    $user_id, $wNotDeleted);
$teamArchived     = fetchBoards($conn, $teamBaseWhere,     $wArchived,  $user_id, $wNotDeleted);
```

- [ ] **Paso 3.4: Calcular el contador de papelera para el badge**

Añadir después de las 4 llamadas a `fetchBoards()` (~línea 93):

```php
$trashCount = 0;
if ($hasDeletedAt) {
    $trashSql = "SELECT COUNT(*) FROM boards b
                 WHERE b.deleted_at IS NOT NULL
                   AND ({$personalBaseWhere} OR {$teamBaseWhere})";
    $trashRes = $conn->query($trashSql);
    if ($trashRes) {
        $trashCount = (int) $trashRes->fetch_row()[0];
        $trashRes->free();
    }
}
```

- [ ] **Paso 3.5: Añadir enlace de papelera al footer del sidebar**

Localizar el cierre `</div><!-- /sidebarScroll -->` (~línea 660) y `</aside>` (~línea 661). Insertar entre ambos:

```php
            <!-- Footer sidebar: enlace a papelera -->
            <div style="padding:8px 12px;border-top:1px solid var(--border-main);flex-shrink:0;">
                <a href="./trash.php"
                   style="display:flex;align-items:center;gap:7px;padding:6px 8px;border-radius:6px;
                          text-decoration:none;color:var(--text-muted);font-size:12px;
                          transition:background 0.15s;"
                   onmouseover="this.style.background='var(--bg-hover)'"
                   onmouseout="this.style.background=''">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                         style="width:13px;height:13px;flex-shrink:0;">
                        <polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14H6L5 6"/>
                        <path d="M10 11v6"/><path d="M14 11v6"/>
                        <path d="M9 6V4h6v2"/>
                    </svg>
                    <span>Papelera</span>
                    <?php if ($trashCount > 0): ?>
                        <span style="margin-left:auto;background:var(--fyc-red);color:#fff;
                                     font-size:10px;font-weight:700;border-radius:999px;
                                     padding:1px 6px;line-height:16px;">
                            <?= $trashCount ?>
                        </span>
                    <?php endif; ?>
                </a>
            </div>
```

- [ ] **Paso 3.6: Verificar manualmente**

1. Recargar `workspace.php`. El tablero eliminado en el Task 2 **no debe aparecer** en el sidebar ni en ninguna sección.
2. Al pie del sidebar debe aparecer el enlace **Papelera** con el badge `1` (o el número de tableros en papelera).
3. En HeidiSQL, ejecutar `UPDATE boards SET deleted_at = NULL, deleted_by = NULL WHERE deleted_at IS NOT NULL;` para restaurar temporalmente y verificar que el tablero vuelve al sidebar y el badge desaparece.
4. Volver a eliminarlo con delete.php para dejar el estado como en Task 2.

- [ ] **Paso 3.7: Commit**

```bash
git add public/boards/workspace.php
git commit -m "feat: exclude trashed boards from workspace; add trash link to sidebar"
```

---

## Task 4: Guard en `boards/view.php` contra tableros eliminados

**Archivos:** `public/boards/view.php`
**Objetivo:** Si alguien accede a `view.php?embed=1&id=X` con un tablero en papelera, recibe un flash de error y es redirigido a `workspace.php`.
**Riesgo:** Bajo. El guard se añade después del fetch existente; no toca la lógica de render.

- [ ] **Paso 4.1: Añadir `deleted_at` a la query de obtención del tablero**

Localizar la query en `view.php` (~línea 30):

```php
$sql = "SELECT b.id, b.nombre, b.color_hex, b.team_id, t.nombre AS team_nombre
        FROM boards b LEFT JOIN teams t ON t.id = b.team_id
        WHERE b.id = ? LIMIT 1";
```

Cambiarla a:

```php
$sql = "SELECT b.id, b.nombre, b.color_hex, b.team_id, b.deleted_at,
               t.nombre AS team_nombre
        FROM boards b LEFT JOIN teams t ON t.id = b.team_id
        WHERE b.id = ? LIMIT 1";
```

- [ ] **Paso 4.2: Añadir el guard después de que `$board` es obtenido**

Localizar la línea donde se valida acceso (~línea 40):

```php
if (!$board || !has_board_access($conn, $board_id, (int)$_SESSION['user_id'])) {
    header('Location: index.php');
    exit;
}
```

Añadir el guard de papelera **antes** de esa validación:

```php
// Guard: tablero en papelera
if ($board && !empty($board['deleted_at'])) {
    $_SESSION['flash'] = [
        'type' => 'err',
        'msg'  => 'Este tablero está en la papelera y no puede visualizarse.'
    ];
    header('Location: ./workspace.php');
    exit;
}
```

- [ ] **Paso 4.3: Verificar manualmente**

Con el tablero eliminado (de los tasks anteriores), acceder directamente a:
`http://localhost/fyc_planner/public/boards/view.php?embed=1&id=<ID_DEL_TABLERO_ELIMINADO>`

Resultado esperado: redirección a `workspace.php` con flash de error _"Este tablero está en la papelera y no puede visualizarse."_

- [ ] **Paso 4.4: Commit**

```bash
git add public/boards/view.php
git commit -m "feat: guard view.php against accessing trashed boards"
```

---

## Task 5: Nueva vista `boards/trash.php`

**Archivos:** `public/_perm.php` (modificar primero), `public/boards/trash.php` (nuevo)
**Objetivo:** Página standalone que lista los tableros en papelera accesibles por el usuario, con días restantes y botones de acción. La vista usa `can_purge_board()` para decidir si mostrar el botón de eliminación definitiva — por eso esa función se define aquí, antes de crear el archivo.
**Riesgo:** Bajo. Archivo nuevo, sin impacto en páginas existentes.

- [ ] **Paso 5.0: Añadir `can_purge_board()` a `_perm.php`** *(prerequisito de trash.php)*

Añadir al final de `public/_perm.php`, antes del cierre de archivo:

```php
/**
 * ¿Puede el usuario eliminar definitivamente un tablero de la papelera?
 * Solo propietario del tablero (rol='propietario' en board_members) o super_admin.
 * admin_equipo puede restaurar pero NO purgar.
 */
function can_purge_board(mysqli $conn, int $board_id, int $user_id): bool
{
    if ($board_id <= 0 || $user_id <= 0) return false;
    if (is_super_admin($conn)) return true;

    $q = $conn->prepare(
        "SELECT rol FROM board_members WHERE board_id = ? AND user_id = ? LIMIT 1"
    );
    if (!$q) return false;
    $q->bind_param('ii', $board_id, $user_id);
    $q->execute();
    $rol = null;
    $q->bind_result($rol);
    $found = $q->fetch();
    $q->close();

    return $found && $rol === 'propietario';
}
```

Verificar que no hay errores de sintaxis: abrir cualquier página del proyecto; si carga sin fatal error, `_perm.php` está bien.

- [ ] **Paso 5.1: Crear `public/boards/trash.php`**

```php
<?php
require_once __DIR__ . '/../_auth.php';
require_login();
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../_perm.php';

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

$userId      = (int)($_SESSION['user_id'] ?? 0);
$isSuperAdmin = is_super_admin($conn);

if (empty($_SESSION['csrf']))
    $_SESSION['csrf'] = bin2hex(random_bytes(32));

// Detectar columna deleted_at (resiliencia de schema)
$cols = [];
$rc = $conn->query("SHOW COLUMNS FROM boards");
while ($rc && ($c = $rc->fetch_assoc())) $cols[$c['Field']] = true;

if (!isset($cols['deleted_at'])) {
    $_SESSION['flash'] = ['type' => 'err', 'msg' => 'La papelera aún no está disponible.'];
    header('Location: ./workspace.php');
    exit;
}

// Construir condición de acceso (misma lógica que workspace.php)
$hasCreatedBy     = isset($cols['created_by']);
$personalWhereMember = "EXISTS (SELECT 1 FROM board_members bm WHERE bm.board_id=b.id AND bm.user_id={$userId})";
$creatorClause    = $hasCreatedBy ? " OR b.created_by={$userId}" : "";
$personalBaseWhere = "(b.team_id IS NULL AND ({$personalWhereMember}{$creatorClause}))";

if ($isSuperAdmin) {
    $accessWhere = "1=1";
} else {
    $teamBaseWhere = "(b.team_id IS NOT NULL AND EXISTS (
        SELECT 1 FROM team_members tm WHERE tm.team_id=b.team_id AND tm.user_id={$userId}
    ))";
    $accessWhere = "({$personalBaseWhere} OR {$teamBaseWhere})";
}

$sql = "SELECT b.id, b.nombre, b.color_hex, b.deleted_at, b.deleted_by,
               u.nombre AS deleted_by_name,
               GREATEST(0, 30 - TIMESTAMPDIFF(DAY, b.deleted_at, NOW())) AS days_remaining
        FROM boards b
        LEFT JOIN users u ON u.id = b.deleted_by
        WHERE b.deleted_at IS NOT NULL
          AND {$accessWhere}
        ORDER BY b.deleted_at DESC";

$boards = [];
$res = $conn->query($sql);
if ($res) {
    while ($r = $res->fetch_assoc()) $boards[] = $r;
    $res->free();
}

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Papelera — FYC Planner</title>
    <link rel="stylesheet" href="/fyc_planner/public/assets/app.css">
    <style>
        body { background: var(--bg-main); color: var(--text-primary); font-family: var(--font-body, sans-serif); }
        .trash-wrap { max-width: 760px; margin: 40px auto; padding: 0 16px; }
        .trash-header { display: flex; align-items: center; gap: 12px; margin-bottom: 28px; }
        .trash-header h1 { font-size: 20px; font-weight: 700; margin: 0; }
        .trash-back { font-size: 12px; color: var(--text-muted); text-decoration: none; }
        .trash-back:hover { color: var(--text-primary); }
        .trash-empty { color: var(--text-ghost); font-size: 14px; text-align: center; padding: 60px 0; }
        .trash-card { background: var(--bg-surface); border: 1px solid var(--border-main);
                      border-radius: 10px; padding: 14px 16px; margin-bottom: 10px;
                      display: flex; align-items: center; gap: 14px; }
        .trash-chip { width: 10px; height: 10px; border-radius: 50%; flex-shrink: 0; }
        .trash-info { flex: 1; min-width: 0; }
        .trash-name { font-size: 14px; font-weight: 600; white-space: nowrap;
                      overflow: hidden; text-overflow: ellipsis; }
        .trash-meta { font-size: 11px; color: var(--text-muted); margin-top: 3px; }
        .trash-days { font-size: 11px; font-weight: 600; margin-top: 2px; }
        .trash-days.urgent { color: var(--fyc-red); }
        .trash-actions { display: flex; gap: 6px; flex-shrink: 0; }
        .btn-sm { font-size: 11px; padding: 5px 10px; border-radius: 6px; border: none;
                  cursor: pointer; font-weight: 600; transition: opacity 0.15s; }
        .btn-sm:hover { opacity: 0.8; }
        .btn-restore { background: var(--bg-hover); color: var(--text-primary);
                       border: 1px solid var(--border-accent); }
        .btn-purge   { background: var(--fyc-red); color: #fff; }
        .flash-ok  { background: #d4edda; color: #155724; border: 1px solid #c3e6cb;
                     padding: 10px 14px; border-radius: 6px; margin-bottom: 16px; font-size: 13px; }
        .flash-err { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb;
                     padding: 10px 14px; border-radius: 6px; margin-bottom: 16px; font-size: 13px; }

        /* Modal de confirmación */
        #purgeModal { display:none; position:fixed; inset:0; background:rgba(0,0,0,0.5);
                      z-index:1000; align-items:center; justify-content:center; }
        #purgeModal.open { display:flex; }
        .modal-box { background:var(--bg-surface); border-radius:10px; padding:24px;
                     max-width:400px; width:100%; border:1px solid var(--border-main); }
        .modal-box h2 { font-size:16px; font-weight:700; margin:0 0 8px; }
        .modal-box p  { font-size:13px; color:var(--text-muted); margin:0 0 20px; }
        .modal-actions { display:flex; gap:8px; justify-content:flex-end; }
    </style>
</head>
<body>
<div class="trash-wrap">
    <div class="trash-header">
        <a href="./workspace.php" class="trash-back">← Volver al workspace</a>
        <h1>Papelera</h1>
        <span style="font-size:12px;color:var(--text-ghost);"><?= count($boards) ?> tablero<?= count($boards) !== 1 ? 's' : '' ?></span>
    </div>

    <?php if ($flash): ?>
        <div class="flash-<?= h($flash['type'] === 'ok' ? 'ok' : 'err') ?>">
            <?= h($flash['msg']) ?>
        </div>
    <?php endif; ?>

    <?php if (empty($boards)): ?>
        <div class="trash-empty">La papelera está vacía.</div>
    <?php else: ?>
        <?php foreach ($boards as $b):
            $color = h($b['color_hex'] ?: '#d32f57');
            $days  = (int)$b['days_remaining'];
            $urgent = $days <= 7;
            $canPurge = can_purge_board($conn, (int)$b['id'], $userId);
        ?>
        <div class="trash-card">
            <div class="trash-chip" style="background:<?= $color ?>;"></div>
            <div class="trash-info">
                <div class="trash-name"><?= h($b['nombre']) ?></div>
                <div class="trash-meta">
                    Eliminado el <?= h(date('d/m/Y H:i', strtotime($b['deleted_at']))) ?>
                    <?php if ($b['deleted_by_name']): ?>
                        por <?= h($b['deleted_by_name']) ?>
                    <?php endif; ?>
                </div>
                <div class="trash-days<?= $urgent ? ' urgent' : '' ?>">
                    <?php if ($days <= 0): ?>
                        Pendiente de purge automático
                    <?php else: ?>
                        Se elimina definitivamente en <strong><?= $days ?> día<?= $days !== 1 ? 's' : '' ?></strong>
                    <?php endif; ?>
                </div>
            </div>
            <div class="trash-actions">
                <!-- Restaurar: propietario + admin_equipo + super_admin -->
                <form method="POST" action="./trash_restore.php">
                    <input type="hidden" name="csrf"     value="<?= h($_SESSION['csrf']) ?>">
                    <input type="hidden" name="board_id" value="<?= (int)$b['id'] ?>">
                    <button type="submit" class="btn-sm btn-restore">Restaurar</button>
                </form>

                <?php if ($canPurge): ?>
                <!-- Eliminar definitivamente: solo propietario + super_admin -->
                <button type="button" class="btn-sm btn-purge"
                        onclick="openPurge(<?= (int)$b['id'] ?>, '<?= h(addslashes($b['nombre'])) ?>')">
                    Eliminar definitivamente
                </button>
                <?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<!-- Modal confirmación de purge -->
<div id="purgeModal">
    <div class="modal-box">
        <h2>¿Eliminar definitivamente?</h2>
        <p id="purgeMsg">Esta acción no se puede deshacer. Se eliminarán el tablero y todas sus columnas, tareas y comentarios.</p>
        <div class="modal-actions">
            <button type="button" class="btn-sm btn-restore" onclick="closePurge()">Cancelar</button>
            <form method="POST" action="./trash_purge.php" id="purgeForm" style="display:inline;">
                <input type="hidden" name="csrf"     value="<?= h($_SESSION['csrf']) ?>">
                <input type="hidden" name="board_id" id="purgeId" value="">
                <button type="submit" class="btn-sm btn-purge">Sí, eliminar definitivamente</button>
            </form>
        </div>
    </div>
</div>

<script>
function openPurge(id, name) {
    document.getElementById('purgeId').value = id;
    document.getElementById('purgeMsg').textContent =
        'El tablero "' + name + '" y todas sus columnas, tareas y comentarios se eliminarán de forma permanente. Esta acción no se puede deshacer.';
    document.getElementById('purgeModal').classList.add('open');
}
function closePurge() {
    document.getElementById('purgeModal').classList.remove('open');
}
document.getElementById('purgeModal').addEventListener('click', function(e) {
    if (e.target === this) closePurge();
});
</script>
</body>
</html>
```

- [ ] **Paso 5.2: Verificar manualmente**

1. Navegar a `http://localhost/fyc_planner/public/boards/trash.php`.
2. Debe aparecer el tablero eliminado anteriormente con su nombre, fecha de eliminación, nombre de quien lo eliminó y los días restantes.
3. Si quedan > 7 días, los días aparecen en color normal. Si ≤ 7, en rojo.
4. El botón **Restaurar** está visible.
5. El botón **Eliminar definitivamente** está visible solo si el usuario es propietario o super_admin.
6. Al hacer clic en **Eliminar definitivamente** debe abrirse el modal de confirmación; al hacer clic en **Cancelar** debe cerrarse.

- [ ] **Paso 5.3: Commit**

```bash
git add public/boards/trash.php
git commit -m "feat: add trash.php board trash view with restore and purge actions"
```

---

## Task 6: Endpoint de restauración `trash_restore.php`

**Archivos:** `public/boards/trash_restore.php` (nuevo)
**Objetivo:** Restaurar un tablero: poner `deleted_at = NULL, deleted_by = NULL`. Accesible para `can_manage_board()`.
**Riesgo:** Bajo. La condición `AND deleted_at IS NOT NULL` evita restaurar tableros activos por manipulación.

- [ ] **Paso 6.1: Crear `public/boards/trash_restore.php`**

```php
<?php
require_once __DIR__ . '/../_auth.php';
require_login();
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../_perm.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ./trash.php');
    exit;
}

if (!isset($_POST['csrf'], $_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], $_POST['csrf'])) {
    $_SESSION['flash'] = ['type' => 'err', 'msg' => 'CSRF inválido.'];
    header('Location: ./trash.php');
    exit;
}

$userId  = (int)($_SESSION['user_id'] ?? 0);
$boardId = (int)($_POST['board_id'] ?? 0);

if ($boardId <= 0) {
    $_SESSION['flash'] = ['type' => 'err', 'msg' => 'Tablero inválido.'];
    header('Location: ./trash.php');
    exit;
}

// Verificar que el tablero está en papelera
$chk = $conn->prepare("SELECT id FROM boards WHERE id = ? AND deleted_at IS NOT NULL LIMIT 1");
$chk->bind_param('i', $boardId);
$chk->execute();
$exists = $chk->get_result()->fetch_assoc();
if (!$exists) {
    $_SESSION['flash'] = ['type' => 'err', 'msg' => 'El tablero no está en la papelera.'];
    header('Location: ./trash.php');
    exit;
}

// Permisos: propietario + admin_equipo + super_admin
if (!can_manage_board($conn, $boardId, $userId)) {
    $_SESSION['flash'] = ['type' => 'err', 'msg' => 'No tienes permisos para restaurar este tablero.'];
    header('Location: ./trash.php');
    exit;
}

$up = $conn->prepare(
    "UPDATE boards SET deleted_at = NULL, deleted_by = NULL WHERE id = ? AND deleted_at IS NOT NULL LIMIT 1"
);
$up->bind_param('i', $boardId);

if ($up->execute() && $up->affected_rows > 0) {
    $_SESSION['flash'] = ['type' => 'ok', 'msg' => 'Tablero restaurado correctamente.'];
} else {
    $_SESSION['flash'] = ['type' => 'err', 'msg' => 'No se pudo restaurar el tablero.'];
}

header('Location: ./trash.php');
exit;
```

- [ ] **Paso 6.2: Verificar manualmente**

1. En `trash.php`, hacer clic en **Restaurar** sobre el tablero eliminado.
2. Resultado: flash _"Tablero restaurado correctamente."_ y el tablero desaparece de la papelera.
3. Ir a `workspace.php`: el tablero debe aparecer de nuevo en el sidebar.
4. En HeidiSQL confirmar: `SELECT deleted_at, deleted_by FROM boards WHERE id = <ID>;` → ambos `NULL`.
5. Volver a eliminar el tablero (para dejar estado para los tasks siguientes).

- [ ] **Paso 6.3: Commit**

```bash
git add public/boards/trash_restore.php
git commit -m "feat: add trash_restore.php endpoint to restore boards from trash"
```

---

## Task 7: Endpoint `trash_purge.php`

**Archivos:** `public/boards/trash_purge.php` (nuevo)
**Objetivo:** Eliminación definitiva manual desde la UI. Solo propietario y super_admin. El CASCADE de FK borra automáticamente todo el contenido (columnas, tareas, comentarios, miembros, eventos). `can_purge_board()` ya existe en `_perm.php` desde el Task 5.
**Riesgo:** Alto — acción irreversible. La doble condición `AND deleted_at IS NOT NULL` en el DELETE es el seguro crítico. El modal de confirmación en trash.php (Task 5) ya protege el flujo UI.

- [ ] **Paso 7.2: Crear `public/boards/trash_purge.php`**

```php
<?php
require_once __DIR__ . '/../_auth.php';
require_login();
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../_perm.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ./trash.php');
    exit;
}

if (!isset($_POST['csrf'], $_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], $_POST['csrf'])) {
    $_SESSION['flash'] = ['type' => 'err', 'msg' => 'CSRF inválido.'];
    header('Location: ./trash.php');
    exit;
}

$userId  = (int)($_SESSION['user_id'] ?? 0);
$boardId = (int)($_POST['board_id'] ?? 0);

if ($boardId <= 0) {
    $_SESSION['flash'] = ['type' => 'err', 'msg' => 'Tablero inválido.'];
    header('Location: ./trash.php');
    exit;
}

// Verificar que el tablero está en papelera (seguro adicional)
$chk = $conn->prepare("SELECT id FROM boards WHERE id = ? AND deleted_at IS NOT NULL LIMIT 1");
$chk->bind_param('i', $boardId);
$chk->execute();
$exists = $chk->get_result()->fetch_assoc();
if (!$exists) {
    $_SESSION['flash'] = ['type' => 'err', 'msg' => 'El tablero no está en la papelera.'];
    header('Location: ./trash.php');
    exit;
}

// Permisos: solo propietario o super_admin
if (!can_purge_board($conn, $boardId, $userId)) {
    $_SESSION['flash'] = ['type' => 'err', 'msg' => 'No tienes permisos para eliminar definitivamente este tablero.'];
    header('Location: ./trash.php');
    exit;
}

$conn->begin_transaction();
try {
    // Condición AND deleted_at IS NOT NULL es el seguro absoluto:
    // nunca borra un tablero activo aunque board_id sea manipulado.
    $del = $conn->prepare(
        "DELETE FROM boards WHERE id = ? AND deleted_at IS NOT NULL LIMIT 1"
    );
    $del->bind_param('i', $boardId);
    $del->execute();

    if ($del->affected_rows === 0) {
        throw new RuntimeException('No se eliminó ningún registro.');
    }

    $conn->commit();
    $_SESSION['flash'] = ['type' => 'ok', 'msg' => 'Tablero eliminado definitivamente.'];
} catch (Throwable $e) {
    $conn->rollback();
    $_SESSION['flash'] = ['type' => 'err', 'msg' => 'No se pudo eliminar el tablero definitivamente.'];
}

header('Location: ./trash.php');
exit;
```

- [ ] **Paso 7.3: Verificar manualmente**

1. Con el tablero en papelera, abrir `trash.php` y hacer clic en **Eliminar definitivamente**.
2. Confirmar en el modal. Resultado: flash _"Tablero eliminado definitivamente."_ y el tablero desaparece de la papelera.
3. En HeidiSQL verificar que el tablero ya no existe: `SELECT * FROM boards WHERE id = <ID>;` → 0 filas.
4. Verificar que las tablas relacionadas también están limpias (CASCADE):
   ```sql
   SELECT COUNT(*) FROM columns  WHERE board_id = <ID>;
   SELECT COUNT(*) FROM tasks    WHERE board_id = <ID>;  -- si existe FK directa
   SELECT COUNT(*) FROM board_members WHERE board_id = <ID>;
   ```
   Todas deben devolver 0.
5. Intentar acceder a `trash_purge.php` con un `board_id` de un tablero **activo** (manipulando el formulario): debe devolver flash de error _"El tablero no está en la papelera."_

- [ ] **Paso 7.4: Commit**

```bash
git add public/boards/trash_purge.php
git commit -m "feat: add trash_purge.php for permanent board deletion"
```

---

## Task 8: Script CLI `cron/purge_trash.php`

**Archivos:** `cron/purge_trash.php` (nuevo)
**Objetivo:** Eliminar automáticamente tableros con `deleted_at` mayor a 30 días. Corre en batches de 100 para no bloquear la BD. Solo ejecutable desde CLI.
**Riesgo:** Bajo en producción si el cron está bien configurado. El script no tiene UI ni acepta input externo.

- [ ] **Paso 8.1: Crear directorio y script `cron/purge_trash.php`**

```php
<?php
/**
 * cron/purge_trash.php
 *
 * Elimina tableros en papelera con más de 30 días de antigüedad.
 * Usar en batches de 100 para no bloquear la base de datos.
 *
 * Uso:
 *   php /ruta/al/proyecto/cron/purge_trash.php
 *
 * Cron en Plesk (diario a las 3:00 AM):
 *   0 3 * * *  php /var/www/vhosts/dominio.com/fyc_planner/cron/purge_trash.php >> /var/log/fyc_purge_trash.log 2>&1
 *
 * En desarrollo (Laragon/Windows), ejecutar manualmente:
 *   php C:\laragon\www\fyc_planner\cron\purge_trash.php
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Este script solo puede ejecutarse desde CLI.\n");
}

require_once __DIR__ . '/../config/db.php';

$totalDeleted = 0;
$batch = 0;

do {
    $result = $conn->query(
        "DELETE FROM boards
         WHERE deleted_at IS NOT NULL
           AND deleted_at < NOW() - INTERVAL 30 DAY
         LIMIT 100"
    );

    if ($result === false) {
        fwrite(STDERR, date('Y-m-d H:i:s') . " — ERROR: " . $conn->error . "\n");
        exit(1);
    }

    $batch = $conn->affected_rows;
    $totalDeleted += $batch;
} while ($batch === 100);

echo date('Y-m-d H:i:s') . " — Purged {$totalDeleted} board(s) from trash.\n";
exit(0);
```

- [ ] **Paso 8.2: Verificar en desarrollo**

Crear un tablero de prueba, eliminarlo, y luego actualizar manualmente `deleted_at` para que tenga más de 30 días:

```sql
UPDATE boards SET deleted_at = NOW() - INTERVAL 31 DAY WHERE deleted_at IS NOT NULL;
```

Ejecutar desde terminal:
```bash
php C:\laragon\www\fyc_planner\cron\purge_trash.php
```

Salida esperada:
```
2026-04-17 03:00:00 — Purged 1 board(s) from trash.
```

Verificar en HeidiSQL que el tablero ya no existe.

- [ ] **Paso 8.3: Anotar configuración de Plesk**

En Plesk: `Scheduled Tasks > Add Task`:
- **Command:** `php /var/www/vhosts/<dominio>/fyc_planner/cron/purge_trash.php >> /var/log/fyc_purge_trash.log 2>&1`
- **Schedule:** `0 3 * * *` (diario a las 3:00 AM)

Esta configuración se realiza manualmente en Plesk tras el deploy. No hay código que modificar.

- [ ] **Paso 8.4: Commit**

```bash
git add cron/purge_trash.php
git commit -m "feat: add cron/purge_trash.php for automatic 30-day board purge"
```

---

## Task 9: Checklist de pruebas manuales (regresión completa)

**Objetivo:** Verificar que toda la feature funciona de extremo a extremo y que ninguna funcionalidad existente se rompió.
**Riesgo:** Solo lectura y verificación. Sin cambios de código.

### 9.1 — Flujo completo de papelera

- [ ] Crear un tablero nuevo desde el workspace.
- [ ] Eliminarlo con el botón de eliminar → flash _"movido a la papelera"_, desaparece del sidebar.
- [ ] Badge en el enlace "Papelera" del sidebar muestra `1`.
- [ ] Abrir `trash.php` → aparece el tablero con fecha, nombre del usuario que eliminó y días restantes.
- [ ] Hacer clic en **Restaurar** → flash _"restaurado"_, tablero desaparece de papelera.
- [ ] Badge desaparece del sidebar. Tablero vuelve al workspace.
- [ ] Eliminar el tablero de nuevo.
- [ ] En `trash.php`, hacer clic en **Eliminar definitivamente** → modal aparece con el nombre correcto.
- [ ] Cancelar el modal → no pasa nada.
- [ ] Confirmar en el modal → flash _"eliminado definitivamente"_, tablero desaparece.
- [ ] Badge desaparece del sidebar.

### 9.2 — Seguridad

- [ ] Acceder a `view.php?embed=1&id=<ID_EN_PAPELERA>` → redirige a workspace con flash de error.
- [ ] Como usuario `editor` o `lector` de un tablero en papelera: el botón **Eliminar definitivamente** NO debe aparecer en `trash.php` (solo lo ven propietario y super_admin).
- [ ] Como `admin_equipo`: el botón **Restaurar** aparece pero **Eliminar definitivamente** no.
- [ ] Enviar POST a `trash_purge.php` con el `board_id` de un tablero activo → flash _"El tablero no está en la papelera."_

### 9.3 — Regresión de funciones existentes

- [ ] Tableros activos siguen apareciendo en el workspace correctamente.
- [ ] Archivar y restaurar un tablero (`archive.php` / `restore.php`) sigue funcionando.
- [ ] Crear tablero, crear columna, crear tarea: flujo normal sin errores.
- [ ] El modal de miembros del tablero sigue funcionando.
- [ ] La paginación de favoritos en el sidebar (localStorage) sigue funcionando.
- [ ] Los tableros de equipo siguen agrupándose correctamente por equipo en el sidebar.

### 9.4 — Purge automático

- [ ] Eliminar un tablero.
- [ ] En HeidiSQL: `UPDATE boards SET deleted_at = NOW() - INTERVAL 31 DAY WHERE deleted_at IS NOT NULL;`
- [ ] Ejecutar: `php cron/purge_trash.php` → salida con el conteo correcto.
- [ ] Verificar en HeidiSQL que el tablero fue eliminado con sus datos relacionados.

---

## Notas de implementación

- **No hay test suite automatizado** en este proyecto. Toda verificación es manual en navegador + HeidiSQL.
- **Orden obligatorio:** Los tasks deben ejecutarse en orden (1→9). El Task 3 depende del Task 1. El Task 5 añade `can_purge_board()` a `_perm.php` antes de crear `trash.php` — no omitir el Paso 5.0.
- **`is_super_admin($conn)`** lee `$_SESSION['user_id']` internamente; no recibe el userId como parámetro.
- **Tailwind v4**: los estilos en `trash.php` usan variables CSS del sistema de diseño existente (`var(--bg-surface)`, `var(--fyc-red)`, etc.). No se necesita recompilar CSS para variables inline.
