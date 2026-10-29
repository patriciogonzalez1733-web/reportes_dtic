<?php
// Muestra el documento de una solicitud como PDF (con sesión o con link de un solo uso).
require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/tokens.php';
require_once __DIR__ . '/includes/documento.php';

$token = (string) ($_GET['token'] ?? '');
$id    = (int) ($_GET['id'] ?? 0);

if ($token !== '') {
    // Revisa que el link sea válido.
    $t = validar_token($conn, $token);
    if (!$t) {
        http_response_code(403);
        die('Enlace inválido o vencido');
    }
    $id = (int) $t['solicitud_id'];
} else {
    // Hay que haber iniciado sesión.
    require_login();
}

// Busca una solicitud por su id.
function buscar_solicitud(mysqli $conn, int $id): ?array
{
    $stmt = $conn->prepare('SELECT * FROM solicitudes WHERE id = ?');
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $fila = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $fila ?: null;
}

$sol = buscar_solicitud($conn, $id);
if (!$sol) {
    http_response_code(404);
    die('La solicitud no existe');
}

if (($_GET['doc'] ?? '') === 'rbi') {
    $origen = $sol['tipo'] === 'informe_tecnico' && $sol['solicitud_origen_id']
        ? buscar_solicitud($conn, (int) $sol['solicitud_origen_id']) : null;
    if (!$origen || $origen['tipo'] !== 'recepcion') {
        http_response_code(404);
        die('Este informe no está asociado a una recepción');
    }
    $sol = $origen;
}

if (isset($_GET['vacio']) && $token === '') {
    $equipos = $conn->query(
        "SELECT e.id, e.codigo_inventario, e.descripcion, e.serie FROM solicitud_equipos se
         JOIN equipos e ON e.id = se.equipo_id WHERE se.solicitud_id = " . (int) $sol['id']
    )->fetch_all(MYSQLI_ASSOC);
    if ($sol['tipo'] === 'informe_tecnico') {
        // Genera el documento con los datos y firmas actuales.
        $archivo = generar_cit($sol, $equipos, null, null, true);
    } else {
        $esRbi   = $sol['tipo'] === 'recepcion';
        // Genera el documento con los datos y firmas actuales.
        $archivo = generar_tbi($sol, $equipos, true, $esRbi ? 'rbi.docx' : 'tbi.docx', $esRbi ? 'RBI' : 'TBI');
    }
} else {
    $archivo = nombre_documento($sol);
}

$ruta = __DIR__ . '/generados/' . basename($archivo);
if (!is_file($ruta)) {
    http_response_code(404);
    die('El documento aún no existe.');
}

$pdf = documento_a_pdf($ruta);

header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="' . basename($pdf) . '"');
header('Content-Length: ' . filesize($pdf));
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store');
readfile($pdf);
exit;
