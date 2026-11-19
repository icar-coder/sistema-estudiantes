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

`api_sync.php` recibe lotes JSON autenticados con `Authorization: Bearer ...`. Antes de habilitarlo, haz un respaldo y aplica manualmente, en este orden, `migracion_sincronizacion_uuid.sql`, `migracion_sync_receiver.sql` y `migracion_unificar_secciones.sql` a la base que recibirá los cambios. La primera agrega UUID, columnas de estado, índices, `sync_map` y triggers para UUID nuevos; verifica que termine sin errores antes de continuar. Configura `SYNC_TOKEN` como variable de entorno en el servidor, con al menos 32 caracteres aleatorios; alternativamente, guarda `sync => ['token' => '...']` en `includes/config.local.php`. No pongas el token en archivos versionados como `.htaccess`. La instalación local de pruebas y producción deben usar tokens distintos. El cliente debe enviar el token correspondiente al servidor de destino y usar HTTPS en producción.

El cuerpo tiene el formato `{"records":{"años_escolares":[{"uuid":"...","nombre":"2025-2026"}]}}`. Los borrados físicos se envían en una propiedad opcional `deletions`, con listas de UUID por tabla. Se aceptan solo las tablas y columnas explícitamente permitidas en `includes/sync_receiver.php`, hasta 50 registros o borrados por tabla y 2 MiB por solicitud. Las claves foráneas se envían como `uuid_<nombre_columna>`; el servidor resuelve esos UUID antes de guardar. `usuarios` no se sincroniza, aunque se pueden referenciar usuarios que ya existan en el servidor. Cada solicitud es transaccional: una referencia inexistente o una colisión de índices únicos cancela el lote y devuelve un error; una colisión única secundaria responde HTTP 409.

El receptor procesa altas/cambios de padres a hijos y borrados de hijos a padres, identifica registros por UUID y actualiza el registro existente si vuelve a recibir el mismo UUID. Los borrados son físicos e idempotentes: el servidor conserva el UUID→ID en `sync_map` para reconocer reintentos después de eliminar la fila. La política elegida es **última recepción en el servidor gana**: una sincronización que llega después puede sobrescribir cambios previos, aunque se hayan editado antes en otro dispositivo; las solicitudes más antiguas que se procesen después se ignoran. `sincronizado` se marca en 1 y la respuesta incluye el ID remoto para actualizar el mapa local. La migración del receptor añade el marcador de recepción y evita que solicitudes concurrentes antiguas reemplacen solicitudes posteriores. La migración de secciones amplía `secciones.nombre`, que el esquema previo limita a 10 caracteres.

Para habilitar también la descarga de cambios, respalda la base MySQL, despliega el código actualizado y ejecuta `php instalar_sync_pull.php` con la conexión configurada para la base Alwaysdata. El instalador CLI crea `sync_changes` y los disparadores de altas, cambios y borrados para las tablas sincronizables; el usuario de base de datos necesita permisos para crear disparadores. No se ejecuta desde la web. `api_sync.php` ofrece la acción autenticada `{"action":"pull","cursor":"0"}`; cada cliente guarda su cursor local y aplica los cambios por UUID, resolviendo las relaciones hacia los IDs locales. La sincronización primero sube la cola local y luego descarga, y no sobrescribe un registro que todavía tenga cambios locales pendientes. Conserva `sync_changes`: es el historial que permite recuperar cambios aunque un cliente permanezca desconectado durante un tiempo.

El alcance offline es el área administrativa; los portales de estudiantes y profesores quedan excluidos. El cliente SQLite permite trabajar con años escolares, secciones, horarios, áreas, estudiantes, inscripciones, profesores y evaluación por literales, y sincroniza los cambios de las tablas incluidas en `includes/sync_receiver.php`. El reporte `admin/reportes/literales.php` está disponible online y en la portable: permite filtrar estudiantes y literales por año escolar, lapso, sección o búsqueda de estudiante, y se puede imprimir o guardar como PDF desde el navegador. Notas/materias, tipos de evaluación, reportes de notas numéricas, asistencia y promedios, administración de usuarios y respaldos/restauración desde el panel siguen siendo funciones solo online y se bloquean al abrirlas directamente en modo offline; las cuentas de usuario locales se conservan en cada instalación y no se sincronizan. El cliente offline mantiene una cola SQLite en `database/datos_locales.sqlite`; dispara la cola automáticamente ante altas, cambios y borrados de las tablas sincronizables. En una sesión administrativa SQLite intenta subir la cola y descargar cambios del servidor al abrir cada página, al recuperar conexión y cada 30 segundos, con bloqueo para evitar solicitudes simultáneas; también se conserva el comando CLI `php sincronizar.php`. El menú offline incluye una pantalla de estado que lista los cambios bloqueados y permite reenviar explícitamente conflictos de recepción obsoleta; corregir un registro rechazado y volver a editarlo actualiza su cola. Los borrados físicos de filas existentes se guardan como tombstones UUID y se eliminan del servidor en orden seguro; borrar un registro que nunca llegó al servidor cancela su inserción pendiente. En la exportación inicial se siembran los mapas UUID→ID local/remoto para reconocer también los registros históricos. Configura `SYNC_ENDPOINT` y `SYNC_TOKEN` como variables de entorno, o `sync.endpoint` y `sync.token` en `includes/config.local.php`. Instala primero el pull en Alwaysdata y despliega la versión actualizada de `api_sync.php` antes de sincronizar con los clientes.

Antes de habilitar SQLite para uso diario, valida en una copia de prueba los flujos de captura y sincronización con el servidor y conserva una copia privada del archivo local. El módulo de respaldos del panel es solo online; en modo offline no aparece en el menú.

El administrador registra y modifica asistencias desde `admin/asistencias/gestionar.php`, seleccionando año escolar, sección y fecha. Esta captura funciona en MySQL y SQLite; las modificaciones offline se encolan y se sincronizan mediante la tabla `asistencias`. El reporte estadístico de asistencias sigue siendo una función solo online.

Para preparar la copia inicial sincronizable, primero aplica las migraciones indicadas arriba en la base MySQL y configura el token del servidor. Luego ejecuta `php exportar_sqlite.php --confirmar-datos-privados` desde la carpeta del proyecto, con la conexión MySQL configurada. El comando es solo CLI, se niega a sobrescribir una base local existente y muestra únicamente conteos. Copia todas las tablas y sus registros a SQLite —incluyendo información personal y usuarios locales—, por lo que protege el USB y sus respaldos. Acepta únicamente los triggers UUID creados por la migración de sincronización; si detecta otros triggers, vistas o índices no compatibles, aborta sin dejar una base parcial. SQLite no reproduce por completo las collations Unicode de MySQL; verifica unicidad en datos con acentos y mayúsculas antes de usar esa copia como base operativa.

## Paquete portable para Windows

Se puede generar una carpeta para USB con PHP y SQLite incluidos, servidor limitado a `127.0.0.1` y los recursos visuales locales; no necesita XAMPP, MySQL ni Internet al ejecutarse. El enrutador local bloquea el acceso web a la base, configuración, sesiones y logs. Para la copia exclusivamente portable, primero prepara `database/datos_locales.sqlite` con `php exportar_sqlite.php --confirmar-datos-privados --portable-sin-sincronizacion`; esta opción añade los UUID necesarios solo a la copia SQLite y no modifica MySQL. Instala las dependencias de Composer y usa un runtime PHP de Windows que incluya `pdo_sqlite`, `sqlite3`, `mbstring`, `openssl` y `curl` (este último es necesario para sincronizar). Los paquetes sincronizables también incluyen un archivo de certificados CA para validar HTTPS. Desde PowerShell ejecuta:

```powershell
.\tools\crear_paquete_portable.ps1 `
  -PhpRuntimePath "C:\xampp\php" `
  -SqliteDatabasePath ".\database\datos_locales.sqlite" `
  -OutputPath "C:\SistemaEstudiantes-portable"
```

El generador excluye la configuración local, SQL, logs y bases de datos del proyecto, y copia únicamente la SQLite indicada. Obtiene versiones fijadas de Bootstrap, Font Awesome y Chart.js mientras se crea el paquete; por eso se requiere Internet durante la generación, pero no al usar el USB. En el destino, ejecuta `iniciar.bat`; el servidor solo acepta conexiones del mismo equipo. Por defecto, la copia portable desactiva la sincronización con Alwaysdata y solo habilita las funciones administrativas compatibles con SQLite. Para generar una copia sincronizable, usa una SQLite exportada después de aplicar las migraciones (no la copia portable local-only), define `SYNC_ENDPOINT` como la URL HTTPS completa de `api_sync.php` y `SYNC_TOKEN` en la sesión de PowerShell, y añade `-EnableSync` al comando del generador. El script valida los UUID, mapas y triggers antes de incluir el token, que se guarda en texto plano dentro del paquete; protege el USB y no reutilices una copia local-only para sincronizar. Contiene información personal: no uses la misma base desde dos equipos a la vez y detén el servidor antes de desconectarlo. El paquete no cifra SQLite.

## Correo de recuperación

El envío de recuperación usa Gmail SMTP mediante PHPMailer. Instala la dependencia con `composer install --no-dev --optimize-autoloader` y despliega también la carpeta `vendor/`.

Configura `APP_URL` con el origen público del sitio, sin ruta ni barra final (por ejemplo, `https://sistemaestudiante.alwaysdata.net`). En `includes/config.local.php` puedes agregar la sección `mail` siguiendo `includes/config.local.example.php`, o definir las variables de entorno `MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`, `MAIL_PASSWORD`, `MAIL_ENCRYPTION`, `MAIL_FROM_ADDRESS` y `MAIL_FROM_NAME`. Para Gmail usa `smtp.gmail.com`, puerto `587`, cifrado `tls`, tu dirección Gmail y una contraseña de aplicación de Google; no uses la contraseña normal de la cuenta. Activa la verificación en dos pasos de Google y genera una contraseña de aplicación desde la configuración de seguridad de esa cuenta.

## Despliegue

No se incluye configuración automática de publicación: primero confirma el proveedor, la ruta pública y los requisitos de PHP. En producción, deja `APP_ENV=production`, configura las credenciales de la base de datos y verifica HTTPS. La carpeta de registros se crea durante la ejecución y no debe publicarse.

Las rutas web se ajustan automáticamente a la ubicación de la aplicación: por ejemplo, `/SistemaEstudiantes` en XAMPP y `/` cuando se publica en la raíz del dominio. Si se instala bajo otra ruta, se puede definir `APP_BASE_PATH` con esa ruta (por ejemplo, `/mi-aplicacion`).

## PDF

El sistema usa **TCPDF**, incluido en `includes/tcpdf/` (versión 6.10.0). Se conserva su licencia junto con la biblioteca; no elimines esa carpeta ni sus fuentes requeridas.
