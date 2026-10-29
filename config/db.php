<?php
// Conexión a la base de datos y dirección base del sistema.
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$host      = '';
$usuario   = '';
$clave     = '';
$baseDatos = '';

define('APP_URL', 'DOMINIO');

$conn = new mysqli($host, $usuario, $clave, $baseDatos);
$conn->set_charset('utf8mb4');
