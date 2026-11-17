<?php
// admin/areas_aprendizaje/gestionar.php
require_once '../../includes/auth.php';
require_once '../../includes/database.php';
checkAuth();
checkRole(['admin']);

$mensaje = '';
$tipo_mensaje = '';

// Procesar cambio de estado
if (isset($_GET['cambiar_estado'])) {
    $id = $_GET['cambiar_estado'];
    $nuevo_estado = $_GET['estado'];
    
    try {
        $stmt = $pdo->prepare("UPDATE areas_aprendizaje SET estado = ? WHERE id = ?");
        $stmt->execute([$nuevo_estado, $id]);
        
        $mensaje = "Estado del área actualizado correctamente";
        $tipo_mensaje = "success";
    } catch (PDOException $e) {
        $mensaje = "Error al actualizar área: " . $e->getMessage();
        $tipo_mensaje = "danger";
    }
}

// Obtener áreas de aprendizaje (CONSULTA CORREGIDA)
try {
    $stmt = $pdo->query("
        SELECT a.*, 
               COUNT(DISTINCT ha.id) as total_horarios,
               COUNT(DISTINCT ha.seccion_id) as total_secciones
        FROM areas_aprendizaje a
        LEFT JOIN horarios_asignaciones ha ON a.id = ha.area_id
        GROUP BY a.id
        ORDER BY a.nivel, a.nombre
    ");
    $areas = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $areas = [];
    $mensaje = "Error al cargar áreas: " . $e->getMessage();
    $tipo_mensaje = "danger";
}

// Calcular estadísticas
$total_areas = count($areas);
$total_activas = 0;
$total_primaria = 0;
$total_secciones = 0;

foreach ($areas as $area) {
    if ($area['estado'] == 'activa') {
        $total_activas++;
    }
    if ($area['nivel'] == 'primaria') {
        $total_primaria++;
    }
    $total_secciones += $area['total_secciones'];
}
?>

<?php include '../../includes/header.php'; ?>

<div class="content-wrapper" style="padding: 20px;">
    <?php include '../../includes/sidebar_admin.php'; ?>
    
   <div class="d-flex justify-content-between align-items-center mb-4">
    <h2>Gestión de Áreas de Aprendizaje</h2>
    <div>
        <a href="gestionar_indicadores.php" class="btn btn-info me-2">
            <i class="fas fa-list-check me-2"></i>Gestionar Indicadores
        </a>
        <a href="agregar.php" class="btn btn-primary">
            <i class="fas fa-plus me-2"></i>Agregar Área
        </a>
    </div>
</div>

    <?php if ($mensaje): ?>
        <div class="alert alert-<?php echo $tipo_mensaje; ?> alert-dismissible fade show" role="alert">
            <?php echo $mensaje; ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <div class="card">
        <div class="card-header">
            <h5 class="card-title mb-0">Lista de Áreas de Aprendizaje</h5>
        </div>
        <div class="card-body">
            <?php if ($areas): ?>
                <div class="table-responsive">
                    <table class="table table-striped table-hover">
                        <thead>
                            <tr>
                                <th>Código</th>
                                <th>Nombre</th>
                                <th>Descripción</th>
                                <th>Nivel</th>
                                <th>Horarios</th>
                                <th>Secciones</th>
                                <th>Estado</th>
                                <th>Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($areas as $area): ?>
                            <tr>
                                <td>
                                    <span class="badge bg-primary"><?php echo htmlspecialchars($area['codigo']); ?></span>
                                </td>
                                <td>
                                    <strong><?php echo htmlspecialchars($area['nombre']); ?></strong>
                                </td>
                                <td>
                                    <small class="text-muted"><?php echo htmlspecialchars($area['descripcion'] ?? 'Sin descripción'); ?></small>
                                </td>
                                <td>
                                    <?php 
                                    $badge_nivel = [
                                        'inicial' => 'bg-info',
                                        'primaria' => 'bg-success', 
                                        'media' => 'bg-warning'
                                    ];
                                    $texto_nivel = [
                                        'inicial' => 'Inicial',
                                        'primaria' => 'Primaria',
                                        'media' => 'Media'
                                    ];
                                    ?>
                                    <span class="badge <?php echo $badge_nivel[$area['nivel']] ?? 'bg-secondary'; ?>">
                                        <?php echo $texto_nivel[$area['nivel']] ?? 'Desconocido'; ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="badge bg-secondary"><?php echo $area['total_horarios']; ?></span>
                                </td>
                                <td>
                                    <span class="badge bg-info"><?php echo $area['total_secciones']; ?></span>
                                </td>
                                <td>
                                    <?php if ($area['estado'] == 'activa'): ?>
                                        <span class="badge bg-success">Activa</span>
                                    <?php else: ?>
                                        <span class="badge bg-secondary">Inactiva</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="btn-group btn-group-sm">
                                        <a href="editar.php?id=<?php echo $area['id']; ?>" 
                                           class="btn btn-outline-primary" title="Editar">
                                            <i class="fas fa-edit"></i>
                                        </a>
                                        
                                        <?php if ($area['estado'] == 'activa'): ?>
                                            <a href="gestionar.php?cambiar_estado=<?php echo $area['id']; ?>&estado=inactiva" 
                                               class="btn btn-outline-warning" title="Desactivar"
                                               onclick="return confirm('¿Desactivar esta área?')">
                                                <i class="fas fa-pause"></i>
                                            </a>
                                        <?php else: ?>
                                            <a href="gestionar.php?cambiar_estado=<?php echo $area['id']; ?>&estado=activa" 
                                               class="btn btn-outline-success" title="Activar"
                                               onclick="return confirm('¿Activar esta área?')">
                                                <i class="fas fa-play"></i>
                                            </a>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                
                <!-- Estadísticas -->
                <div class="row mt-4">
                    <div class="col-md-3">
                        <div class="card bg-primary text-white">
                            <div class="card-body text-center">
                                <h3><?php echo $total_areas; ?></h3>
                                <p class="mb-0">Total Áreas</p>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card bg-success text-white">
                            <div class="card-body text-center">
                                <h3><?php echo $total_activas; ?></h3>
                                <p class="mb-0">Activas</p>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card bg-info text-white">
                            <div class="card-body text-center">
                                <h3><?php echo $total_primaria; ?></h3>
                                <p class="mb-0">Primaria</p>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card bg-warning text-white">
                            <div class="card-body text-center">
                                <h3><?php echo $total_secciones; ?></h3>
                                <p class="mb-0">Secciones Activas</p>
                            </div>
                        </div>
                    </div>
                </div>
                
            <?php else: ?>
                <div class="text-center py-4">
                    <i class="fas fa-book fa-3x text-muted mb-3"></i>
                    <h5>No hay áreas de aprendizaje registradas</h5>
                    <p class="text-muted">Comienza agregando las áreas básicas para el sistema.</p>
                    <a href="agregar.php" class="btn btn-primary me-2">
                        <i class="fas fa-plus me-2"></i>Agregar Primera Área
                    </a>
                    <a href="agregar.php?cargar_predefinidas=1" class="btn btn-success">
                        <i class="fas fa-bolt me-2"></i>Cargar Predefinidas
                    </a>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php include '../../includes/footer.php'; ?>
