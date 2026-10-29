<?php
// Crea el primer administrador. Solo funciona mientras no exista ningún usuario.
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/layout.php';

if (!sin_usuarios($conn)) {
    // Redirige a otra página.
    header('Location: login.php');
    exit;
}

$error = '';
$creado = false;

// Se ejecuta cuando se envía el formulario.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Verifica el token de seguridad del formulario.
    csrf_check();

    $nombre = trim($_POST['nombre'] ?? '');
    $correo = trim($_POST['correo'] ?? '');
    $clave  = $_POST['clave'] ?? '';
    $repite = $_POST['clave_repite'] ?? '';

    if ($nombre === '' || $correo === '' || $clave === '') {
        $error = 'Completa todos los campos.';
    } elseif (!filter_var($correo, FILTER_VALIDATE_EMAIL)) {
        $error = 'El correo no es válido.';
    } elseif ($errLargo = validar_largos([
        'El nombre' => [$nombre, 150],
        'El correo' => [$correo, 150],
    ])) {
        $error = $errLargo;
    } elseif (strlen($clave) < 8) {
        $error = 'La contraseña debe contener al menos 8 caracteres.';
    } elseif (strlen($clave) > 72) {
        $error = 'La contraseña no puede contener más de 72 caracteres.';
    } elseif ($clave !== $repite) {
        $error = 'Las contraseñas deben coincidir.';
    } else {
        $hash = password_hash($clave, PASSWORD_DEFAULT);
        $stmt = $conn->prepare(
            "INSERT INTO usuarios (nombre, correo, password_hash, rol)
             SELECT ?, ?, ?, 'admin' FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM usuarios)"
        );
        $stmt->bind_param('sss', $nombre, $correo, $hash);
        $stmt->execute();
        $creado = $stmt->affected_rows === 1;
        $stmt->close();
        if (!$creado) {
            $error = 'El sistema ya fue configurado: ya existe un usuario.';
        }
    }
}

?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Configuración inicial · DTIC</title>
    <link rel="stylesheet" href="assets/estilos.css?v=<?= (int) @filemtime(__DIR__ . '/assets/estilos.css') ?>">
</head>
<body class="pagina-login">
<div class="login-marco">
    <div class="login-panel-marca">
        <img src="assets/img/unap_logo.webp" alt="Universidad Arturo Prat" class="login-logo">
        <p class="login-panel-texto">Informes DTIC<br>DTIC · Universidad Arturo Prat Sede Victoria</p>
    </div>
    <div class="login-panel-form">
        <?php if ($creado): ?>
            <div class="form-login">
                <h1>Listo</h1>
                <p class="ok">Cuenta de administrador creada. Ya puedes iniciar sesión.</p>
                <a href="login.php" class="primario">Ir a iniciar sesión</a>
            </div>
        <?php else: ?>
            <form method="post" class="form-login" autocomplete="off">
                <h1>Configuración inicial</h1>
                <p>Aún no hay usuarios registrados. Crea la cuenta de administrador. Esta pantalla solo está disponible hasta que exista el primer usuario.</p>
                <?php if ($error): ?><p class="error"><?= h($error) ?></p><?php endif; ?>
                <?= csrf_field() ?>
                <label>Nombre
                    <input name="nombre" required maxlength="150" autofocus value="<?= h($_POST['nombre'] ?? '') ?>">
                </label>
                <label>Correo
                    <input type="email" name="correo" required maxlength="150" value="<?= h($_POST['correo'] ?? '') ?>">
                </label>
                <label>Contraseña
                    <input type="password" name="clave" required minlength="8" maxlength="72" autocomplete="new-password" data-sin-contador>
                </label>
                <label>Repite la contraseña
                    <input type="password" name="clave_repite" required minlength="8" maxlength="72" autocomplete="new-password" data-sin-contador>
                </label>
                <button type="submit" class="primario">Crear administrador</button>
            </form>
        <?php endif; ?>
    </div>
</div>
</body>
</html>
