<?php
require_once '../includes/auth.php';
require_once '../includes/database.php';
checkAuth();
checkRole(['admin']);
$offlineFeatureNotice = $_SESSION['offline_feature_notice'] ?? '';
unset($_SESSION['offline_feature_notice']);

// Consultas para las estadísticas
try {
    // Estadísticas básicas
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM estudiantes");
    $estudiantes_count = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
    
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM profesores");
    $profesores_count = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
    
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM secciones");
    $secciones_count = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
    
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM inscripciones WHERE status = 'activo'");
    $inscripciones_count = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
    
    // Estadísticas para gráficas
    $stmt = $pdo->query("
        SELECT 
            COUNT(*) as total,
            SUM(CASE WHEN estado = 'activo' THEN 1 ELSE 0 END) as activos,
            SUM(CASE WHEN estado = 'inactivo' THEN 1 ELSE 0 END) as inactivos
        FROM usuarios
    ");
    $usuarios_stats = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Distribución de estudiantes por sección
    $stmt = $pdo->query("
        SELECT s.nombre as seccion, COUNT(i.id) as cantidad
        FROM secciones s
        LEFT JOIN inscripciones i ON s.id = i.seccion_id AND i.status = 'activo'
        GROUP BY s.id, s.nombre
        ORDER BY cantidad DESC
        LIMIT 8
    ");
    $estudiantes_seccion = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Últimas inscripciones
    $stmt = $pdo->query("
        SELECT e.nombre, e.apellido, s.nombre as seccion, i.fecha_inscripcion
        FROM inscripciones i
        JOIN estudiantes e ON i.estudiante_id = e.id
        JOIN secciones s ON i.seccion_id = s.id
        WHERE i.status = 'activo'
        ORDER BY i.fecha_inscripcion DESC
        LIMIT 5
    ");
    $ultimas_inscripciones = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Estadísticas de asistencias (si la tabla existe)
    try {
        if (isSqliteDatabase($pdo)) {
            $stmt = $pdo->prepare("
                SELECT estado, COUNT(*) as cantidad
                FROM asistencias
                WHERE fecha >= ?
                GROUP BY estado
            ");
            $stmt->execute([date('Y-m-d', strtotime('-30 days'))]);
        } else {
            $stmt = $pdo->query("
                SELECT estado, COUNT(*) as cantidad
                FROM asistencias
                WHERE fecha >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
                GROUP BY estado
            ");
        }
        $asistencias_stats = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $asistencias_stats = [];
    }
    
    // Año escolar activo
    $stmt = $pdo->prepare("SELECT * FROM años_escolares WHERE estado = 'activo' LIMIT 1");
    $stmt->execute();
    $año_activo = $stmt->fetch(PDO::FETCH_ASSOC);
    $nombre_año_activo = $año_activo ? $año_activo['nombre'] : 'No configurado';
    
    // CHECKLIST DE CONFIGURACIÓN
    $checklist = [];
    
    // 1. Verificar año escolar activo
    $checklist['año_escolar'] = [
        'completado' => (bool)$año_activo,
        'titulo' => 'Año Escolar Activo',
        'descripcion' => 'Configurar el año escolar actual',
        'enlace' => 'años_escolares/gestionar.php',
        'icono' => 'fas fa-calendar-alt'
    ];
    
    // 2. Verificar secciones creadas
    $checklist['secciones'] = [
        'completado' => $secciones_count > 0,
        'titulo' => 'Secciones Creadas',
        'descripcion' => 'Crear secciones para el año actual',
        'enlace' => 'secciones/gestionar.php',
        'icono' => 'fas fa-door-open',
        'cantidad' => $secciones_count
    ];
    
    // 3. Verificar áreas de aprendizaje
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM areas_aprendizaje WHERE estado = 'activa'");
    $areas_count = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
    $checklist['areas_aprendizaje'] = [
        'completado' => $areas_count > 0,
        'titulo' => 'Áreas de Aprendizaje',
        'descripcion' => 'Configurar áreas de aprendizaje',
        'enlace' => 'areas_aprendizaje/gestionar.php',
        'icono' => 'fas fa-book',
        'cantidad' => $areas_count
    ];
    
    // 4. Verificar indicadores
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM areas_indicadores WHERE estado = 'activo'");
    $indicadores_count = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
    $checklist['indicadores'] = [
        'completado' => $indicadores_count > 0,
        'titulo' => 'Indicadores de Evaluación',
        'descripcion' => 'Configurar indicadores por área',
        'enlace' => 'areas_aprendizaje/gestionar_indicadores.php',
        'icono' => 'fas fa-list-check',
        'cantidad' => $indicadores_count
    ];
    
    // 5. Verificar profesores
    $checklist['profesores'] = [
        'completado' => $profesores_count > 0,
        'titulo' => 'Profesores Registrados',
        'descripcion' => 'Registrar profesores en el sistema',
        'enlace' => 'profesores/gestionar.php',
        'icono' => 'fas fa-chalkboard-teacher',
        'cantidad' => $profesores_count
    ];
    
    // 6. Verificar estudiantes
    $checklist['estudiantes'] = [
        'completado' => $estudiantes_count > 0,
        'titulo' => 'Estudiantes Registrados',
        'descripcion' => 'Registrar estudiantes en el sistema',
        'enlace' => 'estudiantes/gestionar.php',
        'icono' => 'fas fa-user-graduate',
        'cantidad' => $estudiantes_count
    ];
    
    // 7. Verificar inscripciones
    $checklist['inscripciones'] = [
        'completado' => $inscripciones_count > 0,
        'titulo' => 'Inscripciones Realizadas',
        'descripcion' => 'Inscribir estudiantes en secciones',
        'enlace' => 'inscripciones/gestionar.php',
        'icono' => 'fas fa-user-check',
        'cantidad' => $inscripciones_count
    ];
    
    // 8. Verificar horarios
    try {
        $stmt = $pdo->query("SELECT COUNT(*) as total FROM horarios_asignaciones");
        $horarios_count = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
    } catch (Exception $e) {
        $horarios_count = 0;
    }
    $checklist['horarios'] = [
        'completado' => $horarios_count > 0,
        'titulo' => 'Horarios Configurados',
        'descripcion' => 'Asignar horarios a las secciones',
        'enlace' => 'horarios/gestionar.php',
        'icono' => 'fas fa-clock',
        'cantidad' => $horarios_count
    ];
    
    // Calcular progreso general
    $total_items = count($checklist);
    $completados = 0;
    foreach ($checklist as $item) {
        if ($item['completado']) {
            $completados++;
        }
    }
    $progreso_porcentaje = $total_items > 0 ? round(($completados / $total_items) * 100) : 0;

} catch (PDOException $e) {
    $estudiantes_count = $profesores_count = $secciones_count = $inscripciones_count = 0;
    $usuarios_stats = ['total' => 0, 'activos' => 0, 'inactivos' => 0];
    $estudiantes_seccion = [];
    $ultimas_inscripciones = [];
    $asistencias_stats = [];
    $nombre_año_activo = 'Error al cargar';
    $checklist = [];
    $progreso_porcentaje = 0;
}

// Preparar datos para gráficas
$datos_graficas = [
    'labels_secciones' => [],
    'data_secciones' => [],
    'labels_asistencias' => [],
    'data_asistencias' => [],
    'colores_asistencias' => []
];

foreach ($estudiantes_seccion as $seccion) {
    $datos_graficas['labels_secciones'][] = $seccion['seccion'];
    $datos_graficas['data_secciones'][] = intval($seccion['cantidad']);
}

// Colores para asistencias
$colores_asistencias = [
    'presente' => 'rgba(75, 192, 192, 0.8)',
    'ausente' => 'rgba(255, 99, 132, 0.8)',
    'tardanza' => 'rgba(255, 206, 86, 0.8)',
    'justificado' => 'rgba(153, 102, 255, 0.8)'
];

foreach ($asistencias_stats as $asistencia) {
    $datos_graficas['labels_asistencias'][] = ucfirst($asistencia['estado']);
    $datos_graficas['data_asistencias'][] = intval($asistencia['cantidad']);
    $datos_graficas['colores_asistencias'][] = $colores_asistencias[$asistencia['estado']] ?? 'rgba(201, 203, 207, 0.8)';
}
?>

<?php 
include '../includes/header.php'; 
include '../includes/sidebar_admin.php'; 
?>

<?php if ($offlineFeatureNotice !== ''): ?>
    <div class="alert alert-warning" role="alert">
        <?php echo htmlspecialchars($offlineFeatureNotice, ENT_QUOTES, 'UTF-8'); ?>
    </div>
<?php endif; ?>

<div class="content-wrapper" style="padding: 20px;">
    <!-- Header -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h2 class="mb-1">Dashboard Administrador</h2>
            <p class="text-muted mb-0">Bienvenido, <?php echo htmlspecialchars($_SESSION['nombre']); ?></p>
            <small class="text-muted">Año escolar: <?php echo htmlspecialchars($nombre_año_activo); ?></small>
        </div>
        <div class="text-end">
            <div class="btn-group">
                <a href="evaluacion_literales/index.php" class="btn btn-primary">
                    <i class="fas fa-chart-line me-2"></i>Reportes
                </a>
                <a href="usuarios/crear.php" class="btn btn-success">
                    <i class="fas fa-user-plus me-2"></i>Nuevo Usuario
                </a>
            </div>
        </div>
    </div>

    <!-- Progreso de Configuración -->
    <div class="row mb-4">
        <div class="col-12">
            <div class="card">
                <div class="card-header bg-info text-white">
                    <div class="d-flex justify-content-between align-items-center">
                        <h5 class="card-title mb-0">
                            <i class="fas fa-tasks me-2"></i>Progreso de Configuración del Año Escolar
                        </h5>
                        <span class="badge bg-light text-dark fs-6"><?php echo $progreso_porcentaje; ?>% Completado</span>
                    </div>
                </div>
                <div class="card-body">
                    <!-- Barra de progreso -->
                    <div class="progress mb-4" style="height: 20px;">
                        <div class="progress-bar progress-bar-striped progress-bar-animated bg-success" 
                             role="progressbar" 
                             style="width: <?php echo $progreso_porcentaje; ?>%"
                             aria-valuenow="<?php echo $progreso_porcentaje; ?>" 
                             aria-valuemin="0" 
                             aria-valuemax="100">
                            <?php echo $progreso_porcentaje; ?>%
                        </div>
                    </div>
                    
                    <!-- Checklist -->
                    <div class="row">
                        <?php foreach ($checklist as $key => $item): ?>
                            <div class="col-md-6 col-lg-4 mb-3">
                                <div class="card checklist-item border-<?php echo $item['completado'] ? 'success' : 'warning'; ?> h-100">
                                    <div class="card-body">
                                        <div class="d-flex align-items-center">
                                            <div class="flex-shrink-0">
                                                <div class="checklist-icon bg-<?php echo $item['completado'] ? 'success' : 'warning'; ?> text-white rounded-circle p-2">
                                                    <i class="<?php echo $item['icono']; ?>"></i>
                                                </div>
                                            </div>
                                            <div class="flex-grow-1 ms-3">
                                                <h6 class="card-title mb-1">
                                                    <?php if ($item['completado']): ?>
                                                        <i class="fas fa-check-circle text-success me-1"></i>
                                                    <?php else: ?>
                                                        <i class="fas fa-exclamation-circle text-warning me-1"></i>
                                                    <?php endif; ?>
                                                    <?php echo $item['titulo']; ?>
                                                </h6>
                                                <p class="card-text small text-muted mb-1"><?php echo $item['descripcion']; ?></p>
                                                <?php if (isset($item['cantidad'])): ?>
                                                    <small class="text-<?php echo $item['completado'] ? 'success' : 'warning'; ?>">
                                                        <strong><?php echo $item['cantidad']; ?></strong> registros
                                                    </small>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="card-footer bg-transparent py-2">
                                        <?php if ($item['completado']): ?>
                                            <span class="badge bg-success">Completado</span>
                                        <?php else: ?>
                                            <a href="<?php echo $item['enlace']; ?>" class="btn btn-sm btn-outline-warning">
                                                <i class="fas fa-cog me-1"></i>Configurar
                                            </a>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    
                    <!-- Resumen del Checklist -->
                    <div class="row mt-3">
                        <div class="col-md-6">
                            <div class="alert alert-info">
                                <h6 class="alert-heading">
                                    <i class="fas fa-info-circle me-2"></i>Estado del Sistema
                                </h6>
                                <p class="mb-2">
                                    <strong><?php echo $completados; ?> de <?php echo $total_items; ?></strong> configuraciones completadas.
                                </p>
                                <?php if ($progreso_porcentaje < 100): ?>
                                    <p class="mb-0 small">
                                        <strong>Faltan por configurar:</strong> 
                                        <?php echo ($total_items - $completados); ?> elementos
                                    </p>
                                <?php else: ?>
                                    <p class="mb-0 small text-success">
                                        <i class="fas fa-check-circle me-1"></i>
                                        <strong>¡Sistema completamente configurado!</strong>
                                    </p>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="alert alert-light">
                                <h6 class="alert-heading">
                                    <i class="fas fa-lightbulb me-2"></i>Recomendaciones
                                </h6>
                                <ul class="mb-0 small">
                                    <li>Siga el orden del checklist para una configuración óptima</li>
                                    <li>Configure primero el año escolar y las áreas de aprendizaje</li>
                                    <li>Asigne horarios después de crear secciones y profesores</li>
                                </ul>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Tarjetas de Estadísticas -->
    <div class="row mb-4">
        <div class="col-md-3 mb-3">
            <div class="card text-white bg-estudiantes dashboard-card">
                <div class="card-body">
                    <div class="d-flex justify-content-between">
                        <div>
                            <h3 class="stats-number"><?php echo $estudiantes_count; ?></h3>
                            <p class="card-text">Estudiantes</p>
                        </div>
                        <div class="align-self-center">
                            <i class="fas fa-user-graduate fa-2x opacity-50"></i>
                        </div>
                    </div>
                    <small class="opacity-75">Total registrados</small>
                </div>
            </div>
        </div>
        <div class="col-md-3 mb-3">
            <div class="card text-white bg-profesores dashboard-card">
                <div class="card-body">
                    <div class="d-flex justify-content-between">
                        <div>
                            <h3 class="stats-number"><?php echo $profesores_count; ?></h3>
                            <p class="card-text">Profesores</p>
                        </div>
                        <div class="align-self-center">
                            <i class="fas fa-chalkboard-teacher fa-2x opacity-50"></i>
                        </div>
                    </div>
                    <small class="opacity-75">En el sistema</small>
                </div>
            </div>
        </div>
        <div class="col-md-3 mb-3">
            <div class="card text-white bg-secciones dashboard-card">
                <div class="card-body">
                    <div class="d-flex justify-content-between">
                        <div>
                            <h3 class="stats-number"><?php echo $secciones_count; ?></h3>
                            <p class="card-text">Secciones</p>
                        </div>
                        <div class="align-self-center">
                            <i class="fas fa-door-open fa-2x opacity-50"></i>
                        </div>
                    </div>
                    <small class="opacity-75">Activas</small>
                </div>
            </div>
        </div>
        <div class="col-md-3 mb-3">
            <div class="card text-white bg-inscripciones dashboard-card">
                <div class="card-body">
                    <div class="d-flex justify-content-between">
                        <div>
                            <h3 class="stats-number"><?php echo $inscripciones_count; ?></h3>
                            <p class="card-text">Inscritos</p>
                        </div>
                        <div class="align-self-center">
                            <i class="fas fa-user-check fa-2x opacity-50"></i>
                        </div>
                    </div>
                    <small class="opacity-75">Año actual</small>
                </div>
            </div>
        </div>
    </div>

    <!-- Gráficas y Contenido Principal -->
    <div class="row">
        <!-- Gráfica de Estudiantes por Sección -->
        <div class="col-md-6 mb-4">
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="card-title mb-0">Estudiantes por Sección</h5>
                    <a href="secciones/gestionar.php" class="btn btn-sm btn-outline-primary">Ver Todas</a>
                </div>
                <div class="card-body">
                    <canvas id="chartEstudiantesSeccion" height="250"></canvas>
                </div>
            </div>
        </div>

        <!-- Gráfica de Asistencias -->
        <div class="col-md-6 mb-4">
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="card-title mb-0">Asistencias (Últimos 30 días)</h5>
                    <?php if (!isSqliteDatabase($pdo)): ?>
                        <a href="reportes/asistencias.php" class="btn btn-sm btn-outline-primary">Ver Reporte</a>
                    <?php endif; ?>
                </div>
                <div class="card-body">
                    <?php if (!empty($asistencias_stats)): ?>
                        <canvas id="chartAsistencias" height="250"></canvas>
                    <?php else: ?>
                        <div class="text-center py-4">
                            <i class="fas fa-chart-pie fa-3x text-muted mb-3"></i>
                            <p class="text-muted">No hay datos de asistencias disponibles</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Últimas Inscripciones -->
        <div class="col-md-6 mb-4">
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="card-title mb-0">Últimas Inscripciones</h5>
                    <a href="inscripciones/gestionar.php" class="btn btn-sm btn-outline-primary">Ver Todas</a>
                </div>
                <div class="card-body">
                    <?php if ($ultimas_inscripciones): ?>
                        <div class="list-group list-group-flush">
                            <?php foreach ($ultimas_inscripciones as $inscripcion): ?>
                                <div class="list-group-item d-flex justify-content-between align-items-center">
                                    <div>
                                        <h6 class="mb-1"><?php echo htmlspecialchars($inscripcion['nombre'] . ' ' . $inscripcion['apellido']); ?></h6>
                                        <small class="text-muted"><?php echo htmlspecialchars($inscripcion['seccion']); ?></small>
                                    </div>
                                    <small class="text-muted"><?php echo date('d/m/Y', strtotime($inscripcion['fecha_inscripcion'])); ?></small>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="text-center py-4">
                            <i class="fas fa-user-plus fa-3x text-muted mb-3"></i>
                            <p class="text-muted">No hay inscripciones recientes</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Estadísticas de Usuarios -->
        <div class="col-md-6 mb-4">
            <div class="card">
                <div class="card-header">
                    <h5 class="card-title mb-0">Estadísticas del Sistema</h5>
                </div>
                <div class="card-body">
                    <div class="row text-center">
                        <div class="col-6 mb-3">
                            <h3 class="text-primary"><?php echo $usuarios_stats['total']; ?></h3>
                            <small class="text-muted">Total Usuarios</small>
                        </div>
                        <div class="col-6 mb-3">
                            <h3 class="text-success"><?php echo $usuarios_stats['activos']; ?></h3>
                            <small class="text-muted">Usuarios Activos</small>
                        </div>
                        <div class="col-6">
                            <h3 class="text-info"><?php echo $secciones_count; ?></h3>
                            <small class="text-muted">Secciones</small>
                        </div>
                        <div class="col-6">
                            <h3 class="text-warning"><?php echo $profesores_count; ?></h3>
                            <small class="text-muted">Profesores</small>
                        </div>
                    </div>
                    <hr>
                    <div class="text-center">
                        <small class="text-muted">Sistema actualizado: <?php echo date('d/m/Y H:i'); ?></small>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Acciones Rápidas -->
    <div class="row">
        <div class="col-12">
            <div class="card">
                <div class="card-header">
                    <h5 class="card-title mb-0">Acciones Rápidas</h5>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-2 text-center mb-3">
                            <a href="estudiantes/agregar.php" class="btn btn-outline-primary btn-lg w-100">
                                <i class="fas fa-user-graduate fa-2x mb-2"></i><br>
                                <small>Nuevo Estudiante</small>
                            </a>
                        </div>
                        <div class="col-md-2 text-center mb-3">
                            <a href="profesores/agregar.php" class="btn btn-outline-success btn-lg w-100">
                                <i class="fas fa-chalkboard-teacher fa-2x mb-2"></i><br>
                                <small>Nuevo Profesor</small>
                            </a>
                        </div>
                        <div class="col-md-2 text-center mb-3">
                            <a href="inscripciones/inscribir.php" class="btn btn-outline-info btn-lg w-100">
                                <i class="fas fa-user-plus fa-2x mb-2"></i><br>
                                <small>Nueva Inscripción</small>
                            </a>
                        </div>
                        <div class="col-md-2 text-center mb-3">
                            <a href="usuarios/crear.php" class="btn btn-outline-warning btn-lg w-100">
                                <i class="fas fa-user-plus fa-2x mb-2"></i><br>
                                <small>Nuevo Usuario</small>
                            </a>
                        </div>
                        <div class="col-md-2 text-center mb-3">
                            <a href="evaluacion_literales/index.php" class="btn btn-outline-danger btn-lg w-100">
                                <i class="fas fa-chart-line fa-2x mb-2"></i><br>
                                <small>Reportes</small>
                            </a>
                        </div>
                        <div class="col-md-2 text-center mb-3">
                            <a href="secciones/gestionar.php" class="btn btn-outline-dark btn-lg w-100">
                                <i class="fas fa-door-open fa-2x mb-2"></i><br>
                                <small>Secciones</small>
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Incluir Chart.js -->
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<script>
// Datos para las gráficas
const datosGraficas = <?php echo json_encode($datos_graficas); ?>;

// Inicializar gráficas cuando el documento esté listo
document.addEventListener('DOMContentLoaded', function() {
    // Gráfica de Estudiantes por Sección
    if (datosGraficas.labels_secciones && datosGraficas.labels_secciones.length > 0) {
        const ctx1 = document.getElementById('chartEstudiantesSeccion').getContext('2d');
        new Chart(ctx1, {
            type: 'bar',
            data: {
                labels: datosGraficas.labels_secciones,
                datasets: [{
                    label: 'Estudiantes',
                    data: datosGraficas.data_secciones,
                    backgroundColor: 'rgba(54, 162, 235, 0.8)',
                    borderColor: 'rgba(54, 162, 235, 1)',
                    borderWidth: 1
                }]
            },
            options: {
                responsive: true,
                scales: {
                    y: {
                        beginAtZero: true,
                        title: {
                            display: true,
                            text: 'Cantidad de Estudiantes'
                        }
                    }
                }
            }
        });
    }

    // Gráfica de Asistencias
    if (datosGraficas.labels_asistencias && datosGraficas.labels_asistencias.length > 0) {
        const ctx2 = document.getElementById('chartAsistencias').getContext('2d');
        new Chart(ctx2, {
            type: 'pie',
            data: {
                labels: datosGraficas.labels_asistencias,
                datasets: [{
                    data: datosGraficas.data_asistencias,
                    backgroundColor: datosGraficas.colores_asistencias,
                    borderWidth: 1
                }]
            },
            options: {
                responsive: true,
                plugins: {
                    legend: {
                        position: 'bottom'
                    }
                }
            }
        });
    }
});
</script>

<style>
.sidebar {
    min-height: 100vh;
}
.nav-link {
    color: rgba(255,255,255,0.8) !important;
    padding: 12px 15px;
    border-radius: 5px;
    margin-bottom: 5px;
    transition: all 0.3s;
}
.nav-link:hover, .nav-link.active {
    background: rgba(255,255,255,0.1);
    color: white !important;
}
.dashboard-card {
    border: none;
    border-radius: 10px;
    transition: transform 0.3s, box-shadow 0.3s;
}
.dashboard-card:hover {
    transform: translateY(-5px);
    box-shadow: 0 8px 25px rgba(0,0,0,0.15);
}
.bg-estudiantes {
    background: linear-gradient(45deg, #3498db, #2980b9) !important;
}
.bg-profesores {
    background: linear-gradient(45deg, #2ecc71, #27ae60) !important;
}
.bg-secciones {
    background: linear-gradient(45deg, #9b59b6, #8e44ad) !important;
}
.bg-inscripciones {
    background: linear-gradient(45deg, #e74c3c, #c0392b) !important;
}
.stats-number {
    font-size: 2.2rem;
    font-weight: bold;
    margin-bottom: 5px;
}
.card {
    border: none;
    box-shadow: 0 2px 10px rgba(0,0,0,0.1);
    transition: transform 0.2s;
}
.card:hover {
    transform: translateY(-2px);
}
.dropdown-menu {
    background: rgba(255,255,255,0.95);
    border: none;
    border-radius: 8px;
    box-shadow: 0 4px 15px rgba(0,0,0,0.1);
}
.dropdown-item {
    color: #333 !important;
    padding: 10px 15px;
    transition: all 0.3s;
}
.dropdown-item:hover {
    background: linear-gradient(45deg, #3498db, #2980b9);
    color: white !important;
    border-radius: 5px;
}
/* Estilos para el checklist */
.checklist-item {
    transition: all 0.3s ease;
}
.checklist-item:hover {
    transform: translateY(-3px);
    box-shadow: 0 5px 15px rgba(0,0,0,0.1);
}
.checklist-icon {
    width: 40px;
    height: 40px;
    display: flex;
    align-items: center;
    justify-content: center;
}
.progress {
    border-radius: 10px;
}
.progress-bar {
    border-radius: 10px;
}
</style>

<?php include '../includes/footer.php'; ?>
