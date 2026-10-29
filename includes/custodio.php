<?php
// Busca el último custodio de cada equipo según su última entrega finalizada.
function custodios_por_equipo(mysqli $conn, array $equipoIds): array
{
    $equipoIds = array_values(array_unique(array_map('intval', $equipoIds)));
    if (!$equipoIds) {
        return [];
    }
    $in = implode(',', array_fill(0, count($equipoIds), '?'));
    $stmt = $conn->prepare(
        "SELECT se.equipo_id, s.id AS sol_id, s.solicitante_nombre, s.solicitante_correo,
                s.jefe_recibe_nombre, s.jefe_recibe_correo, s.datos
         FROM solicitud_equipos se
         JOIN solicitudes s ON s.id = se.solicitud_id
         WHERE s.tipo = 'entrega' AND s.estado = 'finalizada' AND se.equipo_id IN ($in)
         ORDER BY s.id DESC"
    );
    $stmt->bind_param(str_repeat('i', count($equipoIds)), ...$equipoIds);
    $stmt->execute();
    $filas = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $mapa = [];
    foreach ($filas as $f) {
        $eid = (int) $f['equipo_id'];
        if (isset($mapa[$eid])) {
            continue;
        }
        $d = json_decode($f['datos'], true) ?: [];
        $run = (string) ($d['run_nuevo'] ?? '');
        $mapa[$eid] = [
            'sol_id'             => (int) $f['sol_id'],
            'clave'              => mb_strtolower(trim($f['solicitante_nombre'])) . '|' . preg_replace('/[^0-9kK]/', '', mb_strtolower($run)),
            'custodio_nuevo'     => (string) $f['solicitante_nombre'],
            'run_nuevo'          => $run,
            'unidad_nueva'       => (string) ($d['unidad_nueva'] ?? ''),
            'cod_ubic_nueva'     => (string) ($d['cod_ubic_nueva'] ?? ''),
            'ubic_nueva'         => (string) ($d['ubic_nueva'] ?? ''),
            'correo_nuevo'       => (string) $f['solicitante_correo'],
            'jefe_recibe_nombre' => (string) ($f['jefe_recibe_nombre'] ?? ''),
            'jefe_recibe_correo' => (string) ($f['jefe_recibe_correo'] ?? ''),
        ];
    }
    return $mapa;
}

// Devuelve el custodio de los equipos elegidos, o un error si no existe o son distintos.
function custodio_unico(mysqli $conn, array $equipoIds): array
{
    $mapa = custodios_por_equipo($conn, $equipoIds);
    $elegido = null;
    $clave = null;
    foreach ($equipoIds as $eid) {
        $eid = (int) $eid;
        if (!isset($mapa[$eid])) {
            return [null, 'No se encontró en el sistema quién tiene asignado uno de los equipos seleccionados.'];
        }
        if ($clave === null) {
            $clave = $mapa[$eid]['clave'];
        } elseif ($mapa[$eid]['clave'] !== $clave) {
            return [null, 'Los equipos seleccionados tienen custodios distintos. Crea una recepción por cada custodio.'];
        }
        if ($elegido === null || $mapa[$eid]['sol_id'] > $elegido['sol_id']) {
            $elegido = $mapa[$eid];
        }
    }
    if ($elegido === null) {
        return [null, 'Selecciona al menos un equipo a devolver.'];
    }
    return [$elegido, ''];
}
