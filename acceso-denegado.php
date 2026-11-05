<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Acceso Denegado</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
</head>
<body class="bg-light">
    <div class="container mt-5">
        <div class="row justify-content-center">
            <div class="col-md-6 text-center">
                <div class="card shadow">
                    <div class="card-body py-5">
                        <i class="fas fa-exclamation-triangle fa-5x text-warning mb-4"></i>
                        <h2 class="text-danger">Acceso Denegado</h2>
                        <p class="lead">No tienes permisos para acceder a esta página.</p>
                        <p class="text-muted">Contacta al administrador si necesitas acceso.</p>
                        <div class="mt-4">
                            <a href="index.php" class="btn btn-primary me-2">
                                <i class="fas fa-home me-1"></i>Ir al Inicio
                            </a>
                            <a href="logout.php" class="btn btn-outline-secondary">
                                <i class="fas fa-sign-out-alt me-1"></i>Cerrar Sesión
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</body>
</html>