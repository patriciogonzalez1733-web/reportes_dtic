<?php
// Inventario de equipos: agregar, marcar como reparado y eliminar.
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/layout.php';

// Solo los roles indicados pueden entrar a esta página.
$u = require_rol(['responsable', 'encargado', 'admin']);

$etiquetaEstado = ['en_dtic' => 'En DTIC', 'asignado' => 'Asignado', 'baja' => 'De baja', 'en_reparacion' => 'En reparación'];

$mensaje = '';
$error = '';

// Se ejecuta cuando se envía el formulario.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Verifica el token de seguridad del formulario.
    csrf_check();
    $accion = $_POST['accion'] ?? '';

    if ($accion === 'crear') {
        $codigo      = trim($_POST['codigo_inventario'] ?? '');
        $descripcion = trim($_POST['descripcion'] ?? '');
        $serie       = trim($_POST['serie'] ?? '');

        if ($descripcion === '') {
            $error = 'La descripción es obligatoria.';
        } elseif (mb_strlen($codigo) > 50) {
            $error = 'El código de inventario no puede tener más de 50 caracteres.';
        } elseif (mb_strlen($descripcion) > 255) {
            $error = 'La descripción no puede tener más de 255 caracteres.';
        } elseif (mb_strlen($serie) > 100) {
            $error = 'La serie no puede tener más de 100 caracteres.';
        } else {
            $serieRepetida = false;
            if ($serie !== '') {
                $stmt = $conn->prepare('SELECT id FROM equipos WHERE serie = ? LIMIT 1');
                $stmt->bind_param('s', $serie);
                $stmt->execute();
                $serieRepetida = (bool) $stmt->get_result()->fetch_assoc();
                $stmt->close();
            }

            if ($serieRepetida) {
                $error = 'Ya existe un equipo con esa serie. El número de serie no puede repetirse.';
            } else {
                $stmt = $conn->prepare(
                    "INSERT INTO equipos (codigo_inventario, descripcion, serie, estado)
                     VALUES (?, ?, ?, 'en_dtic')"
                );

                $codigoVal = $codigo !== '' ? $codigo : null;
                $serieVal  = $serie !== '' ? $serie : null;
                $stmt->bind_param('sss', $codigoVal, $descripcion, $serieVal);
                $stmt->execute();
                $stmt->close();
                $mensaje = 'Equipo agregado.';
            }
        }
    }

    if ($accion === 'reparado') {
        $id = (int) ($_POST['id'] ?? 0);
        $comentario = trim($_POST['comentario'] ?? '');

        if ($comentario === '') {
            $error = 'Indica qué se reparó: el comentario es obligatorio.';
        } elseif (mb_strlen($comentario) > 500) {
            $error = 'El comentario no puede tener más de 500 caracteres.';
        } else {
            // Los cambios se guardan todos juntos o no se guarda ninguno.
            $conn->begin_transaction();
            try {
                $stmt = $conn->prepare(
                    "UPDATE equipos SET estado = 'en_dtic'
                     WHERE id = ? AND estado = 'en_reparacion' AND bloqueado_por IS NULL"
                );
                $stmt->bind_param('i', $id);
                $stmt->execute();
                $cambiadas = $stmt->affected_rows;
                $stmt->close();
                if ($cambiadas === 0) {
                    throw new RuntimeException('Ese equipo ya no está en reparación (o una solicitud lo tiene reservado).');
                }

                $stmt = $conn->prepare(
                    "INSERT INTO equipo_movimientos (equipo_id, accion, usuario_id, comentario)
                     VALUES (?, 'reparado', ?, ?)"
                );
                $stmt->bind_param('iis', $id, $u['id'], $comentario);
                $stmt->execute();
                $stmt->close();

                $conn->commit();
                $mensaje = 'Equipo marcado como reparado: ya puede asignarse en una Entrega.';
            } catch (Throwable $e) {
                // Si algo falló, se deshacen los cambios.
                $conn->rollback();
                $error = $e instanceof RuntimeException ? $e->getMessage() : 'No se pudo marcar como reparado: ' . $e->getMessage();
            }
        }
    }

    if ($accion === 'eliminar') {
        $id = (int) ($_POST['id'] ?? 0);
        $stmt = $conn->prepare('DELETE FROM equipos WHERE id = ?');
        $stmt->bind_param('i', $id);
        try {
            $stmt->execute();
            $mensaje = $stmt->affected_rows > 0 ? 'Equipo eliminado.' : 'No se encontró el equipo.';
        } catch (mysqli_sql_exception $e) {
            $error = 'No se puede eliminar: este equipo ya tiene historial de solicitudes.';
        }
        $stmt->close();
    }
}

$equipos = $conn->query(
    "SELECT id, codigo_inventario, descripcion, serie, estado, custodio_actual, ubicacion_actual, bloqueado_por
     FROM equipos ORDER BY descripcion"
)->fetch_all(MYSQLI_ASSOC);

$ultimoMovimiento = [];
$filasMov = $conn->query(
    "SELECT m.equipo_id, m.comentario, m.creado_en, us.nombre AS usuario
     FROM equipo_movimientos m JOIN usuarios us ON us.id = m.usuario_id
     WHERE m.id IN (SELECT MAX(id) FROM equipo_movimientos GROUP BY equipo_id)"
)->fetch_all(MYSQLI_ASSOC);
foreach ($filasMov as $mov) {
    $ultimoMovimiento[(int) $mov['equipo_id']] = $mov;
}

// Dibuja la cabecera de la página.
render_header('Inventario de equipos', $u);
?>
<h1>Inventario de equipos</h1>

<?php if ($mensaje): ?><p class="ok"><?= h($mensaje) ?></p><?php endif; ?>
<?php if ($error): ?><p class="error"><?= h($error) ?></p><?php endif; ?>

<h2>Agregar equipo</h2>
<form method="post" class="form-equipo">
    <?= csrf_field() ?>
    <input type="hidden" name="accion" value="crear">
    <?php
    $conservar = $error && ($_POST['accion'] ?? '') === 'crear'; ?>
    <label>Código inventario <input name="codigo_inventario" maxlength="50" value="<?= $conservar ? h($_POST['codigo_inventario'] ?? '') : '' ?>"></label>
    <label>Descripción <input name="descripcion" required maxlength="255" value="<?= $conservar ? h($_POST['descripcion'] ?? '') : '' ?>"></label>
    <label>Serie <input name="serie" maxlength="100" value="<?= $conservar ? h($_POST['serie'] ?? '') : '' ?>"></label>
    <button type="submit" class="primario">Agregar</button>
</form>

<h2>Equipos</h2>
<table class="tabla">
    <thead>
        <tr>
            <th>Código</th><th>Descripción</th><th>Serie</th><th>Estado</th>
            <th>Custodio actual</th><th>Ubicación</th><th></th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($equipos as $e): ?>
            <tr>
                <td><?= h($e['codigo_inventario'] ?: '—') ?></td>
                <td><?= h($e['descripcion']) ?></td>
                <td><?= h($e['serie'] ?: '—') ?></td>
                <td>
                    <span class="pill estado-<?= h($e['estado']) ?>"><?= h($etiquetaEstado[$e['estado']] ?? $e['estado']) ?></span>
                    <?php if ($e['bloqueado_por']): ?>
                        <small class="chico">(reservado, solicitud #<?= (int)$e['bloqueado_por'] ?>)</small>
                    <?php endif; ?>
                    <?php if (isset($ultimoMovimiento[(int) $e['id']])): $mov = $ultimoMovimiento[(int) $e['id']]; ?>
                        <br><small class="chico">Reparado: «<?= h($mov['comentario']) ?>» · <?= h($mov['usuario']) ?>, <?= h(date('d-m-Y', strtotime($mov['creado_en']))) ?></small>
                    <?php endif; ?>
                </td>
                <td><?= h($e['custodio_actual'] ?: '—') ?></td>
                <td><?= h($e['ubicacion_actual'] ?: '—') ?></td>
                <td>
                    <div class="acciones-equipo">
                        <?php if ($e['estado'] === 'en_reparacion' && !$e['bloqueado_por']): ?>
                            <form method="post" onsubmit="return confirm('¿Marcar este equipo como reparado? Quedará disponible para asignarse.');">
                                <?= csrf_field() ?>
                                <input type="hidden" name="accion" value="reparado">
                                <input type="hidden" name="id" value="<?= (int)$e['id'] ?>">
                                <input name="comentario" required maxlength="500" placeholder="¿Qué se reparó?" data-sin-contador>
                                <button type="submit" class="primario chico">Reparado, volver a DTIC</button>
                            </form>
                        <?php endif; ?>
                        <form method="post" onsubmit="return confirm('¿Eliminar este equipo?');">
                            <?= csrf_field() ?>
                            <input type="hidden" name="accion" value="eliminar">
                            <input type="hidden" name="id" value="<?= (int)$e['id'] ?>">
                            <button type="submit" class="link-eliminar">Eliminar</button>
                        </form>
                    </div>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$equipos): ?>
            <tr><td colspan="7">No hay equipos registrados.</td></tr>
        <?php endif; ?>
    </tbody>
</table>

<p><a href="index.php">← Volver al inicio</a></p>
<?php render_footer(); ?>
