<?php
// Revisión de la Fase 2: el jefe que entrega aprueba (con firma) o rechaza.
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/firmas.php';
require_once __DIR__ . '/includes/correo.php';
require_once __DIR__ . '/includes/documento.php';
require_once __DIR__ . '/includes/tokens.php';
require_once __DIR__ . '/vendor/autoload.php';

// Solo los roles indicados pueden entrar a esta página.
$u = require_rol(['encargado', 'admin']);

$id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);

$stmt = $conn->prepare(
    "SELECT s.*, us.nombre AS creador_nombre, us.correo AS creador_correo
     FROM solicitudes s
     JOIN usuarios us ON us.id = s.creado_por
     WHERE s.id = ? AND s.fase_actual = 2 AND s.estado = 'en_curso'"
);
$stmt->bind_param('i', $id);
$stmt->execute();
$solicitud = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$solicitud) {
    // Dibuja la cabecera de la página.
    render_header('Solicitud no encontrada', $u);
    echo '<p class="error">Esta solicitud no existe o ya no está en Fase 2.</p>';
    echo '<p><a href="fase2_pendientes.php">← Volver a pendientes</a></p>';
    render_footer();
    exit;
}

$datos = json_decode($solicitud['datos'], true);

$esRecepcion         = $solicitud['tipo'] === 'recepcion';
$labelCustodioActual = $esRecepcion ? 'Custodio que recibe (DTIC)' : 'Custodio que entrega';
$labelCustodioNuevo  = $esRecepcion ? 'Custodio que entrega (devuelve)' : 'Custodio que recibe';
$labelJefeFase2      = $esRecepcion ? 'jefe de sector que recibe (DTIC)' : 'jefe de sector que entrega';
$labelJefeFase3      = $esRecepcion ? 'jefe de sector que entrega (quien devuelve)' : 'jefe de sector que recibe';

$equipos = $conn->query(
    "SELECT e.codigo_inventario, e.descripcion, e.serie
     FROM solicitud_equipos se
     JOIN equipos e ON e.id = se.equipo_id
     WHERE se.solicitud_id = " . (int) $id
)->fetch_all(MYSQLI_ASSOC);

$error = '';

// Se ejecuta cuando se envía el formulario.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Verifica el token de seguridad del formulario.
    csrf_check();
    $accion = $_POST['accion'] ?? '';
    $comentario = trim($_POST['comentario'] ?? '');
    $firmaDato = $_POST['firma_dato'] ?? '';
    $firmaArchivo = $_FILES['firma_archivo'] ?? null;

    if ($accion === 'rechazar' && $comentario === '') {
        $error = 'Debes indicar el motivo del rechazo.';
    } elseif ($errLargo = validar_largos(['El comentario' => [$comentario, 1000]])) {
        $error = $errLargo;
    } elseif ($accion === 'aprobar' && $firmaDato === '' && (!$firmaArchivo || $firmaArchivo['error'] !== UPLOAD_ERR_OK)) {
        $error = 'Debes firmar para aprobar la solicitud.';
    } elseif (!in_array($accion, ['aprobar', 'rechazar'], true)) {
        $error = 'Acción no válida.';
    } elseif ($accion === 'aprobar' && ($errFirma = validar_firma($firmaDato, $firmaArchivo))) {
        $error = $errFirma;
    }

    if (!$error) {
        // Los cambios se guardan todos juntos o no se guarda ninguno.
        $conn->begin_transaction();
        try {
            $stmt = $conn->prepare("SELECT *, TIMESTAMPDIFF(SECOND, fase_desde, NOW()) AS seg_en_fase FROM solicitudes WHERE id = ? AND fase_actual = 2 AND estado = 'en_curso' FOR UPDATE");
            $stmt->bind_param('i', $id);
            $stmt->execute();
            $solicitudBloqueada = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!$solicitudBloqueada) {
                throw new RuntimeException('La solicitud ya no está en Fase 2 (puede que otra persona ya la haya revisado).');
            }

            $folio = $solicitudBloqueada['folio'];

            $segundos = (int) $solicitudBloqueada['seg_en_fase'];

            if ($accion === 'aprobar') {
                $dirFirmas = __DIR__ . '/firmas';
                if (!is_dir($dirFirmas)) mkdir($dirFirmas, 0775, true);
                $rutaFirma = $dirFirmas . "/{$folio}_jefe_entrega.png";

                guardar_firma($firmaDato, $firmaArchivo, $rutaFirma);

                $stmt = $conn->prepare("UPDATE solicitudes SET fase_actual = 3, fase_desde = NOW() WHERE id = ?");
                $stmt->bind_param('i', $id);
                $stmt->execute();
                $stmt->close();

                $stmt = $conn->prepare(
                    "INSERT INTO historial (solicitud_id, fase_origen, fase_destino, accion, usuario_id, comentario, segundos_en_fase)
                     VALUES (?, 2, 3, 'aprobar', ?, ?, ?)"
                );
                $comentarioAprobar = $comentario !== '' ? $comentario : null;
                $stmt->bind_param('iisi', $id, $u['id'], $comentarioAprobar, $segundos);
                $stmt->execute();
                $stmt->close();

                $conn->commit();

                $stmtSol = $conn->prepare('SELECT * FROM solicitudes WHERE id = ?');
                $stmtSol->bind_param('i', $id);
                $stmtSol->execute();
                $solicitudActualizada = $stmtSol->get_result()->fetch_assoc();
                $stmtSol->close();

                generar_tbi($solicitudActualizada, $equipos, false, $esRecepcion ? 'rbi.docx' : 'tbi.docx', $esRecepcion ? 'RBI' : 'TBI');

                // Crea el link de un solo uso para el firmante.
                $token = crear_token($conn, $id, 3, $solicitudActualizada['jefe_recibe_correo'], $solicitudActualizada['jefe_recibe_nombre']);
                $link = url_base() . '/confirmar.php?token=' . $token;

                $cuerpo = "
                    <p>Se te solicita revisar el traspaso de equipos.</p>
                    <p><strong>Folio:</strong> {$folio}<br>
                       <strong>" . h(ucfirst($labelCustodioNuevo)) . ":</strong> " . h($solicitudActualizada['solicitante_nombre']) . "</p>
                    <p><a href=\"{$link}\">Haz clic aquí para revisar y aprobar o rechazar</a></p>
                    <p>Este link es de un solo uso y expira en 72 horas.</p>
                ";
                enviar_correo($solicitudActualizada['jefe_recibe_correo'], $solicitudActualizada['jefe_recibe_nombre'],
                    "Solicitud $folio - Requiere tu aprobación", $cuerpo);

                $cuerpoCreador = "
                    <p>Tu solicitud <strong>{$folio}</strong> fue <strong>aprobada</strong> por el " . h($labelJefeFase2) . "
                       y avanzó a la <strong>Fase 3</strong> (revisión del " . h($labelJefeFase3) . ").</p>
                ";
                enviar_correo($solicitud['creador_correo'], $solicitud['creador_nombre'],
                    "Solicitud $folio - Aprobada, avanzó a Fase 3", $cuerpoCreador);

                if (strcasecmp($solicitudActualizada['solicitante_correo'], $solicitud['creador_correo']) !== 0) {
                    $cuerpoUsuario = "
                        <p>Tu solicitud <strong>{$folio}</strong> fue <strong>aprobada</strong> por el " . h($labelJefeFase2) . "
                           y avanzó a la <strong>Fase 3</strong> (revisión del " . h($labelJefeFase3) . ").</p>
                    ";
                    enviar_correo($solicitudActualizada['solicitante_correo'], $solicitudActualizada['solicitante_nombre'],
                        "Solicitud $folio - Aprobada, avanzó a Fase 3", $cuerpoUsuario);
                }

                // Redirige a otra página.
                header('Location: fase2_pendientes.php?ok=aprobada');
                exit;
            }

            if ($accion === 'rechazar') {
                $stmt = $conn->prepare("UPDATE solicitudes SET fase_actual = 1, fase_desde = NOW() WHERE id = ?");
                $stmt->bind_param('i', $id);
                $stmt->execute();
                $stmt->close();

                $stmt = $conn->prepare(
                    "INSERT INTO historial (solicitud_id, fase_origen, fase_destino, accion, usuario_id, comentario, segundos_en_fase)
                     VALUES (?, 2, 1, 'rechazar', ?, ?, ?)"
                );
                $stmt->bind_param('iisi', $id, $u['id'], $comentario, $segundos);
                $stmt->execute();
                $stmt->close();

                $conn->commit();

                $linkCorregir = url_base() . '/corregir.php?id=' . $id;
                $cuerpoRechazo = "
                    <p>Tu solicitud <strong>{$folio}</strong> fue <strong>rechazada</strong> por " . h($u['nombre']) . "
                       y volvió a <strong>Fase 1</strong> para corrección.</p>
                    <p><strong>Motivo:</strong> " . nl2br(h($comentario)) . "</p>
                    <p><a href=\"{$linkCorregir}\">Haz clic aquí para corregir y reenviar la solicitud</a></p>
                ";
                enviar_correo($solicitud['creador_correo'], $solicitud['creador_nombre'],
                    "Solicitud $folio - Rechazada, requiere corrección", $cuerpoRechazo);

                if (strcasecmp($solicitud['solicitante_correo'], $solicitud['creador_correo']) !== 0) {
                    $cuerpoRechazoUsuario = "
                        <p>La solicitud <strong>{$folio}</strong> fue <strong>rechazada</strong> por " . h($u['nombre']) . "
                           y volvió a <strong>Fase 1</strong> para corrección.</p>
                        <p><strong>Motivo:</strong> " . nl2br(h($comentario)) . "</p>
                    ";
                    enviar_correo($solicitud['solicitante_correo'], $solicitud['solicitante_nombre'],
                        "Solicitud $folio - Rechazada, requiere corrección", $cuerpoRechazoUsuario);
                }

                // Redirige a otra página.
                header('Location: fase2_pendientes.php?ok=rechazada');
                exit;
            }
        } catch (Throwable $e) {
            // Si algo falló, se deshacen los cambios.
            $conn->rollback();
            $error = 'No se pudo procesar: ' . $e->getMessage();
        }
    }
}

// Dibuja la cabecera de la página.
render_header('Revisar solicitud', $u);
?>
<h1>Revisar solicitud <?= h($solicitud['folio']) ?></h1>

<?php if ($error): ?><p class="error"><?= h($error) ?></p><?php endif; ?>

<p><strong>Creada por:</strong> <?= h($solicitud['creador_nombre']) ?></p>
<p><strong>Tipo de traspaso:</strong> <?= h($datos['tipo_traspaso']) ?>
    <?= $datos['tipo_traspaso'] === 'temporal' ? '(' . h($datos['plazo']) . ' días)' : '' ?>
</p>

<h2>Equipos</h2>
<table class="tabla">
    <thead><tr><th>Código</th><th>Descripción</th><th>Serie</th></tr></thead>
    <tbody>
        <?php foreach ($equipos as $e): ?>
            <tr>
                <td><?= h($e['codigo_inventario'] ?: '—') ?></td>
                <td><?= h($e['descripcion']) ?></td>
                <td><?= h($e['serie'] ?: '—') ?></td>
            </tr>
        <?php endforeach; ?>
    </tbody>
</table>

<div class="dos-columnas">
    <fieldset>
        <legend><?= h(ucfirst($labelCustodioActual)) ?></legend>
        <p><?= h($datos['custodio_actual']) ?> · <?= h($datos['unidad_actual']) ?></p>
    </fieldset>
    <fieldset>
        <legend><?= h(ucfirst($labelCustodioNuevo)) ?></legend>
        <p><?= h($solicitud['solicitante_nombre']) ?> · <?= h($datos['unidad_nueva']) ?></p>
        <p>Jefe de sector: <?= h($solicitud['jefe_recibe_nombre']) ?> (<?= h($solicitud['jefe_recibe_correo']) ?>)</p>
    </fieldset>
</div>

<p><strong>Observaciones:</strong> <?= h($datos['observaciones'] ?: '—') ?></p>
<h2>Documento a revisar</h2>
<iframe class="visor-pdf vertical" src="ver_documento.php?id=<?= (int) $id ?>#toolbar=0&navpanes=0&view=FitH" title="Documento"></iframe>

<h2>Decisión</h2>
<form method="post" class="form-solicitud" enctype="multipart/form-data" id="form-revision">
    <?= csrf_field() ?>
    <input type="hidden" name="id" value="<?= (int) $id ?>">
    <input type="hidden" name="accion" id="accion" value="">

    <h3>Si apruebas: firma aquí</h3>
    <div class="firma-tabs">
        <button type="button" id="tab-dibujar" class="activo" onclick="mostrarFirmaTab('dibujar')">Dibujar firma</button>
        <button type="button" id="tab-subir" onclick="mostrarFirmaTab('subir')">Subir imagen</button>
    </div>
    <div id="panel-dibujar" class="firma-panel activo">
        <canvas id="firma-canvas" width="400" height="150"></canvas>
        <div class="firma-acciones">
            <button type="button" onclick="limpiarFirma()">Limpiar</button>
        </div>
    </div>
    <div id="panel-subir" class="firma-panel">
        <input type="file" name="firma_archivo" id="firma_archivo" accept="image/png,image/jpeg">
    </div>
    <input type="hidden" name="firma_dato" id="firma_dato">

    <h3>Si rechazas: indica el motivo</h3>
    <label><textarea name="comentario" id="comentario" rows="3" maxlength="1000" placeholder="Qué hay que corregir..."></textarea></label>

    <div class="fila-radios">
        <button type="button" class="primario" onclick="enviarComo('aprobar')">Aprobar</button>
        <button type="button" onclick="enviarComo('rechazar')">Rechazar (vuelve a corrección)</button>
    </div>
</form>

<p><a href="fase2_pendientes.php">← Volver a pendientes</a></p>

<script>
// Cambia entre las pestañas de la firma (dibujar o subir archivo).
function mostrarFirmaTab(tab) {
    document.getElementById('tab-dibujar').classList.toggle('activo', tab === 'dibujar');
    document.getElementById('tab-subir').classList.toggle('activo', tab === 'subir');
    document.getElementById('panel-dibujar').classList.toggle('activo', tab === 'dibujar');
    document.getElementById('panel-subir').classList.toggle('activo', tab === 'subir');
}

const canvas = document.getElementById('firma-canvas');
const ctx = canvas.getContext('2d');
ctx.lineWidth = 3;
ctx.lineCap = 'round';
ctx.strokeStyle = '#000';
let dibujando = false;

// Pinta el recuadro de firma de blanco.
function fondoBlancoCanvas() {
    ctx.fillStyle = '#fff';
    ctx.fillRect(0, 0, canvas.width, canvas.height);
    ctx.strokeStyle = '#000';
}
fondoBlancoCanvas();

// Calcula dónde está el dedo o el mouse dentro del recuadro.
function posicion(e) {
    const rect = canvas.getBoundingClientRect();
    const punto = e.touches ? e.touches[0] : e;
    return { x: (punto.clientX - rect.left) * (canvas.width / rect.width), y: (punto.clientY - rect.top) * (canvas.height / rect.height) };
}
// Empieza un trazo.
function empezar(e) { dibujando = true; const p = posicion(e); ctx.beginPath(); ctx.moveTo(p.x, p.y); e.preventDefault(); }
// Dibuja el trazo mientras se mueve el dedo o el mouse.
function mover(e) { if (!dibujando) return; const p = posicion(e); ctx.lineTo(p.x, p.y); ctx.stroke(); e.preventDefault(); }
// Termina el trazo.
function terminar() { dibujando = false; }

canvas.addEventListener('mousedown', empezar);
canvas.addEventListener('mousemove', mover);
canvas.addEventListener('mouseup', terminar);
canvas.addEventListener('mouseleave', terminar);
canvas.addEventListener('touchstart', empezar);
canvas.addEventListener('touchmove', mover);
canvas.addEventListener('touchend', terminar);

// Borra la firma dibujada.
function limpiarFirma() { fondoBlancoCanvas(); }

// Indica si el recuadro de firma está vacío.
function canvasVacio() {
    const pixeles = ctx.getImageData(0, 0, canvas.width, canvas.height).data;
    for (let i = 0; i < pixeles.length; i += 4) {
        if (pixeles[i] !== 255 || pixeles[i + 1] !== 255 || pixeles[i + 2] !== 255) return false;
    }
    return true;
}

// Revisa lo necesario y envía el formulario con la acción elegida.
function enviarComo(accion) {
    const form = document.getElementById('form-revision');

    if (accion === 'rechazar') {
        if (document.getElementById('comentario').value.trim() === '') {
            alert('Indica el motivo del rechazo.');
            return;
        }
        document.getElementById('accion').value = 'rechazar';
        form.submit();
        return;
    }

    const subirActivo = document.getElementById('panel-subir').classList.contains('activo');
    if (!subirActivo) {
        if (canvasVacio()) {
            alert('Dibuja tu firma o sube una imagen para aprobar.');
            return;
        }
        document.getElementById('firma_dato').value = canvas.toDataURL('image/png');
    }
    document.getElementById('accion').value = 'aprobar';
    form.submit();
}
</script>

<?php render_footer(); ?>
