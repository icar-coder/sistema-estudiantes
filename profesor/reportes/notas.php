<?php
require_once '../../includes/auth.php';
require_once '../../includes/database.php';
checkAuth();
checkRole(['profesor']);

$profesor_id = $_SESSION['relacion_id'];

// Obtener las secciones y materias asignadas al profesor
try {
    $stmt = $pdo->prepare("
        SELECT psm.*, 
               s.nombre as seccion_nombre,
               s.id as seccion_id,
               m.nombre as materia_nombre,
               m.codigo as materia_codigo,
               m.id as materia_id, 
               ae.nombre as año_escolar
        FROM profesores_secciones_materias psm
        JOIN secciones s ON psm.seccion_id = s.id
        JOIN materias m ON psm.materia_id = m.id
        JOIN años_escolares ae ON psm.año_escolar_id = ae.id
        WHERE psm.profesor_id = ? AND ae.estado = 'activo'
        ORDER BY ae.nombre DESC, s.nombre, m.nombre
    ");
    $stmt->execute([$profesor_id]);
    $asignaciones = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $asignaciones = [];
}

// Filtros
$seccion_filtro = $_GET['seccion_id'] ?? '';
$materia_filtro = $_GET['materia_id'] ?? '';

// Obtener reporte de notas si hay filtros
$reporte_notas = [];
$estadisticas = [];

if ($seccion_filtro && $materia_filtro) {
    // Verificar acceso del profesor
    $acceso_permitido = false;
    foreach ($asignaciones as $asignacion) {
        if ($asignacion['seccion_id'] == $seccion_filtro && $asignacion['materia_id'] == $materia_filtro) {
            $acceso_permitido = true;
            break;
        }
    }
    
    if ($acceso_permitido) {
        // Obtener reporte de notas por estudiante
        try {
            $stmt = $pdo->prepare("
                SELECT 
                    e.cedula_escolar,
                    e.nombre,
                    e.apellido,
                    l.nombre as lapso_nombre,
                    l.numero as lapso_numero,
                    te.nombre as tipo_evaluacion,
                    te.peso,
                    n.valor_decimal,
                    n.es_promedio
                FROM estudiantes e
                JOIN inscripciones i ON e.id = i.estudiante_id
                LEFT JOIN notas n ON i.id = n.inscripcion_id AND n.materia_id = ?
                LEFT JOIN lapsos l ON n.lapso_id = l.id
                LEFT JOIN tipos_evaluacion te ON n.tipo_evaluacion_id = te.id
                WHERE i.seccion_id = ? AND i.año_escolar_id = ? AND i.status = 'activo'
                ORDER BY e.apellido, e.nombre, l.numero, te.orden
            ");
            
            $stmt_año = $pdo->query("SELECT id FROM años_escolares WHERE estado = 'activo' LIMIT 1");
            $año_activo = $stmt_año->fetch(PDO::FETCH_ASSOC);
            
            if ($año_activo) {
                $stmt->execute([$materia_filtro, $seccion_filtro, $año_activo['id']]);
                $reporte_notas = $stmt->fetchAll(PDO::FETCH_ASSOC);
            }
            
        } catch (PDOException $e) {
            $reporte_notas = [];
        }
        
        // Calcular estadísticas básicas
        if ($reporte_notas) {
            $notas_promedio = array_filter(array_column($reporte_notas, 'valor_decimal'));
            if ($notas_promedio) {
                $estadisticas = [
                    'promedio_general' => number_format(array_sum($notas_promedio) / count($notas_promedio), 2),
                    'maxima_nota' => max($notas_promedio),
                    'minima_nota' => min($notas_promedio),
                    'total_estudiantes' => count(array_unique(array_column($reporte_notas, 'cedula_escolar')))
                ];
            }
        }
    }
}
?>

<?php include '../../includes/header.php'; ?>

    <?php include '../../includes/sidebar_profesor.php'; ?>
    
    <div class="content-wrapper">
    <div class="container-fluid p-4">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h2><i class="fas fa-chart-bar me-2"></i>Reportes de Notas</h2>
        </div>

        <!-- Filtros -->
        <div class="card mb-4">
            <div class="card-header bg-info text-white">
                <h5 class="card-title mb-0"><i class="fas fa-filter me-2"></i>Filtrar Reporte</h5>
            </div>
            <div class="card-body">
                <form method="GET" class="row g-3">
                    <div class="col-md-6">
                        <label for="seccion_id" class="form-label">Sección</label>
                        <select class="form-select" id="seccion_id" name="seccion_id" required>
                            <option value="">Seleccionar sección...</option>
                            <?php foreach ($asignaciones as $asignacion): ?>
                                <option value="<?php echo $asignacion['seccion_id']; ?>" 
                                    <?php echo $seccion_filtro == $asignacion['seccion_id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($asignacion['seccion_nombre'] . ' - ' . $asignacion['año_escolar']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label for="materia_id" class="form-label">Materia</label>
                        <select class="form-select" id="materia_id" name="materia_id" required>
                            <option value="">Seleccionar sección primero</option>
                            <?php if ($seccion_filtro): ?>
                                <?php 
                                $materias_filtradas = array_filter($asignaciones, function($a) use ($seccion_filtro) {
                                    return $a['seccion_id'] == $seccion_filtro;
                                });
                                ?>
                                <?php foreach ($materias_filtradas as $materia): ?>
                                    <option value="<?php echo $materia['materia_id']; ?>" 
                                        <?php echo $materia_filtro == $materia['materia_id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($materia['materia_nombre'] . ' (' . $materia['materia_codigo'] . ')'); ?>
                                    </option>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </select>
                    </div>
                    <div class="col-12">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-chart-bar me-2"></i>Generar Reporte
                        </button>
                        <?php if ($seccion_filtro && $materia_filtro): ?>
                            <a href="notas.php" class="btn btn-outline-secondary ms-2">
                                <i class="fas fa-times me-2"></i>Limpiar
                            </a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
        </div>

        <?php if ($seccion_filtro && $materia_filtro): ?>
            <!-- Estadísticas -->
            <?php if ($estadisticas): ?>
            <div class="row mb-4">
                <div class="col-md-3">
                    <div class="card bg-primary text-white">
                        <div class="card-body text-center">
                            <h4><?php echo $estadisticas['promedio_general']; ?></h4>
                            <p class="mb-0">Promedio General</p>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card bg-success text-white">
                        <div class="card-body text-center">
                            <h4><?php echo $estadisticas['maxima_nota']; ?></h4>
                            <p class="mb-0">Nota Máxima</p>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card bg-warning text-white">
                        <div class="card-body text-center">
                            <h4><?php echo $estadisticas['minima_nota']; ?></h4>
                            <p class="mb-0">Nota Mínima</p>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card bg-info text-white">
                        <div class="card-body text-center">
                            <h4><?php echo $estadisticas['total_estudiantes']; ?></h4>
                            <p class="mb-0">Total Estudiantes</p>
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- Reporte detallado -->
            <div class="card">
                <div class="card-header bg-success text-white">
                    <h5 class="card-title mb-0">
                        <i class="fas fa-list me-2"></i>
                        Reporte Detallado de Notas
                        <?php 
                        $seccion_nombre = '';
                        $materia_nombre = '';
                        foreach ($asignaciones as $asig) {
                            if ($asig['seccion_id'] == $seccion_filtro && $asig['materia_id'] == $materia_filtro) {
                                $seccion_nombre = $asig['seccion_nombre'];
                                $materia_nombre = $asig['materia_nombre'];
                                break;
                            }
                        }
                        echo htmlspecialchars(" - $seccion_nombre - $materia_nombre");
                        ?>
                    </h5>
                </div>
                <div class="card-body">
                    <?php if ($reporte_notas): ?>
                        <div class="table-responsive">
                            <table class="table table-striped table-bordered">
                                <thead class="table-dark">
                                    <tr>
                                        <th>Estudiante</th>
                                        <th>Cédula</th>
                                        <th>Lapso</th>
                                        <th>Evaluación</th>
                                        <th>Nota</th>
                                        <th>Peso</th>
                                        <th>Tipo</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php 
                                    $estudiante_actual = '';
                                    foreach ($reporte_notas as $nota): 
                                        $nombre_completo = $nota['nombre'] . ' ' . $nota['apellido'];
                                        $es_nuevo_estudiante = $nombre_completo !== $estudiante_actual;
                                        $estudiante_actual = $nombre_completo;
                                    ?>
                                        <tr>
                                            <td>
                                                <?php if ($es_nuevo_estudiante): ?>
                                                    <strong><?php echo htmlspecialchars($nombre_completo); ?></strong>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if ($es_nuevo_estudiante): ?>
                                                    <?php echo htmlspecialchars($nota['cedula_escolar']); ?>
                                                <?php endif; ?>
                                            </td>
                                            <td><?php echo htmlspecialchars($nota['lapso_nombre'] ?? 'N/A'); ?></td>
                                            <td><?php echo htmlspecialchars($nota['tipo_evaluacion'] ?? 'N/A'); ?></td>
                                            <td>
                                                <?php if ($nota['valor_decimal']): ?>
                                                    <span class="badge <?php echo $nota['valor_decimal'] >= 10 ? 'bg-success' : 'bg-danger'; ?>">
                                                        <?php echo $nota['valor_decimal']; ?>
                                                    </span>
                                                <?php else: ?>
                                                    <span class="text-muted">Sin nota</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if ($nota['peso'] && !$nota['es_promedio']): ?>
                                                    <?php echo ($nota['peso'] * 100); ?>%
                                                <?php else: ?>
                                                    <span class="text-muted">-</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if ($nota['es_promedio']): ?>
                                                    <span class="badge bg-info">Promedio</span>
                                                <?php else: ?>
                                                    <span class="badge bg-secondary">Evaluación</span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <div class="alert alert-info text-center">
                            <i class="fas fa-info-circle me-2"></i>
                            No hay notas registradas para esta materia y sección.
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>

<script>
// Actualizar materias cuando se cambia la sección
document.getElementById('seccion_id').addEventListener('change', function() {
    const seccionId = this.value;
    const materiaSelect = document.getElementById('materia_id');
    
    if (seccionId) {
        materiaSelect.innerHTML = '<option value="">Seleccionar materia...</option>';
        <?php foreach ($asignaciones as $asig): ?>
            if (<?php echo $asig['seccion_id']; ?> == seccionId) {
                materiaSelect.innerHTML += '<option value="<?php echo $asig['materia_id']; ?>"><?php echo $asig['materia_nombre'] . ' (' . $asig['materia_codigo'] . ')'; ?></option>';
            }
        <?php endforeach; ?>
    } else {
        materiaSelect.innerHTML = '<option value="">Seleccionar sección primero</option>';
    }
});
</script>

<?php include '../../includes/footer.php'; ?>