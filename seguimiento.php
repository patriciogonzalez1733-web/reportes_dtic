<?php
// Historial de solicitudes (con filtros) y detalle del proceso de cada una.
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/documento.php';
require_once __DIR__ . '/vendor/autoload.php';

// Hay que haber iniciado sesión.
$u = require_login();
$id = (int) ($_GET['id'] ?? 0);

if($id === 0){
    $tipos = ['entrega' => 'TBI', 'recepcion'=> 'RBI', 
    'informe_tecnico' => 'CIT'];
    $tipo =(string) ($_GET['tipo'] ?? '');
    if (!isset($tipos[$tipo])){
        $tipo= '';
    }
    $anio = (int) ($_GET['anio'] ?? 0);
    $mes = (int)($_GET['mes'] ?? 0);
    $estadoF = (string) ($_GET['estado'] ?? '');
    if (!in_array($estadoF,['en_curso', 'finalizada', 
    'anulada'], true)){
        $estadoF = '';
    }
    $buscar = mb_substr(trim((string) ($_GET['q'] ?? '')),
    0, 50);

    $where = [];
    $tiposBind = '';
    $params = [];

    if ($tipo !== ''){
        $where[] = 's.tipo = ?';
        $tiposBind .= 's';
        $params[] = $tipo;
    }
    if ($anio > 0){
        $where[]= 's.anio = ?';
        $tiposBind .= 'i';
        $params[] = $anio;
    }
    if($mes >= 1 && $mes <= 12) {
        $where[]= 'MONTH(s.creado_en) = ?';
        $tiposBind .= 'i';
        $params[]= $mes;
    }
    if($estadoF !== ''){
        $where[] = 's.estado = ?';
        $tiposBind .= 's';
        $params[] = $estadoF;
    }
    if($buscar !== ''){
        $like = '%' . str_replace(['\\', '%', '_'],
        ['\\\\', '\\%', '\\_'], $buscar) . '%';
        $where[]= '(s.folio LIKE ? OR s.solicitante_nombre
        LIKE ?)';
        $tiposBind .= 'ss';
        $params[] = $like;
        $params[]= $like;
    }
    $whereSql = $where ? 'WHERE ' . implode(' AND ',  $where) : '';

    $stmt = $conn->prepare("SELECT COUNT(*) FROM 
    solicitudes s $whereSql");

    if($params){
        $stmt->bind_param($tiposBind, ...$params);
    }
    $stmt->execute();
    $total = (int) $stmt->get_result()->fetch_row()[0];
    $stmt->close();

    $porPagina = 20;

    $paginas = max(1, (int) ceil($total / $porPagina));
    $pagina = min(max(1, (int) ($_GET['p'] ?? 1)),
    $paginas);

    $offset = ($pagina - 1) * $porPagina;
    $stmt = $conn->prepare(
        "SELECT s.id, s.tipo, s.folio, s.fase_actual, 
        s.estado, s.solicitante_nombre, s.creado_en, us.nombre AS 
        creador_nombre
        FROM solicitudes s JOIN usuarios us ON us.id = 
        s.creado_por
        $whereSql ORDER BY s.creado_en DESC LIMIT ? OFFSET
        ?"
    );

        $stmt->bind_param($tiposBind . 'ii', ...[...$params,
        $porPagina, $offset]);
        $stmt-> execute();
        $lista = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        $anios = array_column($conn->query('SELECT DISTINCT 
        anio FROM solicitudes ORDER BY anio DESC')->fetch_all(MYSQLI_ASSOC), 'anio');
    
    $mesSel = ($mes >= 1 && $mes <= 12) ? $mes : 0;
    $filtros = ['tipo' => $tipo, 'anio' => $anio ?: '', 'mes' => $mesSel ?: '', 'estado' => $estadoF, 'q' => $buscar];
    $enlace = function (array $cambios) use ($filtros): string {
        $q = array_filter(array_merge($filtros, $cambios), fn($v) => $v !== '' && $v !== null);
        return 'seguimiento.php' . ($q ? '?' . http_build_query($q) : '');
    };
    $meses = [1 => 'Enero', 2 => 'Febrero', 3 => 'Marzo', 4 => 'Abril', 5 => 'Mayo', 6 => 'Junio',
              7 => 'Julio', 8 => 'Agosto', 9 => 'Septiembre', 10 => 'Octubre', 11 => 'Noviembre', 12 => 'Diciembre'];
    $estados = ['en_curso' => 'En curso', 'finalizada' => 'Finalizada', 'anulada' => 'Anulada'];

    // Dibuja la cabecera de la página.
    render_header('Seguimiento de solicitudes', $u);
    ?>
    <h1>Seguimiento de solicitudes</h1>

    <nav class="pestanas">
        <a href="<?= h($enlace(['tipo' => '', 'p' => ''])) ?>" class="<?= $tipo === '' ? 'activa' : '' ?>">Todos</a>
        <?php foreach ($tipos as $clave => $nombreTipo): ?>
            <a href="<?= h($enlace(['tipo' => $clave, 'p' => ''])) ?>" class="<?= $tipo === $clave ? 'activa' : '' ?>"><?= h($nombreTipo) ?></a>
        <?php endforeach; ?>
    </nav>

    <form method="get" class="filtros">
        <?php if ($tipo !== ''): ?><input type="hidden" name="tipo" value="<?= h($tipo) ?>"><?php endif; ?>
        <label>Año
            <select name="anio">
                <option value="">Todos</option>
                <?php foreach ($anios as $a): ?>
                    <option value="<?= (int) $a ?>" <?= $anio === (int) $a ? 'selected' : '' ?>><?= (int) $a ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>Mes
            <select name="mes">
                <option value="">Todos</option>
                <?php foreach ($meses as $n => $nombreMes): ?>
                    <option value="<?= $n ?>" <?= $mesSel === $n ? 'selected' : '' ?>><?= h($nombreMes) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>Estado
            <select name="estado">
                <option value="">Todos</option>
                <?php foreach ($estados as $clave => $etiqueta): ?>
                    <option value="<?= h($clave) ?>" <?= $estadoF === $clave ? 'selected' : '' ?>><?= h($etiqueta) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>Buscar
            <input type="text" name="q" value="<?= h($buscar) ?>" maxlength="50" placeholder="Folio o nombre">
        </label>
        <button type="submit">Filtrar</button>
        <a href="<?= h($enlace(['anio' => '', 'mes' => '', 'estado' => '', 'q' => '', 'p' => ''])) ?>" class="chico">Limpiar</a>
    </form>

    <p><?= (int) $total ?> solicitud(es)</p>

    <?php if (!$lista): ?>
        <p class="aviso">No hay solicitudes con esos filtros.</p>
    <?php else: ?>
    <table class="tabla">
        <thead><tr><th>Tipo</th><th>Folio</th><th>Estado</th><th>Custodio que recibe</th><th>Creada por</th><th>Creada</th><th>Documentos y proceso</th></tr></thead>
        <tbody>
            <?php foreach ($lista as $r): ?>
                <tr>
                    <td><?= h($tipos[$r['tipo']] ?? $r['tipo']) ?></td>
                    <td><?= h($r['folio']) ?></td>
                    <td>
                        <?php if ($r['estado'] === 'en_curso'): ?>
                            <span class="pill estado-en_curso">En curso · Fase <?= (int) $r['fase_actual'] ?></span>
                        <?php elseif ($r['estado'] === 'finalizada'): ?>
                            <span class="pill estado-finalizada">Finalizada</span>
                        <?php else: ?>
                            <span class="pill estado-anulada">Anulada</span>
                        <?php endif; ?>
                    </td>
                    <td><?= h($r['solicitante_nombre']) ?></td>
                    <td><?= h($r['creador_nombre']) ?></td>
                    <td><?= h(date('d-m-Y H:i', strtotime($r['creado_en']))) ?></td>
                    <td>
                        <div class="acciones">
                        <a href="ver_documento.php?id=<?= (int) $r['id'] ?>&vacio=1" target="_blank" class="chico">Documento vacío</a>
                        <?php if (is_file(__DIR__ . '/generados/' . nombre_documento($r))): ?>
                            <a href="ver_documento.php?id=<?= (int) $r['id'] ?>" target="_blank" class="chico">Documento firmado</a>
                        <?php endif; ?>
                        <a href="seguimiento.php?id=<?= (int) $r['id'] ?>" class="chico">Ver proceso</a>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <?php if ($paginas > 1): ?>
        <nav class="paginacion">
            <?php if ($pagina > 1): ?><a href="<?= h($enlace(['p' => $pagina - 1])) ?>" class="chico">← Anterior</a><?php endif; ?>
            <span>Página <?= $pagina ?> de <?= $paginas ?></span>
            <?php if ($pagina < $paginas): ?><a href="<?= h($enlace(['p' => $pagina + 1])) ?>" class="chico">Siguiente →</a><?php endif; ?>
        </nav>
    <?php endif; ?>
    <?php endif; ?>
    <p><a href="index.php">← Volver al inicio</a></p>
    <?php
    render_footer();
    exit;
}

$stmt = $conn->prepare(
    "SELECT s.*, TIMESTAMPDIFF(SECOND, s.fase_desde, NOW()) AS seg_en_fase, us.nombre AS creador_nombre FROM solicitudes s
     JOIN usuarios us ON us.id = s.creado_por WHERE s.id = ?"
);
$stmt->bind_param('i', $id);
$stmt->execute();
$s = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$s) {
    // Dibuja la cabecera de la página.
    render_header('Seguimiento', $u);
    echo '<p class="error">La solicitud no existe.</p><p><a href="index.php">← Volver</a></p>';
    render_footer();
    exit;
}

$stmt = $conn->prepare(
    "SELECT h.*, uh.nombre AS usuario_nombre FROM historial h
     LEFT JOIN usuarios uh ON uh.id = h.usuario_id
     WHERE h.solicitud_id = ? ORDER BY h.id ASC"
);
$stmt->bind_param('i', $id);
$stmt->execute();
$historial = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$fases = $s['tipo'] === 'recepcion' ? [
    1 => 'Creación / corrección',
    2 => 'Jefe de sector que recibe (DTIC)',
    3 => 'Jefe de sector que entrega (devuelve)',
    4 => 'Usuario final',
    5 => 'Cierre',
] : [
    1 => 'Creación / corrección',
    2 => 'Jefe de sector que entrega',
    3 => 'Jefe de sector que recibe',
    4 => 'Usuario final',
    5 => 'Cierre',
];

$esIT = $s['tipo'] === 'informe_tecnico';
$totalFases = $esIT ? 3 : 5;
if ($esIT) {
    $fases = [
        1 => 'Creación del informe',
        2 => 'V°B° del jefe de unidad técnica',
        3 => 'Cierre',
    ];
}
$datosS = json_decode($s['datos'], true) ?: [];
$jefeTecnico = (string) ($datosS['jefe_tecnico_nombre'] ?? '');
$acciones = [
    'crear' => 'Creó la solicitud', 'aprobar' => 'Aprobó', 'rechazar' => 'Rechazó',
    'confirmar' => 'Confirmó la recepción', 'rechazar_usuario' => 'Rechazó (usuario final)',
    'corregir' => 'Corrigió y reenvió', 'finalizar' => 'Finalizó', 'anular' => 'Anuló',
    'reenviar' => 'Reenvió el link',
];
if ($esIT) {
    $acciones['confirmar'] = 'Dio el V°B°';
    $acciones['finalizar'] = 'Finalizó (dictamen aplicado)';
}

$estado = $s['estado'];
$faseActual = (int) $s['fase_actual'];

// Indica si una fase está hecha, actual o pendiente.
function estado_fase(int $n, int $faseActual, string $estado): string
{
    if ($estado === 'finalizada') return 'hecha';
    if ($estado === 'anulada') return $n < $faseActual ? 'hecha' : 'anulada';
    if ($n < $faseActual) return 'hecha';
    return $n === $faseActual ? 'actual' : 'pendiente';
}

// Convierte segundos a un texto legible (días, horas y minutos).
function duracion(int $seg): string
{
    if ($seg < 1) return '0 seg.';
    $d = intdiv($seg, 86400); $hr = intdiv($seg % 86400, 3600);
    $m = intdiv($seg % 3600, 60); $sg = $seg % 60;
    $p = [];
    if ($d)  $p[] = $d . ($d == 1 ? ' día' : ' días');
    if ($hr) $p[] = $hr . ' hrs.';
    if ($m)  $p[] = $m . ' min.';
    if ($sg && !$d) $p[] = $sg . ' seg.';
    return implode(', ', $p);
}
// Da formato a una fecha y hora.
function fmt(?string $f): string { return $f ? date('d/m/Y H:i:s', strtotime($f)) : ''; }

$responsableActual = $esIT ? match ($faseActual) {
    1 => $s['creador_nombre'],
    2 => $jefeTecnico !== '' ? $jefeTecnico : 'Jefe de unidad técnica',
    default => '',
} : match ($faseActual) {
    1 => $s['creador_nombre'],
    2 => 'Encargado(s) de sector (DTIC)',
    3 => $s['jefe_recibe_nombre'],
    4 => $s['solicitante_nombre'],
    default => '',
};

$quienEvento = function (array $e) use ($esIT, $jefeTecnico, $s): string {
    if (!empty($e['usuario_nombre'])) return $e['usuario_nombre'];
    $actor = trim((string) ($e['actor_externo_nombre'] ?? ''));
    if ($actor !== '' && $actor !== '0') return $actor;

    if ($e['accion'] === 'aprobar' && (int) $e['fase_origen'] === 1) return (string) $s['creador_nombre'];
    if ($esIT && $jefeTecnico !== '' && in_array($e['accion'], ['confirmar', 'rechazar', 'finalizar'], true)) return $jefeTecnico;
    return '—';
};

$accionEvento = function (array $e) use ($acciones): string {
    $sinPersona = empty($e['usuario_nombre']) && trim((string) ($e['actor_externo_nombre'] ?? '')) === '';
    if ($e['accion'] === 'aprobar' && (int) $e['fase_origen'] === 1 && $sinPersona) return 'Avanzó a Fase 2';
    return $acciones[$e['accion']] ?? $e['accion'];
};

$filas = [];
$inicio = $s['creado_en'];
foreach ($historial as $h) {
    $rechazo = in_array($h['accion'], ['rechazar', 'rechazar_usuario', 'anular'], true);
    $etapa = $h['accion'] === 'crear' ? 'Inicio · Creación de la solicitud' : ('Fase ' . (int) $h['fase_origen'] . ' · ' . ($fases[(int) $h['fase_origen']] ?? '—'));
    $filas[] = [
        'etapa' => $etapa,
        'quien' => $quienEvento($h),
        'accion' => $accionEvento($h),
        'inicio' => $inicio,
        'termino' => $h['creado_en'],
        'dur' => $h['accion'] === 'crear' ? '0 seg.' : ($h['segundos_en_fase'] !== null ? duracion((int) $h['segundos_en_fase']) : duracion(strtotime($h['creado_en']) - strtotime($inicio))),
        'comentario' => $h['comentario'],
        'clase' => $rechazo ? 'rojo' : 'verde',
    ];
    $inicio = $h['creado_en'];
}
if ($estado === 'en_curso') {
    $filas[] = [
        'etapa' => 'Fase ' . $faseActual . ' · ' . $fases[$faseActual], 'quien' => $responsableActual, 'accion' => 'En espera de respuesta',
        'inicio' => $s['fase_desde'], 'termino' => null,
        'dur' => duracion((int) $s['seg_en_fase']), 'comentario' => null, 'clase' => 'azul',
    ];
}

if ($estado === 'en_curso') {
    $responsables = $esIT
        ? [1 => $s['creador_nombre'], 2 => $jefeTecnico !== '' ? $jefeTecnico : 'Jefe de unidad técnica', 3 => 'Sistema (dictamen y copias)']
        : [1 => $s['creador_nombre'], 2 => 'Encargado(s) de sector (DTIC)', 3 => $s['jefe_recibe_nombre'],
           4 => $s['solicitante_nombre'], 5 => 'Sistema (envío de copias)'];
    for ($f = $faseActual + 1; $f <= $totalFases; $f++) {
        $filas[] = [
            'etapa' => 'Fase ' . $f . ' · ' . $fases[$f], 'quien' => $responsables[$f], 'accion' => 'Pendiente',
            'inicio' => null, 'termino' => null, 'dur' => '', 'comentario' => null, 'clase' => 'gris',
        ];
    }
}

$responsablesFase = $esIT
    ? [1 => $s['creador_nombre'], 2 => $jefeTecnico !== '' ? $jefeTecnico : 'Jefe de unidad técnica', 3 => 'Sistema (dictamen y copias)']
    : [1 => $s['creador_nombre'], 2 => 'Encargado(s) de sector (DTIC)', 3 => $s['jefe_recibe_nombre'],
       4 => $s['solicitante_nombre'], 5 => 'Sistema (envío de copias)'];
$rechazos = ['rechazar', 'rechazar_usuario', 'anular'];

$ultimoPorFase = [];
$inicioEv = $s['creado_en'];
foreach ($historial as $h) {
    $fEv = $h['accion'] === 'crear' ? 1 : (int) $h['fase_origen'];
    $ultimoPorFase[$fEv] = $h + ['inicio_ev' => $inicioEv];
    $inicioEv = $h['creado_en'];
}
$ultimoEvento = $historial ? end($historial) : null;

$filasFases = [];
for ($f = 1; $f <= $totalFases; $f++) {
    $ev = $ultimoPorFase[$f] ?? null;
    $cls = estado_fase($f, $faseActual, $estado);
    $fila = [
        'n' => $f, 'etapa' => $fases[$f], 'quien' => $responsablesFase[$f], 'accion' => 'Pendiente',
        'inicio' => null, 'termino' => null, 'dur' => '', 'comentario' => null, 'clase' => 'gris',
    ];

    if ($cls === 'hecha') {
        $fila['clase'] = 'verde';
        $fila['accion'] = 'Completada';
        if ($ev) {
            $fila['quien'] = $quienEvento($ev);
            $fila['accion'] = $accionEvento($ev);
            $fila['comentario'] = $ev['comentario'];
            $fila['inicio'] = $ev['inicio_ev'];
            $fila['termino'] = $ev['creado_en'];
            $fila['dur'] = $ev['accion'] === 'crear' ? '0 seg.' : duracion((int) ($ev['segundos_en_fase'] ?? 0));
        }
        if ($esIT && $f === 3) {
            $fila['quien'] = 'Sistema (dictamen y copias)';
        }
    } elseif ($cls === 'actual') {
        $fila['clase'] = 'azul';
        $fila['accion'] = $f === 1 ? 'En corrección' : 'En espera de respuesta';
        $fila['inicio'] = $s['fase_desde'];
        $fila['dur'] = duracion((int) $s['seg_en_fase']);

        if ($ultimoEvento && in_array($ultimoEvento['accion'], $rechazos, true) && (int) $ultimoEvento['fase_destino'] === $f) {
            $fila['comentario'] = 'Devuelta desde Fase ' . (int) $ultimoEvento['fase_origen'] . ' por ' . $quienEvento($ultimoEvento)
                                . ($ultimoEvento['comentario'] ? ': ' . $ultimoEvento['comentario'] : '');
        }
    } elseif ($cls === 'anulada') {
        $fila['accion'] = 'Anulada';
    }
    $filasFases[] = $fila;
}

// Dibuja la tabla de etapas del proceso.
function tabla_etapas(array $filas, bool $porFase): void
{
    ?>
<table class="tabla seg-tabla">
    <thead>
        <tr><th><?= $porFase ? 'Fase' : 'N°' ?></th><th>Etapa</th><th>Encargado(a)</th><th>Acción</th><th>Inicio</th><th>Término</th><th>Duración</th></tr>
    </thead>
    <tbody>
        <?php foreach ($filas as $i => $f): ?>
            <tr class="<?= $f['clase'] === 'gris' ? 'pend' : '' ?>">
                <td class="n <?= h($f['clase']) ?>"><?= $porFase ? (int) $f['n'] : $i ?></td>
                <td><?= h($f['etapa']) ?></td>
                <td><?= h($f['quien']) ?></td>
                <td><?= h($f['accion']) ?>
                    <?php if ($f['comentario']): ?><div class="coment">“<?= h($f['comentario']) ?>”</div><?php endif; ?>
                </td>
                <td><?= h(fmt($f['inicio'])) ?></td>
                <td><?= h(fmt($f['termino'])) ?></td>
                <td><?= h($f['dur']) ?></td>
            </tr>
        <?php endforeach; ?>
    </tbody>
</table>
    <?php
}

$estadoTexto = ['en_curso' => 'En curso · Fase ' . $faseActual, 'finalizada' => 'Finalizada', 'anulada' => 'Anulada'][$estado] ?? $estado;

// Dibuja la cabecera de la página.
render_header('Seguimiento ' . $s['folio'], $u);
?>
<style>
    .seg-flujo { background:#fff; border:1px solid #dde3e8; border-radius:8px; padding:12px; overflow-x:auto; }
    .seg-flujo svg { width:100%; height:auto; display:block; }
    .nodo rect { stroke:#5a6b78; stroke-width:1.2; }
    .nodo.hecha rect { fill:#a3d39c; stroke:#3d8b37; }
    .nodo.actual rect { fill:#dbeafe; stroke:#1a5fb4; stroke-width:2.5; }
    .nodo.pendiente rect { fill:#fff; }
    .nodo.anulada rect { fill:#eee; stroke:#999; }
    .nodo text { font:13px sans-serif; fill:#1d2b36; text-anchor:middle; }
    .nodo .num { font-weight:700; font-size:13px; }
    .rechazo-flecha { fill:none; stroke:#c0392b; stroke-width:1.4; stroke-dasharray:5 4; }
    .rechazo-txt { font:12px sans-serif; fill:#c0392b; text-anchor:middle; }
    .avance-flecha { stroke:#333; stroke-width:1.5; fill:none; }
    .seg-leyenda { font-size:12px; color:#555; margin:8px 0 18px; }
    .seg-leyenda span { display:inline-block; width:12px; height:12px; border-radius:2px; margin:0 4px 0 12px; vertical-align:-2px; border:1px solid #888; }
    .seg-tabla th { background:#2e75b6; color:#fff; }
    .seg-tabla td.n { text-align:center; font-weight:600; }
    .seg-tabla td.n.verde { background:#3fa535; color:#fff; }
    .seg-tabla td.n.rojo { background:#c0392b; color:#fff; }
    .seg-tabla td.n.azul { background:#dbeafe; color:#1a5fb4; }
    .seg-tabla td.n.gris { background:#e5e8eb; color:#777; }
    .seg-tabla tr.pend td { color:#888; }
    .seg-tabla .coment { color:#666; font-size:12px; }
</style>

<h1>Seguimiento · <?= h($s['folio']) ?></h1>
<p><strong>Estado:</strong> <span class="pill estado-<?= h($estado) ?>"><?= h($estadoTexto) ?></span>
   · <strong>Creada por:</strong> <?= h($s['creador_nombre']) ?>
   · <?php if ($esIT): ?><strong>Jefe de unidad técnica:</strong> <?= h($jefeTecnico !== '' ? $jefeTecnico : '—') ?>
     <?php else: ?><strong>Custodio que recibe:</strong> <?= h($s['solicitante_nombre']) ?><?php endif; ?></p>

<div class="seg-flujo">
<svg viewBox="0 0 <?= $esIT ? 550 : 900 ?> <?= $esIT ? 150 : 250 ?>" xmlns="http://www.w3.org/2000/svg" role="img" aria-label="Diagrama del flujo de la solicitud">
    <defs>
        <marker id="pf" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="7" markerHeight="7" orient="auto"><path d="M0,0 L10,5 L0,10 z" fill="#333"/></marker>
        <marker id="pr" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="7" markerHeight="7" orient="auto"><path d="M0,0 L10,5 L0,10 z" fill="#c0392b"/></marker>
    </defs>
    <?php
    $x0 = 20; $ancho = 140; $sep = 45; $y = 80; $alto = 70;
    for ($i = 1; $i <= $totalFases; $i++) {
        $x = $x0 + ($i - 1) * ($ancho + $sep);
        $cls = estado_fase($i, $faseActual, $estado);
        $partes = explode(' ', $fases[$i]);
        $mitad = (int) ceil(count($partes) / 2);
        $l1 = implode(' ', array_slice($partes, 0, $mitad));
        $l2 = implode(' ', array_slice($partes, $mitad));
        if ($i < $totalFases) {
            $xa = $x + $ancho;
            echo "<line class=\"avance-flecha\" x1=\"$xa\" y1=\"" . ($y + $alto / 2) . "\" x2=\"" . ($xa + $sep - 1) . "\" y2=\"" . ($y + $alto / 2) . "\" marker-end=\"url(#pf)\"/>";
        }
        echo "<g class=\"nodo $cls\"><rect x=\"$x\" y=\"$y\" width=\"$ancho\" height=\"$alto\" rx=\"6\"/>";
        echo "<text class=\"num\" x=\"" . ($x + $ancho / 2) . "\" y=\"" . ($y + 20) . "\">Fase $i</text>";
        echo "<text x=\"" . ($x + $ancho / 2) . "\" y=\"" . ($y + 40) . "\">" . h($l1) . "</text>";
        echo "<text x=\"" . ($x + $ancho / 2) . "\" y=\"" . ($y + 55) . "\">" . h($l2) . "</text></g>";
    }

    $cx = fn(int $i) => $x0 + ($i - 1) * ($ancho + $sep) + $ancho / 2;
    $yb = $y + $alto;
    echo "<path class=\"rechazo-flecha\" d=\"M{$cx(2)},$y C{$cx(2)},30 {$cx(1)},30 {$cx(1)},$y\" marker-end=\"url(#pr)\"/>";
    echo "<text class=\"rechazo-txt\" x=\"" . (($cx(1) + $cx(2)) / 2) . "\" y=\"38\">rechazo</text>";
    if (!$esIT) {
        echo "<path class=\"rechazo-flecha\" d=\"M{$cx(3)},$yb C{$cx(3)},195 {$cx(2)},195 {$cx(2)},$yb\" marker-end=\"url(#pr)\"/>";
        echo "<text class=\"rechazo-txt\" x=\"" . (($cx(2) + $cx(3)) / 2) . "\" y=\"210\">rechazo</text>";
        echo "<path class=\"rechazo-flecha\" d=\"M{$cx(4)},$yb C{$cx(4)},215 {$cx(2)},215 {$cx(2)},$yb\" marker-end=\"url(#pr)\"/>";
        echo "<text class=\"rechazo-txt\" x=\"" . (($cx(2) + $cx(4)) / 2) . "\" y=\"230\">rechazo del usuario final</text>";
    }
    ?>
</svg>
</div>
<p class="seg-leyenda">
    <span style="background:#a3d39c"></span>Completada
    <span style="background:#dbeafe;border-color:#1a5fb4"></span>En curso
    <span style="background:#fff"></span>Pendiente
    <span style="background:#fff;border:1px dashed #c0392b"></span>Ruta de rechazo
</p>

<?php
if ($s['tipo'] === 'recepcion') {
    $stmtIT = $conn->prepare(
        "SELECT id, folio, estado FROM solicitudes WHERE solicitud_origen_id = ? AND tipo = 'informe_tecnico' AND estado <> 'anulada' ORDER BY id DESC LIMIT 1"
    );
    $stmtIT->bind_param('i', $s['id']);
    $stmtIT->execute();
    $informeLigado = $stmtIT->get_result()->fetch_assoc();
    $stmtIT->close();
    if ($informeLigado): ?>
        <p class="aviso">Informe técnico ligado:
            <a href="seguimiento.php?id=<?= (int) $informeLigado['id'] ?>"><?= h($informeLigado['folio']) ?></a> —
            <?= $informeLigado['estado'] === 'finalizada' ? 'finalizado' : '<strong>pendiente</strong> (no detiene la recepción)' ?>
        </p>
    <?php endif;
} ?>

<h2>Detalle por fase</h2>
<?php tabla_etapas($filasFases, true); ?>

<details style="margin-top:14px;">
    <summary style="cursor:pointer;font-weight:600;">Ver historial completo (cronológico, incluye rechazos y correcciones)</summary>
    <div style="margin-top:10px;">
        <?php tabla_etapas(array_values(array_filter($filas, fn($r) => !in_array($r['clase'], ['gris', 'azul'], true))), false); ?>
    </div>
</details>

<p>
       <?php if (is_file(__DIR__ . '/generados/' . nombre_documento($s))): ?>
        <a href="ver_documento.php?id=<?= (int) $s['id'] ?>" target="_blank" class="chico">Ver documento</a> ·
    <?php endif; ?>
    <a href="seguimiento.php">← Volver al listado de seguimiento</a>
</p>

<?php render_footer(); ?>
