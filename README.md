# Etapa4

## Servidor de archivos

Después de iniciar sesión (`sistema.php`), entra a **archivos.php** (también hay un enlace en `admin.php`) para subir, descargar y eliminar archivos.

- Los archivos se suben **por partes**, así que no importa el límite `upload_max_filesize` de PHP (2 MB por defecto) y se pueden subir archivos de muchos GB.
- Si se corta la conexión, la subida se **reanuda** sola desde donde iba.
- Las descargas admiten reanudación (cabecera `Range`).
- Los archivos se guardan en `almacen/archivos/`, protegida con `.htaccess` para que solo se puedan descargar con sesión iniciada.
- El tamaño máximo por archivo se cambia en `config_archivos.php` (`TAMANO_MAXIMO`, 20 GB por defecto).
