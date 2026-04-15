<?php
// public/admin/_layout_top.php
// Partial de apertura para todas las páginas admin.
// Espera que la página llamante haya definido:
//   $pageTitle  (string) — título del <title>
//   $activePage (string) — 'index' | 'usuarios' | 'equipos'
// Y que ya haya hecho require_once _auth.php + require_admin().

if (empty($_SESSION['csrf']))
    $_SESSION['csrf'] = bin2hex(random_bytes(32));

$_adminNav = [
    ['id' => 'usuarios',     'label' => 'Usuarios',     'url' => 'users_pending.php'],
    ['id' => 'equipos',      'label' => 'Equipos',      'url' => 'teams.php'],
    ['id' => 'estadisticas', 'label' => 'Estadísticas', 'url' => 'stats.php'],
];
?>
<!doctype html>
<html lang="es" data-theme="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf" content="<?= htmlspecialchars($_SESSION['csrf'], ENT_QUOTES, 'UTF-8') ?>">
    <title><?= htmlspecialchars($pageTitle ?? 'Admin', ENT_QUOTES, 'UTF-8') ?> — F&amp;C Planner</title>
    <link rel="stylesheet" href="../assets/app.css">
    <link rel="stylesheet" href="../assets/theme.css">
    <script>(function () {
        var t = localStorage.getItem('fyc-theme') || 'dark';
        document.documentElement.setAttribute('data-theme', t);
    })();</script>
    <style>
        .admin-nav-link {
            font-size: 12px;
            font-weight: 600;
            color: var(--text-muted);
            text-decoration: none;
            padding: 5px 10px;
            border-radius: 8px;
            transition: background 0.15s, color 0.15s;
            white-space: nowrap;
        }
        .admin-nav-link:hover {
            background: var(--bg-hover);
            color: var(--text-primary);
        }
        .admin-nav-link.active {
            color: var(--fyc-red);
            background: var(--badge-overdue-bg);
        }
        .admin-content {
            max-width: 1100px;
            margin: 0 auto;
            padding: 28px 20px;
        }
        .admin-page-title {
            font-family: 'Sora', sans-serif;
            font-size: 22px;
            font-weight: 800;
            color: var(--text-primary);
            letter-spacing: -0.4px;
            margin: 0 0 4px;
        }
        .admin-page-sub {
            font-size: 13px;
            color: var(--text-ghost);
            margin: 0 0 24px;
        }
        /* Tabla admin */
        .admin-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 13px;
        }
        .admin-table th {
            text-align: left;
            padding: 10px 12px;
            font-size: 11px;
            font-weight: 700;
            color: var(--text-ghost);
            text-transform: uppercase;
            letter-spacing: 0.8px;
            border-bottom: 1px solid var(--border-main);
            white-space: nowrap;
        }
        .admin-table td {
            padding: 11px 12px;
            border-bottom: 1px solid var(--border-main);
            vertical-align: middle;
            color: var(--text-secondary);
        }
        .admin-table tr:last-child td {
            border-bottom: none;
        }
        .admin-table tr:hover td {
            background: var(--bg-hover);
        }
        /* Card surface */
        .admin-card {
            background: var(--bg-surface);
            border: 1px solid var(--border-accent);
            border-radius: 16px;
            overflow: hidden;
            margin-bottom: 16px;
        }
        .admin-card-header {
            padding: 14px 18px;
            border-bottom: 1px solid var(--border-main);
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
        }
        .admin-card-title {
            font-family: 'Sora', sans-serif;
            font-size: 15px;
            font-weight: 700;
            color: var(--text-primary);
        }
        .admin-card-body {
            padding: 0;
        }
        /* Stats strip */
        .admin-stats {
            display: flex;
            gap: 12px;
            margin-bottom: 24px;
            flex-wrap: wrap;
        }
        .admin-stat {
            flex: 1;
            min-width: 140px;
            background: var(--bg-surface);
            border: 1px solid var(--border-accent);
            border-radius: 14px;
            padding: 14px 18px;
        }
        .admin-stat-num {
            font-family: 'Sora', sans-serif;
            font-size: 28px;
            font-weight: 800;
            color: var(--fyc-red);
            line-height: 1;
            margin-bottom: 4px;
        }
        .admin-stat-label {
            font-size: 11px;
            font-weight: 600;
            color: var(--text-ghost);
            text-transform: uppercase;
            letter-spacing: 0.8px;
        }
        /* Flash */
        .admin-flash {
            padding: 12px 16px;
            border-radius: 12px;
            font-size: 13px;
            font-weight: 600;
            margin-bottom: 20px;
        }
        .admin-flash-ok {
            background: var(--badge-ok-bg);
            color: var(--badge-ok-tx);
            border: 1px solid var(--badge-ok-tx);
        }
        .admin-flash-error {
            background: var(--badge-overdue-bg);
            color: var(--badge-overdue-tx);
            border: 1px solid var(--badge-overdue-tx);
        }
    </style>
</head>
<body>

<!-- HEADER ADMIN -->
<header class="fyc-header">
    <div style="display:flex;align-items:center;gap:14px;">
        <a href="../boards/workspace.php" class="fyc-logo">F&amp;C <span>Planner</span></a>
        <div style="width:1px;height:18px;background:var(--border-main);"></div>
        <span style="font-size:12px;color:var(--text-ghost);font-family:'Sora',sans-serif;">Administración</span>
        <div style="width:1px;height:18px;background:var(--border-main);"></div>
        <!-- Nav -->
        <nav style="display:flex;align-items:center;gap:4px;">
            <?php foreach ($_adminNav as $_n): ?>
                <a href="<?= htmlspecialchars($_n['url'], ENT_QUOTES, 'UTF-8') ?>"
                   class="admin-nav-link<?= (($activePage ?? '') === $_n['id']) ? ' active' : '' ?>">
                    <?= htmlspecialchars($_n['label'], ENT_QUOTES, 'UTF-8') ?>
                </a>
            <?php endforeach; ?>
        </nav>
    </div>
    <div style="display:flex;align-items:center;gap:10px;">
        <a href="../boards/workspace.php" class="fyc-btn fyc-btn-ghost" style="text-decoration:none;font-size:12px;">← Workspace</a>
        <button id="themeToggle" title="Cambiar tema">
            <span id="themeIcon">🌙</span>
            <span id="themeLabel">Oscuro</span>
        </button>
        <a href="../logout.php" class="fyc-btn fyc-btn-danger" style="text-decoration:none;">Salir</a>
        <div class="fyc-avatar"><?= strtoupper(mb_substr($_SESSION['nombre'] ?? 'A', 0, 2)) ?></div>
    </div>
</header>

<!-- CONTENIDO -->
<main class="admin-content">
