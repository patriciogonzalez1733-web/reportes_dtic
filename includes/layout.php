<?php
// Escapa un texto para mostrarlo en HTML.
function h(?string $texto): string
{
    return htmlspecialchars($texto ?? '', ENT_QUOTES, 'UTF-8');
}

// Valida el formato y el dígito verificador del RUT.
function validar_rut(string $rut): bool
{
    $rut = strtoupper(str_replace(['.', ' '], '', trim($rut)));
    if (!preg_match('/^(\d{7,8})-?([0-9K])$/', $rut, $m)) {
        return false;
    }
    [, $numero, $dv] = $m;
    $suma = 0;
    $multiplicador = 2;
    foreach (array_reverse(str_split($numero)) as $digito) {
        $suma += ((int) $digito) * $multiplicador;
        $multiplicador = $multiplicador === 7 ? 2 : $multiplicador + 1;
    }
    $resto = 11 - ($suma % 11);
    $dvCalculado = $resto === 11 ? '0' : ($resto === 10 ? 'K' : (string) $resto);
    return $dv === $dvCalculado;
}

// Revisa que los textos no pasen el largo máximo.
function validar_largos(array $campos): ?string
{
    foreach ($campos as $etiqueta => [$valor, $maximo]) {
        if (mb_strlen($valor) > $maximo) {
            return "$etiqueta no puede tener más de $maximo caracteres.";
        }
    }
    return null;
}

// Dominios disponibles en el selector de correo.
const DOMINIOS_CORREO = ['unap.cl', 'estudiantesunap.cl', 'gmail.com', 'hotmail.com', 'outlook.com', 'yahoo.com'];

// Campo de correo con selector de dominio.
function campo_correo(string $nombre, string $valor = '', bool $requerido = true): string
{
    [$usuario, $dominio] = array_pad(explode('@', $valor, 2), 2, '');
    $esLista = in_array($dominio, DOMINIOS_CORREO, true);
    $html  = '<span class="correo-campo">';
    $html .= '<input type="hidden" name="' . h($nombre) . '" value="' . h($valor) . '">';
    $html .= '<input type="text" class="correo-usuario" value="' . h($usuario) . '" maxlength="64" placeholder="usuario"' . ($requerido ? ' required' : '') . ' autocomplete="off">';
    $html .= '<select class="correo-dominio">';
    foreach (DOMINIOS_CORREO as $d) {
        $html .= '<option value="' . h($d) . '"' . ($d === $dominio ? ' selected' : '') . '>@' . h($d) . '</option>';
    }
    $html .= '<option value="otro"' . (($dominio !== '' && !$esLista) ? ' selected' : '') . '>Otro dominio…</option>';
    $html .= '</select>';
    $html .= '<input type="text" class="correo-otro" value="' . h($esLista ? '' : $dominio) . '" maxlength="80" placeholder="@dominio.cl">';
    return $html . '</span>';
}

// Cabecera y menú de la página.
function render_header(string $titulo, ?array $u = null): void
{
    ?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= h($titulo) ?> · DTIC</title>
    <link rel="stylesheet" href="assets/estilos.css?v=<?= (int) @filemtime(__DIR__ . '/../assets/estilos.css') ?>">
</head>
<body>
<header class="cabecera">
    <div class="cabecera-marca">
        <strong>DTIC</strong> · Reportes
    </div>
    <?php if ($u): ?>
        <nav class="cabecera-nav">
            <a href="index.php">Inicio</a>
            <a href="seguimiento.php">Seguimiento</a>
            <?php

            $gestion = [];
            if (in_array($u['rol'], ['responsable', 'encargado', 'admin'], true)) {
                $gestion[] = ['equipos.php', 'Inventario'];
            }
            if (in_array($u['rol'], ['responsable', 'encargado', 'admin'], true)) {
                $gestion[] = ['mis_solicitudes.php', 'Mis solicitudes'];
            }
            if ($u['rol'] === 'encargado' || $u['rol'] === 'admin') {
                $gestion[] = ['fase2_pendientes.php', 'Pendientes'];
            }
            if ($u['rol'] === 'admin') {
                $gestion[] = ['usuarios.php', 'Usuarios'];
            }
            ?>
            <?php if ($gestion): ?>
                <details class="nav-desplegable">
                    <summary>Gestión</summary>
                    <div class="nav-desplegable-menu">
                        <?php foreach ($gestion as [$href, $label]): ?>
                            <a href="<?= h($href) ?>"><?= h($label) ?></a>
                        <?php endforeach; ?>
                    </div>
                </details>
            <?php endif; ?>
            <details class="nav-desplegable nav-desplegable-usuario">
                <summary><?= h($u['nombre']) ?></summary>
                <div class="nav-desplegable-menu">
                    <span class="nav-desplegable-rol"><?= h($u['rol']) ?></span>
                    <a href="logout.php">Salir</a>
                </div>
            </details>
        </nav>
    <?php endif; ?>
</header>
<main class="contenido">
    <?php
}

// Cierre de la página.
function render_footer(): void
{
    ?>
</main>
<footer class="pie">
    Universidad Arturo Prat · DTIC
</footer>
<script src="assets/contador.js?v=<?= (int) @filemtime(__DIR__ . '/../assets/contador.js') ?>"></script>
<script src="assets/correo.js?v=<?= (int) @filemtime(__DIR__ . '/../assets/correo.js') ?>"></script>
<script src="assets/escaner_firma.js?v=<?= (int) @filemtime(__DIR__ . '/../assets/escaner_firma.js') ?>"></script>
</body>
</html>
    <?php
}
