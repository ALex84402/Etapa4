<?php
require __DIR__ . '/config_archivos.php';
exigir_sesion();

// Limpia subidas abandonadas hace más de 24 horas.
foreach (glob(DIR_TEMPORAL . '/*.part') ?: [] as $parcial) {
    if (filemtime($parcial) < time() - 86400) @unlink($parcial);
}

$archivos = [];
foreach (scandir(DIR_ARCHIVOS) as $f) {
    if (ruta_archivo($f) === null) continue;
    $ruta = DIR_ARCHIVOS . '/' . $f;
    $archivos[] = ['id' => $f, 'nombre' => substr($f, 33), 'tamano' => filesize($ruta), 'fecha' => filemtime($ruta)];
}
usort($archivos, fn($a, $b) => $b['fecha'] <=> $a['fecha']);
$total = array_sum(array_column($archivos, 'tamano'));
$libre = @disk_free_space(DIR_ARCHIVOS);
$csrf = $_SESSION['csrf'];
function e($s) { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Archivos</title>
    <link rel="stylesheet" href="style.css">
    <style>
        .zona { border: 2px dashed #2a6496; border-radius: 8px; padding: 30px; text-align: center; cursor: pointer; background: #f7fbff; }
        .zona.activa { background: #e1effa; }
        .cola { margin-top: 15px; }
        .item { margin: 10px 0; }
        .item .info { display: flex; justify-content: space-between; font-size: .9em; gap: 10px; }
        .item .info span:first-child { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        progress { width: 100%; height: 16px; }
        .error { color: #c0392b; }
        .ok { color: #27ae60; }
        .resumen { margin-top: 15px; color: #555; font-size: .9em; }
        td form { display: inline; margin: 0; padding: 0; box-shadow: none; max-width: none; background: none; }
        td button { margin: 0; padding: 5px 10px; font-size: .85em; }
        td button.borrar { background: #c0392b; }
        .acciones { white-space: nowrap; }
    </style>
</head>
<body>
    <h1>Servidor de archivos</h1>
    <nav>
        <ul>
            <li><a href="index.html">Inicio</a></li>
            <li><a href="admin.php">Administración</a></li>
            <li><a href="archivos.php">Archivos</a></li>
            <li><a href="logout.php">Cerrar sesión</a></li>
        </ul>
    </nav>

    <div class="container">
        <h2>Subir archivos</h2>
        <div class="zona" id="zona">
            Arrastra archivos aquí o haz clic para elegirlos<br>
            <small>Máximo <?= e(formatear_tamano(TAMANO_MAXIMO)) ?> por archivo · se suben por partes y se reanudan si se corta la conexión</small>
            <input type="file" id="selector" multiple hidden>
        </div>
        <div class="cola" id="cola"></div>

        <h2 style="margin-top:30px">Archivos guardados</h2>
        <?php if (!$archivos): ?>
            <p style="text-align:center">Todavía no hay archivos.</p>
        <?php else: ?>
        <table>
            <tr><th>Nombre</th><th>Tamaño</th><th>Fecha</th><th>Acciones</th></tr>
            <?php foreach ($archivos as $a): ?>
            <tr>
                <td><?= e($a['nombre']) ?></td>
                <td><?= e(formatear_tamano($a['tamano'])) ?></td>
                <td><?= date('d/m/Y H:i', $a['fecha']) ?></td>
                <td class="acciones">
                    <a href="descargar.php?f=<?= urlencode($a['id']) ?>"><button type="button">Descargar</button></a>
                    <form method="post" action="eliminar.php" onsubmit="return confirm('¿Eliminar <?= e(addslashes($a['nombre'])) ?>?')">
                        <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
                        <input type="hidden" name="f" value="<?= e($a['id']) ?>">
                        <button type="submit" class="borrar">Eliminar</button>
                    </form>
                </td>
            </tr>
            <?php endforeach; ?>
        </table>
        <?php endif; ?>
        <p class="resumen">
            <?= count($archivos) ?> archivo(s) · <?= e(formatear_tamano($total)) ?> usados
            <?php if ($libre !== false): ?> · <?= e(formatear_tamano($libre)) ?> libres en disco<?php endif; ?>
        </p>
    </div>

<script>
const CSRF = <?= json_encode($csrf) ?>;
const zona = document.getElementById('zona');
const selector = document.getElementById('selector');
const cola = document.getElementById('cola');
let pendientes = [];
let subiendo = false;
let huboExito = false;

zona.addEventListener('click', () => selector.click());
selector.addEventListener('change', () => { agregar(selector.files); selector.value = ''; });
zona.addEventListener('dragover', e => { e.preventDefault(); zona.classList.add('activa'); });
zona.addEventListener('dragleave', () => zona.classList.remove('activa'));
zona.addEventListener('drop', e => { e.preventDefault(); zona.classList.remove('activa'); agregar(e.dataTransfer.files); });
window.addEventListener('beforeunload', e => { if (subiendo) { e.preventDefault(); e.returnValue = ''; } });

function tam(b) {
    const u = ['B', 'KB', 'MB', 'GB', 'TB']; let i = 0;
    while (b >= 1024 && i < u.length - 1) { b /= 1024; i++; }
    return b.toFixed(i ? 1 : 0) + ' ' + u[i];
}

function agregar(lista) {
    for (const archivo of lista) {
        const el = document.createElement('div');
        el.className = 'item';
        el.innerHTML = '<div class="info"><span></span><span class="estado">En espera</span></div><progress value="0" max="100"></progress>';
        el.querySelector('span').textContent = archivo.name + ' (' + tam(archivo.size) + ')';
        cola.appendChild(el);
        pendientes.push({ archivo, el });
    }
    procesar();
}

async function procesar() {
    if (subiendo) return;
    subiendo = true;
    while (pendientes.length) {
        const { archivo, el } = pendientes.shift();
        try { await subir(archivo, el); huboExito = true; }
        catch (err) {
            const est = el.querySelector('.estado');
            est.textContent = 'Error: ' + err.message;
            est.className = 'estado error';
        }
    }
    subiendo = false;
    if (huboExito) setTimeout(() => location.reload(), 1200);
}

async function peticion(datos) {
    const form = new FormData();
    for (const [k, v] of Object.entries(datos)) form.append(k, v);
    const r = await fetch('subir.php', { method: 'POST', body: form, headers: { 'X-CSRF-Token': CSRF } });
    let json = {};
    try { json = await r.json(); } catch (_) {}
    if (!r.ok) { const e = new Error(json.error || ('HTTP ' + r.status)); e.status = r.status; e.recibido = json.recibido; throw e; }
    return json;
}

async function subir(archivo, el) {
    const barra = el.querySelector('progress');
    const est = el.querySelector('.estado');
    const { id, trozo } = await peticion({ accion: 'iniciar', nombre: archivo.name, tamano: archivo.size });
    let offset = 0, fallos = 0;
    const t0 = Date.now();

    if (archivo.size === 0) await peticion({ accion: 'trozo', id, offset: 0, trozo: new Blob([]) });

    while (offset < archivo.size) {
        try {
            const r = await peticion({ accion: 'trozo', id, offset, trozo: archivo.slice(offset, offset + trozo) });
            offset = r.recibido;
            fallos = 0;
        } catch (err) {
            if (err.status === 409 && typeof err.recibido === 'number') { offset = err.recibido; continue; }
            if (err.status && err.status < 500) throw err;
            if (++fallos > 8) throw err;
            est.textContent = 'Conexión perdida, reintentando (' + fallos + ')...';
            await new Promise(r => setTimeout(r, Math.min(30000, 1000 * 2 ** fallos)));
            // Preguntamos al servidor cuánto tiene para continuar desde ahí.
            try { offset = (await peticion({ accion: 'estado', id })).recibido; } catch (_) {}
            continue;
        }
        const pct = offset / archivo.size * 100;
        const vel = offset / ((Date.now() - t0) / 1000);
        barra.value = pct;
        est.textContent = pct.toFixed(1) + '% · ' + tam(vel) + '/s';
    }
    barra.value = 100;
    est.textContent = 'Completado';
    est.className = 'estado ok';
}
</script>
</body>
</html>
