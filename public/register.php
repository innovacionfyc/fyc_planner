<?php
// public/register.php
session_start();

// Si ya está logueado, redirige
if (isset($_SESSION['user_id'])) {
    header('Location: boards/index.php');
    exit;
}

// CSRF
if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}

// Mensajes
$msg = '';
if (isset($_GET['ok']) && $_GET['ok'] === '1') {
    $msg = '✅ Registro enviado. Espera aprobación del administrador.';
}
$errMap = [
    '1' => 'Faltan datos.',
    '2' => 'El correo debe ser @fycconsultores.com.',
    '3' => 'Las contraseñas no coinciden.',
    '4' => 'La contraseña debe tener al menos 8 caracteres.',
    '5' => 'Ese correo ya existe.',
    '6' => 'Hubo un error al registrar. Intenta de nuevo.',
];
$err = (isset($_GET['e']) && isset($errMap[$_GET['e']])) ? $errMap[$_GET['e']] : '';
?>
<!doctype html>
<html lang="es">

<head>
    <meta charset="utf-8">
    <title>F&C Planner — Registrarme</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <style>
        body {
            font-family: system-ui;
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
            max-width: 420px;
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

        .ok {
            background: #e6ffed;
            color: #03543f;
            border: 1px solid #84e1bc;
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

        a {
            color: #942934;
            text-decoration: none;
            font-weight: 600
        }
    </style>
</head>

<body>
    <div class="card">
        <h1 class="h1">Crear cuenta</h1>
        <p class="muted">Solo correos corporativos <strong>@fycconsultores.com</strong>. Tu cuenta quedará pendiente de
            aprobación.</p>

        <?php if ($msg): ?>
            <div class="ok"><?= htmlspecialchars($msg) ?></div><?php endif; ?>
        <?php if ($err): ?>
            <div class="err"><?= htmlspecialchars($err) ?></div><?php endif; ?>

        <form method="post" action="auth_register.php" autocomplete="on">
            <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['csrf'], ENT_QUOTES, 'UTF-8') ?>">
            <div class="field">
                <label class="label">Nombre</label>
                <input class="input" type="text" name="nombre" required placeholder="Tu nombre y apellido">
            </div>
            <div class="field">
                <label class="label">Correo corporativo</label>
                <input class="input" type="email" name="email" required placeholder="usuario@fycconsultores.com">
            </div>
            <div class="field">
                <label class="label">Contraseña</label>
                <input class="input" type="password" name="password" required placeholder="Mínimo 8 caracteres">
            </div>
            <div class="field">
                <label class="label">Repetir contraseña</label>
                <input class="input" type="password" name="password2" required placeholder="Repite tu contraseña">
            </div>
            <button class="btn" type="submit">Registrarme</button>
            <div class="small" style="margin-top:12px">
                ¿Ya tienes cuenta? <a href="login.php">Iniciar sesión</a>
            </div>
        </form>
    </div>
</body>

</html>