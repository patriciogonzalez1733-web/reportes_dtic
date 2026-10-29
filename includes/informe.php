<?php
// Opciones de dictamen técnico y el estado en que queda el equipo.
const DICTAMENES = [
    'falla_tecnica' => ['Falla técnica o estructural', 'baja',
        'Los bienes están defectuosos debido al desgaste y deterioro producido por el uso continuo; y cuyo costo de mantenimiento, reparación o mejoramiento no es rentable en comparación al valor comercial de un equipo nuevo.'],
    'obsolescencia' => ['Obsolescencia técnica', 'baja',
        'Los bienes están en buen estado, sin embargo, ya no garantizan el eficiente cumplimiento del servicio al cual fueron destinados originalmente, producto de las tendencias modernas y avances tecnológicos, por lo tanto es recomendable reemplazarlos o descontinuar su uso, dado que resulta antieconómico mantenerlos en funcionamiento.'],
    'estado_operativo' => ['Estado operativo', 'en_dtic',
        'Los bienes pueden seguir siendo utilizados en razón de sus buenas condiciones técnicas y del estado físico en que se conservan.'],
    'servicio_tecnico' => ['Enviar a servicio técnico especializado', 'en_reparacion',
        'Por tratarse de equipamiento específico, el diagnóstico de la falla deberá ser realizada en un Servicio Técnico Especializado en la marca del fabricante.'],
    'repuestos' => ['Suministrar repuestos para reparación', 'en_reparacion',
        'Los bienes pueden ser reparados, sin embargo, el suministro de repuestos necesarios deberá ser proporcionado por la Unidad Operativa interesada.'],
    'otros' => ['Otros (ver observaciones)', null,
        'Especificar en el recuadro "Observaciones".'],
];

// Destinos posibles cuando el dictamen es "Otros".
const DESTINOS_OTROS = [
    'en_dtic'       => 'Queda disponible en DTIC',
    'en_reparacion' => 'Queda en reparación',
    'baja'          => 'Se da de baja',
];

// Dictámenes antiguos, solo para informes ya guardados.
const DICTAMENES_ANTIGUOS = [
    'reasignar'   => ['Reasignar', 'en_dtic'],
    'reparar'     => ['Reparar', 'en_reparacion'],
    'dar_de_baja' => ['Dar de baja', 'baja'],
];

// Leyenda con la descripción de cada dictamen.
function leyenda_dictamenes_html(): string
{
    $html = '<details class="leyenda-dictamen" open><summary>Qué significa cada dictamen</summary><dl>';
    foreach (DICTAMENES as [$texto, , $descripcion]) {
        $html .= '<dt>' . htmlspecialchars($texto) . '</dt><dd>' . htmlspecialchars($descripcion) . '</dd>';
    }
    return $html . '</dl></details>';
}

// Texto del dictamen de un equipo.
function dictamen_texto(?array $info, bool $conDestino = false): string
{
    $clave = $info['dictamen'] ?? '';
    $def = DICTAMENES[$clave] ?? DICTAMENES_ANTIGUOS[$clave] ?? null;
    if (!$def) {
        return '';
    }
    if ($conDestino && $clave === 'otros' && isset(DESTINOS_OTROS[$info['destino'] ?? ''])) {
        return $def[0] . ' — ' . DESTINOS_OTROS[$info['destino']];
    }
    return $def[0];
}

// Estado en que queda el equipo según su dictamen.
function dictamen_estado(?array $info): string
{
    $clave = $info['dictamen'] ?? '';
    if ($clave === 'otros') {
        return array_key_exists($info['destino'] ?? '', DESTINOS_OTROS) ? $info['destino'] : 'en_dtic';
    }
    return (DICTAMENES[$clave] ?? DICTAMENES_ANTIGUOS[$clave] ?? [null, 'en_dtic'])[1];
}

// Cambia el estado de los equipos cuando terminaron la recepción y el informe.
function aplicar_dictamen_si_corresponde(mysqli $conn, int $solicitudId): bool
{
    $stmt = $conn->prepare('SELECT id, tipo, solicitud_origen_id FROM solicitudes WHERE id = ?');
    $stmt->bind_param('i', $solicitudId);
    $stmt->execute();
    $sol = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$sol) {
        return false;
    }

    if ($sol['tipo'] === 'informe_tecnico') {
        $informeId   = (int) $sol['id'];
        $recepcionId = (int) $sol['solicitud_origen_id'];
    } elseif ($sol['tipo'] === 'recepcion') {
        $recepcionId = (int) $sol['id'];
        $stmt = $conn->prepare(
            "SELECT id FROM solicitudes WHERE solicitud_origen_id = ? AND tipo = 'informe_tecnico' AND estado <> 'anulada'
             ORDER BY id DESC LIMIT 1"
        );
        $stmt->bind_param('i', $recepcionId);
        $stmt->execute();
        $it = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        $informeId = $it ? (int) $it['id'] : 0;
    } else {
        return false;
    }
    if (!$informeId || !$recepcionId) {
        return false;
    }

    $stmt = $conn->prepare("SELECT id, estado, datos FROM solicitudes WHERE id IN (?, ?)");
    $stmt->bind_param('ii', $informeId, $recepcionId);
    $stmt->execute();
    $filas = [];
    foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $f) {
        $filas[(int) $f['id']] = $f;
    }
    $stmt->close();
    if (($filas[$informeId]['estado'] ?? '') !== 'finalizada' || ($filas[$recepcionId]['estado'] ?? '') !== 'finalizada') {
        return false;
    }

    $datosIT = json_decode($filas[$informeId]['datos'], true) ?: [];

    $stmt = $conn->prepare('SELECT equipo_id FROM solicitud_equipos WHERE solicitud_id = ?');
    $stmt->bind_param('i', $informeId);
    $stmt->execute();
    $equipoIds = array_column($stmt->get_result()->fetch_all(MYSQLI_ASSOC), 'equipo_id');
    $stmt->close();

    $stmtEquipo = $conn->prepare(
        "UPDATE equipos SET estado = ?, custodio_actual = NULL, ubicacion_actual = NULL, bloqueado_por = NULL
         WHERE id = ? AND bloqueado_por = ?"
    );
    foreach ($equipoIds as $equipoId) {
        $estadoNuevo = dictamen_estado($datosIT['equipos_dictamen'][$equipoId] ?? null);
        $equipoId = (int) $equipoId;
        $stmtEquipo->bind_param('sii', $estadoNuevo, $equipoId, $recepcionId);
        $stmtEquipo->execute();
    }
    $stmtEquipo->close();
    return true;
}
