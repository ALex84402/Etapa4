# Etapa4

## Servidor de archivos

Después de iniciar sesión (`sistema.php`), entra a **archivos.php** (también hay un enlace en `admin.php`) para subir, descargar y eliminar archivos.

- Los archivos se suben **por partes** (de hasta 64 MB), así que se pueden guardar archivos de **más de 50 GB** (por ejemplo .zip enormes). No hay límite de tamaño: solo el espacio libre en disco.
- Requiere PHP de 64 bits (XAMPP actual ya lo es).
- Si se corta la conexión, la subida se **reanuda** sola desde donde iba.
- Las descargas admiten reanudación (cabecera `Range`).
- Los archivos se guardan en `almacen/archivos/`, protegida con `.htaccess` para que solo se puedan descargar con sesión iniciada.
- En `config_archivos.php` puedes poner un tamaño máximo (`TAMANO_MAXIMO`, 0 = sin límite) o aceptar solo ciertos tipos (`EXTENSIONES_PERMITIDAS`, p. ej. `['zip']`).
- `.htaccess` y `.user.ini` suben los límites de PHP a 100 MB por petición para que las partes de 64 MB pasen.
