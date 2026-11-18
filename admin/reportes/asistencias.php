<?php
require_once '../../includes/auth.php';
require_once '../../includes/database.php';
require_once '../../includes/functions_notas.php';
checkAuth();
checkRole(['admin']);
requireOnlineFeature($pdo, 'El reporte de asistencias por materia');

$esquema_faltante = verificarEsquemaReportes(['asistencias', 'inscripciones', 'años_escolares', 'secciones']);

// Obtener parámetros de filtro
$año_escolar_id = $_GET['año_escolar_id'] ?? '';
$seccion_id = $_GET['seccion_id'] ?? '';
$materia_id = $_GET['materia_id'] ?? '';
$fecha_inicio = $_GET['fecha_inicio'] ?? date('Y-m-01');
$fecha_fin = $_GET['fecha_fin'] ?? date('Y-m-t');

// Obtener años escolares para el filtro
try {
    $stmt = $pdo->query("SELECT id, nombre FROM años_escolares ORDER BY nombre DESC");
    $años_escolares = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $años_escolares = [];
}

// Obtener secciones para el filtro
try {
    $sql = "SELECT s.id, s.nombre, ae.nombre as año_escolar 
            FROM secciones s 
            JOIN años_escolares ae ON s.año_escolar_id = ae.id";
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

// Obtener materias para el filtro
try {
    $stmt = $pdo->query("SELECT id, nombre, codigo FROM materias WHERE estado = 'activa' ORDER BY nombre");
    $materias = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $materias = [];
}

// Estadísticas de asistencias
$estadisticas = [];
$datos_graficas = [];

if ($año_escolar_id) {
    try {
        // Construir consulta base
        $sql_where = "WHERE i.año_escolar_id = ? AND a.fecha BETWEEN ? AND ?";
        $params = [$año_escolar_id, $fecha_inicio, $fecha_fin];
        
        if ($seccion_id) {
            $sql_where .= " AND i.seccion_id = ?";
            $params[] = $seccion_id;
        }
        
        if ($materia_id) {
            $sql_where .= " AND a.materia_id = ?";
            $params[] = $materia_id;
        }

        // Asistencias por estado
        $stmt = $pdo->prepare("
            SELECT estado, COUNT(*) as cantidad
            FROM asistencias a
            JOIN inscripciones i ON a.inscripcion_id = i.id
            $sql_where
            GROUP BY estado
            ORDER BY cantidad DESC
        ");
        $stmt->execute($params);
        $estadisticas['asistencias_estado'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Asistencias por sección
        $stmt = $pdo->prepare("
            SELECT s.nombre as seccion, 
                   COUNT(*) as total,
                   SUM(CASE WHEN a.estado = 'presente' THEN 1 ELSE 0 END) as presentes,
                   SUM(CASE WHEN a.estado = 'ausente' THEN 1 ELSE 0 END) as ausentes,
                   SUM(CASE WHEN a.estado = 'tardanza' THEN 1 ELSE 0 END) as tardanzas,
                   SUM(CASE WHEN a.estado = 'justificado' THEN 1 ELSE 0 END) as justificados
            FROM asistencias a
            JOIN inscripciones i ON a.inscripcion_id = i.id
            JOIN secciones s ON i.seccion_id = s.id
            $sql_where
            GROUP BY s.id, s.nombre
            ORDER BY total DESC
        ");
        $stmt->execute($params);
        $estadisticas['asistencias_seccion'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Asistencias por materia
        $stmt = $pdo->prepare("
            SELECT m.nombre as materia, 
                   COUNT(*) as total,
                   SUM(CASE WHEN a.estado = 'presente' THEN 1 ELSE 0 END) as presentes,
                   ROUND((SUM(CASE WHEN a.estado = 'presente' THEN 1 ELSE 0 END) * 100.0 / COUNT(*)), 2) as porcentaje_presentes
            FROM asistencias a
            JOIN inscripciones i ON a.inscripcion_id = i.id
            JOIN materias m ON a.materia_id = m.id
            $sql_where
            GROUP BY m.id, m.nombre
            HAVING COUNT(*) >= 5  -- Solo materias con al menos 5 registros
            ORDER BY porcentaje_presentes DESC
            LIMIT 10
        ");
        $stmt->execute($params);
        $estadisticas['asistencias_materia'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Asistencias por día de la semana
        $stmt = $pdo->prepare("
            SELECT 
                DAYNAME(a.fecha) as dia_semana,
                COUNT(*) as total,
                SUM(CASE WHEN a.estado = 'presente' THEN 1 ELSE 0 END) as presentes,
                ROUND((SUM(CASE WHEN a.estado = 'presente' THEN 1 ELSE 0 END) * 100.0 / COUNT(*)), 2) as porcentaje_presentes
            FROM asistencias a
            JOIN inscripciones i ON a.inscripcion_id = i.id
            $sql_where
            GROUP BY DAYNAME(a.fecha), DAYOFWEEK(a.fecha)
            ORDER BY DAYOFWEEK(a.fecha)
        ");
        $stmt->execute($params);
        $estadisticas['asistencias_dia'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Estudiantes con más ausencias
        $stmt = $pdo->prepare("
            SELECT e.nombre, e.apellido, s.nombre as seccion,
                   COUNT(*) as total_asistencias,
                   SUM(CASE WHEN a.estado = 'ausente' THEN 1 ELSE 0 END) as ausencias,
                   ROUND((SUM(CASE WHEN a.estado = 'ausente' THEN 1 ELSE 0 END) * 100.0 / COUNT(*)), 2) as porcentaje_ausencias
            FROM asistencias a
            JOIN inscripciones i ON a.inscripcion_id = i.id
            JOIN estudiantes e ON i.estudiante_id = e.id
            JOIN secciones s ON i.seccion_id = s.id
            $sql_where
            GROUP BY e.id, e.nombre, e.apellido, s.nombre
            HAVING COUNT(*) >= 5  -- Solo estudiantes con al menos 5 registros
            ORDER BY ausencias DESC, porcentaje_ausencias DESC
            LIMIT 10
        ");
        $stmt->execute($params);
        $estadisticas['estudiantes_ausentes'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Evolución mensual de asistencias
        $stmt = $pdo->prepare("
            SELECT 
                DATE_FORMAT(a.fecha, '%Y-%m') as mes,
                COUNT(*) as total,
                SUM(CASE WHEN a.estado = 'presente' THEN 1 ELSE 0 END) as presentes,
                ROUND((SUM(CASE WHEN a.estado = 'presente' THEN 1 ELSE 0 END) * 100.0 / COUNT(*)), 2) as porcentaje_presentes
            FROM asistencias a
            JOIN inscripciones i ON a.inscripcion_id = i.id
            $sql_where
            GROUP BY DATE_FORMAT(a.fecha, '%Y-%m')
            ORDER BY mes
        ");
        $stmt->execute($params);
        $estadisticas['evolucion_mensual'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Preparar datos para gráficas
        // Gráfica de estados
        $datos_graficas['labels_estados'] = [];
        $datos_graficas['data_estados'] = [];
        $colores_estados = [
            'presente' => 'rgba(75, 192, 192, 0.8)',
            'ausente' => 'rgba(255, 99, 132, 0.8)',
            'tardanza' => 'rgba(255, 206, 86, 0.8)',
            'justificado' => 'rgba(153, 102, 255, 0.8)'
        ];
        
        foreach ($estadisticas['asistencias_estado'] as $estado) {
            $datos_graficas['labels_estados'][] = ucfirst($estado['estado']);
            $datos_graficas['data_estados'][] = intval($estado['cantidad']);
            $datos_graficas['colores_estados'][] = $colores_estados[$estado['estado']] ?? 'rgba(201, 203, 207, 0.8)';
        }

        // Gráfica por sección (solo top 5)
        $datos_graficas['labels_secciones'] = [];
        $datos_graficas['data_presentes_secciones'] = [];
        $datos_graficas['data_ausentes_secciones'] = [];
        
        $top_secciones = array_slice($estadisticas['asistencias_seccion'], 0, 5);
        foreach ($top_secciones as $seccion) {
            $datos_graficas['labels_secciones'][] = $seccion['seccion'];
            $datos_graficas['data_presentes_secciones'][] = intval($seccion['presentes']);
            $datos_graficas['data_ausentes_secciones'][] = intval($seccion['ausentes']);
        }

        // Gráfica evolución mensual
        $datos_graficas['labels_meses'] = [];
        $datos_graficas['data_porcentaje_mensual'] = [];
        
        foreach ($estadisticas['evolucion_mensual'] as $mes) {
            $datos_graficas['labels_meses'][] = $mes['mes'];
            $datos_graficas['data_porcentaje_mensual'][] = floatval($mes['porcentaje_presentes']);
        }

        // Gráfica por día de la semana
        $datos_graficas['labels_dias'] = [];
        $datos_graficas['data_porcentaje_dias'] = [];
        
        foreach ($estadisticas['asistencias_dia'] as $dia) {
            $datos_graficas['labels_dias'][] = $dia['dia_semana'];
            $datos_graficas['data_porcentaje_dias'][] = floatval($dia['porcentaje_presentes']);
        }

    } catch (PDOException $e) {
        error_log("Error al cargar estadísticas de asistencias: " . $e->getMessage());
    }
}
?>

<?php include '../../includes/header.php'; ?>

<?php if (!empty($esquema_faltante)): ?>
    <div class="container-fluid mt-3">
        <div class="alert alert-warning border-warning" role="alert">
            <strong>Advertencia de esquema:</strong>
            faltan tablas requeridas para reportes: <?php echo htmlspecialchars(implode(', ', $esquema_faltante)); ?>.
            Algunas funciones de reportes no estarán disponibles hasta que el esquema esté corregido.
        </div>
    </div>
<?php endif; ?>

<div class="row">
    <?php include '../../includes/sidebar_admin.php'; ?>

    <div class="col-md-9">
        <div class="p-4">
            <h2 class="mb-4">Reportes de Asistencias</h2>

            <!-- Filtros -->
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="card-title mb-0">Filtros de Reportes</h5>
                </div>
                <div class="card-body">
                    <form method="GET" class="row g-3">
                        <div class="col-md-3">
                            <label for="año_escolar_id" class="form-label">Año Escolar</label>
                            <select class="form-select" id="año_escolar_id" name="año_escolar_id" required>
                                <option value="">Seleccionar año...</option>
                                <?php foreach ($años_escolares as $año): ?>
                                    <option value="<?php echo $año['id']; ?>" 
                                        <?php echo $año_escolar_id == $año['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($año['nombre']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label for="seccion_id" class="form-label">Sección</label>
                            <select class="form-select" id="seccion_id" name="seccion_id">
                                <option value="">Todas las secciones</option>
                                <?php foreach ($secciones as $seccion): ?>
                                    <option value="<?php echo $seccion['id']; ?>" 
                                        <?php echo $seccion_id == $seccion['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($seccion['nombre']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label for="materia_id" class="form-label">Materia</label>
                            <select class="form-select" id="materia_id" name="materia_id">
                                <option value="">Todas las materias</option>
                                <?php foreach ($materias as $materia): ?>
                                    <option value="<?php echo $materia['id']; ?>" 
                                        <?php echo $materia_id == $materia['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($materia['nombre'] . ' (' . $materia['codigo'] . ')'); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label for="fecha_inicio" class="form-label">Fecha Inicio</label>
                            <input type="date" class="form-control" id="fecha_inicio" name="fecha_inicio" 
                                   value="<?php echo htmlspecialchars($fecha_inicio); ?>">
                        </div>
                        <div class="col-md-3">
                            <label for="fecha_fin" class="form-label">Fecha Fin</label>
                            <input type="date" class="form-control" id="fecha_fin" name="fecha_fin" 
                                   value="<?php echo htmlspecialchars($fecha_fin); ?>">
                        </div>
                        <div class="col-12">
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-filter me-2"></i>Generar Reporte
                            </button>
                            <a href="asistencias.php" class="btn btn-outline-secondary ms-2">
                                <i class="fas fa-times me-2"></i>Limpiar
                            </a>
                            <button type="button" class="btn btn-success ms-2" onclick="exportarPDF()">
                                <i class="fas fa-file-pdf me-2"></i>Exportar PDF
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <?php if ($año_escolar_id && !empty($estadisticas)): ?>
                <!-- Resumen General -->
                <div class="row mb-4">
                    <div class="col-md-3">
                        <div class="card bg-primary text-white">
                            <div class="card-body text-center p-3">
                                <h3 class="mb-0">
                                    <?php 
                                    $total_asistencias = array_sum(array_column($estadisticas['asistencias_estado'], 'cantidad'));
                                    echo number_format($total_asistencias);
                                    ?>
                                </h3>
                                <small>Total Registros</small>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card bg-success text-white">
                            <div class="card-body text-center p-3">
                                <h3 class="mb-0">
                                    <?php 
                                    $presentes = array_sum(array_column(array_filter($estadisticas['asistencias_estado'], function($a) {
                                        return $a['estado'] == 'presente';
                                    }), 'cantidad'));
                                    echo number_format($presentes);
                                    ?>
                                </h3>
                                <small>Presentes</small>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card bg-danger text-white">
                            <div class="card-body text-center p-3">
                                <h3 class="mb-0">
                                    <?php 
                                    $ausentes = array_sum(array_column(array_filter($estadisticas['asistencias_estado'], function($a) {
                                        return $a['estado'] == 'ausente';
                                    }), 'cantidad'));
                                    echo number_format($ausentes);
                                    ?>
                                </h3>
                                <small>Ausentes</small>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card bg-info text-white">
                            <div class="card-body text-center p-3">
                                <h3 class="mb-0">
                                    <?php 
                                    $porcentaje_presentes = $total_asistencias > 0 ? ($presentes / $total_asistencias) * 100 : 0;
                                    echo number_format($porcentaje_presentes, 1) . '%';
                                    ?>
                                </h3>
                                <small>% Asistencia</small>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Gráficas -->
                <div class="row mb-4">
                    <!-- Distribución por Estado -->
                    <div class="col-md-6 mb-4">
                        <div class="card">
                            <div class="card-header">
                                <h5 class="card-title mb-0">Distribución por Estado</h5>
                            </div>
                            <div class="card-body">
                                <canvas id="chartEstados" height="250"></canvas>
                            </div>
                        </div>
                    </div>

                    <!-- Asistencia por Sección -->
                    <div class="col-md-6 mb-4">
                        <div class="card">
                            <div class="card-header">
                                <h5 class="card-title mb-0">Asistencia por Sección (Top 5)</h5>
                            </div>
                            <div class="card-body">
                                <canvas id="chartSecciones" height="250"></canvas>
                            </div>
                        </div>
                    </div>

                    <!-- Evolución Mensual -->
                    <div class="col-md-6 mb-4">
                        <div class="card">
                            <div class="card-header">
                                <h5 class="card-title mb-0">Evolución de Asistencia Mensual</h5>
                            </div>
                            <div class="card-body">
                                <canvas id="chartEvolucion" height="250"></canvas>
                            </div>
                        </div>
                    </div>

                    <!-- Asistencia por Día de la Semana -->
                    <div class="col-md-6 mb-4">
                        <div class="card">
                            <div class="card-header">
                                <h5 class="card-title mb-0">Asistencia por Día de la Semana</h5>
                            </div>
                            <div class="card-body">
                                <canvas id="chartDias" height="250"></canvas>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Tablas Detalladas -->
                <div class="row">
                    <!-- Top Materias con Mejor Asistencia -->
                    <div class="col-md-6 mb-4">
                        <div class="card">
                            <div class="card-header">
                                <h5 class="card-title mb-0">Top 10 Materias con Mejor Asistencia</h5>
                            </div>
                            <div class="card-body">
                                <div class="table-responsive">
                                    <table class="table table-sm table-striped">
                                        <thead>
                                            <tr>
                                                <th>Materia</th>
                                                <th>Total</th>
                                                <th>Presentes</th>
                                                <th>% Asistencia</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($estadisticas['asistencias_materia'] as $materia): ?>
                                                <tr>
                                                    <td><?php echo htmlspecialchars($materia['materia']); ?></td>
                                                    <td><?php echo $materia['total']; ?></td>
                                                    <td><?php echo $materia['presentes']; ?></td>
                                                    <td>
                                                        <span class="badge bg-<?php echo $materia['porcentaje_presentes'] >= 80 ? 'success' : ($materia['porcentaje_presentes'] >= 60 ? 'warning' : 'danger'); ?>">
                                                            <?php echo $materia['porcentaje_presentes']; ?>%
                                                        </span>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Estudiantes con Más Ausencias -->
                    <div class="col-md-6 mb-4">
                        <div class="card">
                            <div class="card-header">
                                <h5 class="card-title mb-0">Estudiantes con Más Ausencias</h5>
                            </div>
                            <div class="card-body">
                                <div class="table-responsive">
                                    <table class="table table-sm table-striped">
                                        <thead>
                                            <tr>
                                                <th>Estudiante</th>
                                                <th>Sección</th>
                                                <th>Ausencias</th>
                                                <th>Total</th>
                                                <th>% Ausencia</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($estadisticas['estudiantes_ausentes'] as $estudiante): ?>
                                                <tr>
                                                    <td><?php echo htmlspecialchars($estudiante['nombre'] . ' ' . $estudiante['apellido']); ?></td>
                                                    <td><?php echo htmlspecialchars($estudiante['seccion']); ?></td>
                                                    <td><?php echo $estudiante['ausencias']; ?></td>
                                                    <td><?php echo $estudiante['total_asistencias']; ?></td>
                                                    <td>
                                                        <span class="badge bg-<?php echo $estudiante['porcentaje_ausencias'] >= 50 ? 'danger' : ($estudiante['porcentaje_ausencias'] >= 30 ? 'warning' : 'info'); ?>">
                                                            <?php echo $estudiante['porcentaje_ausencias']; ?>%
                                                        </span>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

            <?php elseif ($año_escolar_id && empty($estadisticas)): ?>
                <div class="alert alert-info">
                    <i class="fas fa-info-circle me-2"></i>
                    No hay datos de asistencias para los filtros seleccionados.
                </div>
            <?php else: ?>
                <div class="alert alert-warning">
                    <i class="fas fa-exclamation-triangle me-2"></i>
                    Selecciona un año escolar para generar el reporte de asistencias.
                </div>
            <?php endif; ?>
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
    // Gráfica de Estados
    if (datosGraficas.labels_estados && datosGraficas.labels_estados.length > 0) {
        const ctx1 = document.getElementById('chartEstados').getContext('2d');
        new Chart(ctx1, {
            type: 'doughnut',
            data: {
                labels: datosGraficas.labels_estados,
                datasets: [{
                    data: datosGraficas.data_estados,
                    backgroundColor: datosGraficas.colores_estados,
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

    // Gráfica por Sección
    if (datosGraficas.labels_secciones && datosGraficas.labels_secciones.length > 0) {
        const ctx2 = document.getElementById('chartSecciones').getContext('2d');
        new Chart(ctx2, {
            type: 'bar',
            data: {
                labels: datosGraficas.labels_secciones,
                datasets: [
                    {
                        label: 'Presentes',
                        data: datosGraficas.data_presentes_secciones,
                        backgroundColor: 'rgba(75, 192, 192, 0.8)',
                        borderColor: 'rgba(75, 192, 192, 1)',
                        borderWidth: 1
                    },
                    {
                        label: 'Ausentes',
                        data: datosGraficas.data_ausentes_secciones,
                        backgroundColor: 'rgba(255, 99, 132, 0.8)',
                        borderColor: 'rgba(255, 99, 132, 1)',
                        borderWidth: 1
                    }
                ]
            },
            options: {
                responsive: true,
                scales: {
                    y: {
                        beginAtZero: true
                    }
                }
            }
        });
    }

    // Gráfica de Evolución Mensual
    if (datosGraficas.labels_meses && datosGraficas.labels_meses.length > 0) {
        const ctx3 = document.getElementById('chartEvolucion').getContext('2d');
        new Chart(ctx3, {
            type: 'line',
            data: {
                labels: datosGraficas.labels_meses,
                datasets: [{
                    label: '% de Asistencia',
                    data: datosGraficas.data_porcentaje_mensual,
                    backgroundColor: 'rgba(54, 162, 235, 0.2)',
                    borderColor: 'rgba(54, 162, 235, 1)',
                    borderWidth: 2,
                    tension: 0.4,
                    fill: true
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
                            text: 'Porcentaje de Asistencia (%)'
                        }
                    }
                }
            }
        });
    }

    // Gráfica por Día de la Semana
    if (datosGraficas.labels_dias && datosGraficas.labels_dias.length > 0) {
        const ctx4 = document.getElementById('chartDias').getContext('2d');
        new Chart(ctx4, {
            type: 'bar',
            data: {
                labels: datosGraficas.labels_dias,
                datasets: [{
                    label: '% de Asistencia',
                    data: datosGraficas.data_porcentaje_dias,
                    backgroundColor: 'rgba(153, 102, 255, 0.8)',
                    borderColor: 'rgba(153, 102, 255, 1)',
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
                            text: 'Porcentaje de Asistencia (%)'
                        }
                    }
                }
            }
        });
    }
});

// Función para exportar a PDF (placeholder)
function exportarPDF() {
    alert('Funcionalidad de exportación PDF en desarrollo. Por ahora, puedes usar la función de imprimir del navegador.');
    // window.print(); // Opción alternativa: imprimir la página
}

// Actualizar secciones cuando cambie el año escolar
document.getElementById('año_escolar_id').addEventListener('change', function() {
    const añoId = this.value;
    if (añoId) {
        // Recargar con el nuevo año escolar
        window.location.href = `asistencias.php?año_escolar_id=${añoId}`;
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
.card {
    border: none;
    box-shadow: 0 2px 10px rgba(0,0,0,0.1);
}
</style>

<?php include '../../includes/footer.php'; ?>