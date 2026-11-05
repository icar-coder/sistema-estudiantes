<?php
require_once '../../includes/auth.php';
require_once '../../includes/database.php';
checkAuth();
checkRole(['profesor']);

$profesor_id = $_SESSION['relacion_id'];

// Obtener información del profesor
try {
    $stmt = $pdo->prepare("
        SELECT p.*, u.username, u.email as usuario_email
        FROM profesores p
        JOIN usuarios u ON p.id = u.relacion_id AND u.rol = 'profesor'
        WHERE p.id = ?
    ");
    $stmt->execute([$profesor_id]);
    $profesor = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $profesor = null;
}

// Obtener materias asignadas actualmente
try {
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as total_materias
        FROM profesores_secciones_materias psm
        JOIN años_escolares ae ON psm.año_escolar_id = ae.id
        WHERE psm.profesor_id = ? AND ae.estado = 'activo'
    ");
    $stmt->execute([$profesor_id]);
    $total_materias = $stmt->fetch(PDO::FETCH_ASSOC)['total_materias'];
} catch (PDOException $e) {
    $total_materias = 0;
}
?>

<?php include '../../includes/header.php'; ?>

    <?php include '../../includes/sidebar_profesor.php'; ?>
    
    <div class="content-wrapper">
    <div class="container-fluid p-4">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h2><i class="fas fa-user me-2"></i>Mi Perfil</h2>
        </div>

        <?php if ($profesor): ?>
            <div class="row">
                <!-- Información Personal -->
                <div class="col-md-8">
                    <div class="card mb-4">
                        <div class="card-header bg-primary text-white">
                            <h5 class="card-title mb-0"><i class="fas fa-id-card me-2"></i>Información Personal</h5>
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <strong><i class="fas fa-user me-2"></i>Nombre:</strong>
                                        <?php echo htmlspecialchars($profesor['nombre'] . ' ' . $profesor['apellido']); ?>
                                    </div>
                                    <div class="mb-3">
                                        <strong><i class="fas fa-id-card me-2"></i>Cédula:</strong>
                                        <?php echo htmlspecialchars($profesor['cedula'] ?? 'No especificada'); ?>
                                    </div>
                                    <div class="mb-3">
                                        <strong><i class="fas fa-envelope me-2"></i>Email:</strong>
                                        <?php echo htmlspecialchars($profesor['usuario_email'] ?? $profesor['email'] ?? 'No especificado'); ?>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <strong><i class="fas fa-phone me-2"></i>Teléfono:</strong>
                                        <?php echo htmlspecialchars($profesor['telefono'] ?? 'No especificado'); ?>
                                    </div>
                                    <div class="mb-3">
                                        <strong><i class="fas fa-briefcase me-2"></i>Especialidad:</strong>
                                        <?php echo htmlspecialchars($profesor['especialidad'] ?? 'No especificada'); ?>
                                    </div>
                                    <div class="mb-3">
                                        <strong><i class="fas fa-graduation-cap me-2"></i>Grado Académico:</strong>
                                        <?php echo htmlspecialchars($profesor['grado_academico'] ?? 'No especificado'); ?>
                                    </div>
                                </div>
                            </div>
                            
                            <?php if ($profesor['direccion']): ?>
                            <div class="row">
                                <div class="col-12">
                                    <strong><i class="fas fa-map-marker-alt me-2"></i>Dirección:</strong>
                                    <?php echo htmlspecialchars($profesor['direccion']); ?>
                                </div>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Estadísticas y Acciones -->
                <div class="col-md-4">
                    <div class="card mb-4">
                        <div class="card-header bg-success text-white">
                            <h5 class="card-title mb-0"><i class="fas fa-chart-bar me-2"></i>Estadísticas</h5>
                        </div>
                        <div class="card-body text-center">
                            <div class="mb-4">
                                <i class="fas fa-book fa-3x text-primary mb-3"></i>
                                <h3><?php echo $total_materias; ?></h3>
                                <p class="text-muted">Materias Asignadas</p>
                            </div>
                            <div class="mb-3">
                                <i class="fas fa-user-check fa-2x text-success me-2"></i>
                                <span>Estado: </span>
                                <span class="badge bg-success">Activo</span>
                            </div>
                        </div>
                    </div>

                    <div class="card">
                        <div class="card-header bg-info text-white">
                            <h5 class="card-title mb-0"><i class="fas fa-cogs me-2"></i>Acciones Rápidas</h5>
                        </div>
                        <div class="card-body">
                            <div class="d-grid gap-2">
                                <a href="../materias/mis_materias.php" class="btn btn-outline-primary">
                                    <i class="fas fa-book me-2"></i>Ver Mis Materias
                                </a>
                                <a href="../evaluaciones/gestionar.php" class="btn btn-outline-success">
                                    <i class="fas fa-edit me-2"></i>Gestionar Evaluaciones
                                </a>
                                <a href="../asistencias/registrar.php" class="btn btn-outline-warning">
                                    <i class="fas fa-clipboard-list me-2"></i>Registrar Asistencias
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Información Profesional -->
            <div class="card">
                <div class="card-header bg-secondary text-white">
                    <h5 class="card-title mb-0"><i class="fas fa-briefcase me-2"></i>Información Profesional</h5>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-4">
                            <strong><i class="fas fa-calendar-alt me-2"></i>Fecha de Contratación:</strong>
                            <?php echo $profesor['fecha_contratacion'] ? date('d/m/Y', strtotime($profesor['fecha_contratacion'])) : 'No especificada'; ?>
                        </div>
                        <div class="col-md-4">
                            <strong><i class="fas fa-map-marker-alt me-2"></i>Lugar de Nacimiento:</strong>
                            <?php echo htmlspecialchars($profesor['lugar_nacimiento'] ?? 'No especificado'); ?>
                        </div>
                        <div class="col-md-4">
                            <strong><i class="fas fa-birthday-cake me-2"></i>Fecha de Nacimiento:</strong>
                            <?php echo $profesor['fecha_nacimiento'] ? date('d/m/Y', strtotime($profesor['fecha_nacimiento'])) : 'No especificada'; ?>
                        </div>
                    </div>
                </div>
            </div>

        <?php else: ?>
            <div class="alert alert-danger">
                <i class="fas fa-exclamation-triangle me-2"></i>
                No se pudo cargar la información del perfil. Contacta con el administrador.
            </div>
        <?php endif; ?>
    </div>
</div>

<?php include '../../includes/footer.php'; ?>