<?php
// Cierra la sesión y vuelve al login.
require_once __DIR__ . '/includes/auth.php';

cerrar_sesion();
// Redirige a otra página.
header('Location: login.php');
exit;
