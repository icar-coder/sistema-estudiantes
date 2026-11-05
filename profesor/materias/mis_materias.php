<?php
require_once '../../includes/auth.php';
require_once '../../includes/database.php';
checkAuth();
checkRole(['profesor']);

$profesor_id = $_SESSION['relacion_id'];

// Obtener las materias asignadas al profesor
try {
    $stmt = $pdo->prepare("
        SELECT psm.*, 
               s.nombre as seccion_nombre,
               m.nombre as materia_nombre,
               m.codigo as materia_codigo,
               ae.nombre as año_escolar,
               COUNT(DISTINCT i.id) as total_estudiantes
        FROM profesores_secciones_materias psm
        JOIN secciones s ON psm.seccion_id = s.id
        JOIN materias m ON psm.materia_id = m.id
        JOIN años_escolares ae ON psm.año_escolar_id = ae.id
        LEFT JOIN inscripciones i ON s.id = i.seccion_id AND i.año_escolar_id = ae.id AND i.status = 'activo'
        WHERE psm.profesor_id = ? AND ae.estado = 'activo'
        GROUP BY psm.id, s.nombre, m.nombre, ae.nombre
        ORDER BY ae.nombre DESC, s.nombre, m.nombre
    ");
    $stmt->execute([$profesor_id]);
    $materias_asignadas = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $materias_asignadas = [];
}
?>

<?php include '../../includes/header.php'; ?>
    <?php include '../../includes/sidebar_profesor.php'; ?>
    
    <div class="content-wrapper">
    <div class="container-fluid p-4">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h2><i class="fas fa-book me-2"></i>Mis Materias Asignadas</h2>
        </div>

        <?php if ($materias_asignadas): ?>
            <div class="row">
                <?php foreach ($materias_asignadas as $materia): ?>
                    <div class="col-md-6 col-lg-4 mb-4">
                        <div class="card h-100 shadow-sm">
                            <div class="card-header bg-primary text-white">
                                <h5 class="card-title mb-0"><?php echo htmlspecialchars($materia['materia_nombre']); ?></h5>
                                <small><?php echo htmlspecialchars($materia['materia_codigo']); ?></small>
                            </div>
                            <div class="card-body">
                                <div class="mb-3">
                                    <strong><i class="fas fa-door-open me-2"></i>Sección:</strong>
                                    <?php echo htmlspecialchars($materia['seccion_nombre']); ?>
                                </div>
                                <div class="mb-3">
                                    <strong><i class="fas fa-calendar me-2"></i>Año Escolar:</strong>
                                    <?php echo htmlspecialchars($materia['año_escolar']); ?>
                                </div>
                                <div class="mb-3">
                                    <strong><i class="fas fa-users me-2"></i>Estudiantes:</strong>
                                    <span class="badge bg-info"><?php echo $materia['total_estudiantes']; ?> estudiantes</span>
                                </div>
                                <?php if ($materia['horario']): ?>
                                <div class="mb-3">
                                    <strong><i class="fas fa-clock me-2"></i>Horario:</strong>
                                    <?php echo htmlspecialchars($materia['horario']); ?>
                                </div>
                                <?php endif; ?>
                            </div>
                            <div class="card-footer bg-transparent">
                                <div class="d-grid gap-2">
                                    <a href="../evaluaciones/gestionar.php?seccion_id=<?php echo $materia['seccion_id']; ?>&materia_id=<?php echo $materia['materia_id']; ?>" 
                                       class="btn btn-outline-primary btn-sm">
                                        <i class="fas fa-edit me-1"></i>Gestionar Notas
                                    </a>
                                    <a href="../asistencias/registrar.php?seccion_id=<?php echo $materia['seccion_id']; ?>&materia_id=<?php echo $materia['materia_id']; ?>" 
                                       class="btn btn-outline-success btn-sm">
                                        <i class="fas fa-clipboard-list me-1"></i>Registrar Asistencias
                                    </a>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <div class="alert alert-info text-center">
                <i class="fas fa-info-circle fa-2x mb-3"></i>
                <h4>No tienes materias asignadas</h4>
                <p class="mb-0">Contacta con el administrador para que te asignen materias y secciones.</p>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php include '../../includes/footer.php'; ?>