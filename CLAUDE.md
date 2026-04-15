# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Development Environment

This project runs on **Laragon** (local WAMP stack). The app is served at `http://localhost/fyc_planner/public/`.

**Database:** MySQL via Laragon. Connection is configured in `config/db.php`. The database is named `fyc_planner_db`. To recreate it, import `schema_fyc_planner_db.sql`.

## CSS / Frontend Build

Tailwind CSS v4 is used. Source entry point is `assets/app.css`; compiled output goes to `public/assets/app.css`.

```bash
# Watch mode during development
npm run dev

# Production build (minified)
npm run build
```

There is no test suite and no linter configured.

## Architecture Overview

### Request Routing
There is no framework or router. Each `.php` file in `public/` is a direct URL endpoint. Feature areas are grouped into subdirectories: `boards/`, `tasks/`, `columns/`, `comments/`, `notifications/`, `tags/`, `admin/`.

### Shared PHP Includes
- `public/_auth.php` — starts the session, provides `require_login()`, `is_admin()`, `require_admin()`. Must be `require_once`'d at the top of every protected page.
- `public/_perm.php` — finer-grained permission helpers: `is_super_admin()`, `is_admin_user()`.
- `public/_i18n.php` — label translation helpers for roles, priorities, etc. (Spanish UI).
- `config/db.php` — creates the `$conn` MySQLi connection (global). Always already included via `_auth.php`.

Every protected page follows this pattern:
```php
require_once __DIR__ . '/../_auth.php';
require_login();
require_once __DIR__ . '/../../config/db.php';
```

### Schema Resilience Pattern
Several pages perform `SHOW COLUMNS FROM <table>` or `SHOW TABLES LIKE '...'` at runtime to detect optional columns (`archived_at`, `descripcion_md`, `created_by`) or optional tables (`task_tags`). This lets the app handle database migrations incrementally without breaking old schemas. Follow this pattern when adding optional columns.

### CSRF Protection
CSRF tokens are stored in `$_SESSION['csrf']` (generated with `bin2hex(random_bytes(32))`). All mutating endpoints validate the token with `hash_equals()`. The token is embedded in forms and JS state (via `data-csrf` on `#kanban`).

### API Endpoints
Action endpoints (under `tasks/`, `columns/`, `tags/`) return JSON (`Content-Type: application/json`). They accept both `application/json` body and `application/x-www-form-urlencoded` form posts. Response shape is always `{"ok": true, ...}` or `{"ok": false, "error": "..."}`.

### Frontend JavaScript
Two vanilla JS IIFE modules in `public/assets/`:
- `board-view.js` — Kanban board interactions: drag-and-drop task reordering, task drawer open/close (via AJAX fetch of `tasks/drawer.php`), real-time event polling (`boards/events_poll.php`), column management. Reads `data-board-id` and `data-csrf` from `#kanban`.
- `boards-actions.js` — Workspace page modals: create/edit/delete/archive boards.

### Real-Time Presence & Events
- `boards/presence_ping.php` — called periodically by JS to update `board_presence` table.
- `boards/events_poll.php` — long-poll endpoint returning new `board_events` rows since a given `after_id`. JS uses this to update the board without full page reload.

### Role System
Two overlapping permission systems:
1. **Board-level roles** (`board_members.rol`): `propietario`, `editor`, `lector`.
2. **System-level roles** (`users.rol` + `users.is_admin`): `user`, `admin`; super admin is `is_admin=1` AND `rol='super_admin'`.

Teams (`teams`, `team_members`) have their own role: `admin_equipo` or `miembro`. Boards can be personal (`team_id IS NULL`) or team-owned.

### HTML Escaping Convention
Every page defines a local `h()` function:
```php
function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
```
Always use `h()` when echoing user-supplied data into HTML.
