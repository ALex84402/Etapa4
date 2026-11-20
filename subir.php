<?php
// Recibe archivos grandes por trozos. Acciones:
//   iniciar : nombre, tamano          -> { id, trozo }
//   estado  : id                      -> { recibido }   (para reanudar)
//   trozo   : id, offset, trozo(file) -> { recibido, completo }
require __DIR__ . '/config_archivos.php';
exigir_sesion(true);
header('Content-Type: application/json');

function responder($datos, $codigo = 200) {
    http_response_code($codigo);
    echo json_encode($datos);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') responder(['error' => 'Método no permitido'], 405);
if (!verificar_csrf($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')) responder(['error' => 'Token CSRF inválido'], 403);

$accion = $_POST['accion'] ?? '';

if ($accion === 'iniciar') {
    $nombre = limpiar_nombre($_POST['nombre'] ?? '');
    $tamano = filter_var($_POST['tamano'] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
    if ($tamano === false) responder(['error' => 'Tamaño inválido'], 400);
    if (!extension_permitida($nombre)) {
        responder(['error' => 'Tipo de archivo no permitido. Solo: ' . implode(', ', EXTENSIONES_PERMITIDAS)], 415);
    }
    if (TAMANO_MAXIMO > 0 && $tamano > TAMANO_MAXIMO) {
        responder(['error' => 'El archivo supera el máximo de ' . formatear_tamano(TAMANO_MAXIMO)], 413);
    }
    $libre = @disk_free_space(DIR_ARCHIVOS);
    if ($libre !== false && $tamano > $libre - ESPACIO_RESERVADO) {
        responder(['error' => 'No hay espacio suficiente en el disco: el archivo ocupa '
            . formatear_tamano($tamano) . ' y hay ' . formatear_tamano(max(0, $libre - ESPACIO_RESERVADO)) . ' disponibles'], 507);
    }

    $id = bin2hex(random_bytes(16));
    touch(DIR_TEMPORAL . "/$id.part");
    $_SESSION['subidas'][$id] = ['nombre' => $nombre, 'tamano' => $tamano];
    responder(['id' => $id, 'trozo' => tamano_trozo()]);
}

// Las demás acciones requieren una subida iniciada en esta sesión.
$id = $_POST['id'] ?? '';
if (!is_string($id) || !preg_match('/^[a-f0-9]{32}$/', $id) || !isset($_SESSION['subidas'][$id])) {
    responder(['error' => 'Subida desconocida'], 404);
}
$subida = $_SESSION['subidas'][$id];
$parcial = DIR_TEMPORAL . "/$id.part";
clearstatcache(true, $parcial);
$recibido = is_file($parcial) ? filesize($parcial) : 0;

if ($accion === 'estado') {
    responder(['recibido' => $recibido]);
}

if ($accion === 'trozo') {
    // Liberamos la sesión para no bloquear otras pestañas mientras se copia.
    session_write_close();

    $offset = filter_var($_POST['offset'] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
    if ($offset !== $recibido) {
        responder(['error' => 'Offset incorrecto', 'recibido' => $recibido], 409);
    }
    $f = $_FILES['trozo'] ?? null;
    if (!$f || $f['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) {
        responder(['error' => 'Trozo no recibido (código ' . ($f['error'] ?? '?') . ')'], 400);
    }
    if ($recibido + $f['size'] > $subida['tamano']) {
        responder(['error' => 'Se recibieron más datos de los esperados'], 400);
    }

    $origen = fopen($f['tmp_name'], 'rb');
    $destino = fopen($parcial, 'ab');
    if (!$origen || !$destino || !flock($destino, LOCK_EX)) {
        responder(['error' => 'No se pudo escribir en el servidor'], 500);
    }
    $copiados = stream_copy_to_stream($origen, $destino);
    fflush($destino);
    flock($destino, LOCK_UN);
    fclose($destino);
    fclose($origen);
    if ($copiados !== $f['size']) {
        responder(['error' => 'Escritura incompleta'], 500);
    }

    $recibido += $copiados;
    if ($recibido < $subida['tamano']) {
        responder(['recibido' => $recibido, 'completo' => false]);
    }

    // Último trozo: mover al almacén definitivo.
    $final = DIR_ARCHIVOS . '/' . $id . '_' . $subida['nombre'];
    if (!rename($parcial, $final)) responder(['error' => 'No se pudo guardar el archivo'], 500);
    session_start();
    unset($_SESSION['subidas'][$id]);
    responder(['recibido' => $recibido, 'completo' => true]);
}

responder(['error' => 'Acción no válida'], 400);
