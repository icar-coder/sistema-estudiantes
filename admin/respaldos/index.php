<?php
require_once '../../includes/auth.php';
require_once '../../includes/database.php';
checkAuth();
checkRole(['admin']);
requireOnlineFeature($pdo, 'Los respaldos y restauración desde el panel');

$mensaje = $_SESSION['mensaje_backup'] ?? '';
$tipo = $_SESSION['tipo_mensaje_backup'] ?? 'success';
unset($_SESSION['mensaje_backup'], $_SESSION['tipo_mensaje_backup']);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Respaldos</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="../../css/style.css" rel="stylesheet">
</head>
<body>
    <?php require_once '../../includes/navbar.php'; ?>

    <div class="container py-4">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h2 class="mb-1"><i class="fas fa-database me-2"></i>Respaldos de base de datos</h2>
                <p class="text-muted mb-0">Crea copias de seguridad y restaura el sistema cuando sea necesario.</p>
            </div>
            <a href="../dashboard.php" class="btn btn-outline-secondary">Volver</a>
        </div>

        <?php if ($mensaje): ?>
            <div class="alert alert-<?php echo htmlspecialchars($tipo); ?>"><?php echo htmlspecialchars($mensaje); ?></div>
        <?php endif; ?>

        <div class="row g-4">
            <div class="col-lg-4">
                <div class="card h-100 border-0 shadow-sm">
                    <div class="card-body">
                        <div class="text-primary mb-3"><i class="fas fa-download fa-2x"></i></div>
                        <h5>Crear respaldo</h5>
                        <p class="text-muted">Genera un archivo .sql completo de la base de datos del sistema.</p>
                        <a href="crear_backup.php" class="btn btn-primary w-100">Generar copia</a>
                    </div>
                </div>
            </div>

            <div class="col-lg-4">
                <div class="card h-100 border-0 shadow-sm">
                    <div class="card-body">
                        <div class="text-warning mb-3"><i class="fas fa-upload fa-2x"></i></div>
                        <h5>Restaurar copia</h5>
                        <p class="text-muted">Sube un respaldo .sql para devolver el sistema a un punto anterior.</p>
                        <a href="restaurar.php" class="btn btn-warning w-100 text-dark">Restaurar</a>
                    </div>
                </div>
            </div>

            <div class="col-lg-4">
                <div class="card h-100 border-0 shadow-sm">
                    <div class="card-body">
                        <div class="text-success mb-3"><i class="fas fa-user-shield fa-2x"></i></div>
                        <h5>Libera bloqueos</h5>
                        <p class="text-muted">Reinicia los bloqueos temporales por intentos fallidos en los usuarios.</p>
                        <a href="liberar_bloqueos.php" class="btn btn-success w-100">Ejecutar</a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
