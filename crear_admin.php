<?php
// Formulario para que el admin cree usuarios del sistema.
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/layout.php';

// Solo los roles indicados pueden entrar a esta página.
$u = require_rol(['admin']);
$mensaje = '';
$error = '';

// Se ejecuta cuando se envía el formulario.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Verifica el token de seguridad del formulario.
    csrf_check();
    $nombre = trim($_POST['nombre'] ?? '');
    $correo = trim($_POST['correo'] ?? '');
    $clave  = $_POST['clave'] ?? '';
    $rol    = $_POST['rol'] ?? '';
    $repite = $_POST['clave_repite'] ?? '';

    $rolesValidos = ['admin', 'responsable', 'encargado', 'activo_fijo'];

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
    } elseif (!in_array($rol, $rolesValidos, true)) {
        $error = 'Rol no válido.';
    } else {
        try {
            $hash = password_hash($clave, PASSWORD_DEFAULT);
            $stmt = $conn->prepare('INSERT INTO usuarios (nombre, correo, password_hash, rol) VALUES (?,?,?,?)');
            $stmt->bind_param('ssss', $nombre, $correo, $hash, $rol);
            $stmt->execute();
            $stmt->close();
            $mensaje = "Usuario \"$nombre\" ($rol) creado correctamente.";
        } catch (mysqli_sql_exception $e) {
            $error = str_contains($e->getMessage(), 'Duplicate')
                ? 'Ese correo ya está registrado por otro usuario.'
                : 'No se pudo crear el usuario: ' . $e->getMessage();
        }
    }
}

// Dibuja la cabecera de la página.
render_header('Crear usuario', $u);
?>
<h1>Crear usuario</h1>

<?php if ($mensaje): ?><p class="ok"><?= h($mensaje) ?></p><?php endif; ?>
<?php if ($error): ?><p class="error"><?= h($error) ?></p><?php endif; ?>

<?php $conservar = $error !== ''; ?>
<form method="post" class="form-login">
    <?= csrf_field() ?>
    <label>Nombre <input name="nombre" required maxlength="150" value="<?= $conservar ? h($_POST['nombre'] ?? '') : '' ?>"></label>
    <label>Correo <input type="email" name="correo" required maxlength="150" value="<?= $conservar ? h($_POST['correo'] ?? '') : '' ?>"></label>
    <label>Contraseña <input type="password" name="clave" required minlength="8" maxlength="72"
        autocomplete="new-password" data-sin-contador></label>
    <label>Repite la contraseña <input type="password" name="clave_repite" required minlength="8" maxlength="72"
        autocomplete="new-password" data-sin-contador></label>
    <label>Rol
        <select name="rol" required>
            <?php $rolElegido = $conservar ? ($_POST['rol'] ?? '') : ''; ?>
            <option value="admin" <?= $rolElegido === 'admin' ? 'selected' : '' ?>>Admin</option>
            <option value="responsable" <?= $rolElegido === 'responsable' ? 'selected' : '' ?>>Responsable DTIC</option>
            <option value="encargado" <?= $rolElegido === 'encargado' ? 'selected' : '' ?>>Encargado (jefe sector de entrega)</option>
            <option value="activo_fijo" <?= $rolElegido === 'activo_fijo' ? 'selected' : '' ?>>Activo Fijo</option>
        </select>
    </label>
    <button type="submit" class="primario">Crear</button>
</form>

<?php render_footer(); ?>
