<?php
// public/login.php
session_start();

if (isset($_SESSION['user_id'])) {
    header('Location: boards/workspace.php');
    exit;
}

if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}

$errores = [
    '1' => 'Correo o contraseña incorrectos.',
    '2' => 'Faltan campos obligatorios.',
    '3' => 'Tu cuenta está pendiente de aprobación por el administrador.',
    '4' => 'Error de seguridad. Inténtalo de nuevo.',
    '5' => 'Tu cuenta está suspendida o eliminada. Contacta al administrador.'
];
$e = isset($_GET['e']) && isset($errores[$_GET['e']]) ? $errores[$_GET['e']] : '';
?>
<!doctype html>
<html lang="es" data-theme="dark">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>F&amp;C Planner — Iniciar sesión</title>
    <link rel="stylesheet" href="assets/app.css">
    <link rel="stylesheet" href="assets/theme.css">

    <!-- Aplicar tema guardado antes de pintar (evita flash) -->
    <script>
        (function () {
            var t = localStorage.getItem('fyc-theme') || 'dark';
            document.documentElement.setAttribute('data-theme', t);
        })();
    </script>

    <style>
        body {
            margin: 0;
            display: grid;
            place-items: center;
            min-height: 100vh;
            background: var(--bg-app);
        }

        .login-card {
            background: var(--bg-surface);
            border: 1px solid var(--border-accent);
            border-radius: 20px;
            padding: 32px 28px;
            width: 92%;
            max-width: 380px;
            box-shadow: var(--shadow-modal);
        }

        .login-logo {
            font-family: 'Sora', sans-serif;
            font-weight: 800;
            font-size: 22px;
            color: var(--text-primary);
            margin: 0 0 4px;
            letter-spacing: -0.5px;
        }

        .login-logo span {
            color: var(--fyc-red);
        }

        .login-sub {
            font-size: 13px;
            color: var(--text-ghost);
            margin: 0 0 24px;
        }

        .login-toggle {
            position: absolute;
            top: 16px;
            right: 16px;
        }
    </style>
</head>

<body>
    <!-- Toggle tema -->
    <div class="login-toggle">
        <button id="themeToggle" title="Cambiar tema">
            <span id="themeIcon">🌙</span>
            <span id="themeLabel">Oscuro</span>
        </button>
    </div>

    <div class="login-card">
        <h1 class="login-logo">F&amp;C <span>Planner</span></h1>
        <p class="login-sub">Inicia sesión para continuar.</p>

        <?php if ($e): ?>
            <div class="fyc-flash fyc-flash-error" style="margin-bottom:16px;">
                <?= htmlspecialchars($e, ENT_QUOTES, 'UTF-8') ?>
            </div>
        <?php endif; ?>

        <form method="post" action="auth_login.php" autocomplete="on">
            <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['csrf'], ENT_QUOTES, 'UTF-8') ?>">

            <div style="margin-bottom:14px;">
                <label class="fyc-label">Correo</label>
                <input class="fyc-input" type="email" name="email" required placeholder="tucorreo@empresa.com">
            </div>

            <div style="margin-bottom:20px;">
                <label class="fyc-label">Contraseña</label>
                <input class="fyc-input" type="password" name="password" required placeholder="••••••••">
            </div>

            <button type="submit" class="fyc-btn fyc-btn-primary"
                style="width:100%; justify-content:center; padding:11px; font-size:14px;">
                Entrar
            </button>
        </form>

        <div style="text-align:center; margin-top:16px; font-size:12px; color:var(--text-ghost);">
            ¿No tienes cuenta? <a href="register.php"
                style="color:var(--fyc-red); text-decoration:none; font-weight:600;">Regístrate</a>
        </div>

        <div style="text-align:center; margin-top:10px; font-size:11px; color:var(--text-ghost);">
            © F&amp;C Consultores
        </div>
    </div>

    <script>
        (function () {
            var html = document.documentElement;
            var btnT = document.getElementById('themeToggle');
            var iconEl = document.getElementById('themeIcon');
            var labelEl = document.getElementById('themeLabel');

            function applyTheme(t) {
                html.setAttribute('data-theme', t);
                localStorage.setItem('fyc-theme', t);
                if (iconEl) iconEl.textContent = t === 'dark' ? '🌙' : '☀️';
                if (labelEl) labelEl.textContent = t === 'dark' ? 'Oscuro' : 'Claro';
            }

            applyTheme(localStorage.getItem('fyc-theme') || 'dark');

            if (btnT) {
                btnT.addEventListener('click', function () {
                    applyTheme(html.getAttribute('data-theme') === 'dark' ? 'light' : 'dark');
                });
            }
        })();
    </script>
</body>

</html>