<?php
require_once '../../includes/auth.php';
require_once '../../includes/database.php';
checkAuth();
checkRole(['admin']);

$mensaje = '';
$tipo = 'info';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['sql_file']) && is_uploaded_file($_FILES['sql_file']['tmp_name'])) {
    $tmpFile = $_FILES['sql_file']['tmp_name'];
    $name = basename($_FILES['sql_file']['name']);
    $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));

    if ($extension !== 'sql') {
        $mensaje = 'Solo se permiten archivos .sql.';
        $tipo = 'danger';
    } else {
        $command = '"C:\\xampp\\mysql\\bin\\mysql.exe" --user=root --database=sistema_estudiantes < "' . str_replace('\\', '/', $tmpFile) . '" 2>&1';
        exec($command, $output, $exitCode);

        if ($exitCode === 0) {
            $mensaje = 'La base de datos se restauró correctamente desde el archivo "' . htmlspecialchars($name) . '".';
            $tipo = 'success';
        } else {
            $mensaje = 'No se pudo restaurar la copia de seguridad. Revisa la estructura del archivo SQL o verifica la ruta de MySQL.';
            $tipo = 'danger';
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
                <p class="text-muted">Selecciona un archivo .sql para recuperar la información del sistema.</p>

                <?php if ($mensaje): ?>
                    <div class="alert alert-<?php echo htmlspecialchars($tipo); ?>"><?php echo htmlspecialchars($mensaje); ?></div>
                <?php endif; ?>

                <form method="POST" enctype="multipart/form-data">
                    <div class="mb-3">
                        <label for="sql_file" class="form-label">Archivo SQL</label>
                        <input class="form-control" type="file" id="sql_file" name="sql_file" accept=".sql" required>
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
