<?php
require_once __DIR__ . '/../_auth.php';
require_login();
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../_i18n.php';

if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}

$userId = (int)($_SESSION['user_id'] ?? 0);
$userName = $_SESSION['nombre'] ?? $_SESSION['user_email'] ?? 'Usuario';

// Flash simple
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

// Mis equipos (para selector al crear board)
$tm = $conn->prepare("
    SELECT t.id, t.nombre, tm.rol
    FROM team_members tm
    JOIN teams t ON t.id = tm.team_id
    WHERE tm.user_id = ?
    ORDER BY t.nombre ASC
");
$tm->bind_param('i', $userId);
$tm->execute();
$mis_teams = $tm->get_result()->fetch_all(MYSQLI_ASSOC);

// Boards donde soy miembro
$sql = "
    SELECT
        b.id, b.nombre, b.color_hex, b.created_at, b.team_id,
        t.nombre AS team_nombre,
        bm.rol
    FROM board_members bm
    JOIN boards b ON b.id = bm.board_id
    LEFT JOIN teams t ON t.id = b.team_id
    WHERE bm.user_id = ?
    ORDER BY b.created_at DESC
";
$stmt = $conn->prepare($sql);
$stmt->bind_param('i', $userId);
$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

$boards_personal = [];
$boards_equipo = [];
foreach ($rows as $r) {
    if ($r['team_id'] === null) $boards_personal[] = $r;
    else $boards_equipo[] = $r;
}
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <title>F&C Planner — Tableros</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">

  <link rel="stylesheet" href="../assets/app.css?v=6">
</head>

<body class="min-h-screen bg-slate-50 text-slate-900">
  <!-- Fondo claro + manchas corporativas -->
  <div class="fixed inset-0 -z-10">
    <div class="absolute inset-0 bg-gradient-to-b from-white to-slate-50"></div>
    <div class="absolute -top-24 left-10 h-80 w-80 rounded-full bg-[#d32f57]/15 blur-3xl"></div>
    <div class="absolute top-24 right-10 h-80 w-80 rounded-full bg-[#942934]/10 blur-3xl"></div>
    <div class="absolute bottom-[-120px] left-1/2 h-96 w-96 -translate-x-1/2 rounded-full bg-[#d32f57]/10 blur-3xl"></div>
  </div>

  <div class="mx-auto max-w-6xl px-4 py-6">
    <!-- Header -->
    <div class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
      <div>
        <h1 class="text-2xl sm:text-3xl font-black tracking-tight text-[#942934]">Tus tableros</h1>
        <div class="mt-2 flex flex-wrap items-center gap-2 text-sm font-semibold text-slate-600">
          <span>Organiza trabajo por columnas y asignaciones</span>
          <span class="text-slate-400">•</span>
          <span class="inline-flex items-center gap-2 rounded-full border border-[#d32f57]/20 bg-[#d32f57]/10 px-3 py-1 text-[#5e0f1a]">
            <span class="h-2 w-2 rounded-full bg-gradient-to-br from-[#d32f57] to-[#942934] shadow"></span>
            <?= htmlspecialchars($userName) ?>
          </span>
        </div>
      </div>

      <div class="flex flex-wrap items-center gap-2">
        <?php if (isset($_SESSION['rol']) && $_SESSION['rol'] === 'admin'): ?>
          <a class="rounded-xl border border-slate-200 bg-white px-4 py-2 text-sm font-extrabold shadow-sm hover:shadow transition"
             href="../admin/users_pending.php">Aprobar usuarios</a>
          <a class="rounded-xl border border-slate-200 bg-white px-4 py-2 text-sm font-extrabold shadow-sm hover:shadow transition"
             href="../admin/teams.php">Equipos</a>
        <?php endif; ?>

        <a class="rounded-xl bg-gradient-to-br from-[#d32f57] to-[#942934] px-4 py-2 text-sm font-extrabold text-white shadow-md hover:shadow-lg transition"
           href="../logout.php">Cerrar sesión</a>
      </div>
    </div>

    <!-- Flash -->
    <?php if ($flash): ?>
      <div class="mt-5 rounded-2xl border border-slate-200 bg-white px-4 py-3 shadow-sm">
        <div class="text-sm font-bold <?= ($flash['type'] ?? '') === 'ok' ? 'text-emerald-700' : 'text-rose-700' ?>">
          <?= htmlspecialchars($flash['msg'] ?? '') ?>
        </div>
      </div>
    <?php endif; ?>

    <!-- Crear tablero -->
    <div class="mt-6 rounded-3xl border border-slate-200 bg-white/90 p-5 shadow-sm backdrop-blur">
      <div class="flex items-center justify-between gap-3">
        <div class="text-base font-black text-slate-900">Crear tablero</div>
        <span class="rounded-full border border-[#d32f57]/20 bg-[#d32f57]/10 px-3 py-1 text-xs font-extrabold text-[#5e0f1a]">
          Personal o por equipo
        </span>
      </div>

      <form class="mt-4 grid grid-cols-1 gap-3 md:grid-cols-12" method="post" action="create.php">
        <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['csrf']) ?>">

        <div class="md:col-span-5">
          <label class="block text-xs font-extrabold text-slate-600 mb-1">Nombre del tablero</label>
          <input name="nombre" required
                 class="w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm font-semibold outline-none focus:ring-4 focus:ring-[#d32f57]/15 focus:border-[#d32f57]/40"
                 placeholder="Ej. Comercial, Personal, TI…">
        </div>

        <div class="md:col-span-5">
          <label class="block text-xs font-extrabold text-slate-600 mb-1">Espacio</label>
          <select name="team_id"
                  class="w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm font-semibold outline-none focus:ring-4 focus:ring-[#d32f57]/15 focus:border-[#d32f57]/40">
            <option value="">Personal</option>
            <?php foreach ($mis_teams as $t): ?>
              <?php if (($t['rol'] ?? '') === 'admin_equipo'): ?>
                <option value="<?= (int)$t['id'] ?>">Equipo: <?= htmlspecialchars($t['nombre']) ?></option>
              <?php else: ?>
                <option value="" disabled>Equipo: <?= htmlspecialchars($t['nombre']) ?> (solo admin equipo)</option>
              <?php endif; ?>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="md:col-span-1">
          <label class="block text-xs font-extrabold text-slate-600 mb-1">Color</label>
          <input type="color" name="color_hex" value="#d32f57"
                 class="h-[46px] w-full rounded-2xl border border-slate-200 bg-white px-2 py-2">
        </div>

        <div class="md:col-span-1 flex items-end">
          <button class="w-full rounded-2xl bg-gradient-to-br from-[#d32f57] to-[#942934] px-4 py-3 text-sm font-extrabold text-white shadow-md hover:shadow-lg transition">
            Crear
          </button>
        </div>
      </form>
    </div>

    <!-- Personales -->
    <div class="mt-8 flex items-end justify-between gap-4">
      <div>
        <div class="text-lg font-black text-slate-900">Personales</div>
        <div class="text-sm font-semibold text-slate-500">Tableros que son solo tuyos</div>
      </div>
    </div>

    <?php if (!$boards_personal): ?>
      <div class="mt-4 rounded-3xl border border-dashed border-slate-300 bg-white/70 p-6 text-slate-700 shadow-sm">
        <div class="font-extrabold">No tienes tableros personales todavía.</div>
        <div class="mt-1 text-sm font-semibold text-slate-500">Crea uno arriba y empieza.</div>
      </div>
    <?php else: ?>
      <div class="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
        <?php foreach ($boards_personal as $b): ?>
          <?php
            $bId = (int)$b['id'];
            $bNombre = (string)$b['nombre'];
            $bRol = (string)$b['rol'];
            $bColor = $b['color_hex'] ?: '#d32f57';
            $canManage = ($bRol === 'propietario');
          ?>
          <div class="relative overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-sm hover:shadow-md transition">
            <div class="absolute -top-10 -right-10 h-32 w-32 rounded-full blur-2xl opacity-70"
                 style="background: <?= htmlspecialchars($bColor) ?>22;"></div>

            <div class="p-5">
              <div class="flex items-start justify-between gap-3">
                <div>
                  <div class="text-base font-black text-slate-900"><?= htmlspecialchars($bNombre) ?></div>
                  <div class="mt-1 text-sm font-semibold text-slate-500">Espacio: Personal</div>
                </div>

                <div class="flex items-center gap-2">
                  <span class="inline-flex items-center gap-2 rounded-full px-3 py-1 text-xs font-extrabold text-white shadow"
                        style="background: linear-gradient(135deg, <?= htmlspecialchars($bColor) ?>, #942934);">
                    <?= htmlspecialchars(tr_board_role($bRol)) ?>
                  </span>

                  <button type="button"
                          class="rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm font-black text-slate-700 hover:bg-slate-50 transition"
                          onclick="toggleMenu(<?= $bId ?>)">
                    ⋯
                  </button>
                </div>
              </div>

              <a class="mt-4 flex items-center justify-between rounded-2xl border border-[#d32f57]/20 bg-[#d32f57]/10 px-4 py-3 text-sm font-extrabold text-[#942934] hover:bg-[#d32f57]/15 transition"
                 href="view.php?id=<?= $bId ?>">
                Abrir tablero <span>→</span>
              </a>

              <div id="menu-<?= $bId ?>" class="hidden mt-3 rounded-2xl border border-slate-200 bg-white p-2 shadow-sm">
                <button type="button"
                        class="w-full rounded-xl px-3 py-2 text-left text-sm font-extrabold text-slate-800 hover:bg-slate-50"
                        onclick="openEdit(<?= $bId ?>, <?= json_encode($bNombre) ?>, <?= json_encode($bColor) ?>)"
                        <?= $canManage ? '' : 'disabled style="opacity:.5;cursor:not-allowed"' ?>>
                  ✏️ Editar
                </button>

                <button type="button"
                        class="w-full rounded-xl px-3 py-2 text-left text-sm font-extrabold text-rose-700 hover:bg-rose-50"
                        onclick="openDelete(<?= $bId ?>, <?= json_encode($bNombre) ?>)"
                        <?= $canManage ? '' : 'disabled style="opacity:.5;cursor:not-allowed"' ?>>
                  🗑️ Eliminar
                </button>
              </div>

              <?php if (!$canManage): ?>
                <div class="mt-2 text-xs font-bold text-slate-500">
                  Solo el <span class="text-slate-700">propietario</span> puede editar o eliminar.
                </div>
              <?php endif; ?>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <!-- Equipos -->
    <div class="mt-10">
      <div class="text-lg font-black text-slate-900">De mis equipos</div>
      <div class="text-sm font-semibold text-slate-500">Tableros compartidos por equipo</div>

      <?php if (!$boards_equipo): ?>
        <div class="mt-4 rounded-3xl border border-dashed border-slate-300 bg-white/70 p-6 text-slate-700 shadow-sm">
          <div class="font-extrabold">No perteneces aún a tableros de equipo.</div>
          <div class="mt-1 text-sm font-semibold text-slate-500">Cuando un admin de equipo te agregue, aparecerán aquí.</div>
        </div>
      <?php else: ?>
        <div class="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
          <?php foreach ($boards_equipo as $b): ?>
            <?php
              $bId = (int)$b['id'];
              $bNombre = (string)$b['nombre'];
              $bRol = (string)$b['rol'];
              $bColor = $b['color_hex'] ?: '#d32f57';
              $teamNombre = $b['team_nombre'] ?? '—';
              $canManage = ($bRol === 'propietario');
            ?>
            <div class="relative overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-sm hover:shadow-md transition">
              <div class="absolute -top-10 -right-10 h-32 w-32 rounded-full blur-2xl opacity-70"
                   style="background: <?= htmlspecialchars($bColor) ?>22;"></div>

              <div class="p-5">
                <div class="flex items-start justify-between gap-3">
                  <div>
                    <div class="text-base font-black text-slate-900"><?= htmlspecialchars($bNombre) ?></div>
                    <div class="mt-1 text-sm font-semibold text-slate-500">Equipo: <?= htmlspecialchars($teamNombre) ?></div>
                  </div>

                  <div class="flex items-center gap-2">
                    <span class="inline-flex items-center gap-2 rounded-full px-3 py-1 text-xs font-extrabold text-white shadow"
                          style="background: linear-gradient(135deg, <?= htmlspecialchars($bColor) ?>, #942934);">
                      <?= htmlspecialchars(tr_board_role($bRol)) ?>
                    </span>

                    <button type="button"
                            class="rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm font-black text-slate-700 hover:bg-slate-50 transition"
                            onclick="toggleMenu(<?= $bId ?>)">
                      ⋯
                    </button>
                  </div>
                </div>

                <a class="mt-4 flex items-center justify-between rounded-2xl border border-[#d32f57]/20 bg-[#d32f57]/10 px-4 py-3 text-sm font-extrabold text-[#942934] hover:bg-[#d32f57]/15 transition"
                   href="view.php?id=<?= $bId ?>">
                  Abrir tablero <span>→</span>
                </a>

                <div id="menu-<?= $bId ?>" class="hidden mt-3 rounded-2xl border border-slate-200 bg-white p-2 shadow-sm">
                  <button type="button"
                          class="w-full rounded-xl px-3 py-2 text-left text-sm font-extrabold text-slate-800 hover:bg-slate-50"
                          onclick="openEdit(<?= $bId ?>, <?= json_encode($bNombre) ?>, <?= json_encode($bColor) ?>)"
                          <?= $canManage ? '' : 'disabled style="opacity:.5;cursor:not-allowed"' ?>>
                    ✏️ Editar
                  </button>

                  <button type="button"
                          class="w-full rounded-xl px-3 py-2 text-left text-sm font-extrabold text-rose-700 hover:bg-rose-50"
                          onclick="openDelete(<?= $bId ?>, <?= json_encode($bNombre) ?>)"
                          <?= $canManage ? '' : 'disabled style="opacity:.5;cursor:not-allowed"' ?>>
                    🗑️ Eliminar
                  </button>
                </div>

                <?php if (!$canManage): ?>
                  <div class="mt-2 text-xs font-bold text-slate-500">
                    Solo el <span class="text-slate-700">propietario</span> puede editar o eliminar.
                  </div>
                <?php endif; ?>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>

  </div>

  <!-- Modal Edit -->
  <div id="modalEdit" class="hidden fixed inset-0 z-50 items-center justify-center bg-black/30 p-4">
    <div class="w-full max-w-lg rounded-3xl border border-slate-200 bg-white p-6 shadow-xl">
      <div class="flex items-start justify-between gap-3">
        <div>
          <div class="text-lg font-black text-slate-900">Editar tablero</div>
          <div class="text-sm font-semibold text-slate-500">Cambia nombre y color</div>
        </div>
        <button class="rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm font-black hover:bg-slate-50"
                onclick="closeEdit()">✕</button>
      </div>

      <form class="mt-5 space-y-3" method="post" action="update.php">
        <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['csrf']) ?>">
        <input type="hidden" name="board_id" id="edit_board_id" value="">

        <div>
          <label class="block text-xs font-extrabold text-slate-600 mb-1">Nombre</label>
          <input name="nombre" id="edit_nombre" required
                 class="w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm font-semibold outline-none focus:ring-4 focus:ring-[#d32f57]/15 focus:border-[#d32f57]/40">
        </div>

        <div>
          <label class="block text-xs font-extrabold text-slate-600 mb-1">Color</label>
          <input type="color" name="color_hex" id="edit_color_hex"
                 class="h-[46px] w-full rounded-2xl border border-slate-200 bg-white px-2 py-2">
        </div>

        <div class="flex items-center justify-end gap-2 pt-2">
          <button type="button"
                  class="rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm font-extrabold hover:bg-slate-50"
                  onclick="closeEdit()">Cancelar</button>
          <button class="rounded-2xl bg-gradient-to-br from-[#d32f57] to-[#942934] px-4 py-3 text-sm font-extrabold text-white shadow-md hover:shadow-lg transition">
            Guardar cambios
          </button>
        </div>
      </form>
    </div>
  </div>

  <!-- Modal Delete -->
  <div id="modalDelete" class="hidden fixed inset-0 z-50 items-center justify-center bg-black/30 p-4">
    <div class="w-full max-w-lg rounded-3xl border border-slate-200 bg-white p-6 shadow-xl">
      <div class="flex items-start justify-between gap-3">
        <div>
          <div class="text-lg font-black text-slate-900">Eliminar tablero</div>
          <div class="text-sm font-semibold text-slate-500">Borra tablero, columnas, tareas, comentarios, presencia y eventos.</div>
        </div>
        <button class="rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm font-black hover:bg-slate-50"
                onclick="closeDelete()">✕</button>
      </div>

      <div class="mt-4 rounded-2xl border border-rose-200 bg-rose-50 p-4">
        <div class="text-sm font-extrabold text-rose-800">
          ¿Seguro que deseas eliminar “<span id="del_board_name"></span>”?
        </div>
        <div class="mt-1 text-xs font-bold text-rose-700">Esta acción no se puede deshacer.</div>
      </div>

      <form class="mt-5" method="post" action="delete.php">
        <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['csrf']) ?>">
        <input type="hidden" name="board_id" id="del_board_id" value="">

        <div class="flex items-center justify-end gap-2">
          <button type="button"
                  class="rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm font-extrabold hover:bg-slate-50"
                  onclick="closeDelete()">Cancelar</button>

          <button class="rounded-2xl bg-rose-600 px-4 py-3 text-sm font-extrabold text-white shadow-md hover:bg-rose-700 transition">
            Sí, eliminar
          </button>
        </div>
      </form>
    </div>
  </div>

  <script>
    function toggleMenu(id){
      document.querySelectorAll('[id^="menu-"]').forEach(el => {
        if (el.id !== 'menu-' + id) el.classList.add('hidden');
      });
      const el = document.getElementById('menu-' + id);
      if (el) el.classList.toggle('hidden');
    }

    document.addEventListener('click', (e) => {
      const isBtn = e.target.closest('button');
      const isMenu = e.target.closest('[id^="menu-"]');
      if (!isBtn && !isMenu) {
        document.querySelectorAll('[id^="menu-"]').forEach(el => el.classList.add('hidden'));
      }
    });

    function openEdit(boardId, nombre, colorHex){
      document.querySelectorAll('[id^="menu-"]').forEach(el => el.classList.add('hidden'));
      document.getElementById('edit_board_id').value = boardId;
      document.getElementById('edit_nombre').value = nombre || '';
      document.getElementById('edit_color_hex').value = colorHex || '#d32f57';

      const m = document.getElementById('modalEdit');
      m.classList.remove('hidden'); m.classList.add('flex');
      setTimeout(() => document.getElementById('edit_nombre').focus(), 0);
    }
    function closeEdit(){
      const m = document.getElementById('modalEdit');
      m.classList.add('hidden'); m.classList.remove('flex');
    }

    function openDelete(boardId, nombre){
      document.querySelectorAll('[id^="menu-"]').forEach(el => el.classList.add('hidden'));
      document.getElementById('del_board_id').value = boardId;
      document.getElementById('del_board_name').textContent = nombre || '';

      const m = document.getElementById('modalDelete');
      m.classList.remove('hidden'); m.classList.add('flex');
    }
    function closeDelete(){
      const m = document.getElementById('modalDelete');
      m.classList.add('hidden'); m.classList.remove('flex');
    }

    document.addEventListener('keydown', (e) => {
      if (e.key === 'Escape') { closeEdit(); closeDelete(); }
    });
  </script>
</body>
</html>