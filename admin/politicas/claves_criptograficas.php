<?php
require_once '../../includes/auth.php';
checkAuth();
checkRole(['admin']);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Políticas de seguridad</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="../../css/style.css" rel="stylesheet">
</head>
<body>
    <div class="container py-5">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h2 class="mb-1"><i class="fas fa-shield-alt me-2"></i>Políticas de seguridad</h2>
                <p class="text-muted mb-0">Medidas mínimas para proteger la información del sistema.</p>
            </div>
            <a href="../dashboard.php" class="btn btn-outline-secondary">Volver</a>
        </div>

        <div class="row g-4">
            <div class="col-md-6">
                <div class="card h-100 border-0 shadow-sm">
                    <div class="card-body">
                        <h5 class="text-primary"><i class="fas fa-key me-2"></i>Contraseñas</h5>
                        <ul class="mb-0">
                            <li>Las claves se almacenan con hash seguro mediante <code>password_hash()</code>.</li>
                            <li>La longitud mínima recomendada es de 8 caracteres.</li>
                            <li>No se deben reutilizar contraseñas previas ni compartir credenciales.</li>
                        </ul>
                    </div>
                </div>
            </div>

            <div class="col-md-6">
                <div class="card h-100 border-0 shadow-sm">
                    <div class="card-body">
                        <h5 class="text-success"><i class="fas fa-lock me-2"></i>Sesiones</h5>
                        <ul class="mb-0">
                            <li>Las sesiones se validan con caducidad por inactividad.</li>
                            <li>Se regeneran identificadores del usuario cuando se autentica.</li>
                            <li>Las cookies se configuran con atributos de seguridad básicos.</li>
                        </ul>
                    </div>
                </div>
            </div>

            <div class="col-md-6">
                <div class="card h-100 border-0 shadow-sm">
                    <div class="card-body">
                        <h5 class="text-warning"><i class="fas fa-user-shield me-2"></i>Recuperación</h5>
                        <ul class="mb-0">
                            <li>Los enlaces de recuperación expiran en 1 hora.</li>
                            <li>Los tokens se almacenan y se eliminan al completar el cambio.</li>
                            <li>Se bloquean los accesos repetidos por varios intentos fallidos.</li>
                        </ul>
                    </div>
                </div>
            </div>

            <div class="col-md-6">
                <div class="card h-100 border-0 shadow-sm">
                    <div class="card-body">
                        <h5 class="text-danger"><i class="fas fa-database me-2"></i>Respaldos</h5>
                        <ul class="mb-0">
                            <li>Se recomienda realizar respaldos del sistema antes de cambios críticos.</li>
                            <li>Los archivos SQL deben mantenerse fuera del directorio público.</li>
                            <li>Se recomienda verificar la integridad del respaldo antes de restaurar.</li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
