<?php
// Pantalla de los firmantes externos (jefe que recibe, usuario final y V°B° del informe técnico). Se entra con un link de un solo uso, sin iniciar sesión.
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/firmas.php';
require_once __DIR__ . '/includes/correo.php';
require_once __DIR__ . '/includes/documento.php';
require_once __DIR__ . '/includes/tokens.php';
require_once __DIR__ . '/includes/informe.php';
require_once __DIR__ . '/vendor/autoload.php';

// Guarda la firma que envía un firmante externo.
function guardar_firma_externa(string $ruta, string $firmaDato, ?array $firmaArchivo): void
{
    $dir = dirname($ruta);
    if (!is_dir($dir)) mkdir($dir, 0775, true);

    guardar_firma($firmaDato, $firmaArchivo, $ruta);
}

// Muestra una pantalla con un mensaje (error o aviso) y termina.
function pantalla_mensaje(string $titulo, string $mensaje, string $clase = 'error'): void
{
    // Dibuja la cabecera de la página.
    render_header($titulo);
    echo '<h1>' . h($titulo) . '</h1><p class="' . h($clase) . '">' . $mensaje . '</p>';
    render_footer();
    exit;
}

$tokenTexto = (string) ($_GET['token'] ?? $_POST['token'] ?? '');
$token = $tokenTexto !== '' ? validar_token($conn, $tokenTexto) : null;

if (!$token) {
    pantalla_mensaje('Link no válido',
        'Este link no existe, ya fue utilizado o expiró. Si necesitas volver a revisar la solicitud, pide que te envíen uno nuevo.');
}

$fase = (int) $token['fase'];
$id   = (int) $token['solicitud_id'];

$stmt = $conn->prepare(
    "SELECT s.*, us.nombre AS creador_nombre, us.correo AS creador_correo
     FROM solicitudes s JOIN usuarios us ON us.id = s.creado_por
     WHERE s.id = ?"
);
$stmt->bind_param('i', $id);
$stmt->execute();
$solicitud = $stmt->get_result()->fetch_assoc();
$stmt->close();

$fasesValidasPorTipo = $solicitud && $solicitud['tipo'] === 'informe_tecnico' ? [2] : [3, 4];
if (!$solicitud || !in_array($fase, $fasesValidasPorTipo, true) || (int) $solicitud['fase_actual'] !== $fase || $solicitud['estado'] !== 'en_curso') {
    pantalla_mensaje('Solicitud no disponible', 'Esta solicitud ya no está esperando tu respuesta.');
}

$datos = json_decode($solicitud['datos'], true);
$folio = $solicitud['folio'];

$plantillaDoc = $solicitud['tipo'] === 'recepcion' ? 'rbi.docx' : 'tbi.docx';
$prefijoDoc   = $solicitud['tipo'] === 'recepcion' ? 'RBI' : 'TBI';
$firmasDir = __DIR__ . '/firmas';
if (!is_dir($firmasDir)) mkdir($firmasDir, 0775, true);

$equipos = $conn->query(
    "SELECT e.id, e.codigo_inventario, e.descripcion, e.serie
     FROM solicitud_equipos se JOIN equipos e ON e.id = se.equipo_id
     WHERE se.solicitud_id = " . $id
)->fetch_all(MYSQLI_ASSOC);

if ($solicitud['tipo'] === 'informe_tecnico') {
    $errorIT = '';

    // Se ejecuta cuando se envía el formulario.
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        // Verifica el token de seguridad del formulario.
        csrf_check();
        $accionIT          = $_POST['accion'] ?? '';
        $comentarioIT      = trim($_POST['comentario'] ?? '');
        $firmaDatoIT       = $_POST['firma_dato'] ?? '';
        $firmaArchivoIT    = $_FILES['firma_archivo'] ?? null;

        if (!in_array($accionIT, ['aprobar', 'rechazar'], true)) {
            $errorIT = 'Acción no válida.';
        } elseif ($accionIT === 'rechazar' && $comentarioIT === '') {
            $errorIT = 'Debes indicar el motivo del rechazo.';
        } elseif ($errLargoIT = validar_largos(['El comentario' => [$comentarioIT, 1000]])) {
            $errorIT = $errLargoIT;
        } elseif ($accionIT === 'aprobar' && $firmaDatoIT === '' && (!$firmaArchivoIT || $firmaArchivoIT['error'] !== UPLOAD_ERR_OK)) {
            $errorIT = 'Debes firmar para continuar.';
        } elseif ($accionIT === 'aprobar' && ($errFirmaIT = validar_firma($firmaDatoIT, $firmaArchivoIT))) {
            $errorIT = $errFirmaIT;
        }

        if (!$errorIT) {
            // Los cambios se guardan todos juntos o no se guarda ninguno.
            $conn->begin_transaction();
            try {
                $stmt = $conn->prepare("SELECT id FROM tokens_confirmacion WHERE id = ? AND usado = 0 AND expira_en > NOW() FOR UPDATE");
                $stmt->bind_param('i', $token['id']);
                $stmt->execute();
                $tokenVigenteIT = $stmt->get_result()->fetch_assoc();
                $stmt->close();

                $stmt = $conn->prepare("SELECT *, TIMESTAMPDIFF(SECOND, fase_desde, NOW()) AS seg_en_fase FROM solicitudes WHERE id = ? AND fase_actual = 2 AND estado = 'en_curso' FOR UPDATE");
                $stmt->bind_param('i', $id);
                $stmt->execute();
                $bloqueadaIT = $stmt->get_result()->fetch_assoc();
                $stmt->close();

                if (!$tokenVigenteIT || !$bloqueadaIT) {
                    throw new RuntimeException('Este link ya fue utilizado o el informe cambió de fase.');
                }

                $segundosIT = (int) $bloqueadaIT['seg_en_fase'];
                $actorIT    = $token['destinatario_nombre'];
                $ipIT       = $_SERVER['REMOTE_ADDR'] ?? '';
                $datosIT    = json_decode($bloqueadaIT['datos'], true);

                if ($accionIT === 'aprobar') {
                    guardar_firma_externa("$firmasDir/{$folio}_jefe_tecnico.png", $firmaDatoIT, $firmaArchivoIT);

                    $comentarioOpcIT = $comentarioIT !== '' ? $comentarioIT : null;
                    $stmt = $conn->prepare(
                        "INSERT INTO historial (solicitud_id, fase_origen, fase_destino, accion, actor_externo_nombre, comentario, segundos_en_fase)
                         VALUES (?, 2, 3, 'confirmar', ?, ?, ?)"
                    );
                    $stmt->bind_param('issi', $id, $actorIT, $comentarioOpcIT, $segundosIT);
                    $stmt->execute();
                    $stmt->close();

                    $stmt = $conn->prepare("UPDATE solicitudes SET fase_actual = 3, estado = 'finalizada', fase_desde = NOW() WHERE id = ?");
                    $stmt->bind_param('i', $id);
                    $stmt->execute();
                    $stmt->close();

                    $stmt = $conn->prepare(
                        "INSERT INTO historial (solicitud_id, fase_origen, fase_destino, accion, actor_externo_nombre, comentario)
                         VALUES (?, 3, 3, 'finalizar', ?, 'Informe técnico finalizado.')"
                    );
                    $stmt->bind_param('is', $id, $actorIT);
                    $stmt->execute();
                    $stmt->close();

                    aplicar_dictamen_si_corresponde($conn, $id);

                    // Marca el link como usado para que no se pueda repetir.
                    marcar_token_usado($conn, (int) $token['id'], $ipIT);
                    $conn->commit();

                    $solActIT = $bloqueadaIT; $solActIT['fase_actual'] = 3;
                    $rutaTecnico = "$firmasDir/{$folio}_tecnico.png";
                    $rutaJefeTecnico = "$firmasDir/{$folio}_jefe_tecnico.png";
                    // Genera el documento con los datos y firmas actuales.
                    $rutaCit = __DIR__ . '/generados/' . generar_cit(
                        $solActIT, $equipos,
                        is_file($rutaTecnico) ? $rutaTecnico : null,
                        is_file($rutaJefeTecnico) ? $rutaJefeTecnico : null
                    );

                    $destIT = [];
                    $res = $conn->query("SELECT nombre, correo FROM usuarios WHERE rol = 'activo_fijo' AND activo = 1");
                    foreach ($res->fetch_all(MYSQLI_ASSOC) as $r) $destIT[strtolower($r['correo'])] = [$r['correo'], $r['nombre']];
                    $destIT[strtolower($solicitud['creador_correo'])] = [$solicitud['creador_correo'], $solicitud['creador_nombre']];
                    if (!empty($datosIT['jefe_tecnico_correo'])) {
                        $destIT[strtolower($datosIT['jefe_tecnico_correo'])] = [$datosIT['jefe_tecnico_correo'], $datosIT['jefe_tecnico_nombre'] ?? 'Jefe de unidad técnica'];
                    }
                    foreach ($destIT as [$correoD, $nombreD]) {
                        enviar_correo($correoD, $nombreD, "Informe técnico $folio - Finalizado (CIT firmado)",
                            "<p>El informe técnico <strong>{$folio}</strong> fue completado y firmado por todas las partes.</p>
                             <p>Se adjunta copia del documento final.</p>", $rutaCit);
                    }

                    pantalla_mensaje('Respuesta registrada', 'Gracias. El informe técnico quedó finalizado.', 'ok');
                } else {
                    $stmt = $conn->prepare(
                        "INSERT INTO historial (solicitud_id, fase_origen, fase_destino, accion, actor_externo_nombre, comentario, segundos_en_fase)
                         VALUES (?, 2, 1, 'rechazar', ?, ?, ?)"
                    );
                    $stmt->bind_param('issi', $id, $actorIT, $comentarioIT, $segundosIT);
                    $stmt->execute();
                    $stmt->close();

                    $stmt = $conn->prepare("UPDATE solicitudes SET fase_actual = 1, fase_desde = NOW() WHERE id = ?");
                    $stmt->bind_param('i', $id);
                    $stmt->execute();
                    $stmt->close();

                    // Marca el link como usado para que no se pueda repetir.
                    marcar_token_usado($conn, (int) $token['id'], $ipIT);
                    $conn->commit();

                    $linkCorregir = url_base() . '/informe_tecnico.php?id=' . $id;
                    enviar_correo($solicitud['creador_correo'], $solicitud['creador_nombre'],
                        "Informe técnico $folio - Rechazado, requiere corrección",
                        "<p>El informe técnico <strong>{$folio}</strong> fue <strong>rechazado</strong> por " . h($actorIT) . " y volvió a Fase 1.</p>
                         <p><strong>Motivo:</strong> " . nl2br(h($comentarioIT)) . "</p>
                         <p><a href=\"{$linkCorregir}\">Haz clic aquí para corregir y reenviar</a></p>");

                    pantalla_mensaje('Respuesta registrada', 'Tu rechazo fue registrado. El informe vuelve a corrección.', 'ok');
                }
            } catch (Throwable $e) {
                try { $conn->rollback(); } catch (Throwable $ignorar) {}
                $errorIT = 'No se pudo procesar: ' . $e->getMessage();
            }
        }
    }

    // Dibuja la cabecera de la página.
    render_header('Confirmar informe técnico');
    ?>
    <h1>Informe técnico <?= h($folio) ?></h1>
    <p class="aviso">Hola <?= h($token['destinatario_nombre']) ?>, se te solicita dar el V°B° de este informe técnico.</p>

    <?php if ($errorIT): ?><p class="error"><?= h($errorIT) ?></p><?php endif; ?>

    <p><strong>Ubicación física:</strong> <?= h($datos['ubicacion_fisica'] ?? '') ?></p>
    <p><strong>Nombre del custodio:</strong> <?= h($datos['nombre_custodio'] ?? '') ?></p>
    <p><strong>Técnico asignado:</strong> <?= h($datos['tecnico_nombre'] ?? '') ?></p>

    <h2>Diagnóstico por equipo</h2>
    <table class="tabla">
        <thead><tr><th>Código</th><th>Descripción</th><th>Serie</th><th>Diagnóstico</th><th>Dictamen</th></tr></thead>
        <tbody>
            <?php foreach ($equipos as $e): $dict = $datos['equipos_dictamen'][$e['id']] ?? ['diagnostico' => '—', 'dictamen' => '']; ?>
                <tr>
                    <td><?= h($e['codigo_inventario'] ?: '—') ?></td>
                    <td><?= h($e['descripcion']) ?></td>
                    <td><?= h($e['serie'] ?: '—') ?></td>
                    <td><?= h($dict['diagnostico']) ?></td>
                    <td><?= h(dictamen_texto($dict, true) ?: '—') ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <p><strong>Observaciones:</strong> <?= h($datos['observaciones'] ?: '—') ?></p>

    <h2>Documento a firmar</h2>
    <iframe class="visor-pdf horizontal" src="ver_documento.php?token=<?= h($tokenTexto) ?>#toolbar=0&navpanes=0&view=FitH" title="Informe técnico"></iframe>

    <h2>Tu respuesta</h2>
    <form method="post" class="form-solicitud" enctype="multipart/form-data" id="form-revision">
        <?= csrf_field() ?>
        <input type="hidden" name="token" value="<?= h($tokenTexto) ?>">
        <input type="hidden" name="accion" id="accion" value="">

        <h3>Si das el V°B° (informe técnico): firma aquí</h3>
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

        <h3>Si rechazas: indica el motivo</h3>
        <label><textarea name="comentario" id="comentario" rows="3" maxlength="1000" placeholder="Qué hay que corregir..."></textarea></label>

        <div class="fila-radios">
            <button type="button" class="primario" onclick="enviarComo('aprobar')">Dar V°B°</button>
            <button type="button" onclick="enviarComo('rechazar')">Rechazar</button>
        </div>
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
    ctx.lineWidth = 3; ctx.lineCap = 'round';
    let dibujando = false;
    // Pinta el recuadro de firma de blanco.
    function fondoBlancoCanvas() { ctx.fillStyle = '#fff'; ctx.fillRect(0, 0, canvas.width, canvas.height); ctx.strokeStyle = '#000'; }
    fondoBlancoCanvas();
    // Calcula dónde está el dedo o el mouse dentro del recuadro.
    function posicion(e) { const r = canvas.getBoundingClientRect(); const p = e.touches ? e.touches[0] : e; return { x: (p.clientX - r.left) * (canvas.width / r.width), y: (p.clientY - r.top) * (canvas.height / r.height) }; }
    // Empieza un trazo.
    function empezar(e) { dibujando = true; const p = posicion(e); ctx.beginPath(); ctx.moveTo(p.x, p.y); e.preventDefault(); }
    // Dibuja el trazo mientras se mueve el dedo o el mouse.
    function mover(e) { if (!dibujando) return; const p = posicion(e); ctx.lineTo(p.x, p.y); ctx.stroke(); e.preventDefault(); }
    // Termina el trazo.
    function terminar() { dibujando = false; }
    canvas.addEventListener('mousedown', empezar); canvas.addEventListener('mousemove', mover);
    canvas.addEventListener('mouseup', terminar); canvas.addEventListener('mouseleave', terminar);
    canvas.addEventListener('touchstart', empezar); canvas.addEventListener('touchmove', mover); canvas.addEventListener('touchend', terminar);
    // Borra la firma dibujada.
    function limpiarFirma() { fondoBlancoCanvas(); }
    // Indica si el recuadro de firma está vacío.
    function canvasVacio() {
        const px = ctx.getImageData(0, 0, canvas.width, canvas.height).data;
        for (let i = 0; i < px.length; i += 4) if (px[i] !== 255 || px[i+1] !== 255 || px[i+2] !== 255) return false;
        return true;
    }

    let enviado = false;
    // Revisa lo necesario y envía el formulario con la acción elegida.
    function enviarComo(accion) {
        if (enviado) return;
        const form = document.getElementById('form-revision');
        if (accion === 'rechazar') {
            if (document.getElementById('comentario').value.trim() === '') { alert('Indica el motivo del rechazo.'); return; }
        } else {
            if (!document.getElementById('panel-subir').classList.contains('activo')) {
                if (canvasVacio()) { alert('Dibuja tu firma o sube una imagen para continuar.'); return; }
                document.getElementById('firma_dato').value = canvas.toDataURL('image/png');
            } else if (!document.getElementById('firma_archivo').files.length) {
                alert('Selecciona una imagen de firma.'); return;
            }
        }
        enviado = true;
        document.getElementById('accion').value = accion;
        form.submit();
    }
    </script>
    <?php
    render_footer();
    exit;
}

$esRecepcion         = $solicitud['tipo'] === 'recepcion';
$labelCustodioActual = $esRecepcion ? 'Custodio que recibe (DTIC)' : 'Custodio que entrega';
$labelCustodioNuevo  = $esRecepcion ? 'Custodio que entrega (devuelve)' : 'Custodio que recibe';
$labelJefeFase2      = $esRecepcion ? 'jefe de sector que recibe (DTIC)' : 'jefe de sector que entrega';
$labelJefeFase3      = $esRecepcion ? 'jefe de sector que entrega (quien devuelve)' : 'jefe de sector que recibe';

$rolTitulo    = $fase === 3 ? $labelJefeFase3 : 'usuario final (custodio que recibe)';
$textoAprobar = $fase === 3 ? 'Aprobar' : 'Aceptar y recibir equipos';

$error = '';

// Se ejecuta cuando se envía el formulario.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Verifica el token de seguridad del formulario.
    csrf_check();
    $accion       = $_POST['accion'] ?? '';
    $comentario   = trim($_POST['comentario'] ?? '');
    $firmaDato    = $_POST['firma_dato'] ?? '';
    $firmaArchivo = $_FILES['firma_archivo'] ?? null;

    if (!in_array($accion, ['aprobar', 'rechazar'], true)) {
        $error = 'Acción no válida.';
    } elseif ($accion === 'rechazar' && $comentario === '') {
        $error = 'Debes indicar el motivo del rechazo.';
    } elseif ($errLargo = validar_largos(['El comentario' => [$comentario, 1000]])) {
        $error = $errLargo;
    } elseif ($accion === 'aprobar' && $firmaDato === '' && (!$firmaArchivo || $firmaArchivo['error'] !== UPLOAD_ERR_OK)) {
        $error = 'Debes firmar para continuar.';
    } elseif ($accion === 'aprobar' && ($errFirma = validar_firma($firmaDato, $firmaArchivo))) {
        $error = $errFirma;
    }

    if (!$error) {
        $correos = [];
        // Los cambios se guardan todos juntos o no se guarda ninguno.
        $conn->begin_transaction();
        try {
            $stmt = $conn->prepare("SELECT id FROM tokens_confirmacion WHERE id = ? AND usado = 0 AND expira_en > NOW() FOR UPDATE");
            $stmt->bind_param('i', $token['id']);
            $stmt->execute();
            $tokenVigente = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            $stmt = $conn->prepare("SELECT *, TIMESTAMPDIFF(SECOND, fase_desde, NOW()) AS seg_en_fase FROM solicitudes WHERE id = ? AND fase_actual = ? AND estado = 'en_curso' FOR UPDATE");
            $stmt->bind_param('ii', $id, $fase);
            $stmt->execute();
            $bloqueada = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!$tokenVigente || !$bloqueada) {
                throw new RuntimeException('Este link ya fue utilizado o la solicitud cambió de fase.');
            }

            $segundos  = (int) $bloqueada['seg_en_fase'];
            $actor     = $token['destinatario_nombre'];
            $firmasDir = __DIR__ . '/firmas';
            $ip        = $_SERVER['REMOTE_ADDR'] ?? '';

            $historial = function (int $origen, int $destino, string $accionHist, ?string $coment) use ($conn, $id, $actor, $segundos) {
                $stmt = $conn->prepare(
                    "INSERT INTO historial (solicitud_id, fase_origen, fase_destino, accion, actor_externo_nombre, comentario, segundos_en_fase)
                     VALUES (?, ?, ?, ?, ?, ?, ?)"
                );
                $stmt->bind_param('iiisssi', $id, $origen, $destino, $accionHist, $actor, $coment, $segundos);
                $stmt->execute();
                $stmt->close();
            };
            $mover = function (int $nuevaFase, string $estado = 'en_curso') use ($conn, $id) {
                $stmt = $conn->prepare("UPDATE solicitudes SET fase_actual = ?, estado = ?, fase_desde = NOW() WHERE id = ?");
                $stmt->bind_param('isi', $nuevaFase, $estado, $id);
                $stmt->execute();
                $stmt->close();
            };
            $comentarioOpc = $comentario !== '' ? $comentario : null;

            if ($fase === 3 && $accion === 'aprobar') {
                guardar_firma_externa("$firmasDir/{$folio}_jefe_recibe.png", $firmaDato, $firmaArchivo);
                $mover(4);
                $historial(3, 4, 'aprobar', $comentarioOpc);
                // Crea el link de un solo uso para el firmante.
                $nuevoToken = crear_token($conn, $id, 4, $bloqueada['solicitante_correo'], $bloqueada['solicitante_nombre']);
                // Marca el link como usado para que no se pueda repetir.
                marcar_token_usado($conn, (int) $token['id'], $ip);
                $conn->commit();

                $solAct = $bloqueada; $solAct['fase_actual'] = 4;
                generar_tbi($solAct, $equipos, false, $plantillaDoc, $prefijoDoc);
                $link = url_base() . '/confirmar.php?token=' . $nuevoToken;

                $correos[] = [$bloqueada['solicitante_correo'], $bloqueada['solicitante_nombre'],
                    "Solicitud $folio - Confirma la recepción de tus equipos",
                    "<p>Se te asignarán los equipos indicados en el traspaso <strong>{$folio}</strong>. Revisa el detalle, firma y acepta o rechaza la recepción.</p>
                     <p><a href=\"{$link}\">Haz clic aquí para revisar y confirmar</a></p>
                     <p>Este link es de un solo uso y expira en 72 horas.</p>"];
                $correos[] = [$solicitud['creador_correo'], $solicitud['creador_nombre'],
                    "Solicitud $folio - Aprobada por " . ucfirst($labelJefeFase3) . ", avanzó a Fase 4",
                    "<p>Tu solicitud <strong>{$folio}</strong> fue aprobada por " . h($actor) . " (" . h($labelJefeFase3) . ") y ahora espera la confirmación del usuario final (Fase 4).</p>"];

            } elseif ($fase === 3) {
                @unlink("$firmasDir/{$folio}_jefe_entrega.png");
                $mover(2);
                $historial(3, 2, 'rechazar', $comentario);
                // Marca el link como usado para que no se pueda repetir.
                marcar_token_usado($conn, (int) $token['id'], $ip);
                $conn->commit();

                $linkRevisar = url_base() . '/fase2_revisar.php?id=' . $id;
                if (!empty($datos['jefe_entrega_correo'])) {
                    $correos[] = [$datos['jefe_entrega_correo'], $datos['jefe_actual'] ?? ucfirst($labelJefeFase2),
                        "Solicitud $folio - Rechazada por " . ucfirst($labelJefeFase3) . ", requiere tu revisión",
                        "<p>La solicitud <strong>{$folio}</strong> fue <strong>rechazada</strong> por " . h($actor) . " (" . h($labelJefeFase3) . ") y volvió a <strong>Fase 2</strong> para tu revisión.</p>
                         <p><strong>Motivo:</strong> " . nl2br(h($comentario)) . "</p>
                         <p><a href=\"{$linkRevisar}\">Haz clic aquí para revisar y volver a aprobar</a></p>", null];
                }

                $cuerpoInfo = "<p>La solicitud <strong>{$folio}</strong> fue <strong>rechazada</strong> por " . h($actor) . " (" . h($labelJefeFase3) . ") y volvió a <strong>Fase 2</strong> (revisión del " . h($labelJefeFase2) . ").</p>
                     <p><strong>Motivo:</strong> " . nl2br(h($comentario)) . "</p>";
                $correos[] = [$solicitud['creador_correo'], $solicitud['creador_nombre'],
                    "Solicitud $folio - Rechazada por " . ucfirst($labelJefeFase3) . ", vuelve a Fase 2", $cuerpoInfo, null];
                if (strcasecmp($bloqueada['solicitante_correo'], $solicitud['creador_correo']) !== 0) {
                    $correos[] = [$bloqueada['solicitante_correo'], $bloqueada['solicitante_nombre'],
                        "Solicitud $folio - Rechazada por " . ucfirst($labelJefeFase3) . ", vuelve a Fase 2", $cuerpoInfo, null];
                }

            } elseif ($accion === 'aprobar') {
                guardar_firma_externa("$firmasDir/{$folio}_nuevo.png", $firmaDato, $firmaArchivo);
                $historial(4, 5, 'confirmar', $comentarioOpc);
                $mover(5, 'finalizada');
                $custodioNuevo = $bloqueada['solicitante_nombre'];

                if ($solicitud['tipo'] === 'recepcion') {
                    $historial(5, 5, 'finalizar', 'Recepción finalizada.');
                    aplicar_dictamen_si_corresponde($conn, $id);
                } else {
                    $historial(5, 5, 'finalizar', 'Solicitud finalizada: equipos asignados.');

                    $stmt = $conn->prepare(
                        "UPDATE equipos e JOIN solicitud_equipos se ON se.equipo_id = e.id
                         SET e.estado = 'asignado', e.custodio_actual = ?, e.ubicacion_actual = ?, e.bloqueado_por = NULL
                         WHERE se.solicitud_id = ?"
                    );
                    $ubicNueva = $datos['ubic_nueva'] ?? '';
                    $stmt->bind_param('ssi', $custodioNuevo, $ubicNueva, $id);
                    $stmt->execute();
                    $stmt->close();
                }

                // Marca el link como usado para que no se pueda repetir.
                marcar_token_usado($conn, (int) $token['id'], $ip);

                $dest = [];
                $res = $conn->query("SELECT nombre, correo FROM usuarios WHERE rol = 'activo_fijo' AND activo = 1");
                foreach ($res->fetch_all(MYSQLI_ASSOC) as $r) $dest[$r['correo']] = $r['nombre'];
                $dest[$bloqueada['solicitante_correo']] = $bloqueada['solicitante_nombre'];
                $dest[$solicitud['creador_correo']]     = $solicitud['creador_nombre'];
                if (!empty($datos['jefe_entrega_correo'])) {
                    $dest[$datos['jefe_entrega_correo']] = $datos['jefe_actual'] ?? ucfirst($labelJefeFase2);
                }

                $destUnicos = [];
                foreach ($dest as $correoDest => $nombreDest) {
                    $destUnicos[strtolower($correoDest)] ??= $nombreDest;
                }
                $dest = $destUnicos;

                $conn->commit();

                $solAct = $bloqueada; $solAct['fase_actual'] = 5;
                // Genera el documento con los datos y firmas actuales.
                $rutaTbi = __DIR__ . '/generados/' . generar_tbi($solAct, $equipos, false, $plantillaDoc, $prefijoDoc);
                $cuerpoFinal = $solicitud['tipo'] === 'recepcion'
                    ? "<p>La recepción <strong>{$folio}</strong> fue completada y firmada por todas las partes.</p>
                       <p>Se adjunta copia del documento final.</p>"
                    : "<p>El traspaso de bienes <strong>{$folio}</strong> fue completado y firmado por todas las partes.
                          Los equipos quedaron asignados a <strong>" . h($custodioNuevo) . "</strong>.</p>
                       <p>Se adjunta copia del documento final.</p>";
                foreach ($dest as $correo => $nombre) {
                    $correos[] = [$correo, $nombre, "Solicitud $folio - Finalizada ({$prefijoDoc} firmado)", $cuerpoFinal, $rutaTbi];
                }

            } else {
                @unlink("$firmasDir/{$folio}_jefe_entrega.png");
                @unlink("$firmasDir/{$folio}_jefe_recibe.png");
                $mover(2);
                $historial(4, 2, 'rechazar_usuario', $comentario);
                // Marca el link como usado para que no se pueda repetir.
                marcar_token_usado($conn, (int) $token['id'], $ip);
                $conn->commit();

                $solAct = $bloqueada; $solAct['fase_actual'] = 2;
                generar_tbi($solAct, $equipos, false, $plantillaDoc, $prefijoDoc);
                $linkRev = url_base() . '/fase2_revisar.php?id=' . $id;
                $cuerpo = "<p>La solicitud <strong>{$folio}</strong> fue <strong>rechazada por el usuario final</strong> (" . h($actor) . ") y volvió a <strong>Fase 2</strong>.</p>
                           <p><strong>Motivo:</strong> " . nl2br(h($comentario)) . "</p>
                           <p><a href=\"{$linkRev}\">Haz clic aquí para revisarla</a></p>";

                if (!empty($datos['jefe_entrega_correo'])) {
                    $correos[] = [$datos['jefe_entrega_correo'], $datos['jefe_actual'] ?? ucfirst($labelJefeFase2),
                        "Solicitud $folio - Rechazada por usuario final, vuelve a Fase 2", $cuerpo];
                }

                $cuerpoCreador = "<p>La solicitud <strong>{$folio}</strong> fue <strong>rechazada por el usuario final</strong> (" . h($actor) . ") y volvió a <strong>Fase 2</strong>.</p>
                           <p><strong>Motivo:</strong> " . nl2br(h($comentario)) . "</p>
                           <p><a href=\"" . url_base() . "/seguimiento.php?id={$id}\">Ver el seguimiento</a></p>";
                $correos[] = [$solicitud['creador_correo'], $solicitud['creador_nombre'],
                    "Solicitud $folio - Rechazada por usuario final, vuelve a Fase 2", $cuerpoCreador];
            }

            foreach ($correos as $correo) {
                enviar_correo(...$correo);
            }

            $msg = match (true) {
                $fase === 3 && $accion === 'aprobar' => 'Gracias. Tu aprobación fue registrada y la solicitud avanzó al usuario final.',
                $fase === 3                          => 'Tu rechazo fue registrado. La solicitud vuelve a corrección.',
                $accion === 'aprobar'                => 'Gracias. Recepción confirmada: el proceso terminó y recibirás una copia del documento firmado.',
                default                              => 'Tu rechazo fue registrado. La solicitud vuelve a revisión.',
            };
            pantalla_mensaje('Respuesta registrada', h($msg), 'ok');
        } catch (Throwable $e) {
            try { $conn->rollback(); } catch (Throwable $ignorar) {}
            $error = 'No se pudo procesar: ' . $e->getMessage();
        }
    }
}

// Dibuja la cabecera de la página.
render_header('Confirmar solicitud');
?>
<h1>Solicitud <?= h($folio) ?></h1>
<p class="aviso">Hola <?= h($token['destinatario_nombre']) ?>, se requiere tu respuesta como <?= h($rolTitulo) ?> (Fase <?= $fase ?>).</p>

<?php if ($error): ?><p class="error"><?= h($error) ?></p><?php endif; ?>

<p><strong>Tipo de traspaso:</strong> <?= h($datos['tipo_traspaso']) ?>
    <?= $datos['tipo_traspaso'] === 'temporal' ? '(' . h($datos['plazo']) . ' días)' : '' ?></p>

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
        <p>Jefe de sector: <?= h($solicitud['jefe_recibe_nombre']) ?></p>
    </fieldset>
</div>

<p><strong>Observaciones:</strong> <?= h($datos['observaciones'] ?: '—') ?></p>

<h2>Documento a firmar</h2>
<iframe class="visor-pdf vertical" src="ver_documento.php?token=<?= h($tokenTexto) ?>#toolbar=0&navpanes=0&view=FitH" title="Documento"></iframe>

<h2>Tu respuesta</h2>
<form method="post" class="form-solicitud" enctype="multipart/form-data" id="form-revision">
    <?= csrf_field() ?>
    <input type="hidden" name="token" value="<?= h($tokenTexto) ?>">
    <input type="hidden" name="accion" id="accion" value="">

    <h3>Si apruebas: firma aquí</h3>
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

    <h3>Si rechazas: indica el motivo</h3>
    <label><textarea name="comentario" id="comentario" rows="3" maxlength="1000" placeholder="Qué hay que corregir..."></textarea></label>

    <div class="fila-radios">
        <button type="button" class="primario" onclick="enviarComo('aprobar')"><?= h($textoAprobar) ?></button>
        <button type="button" onclick="enviarComo('rechazar')">Rechazar</button>
    </div>
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
    const px = ctx.getImageData(0, 0, canvas.width, canvas.height).data;
    for (let i = 0; i < px.length; i += 4) {
        if (px[i] !== 255 || px[i + 1] !== 255 || px[i + 2] !== 255) return false;
    }
    return true;
}

let enviado = false;
// Revisa lo necesario y envía el formulario con la acción elegida.
function enviarComo(accion) {
    if (enviado) return;
    const form = document.getElementById('form-revision');

    if (accion === 'rechazar') {
        if (document.getElementById('comentario').value.trim() === '') {
            alert('Indica el motivo del rechazo.');
            return;
        }
    } else if (!document.getElementById('panel-subir').classList.contains('activo')) {
        if (canvasVacio()) {
            alert('Dibuja tu firma o sube una imagen para continuar.');
            return;
        }
        document.getElementById('firma_dato').value = canvas.toDataURL('image/png');
    } else if (!document.getElementById('firma_archivo').files.length) {
        alert('Selecciona una imagen de firma.');
        return;
    }
    enviado = true;
    document.getElementById('accion').value = accion;
    form.submit();
}
</script>

<?php render_footer(); ?>
