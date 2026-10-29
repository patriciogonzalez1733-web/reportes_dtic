<?php
// Página de inicio con los accesos según el rol.
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/layout.php';

// Hay que haber iniciado sesión.
$u = require_login();

$tipos = [
    'entrega'         => ['label' => 'Entrega de equipo',     'archivo' => 'nueva_entrega.php'],
    'recepcion'       => ['label' => 'Recepción de equipo',   'archivo' => 'nueva_recepcion.php'],
    'informe_tecnico' => ['label' => 'Informe técnico (CIT)', 'archivo' => 'informes_pendientes.php'],
];

// Dibuja la cabecera de la página.
render_header('Inicio', $u);
?>
<h1>Reportes DTIC</h1>
<p>Hola, <?= h($u['nombre']) ?>.</p>

<?php if (in_array($u['rol'], ['responsable', 'encargado', 'admin'], true)): ?>
    <h2>Nueva solicitud</h2>
    <div class="tarjetas">
        <?php foreach ($tipos as $t): ?>
            <a class="tarjeta" href="<?= h($t['archivo']) ?>"><?= h($t['label']) ?></a>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php if ($u['rol'] === 'encargado' || $u['rol'] === 'admin'): ?>
    <h2>Pendientes</h2>
    <p><a href="fase2_pendientes.php">Ver solicitudes esperando tu revisión (Fase 2)</a></p>
<?php endif; ?>

<?php render_footer(); ?>
