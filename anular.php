<?php
// Anula una solicitud en curso (solo admin) y libera los equipos que tenía reservados.
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/correo.php';

// Solo los roles indicados pueden entrar a esta página.
$u = require_rol(['admin']);

$id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);

$stmt = $conn->prepare(
    "SELECT s.*, us.nombre AS creador_nombre, us.correo AS creador_correo
     FROM solicitudes s JOIN usuarios us ON us.id = s.creado_por
     WHERE s.id = ? AND s.estado = 'en_curso'"
);
$stmt->bind_param('i', $id);
$stmt->execute();
$solicitud = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$solicitud) {
    // Dibuja la cabecera de la página.
    render_header('Solicitud no encontrada', $u);
    echo '<p class="error">Esta solicitud no existe o ya no está en curso (puede que ya haya sido finalizada o anulada).</p>';
    echo '<p><a href="mis_solicitudes.php">← Volver a mis solicitudes</a></p>';
    render_footer();
    exit;
}

$error = '';
$anulada = false;

// Se ejecuta cuando se envía el formulario.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Verifica el token de seguridad del formulario.
    csrf_check();
    $motivo = trim($_POST['motivo'] ?? '');

    if ($motivo === '') {
        $error = 'Debes indicar el motivo de la anulación.';
    } elseif ($errLargo = validar_largos(['El motivo' => [$motivo, 1000]])) {
        $error = $errLargo;
    } else {
        // Los cambios se guardan todos juntos o no se guarda ninguno.
        $conn->begin_transaction();
        try {
            $idsAAnular = [$id];
            if ($solicitud['tipo'] === 'recepcion') {
                $stmtLig = $conn->prepare(
                    "SELECT id FROM solicitudes WHERE solicitud_origen_id = ? AND tipo = 'informe_tecnico' AND estado = 'en_curso' FOR UPDATE"
                );
                $stmtLig->bind_param('i', $id);
                $stmtLig->execute();
                if ($lig = $stmtLig->get_result()->fetch_assoc()) {
                    $idsAAnular[] = (int) $lig['id'];
                }
                $stmtLig->close();
            } elseif ($solicitud['tipo'] === 'informe_tecnico' && !empty($solicitud['solicitud_origen_id'])) {
                $stmtLig = $conn->prepare(
                    "SELECT id FROM solicitudes WHERE id = ? AND estado = 'en_curso' FOR UPDATE"
                );
                $stmtLig->bind_param('i', $solicitud['solicitud_origen_id']);
                $stmtLig->execute();
                if ($lig = $stmtLig->get_result()->fetch_assoc()) {
                    $idsAAnular[] = (int) $lig['id'];
                }
                $stmtLig->close();
            }

            $solicitudesAnuladas = [];
            foreach ($idsAAnular as $sid) {
                $stmtS = $conn->prepare(
                    "SELECT s.*, us.nombre AS creador_nombre, us.correo AS creador_correo
                     FROM solicitudes s JOIN usuarios us ON us.id = s.creado_por
                     WHERE s.id = ? AND s.estado = 'en_curso' FOR UPDATE"
                );
                $stmtS->bind_param('i', $sid);
                $stmtS->execute();
                $solAnular = $stmtS->get_result()->fetch_assoc();
                $stmtS->close();
                if (!$solAnular) {
                    continue;
                }

                $stmt = $conn->prepare("UPDATE solicitudes SET estado = 'anulada' WHERE id = ?");
                $stmt->bind_param('i', $sid);
                $stmt->execute();
                $stmt->close();

                $stmt = $conn->prepare(
                    "INSERT INTO historial (solicitud_id, fase_origen, fase_destino, accion, usuario_id, comentario)
                     VALUES (?, ?, NULL, 'anular', ?, ?)"
                );
                $stmt->bind_param('iiis', $sid, $solAnular['fase_actual'], $u['id'], $motivo);
                $stmt->execute();
                $stmt->close();

                $stmt = $conn->prepare("UPDATE equipos SET bloqueado_por = NULL WHERE bloqueado_por = ?");
                $stmt->bind_param('i', $sid);
                $stmt->execute();
                $stmt->close();

                $solicitudesAnuladas[] = $solAnular;
            }

            if ($solicitud['tipo'] === 'informe_tecnico' && !empty($solicitud['solicitud_origen_id'])) {
                $stmt = $conn->prepare("UPDATE equipos SET bloqueado_por = NULL WHERE bloqueado_por = ?");
                $stmt->bind_param('i', $solicitud['solicitud_origen_id']);
                $stmt->execute();
                $stmt->close();
            }

            if ($solicitud['tipo'] === 'recepcion') {
                $stmtITFin = $conn->prepare(
                    "SELECT s.*, us.nombre AS creador_nombre, us.correo AS creador_correo
                     FROM solicitudes s JOIN usuarios us ON us.id = s.creado_por
                     WHERE s.solicitud_origen_id = ? AND s.tipo = 'informe_tecnico' AND s.estado = 'finalizada' FOR UPDATE"
                );
                $stmtITFin->bind_param('i', $id);
                $stmtITFin->execute();
                $informeFinalizado = $stmtITFin->get_result()->fetch_assoc();
                $stmtITFin->close();

                if ($informeFinalizado) {
                    $stmt = $conn->prepare("UPDATE solicitudes SET estado = 'anulada' WHERE id = ?");
                    $stmt->bind_param('i', $informeFinalizado['id']);
                    $stmt->execute();
                    $stmt->close();

                    $stmt = $conn->prepare(
                        "INSERT INTO historial (solicitud_id, fase_origen, fase_destino, accion, usuario_id, comentario)
                         VALUES (?, ?, NULL, 'anular', ?, ?)"
                    );
                    $motivoIT = 'Anulado automáticamente: la Recepción que lo originó fue anulada después de que este informe ya estaba finalizado. ' . $motivo;
                    $stmt->bind_param('iiis', $informeFinalizado['id'], $informeFinalizado['fase_actual'], $u['id'], $motivoIT);
                    $stmt->execute();
                    $stmt->close();

                    $solicitudesAnuladas[] = $informeFinalizado;
                }
            }

            $conn->commit();

            foreach ($solicitudesAnuladas as $solAnular) {
                $datos = json_decode($solAnular['datos'], true) ?: [];
                $dest = [];
                $dest[strtolower($solAnular['creador_correo'])] = [$solAnular['creador_correo'], $solAnular['creador_nombre']];
                if (!empty($solAnular['solicitante_correo'])) {
                    $dest[strtolower($solAnular['solicitante_correo'])] = [$solAnular['solicitante_correo'], $solAnular['solicitante_nombre']];
                }
                if (!empty($solAnular['jefe_recibe_correo'])) {
                    $dest[strtolower($solAnular['jefe_recibe_correo'])] = [$solAnular['jefe_recibe_correo'], $solAnular['jefe_recibe_nombre'] ?? 'Jefe de sector'];
                }
                if (!empty($datos['jefe_entrega_correo'])) {
                    $dest[strtolower($datos['jefe_entrega_correo'])] = [$datos['jefe_entrega_correo'], $datos['jefe_actual'] ?? 'Jefe de sector'];
                }
                if (!empty($datos['jefe_tecnico_correo'])) {
                    $dest[strtolower($datos['jefe_tecnico_correo'])] = [$datos['jefe_tecnico_correo'], $datos['jefe_tecnico_nombre'] ?? 'Jefe de unidad técnica'];
                }

                $cuerpo = "<p>La solicitud <strong>" . h($solAnular['folio']) . "</strong> fue <strong>anulada</strong> por un administrador.</p>
                           <p><strong>Motivo:</strong> " . nl2br(h($motivo)) . "</p>
                           <p>No es necesario que realices ninguna acción adicional sobre esta solicitud.</p>";
                foreach ($dest as [$correoD, $nombreD]) {
                    enviar_correo($correoD, $nombreD, "Solicitud {$solAnular['folio']} - Anulada", $cuerpo);
                }
            }

            $anulada = true;
        } catch (Throwable $e) {
            // Si algo falló, se deshacen los cambios.
            $conn->rollback();
            $error = 'No se pudo anular la solicitud: ' . $e->getMessage();
        }
    }
}

// Dibuja la cabecera de la página.
render_header('Anular solicitud', $u);
?>
<h1>Anular solicitud <?= h($solicitud['folio']) ?></h1>

<?php if ($anulada): ?>
    <p class="ok">
        La solicitud <?= h($solicitud['folio']) ?> fue anulada correctamente
        <?php if ($solicitud['tipo'] === 'recepcion' || $solicitud['tipo'] === 'informe_tecnico'): ?>
            (junto con su Informe Técnico/Recepción ligada, si correspondía)
        <?php endif; ?>.
        Se notificó por correo a los involucrados y se liberaron los equipos reservados.
    </p>
    <p><a href="mis_solicitudes.php">← Volver a mis solicitudes</a></p>
<?php else: ?>

<?php if ($error): ?><p class="error"><?= h($error) ?></p><?php endif; ?>

<p class="aviso">
    Vas a anular la solicitud <strong><?= h($solicitud['folio']) ?></strong> (<?= h(ucfirst($solicitud['tipo'])) ?>),
    actualmente en Fase <?= (int) $solicitud['fase_actual'] ?>.
    <?php if ($solicitud['tipo'] === 'recepcion'): ?>
        Si tiene un Informe Técnico ligado todavía en curso, también se anulará.
    <?php elseif ($solicitud['tipo'] === 'informe_tecnico' && !empty($solicitud['solicitud_origen_id'])): ?>
        Su Recepción de origen (si sigue en curso) también se anulará.
    <?php endif; ?>
    Esta acción no se puede deshacer. Se liberarán los equipos reservados y se avisará por correo a todos los
    involucrados con el motivo que indiques.
</p>

<form method="post" class="form-linea">
    <?= csrf_field() ?>
    <input type="hidden" name="id" value="<?= (int) $id ?>">
    <label>Motivo de la anulación
        <textarea name="motivo" rows="3" required maxlength="1000" placeholder="Ej: se ingresó un correo equivocado al crear la solicitud."></textarea>
    </label>
    <button type="submit" class="peligro" onclick="return confirm('¿Seguro que quieres anular esta solicitud? No se puede deshacer.');">Anular solicitud</button>
</form>

<p><a href="mis_solicitudes.php">← Cancelar y volver</a></p>

<?php endif; ?>
<?php render_footer(); ?>
