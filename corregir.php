<?php
// Permite al creador corregir y reenviar una solicitud rechazada en la Fase 2.
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/firmas.php';
require_once __DIR__ . '/includes/correo.php';
require_once __DIR__ . '/includes/documento.php';
require_once __DIR__ . '/includes/custodio.php';
require_once __DIR__ . '/includes/tokens.php';
require_once __DIR__ . '/vendor/autoload.php';

// Solo los roles indicados pueden entrar a esta página.
$u = require_rol(['responsable', 'encargado', 'admin']);

$id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);

$stmt = $conn->prepare(
    "SELECT * FROM solicitudes WHERE id = ? AND tipo IN ('entrega', 'recepcion') AND fase_actual = 1 AND estado = 'en_curso'"
);
$stmt->bind_param('i', $id);
$stmt->execute();
$solicitud = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$solicitud) {
    // Dibuja la cabecera de la página.
    render_header('Solicitud no encontrada', $u);
    echo '<p class="error">Esta solicitud no existe o ya no está en Fase 1.</p>';
    echo '<p><a href="mis_solicitudes.php">← Volver a mis solicitudes</a></p>';
    render_footer();
    exit;
}

if ((int) $solicitud['creado_por'] !== (int) $u['id'] && !in_array($u['rol'], ['admin', 'encargado'], true)) {
    // Dibuja la cabecera de la página.
    render_header('Sin permiso', $u);
    echo '<p class="error">No puedes corregir una solicitud que no creaste tú.</p>';
    echo '<p><a href="mis_solicitudes.php">← Volver a mis solicitudes</a></p>';
    render_footer();
    exit;
}

$datos = json_decode($solicitud['datos'], true);

$esRecepcion         = $solicitud['tipo'] === 'recepcion';
$labelCustodioActual = $esRecepcion ? 'Custodio que recibe (DTIC)' : 'Custodio que entrega (DTIC)';
$labelCustodioNuevo  = $esRecepcion ? 'Custodio que entrega (devuelve)' : 'Custodio que recibe (solicitante)';
$labelJefeFase2      = $esRecepcion ? 'jefe de sector que recibe' : 'jefe de sector que entrega';

$stmt = $conn->prepare(
    "SELECT comentario, creado_en FROM historial
     WHERE solicitud_id = ? AND accion = 'rechazar'
     ORDER BY creado_en DESC LIMIT 1"
);
$stmt->bind_param('i', $id);
$stmt->execute();
$ultimoRechazo = $stmt->get_result()->fetch_assoc();
$stmt->close();

$equipos = $conn->query(
    "SELECT e.id, e.codigo_inventario, e.descripcion, e.serie
     FROM solicitud_equipos se
     JOIN equipos e ON e.id = se.equipo_id
     WHERE se.solicitud_id = " . (int) $id
)->fetch_all(MYSQLI_ASSOC);

$custodioBloqueado = null;
$errCustodio = '';
if ($esRecepcion) {
    [$custodioBloqueado, $errCustodio] = custodio_unico($conn, array_column($equipos, 'id'));
}

$error = '';
$corregida = false;

// Se ejecuta cuando se envía el formulario.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Verifica el token de seguridad del formulario.
    csrf_check();

    $custodioActual = trim($_POST['custodio_actual'] ?? '');
    $runActual      = trim($_POST['run_actual'] ?? '');
    $unidadActual   = trim($_POST['unidad_actual'] ?? '');
    $codUbicActual  = trim($_POST['cod_ubic_actual'] ?? '');
    $ubicActual     = trim($_POST['ubic_actual'] ?? '');
    $jefeActual     = trim($_POST['jefe_actual'] ?? '');
    $jefeEntregaCorreo = trim($_POST['jefe_entrega_correo'] ?? '');

    $custodioNuevo  = trim($_POST['custodio_nuevo'] ?? '');
    $runNuevo       = trim($_POST['run_nuevo'] ?? '');
    $unidadNueva    = trim($_POST['unidad_nueva'] ?? '');
    $codUbicNueva   = trim($_POST['cod_ubic_nueva'] ?? '');
    $ubicNueva      = trim($_POST['ubic_nueva'] ?? '');
    $correoNuevo    = trim($_POST['correo_nuevo'] ?? '');

    $jefeRecibeNombre = trim($_POST['jefe_recibe_nombre'] ?? '');
    $jefeRecibeCorreo = trim($_POST['jefe_recibe_correo'] ?? '');

    if ($custodioBloqueado) {
        $custodioNuevo    = $custodioBloqueado['custodio_nuevo'];
        $runNuevo         = $custodioBloqueado['run_nuevo'];
        $unidadNueva      = $custodioBloqueado['unidad_nueva'];
        $codUbicNueva     = $custodioBloqueado['cod_ubic_nueva'];
        $ubicNueva        = $custodioBloqueado['ubic_nueva'];
        $correoNuevo      = $custodioBloqueado['correo_nuevo'];
    }

    $tipoTraspaso   = $_POST['tipo_traspaso'] ?? 'definitivo';
    $plazo          = trim($_POST['plazo'] ?? '');
    $reasignacion   = $_POST['reasignacion'] ?? 'custodio_ubicacion';
    $observaciones  = trim($_POST['observaciones'] ?? '');

    $firmaDato    = $_POST['firma_dato'] ?? '';
    $firmaArchivo = $_FILES['firma_archivo'] ?? null;

    if ($errCustodio !== '') {
        $error = $errCustodio;
    } elseif ($custodioActual === '' || $custodioNuevo === '') {
        $error = 'Faltan datos de los custodios.';
    } elseif (!validar_rut($runActual)) {
        $error = 'El RUT del custodio que entrega no es válido.';
    } elseif (!validar_rut($runNuevo)) {
        $error = 'El RUT del custodio que recibe no es válido.';
    } elseif ($correoNuevo === '' || !filter_var($correoNuevo, FILTER_VALIDATE_EMAIL)) {
        $error = 'El correo del solicitante no es válido.';
    } elseif ($jefeRecibeNombre === '' || $jefeRecibeCorreo === '' || !filter_var($jefeRecibeCorreo, FILTER_VALIDATE_EMAIL)) {
        $error = 'Falta el nombre o el correo del jefe de sector que recibe.';
    } elseif ($jefeActual === '' || $jefeEntregaCorreo === '' || !filter_var($jefeEntregaCorreo, FILTER_VALIDATE_EMAIL)) {
        $error = 'Falta el nombre o el correo del jefe que entrega.';
    } elseif ($errLargo = validar_largos($esRecepcion ? [
        'El nombre de quien devuelve el equipo'            => [$custodioNuevo, 150],
        'El correo de quien devuelve el equipo'            => [$correoNuevo, 150],
        'La ubicación actual de quien devuelve'            => [$ubicNueva, 150],
        'El nombre del jefe de sector de quien devuelve'   => [$jefeRecibeNombre, 150],
        'El correo del jefe de sector de quien devuelve'   => [$jefeRecibeCorreo, 150],
    ] : [
        'El nombre del custodio que recibe'                => [$custodioNuevo, 150],
        'El correo del solicitante'                        => [$correoNuevo, 150],
        'La ubicación nueva'                               => [$ubicNueva, 150],
        'El nombre del jefe de sector que recibe'          => [$jefeRecibeNombre, 150],
        'El correo del jefe de sector que recibe'          => [$jefeRecibeCorreo, 150],
    ])) {
        $error = $errLargo;
    } elseif ($tipoTraspaso === 'temporal' && $plazo === '') {
        $error = 'Indica el plazo del traspaso temporal.';
    } elseif ($errFirma = validar_firma($firmaDato, $firmaArchivo)) {
        $error = $errFirma;
    }

    if (!$error) {
        // Los cambios se guardan todos juntos o no se guarda ninguno.
        $conn->begin_transaction();
        try {
            $stmt = $conn->prepare("SELECT *, TIMESTAMPDIFF(SECOND, fase_desde, NOW()) AS seg_en_fase FROM solicitudes WHERE id = ? AND tipo IN ('entrega', 'recepcion') AND fase_actual = 1 AND estado = 'en_curso' FOR UPDATE");
            $stmt->bind_param('i', $id);
            $stmt->execute();
            $solicitudBloqueada = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!$solicitudBloqueada) {
                throw new RuntimeException('La solicitud ya no está en Fase 1 (puede que ya haya sido corregida).');
            }

            $folio = $solicitudBloqueada['folio'];
            $segundos = (int) $solicitudBloqueada['seg_en_fase'];

            $datosNuevos = [
                'run_actual' => $runActual, 'unidad_actual' => $unidadActual,
                'cod_ubic_actual' => $codUbicActual, 'ubic_actual' => $ubicActual, 'jefe_actual' => $jefeActual, 'jefe_entrega_correo' => $jefeEntregaCorreo,
                'custodio_actual' => $custodioActual,
                'run_nuevo' => $runNuevo, 'unidad_nueva' => $unidadNueva,
                'cod_ubic_nueva' => $codUbicNueva, 'ubic_nueva' => $ubicNueva,
                'tipo_traspaso' => $tipoTraspaso, 'plazo' => $plazo,
                'reasignacion' => $reasignacion, 'observaciones' => $observaciones,
            ];
            $datosJson = json_encode($datosNuevos, JSON_UNESCAPED_UNICODE);

            $stmt = $conn->prepare(
                "UPDATE solicitudes SET
                    solicitante_nombre = ?, solicitante_correo = ?,
                    jefe_recibe_nombre = ?, jefe_recibe_correo = ?,
                    datos = ?, fase_actual = 2, fase_desde = NOW()
                 WHERE id = ?"
            );
            $stmt->bind_param('sssssi', $custodioNuevo, $correoNuevo, $jefeRecibeNombre, $jefeRecibeCorreo, $datosJson, $id);
            $stmt->execute();
            $stmt->close();

            $stmt = $conn->prepare(
                "INSERT INTO historial (solicitud_id, fase_origen, fase_destino, accion, usuario_id, comentario, segundos_en_fase)
                 VALUES (?, 1, 2, 'corregir', ?, 'Solicitud corregida y reenviada a Fase 2.', ?)"
            );
            $stmt->bind_param('iii', $id, $u['id'], $segundos);
            $stmt->execute();
            $stmt->close();

            $conn->commit();

            $dirFirmas = __DIR__ . '/firmas';
            if (!is_dir($dirFirmas)) mkdir($dirFirmas, 0775, true);
            $rutaFirmaActual = $dirFirmas . "/{$folio}_actual.png";

            guardar_firma($firmaDato, $firmaArchivo, $rutaFirmaActual);

            $stmtSol = $conn->prepare('SELECT * FROM solicitudes WHERE id = ?');
            $stmtSol->bind_param('i', $id);
            $stmtSol->execute();
            $solicitudActualizada = $stmtSol->get_result()->fetch_assoc();
            $stmtSol->close();

            $plantillaDoc = $solicitudActualizada['tipo'] === 'recepcion' ? 'rbi.docx' : 'tbi.docx';
            $prefijoDoc   = $solicitudActualizada['tipo'] === 'recepcion' ? 'RBI' : 'TBI';
            $tipoLabel    = $solicitudActualizada['tipo'] === 'recepcion' ? 'Recepción' : 'Entrega';
            // Genera el documento con los datos y firmas actuales.
            $nombreArchivo = generar_tbi($solicitudActualizada, $equipos, false, $plantillaDoc, $prefijoDoc);
            $rutaTbi = __DIR__ . '/generados/' . $nombreArchivo;

            $cuerpo = "
                <p>Una solicitud de <strong>{$tipoLabel}</strong> fue corregida y reenviada a revisión.</p>
                <p><strong>Folio:</strong> {$folio}<br>
                   <strong>Corregida por:</strong> " . h($u['nombre']) . "<br>
                   <strong>" . h(ucfirst($labelCustodioNuevo)) . ":</strong> " . h($custodioNuevo) . "<br>
                   <strong>Fecha:</strong> " . date('d-m-Y H:i') . "</p>
                <p>Queda en <strong>Fase 2</strong>, esperando tu revisión.</p>
                <p><a href=\"" . url_base() . "/fase2_revisar.php?id={$id}\">Haz clic aquí para revisar y aprobar o rechazar</a></p>
            ";
            enviar_correo($jefeEntregaCorreo, $jefeActual, "Solicitud $folio corregida - Fase 2 pendiente", $cuerpo);

            $cuerpoSolicitante = "
                <p>Tu solicitud <strong>{$folio}</strong> fue corregida y reenviada a revisión.</p>
                <p>Vuelve a estar en <strong>Fase 2</strong>, esperando la aprobación del " . h($labelJefeFase2) . ".</p>
            ";
            enviar_correo($correoNuevo, $custodioNuevo, "Solicitud $folio corregida - En revisión", $cuerpoSolicitante);

            if (!empty($u['correo']) && strcasecmp($u['correo'], $correoNuevo) !== 0) {
                $cuerpoCreador = "
                    <p>Corregiste y reenviaste la solicitud <strong>{$folio}</strong>. Vuelve a estar en <strong>Fase 2</strong>.</p>
                    <p><strong>" . h(ucfirst($labelJefeFase2)) . " (aprueba Fase 2):</strong> " . h($jefeActual) . "<br>
                       <strong>Fecha:</strong> " . date('d-m-Y H:i') . "</p>
                    <p><a href=\"" . url_base() . "/seguimiento.php?id={$id}\">Ver el seguimiento</a></p>
                ";
                enviar_correo($u['correo'], $u['nombre'], "Solicitud $folio corregida - Reenviada a Fase 2", $cuerpoCreador);
            }

            $corregida = true;
        } catch (Throwable $e) {
            // Si algo falló, se deshacen los cambios.
            $conn->rollback();
            $error = 'No se pudo corregir la solicitud: ' . $e->getMessage();
        }
    }
}

// Dibuja la cabecera de la página.
render_header('Corregir solicitud', $u);
?>
<h1>Corregir solicitud <?= h($solicitud['folio']) ?></h1>

<?php if ($error): ?><p class="error"><?= h($error) ?></p><?php endif; ?>

<?php if ($corregida): ?>
    <p class="ok">
        Solicitud <strong><?= h($solicitud['folio']) ?></strong> corregida y reenviada a Fase 2.
    </p>
    <p><a href="mis_solicitudes.php">← Volver a mis solicitudes</a> · <a href="index.php">Volver al inicio</a></p>
<?php else: ?>

<?php if ($ultimoRechazo): ?>
    <div class="aviso">
        <strong>Motivo del rechazo:</strong> <?= nl2br(h($ultimoRechazo['comentario'])) ?>
        <br><small><?= h(date('d-m-Y H:i', strtotime($ultimoRechazo['creado_en']))) ?></small>
    </div>
<?php endif; ?>

<h2>Equipos (no se pueden cambiar en esta corrección)</h2>
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

<form method="post" class="form-solicitud" enctype="multipart/form-data">
    <?= csrf_field() ?>
    <input type="hidden" name="id" value="<?= (int) $id ?>">

    <h2>1) Tipo de traspaso</h2>
    <div class="fila-radios">
        <label><input type="radio" name="tipo_traspaso" value="definitivo" <?= $datos['tipo_traspaso'] === 'definitivo' ? 'checked' : '' ?>> Definitivo</label>
        <label><input type="radio" name="tipo_traspaso" value="temporal" <?= $datos['tipo_traspaso'] === 'temporal' ? 'checked' : '' ?>> Temporal, por
            <input type="text" name="plazo" placeholder="días" style="width:80px;display:inline-block" value="<?= h($datos['plazo'] ?? '') ?>"> día(s)
        </label>
    </div>
    <div class="fila-radios">
        <label><input type="radio" name="reasignacion" value="solo_custodio" <?= $datos['reasignacion'] === 'solo_custodio' ? 'checked' : '' ?>> Solo custodio</label>
        <label><input type="radio" name="reasignacion" value="solo_ubicacion" <?= $datos['reasignacion'] === 'solo_ubicacion' ? 'checked' : '' ?>> Solo ubicación física</label>
        <label><input type="radio" name="reasignacion" value="custodio_ubicacion" <?= $datos['reasignacion'] === 'custodio_ubicacion' ? 'checked' : '' ?>> Custodio y ubicación</label>
    </div>

    <div class="dos-columnas">
        <fieldset>
            <legend>2) <?= h($labelCustodioActual) ?></legend>
            <label>Nombre <input name="custodio_actual" required value="<?= h($datos['custodio_actual'] ?? '') ?>"></label>
            <label>RUT <input name="run_actual" class="rut-input" required placeholder="12.345.678-5" value="<?= h($datos['run_actual'] ?? '') ?>"></label>
            <label>Unidad operativa <input name="unidad_actual" value="<?= h($datos['unidad_actual'] ?? '') ?>"></label>
            <label>Código de ubicación <input name="cod_ubic_actual" value="<?= h($datos['cod_ubic_actual'] ?? '') ?>"></label>
            <label>Ubicación actual <input name="ubic_actual" value="<?= h($datos['ubic_actual'] ?? '') ?>"></label>
            <label><?= h(ucfirst($labelJefeFase2)) ?> (nombre) <input name="jefe_actual" required value="<?= h($datos['jefe_actual'] ?? '') ?>"></label>
            <label>Correo del <?= h($labelJefeFase2) ?> <?= campo_correo('jefe_entrega_correo', $datos['jefe_entrega_correo'] ?? '') ?></label>
        </fieldset>
        <fieldset>
            <legend>3) <?= h($labelCustodioNuevo) ?></legend>
            <?php $ro = $custodioBloqueado ? 'readonly' : ''; ?>
            <label>Nombre <input name="custodio_nuevo" required maxlength="150" <?= $ro ?> value="<?= h($custodioBloqueado['custodio_nuevo'] ?? $solicitud['solicitante_nombre']) ?>"></label>
            <label>RUT <input name="run_nuevo" class="rut-input" required placeholder="12.345.678-5" <?= $ro ?> value="<?= h($custodioBloqueado['run_nuevo'] ?? $datos['run_nuevo'] ?? '') ?>"></label>
            <label>Unidad operativa <input name="unidad_nueva" required <?= $ro ?> value="<?= h($custodioBloqueado['unidad_nueva'] ?? $datos['unidad_nueva'] ?? '') ?>"></label>
            <label>Código de ubicación <input name="cod_ubic_nueva" <?= $ro ?> value="<?= h($custodioBloqueado['cod_ubic_nueva'] ?? $datos['cod_ubic_nueva'] ?? '') ?>"></label>
            <label>Ubicación nueva <input name="ubic_nueva" required maxlength="150" <?= $ro ?> value="<?= h($custodioBloqueado['ubic_nueva'] ?? $datos['ubic_nueva'] ?? '') ?>"></label>
            <label>Correo del solicitante
                <?php if ($ro): ?>
                    <input type="email" name="correo_nuevo" required maxlength="150" <?= $ro ?> value="<?= h($custodioBloqueado['correo_nuevo'] ?? $solicitud['solicitante_correo']) ?>">
                <?php else: ?>
                    <?= campo_correo('correo_nuevo', $solicitud['solicitante_correo']) ?>
                <?php endif; ?>
            </label>
        </fieldset>
    </div>

    <h2>4) Jefe de sector de quien recibe</h2>
    <div class="fila-radios">
        <label>Nombre <input name="jefe_recibe_nombre" required maxlength="150" style="width:220px" value="<?= h($solicitud['jefe_recibe_nombre']) ?>"></label>
        <label>Correo <?= campo_correo('jefe_recibe_correo', $solicitud['jefe_recibe_correo'] ?? '') ?></label>
    </div>

    <h2>5) Firma del custodio que entrega</h2>
    <p class="aviso">Opcional: solo si necesitas volver a firmar. Si no dibujas ni subes nada, se mantiene la firma anterior.</p>
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

    <h2>6) Observaciones</h2>
    <label><textarea name="observaciones" rows="3"><?= h($datos['observaciones'] ?? '') ?></textarea></label>

    <button type="submit" class="primario">Corregir y reenviar a Fase 2</button>
</form>

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

document.querySelector('.form-solicitud').addEventListener('submit', function (e) {
    const subirActivo = document.getElementById('panel-subir').classList.contains('activo');
    if (!subirActivo) {
        const pixeles = ctx.getImageData(0, 0, canvas.width, canvas.height).data;
        let vacio = true;
        for (let i = 0; i < pixeles.length; i += 4) {
            if (pixeles[i] !== 255 || pixeles[i + 1] !== 255 || pixeles[i + 2] !== 255) { vacio = false; break; }
        }
        if (!vacio) {
            document.getElementById('firma_dato').value = canvas.toDataURL('image/png');
        }
    }
});

// Calcula el dígito verificador del RUT.
function dvRut(T) {
    let M = 0, S = 1;
    for (; T; T = Math.floor(T / 10)) {
        S = (S + T % 10 * (9 - M++ % 6)) % 11;
    }
    return S ? S - 1 : 'k';
}
// Revisa que el RUT tenga formato y dígito verificador correctos.
function validarRut(rutCompleto) {
    rutCompleto = rutCompleto.replace(/\./g, '');
    if (!/^[0-9]+-[0-9kK]{1}$/.test(rutCompleto)) return false;
    const tmp = rutCompleto.split('-');
    const digv = tmp[1].toLowerCase();
    const rut = tmp[0];
    return String(dvRut(rut)) === digv;
}
document.querySelectorAll('.rut-input').forEach(function (input) {
    input.addEventListener('input', function (e) {
        let value = e.target.value.replace(/\./g, '').replace('-', '');
        if (/^\d{7,8}[0-9kK]$/.test(value)) {
            value = value.replace(/^(\d+)(\d{3})(\d{3})(\w{1})$/, '$1.$2.$3-$4');
        }
        e.target.value = value;
        if (value !== '' && validarRut(value)) {
            e.target.setCustomValidity('');
            e.target.classList.remove('is-invalid');
        } else {
            e.target.setCustomValidity('RUT inválido');
            e.target.classList.add('is-invalid');
        }
    });
});
</script>

<?php endif; ?>
<p><a href="mis_solicitudes.php">← Volver a mis solicitudes</a></p>
<?php render_footer(); ?>
