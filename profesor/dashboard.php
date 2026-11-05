<?php
require_once '../includes/auth.php';
require_once '../includes/database.php';
checkAuth();
checkRole(['profesor']);

// Obtener información del profesor
$profesor_id = $_SESSION['relacion_id'];
$nombre_profesor = $_SESSION['nombre'];

// Obtener estadísticas
try {
    // Total de secciones asignadas
    $stmt = $pdo->prepare("
        SELECT COUNT(DISTINCT seccion_id) as total_secciones
        FROM profesores_secciones_materias 
        WHERE profesor_id = ? AND año_escolar_id IN (
            SELECT id FROM años_escolares WHERE estado = 'activo'
        )
    ");
    $stmt->execute([$profesor_id]);
    $total_secciones = $stmt->fetch(PDO::FETCH_ASSOC)['total_secciones'];

    // Total de materias asignadas
    $stmt = $pdo->prepare("
        SELECT COUNT(DISTINCT materia_id) as total_materias
        FROM profesores_secciones_materias 
        WHERE profesor_id = ? AND año_escolar_id IN (
            SELECT id FROM años_escolares WHERE estado = 'activo'
        )
    ");
    $stmt->execute([$profesor_id]);
    $total_materias = $stmt->fetch(PDO::FETCH_ASSOC)['total_materias'];

    // Total de estudiantes bajo su responsabilidad
    $stmt = $pdo->prepare("
        SELECT COUNT(DISTINCT i.estudiante_id) as total_estudiantes
        FROM profesores_secciones_materias psm
        JOIN inscripciones i ON psm.seccion_id = i.seccion_id
        WHERE psm.profesor_id = ? 
        AND i.año_escolar_id IN (SELECT id FROM años_escolares WHERE estado = 'activo')
        AND i.status = 'activo'
    ");
    $stmt->execute([$profesor_id]);
    $total_estudiantes = $stmt->fetch(PDO::FETCH_ASSOC)['total_estudiantes'];

    // Notas pendientes por registrar (en el lapso actual)
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as notas_pendientes
        FROM (
            SELECT i.id as inscripcion_id
            FROM profesores_secciones_materias psm
            JOIN inscripciones i ON psm.seccion_id = i.seccion_id
            WHERE psm.profesor_id = ? 
            AND i.año_escolar_id IN (SELECT id FROM años_escolares WHERE estado = 'activo')
            AND i.status = 'activo'
        ) estudiantes
        LEFT JOIN notas n ON estudiantes.inscripcion_id = n.inscripcion_id 
            AND n.materia_id IN (
                SELECT materia_id FROM profesores_secciones_materias 
                WHERE profesor_id = ? AND año_escolar_id IN (
                    SELECT id FROM años_escolares WHERE estado = 'activo'
                )
            )
            AND n.lapso_id IN (
                SELECT id FROM lapsos 
                WHERE año_escolar_id IN (SELECT id FROM años_escolares WHERE estado = 'activo')
                AND estado = 'activo'
            )
        WHERE n.id IS NULL
    ");
    $stmt->execute([$profesor_id, $profesor_id]);
    $notas_pendientes = $stmt->fetch(PDO::FETCH_ASSOC)['notas_pendientes'];

    // Secciones asignadas recientemente
    $stmt = $pdo->prepare("
        SELECT s.nombre as seccion, m.nombre as materia, ae.nombre as año_escolar
        FROM profesores_secciones_materias psm
        JOIN secciones s ON psm.seccion_id = s.id
        JOIN materias m ON psm.materia_id = m.id
        JOIN años_escolares ae ON psm.año_escolar_id = ae.id
        WHERE psm.profesor_id = ? AND ae.estado = 'activo'
        ORDER BY psm.created_at DESC
        LIMIT 5
    ");
    $stmt->execute([$profesor_id]);
    $secciones_recientes = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // NUEVO: Datos para gráficas
    // Distribución de notas por rango
    $stmt = $pdo->prepare("
        SELECT 
            COUNT(*) as cantidad,
            CASE 
                WHEN n.valor_decimal >= 18 THEN 'Excelente (18-20)'
                WHEN n.valor_decimal >= 15 THEN 'Bueno (15-17)'
                WHEN n.valor_decimal >= 10 THEN 'Regular (10-14)'
                ELSE 'Insuficiente (0-9)'
            END as rango
        FROM notas n
        JOIN profesores_secciones_materias psm ON n.materia_id = psm.materia_id 
            AND n.lapso_id IN (SELECT id FROM lapsos WHERE estado = 'activo')
        WHERE psm.profesor_id = ? 
            AND n.es_promedio = 0
        GROUP BY 
            CASE 
                WHEN n.valor_decimal >= 18 THEN 'Excelente (18-20)'
                WHEN n.valor_decimal >= 15 THEN 'Bueno (15-17)'
                WHEN n.valor_decimal >= 10 THEN 'Regular (10-14)'
                ELSE 'Insuficiente (0-9)'
            END
        ORDER BY 
            CASE 
                WHEN n.valor_decimal >= 18 THEN 1
                WHEN n.valor_decimal >= 15 THEN 2
                WHEN n.valor_decimal >= 10 THEN 3
                ELSE 4
            END
    ");
    $stmt->execute([$profesor_id]);
    $distribucion_notas = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // NUEVO: Notas por materia
    $stmt = $pdo->prepare("
        SELECT 
            m.nombre as materia,
            ROUND(AVG(n.valor_decimal), 2) as promedio,
            COUNT(n.id) as total_notas
        FROM notas n
        JOIN materias m ON n.materia_id = m.id
        JOIN profesores_secciones_materias psm ON n.materia_id = psm.materia_id 
            AND n.lapso_id IN (SELECT id FROM lapsos WHERE estado = 'activo')
        WHERE psm.profesor_id = ? 
            AND n.es_promedio = 0
        GROUP BY m.nombre
        ORDER BY promedio DESC
        LIMIT 5
    ");
    $stmt->execute([$profesor_id]);
    $notas_por_materia = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // NUEVO: Evolución de notas por lapso
    $stmt = $pdo->prepare("
        SELECT 
            l.nombre as lapso,
            ROUND(AVG(n.valor_decimal), 2) as promedio,
            COUNT(n.id) as total_notas
        FROM notas n
        JOIN lapsos l ON n.lapso_id = l.id
        JOIN profesores_secciones_materias psm ON n.materia_id = psm.materia_id
        WHERE psm.profesor_id = ? 
            AND n.es_promedio = 0
        GROUP BY l.nombre, l.numero
        ORDER BY l.numero
    ");
    $stmt->execute([$profesor_id]);
    $evolucion_notas = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    $total_secciones = 0;
    $total_materias = 0;
    $total_estudiantes = 0;
    $notas_pendientes = 0;
    $secciones_recientes = [];
    $distribucion_notas = [];
    $notas_por_materia = [];
    $evolucion_notas = [];
    error_log("Error al cargar estadísticas: " . $e->getMessage());
}

// Obtener el año escolar activo
try {
    $stmt = $pdo->query("SELECT nombre FROM años_escolares WHERE estado = 'activo' LIMIT 1");
    $año_activo = $stmt->fetch(PDO::FETCH_ASSOC);
    $nombre_año_activo = $año_activo ? $año_activo['nombre'] : 'No configurado';
} catch (PDOException $e) {
    $nombre_año_activo = 'Error al cargar';
}
?>

<?php include '../includes/header.php'; ?>

<!-- AGREGAR CDN DE CHART.JS -->
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<?php include '../includes/sidebar_profesor.php'; ?>
    
<div class="content-wrapper">
    <div class="container-fluid p-4">

        <!-- Header -->
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h2>Dashboard Profesor</h2>
                <p class="text-muted mb-0">Bienvenido, <?php echo htmlspecialchars($nombre_profesor); ?></p>
                <small class="text-muted">Año escolar: <?php echo htmlspecialchars($nombre_año_activo); ?></small>
            </div>
            <div class="text-end">
                <div class="btn-group">
                    <a href="notas/gestionar.php" class="btn btn-primary">
                        <i class="fas fa-edit me-2"></i>Gestionar Notas
                    </a>
                    <a href="asistencias/registrar.php" class="btn btn-outline-primary">
                        <i class="fas fa-clipboard-list me-2"></i>Registrar Asistencias
                    </a>
                </div>
            </div>
        </div>

        <!-- Tarjetas de Estadísticas -->
        <div class="row mb-4">
            <div class="col-md-3">
                <div class="card bg-primary text-white">
                    <div class="card-body">
                        <div class="d-flex justify-content-between">
                            <div>
                                <h4 class="mb-0"><?php echo $total_secciones; ?></h4>
                                <p class="mb-0">Secciones</p>
                            </div>
                            <div class="align-self-center">
                                <i class="fas fa-users fa-2x opacity-50"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card bg-success text-white">
                    <div class="card-body">
                        <div class="d-flex justify-content-between">
                            <div>
                                <h4 class="mb-0"><?php echo $total_materias; ?></h4>
                                <p class="mb-0">Materias</p>
                            </div>
                            <div class="align-self-center">
                                <i class="fas fa-book fa-2x opacity-50"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card bg-info text-white">
                    <div class="card-body">
                        <div class="d-flex justify-content-between">
                            <div>
                                <h4 class="mb-0"><?php echo $total_estudiantes; ?></h4>
                                <p class="mb-0">Estudiantes</p>
                            </div>
                            <div class="align-self-center">
                                <i class="fas fa-graduation-cap fa-2x opacity-50"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card bg-warning text-dark">
                    <div class="card-body">
                        <div class="d-flex justify-content-between">
                            <div>
                                <h4 class="mb-0"><?php echo $notas_pendientes; ?></h4>
                                <p class="mb-0">Notas Pendientes</p>
                            </div>
                            <div class="align-self-center">
                                <i class="fas fa-edit fa-2x opacity-50"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="row">
            <!-- Secciones Asignadas -->
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header">
                        <h5 class="card-title mb-0">
                            <i class="fas fa-list me-2"></i>Mis Secciones y Materias
                        </h5>
                    </div>
                    <div class="card-body">
                        <?php if ($secciones_recientes): ?>
                            <div class="list-group list-group-flush">
                                <?php foreach ($secciones_recientes as $seccion): ?>
                                    <div class="list-group-item d-flex justify-content-between align-items-center">
                                        <div>
                                            <h6 class="mb-1"><?php echo htmlspecialchars($seccion['seccion']); ?></h6>
                                            <small class="text-muted"><?php echo htmlspecialchars($seccion['materia']); ?></small>
                                        </div>
                                        <span class="badge bg-primary rounded-pill"><?php echo htmlspecialchars($seccion['año_escolar']); ?></span>
                                    </div>
                                <?php endforeach; ?>
                                </div>
                                <?php if (count($secciones_recientes) >= 5): ?>
                                    <div class="text-center mt-3">
                                        <a href="notas/gestionar.php" class="btn btn-sm btn-outline-primary">
                                            Ver todas las asignaciones
                                        </a>
                                    </div>
                                <?php endif; ?>
                            <?php else: ?>
                                <div class="text-center py-4">
                                    <i class="fas fa-inbox fa-3x text-muted mb-3"></i>
                                    <p class="text-muted">No tienes secciones asignadas</p>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Acciones Rápidas -->
                <div class="col-md-6">
                    <div class="card">
                        <div class="card-header">
                            <h5 class="card-title mb-0">
                                <i class="fas fa-bolt me-2"></i>Acciones Rápidas
                            </h5>
                        </div>
                        <div class="card-body">
                            <div class="d-grid gap-2">
                                <a href="notas/gestionar.php" class="btn btn-outline-primary text-start">
                                    <i class="fas fa-edit me-2"></i>Registrar Notas
                                </a>
                                <a href="asistencias/registrar.php" class="btn btn-outline-success text-start">
                                    <i class="fas fa-clipboard-check me-2"></i>Tomar Asistencia
                                </a>
                                <a href="evaluaciones/gestionar.php" class="btn btn-outline-info text-start">
                                    <i class="fas fa-chart-bar me-2"></i>Ver Reportes
                                </a>
                                <a href="perfil/ver.php" class="btn btn-outline-secondary text-start">
                                    <i class="fas fa-cog me-2"></i>Mi Perfil
                                </a>
                            </div>
                        </div>
                    </div>

                    <!-- Información del Sistema -->
                    <div class="card mt-4">
                        <div class="card-header">
                            <h5 class="card-title mb-0">
                                <i class="fas fa-info-circle me-2"></i>Información del Sistema
                            </h5>
                        </div>
                        <div class="card-body">
                            <div class="mb-3">
                                <small class="text-muted">Año Escolar Actual:</small>
                                <div class="fw-bold"><?php echo htmlspecialchars($nombre_año_activo); ?></div>
                            </div>
                            <div class="mb-3">
                                <small class="text-muted">Usuario:</small>
                                <div class="fw-bold"><?php echo htmlspecialchars($_SESSION['username']); ?></div>
                            </div>
                            <div>
                                <small class="text-muted">Rol:</small>
                                <div class="fw-bold">Profesor</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Alertas Importantes -->
            <?php if ($notas_pendientes > 0): ?>
            <div class="alert alert-warning mt-4">
                <div class="d-flex align-items-center">
                    <i class="fas fa-exclamation-triangle fa-2x me-3"></i>
                    <div>
                        <h5 class="alert-heading mb-1">¡Notas Pendientes!</h5>
                        <p class="mb-0">Tienes <strong><?php echo $notas_pendientes; ?> notas</strong> pendientes de registrar en el lapso actual.</p>
                        <a href="notas/gestionar.php" class="btn btn-warning btn-sm mt-2">
                            <i class="fas fa-edit me-1"></i>Registrar Notas Ahora
                        </a>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- NUEVA SECCIÓN: GRÁFICAS AL FINAL -->
            <div class="row mt-4">
                <div class="col-12">
                    <h4 class="mb-4">
                        <i class="fas fa-chart-bar me-2"></i>Estadísticas Visuales
                    </h4>
                </div>
                
                <!-- Gráfica 1: Distribución de Notas -->
                <div class="col-md-6 mb-4">
                    <div class="card">
                        <div class="card-header bg-primary text-white">
                            <h5 class="card-title mb-0">
                                <i class="fas fa-chart-pie me-2"></i>Distribución de Notas
                            </h5>
                        </div>
                        <div class="card-body">
                            <canvas id="distribucionNotasChart" height="250"></canvas>
                        </div>
                    </div>
                </div>

                <!-- Gráfica 2: Promedio por Materia -->
                <div class="col-md-6 mb-4">
                    <div class="card">
                        <div class="card-header bg-success text-white">
                            <h5 class="card-title mb-0">
                                <i class="fas fa-chart-bar me-2"></i>Promedio por Materia
                            </h5>
                        </div>
                        <div class="card-body">
                            <canvas id="promedioMateriaChart" height="250"></canvas>
                        </div>
                    </div>
                </div>

                <!-- Gráfica 3: Evolución por Lapso -->
                <div class="col-md-12 mb-4">
                    <div class="card">
                        <div class="card-header bg-info text-white">
                            <h5 class="card-title mb-0">
                                <i class="fas fa-chart-line me-2"></i>Evolución de Notas por Lapso
                            </h5>
                        </div>
                        <div class="card-body">
                            <canvas id="evolucionNotasChart" height="100"></canvas>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- SCRIPT PARA LAS GRÁFICAS -->
<script>
document.addEventListener('DOMContentLoaded', function() {
    // Colores para las gráficas
    const colores = [
        '#3498db', '#2ecc71', '#e74c3c', '#f39c12', '#9b59b6',
        '#1abc9c', '#d35400', '#c0392b', '#16a085', '#8e44ad'
    ];

    // Gráfica 1: Distribución de Notas (Dona)
    <?php if (!empty($distribucion_notas)): ?>
    const ctxDistribucion = document.getElementById('distribucionNotasChart').getContext('2d');
    new Chart(ctxDistribucion, {
        type: 'doughnut',
        data: {
            labels: [<?php echo implode(', ', array_map(function($item) { return "'" . $item['rango'] . "'"; }, $distribucion_notas)); ?>],
            datasets: [{
                data: [<?php echo implode(', ', array_map(function($item) { return $item['cantidad']; }, $distribucion_notas)); ?>],
                backgroundColor: ['#2ecc71', '#3498db', '#f39c12', '#e74c3c'],
                borderWidth: 2,
                borderColor: '#fff'
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    position: 'bottom',
                    labels: {
                        padding: 20,
                        usePointStyle: true
                    }
                },
                tooltip: {
                    callbacks: {
                        label: function(context) {
                            const label = context.label || '';
                            const value = context.raw || 0;
                            const total = context.dataset.data.reduce((a, b) => a + b, 0);
                            const percentage = Math.round((value / total) * 100);
                            return `${label}: ${value} notas (${percentage}%)`;
                        }
                    }
                }
            }
        }
    });
    <?php else: ?>
    document.getElementById('distribucionNotasChart').innerHTML = `
        <div class="text-center py-5 text-muted">
            <i class="fas fa-chart-pie fa-3x mb-3"></i>
            <p>No hay datos suficientes para mostrar la distribución</p>
            <small>Registra algunas notas para ver las estadísticas</small>
        </div>
    `;
    <?php endif; ?>

    // Gráfica 2: Promedio por Materia (Barras)
    <?php if (!empty($notas_por_materia)): ?>
    const ctxMateria = document.getElementById('promedioMateriaChart').getContext('2d');
    new Chart(ctxMateria, {
        type: 'bar',
        data: {
            labels: [<?php echo implode(', ', array_map(function($item) { return "'" . addslashes($item['materia']) . "'"; }, $notas_por_materia)); ?>],
            datasets: [{
                label: 'Promedio',
                data: [<?php echo implode(', ', array_map(function($item) { return $item['promedio']; }, $notas_por_materia)); ?>],
                backgroundColor: colores.slice(0, <?php echo count($notas_por_materia); ?>),
                borderColor: colores.slice(0, <?php echo count($notas_por_materia); ?>).map(color => color.replace('0.8', '1')),
                borderWidth: 1
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            scales: {
                y: {
                    beginAtZero: true,
                    max: 20,
                    title: {
                        display: true,
                        text: 'Calificación (0-20)'
                    }
                },
                x: {
                    title: {
                        display: true,
                        text: 'Materias'
                    }
                }
            },
            plugins: {
                legend: {
                    display: false
                },
                tooltip: {
                    callbacks: {
                        afterLabel: function(context) {
                            const data = <?php echo json_encode($notas_por_materia); ?>;
                            const item = data[context.dataIndex];
                            return `Total notas: ${item.total_notas}`;
                        }
                    }
                }
            }
        }
    });
    <?php else: ?>
    document.getElementById('promedioMateriaChart').innerHTML = `
        <div class="text-center py-5 text-muted">
            <i class="fas fa-chart-bar fa-3x mb-3"></i>
            <p>No hay datos suficientes para mostrar promedios</p>
            <small>Registra algunas notas para ver las estadísticas</small>
        </div>
    `;
    <?php endif; ?>

    // Gráfica 3: Evolución por Lapso (Línea)
    <?php if (!empty($evolucion_notas)): ?>
    const ctxEvolucion = document.getElementById('evolucionNotasChart').getContext('2d');
    new Chart(ctxEvolucion, {
        type: 'line',
        data: {
            labels: [<?php echo implode(', ', array_map(function($item) { return "'" . addslashes($item['lapso']) . "'"; }, $evolucion_notas)); ?>],
            datasets: [{
                label: 'Promedio General',
                data: [<?php echo implode(', ', array_map(function($item) { return $item['promedio']; }, $evolucion_notas)); ?>],
                borderColor: '#3498db',
                backgroundColor: 'rgba(52, 152, 219, 0.1)',
                borderWidth: 3,
                fill: true,
                tension: 0.4,
                pointBackgroundColor: '#3498db',
                pointBorderColor: '#fff',
                pointBorderWidth: 2,
                pointRadius: 6
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            scales: {
                y: {
                    beginAtZero: true,
                    max: 20,
                    title: {
                        display: true,
                        text: 'Promedio'
                    }
                },
                x: {
                    title: {
                        display: true,
                        text: 'Lapsos'
                    }
                }
            },
            plugins: {
                tooltip: {
                    callbacks: {
                        afterLabel: function(context) {
                            const data = <?php echo json_encode($evolucion_notas); ?>;
                            const item = data[context.dataIndex];
                            return `Total evaluaciones: ${item.total_notas}`;
                        }
                    }
                }
            }
        }
    });
    <?php else: ?>
    document.getElementById('evolucionNotasChart').innerHTML = `
        <div class="text-center py-5 text-muted">
            <i class="fas fa-chart-line fa-3x mb-3"></i>
            <p>No hay datos suficientes para mostrar evolución</p>
            <small>Registra notas en diferentes lapsos para ver la evolución</small>
        </div>
    `;
    <?php endif; ?>
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
.card {
    border: none;
    box-shadow: 0 2px 10px rgba(0,0,0,0.1);
    transition: transform 0.2s;
}
.card:hover {
    transform: translateY(-2px);
}
/* Estilos para las gráficas */
canvas {
    border-radius: 8px;
}
</style>

<?php include '../includes/footer.php'; ?>