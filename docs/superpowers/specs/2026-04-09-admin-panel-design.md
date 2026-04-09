# Admin Panel — Diseño aprobado
**Fecha:** 2026-04-09  
**Estado:** Aprobado por el usuario

---

## Objetivo

Crear un panel administrativo centralizado integrado visualmente con el design system del workspace (dark/light mode, variables CSS, tipografía Sora/DM Sans, mismos componentes).

---

## Arquitectura

### Patrón: Shared Layout Partials + Dashboard independiente

- `admin/_layout_top.php` — emite DOCTYPE, `<head>`, `<header>` admin, apertura de `<main>`
- `admin/_layout_bottom.php` — cierra `<main>`, `</body>`, `</html>`, incluye theme-toggle JS compartido
- `admin/index.php` — dashboard central con stats y tarjetas de módulos
- Cada página admin incluye los dos partials envolviendo su contenido específico

### Convención de uso en cada página admin

```php
$pageTitle  = 'Título de la página';
$activePage = 'usuarios'; // 'index' | 'usuarios' | 'equipos'
require_once __DIR__ . '/_layout_top.php';
// ... contenido específico de la página ...
require_once __DIR__ . '/_layout_bottom.php';
```

---

## Control de acceso

### Función nueva: `can_see_admin_panel(mysqli $conn): bool`
Añadida en `public/_perm.php`.

Condición: `is_admin = 1` Y `rol IN ('super_admin', 'director', 'ti')`.

Los roles `coordinador` y `user` con `is_admin=1` no verán el panel.  
Las páginas admin siguen protegiéndose con `require_admin()` (is_admin=1), que es la barrera de acceso real.

---

## Header admin (`_layout_top.php`)

```
[F&C Planner] | Administración      [Usuarios] [Equipos]    [← Workspace] [🌙] [Salir]
```

- **Izquierda:** logo `F&C Planner` → separador → label `Administración`
- **Centro-derecha:** nav links dinámicos desde array. El link cuyo `id` coincide con `$activePage` recibe clase activa (subrayado color `--fyc-red`)
- **Extremo derecho:** `← Workspace` (→ `../boards/workspace.php`) → toggle tema → avatar + Salir
- Clase del contenedor: `fyc-header` (igual que workspace)
- Dark/light toggle: mismo JS inline que workspace

---

## Dashboard (`admin/index.php`)

### Stats bar (3 números)
Consultados en la BD al cargar la página:
- Total usuarios activos
- Usuarios pendientes de aprobación
- Total equipos

### Tarjetas de módulos (data-driven)
El array `$modules` en `index.php` define los módulos. Para agregar uno nuevo, solo se añade una entrada al array.

```php
$modules = [
    ['id' => 'usuarios',  'title' => 'Usuarios',  'icon' => '👥', 'desc' => '...', 'url' => 'users_pending.php', 'stat' => "$n usuarios", 'active' => true],
    ['id' => 'equipos',   'title' => 'Equipos',   'icon' => '🏢', 'desc' => '...', 'url' => 'teams.php',         'stat' => "$n equipos",  'active' => true],
    ['id' => 'tableros',  'title' => 'Tableros',  'icon' => '📋', 'desc' => 'Próximamente', 'url' => null,       'stat' => null,          'active' => false],
];
```

Las tarjetas usan variables CSS del design system: `--bg-surface`, `--border-accent`, `--fyc-red`, etc.  
Las tarjetas inactivas (`active: false`) se muestran con opacidad reducida y sin enlace.

---

## Acceso desde el workspace

**Archivo:** `public/boards/workspace.php`, línea ~242  
**Cambio:** reemplaza el botón `👥 Usuarios` → `⚙ Admin` apuntando a `admin/index.php`  
**Visibilidad:** `can_see_admin_panel($conn)` (solo roles administrativos reales)

---

## Archivos modificados

| Operación | Archivo |
|---|---|
| CREAR | `public/admin/_layout_top.php` |
| CREAR | `public/admin/_layout_bottom.php` |
| CREAR | `public/admin/index.php` |
| MODIFICAR | `public/_perm.php` — agregar `can_see_admin_panel()` |
| MODIFICAR | `public/boards/workspace.php` — botón Admin en header |
| MODIFICAR | `public/admin/users_pending.php` — usar partials, adoptar design system |
| MODIFICAR | `public/admin/teams.php` — usar partials, adoptar design system |

`user_action.php` y `team_action.php` no se tocan (endpoints de acción pura, sin HTML).

---

## Orden de implementación

1. Crear `_layout_top.php` y `_layout_bottom.php`
2. Crear `admin/index.php`
3. Agregar `can_see_admin_panel()` en `_perm.php` + actualizar `workspace.php`
4. Refactorizar `users_pending.php`
5. Refactorizar `teams.php`

---

## Fuera de alcance (este bloque)

- Lógica de equipos ↔ tableros
- Gestión de tableros desde admin
- Cualquier cambio en endpoints de acción PHP
