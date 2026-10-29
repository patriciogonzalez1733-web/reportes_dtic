<?php
// Lista los informes técnicos que faltan por completar.
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/layout.php';

// Solo los roles indicados pueden entrar a esta página.
$u = require_rol(['responsable', 'encargado', 'admin']);

$veTodos = in_array($u['rol'], ['admin', 'encargado'], true);

$sql = "SELECT s.id, s.folio, s.creado_en, s.solicitud_origen_id, us.nombre AS creador_nombre
        FROM solicitudes s
        JOIN usuarios us ON us.id = s.creado_por
        WHERE s.tipo = 'informe_tecnico' AND s.fase_actual = 1 AND s.estado = 'en_curso'";
if (!$veTodos) {
    $sql .= ' AND s.creado_por = ' . (int) $u['id'];
}
$sql .= ' ORDER BY s.creado_en ASC';
$pendientes = $conn->query($sql)->fetch_all(MYSQLI_ASSOC);

foreach ($pendientes as &$p) {
    $p['folio_origen'] = null;
    if ($p['solicitud_origen_id']) {
        $stmt = $conn->prepare('SELECT folio FROM solicitudes WHERE id = ?');
        $stmt->bind_param('i', $p['solicitud_origen_id']);
        $stmt->execute();
        $origen = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        $p['folio_origen'] = $origen['folio'] ?? null;
    }
}
unset($p);

// Dibuja la cabecera de la página.
render_header('Informes técnicos pendientes', $u);
?>
<h1>Informes técnicos pendientes</h1>
<p class="aviso">Estos informes esperan que se complete el diagnóstico y dictamen de cada equipo, y la firma del técnico.</p>

<table class="tabla">
    <thead>
        <tr><th>Folio</th><th>Recepción origen</th><th>Creado por</th><th>Creado</th><th></th></tr>
    </thead>
    <tbody>
        <?php foreach ($pendientes as $p): ?>
            <tr>
                <td><?= h($p['folio']) ?></td>
                <td><?= h($p['folio_origen'] ?: '—') ?></td>
                <td><?= h($p['creador_nombre']) ?></td>
                <td><?= h(date('d-m-Y H:i', strtotime($p['creado_en']))) ?></td>
                <td><a href="informe_tecnico.php?id=<?= (int) $p['id'] ?>" class="primario">Completar</a></td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$pendientes): ?>
            <tr><td colspan="5">No hay informes técnicos pendientes.</td></tr>
        <?php endif; ?>
    </tbody>
</table>

<p><a href="index.php">← Volver al inicio</a></p>
<?php render_footer(); ?>
