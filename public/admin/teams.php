<?php
// public/admin/teams.php
require_once __DIR__ . '/../_auth.php';
require_admin();
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../_perm.php';

$isSuper = is_super_admin($conn);

$teams = $conn->query("
    SELECT id, nombre, created_at
    FROM teams
    ORDER BY nombre ASC
")->fetch_all(MYSQLI_ASSOC);

function membersOf(mysqli $conn, int $team_id): array
{
    $q = $conn->prepare("
        SELECT u.id AS user_id, u.nombre, u.email, tm.rol
        FROM team_members tm
        JOIN users u ON u.id = tm.user_id
        WHERE tm.team_id = ?
        ORDER BY tm.rol ASC, u.nombre ASC
    ");
    $q->bind_param('i', $team_id);
    $q->execute();
    return $q->get_result()->fetch_all(MYSQLI_ASSOC);
}

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

$pageTitle  = 'Equipos';
$activePage = 'equipos';
require_once __DIR__ . '/_layout_top.php';
?>

<style>
    .teams-table select {
        background: var(--bg-input);
        border: 1px solid var(--border-accent);
        border-radius: 8px;
        padding: 5px 8px;
        font-size: 12px;
        color: var(--text-primary);
        font-family: 'DM Sans', sans-serif;
        outline: none;
    }
    .teams-table select:focus { border-color: var(--fyc-red); }
    .empty-note { color: var(--text-ghost); font-style: italic; font-size: 13px; padding: 10px 0; }
    /* Toast */
    #toast {
        position: fixed; bottom: 22px; right: 22px; padding: 12px 18px;
        border-radius: 12px; font-weight: 700; font-family: 'DM Sans', sans-serif;
        z-index: 9999; display: none; max-width: 380px;
        box-shadow: 0 4px 20px rgba(0,0,0,.4); font-size: 13px;
    }
    #toast.ok  { background: var(--badge-ok-bg);      color: var(--badge-ok-tx);      border: 1px solid var(--badge-ok-tx); }
    #toast.err { background: var(--badge-overdue-bg);  color: var(--badge-overdue-tx); border: 1px solid var(--badge-overdue-tx); }
</style>

<div id="toast"></div>

<h1 class="admin-page-title">Equipos</h1>
<p class="admin-page-sub">
    <?= $isSuper ? 'Modo <strong>Super Admin</strong> — puedes crear y eliminar equipos.' : 'Modo <strong>Admin</strong> — puedes gestionar miembros.' ?>
</p>

<!-- Crear equipo -->
<div class="admin-card" style="margin-bottom:20px;">
    <div class="admin-card-header">
        <span class="admin-card-title">Crear equipo</span>
    </div>
    <div style="padding:16px;">
        <form id="createTeamForm" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
            <input class="fyc-input" style="max-width:320px;" type="text" name="nombre"
                   placeholder="Nombre del equipo (TI, Comercial…)" maxlength="120" required>
            <button class="fyc-btn fyc-btn-primary" type="submit">Crear equipo</button>
        </form>
    </div>
</div>

<!-- Mensaje vacío -->
<div id="teamsEmpty" class="admin-flash" style="background:var(--bg-surface);color:var(--text-ghost);border:1px solid var(--border-main);<?= count($teams) > 0 ? 'display:none;' : '' ?>">
    No hay equipos todavía. Crea uno arriba.
</div>

<!-- Lista de equipos -->
<div id="teamsContainer" data-is-super="<?= $isSuper ? '1' : '0' ?>">
    <?php foreach ($teams as $t):
        $tid     = (int)$t['id'];
        $members = membersOf($conn, $tid);
    ?>
    <div class="admin-card" id="team-<?= $tid ?>" style="margin-bottom:16px;">

        <div class="admin-card-header">
            <span class="admin-card-title"><?= h($t['nombre']) ?></span>
            <div style="display:flex;align-items:center;gap:8px;">
                <span class="fyc-badge" style="background:var(--bg-hover);color:var(--text-muted);border:1px solid var(--border-accent);"
                      id="count-<?= $tid ?>">Miembros: <?= count($members) ?></span>
                <?php if ($isSuper): ?>
                    <button class="fyc-btn fyc-btn-danger delete-team-btn" style="padding:5px 10px;font-size:11px;"
                            data-team-id="<?= $tid ?>">Eliminar equipo</button>
                <?php endif; ?>
            </div>
        </div>

        <div class="admin-card-body">
            <table class="admin-table teams-table">
                <thead>
                    <tr>
                        <th style="width:28%">Nombre</th>
                        <th style="width:30%">Email</th>
                        <th style="width:22%">Rol</th>
                        <th style="width:20%"></th>
                    </tr>
                </thead>
                <tbody id="members-<?= $tid ?>">
                    <?php if (empty($members)): ?>
                        <tr class="empty-row"><td colspan="4" class="empty-note">Sin miembros todavía.</td></tr>
                    <?php else: ?>
                        <?php foreach ($members as $m):
                            $uid    = (int)$m['user_id'];
                            $rolAct = $m['rol'];
                            $altVal = $rolAct === 'admin_equipo' ? 'miembro'      : 'admin_equipo';
                            $curLbl = $rolAct === 'admin_equipo' ? 'Admin equipo' : 'Miembro';
                            $altLbl = $rolAct === 'admin_equipo' ? 'Miembro'      : 'Admin equipo';
                        ?>
                        <tr data-user-id="<?= $uid ?>">
                            <td style="font-weight:700;color:var(--text-primary);"><?= h($m['nombre']) ?></td>
                            <td style="color:var(--text-muted);font-size:12px;"><?= h($m['email']) ?></td>
                            <td>
                                <select class="role-select" data-team-id="<?= $tid ?>" data-user-id="<?= $uid ?>">
                                    <option value="<?= h($rolAct) ?>" selected><?= h($curLbl) ?></option>
                                    <option value="<?= h($altVal) ?>"><?= h($altLbl) ?></option>
                                </select>
                            </td>
                            <td>
                                <button class="fyc-btn fyc-btn-danger remove-member-btn"
                                        style="padding:5px 10px;font-size:11px;"
                                        data-team-id="<?= $tid ?>" data-user-id="<?= $uid ?>">Quitar</button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>

            <!-- Agregar miembro -->
            <form class="add-member-form" data-team-id="<?= $tid ?>"
                  style="padding:14px 12px;border-top:1px solid var(--border-main);display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
                <input class="fyc-input" style="max-width:240px;"
                       type="email" name="email" placeholder="email@fycconsultores.com" required>
                <select class="teams-table" name="rol"
                        style="background:var(--bg-input);border:1px solid var(--border-accent);border-radius:8px;padding:8px 10px;font-size:12px;color:var(--text-primary);font-family:'DM Sans',sans-serif;outline:none;">
                    <option value="miembro">Miembro</option>
                    <option value="admin_equipo">Admin equipo</option>
                </select>
                <button class="fyc-btn fyc-btn-primary" type="submit" style="padding:8px 14px;font-size:12px;">Agregar</button>
            </form>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<script>
(function () {
    'use strict';

    var CSRF     = document.querySelector('meta[name="csrf"]').getAttribute('content');
    var IS_SUPER = document.getElementById('teamsContainer').dataset.isSuper === '1';

    // Toast
    var toastEl    = document.getElementById('toast');
    var toastTimer = null;
    function toast(msg, type) {
        toastEl.textContent   = msg;
        toastEl.className     = type || 'ok';
        toastEl.style.display = 'block';
        clearTimeout(toastTimer);
        toastTimer = setTimeout(function () { toastEl.style.display = 'none'; }, 3500);
    }

    function setLoading(btn, loading) {
        if (loading) { btn.disabled = true; btn._orig = btn.textContent; btn.textContent = 'Cargando…'; }
        else         { btn.disabled = false; btn.textContent = btn._orig || btn.textContent; }
    }

    function api(action, extra) {
        var params = new URLSearchParams({ action: action, csrf: CSRF });
        Object.keys(extra || {}).forEach(function (k) { params.set(k, extra[k]); });
        return fetch('team_action.php', { method: 'POST', body: params })
            .then(function (r) { return r.json(); });
    }

    function esc(s) {
        return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }

    function updateCount(teamId) {
        var tbody = document.getElementById('members-' + teamId);
        var badge = document.getElementById('count-'   + teamId);
        if (!tbody || !badge) return;
        badge.textContent = 'Miembros: ' + tbody.querySelectorAll('tr:not(.empty-row)').length;
    }

    function syncEmptyRow(teamId) {
        var tbody = document.getElementById('members-' + teamId);
        if (!tbody) return;
        var real  = tbody.querySelectorAll('tr:not(.empty-row)').length;
        var empty = tbody.querySelector('.empty-row');
        if (real === 0 && !empty) {
            tbody.insertAdjacentHTML('beforeend',
                '<tr class="empty-row"><td colspan="4" class="empty-note">Sin miembros todavía.</td></tr>');
        } else if (real > 0 && empty) {
            empty.parentNode.removeChild(empty);
        }
    }

    function memberRowHTML(teamId, userId, nombre, email, rol) {
        var curLbl = rol === 'admin_equipo' ? 'Admin equipo' : 'Miembro';
        var altVal = rol === 'admin_equipo' ? 'miembro'      : 'admin_equipo';
        var altLbl = rol === 'admin_equipo' ? 'Miembro'      : 'Admin equipo';
        return '<tr data-user-id="' + userId + '">'
            + '<td style="font-weight:700;color:var(--text-primary);">' + esc(nombre) + '</td>'
            + '<td style="color:var(--text-muted);font-size:12px;">' + esc(email) + '</td>'
            + '<td><select class="role-select" data-team-id="' + teamId + '" data-user-id="' + userId + '">'
            +   '<option value="' + esc(rol)    + '" selected>' + esc(curLbl) + '</option>'
            +   '<option value="' + esc(altVal) + '">'          + esc(altLbl) + '</option>'
            + '</select></td>'
            + '<td><button class="fyc-btn fyc-btn-danger remove-member-btn" style="padding:5px 10px;font-size:11px;"'
            +   ' data-team-id="' + teamId + '" data-user-id="' + userId + '">Quitar</button></td>'
            + '</tr>';
    }

    function buildTeamCard(teamId, nombre, creator) {
        var delBtn = IS_SUPER
            ? '<button class="fyc-btn fyc-btn-danger delete-team-btn" style="padding:5px 10px;font-size:11px;" data-team-id="' + teamId + '">Eliminar equipo</button>'
            : '';
        return '<div class="admin-card" id="team-' + teamId + '" style="margin-bottom:16px;">'
            + '<div class="admin-card-header">'
            +   '<span class="admin-card-title">' + esc(nombre) + '</span>'
            +   '<div style="display:flex;align-items:center;gap:8px;">'
            +     '<span class="fyc-badge" style="background:var(--bg-hover);color:var(--text-muted);border:1px solid var(--border-accent);" id="count-' + teamId + '">Miembros: 1</span>'
            +     delBtn
            +   '</div>'
            + '</div>'
            + '<div class="admin-card-body">'
            +   '<table class="admin-table teams-table"><thead><tr>'
            +     '<th style="width:28%">Nombre</th><th style="width:30%">Email</th>'
            +     '<th style="width:22%">Rol</th><th style="width:20%"></th>'
            +   '</tr></thead>'
            +   '<tbody id="members-' + teamId + '">'
            +     memberRowHTML(teamId, creator.user_id, creator.nombre, creator.email, creator.rol)
            +   '</tbody></table>'
            +   '<form class="add-member-form" data-team-id="' + teamId + '"'
            +     ' style="padding:14px 12px;border-top:1px solid var(--border-main);display:flex;gap:8px;flex-wrap:wrap;align-items:center;">'
            +     '<input class="fyc-input" style="max-width:240px;" type="email" name="email" placeholder="email@fycconsultores.com" required>'
            +     '<select name="rol" style="background:var(--bg-input);border:1px solid var(--border-accent);border-radius:8px;padding:8px 10px;font-size:12px;color:var(--text-primary);font-family:\'DM Sans\',sans-serif;outline:none;">'
            +       '<option value="miembro">Miembro</option>'
            +       '<option value="admin_equipo">Admin equipo</option>'
            +     '</select>'
            +     '<button class="fyc-btn fyc-btn-primary" type="submit" style="padding:8px 14px;font-size:12px;">Agregar</button>'
            +   '</form>'
            + '</div></div>';
    }

    // CREATE TEAM
    document.getElementById('createTeamForm').addEventListener('submit', function (e) {
        e.preventDefault();
        var input  = this.querySelector('input[name="nombre"]');
        var btn    = this.querySelector('button[type="submit"]');
        var nombre = input.value.trim();
        if (!nombre) return;
        setLoading(btn, true);
        api('create_team', { nombre: nombre })
            .then(function (res) {
                if (res.ok) {
                    document.getElementById('teamsContainer').insertAdjacentHTML('afterbegin',
                        buildTeamCard(res.team_id, res.nombre, res.creator));
                    document.getElementById('teamsEmpty').style.display = 'none';
                    input.value = '';
                    toast('Equipo "' + res.nombre + '" creado correctamente.', 'ok');
                } else { toast(res.error || 'Error al crear equipo.', 'err'); }
            })
            .catch(function () { toast('Error de conexión.', 'err'); })
            .finally(function () { setLoading(btn, false); });
    });

    // ADD MEMBER
    document.addEventListener('submit', function (e) {
        var form = e.target.closest('.add-member-form');
        if (!form) return;
        e.preventDefault();
        var teamId = form.dataset.teamId;
        var email  = form.querySelector('input[name="email"]').value.trim();
        var rol    = form.querySelector('select[name="rol"]').value;
        var btn    = form.querySelector('button[type="submit"]');
        if (!email) return;
        setLoading(btn, true);
        api('add_member', { team_id: teamId, email: email, rol: rol })
            .then(function (res) {
                if (res.ok) {
                    document.getElementById('members-' + teamId)
                        .insertAdjacentHTML('beforeend', memberRowHTML(teamId, res.user_id, res.nombre, res.email, res.rol));
                    form.querySelector('input[name="email"]').value = '';
                    syncEmptyRow(teamId);
                    updateCount(teamId);
                    toast('Miembro agregado correctamente.', 'ok');
                } else { toast(res.error || 'Error al agregar miembro.', 'err'); }
            })
            .catch(function () { toast('Error de conexión.', 'err'); })
            .finally(function () { setLoading(btn, false); });
    });

    // REMOVE MEMBER
    document.addEventListener('click', function (e) {
        var btn = e.target.closest('.remove-member-btn');
        if (!btn) return;
        setLoading(btn, true);
        api('remove_member', { team_id: btn.dataset.teamId, user_id: btn.dataset.userId })
            .then(function (res) {
                if (res.ok) {
                    var row = btn.closest('tr');
                    row.parentNode.removeChild(row);
                    syncEmptyRow(btn.dataset.teamId);
                    updateCount(btn.dataset.teamId);
                    toast('Miembro quitado del equipo.', 'ok');
                } else { toast(res.error || 'Error al quitar miembro.', 'err'); setLoading(btn, false); }
            })
            .catch(function () { toast('Error de conexión.', 'err'); setLoading(btn, false); });
    });

    // CHANGE ROLE
    document.addEventListener('change', function (e) {
        var sel = e.target.closest('.role-select');
        if (!sel) return;
        var newRol = sel.value;
        var prevOption = sel.querySelector('option:not([value="' + newRol + '"])');
        var prevRol    = prevOption ? prevOption.value : (newRol === 'admin_equipo' ? 'miembro' : 'admin_equipo');
        sel.disabled = true;
        api('set_member_role', { team_id: sel.dataset.teamId, user_id: sel.dataset.userId, rol: newRol })
            .then(function (res) {
                if (res.ok) {
                    var curLbl = newRol === 'admin_equipo' ? 'Admin equipo' : 'Miembro';
                    var altLbl = prevRol === 'admin_equipo' ? 'Admin equipo' : 'Miembro';
                    sel.innerHTML =
                        '<option value="' + esc(newRol)  + '" selected>' + esc(curLbl) + '</option>'
                      + '<option value="' + esc(prevRol) + '">'           + esc(altLbl) + '</option>';
                    toast('Rol actualizado.', 'ok');
                } else { sel.value = prevRol; toast(res.error || 'Error al cambiar rol.', 'err'); }
            })
            .catch(function () { sel.value = prevRol; toast('Error de conexión.', 'err'); })
            .finally(function () { sel.disabled = false; });
    });

    // DELETE TEAM
    document.addEventListener('click', function (e) {
        var btn = e.target.closest('.delete-team-btn');
        if (!btn) return;
        if (!confirm('¿Eliminar este equipo? Se quitarán todos sus miembros. Esta acción no se puede deshacer.')) return;
        setLoading(btn, true);
        api('delete_team', { team_id: btn.dataset.teamId })
            .then(function (res) {
                if (res.ok) {
                    var card = document.getElementById('team-' + btn.dataset.teamId);
                    card.parentNode.removeChild(card);
                    var container = document.getElementById('teamsContainer');
                    if (!container.querySelector('.admin-card')) {
                        document.getElementById('teamsEmpty').style.display = '';
                    }
                    toast('Equipo eliminado.', 'ok');
                } else { toast(res.error || 'Error al eliminar equipo.', 'err'); setLoading(btn, false); }
            })
            .catch(function () { toast('Error de conexión.', 'err'); setLoading(btn, false); });
    });

})();
</script>

<?php require_once __DIR__ . '/_layout_bottom.php'; ?>
