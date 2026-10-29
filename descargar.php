<?php
// Redirige al visor de PDF (ver_documento.php).
$id = (int) ($_GET['id'] ?? 0);
// Redirige a otra página.
header('Location: ver_documento.php?id=' . $id);
exit;
