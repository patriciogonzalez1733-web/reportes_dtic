<?php
use PhpOffice\PhpWord\TemplateProcessor;

require_once __DIR__ . '/informe.php';

// Genera el documento Word (TBI o RBI) desde la plantilla.
function generar_tbi(array $solicitud, array $equipos, bool $sinFirmas = false, string $plantilla = 'tbi.docx', string $prefijo = 'TBI'): string
{
    $folio = $solicitud['folio'];
    $datos = json_decode($solicitud['datos'], true);

    $tp = new TemplateProcessor(__DIR__ . '/../plantillas/' . $plantilla);

    $tp->setValue('fecha', date('d-m-Y', strtotime($solicitud['creado_en'])));
    $tp->setValue('folio', $folio);

    $tp->setValue('x_definitivo', $datos['tipo_traspaso'] === 'definitivo' ? 'X' : ' ');
    $tp->setValue('x_temporal',   $datos['tipo_traspaso'] === 'temporal'   ? 'X' : ' ');
    $tp->setValue('plazo', $datos['tipo_traspaso'] === 'temporal' ? $datos['plazo'] : '');

    $tp->setValue('x_solo_custodio',      $datos['reasignacion'] === 'solo_custodio'      ? 'X' : ' ');
    $tp->setValue('x_solo_ubicacion',     $datos['reasignacion'] === 'solo_ubicacion'     ? 'X' : ' ');
    $tp->setValue('x_custodio_ubicacion', $datos['reasignacion'] === 'custodio_ubicacion' ? 'X' : ' ');

    $tp->setValue('custodio_actual', $datos['custodio_actual']);
    $tp->setValue('run_actual', $datos['run_actual']);
    $tp->setValue('unidad_actual', $datos['unidad_actual']);
    $tp->setValue('cod_ubic_actual', $datos['cod_ubic_actual']);
    $tp->setValue('ubic_actual', $datos['ubic_actual']);
    $tp->setValue('jefe_actual', $datos['jefe_actual']);

    $tp->setValue('custodio_nuevo', $solicitud['solicitante_nombre']);
    $tp->setValue('run_nuevo', $datos['run_nuevo']);
    $tp->setValue('unidad_nueva', $datos['unidad_nueva']);
    $tp->setValue('cod_ubic_nueva', $datos['cod_ubic_nueva']);
    $tp->setValue('ubic_nueva', $datos['ubic_nueva']);
    $tp->setValue('jefe_nuevo', $solicitud['jefe_recibe_nombre']);

    $tp->setValue('observaciones', $datos['observaciones'] ?: '—');

    $firmas = [
        'firma_entrega_custodio' => __DIR__ . "/../firmas/{$folio}_actual.png",
        'firma_jefe_entrega'     => __DIR__ . "/../firmas/{$folio}_jefe_entrega.png",
        'firma_jefe_recibe'      => __DIR__ . "/../firmas/{$folio}_jefe_recibe.png",
        'firma_recibe_custodio'  => __DIR__ . "/../firmas/{$folio}_nuevo.png",
    ];
    foreach ($firmas as $marcador => $ruta) {
        if (!$sinFirmas && is_file($ruta)) {
            $tp->setImageValue($marcador, ['path' => $ruta, 'width' => 180, 'height' => 68, 'ratio' => false]);
        } else {
            $tp->setValue($marcador, '');
        }
    }

    $tp->cloneRow('n', count($equipos));
    foreach ($equipos as $i => $e) {
        $fila = $i + 1;
        $tp->setValue("n#$fila", $fila);
        $tp->setValue("codigo#$fila", $e['codigo_inventario'] ?: '—');
        $tp->setValue("descripcion#$fila", $e['descripcion']);
        $tp->setValue("serie#$fila", $e['serie'] ?: '—');
    }

    $nombreArchivo = "{$prefijo}_{$folio}" . ($sinFirmas ? '_sin_firmas' : '') . ".docx";
    $tp->saveAs(__DIR__ . '/../generados/' . $nombreArchivo);
    return $nombreArchivo;
}

// Genera el informe técnico (Excel) desde la plantilla.
function generar_cit(array $solicitud, array $equipos, ?string $firmaTecnico, ?string $firmaJefeTecnico, bool $sinFirmas = false): string
{
    if (count($equipos) > 9) {
        throw new RuntimeException('El informe técnico admite como máximo 9 equipos.');
    }
    $folio = $solicitud['folio'];
    $datos = json_decode($solicitud['datos'], true);

    $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load(__DIR__ . '/../plantillas/cit.xlsx');
    $hoja = $spreadsheet->getSheetByName('CIT');

    $hoja->setCellValue('A3', $datos['ubicacion_fisica'] ?? '');
    $hoja->setCellValue('E3', $datos['nombre_custodio'] ?? '');

    $hoja->setCellValue('A5', $datos['observaciones'] ?? '');
    $hoja->setCellValue('E5', $datos['tecnico_nombre'] ?? '');
    $hoja->setCellValue('F5', $datos['jefe_tecnico_nombre'] ?? '');
    $hoja->setCellValue('E9', date('d-m-Y', strtotime($solicitud['creado_en'])));
    $hoja->setCellValue('F9', $folio);

    $hoja->mergeCells('A22:F22');

    $equiposDictamen = $datos['equipos_dictamen'] ?? [];
    foreach (array_slice($equipos, 0, 9) as $i => $e) {
        $fila = 12 + $i;
        $info = $equiposDictamen[$e['id']] ?? ['diagnostico' => '', 'dictamen' => ''];
        $hoja->setCellValue("A$fila", $i + 1);
        $hoja->setCellValue("B$fila", $e['codigo_inventario'] ?: '—');
        $hoja->setCellValue("C$fila", $e['descripcion']);
        $hoja->setCellValue("D$fila", $e['serie'] ?: '—');
        $hoja->setCellValue("E$fila", $info['diagnostico'] ?? '');
        $hoja->setCellValue("F$fila", dictamen_texto($info));
    }

    $insertarFirma = function (?string $ruta, string $celda) use ($hoja): void {
        if ($ruta === null || !is_file($ruta)) {
            return;
        }
        $dibujo = new \PhpOffice\PhpSpreadsheet\Worksheet\Drawing();
        $dibujo->setName('Firma');
        $dibujo->setPath($ruta);
        $dibujo->setCoordinates($celda);
        $dibujo->setResizeProportional(false);
        $dibujo->setWidthAndHeight(140, 40);
        $dibujo->setOffsetX(4);
        $dibujo->setOffsetY(4);
        $dibujo->setWorksheet($hoja);
    };
    if (!$sinFirmas) {
        $insertarFirma($firmaTecnico, 'E6');
        $insertarFirma($firmaJefeTecnico, 'F6');
    }

    $nombreArchivo = "CIT_{$folio}" . ($sinFirmas ? '_sin_firmas' : '') . ".xlsx";
    $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
    $writer->save(__DIR__ . '/../generados/' . $nombreArchivo);
    return $nombreArchivo;
}

// Nombre del archivo del documento de una solicitud.
function nombre_documento(array $solicitud): string
{
    $folio = $solicitud['folio'];
    return match ($solicitud['tipo']) {
        'recepcion'       => "RBI_{$folio}.docx",
        'informe_tecnico' => "CIT_{$folio}.xlsx",
        default           => "TBI_{$folio}.docx",
    };
}

// Convierte el documento a PDF (reutiliza el PDF si está al día).
function documento_a_pdf(string $rutaOrigen): string
{
    $rutaPdf = preg_replace('/\.(docx|xlsx)$/i', '.pdf', $rutaOrigen, -1, $esDocumento);
    if (!$esDocumento) {
        throw new RuntimeException('Solo los docx y xlsx pueden convertirse a PDF.');
    }
    if (!is_file($rutaOrigen)) {
        throw new RuntimeException('El archivo no existe.');
    }
    if (is_file($rutaPdf) && filemtime($rutaPdf) >= filemtime($rutaOrigen)) {
        return $rutaPdf;
    }

    $tmp = sys_get_temp_dir() . '/mpdf_dtic';
    if (!is_dir($tmp)) {
        mkdir($tmp, 0775, true);
    }
    $rutaTmp = $rutaPdf . '.tmp';

    $titulo = str_replace('_', ' ', pathinfo($rutaOrigen, PATHINFO_FILENAME));

    if (str_ends_with(strtolower($rutaOrigen), '.xlsx')) {
        \PhpOffice\PhpSpreadsheet\IOFactory::registerWriter('Pdf', \PhpOffice\PhpSpreadsheet\Writer\Pdf\Mpdf::class);
        $hoja = \PhpOffice\PhpSpreadsheet\IOFactory::load($rutaOrigen);
        $hoja->getProperties()->setTitle($titulo);
        \PhpOffice\PhpSpreadsheet\IOFactory::createWriter($hoja, 'Pdf')->save($rutaTmp);
    } else {
        \PhpOffice\PhpWord\Settings::setPdfRendererName(\PhpOffice\PhpWord\Settings::PDF_RENDERER_MPDF);
        \PhpOffice\PhpWord\Settings::setPdfRendererPath(__DIR__ . '/../vendor/mpdf/mpdf');
        $phpWord = \PhpOffice\PhpWord\IOFactory::load($rutaOrigen);
        $html = \PhpOffice\PhpWord\IOFactory::createWriter($phpWord, 'PDF')->getContent();

        $html = preg_replace('~(<img[^>]*style=")width:\s*271px;\s*height:\s*271px;~', '$1width: 118px; height: 118px;', $html, 1);
        $html = str_replace('sheet-size: A4', 'sheet-size: Letter', $html);
        $html = str_replace("'Arial'", 'freesans', $html);
        $html = preg_replace('~<title>.*?</title>~s', '<title>' . htmlspecialchars($titulo) . '</title>', $html, 1);
        $html = str_replace('</style>', "p {margin: 0; padding: 0;}
td {padding-top: 1px; padding-bottom: 1px;}
</style>", $html);

        $mpdf = new \Mpdf\Mpdf(['default_font' => 'freesans', 'tempDir' => $tmp]);
        $mpdf->SetTitle($titulo);
        $mpdf->AddPage('P');

        $pos = strpos($html, '<body>');
        if ($pos !== false) {
            $pos += strlen('<body>');
            $mpdf->WriteHTML(substr($html, 0, $pos));
            $html = substr($html, $pos);
        }
        foreach (explode("
", $html) as $linea) {
            $mpdf->WriteHTML($linea . "
");
        }
        $mpdf->Output($rutaTmp, \Mpdf\Output\Destination::FILE);
    }

    rename($rutaTmp, $rutaPdf);
    return $rutaPdf;
}
