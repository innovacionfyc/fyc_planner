<?php
// public/tasks/view.php
require_once __DIR__ . '/../_auth.php';
require_login();
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../_i18n.php';

$task_id = (int) ($_GET['id'] ?? 0);
if ($task_id <= 0) {
    header('Location: ../boards/index.php');
    exit;
}

/**
 * Detectar columnas reales de tasks para evitar "Unknown column ..."
 */
$taskCols = [];
$resCols = $conn->query("SHOW COLUMNS FROM tasks");
if ($resCols) {
    while ($c = $resCols->fetch_assoc()) {
        $taskCols[$c['Field']] = true;
    }
}

// Elegir columna de descripción disponible
$descExpr = "''";
if (!empty($taskCols['descripcion_md'])) {
    $descExpr = "t.descripcion_md";
} elseif (!empty($taskCols['descripcion'])) {
    $descExpr = "t.descripcion";
} elseif (!empty($taskCols['detalle'])) {
    $descExpr = "t.detalle";
} elseif (!empty($taskCols['texto'])) {
    $descExpr = "t.texto";
}

// Traer tarea + validar membresía
$sql = "SELECT t.id, t.titulo, {$descExpr} AS descripcion_md, t.prioridad, t.fecha_limite,
               t.board_id, t.column_id, t.assignee_id,
               b.nombre AS board_nombre, c.nombre AS col_nombre
        FROM tasks t
        JOIN boards b ON b.id = t.board_id
        JOIN columns c ON c.id = t.column_id
        JOIN board_members bm ON bm.board_id = b.id AND bm.user_id = ?
        WHERE t.id = ?
        LIMIT 1";

$stmt = $conn->prepare($sql);
if (!$stmt) {
    die("Error preparando consulta task: " . $conn->error);
}

$stmt->bind_param('ii', $_SESSION['user_id'], $task_id);
$stmt->execute();
$task = $stmt->get_result()->fetch_assoc();
if (!$task) {
    header('Location: ../boards/index.php');
    exit;
}

// Miembros del tablero para el selector de responsable
$ms = $conn->prepare("SELECT u.id, u.nombre
                      FROM board_members bm
                      JOIN users u ON u.id = bm.user_id
                      WHERE bm.board_id = ?
                      ORDER BY u.nombre ASC");
$ms->bind_param('i', $task['board_id']);
$ms->execute();
$members = $ms->get_result()->fetch_all(MYSQLI_ASSOC);

// CSRF
if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}

/**
 * ===== Comentarios (blindado por esquema real) =====
 * Queremos devolver SIEMPRE:
 *  - texto_md
 *  - creado_en
 */
$commentCols = [];
$resCC = $conn->query("SHOW COLUMNS FROM comments");
if ($resCC) {
    while ($c = $resCC->fetch_assoc()) {
        $commentCols[$c['Field']] = true;
    }
}

// Columna de texto
$commentTextExpr = "''";
if (!empty($commentCols['texto_md'])) {
    $commentTextExpr = "c.texto_md";
} elseif (!empty($commentCols['texto'])) {
    $commentTextExpr = "c.texto";
} elseif (!empty($commentCols['comentario'])) {
    $commentTextExpr = "c.comentario";
} elseif (!empty($commentCols['contenido'])) {
    $commentTextExpr = "c.contenido";
}

// Columna de fecha
$commentDateExpr = "c.creado_en";
if (empty($commentCols['creado_en'])) {
    if (!empty($commentCols['created_at'])) {
        $commentDateExpr = "c.created_at";
    } elseif (!empty($commentCols['fecha'])) {
        $commentDateExpr = "c.fecha";
    }
}

$comments = [];
$csSql = "SELECT c.id,
                 {$commentTextExpr} AS texto_md,
                 {$commentDateExpr} AS creado_en,
                 u.nombre
          FROM comments c
          JOIN users u ON u.id = c.user_id
          WHERE c.task_id = ?
          ORDER BY {$commentDateExpr} ASC";

$cs = $conn->prepare($csSql);
if ($cs) {
    $cs->bind_param('i', $task_id);
    $cs->execute();
    $comments = $cs->get_result()->fetch_all(MYSQLI_ASSOC);
} else {
    // no tumbamos la vista
    $comments = [];
}
?>
<!doctype html>
<html lang="es">

<head>
    <meta charset="utf-8">
    <title>
        <?= htmlspecialchars($task['titulo']) ?> — F&C Planner
    </title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <style>
        :root {
            --vino: #942934;
            --vino2: #d32f57;
        }

        body {
            font-family: system-ui;
            margin: 0;
            background: #f7f7f7
        }

        .wrap {
            max-width: 980px;
            margin: 24px auto;
            padding: 0 16px
        }

        a {
            color: var(--vino);
            text-decoration: none;
            font-weight: 600
        }

        .grid {
            display: grid;
            grid-template-columns: 1fr;
            gap: 16px
        }

        @media (min-width: 920px) {
            .grid {
                grid-template-columns: 2fr 1fr
            }
        }

        .card {
            background: #fff;
            border: 1px solid #ddd;
            border-radius: 16px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, .06);
            padding: 16px
        }

        .h1 {
            margin: 0 0 8px;
            color: var(--vino)
        }

        .muted {
            color: #777
        }

        .row {
            display: flex;
            gap: 8px;
            align-items: center;
            flex-wrap: wrap;
            margin: 8px 0
        }

        .input,
        select,
        textarea {
            width: 100%;
            box-sizing: border-box;
            padding: 10px;
            border: 1px solid #ccc;
            border-radius: 10px;
            font: inherit
        }

        .btn {
            padding: 10px 14px;
            border: 0;
            border-radius: 10px;
            background: var(--vino2);
            color: #fff;
            font-weight: 700;
            cursor: pointer
        }

        .btn:hover {
            filter: brightness(1.08)
        }

        .comment {
            border: 1px solid #eee;
            border-radius: 12px;
            padding: 10px;
            margin: 8px 0;
            background: #fff
        }

        .comment .meta {
            font-size: 12px;
            color: #777;
            margin-bottom: 6px
        }

        .md {
            white-space: pre-wrap;
            line-height: 1.45
        }

        .pill {
            display: inline-block;
            background: #eee;
            border-radius: 999px;
            padding: 4px 10px;
            font-size: 12px
        }
    </style>
</head>

<body>
    <div class="wrap">
        <div class="row" style="justify-content:space-between">
            <div><a href="../boards/view.php?id=<?= (int) $task['board_id'] ?>">← Volver al tablero</a></div>
            <div class="muted">
                <?= htmlspecialchars($task['board_nombre']) ?> · Columna: <span class="pill">
                    <?= htmlspecialchars($task['col_nombre']) ?>
                </span>
            </div>
        </div>

        <div class="grid">
            <!-- Lado izquierdo: título + descripción + propiedades -->
            <div class="card">
                <h1 class="h1">
                    <?= htmlspecialchars($task['titulo']) ?>
                </h1>

                <!-- Renombrar (rápido) -->
                <form class="row" method="post" action="../tasks/rename.php" style="margin-top:4px">
                    <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['csrf']) ?>">
                    <input type="hidden" name="board_id" value="<?= (int) $task['board_id'] ?>">
                    <input type="hidden" name="task_id" value="<?= (int) $task['id'] ?>">
                    <input class="input" type="text" name="titulo" value="<?= htmlspecialchars($task['titulo']) ?>"
                        placeholder="Nuevo título" required>
                    <button class="btn">Guardar título</button>
                </form>

                <hr style="border:none;border-top:1px solid #eee;margin:14px 0">

                <!-- Descripción + propiedades (prioridad, fecha) -->
                <form method="post" action="../tasks/update.php">
                    <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['csrf']) ?>">
                    <input type="hidden" name="board_id" value="<?= (int) $task['board_id'] ?>">
                    <input type="hidden" name="task_id" value="<?= (int) $task['id'] ?>">

                    <div class="row">
                        <label>Prioridad</label>
                        <select name="prioridad" style="max-width:180px">
                            <?php
                            $opts = ['low' => 'Baja', 'med' => 'Media', 'high' => 'Alta', 'urgent' => 'Urgente'];
                            foreach ($opts as $val => $label) {
                                $sel = ($task['prioridad'] === $val) ? 'selected' : '';
                                echo "<option value=\"$val\" $sel>$label</option>";
                            }
                            ?>
                        </select>

                        <label>Fecha límite</label>
                        <input type="date" name="fecha_limite"
                            value="<?= htmlspecialchars($task['fecha_limite'] ?? '') ?>" style="max-width:180px">
                    </div>

                    <div class="row">
                        <label>Responsable</label>
                        <select name="assignee_id" style="max-width:280px">
                            <option value="">Sin asignar</option>
                            <?php foreach ($members as $m): ?>
                                <option value="<?= (int) $m['id'] ?>" <?= ((int) ($task['assignee_id'] ?? 0) === (int) $m['id']) ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($m['nombre']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <label>Descripción (Markdown básico)</label>
                    <textarea name="descripcion_md" rows="8"
                        placeholder="Escribe detalles, checklist, notas..."><?= htmlspecialchars($task['descripcion_md'] ?? '') ?></textarea>

                    <div class="row" style="justify-content:flex-end">
                        <button class="btn" type="submit">Guardar cambios</button>
                    </div>
                </form>
            </div>

            <!-- Lado derecho: comentarios -->
            <div class="card">
                <h2 style="margin:0 0 8px;color:#333">Comentarios</h2>

                <?php if (!$comments): ?>
                    <p class="muted">Aún no hay comentarios.</p>
                <?php else: ?>
                    <?php foreach ($comments as $cm): ?>
                        <div class="comment">
                            <div class="meta">
                                <?= htmlspecialchars($cm['nombre']) ?> ·
                                <?= htmlspecialchars($cm['creado_en']) ?>
                            </div>
                            <div class="md">
                                <?= nl2br(htmlspecialchars($cm['texto_md'])) ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>

                <form method="post" action="../comments/add.php" style="margin-top:10px">
                    <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['csrf']) ?>">
                    <input type="hidden" name="board_id" value="<?= (int) $task['board_id'] ?>">
                    <input type="hidden" name="task_id" value="<?= (int) $task['id'] ?>">
                    <textarea name="texto_md" rows="4" placeholder="Escribe un comentario (@menciones vendrán luego)"
                        required></textarea>
                    <div class="row" style="justify-content:flex-end">
                        <button class="btn" type="submit">Comentar</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</body>

</html>