<?php
// Formulario del informe técnico (CIT): diagnóstico y dictamen por equipo, y firma del técnico.
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/firmas.php';
require_once __DIR__ . '/includes/correo.php';
require_once __DIR__ . '/includes/documento.php';
require_once __DIR__ . '/includes/tokens.php';
require_once __DIR__ . '/vendor/autoload.php';

// Solo los roles indicados pueden entrar a esta página.
$u = require_rol(['responsable', 'encargado', 'admin']);

$id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);

$stmt = $conn->prepare(
    "SELECT s.*, us.nombre AS creador_nombre, us.correo AS creador_correo
     FROM solicitudes s JOIN usuarios us ON us.id = s.creado_por
     WHERE s.id = ? AND s.tipo = 'informe_tecnico' AND s.fase_actual = 1 AND s.estado = 'en_curso'"
);
$stmt->bind_param('i', $id);
$stmt->execute();
$solicitud = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$solicitud) {
    // Dibuja la cabecera de la página.
    render_header('Informe no encontrado', $u);
    echo '<p class="error">Este informe técnico no existe o ya no está en Fase 1.</p>';
    echo '<p><a href="index.php">← Volver al inicio</a></p>';
    render_footer();
    exit;
}

if ((int) $solicitud['creado_por'] !== (int) $u['id'] && !in_array($u['rol'], ['admin', 'encargado'], true)) {
    // Dibuja la cabecera de la página.
    render_header('Sin permiso', $u);
    echo '<p class="error">No puedes completar un informe técnico que no creaste tú.</p>';
    echo '<p><a href="index.php">← Volver al inicio</a></p>';
    render_footer();
    exit;
}

$datos = json_decode($solicitud['datos'], true) ?: [];

$equipos = $conn->query(
    "SELECT e.id, e.codigo_inventario, e.descripcion, e.serie
     FROM solicitud_equipos se JOIN equipos e ON e.id = se.equipo_id
     WHERE se.solicitud_id = " . (int) $id
)->fetch_all(MYSQLI_ASSOC);

$error = '';

// Se ejecuta cuando se envía el formulario.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Verifica el token de seguridad del formulario.
    csrf_check();

    $ubicacionFisica   = trim($_POST['ubicacion_fisica'] ?? '');
    $nombreCustodio    = trim($_POST['nombre_custodio'] ?? '');
    $observaciones     = trim($_POST['observaciones'] ?? '');
    $tecnicoNombre     = trim($_POST['tecnico_nombre'] ?? '');
    $jefeTecnicoNombre = trim($_POST['jefe_tecnico_nombre'] ?? '');
    $jefeTecnicoCorreo = trim($_POST['jefe_tecnico_correo'] ?? '');

    $diagnosticoPost = $_POST['diagnostico'] ?? [];
    $dictamenPost    = $_POST['dictamen'] ?? [];
    $destinoPost     = $_POST['destino'] ?? [];

    $firmaDato    = $_POST['firma_dato'] ?? '';
    $firmaArchivo = $_FILES['firma_archivo'] ?? null;

    $dictamenesValidos = array_keys(DICTAMENES);

    if ($ubicacionFisica === '' || $nombreCustodio === '') {
        $error = 'Falta la ubicación física o el nombre del custodio.';
    } elseif ($tecnicoNombre === '') {
        $error = 'Falta el nombre del técnico asignado.';
    } elseif ($jefeTecnicoNombre === '' || $jefeTecnicoCorreo === '' || !filter_var($jefeTecnicoCorreo, FILTER_VALIDATE_EMAIL)) {
        $error = 'Falta el nombre o el correo del jefe de unidad técnica.';
    } elseif ($errLargo = validar_largos([

        'El nombre del jefe de unidad técnica' => [$jefeTecnicoNombre, 150],
        'El correo del jefe de unidad técnica' => [$jefeTecnicoCorreo, 150],
    ])) {
        $error = $errLargo;
    } elseif ($firmaDato === '' && (!$firmaArchivo || $firmaArchivo['error'] !== UPLOAD_ERR_OK)) {
        $error = 'Debes ingresar la firma del técnico.';
    } elseif ($errFirma = validar_firma($firmaDato, $firmaArchivo)) {
        $error = $errFirma;
    } else {
        $hayOtros = false;
        foreach ($equipos as $e) {
            $eid = $e['id'];
            if (trim($diagnosticoPost[$eid] ?? '') === '' || !in_array($dictamenPost[$eid] ?? '', $dictamenesValidos, true)) {
                $error = 'Completa el diagnóstico y el dictamen de todos los equipos.';
                break;
            }
            if ($dictamenPost[$eid] === 'otros') {
                $hayOtros = true;
                $destino = $destinoPost[$eid] ?? '';
                if (!is_string($destino) || !isset(DESTINOS_OTROS[$destino])) {
                    $error = 'En los equipos con dictamen "Otros" indica en qué estado queda el equipo.';
                    break;
                }
            }
        }

        if (!$error && $hayOtros && $observaciones === '') {
            $error = 'Con el dictamen "Otros" debes explicar el motivo en Observaciones.';
        }
    }

    if (!$error) {
        // Los cambios se guardan todos juntos o no se guarda ninguno.
        $conn->begin_transaction();
        try {
            $stmt = $conn->prepare(
                "SELECT *, TIMESTAMPDIFF(SECOND, fase_desde, NOW()) AS seg_en_fase
                 FROM solicitudes WHERE id = ? AND tipo = 'informe_tecnico' AND fase_actual = 1 AND estado = 'en_curso' FOR UPDATE"
            );
            $stmt->bind_param('i', $id);
            $stmt->execute();
            $solicitudBloqueada = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!$solicitudBloqueada) {
                throw new RuntimeException('Este informe ya no está en Fase 1 (puede que ya se haya enviado).');
            }

            $folio = $solicitudBloqueada['folio'];
            $segundos = (int) $solicitudBloqueada['seg_en_fase'];

            $equiposDictamen = [];
            foreach ($equipos as $e) {
                $eid = $e['id'];
                $equiposDictamen[$eid] = [
                    'diagnostico' => trim($diagnosticoPost[$eid]),
                    'dictamen'    => $dictamenPost[$eid],
                ];
                if ($dictamenPost[$eid] === 'otros') {
                    $equiposDictamen[$eid]['destino'] = $destinoPost[$eid];
                }
            }

            $datosNuevos = [
                'ubicacion_fisica'     => $ubicacionFisica,
                'nombre_custodio'      => $nombreCustodio,
                'observaciones'        => $observaciones,
                'tecnico_nombre'       => $tecnicoNombre,
                'jefe_tecnico_nombre'  => $jefeTecnicoNombre,
                'jefe_tecnico_correo'  => $jefeTecnicoCorreo,
                'equipos_dictamen'     => $equiposDictamen,
            ];
            $datosJson = json_encode($datosNuevos, JSON_UNESCAPED_UNICODE);

            $stmt = $conn->prepare(
                "UPDATE solicitudes SET datos = ?, fase_actual = 2, fase_desde = NOW() WHERE id = ?"
            );
            $stmt->bind_param('si', $datosJson, $id);
            $stmt->execute();
            $stmt->close();

            $stmt = $conn->prepare(
                "INSERT INTO historial (solicitud_id, fase_origen, fase_destino, accion, usuario_id, comentario, segundos_en_fase)
                 VALUES (?, 1, 2, 'crear', ?, 'Informe técnico completado y enviado a V°B° del jefe de unidad técnica.', ?)"
            );
            $stmt->bind_param('iii', $id, $u['id'], $segundos);
            $stmt->execute();
            $stmt->close();

            $conn->commit();

            $dirFirmas = __DIR__ . '/firmas';
            if (!is_dir($dirFirmas)) mkdir($dirFirmas, 0775, true);
            $rutaFirmaTecnico = $dirFirmas . "/{$folio}_tecnico.png";

            guardar_firma($firmaDato, $firmaArchivo, $rutaFirmaTecnico);

            $stmtSol = $conn->prepare('SELECT * FROM solicitudes WHERE id = ?');
            $stmtSol->bind_param('i', $id);
            $stmtSol->execute();
            $solicitudActualizada = $stmtSol->get_result()->fetch_assoc();
            $stmtSol->close();

            // Genera el documento con los datos y firmas actuales.
            $nombreArchivo = generar_cit($solicitudActualizada, $equipos, $rutaFirmaTecnico, null);
            $rutaCit = __DIR__ . '/generados/' . $nombreArchivo;

            // Crea el link de un solo uso para el firmante.
            $token = crear_token($conn, $id, 2, $jefeTecnicoCorreo, $jefeTecnicoNombre);
            $link = url_base() . '/confirmar.php?token=' . $token;

            $cuerpo = "
                <p>Se te solicita dar el V°B° a un Informe Técnico (CIT).</p>
                <p><strong>Folio:</strong> {$folio}<br>
                   <strong>Técnico asignado:</strong> " . h($tecnicoNombre) . "<br>
                   <strong>Ubicación física:</strong> " . h($ubicacionFisica) . "</p>
                <p><a href=\"{$link}\">Haz clic aquí para revisar y dar tu V°B° o rechazar</a></p>
                <p>Este link es de un solo uso y expira en 72 horas.</p>
            ";
            enviar_correo($jefeTecnicoCorreo, $jefeTecnicoNombre, "Informe técnico $folio - Requiere tu V°B°", $cuerpo);

            if (!empty($u['correo'])) {
                $cuerpoCreador = "
                    <p>El informe técnico <strong>{$folio}</strong> fue completado y enviado al jefe de unidad técnica para su V°B°.</p>
                ";
                enviar_correo($u['correo'], $u['nombre'], "Informe técnico $folio - Enviado a V°B°", $cuerpoCreador);
            }

            // Redirige a otra página.
            header('Location: index.php?informe=enviado');
            exit;
        } catch (Throwable $e) {
            // Si algo falló, se deshacen los cambios.
            $conn->rollback();
            $error = 'No se pudo enviar el informe: ' . $e->getMessage();
        }
    }
}

// Dibuja la cabecera de la página.
render_header('Completar informe técnico', $u);
?>
<h1>Completar informe técnico <?= h($solicitud['folio']) ?></h1>

<?php if ($error): ?><p class="error"><?= h($error) ?></p><?php endif; ?>

<form method="post" class="form-solicitud" enctype="multipart/form-data">
    <?= csrf_field() ?>
    <input type="hidden" name="id" value="<?= (int) $id ?>">

    <h2>1) Datos generales</h2>
    <div class="dos-columnas">
        <fieldset>
            <legend>Ubicación y custodio</legend>
            <label>Ubicación física <input name="ubicacion_fisica" required value="<?= h($datos['ubicacion_fisica'] ?? '') ?>"></label>
            <label>Nombre del custodio <input name="nombre_custodio" required value="<?= h($datos['nombre_custodio'] ?? '') ?>"></label>
        </fieldset>
        <fieldset>
            <legend>Responsables del informe</legend>
            <label>Nombre del técnico asignado <input name="tecnico_nombre" required value="<?= h($datos['tecnico_nombre'] ?? '') ?>"></label>
            <label>Nombre del jefe de unidad técnica <input name="jefe_tecnico_nombre" required maxlength="150" value="<?= h($datos['jefe_tecnico_nombre'] ?? '') ?>"></label>
            <label>Correo del jefe de unidad técnica <?= campo_correo('jefe_tecnico_correo', $datos['jefe_tecnico_correo'] ?? '') ?></label>
        </fieldset>
    </div>

    <h2>2) Diagnóstico por equipo</h2>
    <?= leyenda_dictamenes_html() ?>
    <table class="tabla">
        <thead><tr><th>Código</th><th>Descripción</th><th>Serie</th><th>Diagnóstico</th><th>Dictamen</th></tr></thead>
        <tbody>
            <?php foreach ($equipos as $e): $dict = $datos['equipos_dictamen'][$e['id']] ?? ['diagnostico' => '', 'dictamen' => '']; ?>
                <tr>
                    <td><?= h($e['codigo_inventario'] ?: '—') ?></td>
                    <td><?= h($e['descripcion']) ?></td>
                    <td><?= h($e['serie'] ?: '—') ?></td>
                    <td><textarea name="diagnostico[<?= (int) $e['id'] ?>]" rows="2" required><?= h($dict['diagnostico']) ?></textarea></td>
                    <td>
                        <select name="dictamen[<?= (int) $e['id'] ?>]" class="dictamen-select" required>
                            <option value="">Elegir…</option>
                            <?php foreach (DICTAMENES as $clave => [$texto]): ?>
                                <option value="<?= h($clave) ?>" <?= ($dict['dictamen'] ?? '') === $clave ? 'selected' : '' ?>><?= h($texto) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <div class="destino-otros" style="display:none">
                            <select name="destino[<?= (int) $e['id'] ?>]">
                                <option value="">Estado del equipo…</option>
                                <?php foreach (DESTINOS_OTROS as $clave => $texto): ?>
                                    <option value="<?= h($clave) ?>" <?= ($dict['destino'] ?? '') === $clave ? 'selected' : '' ?>><?= h($texto) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <h2>3) Observaciones</h2>
    <p class="nota-bloqueado">Si el dictamen de algún equipo es "Otros", explica aquí el motivo.</p>
    <label><textarea name="observaciones" rows="3"><?= h($datos['observaciones'] ?? '') ?></textarea></label>

    <h2>4) Firma del técnico</h2>
    <div class="firma-tabs">
        <button type="button" id="tab-dibujar" class="activo" onclick="mostrarFirmaTab('dibujar')">Dibujar firma</button>
        <button type="button" id="tab-subir" onclick="mostrarFirmaTab('subir')">Subir imagen</button>
    </div>
    <div id="panel-dibujar" class="firma-panel activo">
        <canvas id="firma-canvas" width="400" height="150"></canvas>
        <div class="firma-acciones"><button type="button" onclick="limpiarFirma()">Limpiar</button></div>
    </div>
    <div id="panel-subir" class="firma-panel">
        <input type="file" name="firma_archivo" id="firma_archivo" accept="image/png,image/jpeg">
    </div>
    <input type="hidden" name="firma_dato" id="firma_dato">

    <button type="submit" class="primario">Enviar a V°B° del jefe técnico</button>
</form>

<script>
// Cambia entre las pestañas de la firma (dibujar o subir archivo).
function mostrarFirmaTab(tab) {
    document.getElementById('tab-dibujar').classList.toggle('activo', tab === 'dibujar');
    document.getElementById('tab-subir').classList.toggle('activo', tab === 'subir');
    document.getElementById('panel-dibujar').classList.toggle('activo', tab === 'dibujar');
    document.getElementById('panel-subir').classList.toggle('activo', tab === 'subir');
}

// Muestra u oculta el selector de destino cuando el dictamen es "Otros".
function actualizarDestinos() {
    document.querySelectorAll('.dictamen-select').forEach(function (sel) {
        const caja = sel.parentElement.querySelector('.destino-otros');
        const destino = caja.querySelector('select');
        const esOtros = sel.value === 'otros';
        caja.style.display = esOtros ? 'block' : 'none';
        destino.required = esOtros;
        if (!esOtros) { destino.value = ''; }
    });
}
document.querySelectorAll('.dictamen-select').forEach(function (sel) {
    sel.addEventListener('change', actualizarDestinos);
});
actualizarDestinos();

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
</script>

<?php render_footer(); ?>
