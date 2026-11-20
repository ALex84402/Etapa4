<?php
require __DIR__ . '/config_archivos.php';
exigir_sesion();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verificar_csrf($_POST['csrf'] ?? '')) {
    $ruta = ruta_archivo($_POST['f'] ?? '');
    if ($ruta !== null) unlink($ruta);
}
header('Location: archivos.php');
exit();
