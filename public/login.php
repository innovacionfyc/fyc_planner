<?php
// public/login.php
session_start();

// Si ya está logueado, mándalo al panel (temporal)
if (isset($_SESSION['user_id'])) {
    header('Location: app.php');
    exit;
}

// Generar token CSRF simple
if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}

// Mensajes de error opcionales
$errores = [
    '1' => 'Correo o contraseña incorrectos.',
    '2' => 'Faltan campos obligatorios.',
    '3' => 'Tu cuenta está pendiente de aprobación por el administrador.',
    '4' => 'Error de seguridad. Inténtalo de nuevo.'
];
$e = isset($_GET['e']) && isset($errores[$_GET['e']]) ? $errores[$_GET['e']] : '';
?>
<!doctype html>
<html lang="es">

<head>
    <meta charset="utf-8">
    <title>F&C Planner — Iniciar sesión</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <style>
        body {
            font-family: system-ui, -apple-system, Segoe UI, Robotto, Arial;
            background: #f7f7f7;
            margin: 0;
            display: grid;
            place-items: center;
            min-height: 100vh
        }

        .card {
            background: #fff;
            border: 1px solid #ddd;
            border-radius: 16px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, .06);
            padding: 24px;
            max-width: 380px;
            width: 92%
        }

        .h1 {
            margin: 0 0 12px;
            font-weight: 700;
            font-size: 20px;
            color: #942934
        }

        .muted {
            color: #666;
            margin: 0 0 16px
        }

        .field {
            display: flex;
            flex-direction: column;
            margin-bottom: 12px
        }

        .label {
            font-weight: 600;
            margin-bottom: 6px
        }

        .input {
            padding: 10px 12px;
            border: 1px solid #ccc;
            border-radius: 10px;
            font-size: 14px
        }

        .btn {
            width: 100%;
            padding: 12px;
            border: 0;
            border-radius: 12px;
            background: #d32f57;
            color: #fff;
            font-weight: 700;
            cursor: pointer
        }

        .btn:hover {
            filter: brightness(1.08)
        }

        .err {
            background: #ffe8ea;
            color: #8b1c2b;
            border: 1px solid #f3c3ca;
            padding: 10px;
            border-radius: 10px;
            margin-bottom: 12px
        }

        .small {
            font-size: 12px;
            color: #888;
            margin-top: 10px;
            text-align: center
        }
    </style>
</head>

<body>
    <div class="card">
        <h1 class="h1">F&C Planner</h1>
        <p class="muted">Inicia sesión para continuar.</p>

        <?php if ($e): ?>
            <div class="err"><?= htmlspecialchars($e, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endif; ?>

        <form method="post" action="auth_login.php" autocomplete="on">
            <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['csrf'], ENT_QUOTES, 'UTF-8') ?>">
            <div class="field">
                <label class="label">Correo</label>
                <input class="input" type="email" name="email" required placeholder="tucorreo@empresa.com">
            </div>
            <div class="field">
                <label class="label">Contraseña</label>
                <input class="input" type="password" name="password" required placeholder="••••••••">
            </div>
            <button class="btn" type="submit">Entrar</button>
            <div class="small">© F&C Consultores</div>
        </form>
        <div class="small" style="margin-top:12px">
            ¿No tienes cuenta? <a href="register.php">Regístrate</a>
        </div>
    </div>
</body>

</html>