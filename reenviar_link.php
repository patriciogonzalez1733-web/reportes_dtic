<?php
// Reenvía por correo un link nuevo al firmante de la fase actual.
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/correo.php';
require_once __DIR__ . '/includes/documento.php';
require_once __DIR__ . '/includes/tokens.php';

// Solo los roles indicados pueden entrar a esta página.
$u = require_rol(['responsable', 'admin']);

$id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);

// Muestra un aviso y termina la página.
function pantalla_aviso(array $u, string $mensaje): void
{
    // Dibuja la cabecera de la página.
    render_header('reenviar link', $u);
    echo '<p class="error">' . h($mensaje) . '</p>';
    echo '<p><a href="mis_solicitudes.php">Volver a mis solicitudes</a></p>';
    render_footer();
    exit;
}

$stmt = $conn->prepare(
    "SELECT s.*, us.nombre AS  creador_nombre, us.correo AS creador_correo
    FROM solicitudes s JOIN usuarios us ON us.id = s.creado_por
    WHERE s.id = ? AND s.estado = 'en_curso'"
);

$stmt->bind_param('i', $id);
$stmt->execute();
$solicitud = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$solicitud) {
    pantalla_aviso($u, 'Solicitud no encontrada o no está en curso.');
}
if ($u['rol'] !== 'admin' && (int) $solicitud['creado_por'] !== (int) $u['id']) {
    pantalla_aviso($u, 'Solo el admin y el creador de la solicitud pueden reenviar el link.');
}
$fase = (int) $solicitud['fase_actual'];
$faseConToken = $solicitud['tipo'] === 'informe_tecnico' ? [2] : [3, 4];
if (!in_array($fase, $faseConToken, true)) {
    pantalla_aviso($u, 'La solicitud no está en una fase que permita reenviar el link.');
}

$stmt = $conn->prepare(
    "SELECT destinatario_nombre, destinatario_correo FROM tokens_confirmacion
    WHERE solicitud_id = ? AND fase = ? ORDER BY id DESC LIMIT 1"
);
$stmt->bind_param('ii', $id, $fase);
$stmt->execute();
$destino = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$destino) {
    pantalla_aviso($u, 'No se encontró un destinatario para la fase actual.');
}

$error = '';
$enviado = false;

// Se ejecuta cuando se envía el formulario.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Verifica el token de seguridad del formulario.
    csrf_check();
    // Los cambios se guardan todos juntos o no se guarda ninguno.
    $conn->begin_transaction();
    try {
        $stmt = $conn->prepare("SELECT id FROM solicitudes WHERE id = ? AND fase_actual = ? AND
        estado = 'en_curso' FOR UPDATE");
        $stmt->bind_param('ii', $id, $fase);
        $stmt->execute();
        $bloqueada = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$bloqueada) {
            throw new RuntimeException('La solicitud ya no está en curso o ha cambiado de fase.');
        }

        $stmt = $conn->prepare("UPDATE tokens_confirmacion SET expira_en = NOW() WHERE
        solicitud_id = ? AND fase = ? AND usado = 0");
        $stmt->bind_param('ii', $id, $fase);
        $stmt->execute();
        $stmt->close();

        // Crea el link de un solo uso para el firmante.
        $token = crear_token($conn, $id, $fase, $destino['destinatario_correo'],
        $destino['destinatario_nombre']);

        $comentario = 'Link reenviado a ' . $destino['destinatario_nombre'];
        $stmt = $conn->prepare(
            "INSERT INTO historial (solicitud_id, fase_origen, fase_destino, accion, usuario_id,
            comentario)
                VALUES(?,?,?, 'reenviar', ?,?)"
        );
        $stmt->bind_param('iiiis', $id, $fase, $fase, $u['id'], $comentario);
        $stmt->execute();
        $stmt->close();

        $conn->commit();
    } catch (Throwable $e) {
        // Si algo falló, se deshacen los cambios.
        $conn->rollback();
        $error = 'Error al reenviar el link: ' . $e->getMessage();
    }

    if (!$error) {
        $folio = $solicitud['folio'];
        $link = url_base() . '/confirmar.php?token=' . $token;
        $cuerpo = "
            <p>Se te reenvía el link para revisar la solicitud <strong>{$folio}</strong>.</p>
            <p><a href=\"{$link}\">Haz clic aquí para revisar y aprobar o rechazar</a></p>
            <p>Este link es de un solo uso y expira en 72 horas. Los links anteriores dejaron de funcionar.</p>
        ";
        $enviado = enviar_correo($destino['destinatario_correo'], $destino['destinatario_nombre'],
            "Solicitud $folio - Link reenviado", $cuerpo);
        if (!$enviado) {
            $error = 'El link nuevo se creó, pero el correo no se pudo enviar. Intenta reenviarlo otra vez.';
        }
    }
}

// Dibuja la cabecera de la página.
render_header('Reenviar link', $u);
?>
<h1>Reenviar link</h1>

<?php if ($error): ?><p class="error"><?= h($error) ?></p><?php endif; ?>
<?php if ($enviado): ?><p class="ok">Link reenviado a <?= h($destino['destinatario_correo']) ?>.</p><?php endif; ?>

<p>Solicitud <strong><?= h($solicitud['folio']) ?></strong>, Fase <?= $fase ?>.</p>
<p>Se enviará un link nuevo a <strong><?= h($destino['destinatario_nombre']) ?></strong>
   (<?= h($destino['destinatario_correo']) ?>). Los links anteriores dejarán de funcionar.</p>

<?php if (!$enviado): ?>
<form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="id" value="<?= (int) $id ?>">
    <button type="submit" class="primario">Reenviar link</button>
    <a href="mis_solicitudes.php" class="chico">Cancelar</a>
</form>
<?php endif; ?>

<p><a href="mis_solicitudes.php">← Volver a mis solicitudes</a></p>

<?php render_footer(); ?>
