<?php
// Crea un link de firma de 2 horas para firmar en este dispositivo, sin enviar correo.
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/tokens.php';

// Solo los roles indicados pueden entrar a esta página.
$u = require_rol(['responsable', 'encargado', 'admin']);

$id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);

// Muestra un aviso y termina la página.
function pantalla_aviso(array $u, string $mensaje): void
{
    // Dibuja la cabecera de la página.
    render_header('Firma presencial', $u);
    echo '<p class="error">' . h($mensaje) . '</p>';
    echo '<p><a href="mis_solicitudes.php">Volver a mis solicitudes</a></p>';
    render_footer();
    exit;
}

$stmt = $conn->prepare("SELECT * FROM solicitudes WHERE id = ? AND estado = 'en_curso'");
$stmt->bind_param('i', $id);
$stmt->execute();
$solicitud = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$solicitud) {
    pantalla_aviso($u, 'Solicitud no encontrada o no está en curso.');
}

$fase = (int) $solicitud['fase_actual'];
$faseConToken = $solicitud['tipo'] === 'informe_tecnico' ? [2] : [3, 4];
if (!in_array($fase, $faseConToken, true)) {
    pantalla_aviso($u, 'En esta fase no se puede firmar de forma presencial.');
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
    pantalla_aviso($u, 'No se encontró quién debe firmar en esta fase.');
}

// Se ejecuta cuando se envía el formulario.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Verifica el token de seguridad del formulario.
    csrf_check();
    // Los cambios se guardan todos juntos o no se guarda ninguno.
    $conn->begin_transaction();
    try {
        // Crea el link de un solo uso para el firmante.
        $token = crear_token($conn, $id, $fase, $destino['destinatario_correo'], $destino['destinatario_nombre'], 2);

        $comentario = 'Firma presencial facilitada por ' . $u['nombre'] . ' para ' . $destino['destinatario_nombre'];
        $stmt = $conn->prepare(
            "INSERT INTO historial (solicitud_id, fase_origen, fase_destino, accion, usuario_id, comentario)
             VALUES (?, ?, ?, 'reenviar', ?, ?)"
        );
        $stmt->bind_param('iiiis', $id, $fase, $fase, $u['id'], $comentario);
        $stmt->execute();
        $stmt->close();

        $conn->commit();
    } catch (Throwable $e) {
        // Si algo falló, se deshacen los cambios.
        $conn->rollback();
        pantalla_aviso($u, 'No se pudo preparar la firma.');
    }
    // Redirige a otra página.
    header('Location: confirmar.php?token=' . $token);
    exit;
}

// Dibuja la cabecera de la página.
render_header('Firma presencial', $u);
?>
<h1>Firma presencial</h1>
<p>Solicitud <strong><?= h($solicitud['folio']) ?></strong>, Fase <?= $fase ?>.</p>
<p>Firma: <strong><?= h($destino['destinatario_nombre']) ?></strong>.</p>
<p>Se abrirá la pantalla de firma en este dispositivo. Entrégaselo a la persona para que revise y firme.
   Quedará registrado en el historial que tú facilitaste la firma.</p>

<form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="id" value="<?= (int) $id ?>">
    <button type="submit" class="primario">Abrir pantalla de firma</button>
    <a href="mis_solicitudes.php" class="chico">Cancelar</a>
</form>
<?php render_footer(); ?>