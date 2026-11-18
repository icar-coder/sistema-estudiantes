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

`api_sync.php` recibe lotes JSON autenticados con `Authorization: Bearer ...`. Antes de habilitarlo, revisa y aplica manualmente `migracion_sync_receiver.sql` y `migracion_unificar_secciones.sql`, con respaldo previo. Configura `SYNC_TOKEN` como variable de entorno en cada instalación, con al menos 32 caracteres aleatorios; alternativamente, guarda `sync => ['token' => '...']` en `includes/config.local.php`. No pongas el token en archivos versionados como `.htaccess`. La instalación local de pruebas y producción deben usar tokens distintos. El cliente debe enviar el token correspondiente al servidor de destino y usar HTTPS en producción.

El cuerpo tiene el formato `{"records":{"años_escolares":[{"uuid":"...","nombre":"2025-2026"}]}}`. Los borrados físicos se envían en una propiedad opcional `deletions`, con listas de UUID por tabla. Se aceptan solo las tablas y columnas explícitamente permitidas en `includes/sync_receiver.php`, hasta 50 registros o borrados por tabla y 2 MiB por solicitud. Las claves foráneas se envían como `uuid_<nombre_columna>`; el servidor resuelve esos UUID antes de guardar. `usuarios` no se sincroniza, aunque se pueden referenciar usuarios que ya existan en el servidor. Cada solicitud es transaccional: una referencia inexistente o una colisión de índices únicos cancela el lote y devuelve un error; una colisión única secundaria responde HTTP 409.

El receptor procesa altas/cambios de padres a hijos y borrados de hijos a padres, identifica registros por UUID y actualiza el registro existente si vuelve a recibir el mismo UUID. Los borrados son físicos e idempotentes: el servidor conserva el UUID→ID en `sync_map` para reconocer reintentos después de eliminar la fila. La política elegida es **última recepción en el servidor gana**: una sincronización que llega después puede sobrescribir cambios previos, aunque se hayan editado antes en otro dispositivo; las solicitudes más antiguas que se procesen después se ignoran. `sincronizado` se marca en 1 y la respuesta incluye el ID remoto para actualizar el mapa local. La migración del receptor añade el marcador de recepción y evita que solicitudes concurrentes antiguas reemplacen solicitudes posteriores. La migración de secciones amplía `secciones.nombre`, que el esquema previo limita a 10 caracteres.

El alcance offline acordado es el área administrativa; los portales de estudiantes y profesores quedan excluidos. También se excluirán del modo offline las pantallas obsoletas de notas, materias, tipos de evaluación y sus reportes, ya que las tablas correspondientes no existen en el esquema recibido. El cliente offline mantiene una cola SQLite en `database/datos_locales.sqlite`; dispara la cola automáticamente ante altas, cambios y borrados de las tablas sincronizables. En una sesión administrativa SQLite intenta sincronizar al abrir cada página, al recuperar conexión y cada 30 segundos, con bloqueo para evitar solicitudes simultáneas; también se conserva el comando CLI `php sincronizar.php`. Los borrados de filas existentes se guardan como tombstones UUID y se eliminan del servidor en orden seguro; borrar un registro que nunca llegó al servidor cancela su inserción pendiente. En la exportación inicial se siembran los mapas UUID→ID local/remoto para reconocer también los registros históricos. Configura `SYNC_ENDPOINT` y `SYNC_TOKEN` como variables de entorno, o `sync.endpoint` y `sync.token` en `includes/config.local.php`. Despliega primero la versión actualizada de `api_sync.php` antes de sincronizar borrados desde un cliente. La cola conserva registros ante errores de red y muestra conflictos/rechazos. **La migración de las pantallas administrativas restantes sigue en progreso**; no actives `db_driver => sqlite` para uso diario hasta completar y validar ese trabajo.

Para preparar la copia inicial, ejecuta `php exportar_sqlite.php --confirmar-datos-privados` desde la carpeta del proyecto, con la conexión MySQL configurada. El comando es solo CLI, se niega a sobrescribir una base local existente y muestra únicamente conteos. Copia todas las tablas y sus registros a SQLite —incluyendo información personal y usuarios locales—, por lo que protege el USB y sus respaldos. Si detecta vistas, triggers o índices no compatibles, aborta sin dejar una base parcial. SQLite no reproduce por completo las collations Unicode de MySQL; verifica unicidad en datos con acentos y mayúsculas antes de usar esa copia como base operativa.

## Correo de recuperación

El envío de recuperación usa Gmail SMTP mediante PHPMailer. Instala la dependencia con `composer install --no-dev --optimize-autoloader` y despliega también la carpeta `vendor/`.

Configura `APP_URL` con el origen público del sitio, sin ruta ni barra final (por ejemplo, `https://sistemaestudiante.alwaysdata.net`). En `includes/config.local.php` puedes agregar la sección `mail` siguiendo `includes/config.local.example.php`, o definir las variables de entorno `MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`, `MAIL_PASSWORD`, `MAIL_ENCRYPTION`, `MAIL_FROM_ADDRESS` y `MAIL_FROM_NAME`. Para Gmail usa `smtp.gmail.com`, puerto `587`, cifrado `tls`, tu dirección Gmail y una contraseña de aplicación de Google; no uses la contraseña normal de la cuenta. Activa la verificación en dos pasos de Google y genera una contraseña de aplicación desde la configuración de seguridad de esa cuenta.

## Despliegue

No se incluye configuración automática de publicación: primero confirma el proveedor, la ruta pública y los requisitos de PHP. En producción, deja `APP_ENV=production`, configura las credenciales de la base de datos y verifica HTTPS. La carpeta de registros se crea durante la ejecución y no debe publicarse.

Las rutas web se ajustan automáticamente a la ubicación de la aplicación: por ejemplo, `/SistemaEstudiantes` en XAMPP y `/` cuando se publica en la raíz del dominio. Si se instala bajo otra ruta, se puede definir `APP_BASE_PATH` con esa ruta (por ejemplo, `/mi-aplicacion`).

## PDF

El sistema usa **TCPDF**, incluido en `includes/tcpdf/` (versión 6.10.0). Se conserva su licencia junto con la biblioteca; no elimines esa carpeta ni sus fuentes requeridas.
