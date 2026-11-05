<?php
require_once '../../includes/auth.php';
require_once '../../includes/database.php';
checkAuth();
checkRole(['admin']);

// Obtener años escolares activos
try {
    $stmt_años = $pdo->query("SELECT * FROM años_escolares WHERE estado = 'activo' ORDER BY nombre DESC");
    $años_escolares = $stmt_años->fetchAll(PDO::FETCH_ASSOC);
    
    // Por defecto, usar el primer año activo
    $año_actual = $años_escolares[0] ?? null;
    $año_id = $año_actual['id'] ?? null;
    
} catch (PDOException $e) {
    error_log("Error al cargar años escolares: " . $e->getMessage());
    $años_escolares = [];
    $año_actual = null;
    $año_id = null;
}

// DEBUG: Ver qué año está activo
error_log("Año activo ID: " . $año_id);

// Obtener grados/secciones del año actual
$grados = [];
if ($año_id) {
    try {
        $stmt = $pdo->prepare("
            SELECT 
                s.id,
                s.nombre AS letra_seccion,
                s.nivel,
                s.tipo_grado,
                s.capacidad,
                g.id AS grado_id,
                g.nombre AS grado_nombre,
                g.numero AS grado_numero,
                g.orden AS grado_orden,
                CONCAT(g.nombre, ' ', s.nombre) AS nombre_completo,
                p.nombre AS profesor_nombre,
                p.apellido AS profesor_apellido,
                COUNT(DISTINCT i.id) as total_estudiantes,
                COUNT(DISTINCT i.estudiante_id) as estudiantes_activos
            FROM secciones s
            JOIN grados g ON s.grado_id = g.id
            LEFT JOIN inscripciones i 
                ON s.id = i.seccion_id 
                AND i.año_escolar_id = ? 
                AND i.status = 'activo'
            LEFT JOIN profesores p ON s.profesor_id = p.id
            WHERE s.año_escolar_id = ?
            GROUP BY s.id, s.nombre, s.nivel, s.tipo_grado, s.capacidad,
                     g.id, g.nombre, g.numero, g.orden,
                     p.nombre, p.apellido
            ORDER BY 
                CASE s.nivel 
                    WHEN 'primaria' THEN 1 
                    WHEN 'secundaria' THEN 2 
                    ELSE 3 
                END,
                g.orden,
                s.nombre
        ");
        $stmt->execute([$año_id, $año_id]);
        $grados = $stmt->fetchAll(PDO::FETCH_ASSOC);

        error_log("Grados cargados para año $año_id: " . count($grados));

    } catch (PDOException $e) {
        error_log("Error al cargar grados: " . $e->getMessage());
        $grados = [];
    }
}

// Obtener lapsos del año actual para mostrar en las cards
$lapsos = [];
if ($año_id) {
    try {
        // CORREGIDO: Usar el nombre correcto de la columna
        $stmt = $pdo->prepare("SELECT * FROM lapsos WHERE año_escolar_id = ? ORDER BY numero");
        $stmt->execute([$año_id]);
        $lapsos = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Si no hay lapsos para este año, mostrar un mensaje
        if (empty($lapsos)) {
            error_log("No hay lapsos configurados para el año escolar ID: $año_id");
        }

    } catch (PDOException $e) {
        error_log("Error al cargar lapsos: " . $e->getMessage());
        $lapsos = [];
    }
}

// DEBUG: Verificar datos
error_log("Año ID: " . $año_id);
error_log("Grados encontrados: " . count($grados));
error_log("Lapsos encontrados: " . count($lapsos));
?>

<?php include '../../includes/header.php'; ?>
<?php include '../../includes/sidebar_admin.php'; ?>

<div class="container-fluid">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2>
            <i class="fas fa-chart-bar me-2"></i>Evaluación por Literales
        </h2>
        <?php if ($año_actual): ?>
            <div class="alert alert-info mb-0 py-2">
                <i class="fas fa-calendar-alt me-2"></i>
                <strong>Año Escolar:</strong> <?php echo htmlspecialchars($año_actual['nombre']); ?>
                | <strong>Período:</strong> 
                <?php echo date('d/m/Y', strtotime($año_actual['fecha_inicio'])); ?> - 
                <?php echo date('d/m/Y', strtotime($año_actual['fecha_fin'])); ?>
            </div>
        <?php endif; ?>
    </div>

    <?php if (!$año_actual): ?>
        <div class="alert alert-warning">
            <i class="fas fa-exclamation-triangle me-2"></i>
            No hay un año escolar activo. Por favor, active un año escolar primero.
        </div>
    <?php elseif (empty($grados)): ?>
        <div class="alert alert-info">
            <i class="fas fa-info-circle me-2"></i>
            No hay grados/secciones configurados para el año escolar actual.
        </div>
    <?php else: ?>
        <!-- Cards de Grados -->
        <div class="row">
            <?php foreach ($grados as $grado): ?>
                <div class="col-xl-3 col-lg-4 col-md-6 mb-4">
                    <div class="card h-100 shadow-sm border-0 grado-card" 
                         style="cursor: pointer; transition: transform 0.2s;"
                         onclick="window.location.href='seccion.php?seccion_id=<?php echo $grado['id']; ?>'"
                         onmouseover="this.style.transform='translateY(-5px)'" 
                         onmouseout="this.style.transform='translateY(0)'">
                        
                        <div class="card-header bg-primary text-white py-3">
                            <div class="d-flex justify-content-between align-items-center">
                                <h5 class="card-title mb-0">
                                    <i class="fas fa-graduation-cap me-2"></i>
                                    <?php echo htmlspecialchars($grado['nombre_completo']); ?>
                                </h5>
                                <span class="badge bg-light text-dark">
                                    <?php echo ucfirst($grado['nivel']); ?>
                                </span>
                            </div>
                        </div>
                        
                        <div class="card-body">
                            <!-- Información de estudiantes -->
                            <div class="row text-center mb-3">
                                <div class="col-6">
                                    <div class="border-end">
                                        <h4 class="text-primary mb-0"><?php echo $grado['estudiantes_activos']; ?></h4>
                                        <small class="text-muted">Estudiantes</small>
                                    </div>
                                </div>
                                <div class="col-6">
                                    <div>
                                        <h4 class="text-success mb-0"><?php echo $grado['total_estudiantes']; ?></h4>
                                        <small class="text-muted">Total Inscritos</small>
                                    </div>
                                </div>
                            </div>

                            <?php if (!empty($grado['profesor_nombre'])): ?>
                                <div class="mt-2">
                                    <small class="text-muted">
                                        <i class="fas fa-chalkboard-teacher me-1"></i>
                                        <?php echo htmlspecialchars($grado['profesor_nombre'] . ' ' . $grado['profesor_apellido']); ?>
                                    </small>
                                </div>
                            <?php endif; ?>
                            
                            <!-- Lapsos disponibles -->
                            <div class="mb-3">
                                <small class="text-muted d-block mb-2">
                                    <i class="fas fa-calendar me-1"></i>Lapsos:
                                </small>
                                <div class="d-flex flex-wrap gap-1">
                                    <?php foreach ($lapsos as $lapso): ?>
                                        <span class="badge bg-secondary">Lapso <?php echo $lapso['numero']; ?></span>
                                    <?php endforeach; ?>
                                    <span class="badge bg-warning text-dark">Final</span>
                                </div>
                            </div>
                            
                            <!-- Progreso de evaluación (placeholder) -->
                            <div class="progress mb-2" style="height: 8px;">
                                <div class="progress-bar bg-success" style="width: 0%"></div>
                            </div>
                            <small class="text-muted">Evaluación: Por iniciar</small>
                        </div>
                        
                        <div class="card-footer bg-light">
                            <div class="text-center">
                                <small class="text-primary">
                                    <i class="fas fa-arrow-right me-1"></i>
                                    Hacer clic para evaluar
                                </small>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <!-- Resumen General -->
        <div class="row mt-4">
            <div class="col-12">
                <div class="card">
                    <div class="card-header">
                        <h6 class="card-title mb-0">
                            <i class="fas fa-chart-pie me-2"></i>Resumen General
                        </h6>
                    </div>
                    <div class="card-body">
                        <div class="row text-center">
                            <div class="col-md-3">
                                <div class="border rounded p-3">
                                    <h3 class="text-primary mb-0"><?php echo count($grados); ?></h3>
                                    <small class="text-muted">Grados/Secciones</small>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="border rounded p-3">
                                    <h3 class="text-success mb-0">
                                        <?php echo array_sum(array_column($grados, 'estudiantes_activos')); ?>
                                    </h3>
                                    <small class="text-muted">Estudiantes Activos</small>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="border rounded p-3">
                                    <h3 class="text-info mb-0"><?php echo count($lapsos); ?></h3>
                                    <small class="text-muted">Lapsos Académicos</small>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="border rounded p-3">
                                    <h3 class="text-warning mb-0">0%</h3>
                                    <small class="text-muted">Evaluación Completada</small>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>
</div>

<style>
.grado-card:hover {
    box-shadow: 0 4px 15px rgba(0,0,0,0.1) !important;
}

.card-header {
    border-bottom: 2px solid rgba(0,0,0,0.1);
}

.progress {
    background-color: #e9ecef;
    border-radius: 10px;
}
</style>

<?php include '../../includes/footer.php'; ?>