<?php
require_once '../../includes/auth.php';
require_once '../../includes/database.php';
checkAuth();
checkRole(['admin']);

if (!isset($_GET['seccion_id']) || empty($_GET['seccion_id'])) {
    error_log("Error en " . __FILE__ . ": No se proporcionó ID de sección.");
    $_SESSION['error_sistema'] = "Ocurrió un error al procesar la solicitud. El equipo técnico fue notificado.";
    header('Location: ' . ($_SERVER['HTTP_REFERER'] ?? app_url()));
    exit;
}

$seccion_id = $_GET['seccion_id'];

// Obtener estudiantes de la sección
function obtenerEstudiantesSeccion($seccion_id, $pdo) {
    try {
        $stmt = $pdo->prepare("
            SELECT i.id as inscripcion_id, e.*, s.nombre as seccion_nombre, ae.nombre as año_escolar
            FROM inscripciones i
            JOIN estudiantes e ON i.estudiante_id = e.id
            JOIN secciones s ON i.seccion_id = s.id
            JOIN años_escolares ae ON i.año_escolar_id = ae.id
            WHERE i.seccion_id = ? AND i.status = 'activo'
            ORDER BY e.apellido, e.nombre
        ");
        $stmt->execute([$seccion_id]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Error al obtener estudiantes: " . $e->getMessage());
        return [];
    }
}

$estudiantes = obtenerEstudiantesSeccion($seccion_id, $pdo);
?>

<?php include '../../includes/header.php'; ?>

<div class="content-wrapper" style="padding: 20px;">
    <?php include '../../includes/sidebar_admin.php'; ?>
    
    <div class="container-fluid">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h2>Generar Constancias de Matrícula</h2>
            <a href="gestionar.php" class="btn btn-secondary">
                <i class="fas fa-arrow-left me-2"></i>Volver
            </a>
        </div>

        <div class="card">
            <div class="card-header">
                <h5 class="card-title mb-0">Estudiantes de la Sección</h5>
            </div>
            <div class="card-body">
                <?php if ($estudiantes): ?>
                    <div class="alert alert-info">
                        <i class="fas fa-info-circle me-2"></i>
                        Seleccione un estudiante para generar su constancia de matrícula individual.
                    </div>
                    
                    <div class="table-responsive">
                        <table class="table table-striped table-hover">
                            <thead class="table-dark">
                                <tr>
                                    <th>Cédula Escolar</th>
                                    <th>Nombre Completo</th>
                                    <th>Sección</th>
                                    <th>Año Escolar</th>
                                    <th>Acciones</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($estudiantes as $estudiante): ?>
                                    <tr>
                                        <td><strong><?php echo htmlspecialchars($estudiante['cedula_escolar']); ?></strong></td>
                                        <td><?php echo htmlspecialchars($estudiante['nombre'] . ' ' . $estudiante['apellido']); ?></td>
                                        <td><span class="badge bg-primary"><?php echo htmlspecialchars($estudiante['seccion_nombre']); ?></span></td>
                                        <td><span class="badge bg-info"><?php echo htmlspecialchars($estudiante['año_escolar']); ?></span></td>
                                        <td>
                                            <a href="generar_constancia.php?inscripcion_id=<?php echo $estudiante['inscripcion_id']; ?>" 
                                               class="btn btn-primary btn-sm" 
                                               target="_blank">
                                                <i class="fas fa-file-pdf me-1"></i>Descargar Constancia
                                            </a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <div class="text-center py-4">
                        <i class="fas fa-users-slash fa-3x text-muted mb-3"></i>
                        <h5>No hay estudiantes en esta sección</h5>
                        <p class="text-muted">No se encontraron estudiantes activos en esta sección.</p>
                        <a href="gestionar.php" class="btn btn-primary">
                            <i class="fas fa-arrow-left me-2"></i>Volver a Gestión de Secciones
                        </a>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php include '../../includes/footer.php'; ?>
