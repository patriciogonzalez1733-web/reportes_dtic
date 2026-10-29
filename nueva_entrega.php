<?php
// Crea una entrega de equipos (TBI) y la envía a la Fase 2.
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/firmas.php';
require_once __DIR__ . '/includes/correo.php';
require_once __DIR__ . '/includes/documento.php';
require_once __DIR__ . '/includes/tokens.php';
require_once __DIR__ . '/vendor/autoload.php';

// Solo los roles indicados pueden entrar a esta página.
$u = require_rol(['responsable', 'encargado', 'admin']);

$equipos = $conn->query(
    "SELECT id, codigo_inventario, descripcion, serie
     FROM equipos WHERE estado = 'en_dtic' AND bloqueado_por IS NULL
     ORDER BY descripcion"
)->fetch_all(MYSQLI_ASSOC);

$error = '';
$folioGenerado = null;
$archivoGenerado = null;

// Se ejecuta cuando se envía el formulario.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Verifica el token de seguridad del formulario.
    csrf_check();

    $equipoIds = array_map('intval', $_POST['equipos'] ?? []);

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

    $tipoTraspaso   = $_POST['tipo_traspaso'] ?? 'definitivo';
    $plazo          = trim($_POST['plazo'] ?? '');
    $reasignacion   = $_POST['reasignacion'] ?? 'custodio_ubicacion';
    $observaciones  = trim($_POST['observaciones'] ?? '');

    $firmaDato    = $_POST['firma_dato'] ?? '';
    $firmaArchivo = $_FILES['firma_archivo'] ?? null;

    if (!$equipoIds) {
        $error = 'Selecciona al menos un equipo.';
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
    } elseif ($errLargo = validar_largos([
        'El nombre del custodio que recibe'          => [$custodioNuevo, 150],
        'El correo del solicitante'                  => [$correoNuevo, 150],
        'La ubicación nueva'                         => [$ubicNueva, 150],
        'El nombre del jefe de sector que recibe'    => [$jefeRecibeNombre, 150],
        'El correo del jefe de sector que recibe'    => [$jefeRecibeCorreo, 150],
    ])) {
        $error = $errLargo;
    } elseif ($tipoTraspaso === 'temporal' && $plazo === '') {
        $error = 'Indica el plazo del traspaso temporal.';
    } elseif ($firmaDato === '' && (!$firmaArchivo || $firmaArchivo['error'] !== UPLOAD_ERR_OK)) {
        $error = 'Debes ingresar la firma del custodio que entrega (dibujada o subida).';
    } elseif ($errFirma = validar_firma($firmaDato, $firmaArchivo)) {
        $error = $errFirma;
    }

    if (!$error) {
        $archivosCreados = [];
        $confirmado = false;
        // Los cambios se guardan todos juntos o no se guarda ninguno.
        $conn->begin_transaction();
        try {
            $in = implode(',', array_fill(0, count($equipoIds), '?'));
            $tipos = str_repeat('i', count($equipoIds));
            $stmt = $conn->prepare("SELECT id, codigo_inventario, descripcion, serie FROM equipos
                                     WHERE id IN ($in) AND estado='en_dtic' AND bloqueado_por IS NULL FOR UPDATE");
            $stmt->bind_param($tipos, ...$equipoIds);
            $stmt->execute();
            $equiposSel = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();

            if (count($equiposSel) !== count($equipoIds)) {
                throw new RuntimeException('Uno o más equipos ya no están disponibles. Actualiza la página.');
            }

            $anio = (int) date('Y');
            $stmt = $conn->prepare(
                "INSERT INTO correlativos (tipo, anio, ultimo) VALUES ('entrega', ?, LAST_INSERT_ID(1))
                 ON DUPLICATE KEY UPDATE ultimo = LAST_INSERT_ID(ultimo + 1)"
            );
            $stmt->bind_param('i', $anio);
            $stmt->execute();
            $folioNum = (int) $conn->insert_id;
            $stmt->close();
            $folio = "VIC-TC-$folioNum-$anio";

            $datos = [
                'run_actual' => $runActual, 'unidad_actual' => $unidadActual,
                'cod_ubic_actual' => $codUbicActual, 'ubic_actual' => $ubicActual, 'jefe_actual' => $jefeActual, 'jefe_entrega_correo' => $jefeEntregaCorreo,
                'custodio_actual' => $custodioActual,
                'run_nuevo' => $runNuevo, 'unidad_nueva' => $unidadNueva,
                'cod_ubic_nueva' => $codUbicNueva, 'ubic_nueva' => $ubicNueva,
                'tipo_traspaso' => $tipoTraspaso, 'plazo' => $plazo,
                'reasignacion' => $reasignacion, 'observaciones' => $observaciones,
            ];

            $stmt = $conn->prepare(
                "INSERT INTO solicitudes (tipo, folio_num, anio, folio, fase_actual, estado, creado_por,
                                          solicitante_nombre, solicitante_correo,
                                          jefe_recibe_nombre, jefe_recibe_correo, datos, fase_desde)
                 VALUES ('entrega', ?, ?, ?, 2, 'en_curso', ?, ?, ?, ?, ?, ?, NOW())"
            );
            $datosJson = json_encode($datos, JSON_UNESCAPED_UNICODE);
            $stmt->bind_param(
                'iisisssss',
                $folioNum, $anio, $folio, $u['id'],
                $custodioNuevo, $correoNuevo, $jefeRecibeNombre, $jefeRecibeCorreo, $datosJson
            );
            $stmt->execute();
            $solicitudId = $conn->insert_id;
            $stmt->close();

            $stmt = $conn->prepare('INSERT INTO solicitud_equipos (solicitud_id, equipo_id) VALUES (?,?)');
            $stmtLock = $conn->prepare('UPDATE equipos SET bloqueado_por=? WHERE id=?');
            foreach ($equipoIds as $eid) {
                $stmt->bind_param('ii', $solicitudId, $eid);
                $stmt->execute();
                $stmtLock->bind_param('ii', $solicitudId, $eid);
                $stmtLock->execute();
            }
            $stmt->close();
            $stmtLock->close();

            $stmt = $conn->prepare(
                "INSERT INTO historial (solicitud_id, fase_origen, fase_destino, accion, usuario_id, comentario)
                 VALUES (?, NULL, 2, 'crear', ?, 'Solicitud creada y enviada a Fase 2.')"
            );
            $stmt->bind_param('ii', $solicitudId, $u['id']);
            $stmt->execute();
            $stmt->close();

            $dirFirmas = __DIR__ . '/firmas';
            if (!is_dir($dirFirmas)) mkdir($dirFirmas, 0775, true);

            $rutaFirmaActual = $dirFirmas . "/{$folio}_actual.png";
            $archivosCreados[] = $rutaFirmaActual;
            foreach (['jefe_entrega', 'jefe_recibe', 'nuevo'] as $sufijoViejo) {
                @unlink($dirFirmas . "/{$folio}_{$sufijoViejo}.png");
            }

            guardar_firma($firmaDato, $firmaArchivo, $rutaFirmaActual);

            $stmtSol = $conn->prepare('SELECT * FROM solicitudes WHERE id = ?');
            $stmtSol->bind_param('i', $solicitudId);
            $stmtSol->execute();
            $solicitudGuardada = $stmtSol->get_result()->fetch_assoc();
            $stmtSol->close();

            // Genera el documento con los datos y firmas actuales.
            $nombreArchivo = generar_tbi($solicitudGuardada, $equiposSel);
            $rutaTbi = __DIR__ . '/generados/' . $nombreArchivo;
            $archivosCreados[] = $rutaTbi;

            $conn->commit();
            $confirmado = true;

            $cuerpo = "
                <p>Se generó una nueva solicitud de <strong>Entrega</strong> en DTIC.</p>
                <p><strong>Folio:</strong> {$folio}<br>
                   <strong>Creada por:</strong> " . h($u['nombre']) . "<br>
                   <strong>Custodio que recibe:</strong> " . h($custodioNuevo) . "<br>
                   <strong>Fecha:</strong> " . date('d-m-Y H:i') . "</p>
                <p>Queda en <strong>Fase 2</strong>, esperando tu revisión.</p>
                <p><a href=\"" . url_base() . "/fase2_revisar.php?id={$solicitudId}\">Haz clic aquí para revisar y aprobar o rechazar</a></p>
            ";
            enviar_correo($jefeEntregaCorreo, $jefeActual, "Nueva solicitud $folio - Fase 2 pendiente", $cuerpo);

            $cuerpoSolicitante = "
                <p>Se generó tu solicitud de <strong>Entrega</strong> en DTIC.</p>
                <p><strong>Folio:</strong> {$folio}<br>
                   <strong>Equipo(s) solicitado(s):</strong> " . count($equiposSel) . "<br>
                   <strong>Fecha:</strong> " . date('d-m-Y H:i') . "</p>
                <p>Tu solicitud está en revisión por el jefe de sector que entrega. Te avisaremos por correo
                   cada vez que avance de fase.</p>
            ";
            enviar_correo($correoNuevo, $custodioNuevo, "Solicitud $folio creada - En revisión", $cuerpoSolicitante);

            if (!empty($u['correo']) && strcasecmp($u['correo'], $correoNuevo) !== 0) {
                $cuerpoCreador = "
                    <p>Tu solicitud de <strong>Entrega</strong> fue creada y enviada a <strong>Fase 2</strong>.</p>
                    <p><strong>Folio:</strong> {$folio}<br>
                       <strong>Custodio que recibe:</strong> " . h($custodioNuevo) . "<br>
                       <strong>Jefe que entrega (aprueba Fase 2):</strong> " . h($jefeActual) . "<br>
                       <strong>Equipo(s):</strong> " . count($equiposSel) . "<br>
                       <strong>Fecha:</strong> " . date('d-m-Y H:i') . "</p>
                    <p>Te avisaremos por correo cada vez que avance de fase.
                       <a href=\"" . url_base() . "/seguimiento.php?id={$solicitudId}\">Ver el seguimiento</a></p>
                ";
                enviar_correo($u['correo'], $u['nombre'], "Solicitud $folio creada - Enviada a Fase 2", $cuerpoCreador);
            }

            $folioGenerado = $folio;
            $archivoGenerado = $nombreArchivo;
        } catch (Throwable $e) {
            if (!$confirmado) {
                // Si algo falló, se deshacen los cambios.
                $conn->rollback();
                foreach ($archivosCreados as $ruta) {
                    @unlink($ruta);
                }
            }
            $error = 'No se pudo crear la solicitud: ' . $e->getMessage();
        }
    }
}

// Dibuja la cabecera de la página.
render_header('Nueva entrega', $u);
?>
<h1>Nueva solicitud: Entrega</h1>

<?php if ($error): ?><p class="error"><?= h($error) ?></p><?php endif; ?>

<?php if ($folioGenerado): ?>
    <p class="ok">
        Solicitud <strong><?= h($folioGenerado) ?></strong> creada y enviada a Fase 2.
        <a href="ver_documento.php?id=<?= (int) $solicitudId ?>" target="_blank">Ver TBI generado</a>
    </p>
    <p><a href="index.php">← Volver al inicio</a> · <a href="nueva_entrega.php">Crear otra entrega</a></p>
<?php else: ?>

<?php if (!$equipos): ?>
    <p class="aviso">No hay equipos disponibles en DTIC. Agrega equipos en <a href="equipos.php">Inventario</a> antes de continuar.</p>
<?php else: ?>

<form method="post" class="form-solicitud" enctype="multipart/form-data">
    <?= csrf_field() ?>

    <h2>1) Equipos a entregar</h2>
    <div class="lista-check" id="lista-equipos">
        <?php foreach ($equipos as $e): ?>
            <label class="check-item">
                <input type="checkbox" name="equipos[]" value="<?= (int)$e['id'] ?>">
                <span><strong><?= h($e['codigo_inventario'] ?: 's/código') ?></strong> — <?= h($e['descripcion']) ?>
                    <small><?= h($e['serie'] ?: 'sin serie') ?></small>
                </span>
            </label>
        <?php endforeach; ?>
    </div>

    <h2>2) Tipo de traspaso</h2>
    <div class="fila-radios">
        <label><input type="radio" name="tipo_traspaso" value="definitivo" checked> Definitivo</label>
        <label><input type="radio" name="tipo_traspaso" value="temporal"> Temporal, por
            <input type="text" name="plazo" placeholder="días" style="width:80px;display:inline-block"> día(s)
        </label>
    </div>
    <div class="fila-radios">
        <label><input type="radio" name="reasignacion" value="solo_custodio"> Solo custodio</label>
        <label><input type="radio" name="reasignacion" value="solo_ubicacion"> Solo ubicación física</label>
        <label><input type="radio" name="reasignacion" value="custodio_ubicacion" checked> Custodio y ubicación</label>
    </div>

    <div class="dos-columnas">
        <fieldset>
            <legend>3) Custodio que entrega (DTIC)</legend>
            <label>Nombre <input name="custodio_actual" required value="<?= h($u['nombre']) ?>"></label>
            <label>RUT <input name="run_actual" class="rut-input" required placeholder="12.345.678-5"></label>
            <label>Unidad operativa <input name="unidad_actual" value="DTIC"></label>
            <label>Código de ubicación <input name="cod_ubic_actual"></label>
            <label>Ubicación actual <input name="ubic_actual"></label>
            <label>Jefe que entrega (nombre) <input name="jefe_actual" required></label>
            <label>Correo del jefe que entrega <?= campo_correo('jefe_entrega_correo') ?></label>
        </fieldset>
        <fieldset>
            <legend>4) Custodio que recibe (solicitante)</legend>
            <label>Nombre <input name="custodio_nuevo" required maxlength="150"></label>
            <label>RUT <input name="run_nuevo" class="rut-input" required placeholder="12.345.678-5"></label>
            <label>Unidad operativa <input name="unidad_nueva" required></label>
            <label>Código de ubicación <input name="cod_ubic_nueva"></label>
            <label>Ubicación nueva <input name="ubic_nueva" required maxlength="150"></label>
            <label>Correo del solicitante <?= campo_correo('correo_nuevo') ?></label>
        </fieldset>
    </div>

    <h2>5) Jefe de sector de quien recibe</h2>
    <p class="aviso">Esta persona no tiene cuenta en el sistema: recibirá un link por correo para aprobar o rechazar (Fase 3).</p>
    <div class="fila-radios">
        <label>Nombre <input name="jefe_recibe_nombre" required maxlength="150" style="width:220px"></label>
        <label>Correo <?= campo_correo('jefe_recibe_correo') ?></label>
    </div>

    <h2>6) Firma del custodio que entrega</h2>
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

    <h2>7) Observaciones</h2>
    <label><textarea name="observaciones" rows="3"></textarea></label>

    <button type="submit" class="primario">Crear solicitud y generar TBI</button>
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
    if (!document.querySelector('#lista-equipos input[type=checkbox]:checked')) {
        alert('Selecciona al menos un equipo.');
        e.preventDefault();
        return;
    }
    const subirActivo = document.getElementById('panel-subir').classList.contains('activo');
    if (!subirActivo) {
        const pixeles = ctx.getImageData(0, 0, canvas.width, canvas.height).data;
        let vacio = true;
        for (let i = 0; i < pixeles.length; i += 4) {
            if (pixeles[i] !== 255 || pixeles[i + 1] !== 255 || pixeles[i + 2] !== 255) { vacio = false; break; }
        }
        if (vacio) {
            alert('Dibuja la firma o cambia a "Subir imagen".');
            e.preventDefault();
            return;
        }
        document.getElementById('firma_dato').value = canvas.toDataURL('image/png');
    } else {
        document.getElementById('firma_dato').value = '';
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
<?php endif; ?>
<p><a href="index.php">← Volver al inicio</a></p>
<?php render_footer(); ?>
