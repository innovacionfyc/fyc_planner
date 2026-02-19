<?php
// public/boards/index.php
require_once __DIR__ . '/../_auth.php';
require_login();
require_once __DIR__ . '/../../config/db.php';

if (session_status() !== PHP_SESSION_ACTIVE)
  session_start();
if (empty($_SESSION['csrf']))
  $_SESSION['csrf'] = bin2hex(random_bytes(16));

$user_id = (int) ($_SESSION['user_id'] ?? 0);
if ($user_id <= 0) {
  header('Location: ../login.php');
  exit;
}

function h($s)
{
  return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
}

// -----------------------------
// Detectar columna de archivado
// -----------------------------
$archiveMode = null; // 'archived_at' | 'flag'
$archiveCol = null;

$cols = [];
$res = $conn->query("SHOW COLUMNS FROM boards");
if ($res) {
  while ($r = $res->fetch_assoc())
    $cols[] = strtolower($r['Field']);
  $res->free();
}

if (in_array('archived_at', $cols, true)) {
  $archiveMode = 'archived_at';
  $archiveCol = 'archived_at';
} elseif (in_array('is_archived', $cols, true)) {
  $archiveMode = 'flag';
  $archiveCol = 'is_archived';
} elseif (in_array('archived', $cols, true)) {
  $archiveMode = 'flag';
  $archiveCol = 'archived';
} else {
  // fallback: si no existe nada, asumimos no archivado
  $archiveMode = 'none';
  $archiveCol = null;
}

function archiveWhere($mode, $col, $archivedWanted)
{
  // $archivedWanted: true => archivados, false => activos
  if ($mode === 'archived_at') {
    return $archivedWanted ? "b.`$col` IS NOT NULL" : "b.`$col` IS NULL";
  }
  if ($mode === 'flag') {
    return $archivedWanted ? "b.`$col`=1" : "(b.`$col`=0 OR b.`$col` IS NULL)";
  }
  return $archivedWanted ? "1=0" : "1=1";
}

$wActive = archiveWhere($archiveMode, $archiveCol, false);
$wArchived = archiveWhere($archiveMode, $archiveCol, true);

// -----------------------------
// Consultas
// - Personales: boards.team_id IS NULL y miembro (board_members) o creador
// - Equipos:   boards.team_id IS NOT NULL y miembro (board_members)
// Nota: si tu sistema no usa created_by, igual funciona por board_members.
// -----------------------------
$hasCreatedBy = in_array('created_by', $cols, true);

$personalWhereMember = "EXISTS (SELECT 1 FROM board_members bm WHERE bm.board_id=b.id AND bm.user_id={$user_id})";
$creatorClause = $hasCreatedBy ? " OR b.created_by={$user_id}" : "";
$personalBaseWhere = "(b.team_id IS NULL AND ({$personalWhereMember}{$creatorClause}))";

$teamBaseWhere = "(b.team_id IS NOT NULL AND EXISTS (SELECT 1 FROM board_members bm WHERE bm.board_id=b.id AND bm.user_id={$user_id}))";

// Traer rol desde board_members (si existe), si no, null
function fetchBoards($conn, $whereBase, $whereArchive, $user_id)
{
  $sql = "
    SELECT
      b.id, b.nombre, b.color_hex, b.team_id,
      COALESCE(bm.rol, '') AS my_role
    FROM boards b
    LEFT JOIN board_members bm
      ON bm.board_id=b.id AND bm.user_id={$user_id}
    WHERE {$whereBase} AND {$whereArchive}
    ORDER BY b.created_at DESC, b.id DESC
  ";
  $out = [];
  $res = $conn->query($sql);
  if ($res) {
    while ($r = $res->fetch_assoc())
      $out[] = $r;
    $res->free();
  }
  return $out;
}

$personalActive = fetchBoards($conn, $personalBaseWhere, $wActive, $user_id);
$personalArchived = fetchBoards($conn, $personalBaseWhere, $wArchived, $user_id);
$teamActive = fetchBoards($conn, $teamBaseWhere, $wActive, $user_id);
$teamArchived = fetchBoards($conn, $teamBaseWhere, $wArchived, $user_id);

// Traer nombres de equipos (para mostrar)
$teamsById = [];
$resT = $conn->query("SELECT id, nombre FROM teams");
if ($resT) {
  while ($r = $resT->fetch_assoc())
    $teamsById[(int) $r['id']] = $r['nombre'];
  $resT->free();
}

// Helpers UI
function badgeRole($roleRaw)
{
  $role = strtolower(trim((string) $roleRaw));
  if ($role === 'propietario' || $role === 'owner')
    return ['Propietario', 'bg-[#942934] text-white'];
  if ($role === 'admin_equipo' || $role === 'admin')
    return ['Admin', 'bg-[#d32f57] text-white'];
  if ($role === 'miembro' || $role === 'member')
    return ['Miembro', 'bg-[#f3e3e7] text-[#942934] border border-[#d32f57]/30'];
  return ['Mi rol', 'bg-gray-100 text-gray-700 border border-gray-200'];
}
?>
<!doctype html>
<html lang="es">

<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>F&C Planner · Tableros</title>

  <!-- Ajusta a tu ruta real de Tailwind compilado -->
  <link rel="stylesheet" href="../assets/app.css">
</head>

<body class="min-h-screen bg-[#f7f4f5] text-gray-900">
  <!-- manchas vinotinto suaves -->
  <div class="pointer-events-none fixed inset-0 -z-10">
    <div class="absolute -top-24 -left-24 h-72 w-72 rounded-full blur-3xl opacity-25 bg-[#d32f57]"></div>
    <div class="absolute top-40 -right-24 h-72 w-72 rounded-full blur-3xl opacity-20 bg-[#942934]"></div>
    <div class="absolute bottom-0 left-1/3 h-80 w-80 rounded-full blur-3xl opacity-15 bg-[#d32f57]"></div>
  </div>

  <div class="mx-auto max-w-6xl px-4 py-8">
    <header class="flex flex-col gap-4 md:flex-row md:items-center md:justify-between">
      <div>
        <h1 class="text-2xl md:text-3xl font-black tracking-tight">
          Tableros · <span class="text-[#942934]">F&amp;C Planner</span>
        </h1>
        <p class="mt-1 text-sm text-gray-600">
          Personales y de equipo. Acciones rápidas por iconos (sin menús raros).
        </p>
      </div>

      <button type="button"
        class="inline-flex items-center justify-center gap-2 rounded-2xl bg-[#d32f57] px-5 py-3 text-sm font-bold text-white shadow-lg shadow-[#d32f57]/20 transition-all duration-300 hover:scale-[1.01] active:scale-[0.98]"
        onclick="openCreate()" title="Crear tablero">
        <span class="inline-flex h-5 w-5 items-center justify-center">
          <!-- plus -->
          <svg viewBox="0 0 24 24" fill="none" class="h-5 w-5" stroke="currentColor" stroke-width="2">
            <path d="M12 5v14M5 12h14" stroke-linecap="round" />
          </svg>
        </span>
        Crear tablero
      </button>
    </header>

    <!-- Sección: Personales activos -->
    <section class="mt-8">
      <div class="flex items-end justify-between">
        <h2 class="text-lg font-black text-gray-900">Personales</h2>
        <span class="text-xs text-gray-500"><?= (int) count($personalActive) ?> activos</span>
      </div>

      <div class="mt-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
        <?php if (!count($personalActive)): ?>
          <div class="rounded-3xl border border-gray-200 bg-white p-6 shadow-sm">
            <p class="text-sm text-gray-600">No tienes tableros personales activos.</p>
          </div>
        <?php endif; ?>

        <?php foreach ($personalActive as $b): ?>
          <?php [$roleTxt, $roleCls] = badgeRole($b['my_role']); ?>
          <article class="rounded-3xl border border-gray-200 bg-white p-6 shadow-sm">
            <div class="flex items-start justify-between gap-3">
              <div class="min-w-0">
                <div class="flex items-center gap-2">
                  <span class="inline-block h-3.5 w-3.5 rounded-full"
                    style="background: <?= h($b['color_hex'] ?: '#d32f57') ?>;"></span>
                  <h3 class="truncate text-base font-black"><?= h($b['nombre']) ?></h3>
                </div>
                <div class="mt-2 flex flex-wrap items-center gap-2">
                  <span class="inline-flex items-center rounded-full px-2.5 py-1 text-xs font-bold <?= h($roleCls) ?>">
                    <?= h($roleTxt) ?>
                  </span>
                  <span class="text-xs text-gray-500">Personal</span>
                </div>
              </div>

              <!-- Acciones (iconos) -->
              <div class="flex shrink-0 items-center gap-1">
                <button class="iconBtn" type="button" title="Editar" data-action="edit" data-id="<?= (int) $b['id'] ?>"
                  data-name="<?= h($b['nombre']) ?>" data-color="<?= h($b['color_hex'] ?: '#d32f57') ?>">
                  <!-- pencil -->
                  <svg viewBox="0 0 24 24" fill="none" class="h-4 w-4" stroke="currentColor" stroke-width="2">
                    <path d="M12 20h9" stroke-linecap="round" />
                    <path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z" stroke-linejoin="round" />
                  </svg>
                </button>

                <button class="iconBtn" type="button" title="Duplicar" data-action="dup" data-id="<?= (int) $b['id'] ?>"
                  data-name="<?= h($b['nombre']) ?>">
                  <!-- copy -->
                  <svg viewBox="0 0 24 24" fill="none" class="h-4 w-4" stroke="currentColor" stroke-width="2">
                    <path d="M8 8h12v12H8z" stroke-linejoin="round" />
                    <path d="M4 16H3a1 1 0 0 1-1-1V3a1 1 0 0 1 1-1h12a1 1 0 0 1 1 1v1" stroke-linecap="round" />
                  </svg>
                </button>

                <button class="iconBtn" type="button" title="Archivar" data-action="arc" data-id="<?= (int) $b['id'] ?>"
                  data-name="<?= h($b['nombre']) ?>">
                  <!-- archive/box -->
                  <svg viewBox="0 0 24 24" fill="none" class="h-4 w-4" stroke="currentColor" stroke-width="2">
                    <path d="M21 8v13H3V8" stroke-linejoin="round" />
                    <path d="M1 3h22v5H1z" stroke-linejoin="round" />
                    <path d="M10 12h4" stroke-linecap="round" />
                  </svg>
                </button>

                <button class="iconBtnDanger" type="button" title="Eliminar" data-action="del"
                  data-id="<?= (int) $b['id'] ?>" data-name="<?= h($b['nombre']) ?>">
                  <!-- trash -->
                  <svg viewBox="0 0 24 24" fill="none" class="h-4 w-4" stroke="currentColor" stroke-width="2">
                    <path d="M3 6h18" stroke-linecap="round" />
                    <path d="M8 6V4h8v2" stroke-linejoin="round" />
                    <path d="M6 6l1 16h10l1-16" stroke-linejoin="round" />
                  </svg>
                </button>
              </div>
            </div>

            <div class="mt-5">
              <a href="./view.php?id=<?= (int) $b['id'] ?>"
                class="inline-flex w-full items-center justify-center rounded-2xl border border-[#d32f57]/25 bg-[#f9eef1] px-4 py-2.5 text-sm font-black text-[#942934] transition-all duration-300 hover:scale-[1.01] active:scale-[0.98]">
                Abrir tablero
              </a>
            </div>
          </article>
        <?php endforeach; ?>
      </div>

      <!-- Archivados personales -->
      <details class="mt-6 rounded-3xl border border-gray-200 bg-white p-5 shadow-sm">
        <summary class="cursor-pointer select-none text-sm font-black text-gray-800">
          Archivados personales (<?= (int) count($personalArchived) ?>)
        </summary>

        <div class="mt-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
          <?php if (!count($personalArchived)): ?>
            <div class="rounded-3xl border border-gray-200 bg-white p-6">
              <p class="text-sm text-gray-600">No hay archivados personales.</p>
            </div>
          <?php endif; ?>

          <?php foreach ($personalArchived as $b): ?>
            <article class="rounded-3xl border border-gray-200 bg-white p-6 shadow-sm">
              <div class="flex items-start justify-between gap-3">
                <div class="min-w-0">
                  <div class="flex items-center gap-2">
                    <span class="inline-block h-3.5 w-3.5 rounded-full"
                      style="background: <?= h($b['color_hex'] ?: '#d32f57') ?>;"></span>
                    <h3 class="truncate text-base font-black"><?= h($b['nombre']) ?></h3>
                  </div>
                  <p class="mt-2 text-xs text-gray-500">Personal · Archivado</p>
                </div>

                <div class="flex shrink-0 items-center gap-1">
                  <button class="iconBtn" type="button" title="Restaurar" data-action="res"
                    data-id="<?= (int) $b['id'] ?>" data-name="<?= h($b['nombre']) ?>">
                    <!-- rotate -->
                    <svg viewBox="0 0 24 24" fill="none" class="h-4 w-4" stroke="currentColor" stroke-width="2">
                      <path d="M3 12a9 9 0 0 1 15.5-6.5" stroke-linecap="round" />
                      <path d="M18 3v6h-6" stroke-linejoin="round" />
                      <path d="M21 12a9 9 0 0 1-15.5 6.5" stroke-linecap="round" />
                      <path d="M6 21v-6h6" stroke-linejoin="round" />
                    </svg>
                  </button>

                  <button class="iconBtnDanger" type="button" title="Eliminar" data-action="del"
                    data-id="<?= (int) $b['id'] ?>" data-name="<?= h($b['nombre']) ?>">
                    <svg viewBox="0 0 24 24" fill="none" class="h-4 w-4" stroke="currentColor" stroke-width="2">
                      <path d="M3 6h18" stroke-linecap="round" />
                      <path d="M8 6V4h8v2" stroke-linejoin="round" />
                      <path d="M6 6l1 16h10l1-16" stroke-linejoin="round" />
                    </svg>
                  </button>
                </div>
              </div>
            </article>
          <?php endforeach; ?>
        </div>
      </details>
    </section>

    <!-- Sección: Equipos activos -->
    <section class="mt-10">
      <div class="flex items-end justify-between">
        <h2 class="text-lg font-black text-gray-900">Equipos</h2>
        <span class="text-xs text-gray-500"><?= (int) count($teamActive) ?> activos</span>
      </div>

      <div class="mt-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
        <?php if (!count($teamActive)): ?>
          <div class="rounded-3xl border border-gray-200 bg-white p-6 shadow-sm">
            <p class="text-sm text-gray-600">No tienes tableros de equipo activos.</p>
          </div>
        <?php endif; ?>

        <?php foreach ($teamActive as $b): ?>
          <?php [$roleTxt, $roleCls] = badgeRole($b['my_role']); ?>
          <?php $teamName = $b['team_id'] ? ($teamsById[(int) $b['team_id']] ?? ('Equipo #' . (int) $b['team_id'])) : 'Equipo'; ?>
          <article class="rounded-3xl border border-gray-200 bg-white p-6 shadow-sm">
            <div class="flex items-start justify-between gap-3">
              <div class="min-w-0">
                <div class="flex items-center gap-2">
                  <span class="inline-block h-3.5 w-3.5 rounded-full"
                    style="background: <?= h($b['color_hex'] ?: '#d32f57') ?>;"></span>
                  <h3 class="truncate text-base font-black"><?= h($b['nombre']) ?></h3>
                </div>
                <div class="mt-2 flex flex-wrap items-center gap-2">
                  <span class="inline-flex items-center rounded-full px-2.5 py-1 text-xs font-bold <?= h($roleCls) ?>">
                    <?= h($roleTxt) ?>
                  </span>
                  <span class="text-xs text-gray-500"><?= h($teamName) ?></span>
                </div>
              </div>

              <div class="flex shrink-0 items-center gap-1">
                <button class="iconBtn" type="button" title="Editar" data-action="edit" data-id="<?= (int) $b['id'] ?>"
                  data-name="<?= h($b['nombre']) ?>" data-color="<?= h($b['color_hex'] ?: '#d32f57') ?>">
                  <svg viewBox="0 0 24 24" fill="none" class="h-4 w-4" stroke="currentColor" stroke-width="2">
                    <path d="M12 20h9" stroke-linecap="round" />
                    <path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z" stroke-linejoin="round" />
                  </svg>
                </button>

                <button class="iconBtn" type="button" title="Duplicar" data-action="dup" data-id="<?= (int) $b['id'] ?>"
                  data-name="<?= h($b['nombre']) ?>">
                  <svg viewBox="0 0 24 24" fill="none" class="h-4 w-4" stroke="currentColor" stroke-width="2">
                    <path d="M8 8h12v12H8z" stroke-linejoin="round" />
                    <path d="M4 16H3a1 1 0 0 1-1-1V3a1 1 0 0 1 1-1h12a1 1 0 0 1 1 1v1" stroke-linecap="round" />
                  </svg>
                </button>

                <button class="iconBtn" type="button" title="Archivar" data-action="arc" data-id="<?= (int) $b['id'] ?>"
                  data-name="<?= h($b['nombre']) ?>">
                  <svg viewBox="0 0 24 24" fill="none" class="h-4 w-4" stroke="currentColor" stroke-width="2">
                    <path d="M21 8v13H3V8" stroke-linejoin="round" />
                    <path d="M1 3h22v5H1z" stroke-linejoin="round" />
                    <path d="M10 12h4" stroke-linecap="round" />
                  </svg>
                </button>

                <button class="iconBtnDanger" type="button" title="Eliminar" data-action="del"
                  data-id="<?= (int) $b['id'] ?>" data-name="<?= h($b['nombre']) ?>">
                  <svg viewBox="0 0 24 24" fill="none" class="h-4 w-4" stroke="currentColor" stroke-width="2">
                    <path d="M3 6h18" stroke-linecap="round" />
                    <path d="M8 6V4h8v2" stroke-linejoin="round" />
                    <path d="M6 6l1 16h10l1-16" stroke-linejoin="round" />
                  </svg>
                </button>
              </div>
            </div>

            <div class="mt-5">
              <a href="./view.php?id=<?= (int) $b['id'] ?>"
                class="inline-flex w-full items-center justify-center rounded-2xl border border-[#d32f57]/25 bg-[#f9eef1] px-4 py-2.5 text-sm font-black text-[#942934] transition-all duration-300 hover:scale-[1.01] active:scale-[0.98]">
                Abrir tablero
              </a>
            </div>
          </article>
        <?php endforeach; ?>
      </div>

      <!-- Archivados equipo -->
      <details class="mt-6 rounded-3xl border border-gray-200 bg-white p-5 shadow-sm">
        <summary class="cursor-pointer select-none text-sm font-black text-gray-800">
          Archivados de equipos (<?= (int) count($teamArchived) ?>)
        </summary>

        <div class="mt-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
          <?php if (!count($teamArchived)): ?>
            <div class="rounded-3xl border border-gray-200 bg-white p-6">
              <p class="text-sm text-gray-600">No hay archivados de equipo.</p>
            </div>
          <?php endif; ?>

          <?php foreach ($teamArchived as $b): ?>
            <?php $teamName = $b['team_id'] ? ($teamsById[(int) $b['team_id']] ?? ('Equipo #' . (int) $b['team_id'])) : 'Equipo'; ?>
            <article class="rounded-3xl border border-gray-200 bg-white p-6 shadow-sm">
              <div class="flex items-start justify-between gap-3">
                <div class="min-w-0">
                  <div class="flex items-center gap-2">
                    <span class="inline-block h-3.5 w-3.5 rounded-full"
                      style="background: <?= h($b['color_hex'] ?: '#d32f57') ?>;"></span>
                    <h3 class="truncate text-base font-black"><?= h($b['nombre']) ?></h3>
                  </div>
                  <p class="mt-2 text-xs text-gray-500"><?= h($teamName) ?> · Archivado</p>
                </div>

                <div class="flex shrink-0 items-center gap-1">
                  <button class="iconBtn" type="button" title="Restaurar" data-action="res"
                    data-id="<?= (int) $b['id'] ?>" data-name="<?= h($b['nombre']) ?>">
                    <svg viewBox="0 0 24 24" fill="none" class="h-4 w-4" stroke="currentColor" stroke-width="2">
                      <path d="M3 12a9 9 0 0 1 15.5-6.5" stroke-linecap="round" />
                      <path d="M18 3v6h-6" stroke-linejoin="round" />
                      <path d="M21 12a9 9 0 0 1-15.5 6.5" stroke-linecap="round" />
                      <path d="M6 21v-6h6" stroke-linejoin="round" />
                    </svg>
                  </button>

                  <button class="iconBtnDanger" type="button" title="Eliminar" data-action="del"
                    data-id="<?= (int) $b['id'] ?>" data-name="<?= h($b['nombre']) ?>">
                    <svg viewBox="0 0 24 24" fill="none" class="h-4 w-4" stroke="currentColor" stroke-width="2">
                      <path d="M3 6h18" stroke-linecap="round" />
                      <path d="M8 6V4h8v2" stroke-linejoin="round" />
                      <path d="M6 6l1 16h10l1-16" stroke-linejoin="round" />
                    </svg>
                  </button>
                </div>
              </div>
            </article>
          <?php endforeach; ?>
        </div>
      </details>
    </section>
  </div>

  <!-- =========================
       MODALES
  ========================== -->

  <!-- Modal Create -->
  <div id="modalCreate" class="modal hidden" aria-hidden="true">
    <div class="modalBackdrop"></div>
    <div class="modalCard">
      <div class="flex items-start justify-between gap-3">
        <h3 class="text-lg font-black text-gray-900">Crear tablero</h3>
        <button type="button" class="modalX" onclick="closeModal('modalCreate')" title="Cerrar">✕</button>
      </div>

      <form class="mt-4 space-y-4" method="POST" action="./create.php">
        <input type="hidden" name="csrf" value="<?= h($_SESSION['csrf']) ?>">

        <div>
          <label class="block text-xs font-black text-gray-700">Nombre</label>
          <input type="text" name="nombre" required
            class="mt-1 w-full rounded-2xl border border-gray-300 bg-white p-3 text-sm placeholder:text-gray-500 placeholder:font-medium transition-all duration-300 focus:ring-2 focus:ring-[#d32f57]"
            placeholder="Ej: Planeación semanal">
        </div>

        <div>
          <label class="block text-xs font-black text-gray-700">Color</label>
          <input type="text" name="color_hex"
            class="mt-1 w-full rounded-2xl border border-gray-300 bg-white p-3 text-sm placeholder:text-gray-500 placeholder:font-medium transition-all duration-300 focus:ring-2 focus:ring-[#d32f57]"
            value="#d32f57">
          <p class="mt-1 text-xs text-gray-500">Formato HEX, ej: #d32f57</p>
        </div>

        <div class="flex items-center justify-end gap-2 pt-2">
          <button type="button" class="btnSoft" onclick="closeModal('modalCreate')">Cancelar</button>
          <button type="submit" class="btnPrimary">Crear</button>
        </div>
      </form>
    </div>
  </div>

  <!-- Modal Edit -->
  <div id="modalEdit" class="modal hidden" aria-hidden="true">
    <div class="modalBackdrop"></div>
    <div class="modalCard">
      <div class="flex items-start justify-between gap-3">
        <h3 class="text-lg font-black text-gray-900">Editar tablero</h3>
        <button type="button" class="modalX" onclick="closeModal('modalEdit')" title="Cerrar">✕</button>
      </div>

      <form class="mt-4 space-y-4" method="POST" action="./update.php">
        <input type="hidden" name="csrf" value="<?= h($_SESSION['csrf']) ?>">
        <input type="hidden" id="edit_board_id" name="board_id" value="">

        <div>
          <label class="block text-xs font-black text-gray-700">Nombre</label>
          <input id="edit_nombre" type="text" name="nombre" required
            class="mt-1 w-full rounded-2xl border border-gray-300 bg-white p-3 text-sm placeholder:text-gray-500 placeholder:font-medium transition-all duration-300 focus:ring-2 focus:ring-[#d32f57]">
        </div>

        <div>
          <label class="block text-xs font-black text-gray-700">Color HEX</label>
          <input id="edit_color_hex" type="text" name="color_hex"
            class="mt-1 w-full rounded-2xl border border-gray-300 bg-white p-3 text-sm placeholder:text-gray-500 placeholder:font-medium transition-all duration-300 focus:ring-2 focus:ring-[#d32f57]">
        </div>

        <div class="flex items-center justify-end gap-2 pt-2">
          <button type="button" class="btnSoft" onclick="closeModal('modalEdit')">Cancelar</button>
          <button type="submit" class="btnPrimary">Guardar</button>
        </div>
      </form>
    </div>
  </div>

  <!-- Modal Delete -->
  <div id="modalDelete" class="modal hidden" aria-hidden="true">
    <div class="modalBackdrop"></div>
    <div class="modalCard">
      <div class="flex items-start justify-between gap-3">
        <h3 class="text-lg font-black text-gray-900">Eliminar tablero</h3>
        <button type="button" class="modalX" onclick="closeModal('modalDelete')" title="Cerrar">✕</button>
      </div>

      <p class="mt-3 text-sm text-gray-600">
        Vas a eliminar: <span id="del_board_name" class="font-black text-[#942934]"></span>
      </p>

      <form class="mt-4" method="POST" action="./delete.php">
        <input type="hidden" name="csrf" value="<?= h($_SESSION['csrf']) ?>">
        <input type="hidden" id="del_board_id" name="board_id" value="">
        <div class="flex items-center justify-end gap-2 pt-2">
          <button type="button" class="btnSoft" onclick="closeModal('modalDelete')">Cancelar</button>
          <button type="submit" class="btnDanger">Eliminar</button>
        </div>
      </form>
    </div>
  </div>

  <!-- Modal Duplicate -->
  <div id="modalDuplicate" class="modal hidden" aria-hidden="true">
    <div class="modalBackdrop"></div>
    <div class="modalCard">
      <div class="flex items-start justify-between gap-3">
        <h3 class="text-lg font-black text-gray-900">Duplicar tablero</h3>
        <button type="button" class="modalX" onclick="closeModal('modalDuplicate')" title="Cerrar">✕</button>
      </div>

      <p class="mt-3 text-sm text-gray-600">
        Duplicar: <span id="dup_board_name" class="font-black text-[#942934]"></span>
      </p>

      <form class="mt-4" method="POST" action="./duplicate.php">
        <input type="hidden" name="csrf" value="<?= h($_SESSION['csrf']) ?>">
        <input type="hidden" id="dup_board_id" name="board_id" value="">
        <div class="flex items-center justify-end gap-2 pt-2">
          <button type="button" class="btnSoft" onclick="closeModal('modalDuplicate')">Cancelar</button>
          <button type="submit" class="btnPrimary">Duplicar</button>
        </div>
      </form>
    </div>
  </div>

  <!-- Modal Archive -->
  <div id="modalArchive" class="modal hidden" aria-hidden="true">
    <div class="modalBackdrop"></div>
    <div class="modalCard">
      <div class="flex items-start justify-between gap-3">
        <h3 class="text-lg font-black text-gray-900">Archivar tablero</h3>
        <button type="button" class="modalX" onclick="closeModal('modalArchive')" title="Cerrar">✕</button>
      </div>

      <p class="mt-3 text-sm text-gray-600">
        Archivar: <span id="arc_board_name" class="font-black text-[#942934]"></span>
      </p>

      <form class="mt-4" method="POST" action="./archive.php">
        <input type="hidden" name="csrf" value="<?= h($_SESSION['csrf']) ?>">
        <input type="hidden" id="arc_board_id" name="board_id" value="">
        <div class="flex items-center justify-end gap-2 pt-2">
          <button type="button" class="btnSoft" onclick="closeModal('modalArchive')">Cancelar</button>
          <button type="submit" class="btnPrimary">Archivar</button>
        </div>
      </form>
    </div>
  </div>

  <!-- Modal Restore -->
  <div id="modalRestore" class="modal hidden" aria-hidden="true">
    <div class="modalBackdrop"></div>
    <div class="modalCard">
      <div class="flex items-start justify-between gap-3">
        <h3 class="text-lg font-black text-gray-900">Restaurar tablero</h3>
        <button type="button" class="modalX" onclick="closeModal('modalRestore')" title="Cerrar">✕</button>
      </div>

      <p class="mt-3 text-sm text-gray-600">
        Restaurar: <span id="res_board_name" class="font-black text-[#942934]"></span>
      </p>

      <form class="mt-4" method="POST" action="./restore.php">
        <input type="hidden" name="csrf" value="<?= h($_SESSION['csrf']) ?>">
        <input type="hidden" id="res_board_id" name="board_id" value="">
        <div class="flex items-center justify-end gap-2 pt-2">
          <button type="button" class="btnSoft" onclick="closeModal('modalRestore')">Cancelar</button>
          <button type="submit" class="btnPrimary">Restaurar</button>
        </div>
      </form>
    </div>
  </div>

  <!-- =========================
       ESTILOS MINI (solo para modales/botones)
       (si prefieres, pásalo a tu app.css)
  ========================== -->
  <style>
    .iconBtn {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      width: 34px;
      height: 34px;
      border-radius: 14px;
      border: 1px solid rgba(211, 47, 87, .20);
      background: rgba(249, 238, 241, .9);
      color: #942934;
      transition: transform .2s ease, box-shadow .2s ease, background .2s ease;
    }

    .iconBtn:hover {
      transform: scale(1.03);
      box-shadow: 0 10px 25px rgba(148, 41, 52, .12);
      background: rgba(249, 238, 241, 1);
    }

    .iconBtn:active {
      transform: scale(.98);
    }

    .iconBtnDanger {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      width: 34px;
      height: 34px;
      border-radius: 14px;
      border: 1px solid rgba(220, 38, 38, .20);
      background: rgba(254, 242, 242, .9);
      color: rgb(185, 28, 28);
      transition: transform .2s ease, box-shadow .2s ease, background .2s ease;
    }

    .iconBtnDanger:hover {
      transform: scale(1.03);
      box-shadow: 0 10px 25px rgba(185, 28, 28, .10);
      background: rgba(254, 242, 242, 1);
    }

    .iconBtnDanger:active {
      transform: scale(.98);
    }

    .modal {
      position: fixed;
      inset: 0;
      z-index: 50;
      display: grid;
      place-items: center;
      padding: 16px;
    }

    .modalBackdrop {
      position: absolute;
      inset: 0;
      background: rgba(17, 24, 39, .45);
      backdrop-filter: blur(2px);
    }

    .modalCard {
      position: relative;
      width: 100%;
      max-width: 520px;
      border-radius: 24px;
      border: 1px solid rgba(229, 231, 235, 1);
      background: white;
      padding: 20px;
      box-shadow: 0 30px 70px rgba(0, 0, 0, .18);
    }

    .modalX {
      width: 38px;
      height: 38px;
      border-radius: 16px;
      border: 1px solid rgba(229, 231, 235, 1);
      background: white;
      font-weight: 900;
      color: #111827;
    }

    .btnSoft {
      border-radius: 16px;
      border: 1px solid rgba(229, 231, 235, 1);
      background: white;
      padding: 10px 14px;
      font-weight: 900;
      font-size: 14px;
      transition: transform .2s ease;
    }

    .btnSoft:hover {
      transform: scale(1.01);
    }

    .btnPrimary {
      border-radius: 16px;
      background: #d32f57;
      color: white;
      padding: 10px 14px;
      font-weight: 900;
      font-size: 14px;
      box-shadow: 0 12px 28px rgba(211, 47, 87, .22);
      transition: transform .2s ease;
    }

    .btnPrimary:hover {
      transform: scale(1.01);
    }

    .btnDanger {
      border-radius: 16px;
      background: rgb(185, 28, 28);
      color: white;
      padding: 10px 14px;
      font-weight: 900;
      font-size: 14px;
      box-shadow: 0 12px 28px rgba(185, 28, 28, .20);
      transition: transform .2s ease;
    }

    .btnDanger:hover {
      transform: scale(1.01);
    }

    .hidden {
      display: none !important;
    }
  </style>

  <!-- =========================
       JS (sin cortes / sin template strings)
       -> Evita "Unexpected end of input"
  ========================== -->
  <script>
    (function () {
      'use strict';

      function byId(id) { return document.getElementById(id); }

      function openModal(id) {
        var el = byId(id);
        if (!el) return;
        el.classList.remove('hidden');
        el.setAttribute('aria-hidden', 'false');
      }

      function closeModal(id) {
        var el = byId(id);
        if (!el) return;
        el.classList.add('hidden');
        el.setAttribute('aria-hidden', 'true');
      }

      // Cerrar por backdrop + Esc
      function wireModalClose(modalId) {
        var m = byId(modalId);
        if (!m) return;
        var backdrop = m.querySelector('.modalBackdrop');
        if (backdrop) {
          backdrop.addEventListener('click', function () { closeModal(modalId); });
        }
      }

      // Exponer closeModal global (por onclick en HTML)
      window.closeModal = closeModal;

      // Crear
      window.openCreate = function () {
        openModal('modalCreate');
      };

      // Edit
      window.openEdit = function (id, name, colorHex) {
        byId('edit_board_id').value = String(id || '');
        byId('edit_nombre').value = name || '';
        byId('edit_color_hex').value = colorHex || '#d32f57';
        openModal('modalEdit');
      };

      // Delete
      window.openDelete = function (id, name) {
        byId('del_board_id').value = String(id || '');
        byId('del_board_name').textContent = name || '';
        openModal('modalDelete');
      };

      // Duplicate
      window.openDuplicate = function (id, name) {
        byId('dup_board_id').value = String(id || '');
        byId('dup_board_name').textContent = name || '';
        openModal('modalDuplicate');
      };

      // Archive
      window.openArchive = function (id, name) {
        byId('arc_board_id').value = String(id || '');
        byId('arc_board_name').textContent = name || '';
        openModal('modalArchive');
      };

      // Restore
      window.openRestore = function (id, name) {
        byId('res_board_id').value = String(id || '');
        byId('res_board_name').textContent = name || '';
        openModal('modalRestore');
      };

      // Delegación: botones iconos
      document.addEventListener('click', function (ev) {
        var btn = ev.target.closest('button[data-action]');
        if (!btn) return;

        var action = btn.getAttribute('data-action');
        var id = btn.getAttribute('data-id');
        var name = btn.getAttribute('data-name') || '';
        var color = btn.getAttribute('data-color') || '#d32f57';

        if (action === 'edit') window.openEdit(id, name, color);
        else if (action === 'del') window.openDelete(id, name);
        else if (action === 'dup') window.openDuplicate(id, name);
        else if (action === 'arc') window.openArchive(id, name);
        else if (action === 'res') window.openRestore(id, name);
      });

      // Cierres
      wireModalClose('modalCreate');
      wireModalClose('modalEdit');
      wireModalClose('modalDelete');
      wireModalClose('modalDuplicate');
      wireModalClose('modalArchive');
      wireModalClose('modalRestore');

      document.addEventListener('keydown', function (e) {
        if (e.key !== 'Escape') return;
        closeModal('modalCreate');
        closeModal('modalEdit');
        closeModal('modalDelete');
        closeModal('modalDuplicate');
        closeModal('modalArchive');
        closeModal('modalRestore');
      });

    })();
  </script>
</body>

</html>