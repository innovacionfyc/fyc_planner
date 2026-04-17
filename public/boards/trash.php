<?php
require_once __DIR__ . '/../_auth.php';
require_login();
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../_perm.php';

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

$userId       = (int)($_SESSION['user_id'] ?? 0);
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

// Condición de acceso (misma lógica que workspace.php)
$hasCreatedBy        = isset($cols['created_by']);
$personalMemberWhere = "EXISTS (SELECT 1 FROM board_members bm WHERE bm.board_id=b.id AND bm.user_id={$userId})";
$creatorClause       = $hasCreatedBy ? " OR b.created_by={$userId}" : "";
$personalBaseWhere   = "(b.team_id IS NULL AND ({$personalMemberWhere}{$creatorClause}))";
$teamBaseWhere       = $isSuperAdmin
    ? "b.team_id IS NOT NULL"
    : "(b.team_id IS NOT NULL AND EXISTS (SELECT 1 FROM team_members tm WHERE tm.team_id=b.team_id AND tm.user_id={$userId}))";
$accessWhere = "({$personalBaseWhere} OR {$teamBaseWhere})";

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
<!doctype html>
<html lang="es" data-theme="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Papelera — F&amp;C Planner</title>
    <link rel="stylesheet" href="../assets/app.css">
    <link rel="stylesheet" href="../assets/theme.css">
    <script>
        (function () {
            var t = localStorage.getItem('fyc-theme') || 'dark';
            document.documentElement.setAttribute('data-theme', t);
        })();
    </script>
    <style>
        body {
            margin: 0;
            background: var(--bg-app);
            color: var(--text-primary);
            font-family: 'DM Sans', sans-serif;
            font-size: 13px;
        }
        .trash-wrap {
            max-width: 720px;
            margin: 40px auto;
            padding: 0 20px;
        }
        .trash-header {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 28px;
            padding-bottom: 16px;
            border-bottom: 1px solid var(--border-main);
        }
        .trash-title {
            font-family: 'Sora', sans-serif;
            font-size: 18px;
            font-weight: 700;
            color: var(--text-primary);
            margin: 0;
        }
        .trash-count {
            font-size: 11px;
            color: var(--text-ghost);
            font-weight: 600;
            background: var(--bg-hover);
            padding: 2px 8px;
            border-radius: 999px;
        }
        .trash-back {
            font-size: 12px;
            color: var(--text-muted);
            text-decoration: none;
            margin-left: auto;
            display: flex;
            align-items: center;
            gap: 4px;
        }
        .trash-back:hover { color: var(--text-primary); }
        .trash-empty {
            color: var(--text-ghost);
            font-size: 13px;
            text-align: center;
            padding: 60px 0;
        }
        .trash-card {
            background: var(--bg-surface);
            border: 1px solid var(--border-main);
            border-radius: 10px;
            padding: 14px 16px;
            margin-bottom: 8px;
            display: flex;
            align-items: center;
            gap: 14px;
            transition: border-color 0.15s;
        }
        .trash-card:hover { border-color: var(--border-accent); }
        .trash-chip {
            width: 10px;
            height: 10px;
            border-radius: 50%;
            flex-shrink: 0;
        }
        .trash-info { flex: 1; min-width: 0; }
        .trash-name {
            font-size: 13px;
            font-weight: 600;
            color: var(--text-primary);
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .trash-meta {
            font-size: 11px;
            color: var(--text-muted);
            margin-top: 3px;
        }
        .trash-days {
            font-size: 11px;
            font-weight: 600;
            color: var(--text-muted);
            margin-top: 2px;
        }
        .trash-days.urgent { color: var(--fyc-red); }
        .trash-actions { display: flex; gap: 6px; flex-shrink: 0; }
        .btn-trash {
            font-size: 11px;
            font-weight: 600;
            padding: 5px 12px;
            border-radius: 6px;
            border: none;
            cursor: pointer;
            transition: opacity 0.15s;
            line-height: 1;
        }
        .btn-trash:hover { opacity: 0.8; }
        .btn-restore {
            background: var(--bg-hover);
            color: var(--text-primary);
            border: 1px solid var(--border-accent);
        }
        .flash-ok {
            background: var(--badge-ok-bg);
            color: var(--badge-ok-tx);
            border: 1px solid var(--badge-ok-tx);
            padding: 10px 14px;
            border-radius: 6px;
            margin-bottom: 16px;
            font-size: 13px;
        }
        .flash-err {
            background: var(--badge-overdue-bg);
            color: var(--badge-overdue-tx);
            border: 1px solid var(--badge-overdue-tx);
            padding: 10px 14px;
            border-radius: 6px;
            margin-bottom: 16px;
            font-size: 13px;
        }
    </style>
</head>
<body>
<div class="trash-wrap">
    <div class="trash-header">
        <h1 class="trash-title">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                 style="width:18px;height:18px;vertical-align:-3px;margin-right:6px;color:var(--text-muted);">
                <polyline points="3 6 5 6 21 6"/>
                <path d="M19 6l-1 14H6L5 6"/>
                <path d="M10 11v6"/><path d="M14 11v6"/>
                <path d="M9 6V4h6v2"/>
            </svg>
            Papelera
        </h1>
        <?php if (count($boards) > 0): ?>
            <span class="trash-count"><?= count($boards) ?></span>
        <?php endif; ?>
        <a href="./workspace.php" class="trash-back">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                 style="width:12px;height:12px;">
                <path d="M19 12H5M12 5l-7 7 7 7" stroke-linecap="round" stroke-linejoin="round"/>
            </svg>
            Volver al workspace
        </a>
    </div>

    <?php if ($flash): ?>
        <div class="flash-<?= $flash['type'] === 'ok' ? 'ok' : 'err' ?>">
            <?= h($flash['msg']) ?>
        </div>
    <?php endif; ?>

    <?php if (empty($boards)): ?>
        <div class="trash-empty">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"
                 style="width:40px;height:40px;margin:0 auto 12px;display:block;color:var(--text-ghost);opacity:0.4;">
                <polyline points="3 6 5 6 21 6"/>
                <path d="M19 6l-1 14H6L5 6"/>
                <path d="M10 11v6"/><path d="M14 11v6"/>
                <path d="M9 6V4h6v2"/>
            </svg>
            La papelera está vacía.
        </div>
    <?php else: ?>
        <?php foreach ($boards as $b):
            $color     = h($b['color_hex'] ?: '#e85070');
            $days      = (int)$b['days_remaining'];
            $urgent    = $days <= 7;
            $deletedAt = date('d/m/Y H:i', strtotime($b['deleted_at']));
        ?>
        <div class="trash-card">
            <div class="trash-chip" style="background:<?= $color ?>;"></div>
            <div class="trash-info">
                <div class="trash-name"><?= h($b['nombre']) ?></div>
                <div class="trash-meta">
                    Eliminado el <?= h($deletedAt) ?>
                    <?php if (!empty($b['deleted_by_name'])): ?>
                        · por <?= h($b['deleted_by_name']) ?>
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
                <form method="POST" action="./trash_restore.php">
                    <input type="hidden" name="csrf"     value="<?= h($_SESSION['csrf']) ?>">
                    <input type="hidden" name="board_id" value="<?= (int)$b['id'] ?>">
                    <button type="submit" class="btn-trash btn-restore">Restaurar</button>
                </form>
            </div>
        </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>
</body>
</html>
