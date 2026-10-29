<?php
// Lista las solicitudes que esperan la aprobación de la Fase 2.
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/layout.php';

// Solo los roles indicados pueden entrar a esta página.
$u = require_rol(['encargado', 'admin']);

$pendientes = $conn->query(
    "SELECT s.id, s.folio, s.tipo, s.solicitante_nombre, s.solicitante_correo,
            s.fase_desde, s.creado_en, us.nombre AS creador_nombre
     FROM solicitudes s
     JOIN usuarios us ON us.id = s.creado_por
     WHERE s.fase_actual = 2 AND s.estado = 'en_curso'
     ORDER BY s.creado_en ASC"
)->fetch_all(MYSQLI_ASSOC);

// Dibuja la cabecera de la página.
render_header('Pendientes - Fase 2', $u);
?>
<h1>Solicitudes pendientes (Fase 2)</h1>
<p class="aviso">Estas solicitudes esperan tu revisión en Fase 2 (en Entrega, como jefe de quien entrega; en Recepción, como jefe de quien recibe — DTIC).</p>

<table class="tabla">
    <thead>
        <tr>
            <th>Folio</th><th>Tipo</th><th>Creada por</th><th>Custodio nuevo</th>
            <th>En esta fase desde</th><th></th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($pendientes as $s): ?>
            <tr>
                <td><?= h($s['folio']) ?></td>
                <td><?= h($s['tipo']) ?></td>
                <td><?= h($s['creador_nombre']) ?></td>
                <td><?= h($s['solicitante_nombre']) ?></td>
                <td><?= h(date('d-m-Y H:i', strtotime($s['fase_desde']))) ?></td>
                <td><a href="fase2_revisar.php?id=<?= (int)$s['id'] ?>" class="primario">Revisar</a></td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$pendientes): ?>
            <tr><td colspan="6">No hay solicitudes pendientes en esta fase.</td></tr>
        <?php endif; ?>
    </tbody>
</table>

<p><a href="index.php">← Volver al inicio</a></p>
<?php render_footer(); ?>
