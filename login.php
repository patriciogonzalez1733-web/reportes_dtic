<?php
// Inicio de sesión.
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/layout.php';

if (sin_usuarios($conn)) {
    // Redirige a otra página.
    header('Location: primer_admin.php');
    exit;
}

if (usuario_actual()) {
    // Redirige a otra página.
    header('Location: index.php');
    exit;
}

$error = '';

// Se ejecuta cuando se envía el formulario.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Verifica el token de seguridad del formulario.
    csrf_check();

    $correo = trim($_POST['correo'] ?? '');
    $clave  = $_POST['clave'] ?? '';

    if ($correo === '' || $clave === '') {
        $error = 'Ingresa tu correo y clave.';
    } elseif (intentar_login($conn, $correo, $clave)) {
        // Redirige a otra página.
        header('Location: ' . pagina_despues_del_login());
        exit;
    } else {
        $error = 'Correo o clave incorrectos.';
    }
}

?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Iniciar sesión · DTIC</title>
    <link rel="stylesheet" href="assets/estilos.css?v=<?= (int) @filemtime(__DIR__ . '/assets/estilos.css') ?>">
</head>
<body class="pagina-login">
<div class="login-marco">
    <div class="login-panel-marca">
        <img src="assets/img/unap_logo.webp" alt="Universidad Arturo Prat" class="login-logo">
        <p class="login-panel-texto">Informes DTIC<br>DTIC · Universidad Arturo Prat Sede Victoria</p>
    </div>
    <div class="login-panel-form">
        <form method="post" class="form-login">
            <h1>Iniciar sesión</h1>
            <?php if ($error): ?><p class="error"><?= h($error) ?></p><?php endif; ?>
            <?= csrf_field() ?>
            <label>Correo
                <input type="email" name="correo" required autofocus>
            </label>
            <label>Clave
                <input type="password" name="clave" required>
            </label>
            <button type="submit" class="primario">Entrar</button>
        </form>
    </div>
</div>
</body>
</html>
