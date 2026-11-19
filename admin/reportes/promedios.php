<?php
require_once '../../includes/auth.php';
require_once '../../includes/database.php';
require_once '../../includes/functions_notas.php';
checkAuth();
checkRole(['admin']);
requireOnlineFeature($pdo, 'El reporte de promedios');

$esquema_faltante = verificarEsquemaReportes(['notas', 'lapsos', 'inscripciones', 'años_escolares']);

// Obtener parámetros
$seccion_id = $_GET['seccion_id'] ?? '';
$lapso_id = $_GET['lapso_id'] ?? '';
$estudiante_id = $_GET['estudiante_id'] ?? '';
$busqueda = $_GET['busqueda'] ?? '';

// Obtener datos para filtros
try {
    // Secciones
    $stmt_secciones = $pdo->query("
        SELECT s.*, ae.nombre as año_escolar 
        FROM secciones s 
        JOIN años_escolares ae ON s.año_escolar_id = ae.id 
        WHERE ae.estado = 'activo'
        ORDER BY s.nombre
    ");
    $secciones = $stmt_secciones->fetchAll(PDO::FETCH_ASSOC);
    
    // Lapsos
    $stmt_año = $pdo->query("SELECT id FROM años_escolares WHERE estado = 'activo' LIMIT 1");
    $año_activo = $stmt_año->fetch(PDO::FETCH_ASSOC);
    $lapsos = [];
    
    if ($año_activo) {
        $stmt_lapsos = $pdo->prepare("SELECT * FROM lapsos WHERE año_escolar_id = ? ORDER BY numero");
        $stmt_lapsos->execute([$año_activo['id']]);
        $lapsos = $stmt_lapsos->fetchAll(PDO::FETCH_ASSOC);
    }
    
    // Estudiantes (si hay sección seleccionada o búsqueda)
    $estudiantes = [];
    
    // Obtener el año escolar activo para las consultas
    $stmt_año = $pdo->query("SELECT id FROM años_escolares WHERE estado = 'activo' LIMIT 1");
    $año_activo = $stmt_año->fetch(PDO::FETCH_ASSOC);
    $año_escolar_id = $año_activo ? $año_activo['id'] : null;
    
    if ($año_escolar_id) {
        $sql = "
            SELECT i.id as inscripcion_id, e.id, e.cedula_escolar, e.nombre, e.apellido,
                   s.nombre as seccion_nombre, ae.nombre as año_escolar_nombre
            FROM inscripciones i
            JOIN estudiantes e ON i.estudiante_id = e.id
            LEFT JOIN secciones s ON i.seccion_id = s.id
            LEFT JOIN años_escolares ae ON i.año_escolar_id = ae.id
            WHERE i.año_escolar_id = ? AND i.status = 'activo'
        ";
        
        $params = [$año_escolar_id];
        
        if ($seccion_id) {
            $sql .= " AND i.seccion_id = ?";
            $params[] = $seccion_id;
        }
        
        if ($busqueda) {
            $sql .= " AND (e.nombre LIKE ? OR e.apellido LIKE ? OR e.cedula_escolar LIKE ?)";
            $like_busqueda = "%$busqueda%";
            $params[] = $like_busqueda;
            $params[] = $like_busqueda;
            $params[] = $like_busqueda;
        }
        
        $sql .= " ORDER BY e.apellido, e.nombre";
        
        $stmt_estudiantes = $pdo->prepare($sql);
        $stmt_estudiantes->execute($params);
        $estudiantes = $stmt_estudiantes->fetchAll(PDO::FETCH_ASSOC);
    }
    
} catch (PDOException $e) {
    error_log("Error cargando datos: " . $e->getMessage());
    $secciones = $lapsos = $estudiantes = [];
}

// Obtener promedios si hay estudiante seleccionado
$promedios_data = [];
if ($estudiante_id) {
    // Encontrar la inscripción_id
    $inscripcion_id = null;
    foreach ($estudiantes as $est) {
        if ($est['id'] == $estudiante_id) {
            $inscripcion_id = $est['inscripcion_id'];
            break;
        }
    }
    
    if ($inscripcion_id) {
        $promedios_data = obtenerPromediosEstudiante($inscripcion_id);
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

<div class="container-fluid">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2><i class="fas fa-chart-bar me-2"></i>Reporte de Promedios</h2>
    </div>

    <!-- Filtros y Buscador -->
    <div class="card mb-4">
        <div class="card-header">
            <h5 class="card-title mb-0">Filtrar y Buscar Estudiantes</h5>
        </div>
        <div class="card-body">
            <!-- Buscador rápido -->
            <div class="row mb-4">
                <div class="col-12">
                    <label class="form-label"><strong>Búsqueda Rápida de Estudiante</strong></label>
                    <form method="GET" class="row g-2">
                        <input type="hidden" name="seccion_id" value="<?php echo htmlspecialchars($seccion_id); ?>">
                        <input type="hidden" name="lapso_id" value="<?php echo htmlspecialchars($lapso_id); ?>">
                        <div class="col-md-8">
                            <input type="text" 
                                   class="form-control" 
                                   name="busqueda" 
                                   value="<?php echo htmlspecialchars($busqueda); ?>"
                                   placeholder="Buscar por nombre, apellido o cédula escolar..."
                                   required>
                        </div>
                        <div class="col-md-4">
                            <button type="submit" class="btn btn-primary w-100">
                                <i class="fas fa-search me-2"></i>Buscar Estudiante
                            </button>
                        </div>
                    </form>
                    <?php if ($busqueda): ?>
                        <div class="mt-2">
                            <small class="text-muted">
                                Buscando: "<?php echo htmlspecialchars($busqueda); ?>"
                                <a href="promedios.php" class="text-danger ms-2">
                                    <i class="fas fa-times"></i> Limpiar búsqueda
                                </a>
                            </small>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <hr>

            <!-- Filtros tradicionales -->
            <form method="GET" class="row g-3">
                <input type="hidden" name="busqueda" value="<?php echo htmlspecialchars($busqueda); ?>">
                
                <div class="col-md-4">
                    <label for="seccion_id" class="form-label">Sección</label>
                    <select class="form-select" id="seccion_id" name="seccion_id">
                        <option value="">Todas las secciones</option>
                        <?php foreach ($secciones as $seccion): ?>
                            <option value="<?php echo $seccion['id']; ?>" <?php echo $seccion_id == $seccion['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($seccion['nombre'] . ' - ' . $seccion['año_escolar']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="col-md-4">
                    <label for="lapso_id" class="form-label">Lapso</label>
                    <select class="form-select" id="lapso_id" name="lapso_id">
                        <option value="">Todos los lapsos</option>
                        <?php foreach ($lapsos as $lapso): ?>
                            <option value="<?php echo $lapso['id']; ?>" <?php echo $lapso_id == $lapso['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($lapso['nombre']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="col-md-4">
                    <label for="estudiante_id" class="form-label">Estudiante</label>
                    <select class="form-select" id="estudiante_id" name="estudiante_id" required>
                        <option value="">Seleccionar estudiante...</option>
                        <?php foreach ($estudiantes as $estudiante): ?>
                            <option value="<?php echo $estudiante['id']; ?>" <?php echo $estudiante_id == $estudiante['id'] ? 'selected' : ''; ?>>
                                <?php 
                                $info_estudiante = htmlspecialchars($estudiante['nombre'] . ' ' . $estudiante['apellido'] . ' (' . $estudiante['cedula_escolar'] . ')');
                                if ($estudiante['seccion_nombre']) {
                                    $info_estudiante .= ' - ' . htmlspecialchars($estudiante['seccion_nombre']);
                                }
                                echo $info_estudiante;
                                ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <?php if (empty($estudiantes) && ($seccion_id || $busqueda)): ?>
                        <div class="form-text text-warning">
                            <i class="fas fa-exclamation-triangle"></i> No hay estudiantes que coincidan con los filtros
                        </div>
                    <?php elseif ($busqueda && !empty($estudiantes)): ?>
                        <div class="form-text text-success">
                            <i class="fas fa-check-circle"></i> <?php echo count($estudiantes); ?> estudiante(s) encontrado(s)
                        </div>
                    <?php endif; ?>
                </div>
                
                <div class="col-12">
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-chart-bar me-2"></i>Generar Reporte de Promedios
                    </button>
                    <?php if ($estudiante_id || $busqueda || $seccion_id): ?>
                        <a href="promedios.php" class="btn btn-outline-secondary ms-2">
                            <i class="fas fa-times me-2"></i>Limpiar Todo
                        </a>
                    <?php endif; ?>
                </div>
            </form>
        </div>
    </div>

    <!-- Información del estudiante seleccionado -->
    <?php if ($estudiante_id && !empty($estudiantes)): ?>
        <?php 
        $estudiante_actual = null;
        foreach ($estudiantes as $est) {
            if ($est['id'] == $estudiante_id) {
                $estudiante_actual = $est;
                break;
            }
        }
        ?>
        <?php if ($estudiante_actual): ?>
            <div class="alert alert-info">
                <div class="row">
                    <div class="col-md-6">
                        <strong><i class="fas fa-user-graduate me-2"></i>Estudiante:</strong>
                        <?php echo htmlspecialchars($estudiante_actual['nombre'] . ' ' . $estudiante_actual['apellido']); ?>
                    </div>
                    <div class="col-md-3">
                        <strong><i class="fas fa-id-card me-2"></i>Cédula:</strong>
                        <?php echo htmlspecialchars($estudiante_actual['cedula_escolar']); ?>
                    </div>
                    <div class="col-md-3">
                        <strong><i class="fas fa-users me-2"></i>Sección:</strong>
                        <?php echo $estudiante_actual['seccion_nombre'] ? htmlspecialchars($estudiante_actual['seccion_nombre']) : '<span class="text-muted">No asignada</span>'; ?>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    <?php endif; ?>

    <!-- Reporte de promedios -->
    <?php if (!empty($promedios_data) && !empty($promedios_data['materias'])): ?>
        <div class="card">
            <div class="card-header">
                <h5 class="card-title mb-0">
                    <i class="fas fa-chart-line me-2"></i>
                    Reporte de Promedios
                </h5>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-striped table-bordered">
                        <thead class="table-dark">
                            <tr>
                                <th>Materia</th>
                                <?php foreach ($promedios_data['lapsos'] as $lapso): ?>
                                    <th class="text-center"><?php echo htmlspecialchars($lapso['nombre']); ?></th>
                                <?php endforeach; ?>
                                <th class="text-center">Promedio General</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            $totales_lapsos = array_fill_keys(array_column($promedios_data['lapsos'], 'numero'), ['suma' => 0, 'count' => 0]);
                            $materias_con_notas = 0;
                            
                            foreach ($promedios_data['materias'] as $materia): 
                                $materia_tiene_notas = false;
                                $suma_materia = 0;
                                $count_materia = 0;
                            ?>
                                <tr>
                                    <td>
                                        <strong><?php echo htmlspecialchars($materia['nombre']); ?></strong>
                                        <br>
                                        <small class="text-muted"><?php echo htmlspecialchars($materia['codigo']); ?></small>
                                    </td>
                                    
                                    <?php foreach ($promedios_data['lapsos'] as $lapso): 
                                        $promedio_lapso = $materia['lapsos'][$lapso['numero']]['promedio'] ?? null;
                                        $total_evaluaciones = $materia['lapsos'][$lapso['numero']]['total_evaluaciones'] ?? 0;
                                        
                                        // Calcular color según promedio
                                        $color = 'text-muted';
                                        $icon = '';
                                        if ($promedio_lapso !== null) {
                                            $materia_tiene_notas = true;
                                            if ($promedio_lapso >= 10.0) {
                                                $color = 'text-success';
                                                $icon = '<i class="fas fa-check-circle me-1"></i>';
                                            } else {
                                                $color = 'text-danger';
                                                $icon = '<i class="fas fa-times-circle me-1"></i>';
                                            }
                                            
                                            // Acumular para promedios generales
                                            $totales_lapsos[$lapso['numero']]['suma'] += $promedio_lapso;
                                            $totales_lapsos[$lapso['numero']]['count']++;
                                            $suma_materia += $promedio_lapso;
                                            $count_materia++;
                                        }
                                    ?>
                                        <td class="text-center <?php echo $color; ?>">
                                            <?php echo $icon; ?>
                                            <strong><?php echo $promedio_lapso ?? 'N/A'; ?></strong>
                                            <?php if ($total_evaluaciones > 0): ?>
                                                <br>
                                                <small class="text-muted">(<?php echo $total_evaluaciones; ?> eval.)</small>
                                            <?php endif; ?>
                                        </td>
                                    <?php endforeach; ?>
                                    
                                    <!-- Promedio general de la materia -->
                                    <td class="text-center fw-bold">
                                        <?php if ($count_materia > 0): 
                                            $promedio_general_materia = number_format($suma_materia / $count_materia, 2);
                                            $color_general = $promedio_general_materia >= 10.0 ? 'text-success' : 'text-danger';
                                        ?>
                                            <span class="<?php echo $color_general; ?>">
                                                <?php echo $promedio_general_materia; ?>
                                            </span>
                                        <?php else: ?>
                                            <span class="text-muted">N/A</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php 
                                if ($materia_tiene_notas) $materias_con_notas++;
                            endforeach; ?>
                        </tbody>
                        <tfoot class="table-light">
                            <tr>
                                <th>Promedio General por Lapso</th>
                                <?php foreach ($promedios_data['lapsos'] as $lapso): 
                                    $promedio_lapso_general = $totales_lapsos[$lapso['numero']]['count'] > 0 
                                        ? number_format($totales_lapsos[$lapso['numero']]['suma'] / $totales_lapsos[$lapso['numero']]['count'], 2)
                                        : null;
                                    $color_promedio = $promedio_lapso_general !== null 
                                        ? ($promedio_lapso_general >= 10.0 ? 'text-success' : 'text-danger')
                                        : 'text-muted';
                                ?>
                                    <th class="text-center <?php echo $color_promedio; ?>">
                                        <?php echo $promedio_lapso_general ?? 'N/A'; ?>
                                    </th>
                                <?php endforeach; ?>
                                <th class="text-center">
                                    <?php
                                    $suma_total = 0;
                                    $count_total = 0;
                                    foreach ($totales_lapsos as $lapso_data) {
                                        if ($lapso_data['count'] > 0) {
                                            $suma_total += $lapso_data['suma'];
                                            $count_total += $lapso_data['count'];
                                        }
                                    }
                                    $promedio_final = $count_total > 0 ? number_format($suma_total / $count_total, 2) : null;
                                    $color_final = $promedio_final !== null 
                                        ? ($promedio_final >= 10.0 ? 'text-success' : 'text-danger')
                                        : 'text-muted';
                                    ?>
                                    <span class="<?php echo $color_final; ?>">
                                        <?php echo $promedio_final ?? 'N/A'; ?>
                                    </span>
                                </th>
                            </tr>
                        </tfoot>
                    </table>
                </div>
                
                <!-- Resumen -->
                <div class="row mt-4">
                    <div class="col-md-6">
                        <div class="card bg-light">
                            <div class="card-body">
                                <h6 class="card-title">Resumen Académico</h6>
                                <div class="row">
                                    <div class="col-6">
                                        <small class="text-muted">Total Materias:</small>
                                        <br>
                                        <strong><?php echo count($promedios_data['materias']); ?></strong>
                                    </div>
                                    <div class="col-6">
                                        <small class="text-muted">Materias con Notas:</small>
                                        <br>
                                        <strong><?php echo $materias_con_notas; ?></strong>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="card <?php echo $promedio_final && $promedio_final >= 10.0 ? 'bg-success text-white' : 'bg-danger text-white'; ?>">
                            <div class="card-body text-center">
                                <h6 class="card-title">Promedio Final</h6>
                                <h2 class="mb-0"><?php echo $promedio_final ?? 'N/A'; ?></h2>
                                <small>
                                    <?php if ($promedio_final !== null): ?>
                                        <?php echo $promedio_final >= 10.0 ? 'APROBADO' : 'REPROBADO'; ?>
                                    <?php else: ?>
                                        SIN DATOS
                                    <?php endif; ?>
                                </small>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    <?php elseif ($estudiante_id): ?>
        <div class="alert alert-warning">
            <i class="fas fa-exclamation-triangle me-2"></i>
            No se encontraron notas registradas para este estudiante.
        </div>
    <?php endif; ?>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const seccionSelect = document.getElementById('seccion_id');
    const busquedaInput = document.querySelector('input[name="busqueda"]');
    
    // Actualizar lista de estudiantes cuando cambia la sección
    seccionSelect.addEventListener('change', function() {
        // Mantener la búsqueda actual si existe
        if (busquedaInput.value) {
            const url = new URL(window.location);
            url.searchParams.set('seccion_id', this.value);
            url.searchParams.set('busqueda', busquedaInput.value);
            url.searchParams.delete('estudiante_id');
            window.location.href = url.toString();
        } else {
            // Si no hay búsqueda, simplemente recargar con la nueva sección
            const url = new URL(window.location);
            url.searchParams.set('seccion_id', this.value);
            url.searchParams.delete('estudiante_id');
            window.location.href = url.toString();
        }
    });
});
</script>

 </main>
<?php include '../../includes/footer.php'; ?>