<?php
// Descarga un archivo guardado. Admite cabecera Range para reanudar
// descargas grandes y para reproducir video/audio en el navegador.
require __DIR__ . '/config_archivos.php';
exigir_sesion();
session_write_close();

$ruta = ruta_archivo($_GET['f'] ?? '');
if ($ruta === null) {
    http_response_code(404);
    exit('Archivo no encontrado');
}

$tamano = filesize($ruta);
$nombre = substr(basename($ruta), 33);
$inicio = 0;
$fin = $tamano - 1;

if (isset($_SERVER['HTTP_RANGE'])) {
    if (!preg_match('/^bytes=(\d*)-(\d*)$/', $_SERVER['HTTP_RANGE'], $m) || ($m[1] === '' && $m[2] === '')) {
        http_response_code(416);
        header("Content-Range: bytes */$tamano");
        exit();
    }
    if ($m[1] === '') {            // bytes=-500 (últimos 500)
        $inicio = max(0, $tamano - (int) $m[2]);
    } else {
        $inicio = (int) $m[1];
        if ($m[2] !== '') $fin = min($fin, (int) $m[2]);
    }
    if ($inicio > $fin || $inicio >= $tamano) {
        http_response_code(416);
        header("Content-Range: bytes */$tamano");
        exit();
    }
    http_response_code(206);
    header("Content-Range: bytes $inicio-$fin/$tamano");
}

$tipo = function_exists('mime_content_type') ? (mime_content_type($ruta) ?: 'application/octet-stream') : 'application/octet-stream';
$disposicion = isset($_GET['ver']) ? 'inline' : 'attachment';

header('Content-Type: ' . $tipo);
header('Content-Length: ' . ($fin - $inicio + 1));
header('Accept-Ranges: bytes');
header('X-Content-Type-Options: nosniff');
header("Content-Disposition: $disposicion; filename=\"" . addcslashes($nombre, '"\\') . "\"; filename*=UTF-8''" . rawurlencode($nombre));

set_time_limit(0);
while (ob_get_level()) ob_end_clean();

$fp = fopen($ruta, 'rb');
fseek($fp, $inicio);
$restante = $fin - $inicio + 1;
while ($restante > 0 && !feof($fp) && !connection_aborted()) {
    $bloque = fread($fp, (int) min(1024 * 1024, $restante));
    echo $bloque;
    flush();
    $restante -= strlen($bloque);
}
fclose($fp);
