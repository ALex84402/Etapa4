<?php
// Configuración del servidor de archivos.

// Carpetas de almacenamiento (protegidas por almacen/.htaccess).
define('DIR_ARCHIVOS', __DIR__ . '/almacen/archivos');
define('DIR_TEMPORAL', __DIR__ . '/almacen/temporal');

// Tamaño máximo por archivo. 0 = sin límite (solo lo limita el espacio libre en disco).
// Ejemplo para limitar a 100 GB: 100 * 1024 * 1024 * 1024
define('TAMANO_MAXIMO', 0);

// Extensiones permitidas. Vacío = cualquier tipo de archivo.
// Ejemplo para aceptar solo zip: ['zip']
define('EXTENSIONES_PERMITIDAS', []);

// Los archivos de más de 2 GB necesitan PHP de 64 bits.
if (PHP_INT_SIZE < 8) {
    http_response_code(500);
    exit('Este servidor de archivos necesita PHP de 64 bits para manejar archivos de más de 2 GB.');
}

// Espacio libre que siempre se deja en el disco: 1 GB.
define('ESPACIO_RESERVADO', 1024 * 1024 * 1024);

// Convierte valores de php.ini como "2M" o "1G" a bytes.
function ini_a_bytes($valor) {
    $valor = trim((string) $valor);
    $num = (float) $valor;
    switch (strtolower(substr($valor, -1))) {
        case 'g': $num *= 1024;
        case 'm': $num *= 1024;
        case 'k': $num *= 1024;
    }
    return (int) $num;
}

// Tamaño de cada trozo: el menor entre 64 MB y los límites de PHP,
// así funciona aunque upload_max_filesize sea pequeño (2 MB por defecto).
// El .htaccess y .user.ini de la raíz suben esos límites a 100 MB para que
// un archivo de 50 GB se envíe en ~800 partes en lugar de ~26.000.
function tamano_trozo() {
    $limites = [64 * 1024 * 1024];
    foreach (['upload_max_filesize', 'post_max_size'] as $clave) {
        $b = ini_a_bytes(ini_get($clave));
        if ($b > 0) $limites[] = $b;
    }
    // Margen de 64 KB para las cabeceras del formulario.
    return max(256 * 1024, min($limites) - 64 * 1024);
}

function formatear_tamano($bytes) {
    $unidades = ['B', 'KB', 'MB', 'GB', 'TB'];
    $i = 0;
    while ($bytes >= 1024 && $i < count($unidades) - 1) { $bytes /= 1024; $i++; }
    return round($bytes, $i ? 2 : 0) . ' ' . $unidades[$i];
}

// Deja solo caracteres seguros en el nombre del archivo.
function limpiar_nombre($nombre) {
    $nombre = basename(str_replace('\\', '/', $nombre));
    $nombre = preg_replace('/[^\p{L}\p{N}._ -]+/u', '_', $nombre);
    $nombre = trim($nombre, ". ");
    if ($nombre === '') $nombre = 'archivo';
    return mb_substr($nombre, 0, 150);
}

// Valida un nombre almacenado (formato: <32 hex>_<nombre>) y devuelve su ruta.
function ruta_archivo($almacenado) {
    $almacenado = (string) $almacenado;
    if ($almacenado !== basename($almacenado) || !preg_match('/^[a-f0-9]{32}_.+$/', $almacenado)) {
        return null;
    }
    $ruta = DIR_ARCHIVOS . '/' . $almacenado;
    return is_file($ruta) ? $ruta : null;
}

function exigir_sesion($json = false) {
    if (session_status() !== PHP_SESSION_ACTIVE) session_start();
    if (!isset($_SESSION['usuario'])) {
        if ($json) {
            http_response_code(401);
            header('Content-Type: application/json');
            echo json_encode(['error' => 'Sesión no iniciada']);
        } else {
            header('Location: sistema.php');
        }
        exit();
    }
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
}

function extension_permitida($nombre) {
    if (!EXTENSIONES_PERMITIDAS) return true;
    $ext = strtolower(pathinfo($nombre, PATHINFO_EXTENSION));
    return in_array($ext, array_map('strtolower', EXTENSIONES_PERMITIDAS), true);
}

function verificar_csrf($token) {
    return is_string($token) && hash_equals($_SESSION['csrf'] ?? '', $token);
}
