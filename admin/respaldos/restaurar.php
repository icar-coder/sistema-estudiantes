<?php
require_once '../../includes/auth.php';
require_once '../../includes/database.php';
require_once '../../includes/database_backup.php';
checkAuth();
checkRole(['admin']);

$mensaje = '';
$tipo = 'info';
$maxFileSize = 50 * 1024 * 1024;
if (empty($_SESSION['csrf_restaurar_backup'])) {
    $_SESSION['csrf_restaurar_backup'] = bin2hex(random_bytes(32));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_restaurar_backup'], (string) $_POST['csrf_token'])) {
        $mensaje = 'La solicitud expiró. Recarga la página e inténtalo nuevamente.';
        $tipo = 'danger';
    } elseif (($_POST['confirm_restore'] ?? '') !== 'yes') {
        $mensaje = 'Confirma que entiendes que la restauración puede reemplazar datos actuales.';
        $tipo = 'danger';
    } elseif (
        !isset($_FILES['sql_file'])
        || ($_FILES['sql_file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK
        || !isset($_FILES['sql_file']['tmp_name'])
        || !is_uploaded_file($_FILES['sql_file']['tmp_name'])
    ) {
        $mensaje = 'No se pudo recibir el archivo. Verifica el tamaño permitido por el servidor e inténtalo nuevamente.';
        $tipo = 'danger';
    } else {
        $tmpFile = $_FILES['sql_file']['tmp_name'];
        $name = basename((string) $_FILES['sql_file']['name']);
        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $fileSize = filesize($tmpFile);

        if ($extension !== 'sql') {
            $mensaje = 'Solo se permiten archivos .sql.';
            $tipo = 'danger';
        } elseif ($fileSize === false || $fileSize === 0 || $fileSize > $maxFileSize) {
            $mensaje = 'El archivo debe tener contenido y no superar los 50 MB.';
            $tipo = 'danger';
        } else {
            try {
                restoreBackupContents($pdo, $tmpFile);
                $mensaje = 'La base de datos se restauró correctamente desde el archivo "' . $name . '".';
                $tipo = 'success';
            } catch (Throwable $e) {
                error_log('Error al restaurar respaldo de base de datos: ' . $e->getMessage());
                $mensaje = 'No se pudo completar la restauración. Algunas tablas podrían haberse modificado; revisa el respaldo y los registros del servidor.';
                $tipo = 'danger';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Restaurar respaldo</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="../../css/style.css" rel="stylesheet">
</head>
<body>
    <div class="container py-5">
        <div class="card shadow-sm border-0 mx-auto" style="max-width: 600px;">
            <div class="card-body">
                <h2 class="mb-3"><i class="fas fa-upload me-2"></i>Restaurar respaldo</h2>
                <p class="text-muted">Selecciona un respaldo SQL compatible. La restauración modifica los datos actuales de la base de datos.</p>

                <?php if ($mensaje): ?>
                    <div class="alert alert-<?php echo htmlspecialchars($tipo); ?>"><?php echo htmlspecialchars($mensaje); ?></div>
                <?php endif; ?>

                <form method="POST" enctype="multipart/form-data">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_restaurar_backup'], ENT_QUOTES, 'UTF-8'); ?>">
                    <div class="mb-3">
                        <label for="sql_file" class="form-label">Archivo SQL</label>
                        <input class="form-control" type="file" id="sql_file" name="sql_file" accept=".sql" required>
                    </div>
                    <div class="form-check mb-3">
                        <input class="form-check-input" type="checkbox" id="confirm_restore" name="confirm_restore" value="yes" required>
                        <label class="form-check-label" for="confirm_restore">Entiendo que restaurar puede reemplazar los datos actuales.</label>
                    </div>
                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-warning text-dark"><i class="fas fa-database me-2"></i>Restaurar</button>
                        <a href="index.php" class="btn btn-outline-secondary">Cancelar</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</body>
</html>
