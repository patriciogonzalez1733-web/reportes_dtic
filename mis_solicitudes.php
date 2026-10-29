<?php
// Listado de las solicitudes propias (todas si es admin) con sus acciones.
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/documento.php';

// Solo los roles indicados pueden entrar a esta página.
$u = require_rol(['responsable', 'encargado', 'admin']);

$veTodas = in_array($u['rol'], ['admin', 'encargado'], true);
if ($veTodas) {
    $solicitudes = $conn->query(
        "SELECT s.*, us.nombre AS creador_nombre,
                (SELECT COUNT(*) FROM solicitudes it WHERE it.solicitud_origen_id = s.id
                   AND it.tipo = 'informe_tecnico' AND it.estado = 'en_curso') AS informe_pendiente,
                (SELECT it.id FROM solicitudes it WHERE it.solicitud_origen_id = s.id
                   AND it.tipo = 'informe_tecnico' AND it.estado = 'en_curso' LIMIT 1) AS informe_id,
                (SELECT it.fase_actual FROM solicitudes it WHERE it.solicitud_origen_id = s.id
                   AND it.tipo = 'informe_tecnico' AND it.estado = 'en_curso' LIMIT 1) AS informe_fase
         FROM solicitudes s
         JOIN usuarios us ON us.id = s.creado_por
         ORDER BY s.creado_en DESC"
    )->fetch_all(MYSQLI_ASSOC);
} else {
    $stmt = $conn->prepare(
        "SELECT s.*, us.nombre AS creador_nombre,
                (SELECT COUNT(*) FROM solicitudes it WHERE it.solicitud_origen_id = s.id
                   AND it.tipo = 'informe_tecnico' AND it.estado = 'en_curso') AS informe_pendiente,
                (SELECT it.id FROM solicitudes it WHERE it.solicitud_origen_id = s.id
                   AND it.tipo = 'informe_tecnico' AND it.estado = 'en_curso' LIMIT 1) AS informe_id,
                (SELECT it.fase_actual FROM solicitudes it WHERE it.solicitud_origen_id = s.id
                   AND it.tipo = 'informe_tecnico' AND it.estado = 'en_curso' LIMIT 1) AS informe_fase
         FROM solicitudes s
         JOIN usuarios us ON us.id = s.creado_por
         WHERE s.creado_por = ?
         ORDER BY s.creado_en DESC"
    );
    $stmt->bind_param('i', $u['id']);
    $stmt->execute();
    $solicitudes = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
}

// Nombre de la fase según el tipo de solicitud.
function etiqueta_fase(string $tipo, int $fase): string
{
    $esRecepcion = $tipo === 'recepcion';
    $etiquetas = [
        1 => 'Fase 1 · Corrección',
        2 => 'Fase 2 · ' . ($esRecepcion ? 'Jefe que recibe (DTIC)' : 'Jefe que entrega'),
        3 => 'Fase 3 · ' . ($esRecepcion ? 'Jefe que entrega (devuelve)' : 'Jefe que recibe'),
        4 => 'Fase 4 · Usuario final',
        5 => 'Fase 5 · Cierre',
    ];
    return $etiquetas[$fase] ?? 'Fase ' . $fase;
}

// Dibuja la cabecera de la página.
render_header('Mis solicitudes', $u);
?>
<h1>Mis solicitudes</h1>

<?php if (!$solicitudes): ?>
    <p class="aviso">Aún no has creado ninguna solicitud. <a href="nueva_entrega.php">Crear una nueva entrega</a></p>
<?php else: ?>

<table class="tabla">
    <thead>
        <tr>
            <th>Folio</th>
            <th>Tipo</th>
            <th>Estado</th>
            <th>Custodio que recibe</th>
            <?php if ($veTodas): ?><th>Creada por</th><?php endif; ?>
            <th>Creada</th>
            <th>Acciones</th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($solicitudes as $s): ?>
            <tr>
                <td><?= h($s['folio']) ?></td>
                <td><?= h(ucfirst($s['tipo'])) ?></td>
                <td>
                    <?php if ($s['estado'] === 'en_curso'): ?>
                        <span class="pill estado-en_curso"><?= h(etiqueta_fase($s['tipo'], (int) $s['fase_actual'])) ?></span>
                    <?php elseif ($s['estado'] === 'finalizada'): ?>
                        <span class="pill estado-finalizada">Finalizada</span>
                    <?php else: ?>
                        <span class="pill estado-anulada">Anulada</span>
                    <?php endif; ?>
                </td>
                <td><?= h($s['solicitante_nombre']) ?></td>
                <?php if ($veTodas): ?><td><?= h($s['creador_nombre']) ?></td><?php endif; ?>
                <td><?= h(date('d-m-Y H:i', strtotime($s['creado_en']))) ?></td>
                <td>
                    <div class="acciones">
                    <a href="seguimiento.php?id=<?= (int) $s['id'] ?>" class="chico">Ver seguimiento</a>
                    <?php if ($s['estado'] === 'en_curso' && (int) $s['fase_actual'] === 1): ?>
                        <?php if ($s['tipo'] === 'informe_tecnico'): ?>
                            <a href="informe_tecnico.php?id=<?= (int) $s['id'] ?>" class="chico">Completar informe técnico</a>
                        <?php else: ?>
                            <a href="corregir.php?id=<?= (int) $s['id'] ?>" class="chico">Corregir y reenviar</a>
                        <?php endif; ?>
                    <?php endif; ?>
                    <?php if ($s['tipo'] === 'recepcion' && (int) $s['informe_pendiente'] > 0): ?>
                        <?php if ((int) $s['informe_fase'] === 1): ?>
                            <a href="informe_tecnico.php?id=<?= (int) $s['informe_id'] ?>" class="chico">Informe técnico pendiente: completar</a>
                        <?php else: ?>
                            <span class="chico">Informe técnico pendiente: en V°B°</span>
                        <?php endif; ?>
                    <?php endif; ?>
                    <?php if (is_file(__DIR__ . '/generados/' . nombre_documento($s))): ?>
                        <a href="ver_documento.php?id=<?= (int) $s['id'] ?>" target="_blank" class="chico">Ver documento</a>
                    <?php endif; ?>
                    <?php $fasesToken = $s['tipo'] === 'informe_tecnico' ? [2] : [3, 4]; ?>
                    <?php if ($s['estado'] === 'en_curso' && in_array((int) $s['fase_actual'], $fasesToken, true)
                              && ($u['rol'] === 'admin' || (int) $s['creado_por'] === (int) $u['id'])): ?>
                        <a href="reenviar_link.php?id=<?= (int) $s['id'] ?>" class="chico">Reenviar link</a>
                    <?php endif; ?>
                    <?php if ($s['estado'] === 'en_curso' && in_array((int) $s['fase_actual'], $fasesToken, true)): ?>
                        <a href="firma_presencial.php?id=<?= (int) $s['id'] ?>" class="chico">Firmar presencialmente</a>
                    <?php endif; ?>
                    <?php if ($u['rol'] === 'admin' && $s['estado'] === 'en_curso'): ?>
                        <a href="anular.php?id=<?= (int) $s['id'] ?>" class="chico link-eliminar">Anular</a>
                    <?php endif; ?>
                    </div>
                </td>
            </tr>
        <?php endforeach; ?>
    </tbody>
</table>

<?php endif; ?>

<p><a href="nueva_entrega.php">+ Nueva entrega</a> · <a href="index.php">← Volver al inicio</a></p>

<?php render_footer(); ?>
