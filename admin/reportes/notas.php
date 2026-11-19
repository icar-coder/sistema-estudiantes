<?php
require_once '../../includes/auth.php';
require_once '../../includes/database.php';
require_once '../../includes/functions_evaluacion.php';
require_once '../../includes/functions_notas.php';
checkAuth();
checkRole(['admin']);
requireOnlineFeature($pdo, 'El reporte de notas');

$esquema_faltante = verificarEsquemaReportes(['notas', 'lapsos', 'inscripciones', 'años_escolares']);

// Obtener parámetros de filtro
$año_escolar_id = $_GET['año_escolar_id'] ?? '';
$seccion_id = $_GET['seccion_id'] ?? '';
$lapso_id = $_GET['lapso_id'] ?? '';

// Obtener años escolares para el filtro
try {
    $stmt = $pdo->query("SELECT id, nombre FROM años_escolares ORDER BY nombre DESC");
    $años_escolares = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $años_escolares = [];
}

// Obtener secciones para el filtro
try {
    $sql = "SELECT s.id, s.nombre, ae.nombre as año_escolar, g.nombre as grado_nombre
            FROM secciones s 
            JOIN años_escolares ae ON s.año_escolar_id = ae.id
            LEFT JOIN grados g ON s.grado_id = g.id";
    if ($año_escolar_id) {
        $sql .= " WHERE s.año_escolar_id = ?";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$año_escolar_id]);
    } else {
        $stmt = $pdo->query($sql);
    }
    $secciones = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $secciones = [];
}

// Obtener lapsos para el filtro
try {
    $sql = "SELECT l.id, l.nombre, l.numero, ae.nombre as año_escolar 
            FROM lapsos l 
            JOIN años_escolares ae ON l.año_escolar_id = ae.id";
    if ($año_escolar_id) {
        $sql .= " WHERE l.año_escolar_id = ? ORDER BY l.numero";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$año_escolar_id]);
    } else {
        $sql .= " ORDER BY l.numero";
        $stmt = $pdo->query($sql);
    }
    $lapsos = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $lapsos = [];
}

// Estadísticas generales basadas en literales
$estadisticas = [];
$datos_graficas = [];

if ($año_escolar_id) {
    try {
        // Distribución de literales por sección (para el lapso más reciente o todos)
        $stmt = $pdo->prepare("
            SELECT s.nombre as seccion, 
                   ll.literal,
                   COUNT(*) as cantidad
            FROM literales_lapso ll
            JOIN inscripciones i ON ll.inscripcion_id = i.id
            JOIN secciones s ON i.seccion_id = s.id
            JOIN lapsos l ON ll.lapso_id = l.id
            WHERE i.año_escolar_id = ?
            " . ($lapso_id ? " AND ll.lapso_id = ?" : " AND l.numero = (SELECT MAX(numero) FROM lapsos WHERE año_escolar_id = ?)") . "
            GROUP BY s.nombre, ll.literal
            ORDER BY s.nombre, ll.literal
        ");
        
        if ($lapso_id) {
            $stmt->execute([$año_escolar_id, $lapso_id]);
        } else {
            $stmt->execute([$año_escolar_id, $año_escolar_id]);
        }
        
        $distribucion_literales = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Procesar distribución para gráficas
        $literales_por_seccion = [];
        foreach ($distribucion_literales as $dist) {
            $seccion = $dist['seccion'];
            if (!isset($literales_por_seccion[$seccion])) {
                $literales_por_seccion[$seccion] = ['A' => 0, 'B' => 0, 'C' => 0, 'D' => 0, 'E' => 0];
            }
            $literales_por_seccion[$seccion][$dist['literal']] = $dist['cantidad'];
        }
        
        $estadisticas['distribucion_literales'] = $literales_por_seccion;

        // Literales finales del año
        $stmt = $pdo->prepare("
            SELECT lf.literal_final as literal, COUNT(*) as cantidad
            FROM literales_finales lf
            JOIN inscripciones i ON lf.inscripcion_id = i.id
            WHERE i.año_escolar_id = ?
            GROUP BY lf.literal_final
            ORDER BY lf.literal_final
        ");
        $stmt->execute([$año_escolar_id]);
        $estadisticas['literales_finales'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Top estudiantes (basado en literales)
        $stmt = $pdo->prepare("
            SELECT e.nombre, e.apellido, s.nombre as seccion, lf.literal_final as literal
            FROM literales_finales lf
            JOIN inscripciones i ON lf.inscripcion_id = i.id
            JOIN estudiantes e ON i.estudiante_id = e.id
            JOIN secciones s ON i.seccion_id = s.id
            WHERE i.año_escolar_id = ? AND lf.literal_final IN ('A', 'B')
            ORDER BY 
                CASE lf.literal_final 
                    WHEN 'A' THEN 1 
                    WHEN 'B' THEN 2 
                    ELSE 3 
                END,
                e.apellido, e.nombre
            LIMIT 15
        ");
        $stmt->execute([$año_escolar_id]);
        $estadisticas['top_estudiantes'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Estadísticas de evaluación por áreas de aprendizaje
        $stmt = $pdo->prepare("
            SELECT aa.nombre as area,
                   COUNT(DISTINCT hi.indicador_id) as indicadores_evaluados,
                   COUNT(DISTINCT i.id) as estudiantes_evaluados,
                   ROUND(COUNT(DISTINCT hi.indicador_id) * 100.0 / (SELECT COUNT(*) FROM areas_indicadores WHERE estado = 'activo'), 2) as porcentaje_indicadores
            FROM historial_indicadores hi
            JOIN inscripciones i ON hi.historial_id IN (
                SELECT id FROM historial_academico WHERE estudiante_id = i.estudiante_id AND año_escolar_id = ?
            )
            JOIN areas_indicadores ai ON hi.indicador_id = ai.id
            JOIN areas_aprendizaje aa ON ai.area_id = aa.id
            WHERE i.año_escolar_id = ?
            GROUP BY aa.id, aa.nombre
            ORDER BY porcentaje_indicadores DESC
        ");
        $stmt->execute([$año_escolar_id, $año_escolar_id]);
        $estadisticas['evaluacion_areas'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Preparar datos para gráficas
        $datos_graficas['labels_secciones'] = array_keys($literales_por_seccion);
        $datos_graficas['data_literales_A'] = [];
        $datos_graficas['data_literales_B'] = [];
        $datos_graficas['data_literales_C'] = [];
        $datos_graficas['data_literales_D'] = [];
        $datos_graficas['data_literales_E'] = [];
        
        foreach ($literales_por_seccion as $seccion) {
            $datos_graficas['data_literales_A'][] = $seccion['A'] ?? 0;
            $datos_graficas['data_literales_B'][] = $seccion['B'] ?? 0;
            $datos_graficas['data_literales_C'][] = $seccion['C'] ?? 0;
            $datos_graficas['data_literales_D'][] = $seccion['D'] ?? 0;
            $datos_graficas['data_literales_E'][] = $seccion['E'] ?? 0;
        }

        // Datos para gráfica de literales finales
        $datos_graficas['labels_finales'] = [];
        $datos_graficas['data_finales'] = [];
        foreach ($estadisticas['literales_finales'] as $final) {
            $datos_graficas['labels_finales'][] = "Literal " . $final['literal'];
            $datos_graficas['data_finales'][] = intval($final['cantidad']);
        }

        // Datos para gráfica de áreas de aprendizaje
        $datos_graficas['labels_areas'] = [];
        $datos_graficas['data_areas'] = [];
        foreach ($estadisticas['evaluacion_areas'] as $area) {
            $datos_graficas['labels_areas'][] = $area['area'];
            $datos_graficas['data_areas'][] = floatval($area['porcentaje_indicadores']);
        }

    } catch (PDOException $e) {
        error_log("Error al cargar estadísticas: " . $e->getMessage());
    }
}

// Función auxiliar para obtener el nombre del literal
function getLiteralNombre($literal) {
    switch ($literal) {
        case 'A': return 'Excelente';
        case 'B': return 'Muy Bueno';
        case 'C': return 'Bueno';
        case 'D': return 'Regular';
        case 'E': return 'Deficiente';
        default: return 'Sin evaluar';
    }
}

// Función auxiliar para obtener el color del literal
function getLiteralColor($literal) {
    switch ($literal) {
        case 'A': return 'success';
        case 'B': return 'info';
        case 'C': return 'primary';
        case 'D': return 'warning';
        case 'E': return 'danger';
        default: return 'secondary';
    }
}
?>

<?php include '../../includes/header.php'; ?>
<?php include '../../includes/sidebar_admin.php'; ?>
<main class="content-wrapper">

<?php if (!empty($esquema_faltante)): ?>
    <div class="container-fluid mt-3">
        <div class="alert alert-warning border-warning" role="alert">
            <strong>Advertencia de esquema:</strong>
            faltan tablas requeridas para reportes: <?php echo htmlspecialchars(implode(', ', $esquema_faltante)); ?>.
            Algunas funciones de reportes no estarán disponibles hasta que el esquema esté corregido.
        </div>
    </div>
<?php endif; ?>

<div class="container-fluid p-4">
            <h2 class="mb-4">
                <i class="fas fa-chart-bar me-2"></i>Reportes y Estadísticas por Literales
            </h2>

            <!-- Filtros -->
            <div class="card mb-4">
                <div class="card-header bg-primary text-white">
                    <h5 class="card-title mb-0">
                        <i class="fas fa-filter me-2"></i>Filtros de Reportes
                    </h5>
                </div>
                <div class="card-body">
                    <form method="GET" class="row g-3">
                        <div class="col-md-4">
                            <label for="año_escolar_id" class="form-label">Año Escolar</label>
                            <select class="form-select" id="año_escolar_id" name="año_escolar_id">
                                <option value="">Seleccionar año escolar</option>
                                <?php foreach ($años_escolares as $año): ?>
                                    <option value="<?php echo $año['id']; ?>" 
                                        <?php echo $año_escolar_id == $año['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($año['nombre']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label for="seccion_id" class="form-label">Sección</label>
                            <select class="form-select" id="seccion_id" name="seccion_id">
                                <option value="">Todas las secciones</option>
                                <?php foreach ($secciones as $seccion): ?>
                                    <option value="<?php echo $seccion['id']; ?>" 
                                        <?php echo $seccion_id == $seccion['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($seccion['nombre'] . ' - ' . $seccion['grado_nombre']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label for="lapso_id" class="form-label">Lapso</label>
                            <select class="form-select" id="lapso_id" name="lapso_id">
                                <option value="">Lapso más reciente</option>
                                <?php foreach ($lapsos as $lapso): ?>
                                    <option value="<?php echo $lapso['id']; ?>" 
                                        <?php echo $lapso_id == $lapso['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($lapso['nombre']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12">
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-chart-bar me-2"></i>Generar Reportes
                            </button>
                            <a href="reportes.php" class="btn btn-outline-secondary ms-2">
                                <i class="fas fa-times me-2"></i>Limpiar Filtros
                            </a>
                        </div>
                    </form>
                </div>
            </div>

            <?php if ($año_escolar_id && !empty($estadisticas)): ?>
                <!-- Gráficas -->
                <div class="row mb-4">
                    <!-- Distribución de Literales por Sección -->
                    <div class="col-md-8 mb-4">
                        <div class="card">
                            <div class="card-header bg-info text-white">
                                <h5 class="card-title mb-0">
                                    <i class="fas fa-chart-bar me-2"></i>Distribución de Literales por Sección
                                    <?php if ($lapso_id): ?>
                                        <small class="ms-2">(Lapso específico)</small>
                                    <?php else: ?>
                                        <small class="ms-2">(Lapso más reciente)</small>
                                    <?php endif; ?>
                                </h5>
                            </div>
                            <div class="card-body">
                                <canvas id="chartLiteralesSecciones" height="300"></canvas>
                            </div>
                        </div>
                    </div>

                    <!-- Literales Finales del Año -->
                    <div class="col-md-4 mb-4">
                        <div class="card">
                            <div class="card-header bg-success text-white">
                                <h5 class="card-title mb-0">
                                    <i class="fas fa-trophy me-2"></i>Literales Finales del Año
                                </h5>
                            </div>
                            <div class="card-body">
                                <canvas id="chartLiteralesFinales" height="300"></canvas>
                            </div>
                        </div>
                    </div>

                    <!-- Evaluación por Áreas de Aprendizaje -->
                    <div class="col-md-6 mb-4">
                        <div class="card">
                            <div class="card-header bg-warning text-dark">
                                <h5 class="card-title mb-0">
                                    <i class="fas fa-book me-2"></i>Evaluación por Áreas de Aprendizaje
                                </h5>
                            </div>
                            <div class="card-body">
                                <canvas id="chartEvaluacionAreas" height="250"></canvas>
                            </div>
                        </div>
                    </div>

                    <!-- Top Estudiantes -->
                    <div class="col-md-6 mb-4">
                        <div class="card">
                            <div class="card-header bg-primary text-white">
                                <h5 class="card-title mb-0">
                                    <i class="fas fa-star me-2"></i>Estudiantes Destacados
                                </h5>
                            </div>
                            <div class="card-body">
                                <div class="table-responsive" style="max-height: 300px; overflow-y: auto;">
                                    <table class="table table-sm table-striped">
                                        <thead>
                                            <tr>
                                                <th>#</th>
                                                <th>Estudiante</th>
                                                <th>Sección</th>
                                                <th>Literal Final</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php if (!empty($estadisticas['top_estudiantes'])): ?>
                                                <?php foreach ($estadisticas['top_estudiantes'] as $index => $estudiante): ?>
                                                    <tr>
                                                        <td><?php echo $index + 1; ?></td>
                                                        <td><?php echo htmlspecialchars($estudiante['nombre'] . ' ' . $estudiante['apellido']); ?></td>
                                                        <td><?php echo htmlspecialchars($estudiante['seccion']); ?></td>
                                                        <td>
                                                            <span class="badge bg-<?php echo getLiteralColor($estudiante['literal']); ?>">
                                                                <?php echo $estudiante['literal'] . ' - ' . getLiteralNombre($estudiante['literal']); ?>
                                                            </span>
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            <?php else: ?>
                                                <tr>
                                                    <td colspan="4" class="text-center text-muted py-3">
                                                        No hay estudiantes con literales A o B en este período.
                                                    </td>
                                                </tr>
                                            <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Estadísticas Detalladas -->
                <div class="row">
                    <div class="col-12">
                        <div class="card">
                            <div class="card-header bg-secondary text-white">
                                <h5 class="card-title mb-0">
                                    <i class="fas fa-chart-pie me-2"></i>Resumen Estadístico
                                </h5>
                            </div>
                            <div class="card-body">
                                <div class="row text-center">
                                    <div class="col-md-2 mb-3">
                                        <div class="border rounded p-3 bg-light">
                                            <h3 class="text-primary mb-1">
                                                <?php echo count($estadisticas['distribucion_literales']); ?>
                                            </h3>
                                            <p class="text-muted mb-0 small">Secciones Evaluadas</p>
                                        </div>
                                    </div>
                                    <div class="col-md-2 mb-3">
                                        <div class="border rounded p-3 bg-light">
                                            <?php
                                            $total_literales = 0;
                                            foreach ($estadisticas['distribucion_literales'] as $seccion) {
                                                $total_literales += array_sum($seccion);
                                            }
                                            ?>
                                            <h3 class="text-success mb-1"><?php echo $total_literales; ?></h3>
                                            <p class="text-muted mb-0 small">Evaluaciones</p>
                                        </div>
                                    </div>
                                    <div class="col-md-2 mb-3">
                                        <div class="border rounded p-3 bg-light">
                                            <h3 class="text-info mb-1">
                                                <?php echo count($estadisticas['evaluacion_areas']); ?>
                                            </h3>
                                            <p class="text-muted mb-0 small">Áreas Evaluadas</p>
                                        </div>
                                    </div>
                                    <div class="col-md-2 mb-3">
                                        <div class="border rounded p-3 bg-light">
                                            <h3 class="text-warning mb-1">
                                                <?php echo count($estadisticas['top_estudiantes']); ?>
                                            </h3>
                                            <p class="text-muted mb-0 small">Destacados</p>
                                        </div>
                                    </div>
                                    <div class="col-md-2 mb-3">
                                        <div class="border rounded p-3 bg-light">
                                            <?php
                                            $total_finales = array_sum(array_column($estadisticas['literales_finales'], 'cantidad'));
                                            ?>
                                            <h3 class="text-danger mb-1"><?php echo $total_finales; ?></h3>
                                            <p class="text-muted mb-0 small">Literales Finales</p>
                                        </div>
                                    </div>
                                    <div class="col-md-2 mb-3">
                                        <div class="border rounded p-3 bg-light">
                                            <?php
                                            $promedio_evaluacion = count($estadisticas['evaluacion_areas']) > 0 ? 
                                                array_sum(array_column($estadisticas['evaluacion_areas'], 'porcentaje_indicadores')) / count($estadisticas['evaluacion_areas']) : 0;
                                            ?>
                                            <h3 class="text-dark mb-1"><?php echo number_format($promedio_evaluacion, 1); ?>%</h3>
                                            <p class="text-muted mb-0 small">Evaluación Promedio</p>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

            <?php elseif ($año_escolar_id && empty($estadisticas)): ?>
                <div class="alert alert-info">
                    <i class="fas fa-info-circle me-2"></i>
                    No hay datos suficientes para generar reportes con los filtros seleccionados.
                </div>
            <?php else: ?>
                <div class="alert alert-warning">
                    <i class="fas fa-exclamation-triangle me-2"></i>
                    Selecciona un año escolar para ver los reportes y estadísticas.
                </div>
            <?php endif; ?>
        </div>
</main>

<!-- Incluir Chart.js -->
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<script>
// Datos para las gráficas
const datosGraficas = <?php echo json_encode($datos_graficas); ?>;

// Inicializar gráficas cuando el documento esté listo
document.addEventListener('DOMContentLoaded', function() {
    // Gráfica de Literales por Sección
    if (datosGraficas.labels_secciones && datosGraficas.labels_secciones.length > 0) {
        const ctx1 = document.getElementById('chartLiteralesSecciones').getContext('2d');
        new Chart(ctx1, {
            type: 'bar',
            data: {
                labels: datosGraficas.labels_secciones,
                datasets: [
                    {
                        label: 'A - Excelente',
                        data: datosGraficas.data_literales_A,
                        backgroundColor: 'rgba(40, 167, 69, 0.8)',
                        borderColor: 'rgba(40, 167, 69, 1)',
                        borderWidth: 1
                    },
                    {
                        label: 'B - Muy Bueno',
                        data: datosGraficas.data_literales_B,
                        backgroundColor: 'rgba(23, 162, 184, 0.8)',
                        borderColor: 'rgba(23, 162, 184, 1)',
                        borderWidth: 1
                    },
                    {
                        label: 'C - Bueno',
                        data: datosGraficas.data_literales_C,
                        backgroundColor: 'rgba(0, 123, 255, 0.8)',
                        borderColor: 'rgba(0, 123, 255, 1)',
                        borderWidth: 1
                    },
                    {
                        label: 'D - Regular',
                        data: datosGraficas.data_literales_D,
                        backgroundColor: 'rgba(255, 193, 7, 0.8)',
                        borderColor: 'rgba(255, 193, 7, 1)',
                        borderWidth: 1
                    },
                    {
                        label: 'E - Deficiente',
                        data: datosGraficas.data_literales_E,
                        backgroundColor: 'rgba(220, 53, 69, 0.8)',
                        borderColor: 'rgba(220, 53, 69, 1)',
                        borderWidth: 1
                    }
                ]
            },
            options: {
                responsive: true,
                scales: {
                    x: {
                        stacked: false,
                    },
                    y: {
                        stacked: false,
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

    // Gráfica de Literales Finales
    if (datosGraficas.labels_finales && datosGraficas.labels_finales.length > 0) {
        const ctx2 = document.getElementById('chartLiteralesFinales').getContext('2d');
        new Chart(ctx2, {
            type: 'doughnut',
            data: {
                labels: datosGraficas.labels_finales,
                datasets: [{
                    data: datosGraficas.data_finales,
                    backgroundColor: [
                        'rgba(40, 167, 69, 0.8)',  // A - Verde
                        'rgba(23, 162, 184, 0.8)', // B - Azul claro
                        'rgba(0, 123, 255, 0.8)',  // C - Azul
                        'rgba(255, 193, 7, 0.8)',  // D - Amarillo
                        'rgba(220, 53, 69, 0.8)'   // E - Rojo
                    ],
                    borderWidth: 2,
                    borderColor: '#fff'
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

    // Gráfica de Evaluación por Áreas
    if (datosGraficas.labels_areas && datosGraficas.labels_areas.length > 0) {
        const ctx3 = document.getElementById('chartEvaluacionAreas').getContext('2d');
        new Chart(ctx3, {
            type: 'bar',
            data: {
                labels: datosGraficas.labels_areas,
                datasets: [{
                    label: '% de Indicadores Evaluados',
                    data: datosGraficas.data_areas,
                    backgroundColor: 'rgba(255, 159, 64, 0.8)',
                    borderColor: 'rgba(255, 159, 64, 1)',
                    borderWidth: 1
                }]
            },
            options: {
                responsive: true,
                scales: {
                    y: {
                        beginAtZero: true,
                        max: 100,
                        title: {
                            display: true,
                            text: 'Porcentaje de Evaluación'
                        }
                    }
                }
            }
        });
    }
});

// Actualizar secciones y lapsos cuando cambie el año escolar
document.getElementById('año_escolar_id').addEventListener('change', function() {
    const añoId = this.value;
    
    // Recargar la página con el nuevo filtro
    if (añoId) {
        window.location.href = `reportes.php?año_escolar_id=${añoId}`;
    }
});
</script>

<style>
.card {
    border: none;
    box-shadow: 0 2px 10px rgba(0,0,0,0.1);
    transition: transform 0.2s;
}
.card:hover {
    transform: translateY(-2px);
}
.stats-number {
    font-size: 2.5rem;
    font-weight: bold;
}
.badge {
    font-size: 0.75em;
}
</style>

<?php include '../../includes/footer.php'; ?>