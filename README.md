# Sistema de Gestión Estudiantil

Aplicación PHP y MySQL para gestionar estudiantes, profesores, secciones, asistencia, evaluaciones, notas y documentos escolares.

## Requisitos

- PHP 7.4 o superior y MySQL/MariaDB.
- Extensiones PHP `pdo_mysql`, `mbstring` y las requeridas por TCPDF para generar documentos PDF.
- HTTPS en producción.

## Base de datos

Configura la conexión usando las variables de entorno `DB_HOST`, `DB_NAME`, `DB_USER` y `DB_PASS`, o copia `includes/config.local.example.php` a `includes/config.local.php` y reemplaza los valores de ejemplo. El archivo local está excluido de Git.

Los archivos SQL y respaldos actuales pueden contener datos personales de estudiantes. Se conservan en el equipo, pero están excluidos de Git y bloqueados para descarga directa desde Apache. Antes de preparar un repositorio, crea un esquema de instalación limpio y anonimizado si hace falta compartirlo.

El proyecto incluye `migracion_reset_password.sql` para una migración concreta; revisa su contenido y compatibilidad con la base de datos antes de ejecutarla.

## Correo de recuperación

El envío de recuperación usa Gmail SMTP mediante PHPMailer. Instala la dependencia con `composer install --no-dev --optimize-autoloader` y despliega también la carpeta `vendor/`.

Configura `APP_URL` con el origen público del sitio, sin ruta ni barra final (por ejemplo, `https://sistemaestudiante.alwaysdata.net`). En `includes/config.local.php` puedes agregar la sección `mail` siguiendo `includes/config.local.example.php`, o definir las variables de entorno `MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`, `MAIL_PASSWORD`, `MAIL_ENCRYPTION`, `MAIL_FROM_ADDRESS` y `MAIL_FROM_NAME`. Para Gmail usa `smtp.gmail.com`, puerto `587`, cifrado `tls`, tu dirección Gmail y una contraseña de aplicación de Google; no uses la contraseña normal de la cuenta. Activa la verificación en dos pasos de Google y genera una contraseña de aplicación desde la configuración de seguridad de esa cuenta.

## Despliegue

No se incluye configuración automática de publicación: primero confirma el proveedor, la ruta pública y los requisitos de PHP. En producción, deja `APP_ENV=production`, configura las credenciales de la base de datos y verifica HTTPS. La carpeta de registros se crea durante la ejecución y no debe publicarse.

Las rutas web se ajustan automáticamente a la ubicación de la aplicación: por ejemplo, `/SistemaEstudiantes` en XAMPP y `/` cuando se publica en la raíz del dominio. Si se instala bajo otra ruta, se puede definir `APP_BASE_PATH` con esa ruta (por ejemplo, `/mi-aplicacion`).

## PDF

El sistema usa **TCPDF**, incluido en `includes/tcpdf/` (versión 6.10.0). Se conserva su licencia junto con la biblioteca; no elimines esa carpeta ni sus fuentes requeridas.
