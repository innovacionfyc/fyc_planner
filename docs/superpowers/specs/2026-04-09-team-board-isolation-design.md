# Aislamiento de tableros de equipo — Diseño aprobado
**Fecha:** 2026-04-09  
**Estado:** Aprobado por el usuario

---

## Objetivo

Hacer que `team_members` sea la fuente de verdad para acceso a tableros de equipo (`boards.team_id IS NOT NULL`), mientras `board_members` sigue siendo la fuente de verdad para tableros personales (`team_id IS NULL`). El super_admin tiene bypass total.

---

## Regla central de acceso

```
Si board.team_id IS NOT NULL (tablero de equipo):
  acceso ← usuario en team_members para ese equipo
         OR usuario es propietario en board_members (por si salió del equipo)
         OR super_admin

Si board.team_id IS NULL (tablero personal):
  acceso ← usuario en board_members [sin cambios]
         OR super_admin
```

---

## Cambios en `_perm.php`

### Nueva función `has_board_access(mysqli $conn, int $board_id, int $user_id): bool`

Centraliza la pregunta "¿puede este usuario ver/abrir este tablero?".

```
1. if super_admin → true
2. fetch board.team_id (si no existe → false)
3. if team_id IS NOT NULL:
     a. EXISTS team_members WHERE team_id=? AND user_id=?  → true
     b. EXISTS board_members WHERE board_id=? AND user_id=? AND rol='propietario' → true
     → false
4. else (personal):
     EXISTS board_members WHERE board_id=? AND user_id=? → result
```

El check del propietario (3b) garantiza consistencia con `can_manage_board()` — el creador del tablero no pierde acceso si es removido del equipo.

### Actualizar `can_edit_board(mysqli $conn, int $board_id, int $user_id): bool`

Actualmente: solo revisa `board_members.rol IN ('propietario','editor')`.

Nueva lógica:
```
1. if super_admin → true
2. fetch board.team_id
3. if team_id IS NOT NULL:
     EXISTS team_members WHERE team_id=? AND user_id=? (cualquier rol)
4. else:
     board_members.rol IN ('propietario','editor') [sin cambios]
```

`can_manage_board()` no cambia — ya funciona correctamente.  
`can_write_board()` hereda el fix automáticamente (llama a ambas funciones).

---

## Cambios en `boards/workspace.php`

### Filtro de tableros de equipo

```php
// ANTES:
$teamBaseWhere = "(b.team_id IS NOT NULL
    AND EXISTS (SELECT 1 FROM board_members bm
                WHERE bm.board_id=b.id AND bm.user_id={$user_id}))";

// DESPUÉS:
$isSuperAdmin = is_super_admin($conn);
if ($isSuperAdmin) {
    $teamBaseWhere = "(b.team_id IS NOT NULL)";
} else {
    $teamBaseWhere = "(b.team_id IS NOT NULL
        AND EXISTS (SELECT 1 FROM team_members tm
                    WHERE tm.team_id=b.team_id AND tm.user_id={$user_id}))";
}
```

Los tableros personales (`$personalBaseWhere`) no cambian.

---

## Cambios en `boards/view.php`

### Reemplazar JOIN por dos pasos

```php
// ANTES: JOIN board_members filtra acceso en la misma query
// DESPUÉS: fetch + validación separada con has_board_access()

$sql = "SELECT b.id, b.nombre, b.color_hex, b.team_id, t.nombre AS team_nombre
        FROM boards b LEFT JOIN teams t ON t.id=b.team_id
        WHERE b.id=? LIMIT 1";
// ...
if (!$board || !has_board_access($conn, $board_id, $_SESSION['user_id'])) {
    header('Location: index.php'); exit;
}
```

---

## Lo que NO cambia

| Componente | Razón |
|---|---|
| `board_members` tabla | Fuente de verdad para personales y propietario |
| `boards/create.php` | Sigue requiriendo `admin_equipo` para tableros de equipo |
| `boards/member_action.php` | `can_manage_board()` ya correcto |
| `boards/update/delete/archive/restore/duplicate` | Usan `can_manage_board()` — ya correcto |
| `tasks/*.php` | Usan `can_write_board()` — se corrige automáticamente |
| BD / schema | Sin cambios — `team_id` ya existe |

---

## Orden de implementación

1. `_perm.php` — agregar `has_board_access()` + actualizar `can_edit_board()`
2. `boards/workspace.php` — nuevo filtro + super_admin bypass
3. `boards/view.php` — reemplazar JOIN por dos pasos
