<?php
// public/admin/index.php — Dashboard central de administración
require_once __DIR__ . '/../_auth.php';
require_admin();
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../_perm.php';

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

// Stats
$totalUsers    = (int)($conn->query("SELECT COUNT(*) FROM users WHERE deleted_at IS NULL")->fetch_row()[0] ?? 0);
$pendingUsers  = (int)($conn->query("SELECT COUNT(*) FROM users WHERE estado='pendiente' AND deleted_at IS NULL")->fetch_row()[0] ?? 0);
$totalTeams    = (int)($conn->query("SELECT COUNT(*) FROM teams")->fetch_row()[0] ?? 0);

// Módulos — agregar una entrada aquí para extender el panel en el futuro
$modules = [
    [
        'id'     => 'usuarios',
        'title'  => 'Usuarios',
        'icon'   => '👥',
        'desc'   => 'Aprueba registros, asigna roles y gestiona el estado de cada cuenta.',
        'url'    => 'users_pending.php',
        'stat'   => $totalUsers . ' usuario' . ($totalUsers !== 1 ? 's' : ''),
        'badge'  => $pendingUsers > 0 ? $pendingUsers . ' pendiente' . ($pendingUsers !== 1 ? 's' : '') : null,
        'active' => true,
    ],
    [
        'id'     => 'equipos',
        'title'  => 'Equipos',
        'icon'   => '🏢',
        'desc'   => 'Crea equipos, asigna administradores y gestiona la membresía.',
        'url'    => 'teams.php',
        'stat'   => $totalTeams . ' equipo' . ($totalTeams !== 1 ? 's' : ''),
        'badge'  => null,
        'active' => true,
    ],
    [
        'id'     => 'tableros',
        'title'  => 'Tableros',
        'icon'   => '📋',
        'desc'   => 'Gestión global de tableros por equipo. Disponible próximamente.',
        'url'    => null,
        'stat'   => null,
        'badge'  => null,
        'active' => false,
    ],
];

$pageTitle  = 'Panel Admin';
$activePage = 'index';
require_once __DIR__ . '/_layout_top.php';
?>

<h1 class="admin-page-title">Panel de administración</h1>
<p class="admin-page-sub">Gestiona usuarios, equipos y configuración del sistema.</p>

<!-- Stats -->
<div class="admin-stats">
    <div class="admin-stat">
        <div class="admin-stat-num"><?= $totalUsers ?></div>
        <div class="admin-stat-label">Usuarios totales</div>
    </div>
    <div class="admin-stat">
        <div class="admin-stat-num" style="color:<?= $pendingUsers > 0 ? 'var(--badge-soon-tx)' : 'var(--fyc-red)' ?>;"><?= $pendingUsers ?></div>
        <div class="admin-stat-label">Pendientes de aprobación</div>
    </div>
    <div class="admin-stat">
        <div class="admin-stat-num"><?= $totalTeams ?></div>
        <div class="admin-stat-label">Equipos</div>
    </div>
</div>

<!-- Módulos -->
<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:14px;">
    <?php foreach ($modules as $mod): ?>
        <?php if ($mod['active']): ?>
            <a href="<?= h($mod['url']) ?>" style="text-decoration:none;">
                <div class="admin-card" style="cursor:pointer;transition:border-color 0.15s,transform 0.1s;height:100%;"
                     onmouseover="this.style.borderColor='var(--fyc-red)';this.style.transform='translateY(-2px)'"
                     onmouseout="this.style.borderColor='var(--border-accent)';this.style.transform=''">
        <?php else: ?>
            <div>
                <div class="admin-card" style="opacity:0.45;height:100%;">
        <?php endif; ?>

                    <div style="padding:20px 20px 8px;">
                        <div style="font-size:28px;margin-bottom:10px;line-height:1;"><?= $mod['icon'] ?></div>
                        <div style="font-family:'Sora',sans-serif;font-size:16px;font-weight:800;color:var(--text-primary);margin-bottom:6px;">
                            <?= h($mod['title']) ?>
                            <?php if (!$mod['active']): ?>
                                <span style="font-size:10px;font-weight:600;color:var(--text-ghost);margin-left:6px;vertical-align:middle;">Próximamente</span>
                            <?php endif; ?>
                        </div>
                        <p style="font-size:13px;color:var(--text-muted);line-height:1.5;margin:0 0 14px;"><?= h($mod['desc']) ?></p>
                    </div>

                    <?php if ($mod['active']): ?>
                    <div style="padding:12px 20px;border-top:1px solid var(--border-main);display:flex;align-items:center;justify-content:space-between;gap:8px;">
                        <span style="font-size:12px;color:var(--text-ghost);"><?= h($mod['stat'] ?? '') ?></span>
                        <div style="display:flex;align-items:center;gap:6px;">
                            <?php if ($mod['badge']): ?>
                                <span class="fyc-badge fyc-badge-soon"><?= h($mod['badge']) ?></span>
                            <?php endif; ?>
                            <span style="font-size:12px;font-weight:700;color:var(--fyc-red);">Ir →</span>
                        </div>
                    </div>
                    <?php endif; ?>

        <?php if ($mod['active']): ?>
                </div>
            </a>
        <?php else: ?>
                </div>
            </div>
        <?php endif; ?>
    <?php endforeach; ?>
</div>

<?php require_once __DIR__ . '/_layout_bottom.php'; ?>
