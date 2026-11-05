# Preparación para publicar el sistema estudiantil

1. Verifica que el hosting soporte PHP, MySQL/MariaDB y las extensiones requeridas por el sistema y TCPDF.
2. Crea la base de datos en el hosting y carga únicamente un esquema de instalación seguro. No subas los volcados actuales: contienen registros de estudiantes.
3. Configura `DB_HOST`, `DB_NAME`, `DB_USER` y `DB_PASS` como variables de entorno, o instala `includes/config.local.php` en el servidor a partir de `includes/config.local.example.php`. No subas este archivo ni las credenciales al futuro repositorio.
4. Configura `APP_ENV=production`, habilita HTTPS y confirma que Apache permita las reglas `.htaccess` que bloquean descargas de SQL, logs y archivos de configuración.
5. Si se usan respaldos desde el sistema, confirma que permanezcan fuera del directorio público o que no puedan descargarse por HTTP.
6. El proyecto aún no tiene repositorio Git ni workflow de despliegue. Crea y configura el repositorio solo después de revisar los archivos ignorados y decidir cómo entregar el esquema sin datos personales.

La aplicación genera documentos y respaldos; prueba esos flujos en un entorno de ensayo y confirma que las rutas de escritura no permitan exponer archivos privados.
