# Sistema de Gestión Estudiantil

Aplicación PHP y MySQL para gestionar estudiantes, profesores, secciones, asistencia, evaluaciones, notas y documentos escolares.

## Requisitos

- PHP 7.4 o superior y MySQL/MariaDB.
- Extensiones PHP `pdo_mysql`, `mbstring` y las requeridas por TCPDF para generar documentos PDF.
- HTTPS en producción.

## Base de datos

Configura la conexión usando las variables de entorno `DB_HOST`, `DB_NAME`, `DB_USER` y `DB_PASS`, o copia `includes/config.local.example.php` a `includes/config.local.php` y reemplaza los valores de ejemplo. El archivo local está excluido de Git.

Los archivos SQL y respaldos actuales pueden contener datos personales de estudiantes. Se conservan en el equipo, pero están excluidos de Git y bloqueados para descarga directa desde Apache. Antes de preparar un repositorio, crea un esquema de instalación limpio y anonimizado si hace falta compartirlo.

El proyecto incluye `migracion_reset_password.sql` para una migración concreta y `migracion_unificar_secciones.sql` para ampliar el nombre de sección a 50 caracteres. Revisa cada migración y su compatibilidad con la base de datos, conserva un respaldo y ejecútala manualmente; la aplicación no las aplica automáticamente.

## Respaldos de base de datos

El panel de respaldos crea un archivo SQL descargable y restaura archivos SQL usando la conexión PDO configurada; no requiere los ejecutables locales de XAMPP. Guarda los respaldos descargados en un lugar privado. La importación reemplaza tablas y puede dejar una restauración parcial si el SQL falla; crea y conserva un respaldo de los datos actuales antes de restaurar.

## Receptor de sincronización

`api_sync.php` recibe lotes JSON autenticados con `Authorization: Bearer ...`. Antes de habilitarlo, revisa y aplica manualmente `migracion_sync_receiver.sql` y `migracion_unificar_secciones.sql`, con respaldo previo. Configura `SYNC_TOKEN` como variable de entorno en cada instalación, con al menos 32 caracteres aleatorios; no guardes el token en Git. La instalación local de pruebas y producción deben usar tokens distintos. El cliente debe enviar el token correspondiente al servidor de destino y usar HTTPS en producción.

El cuerpo tiene el formato `{"records":{"años_escolares":[{"uuid":"...","nombre":"2025-2026"}]}}`. Se aceptan solo las tablas y columnas explícitamente permitidas en `includes/sync_receiver.php`, hasta 50 registros por tabla y 2 MiB por solicitud. Las claves foráneas se envían como `uuid_<nombre_columna>`; el servidor resuelve esos UUID antes de guardar. `usuarios` no se sincroniza, aunque se pueden referenciar usuarios que ya existan en el servidor. Cada solicitud es transaccional: una referencia inexistente o una colisión de índices únicos cancela el lote y devuelve un error; una colisión única secundaria responde HTTP 409.

El receptor procesa padres antes que hijos, identifica registros por UUID y actualiza el registro existente si vuelve a recibir el mismo UUID. La política elegida es **última recepción en el servidor gana**: una sincronización que llega después puede sobrescribir cambios previos, aunque se hayan editado antes en otro dispositivo; las solicitudes más antiguas que se procesen después se ignoran. `sincronizado` se marca en 1 y la respuesta incluye el ID remoto para actualizar el mapa local. La migración del receptor añade el marcador de recepción y evita que solicitudes concurrentes antiguas reemplacen solicitudes posteriores. La migración de secciones amplía `secciones.nombre`, que el esquema previo limita a 10 caracteres.

## Correo de recuperación

El envío de recuperación usa Gmail SMTP mediante PHPMailer. Instala la dependencia con `composer install --no-dev --optimize-autoloader` y despliega también la carpeta `vendor/`.

Configura `APP_URL` con el origen público del sitio, sin ruta ni barra final (por ejemplo, `https://sistemaestudiante.alwaysdata.net`). En `includes/config.local.php` puedes agregar la sección `mail` siguiendo `includes/config.local.example.php`, o definir las variables de entorno `MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`, `MAIL_PASSWORD`, `MAIL_ENCRYPTION`, `MAIL_FROM_ADDRESS` y `MAIL_FROM_NAME`. Para Gmail usa `smtp.gmail.com`, puerto `587`, cifrado `tls`, tu dirección Gmail y una contraseña de aplicación de Google; no uses la contraseña normal de la cuenta. Activa la verificación en dos pasos de Google y genera una contraseña de aplicación desde la configuración de seguridad de esa cuenta.

## Despliegue

No se incluye configuración automática de publicación: primero confirma el proveedor, la ruta pública y los requisitos de PHP. En producción, deja `APP_ENV=production`, configura las credenciales de la base de datos y verifica HTTPS. La carpeta de registros se crea durante la ejecución y no debe publicarse.

Las rutas web se ajustan automáticamente a la ubicación de la aplicación: por ejemplo, `/SistemaEstudiantes` en XAMPP y `/` cuando se publica en la raíz del dominio. Si se instala bajo otra ruta, se puede definir `APP_BASE_PATH` con esa ruta (por ejemplo, `/mi-aplicacion`).

## PDF

El sistema usa **TCPDF**, incluido en `includes/tcpdf/` (versión 6.10.0). Se conserva su licencia junto con la biblioteca; no elimines esa carpeta ni sus fuentes requeridas.
