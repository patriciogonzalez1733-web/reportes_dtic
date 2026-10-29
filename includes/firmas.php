<?php
// Pone fondo blanco a la imagen de la firma.
function aplanar_fondo_blanco($img)
{
    $ancho = imagesx($img);
    $alto  = imagesy($img);
    $fondo = imagecreatetruecolor($ancho, $alto);
    $blanco = imagecolorallocate($fondo, 255, 255, 255);
    imagefill($fondo, 0, 0, $blanco);
    imagealphablending($fondo, true);
    imagecopy($fondo, $img, 0, 0, 0, 0, $ancho, $alto);
    imagedestroy($img);
    return $fondo;
}

// Tamaño máximo de la firma (2 MB).
const FIRMA_MAX_BYTES = 2 * 1024 * 1024;
const FIRMA_MAX_LADO  = 4000;

// Lee y valida la firma (dibujada o subida) como imagen.
function leer_firma_gd(string $firmaDato, ?array $firmaArchivo)
{
    if ($firmaDato !== '') {
        $base64 = preg_replace('#^data:image/\w+;base64,#', '', $firmaDato);
        $bin = base64_decode($base64, true);
        if ($bin === false || $bin === '' || strlen($bin) > FIRMA_MAX_BYTES) {
            return null;
        }
        $info = @getimagesizefromstring($bin);
        if ($info === false) {
            return null;
        }
        $img = @imagecreatefromstring($bin);
    } elseif ($firmaArchivo && $firmaArchivo['error'] === UPLOAD_ERR_OK) {
        if ($firmaArchivo['size'] > FIRMA_MAX_BYTES) {
            return null;
        }
        $info = @getimagesize($firmaArchivo['tmp_name']);
        if ($info === false) {
            return null;
        }
        $img = match ($info[2]) {
            IMAGETYPE_PNG  => @imagecreatefrompng($firmaArchivo['tmp_name']),
            IMAGETYPE_JPEG => @imagecreatefromjpeg($firmaArchivo['tmp_name']),
            default        => false,
        };
    } else {
        return null;
    }

    if (!in_array($info[2], [IMAGETYPE_PNG, IMAGETYPE_JPEG], true)
        || $info[0] > FIRMA_MAX_LADO || $info[1] > FIRMA_MAX_LADO) {
        return null;
    }
    return $img ?: null;
}

// Revisa que la firma sea una imagen válida.
function validar_firma(string $firmaDato, ?array $firmaArchivo): ?string
{
    $hayArchivo = $firmaArchivo && $firmaArchivo['error'] !== UPLOAD_ERR_NO_FILE;
    if ($firmaDato === '' && !$hayArchivo) {
        return null;
    }
    $img = leer_firma_gd($firmaDato, $firmaArchivo);
    if (!$img) {
        return 'La firma debe ser una imagen PNG o JPG válida (máximo 2 MB).';
    }
    imagedestroy($img);
    return null;
}

// Guarda la firma como PNG en la ruta indicada.
function guardar_firma(string $firmaDato, ?array $firmaArchivo, string $ruta): void
{
    $hayArchivo = $firmaArchivo && $firmaArchivo['error'] === UPLOAD_ERR_OK;
    if ($firmaDato === '' && !$hayArchivo) {
        return;
    }
    $img = leer_firma_gd($firmaDato, $firmaArchivo);
    if (!$img) {
        throw new RuntimeException('La firma no es una imagen válida.');
    }
    $img = aplanar_fondo_blanco($img);
    imagepng($img, $ruta);
    imagedestroy($img);
}
