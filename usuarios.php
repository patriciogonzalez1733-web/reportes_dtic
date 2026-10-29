<?php
// Administración de usuarios: editar, cambiar contraseña, activar o desactivar y eliminar.
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/layout.php';

// Hay que haber iniciado sesión.
require_login();
require_rol(['admin']);

$u = usuario_actual();
$error = '';
$ok = '';

$roles = ['admin', 'responsable', 'encargado', 'activo_fijo'];

// Guarda los cambios de un usuario.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'editar') {
    // Verifica el token de seguridad del formulario.
    csrf_check();

    $id     = (int)($_POST['id'] ?? 0);
    $nombre = trim($_POST['nombre'] ?? '');
    $correo = trim($_POST['correo'] ?? '');
    $rol    = $_POST['rol'] ?? '';

    if ($nombre === '' || $correo === '' || !filter_var($correo, FILTER_VALIDATE_EMAIL)) {
        $error = 'Nombre o correo inválido.';
    } elseif ($errLargo = validar_largos([
        'El nombre' => [$nombre, 150],
        'El correo' => [$correo, 150],
    ])) {
        $error = $errLargo;
    } elseif (!in_array($rol, $roles, true)) {
        $error = 'Rol inválido.';
    } else {
        try {
            $stmt = $conn->prepare('UPDATE usuarios SET nombre = ?, correo = ?, rol = ? WHERE id = ?');
            $stmt->bind_param('sssi', $nombre, $correo, $rol, $id);
            $stmt->execute();
            $stmt->close();
            $ok = "Usuario actualizado correctamente.";
        } catch (mysqli_sql_exception $e) {
            $error = (str_contains($e->getMessage(), 'Duplicate'))
                ? 'Ese correo ya está en uso por otro usuario.'
                : 'No se pudo actualizar: ' . $e->getMessage();
        }
    }
}

// Cambia la contraseña de un usuario.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'clave') {
    // Verifica el token de seguridad del formulario.
    csrf_check();

    $id = (int)($_POST['id'] ?? 0);
    $nueva = $_POST['clave_nueva'] ?? '';
    $repite = $_POST['clave_repite'] ?? '';

    $stmt = $conn->prepare('SELECT nombre FROM usuarios WHERE id = ?');
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $destino = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$destino) {
        $error = 'El usuario no existe.';
    } elseif (strlen($nueva) < 8) {
        $error = 'La contraseña debe contener al menos 8 caracteres.';
    } elseif (strlen($nueva) > 72) {
        $error = 'La contraseña no puede contener más de 72 caracteres.';
    } elseif ($nueva !== $repite) {
        $error = 'Las contraseñas deben coincidir.';
    } else {
        $hash = password_hash($nueva, PASSWORD_DEFAULT);
        $stmt = $conn->prepare('UPDATE usuarios SET password_hash = ? WHERE id = ?');
        $stmt->bind_param('si', $hash, $id);
        $stmt->execute();
        $stmt->close();
        $ok = 'Contraseña de ' . $destino['nombre'] . ' actualizada.';
    }
}

// Activa o desactiva una cuenta.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'estado') {
    // Verifica el token de seguridad del formulario.
    csrf_check();

    $id = (int) ($_POST['id'] ?? 0);
    $activar = ($_POST['activo'] ?? '') === '1' ? 1 : 0;

    $stmt = $conn->prepare('SELECT rol FROM usuarios WHERE id = ?');
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $destino = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$destino) {
        $error = 'El usuario no existe.';
    } elseif (!$activar && $id === (int) $u['id']) {
        $error = 'No puedes desactivar tu propia cuenta.';
    } else {
        $otrosAdmins = 1;
        if (!$activar && $destino['rol'] === 'admin') {
            $stmt = $conn->prepare("SELECT COUNT(*) FROM usuarios WHERE rol = 'admin' AND activo = 1 AND id <> ?");
            $stmt->bind_param('i', $id);
            $stmt->execute();
            $otrosAdmins = (int) $stmt->get_result()->fetch_row()[0];
            $stmt->close();
        }
        if ($otrosAdmins === 0) {
            $error = 'Debe quedar al menos un administrador activo.';
        } else {
            $stmt = $conn->prepare('UPDATE usuarios SET activo = ? WHERE id = ?');
            $stmt->bind_param('ii', $activar, $id);
            $stmt->execute();
            $stmt->close();
            $ok = $activar ? 'Cuenta activada.' : 'Cuenta desactivada.';
        }
    }
}

// Elimina un usuario.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'eliminar') {
    // Verifica el token de seguridad del formulario.
    csrf_check();

    $id = (int)($_POST['id'] ?? 0);

    if ($id === (int)$u['id']) {
        $error = 'No puedes eliminar tu propio usuario mientras tienes la sesión activa.';
    } else {
        try {
            $stmt = $conn->prepare('DELETE FROM usuarios WHERE id = ?');
            $stmt->bind_param('i', $id);
            $stmt->execute();
            $stmt->close();
            $ok = 'Usuario eliminado.';
        } catch (mysqli_sql_exception $e) {
            $error = 'No se pudo eliminar: este usuario tiene solicitudes creadas asociadas a su cuenta.';
        }
    }
}

$usuarios = $conn->query('SELECT id, nombre, correo, rol, activo, creado_en FROM usuarios ORDER BY rol, nombre')
                  ->fetch_all(MYSQLI_ASSOC);

$editarId = isset($_GET['editar']) ? (int)$_GET['editar'] : null;
$usuarioEditar = null;
if ($editarId) {
    foreach ($usuarios as $fila) {
        if ((int)$fila['id'] === $editarId) {
            $usuarioEditar = $fila;
            break;
        }
    }
}

// Dibuja la cabecera de la página.
render_header('Usuarios', $u);
?>

<h1>Usuarios del sistema</h1>
<p><a href="crear_admin.php">+ Crear nuevo usuario</a></p>

<?php if ($error): ?><div class="error"><?= h($error) ?></div><?php endif; ?>
<?php if ($ok): ?><div class="ok"><?= h($ok) ?></div><?php endif; ?>

<?php if ($usuarioEditar): ?>
    <div class="form-solicitud">
        <h2>Editar usuario: <?= h($usuarioEditar['nombre']) ?></h2>
        <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="accion" value="editar">
            <input type="hidden" name="id" value="<?= (int)$usuarioEditar['id'] ?>">

            <div class="form-linea">
                <label>Nombre</label>
                <input type="text" name="nombre" value="<?= h($usuarioEditar['nombre']) ?>" required maxlength="150">
            </div>
            <div class="form-linea">
                <label>Correo</label>
                <input type="email" name="correo" value="<?= h($usuarioEditar['correo']) ?>" required maxlength="150">
            </div>
            <div class="form-linea">
                <label>Rol</label>
                <select name="rol" required>
                    <?php foreach ($roles as $r): ?>
                        <option value="<?= h($r) ?>" <?= $r === $usuarioEditar['rol'] ? 'selected' : '' ?>>
                            <?= h($r) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <button type="submit" class="primario">Guardar cambios</button>
            <a href="usuarios.php" class="chico">Cancelar</a>
        </form>

        <h3>Cambiar contraseña</h3>
        <form method="post" action="usuarios.php?editar=<?= (int)$usuarioEditar['id'] ?>" autocomplete="off">
            <?= csrf_field() ?>
            <input type="hidden" name="accion" value="clave">
            <input type="hidden" name="id" value="<?= (int)$usuarioEditar['id'] ?>">

            <div class="form-linea">
                <label>Nueva contraseña</label>
                <input type="password" name="clave_nueva" required minlength="8" maxlength="72"
                       autocomplete="new-password" data-sin-contador>
            </div>
            <div class="form-linea">
                <label>Repite la contraseña</label>
                <input type="password" name="clave_repite" required minlength="8" maxlength="72"
                       autocomplete="new-password" data-sin-contador>
            </div>

            <button type="submit" class="primario">Cambiar contraseña</button>
        </form>
    </div>
<?php endif; ?>

<table class="tabla">
    <thead>
        <tr>
            <th>Nombre</th>
            <th>Correo</th>
            <th>Rol</th>
            <th>Estado</th>
            <th>Creado</th>
            <th>Acciones</th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($usuarios as $fila): ?>
            <?php $activa = (int) ($fila['activo'] ?? 1) === 1; ?>
            <tr class="<?= $activa ? '' : 'fila-desactivada' ?>">
                <td><?= h($fila['nombre']) ?></td>
                <td><?= h($fila['correo']) ?></td>
                <td><span class="pill"><?= h($fila['rol']) ?></span></td>
                <td>
                    <?php if ($activa): ?>
                        <span class="pill estado-finalizada">Activa</span>
                    <?php else: ?>
                        <span class="pill estado-anulada">Desactivada</span>
                    <?php endif; ?>
                </td>
                <td><?= h(date('d-m-Y', strtotime($fila['creado_en']))) ?></td>
                <td>
                    <div class="acciones">
                    <a href="usuarios.php?editar=<?= (int)$fila['id'] ?>" class="chico">Editar</a>
                    <?php if ((int)$fila['id'] !== (int)$u['id']): ?>
                        <form method="post" style="display:inline"
                              <?php if ($activa): ?>onsubmit="return confirm('¿Desactivar a <?= h(addslashes($fila['nombre'])) ?>? No podrá iniciar sesión.');"<?php endif; ?>>
                            <?= csrf_field() ?>
                            <input type="hidden" name="accion" value="estado">
                            <input type="hidden" name="id" value="<?= (int)$fila['id'] ?>">
                            <input type="hidden" name="activo" value="<?= $activa ? '0' : '1' ?>">
                            <button type="submit" class="chico"><?= $activa ? 'Desactivar' : 'Activar' ?></button>
                        </form>
                        <form method="post" style="display:inline"
                              onsubmit="return confirm('¿Eliminar a <?= h(addslashes($fila['nombre'])) ?>?');">
                            <?= csrf_field() ?>
                            <input type="hidden" name="accion" value="eliminar">
                            <input type="hidden" name="id" value="<?= (int)$fila['id'] ?>">
                            <button type="submit" class="chico link-eliminar">Eliminar</button>
                        </form>
                    <?php endif; ?>
                    </div>
                </td>
            </tr>
        <?php endforeach; ?>
    </tbody>
</table>

<?php render_footer(); ?>
