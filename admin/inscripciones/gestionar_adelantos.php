<?php
require_once '../../includes/auth.php';
require_once '../../includes/database.php';
checkAuth();
checkRole(['admin']);

$mensaje = '';
$tipo_mensaje = '';

// Verificar si existe la tabla historial_academico
$tabla_historial_existe = false;
try {
    $stmt = $pdo->query("SHOW TABLES LIKE 'historial_academico'");
    $tabla_historial_existe = $stmt->rowCount() > 0;
} catch (PDOException $e) {
    $tabla_historial_existe = false;
}

// Filtros
$filtro_año_escolar = $_GET['año_escolar_id'] ?? '';
$filtro_grado = $_GET['grado'] ?? '';
$filtro_tipo_adelantamiento = $_GET['tipo_adelantamiento'] ?? '';

// Obtener años escolares
try {
    $stmt = $pdo->query("SELECT id, nombre FROM años_escolares ORDER BY nombre DESC");
    $años_escolares = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $años_escolares = [];
}

// Obtener estudiantes adelantados
$estudiantes_adelantados = [];
if ($tabla_historial_existe) {
    try {
        $sql = "
            SELECT 
                ha.*,
                e.id as estudiante_id,
                e.cedula_escolar,
                e.nombre as estudiante_nombre,
                e.apellido as estudiante_apellido,
                e.fecha_nacimiento,
                e.genero,
                ae.nombre as año_escolar_nombre,
                g_anterior.nombre as grado_anterior,
                g_anterior.numero as grado_anterior_numero,
                g_actual.nombre as grado_actual,
                g_actual.numero as grado_actual_numero,
                s.nombre as seccion_actual,
                p.nombre as profesor_nombre,
                p.apellido as profesor_apellido,
                (SELECT COUNT(*) FROM historial_indicadores hi WHERE hi.historial_id = ha.id) as total_indicadores
            FROM historial_academico ha
            JOIN estudiantes e ON ha.estudiante_id = e.id
            JOIN años_escolares ae ON ha.año_escolar_id = ae.id
            JOIN grados g_anterior ON ha.grado_id = g_anterior.id
            LEFT JOIN (
                SELECT i1.estudiante_id, i1.seccion_id, MAX(i1.fecha_inscripcion) as ultima
                FROM inscripciones i1
                WHERE i1.status = 'activo'
                GROUP BY i1.estudiante_id
            ) i ON e.id = i.estudiante_id
            LEFT JOIN secciones s ON i.seccion_id = s.id
            LEFT JOIN grados g_actual ON s.grado_id = g_actual.id
            LEFT JOIN profesores p ON s.profesor_id = p.id
            WHERE 1=1
        ";

        $params = [];

        if ($filtro_año_escolar) {
            $sql .= " AND ha.año_escolar_id = ?";
            $params[] = $filtro_año_escolar;
        }

        if ($filtro_grado) {
            $sql .= " AND g_actual.numero = ?";
            $params[] = $filtro_grado;
        }

        if ($filtro_tipo_adelantamiento) {
            $sql .= " AND ha.tipo_adelantamiento = ?";
            $params[] = $filtro_tipo_adelantamiento;
        }

        $sql .= " ORDER BY ha.fecha_registro DESC, e.apellido, e.nombre";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $estudiantes_adelantados = $stmt->fetchAll(PDO::FETCH_ASSOC);

    } catch (PDOException $e) {
        $mensaje = "Error al cargar estudiantes adelantados: " . $e->getMessage();
        $tipo_mensaje = "danger";
    }
}

// Calcular estadísticas
$total_adelantados = count($estudiantes_adelantados);
$total_academicos = 0;
$total_transferencias = 0;
$total_otros = 0;

foreach ($estudiantes_adelantados as $estudiante) {
    switch ($estudiante['tipo_adelantamiento']) {
        case 'academico': $total_academicos++; break;
        case 'transferencia': $total_transferencias++; break;
        case 'otros': $total_otros++; break;
    }
}
?>

<?php include '../../includes/header.php'; ?>

<div class="content-wrapper" style="padding: 20px;">
    <?php include '../../includes/sidebar_admin.php'; ?>
    
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2>🎓 Gestión de Estudiantes Adelantados</h2>
        <a href="../estudiantes/gestionar.php" class="btn btn-outline-secondary">
            <i class="fas fa-arrow-left me-2"></i>Volver a Inscripciones
        </a>
    </div>

    <?php if ($mensaje): ?>
        <div class="alert alert-<?php echo $tipo_mensaje; ?> alert-dismissible fade show" role="alert">
            <?php echo $mensaje; ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <?php if (!$tabla_historial_existe): ?>
        <div class="alert alert-warning">
            <i class="fas fa-exclamation-triangle me-2"></i>
            La tabla de historial académico no existe. Los estudiantes adelantados no podrán ser gestionados hasta que se cree la tabla.
        </div>
    <?php endif; ?>

    <!-- Estadísticas rápidas -->
    <div class="row mb-4">
        <div class="col-md-3">
            <div class="card bg-primary text-white">
                <div class="card-body text-center">
                    <h3><?php echo $total_adelantados; ?></h3>
                    <p class="mb-0">Total Adelantados</p>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card bg-success text-white">
                <div class="card-body text-center">
                    <h3><?php echo $total_academicos; ?></h3>
                    <p class="mb-0">Por Mérito</p>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card bg-info text-white">
                <div class="card-body text-center">
                    <h3><?php echo $total_transferencias; ?></h3>
                    <p class="mb-0">Transferencias</p>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card bg-warning text-white">
                <div class="card-body text-center">
                    <h3><?php echo $total_otros; ?></h3>
                    <p class="mb-0">Otros Motivos</p>
                </div>
            </div>
        </div>
    </div>

    <!-- Filtros -->
    <div class="card mb-4">
        <div class="card-header bg-light">
            <h6 class="card-title mb-0">
                <i class="fas fa-filter me-2"></i>Filtros de Búsqueda
            </h6>
        </div>
        <div class="card-body">
            <form method="GET" class="row g-3">
                <div class="col-md-4">
                    <label for="año_escolar_id" class="form-label">Año Escolar</label>
                    <select class="form-select" id="año_escolar_id" name="año_escolar_id">
                        <option value="">Todos los años</option>
                        <?php foreach ($años_escolares as $año): ?>
                            <option value="<?php echo $año['id']; ?>" 
                                <?php echo $filtro_año_escolar == $año['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($año['nombre']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4">
                    <label for="grado" class="form-label">Grado Actual</label>
                    <select class="form-select" id="grado" name="grado">
                        <option value="">Todos los grados</option>
                        <?php for ($i = 1; $i <= 6; $i++): ?>
                            <option value="<?php echo $i; ?>" 
                                <?php echo $filtro_grado == $i ? 'selected' : ''; ?>>
                                <?php echo $i . '° Grado'; ?>
                            </option>
                        <?php endfor; ?>
                    </select>
                </div>
                <div class="col-md-4">
                    <label for="tipo_adelantamiento" class="form-label">Tipo de Adelantamiento</label>
                    <select class="form-select" id="tipo_adelantamiento" name="tipo_adelantamiento">
                        <option value="">Todos los tipos</option>
                        <option value="academico" <?php echo $filtro_tipo_adelantamiento == 'academico' ? 'selected' : ''; ?>>Mérito Académico</option>
                        <option value="transferencia" <?php echo $filtro_tipo_adelantamiento == 'transferencia' ? 'selected' : ''; ?>>Transferencia</option>
                        <option value="otros" <?php echo $filtro_tipo_adelantamiento == 'otros' ? 'selected' : ''; ?>>Otros Motivos</option>
                    </select>
                </div>
                <div class="col-12">
                    <button type="submit" class="btn btn-outline-primary me-2">
                        <i class="fas fa-filter me-1"></i>Filtrar
                    </button>
                    <a href="gestionar_adelantos.php" class="btn btn-outline-secondary">
                        <i class="fas fa-times me-1"></i>Limpiar Filtros
                    </a>
                    <?php if ($estudiantes_adelantados): ?>
                        <span class="text-muted ms-2">
                            <i class="fas fa-info-circle me-1"></i>
                            Mostrando <?php echo count($estudiantes_adelantados); ?> estudiante(s) adelantado(s)
                        </span>
                    <?php endif; ?>
                </div>
            </form>
        </div>
    </div>

    <div class="card">
        <div class="card-header">
            <h5 class="card-title mb-0">
                <i class="fas fa-list me-2"></i>Historial de Estudiantes Adelantados
            </h5>
        </div>
        <div class="card-body">
            <?php if ($estudiantes_adelantados): ?>
                <div class="table-responsive">
                    <table class="table table-striped table-hover">
                        <thead>
                            <tr>
                                <th>Estudiante</th>
                                <th>Información Académica</th>
                                <th>Situación Actual</th>
                                <th>Evaluación</th>
                                <th>Tipo</th>
                                <th>Fecha</th>
                                <th>Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($estudiantes_adelantados as $estudiante): 
                                $edad = $estudiante['fecha_nacimiento'] ? floor((time() - strtotime($estudiante['fecha_nacimiento'])) / 31556926) : null;
                                
                                // Colores para los tipos de adelantamiento
                                $tipo_badge = [
                                    'academico' => 'bg-success',
                                    'transferencia' => 'bg-info', 
                                    'otros' => 'bg-warning'
                                ];
                                
                                $tipo_texto = [
                                    'academico' => 'Mérito Académico',
                                    'transferencia' => 'Transferencia',
                                    'otros' => 'Otros Motivos'
                                ];
                                
                                // Colores para los literales
                                $literal_badge = [
                                    'A' => 'bg-success',
                                    'B' => 'bg-info',
                                    'C' => 'bg-warning',
                                    'D' => 'bg-danger',
                                    'E' => 'bg-secondary'
                                ];
                            ?>
                                <tr>
                                    <td>
                                        <div class="d-flex align-items-center">
                                            <span class="badge bg-warning me-2" title="Estudiante Adelantado">
                                                <i class="fas fa-forward"></i>
                                            </span>
                                            <div>
                                                <strong><?php echo htmlspecialchars($estudiante['estudiante_nombre'] . ' ' . $estudiante['estudiante_apellido']); ?></strong>
                                                <br>
                                                <small class="text-muted">
                                                    ID: <?php echo $estudiante['estudiante_id']; ?>
                                                    <?php if ($estudiante['cedula_escolar']): ?>
                                                        | <?php echo htmlspecialchars($estudiante['cedula_escolar']); ?>
                                                    <?php endif; ?>
                                                    <?php if ($edad): ?>
                                                        | <?php echo $edad; ?> años
                                                        <?php if ($estudiante['genero']): ?>
                                                            | <?php echo $estudiante['genero'] == 'masculino' ? '♂' : '♀'; ?>
                                                        <?php endif; ?>
                                                    <?php endif; ?>
                                                </small>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="mb-1">
                                            <small class="text-muted">Grado Anterior:</small>
                                            <br>
                                            <span class="badge bg-secondary"><?php echo htmlspecialchars($estudiante['grado_anterior']); ?></span>
                                        </div>
                                        <div>
                                            <small class="text-muted">Año Escolar:</small>
                                            <br>
                                            <span class="badge bg-primary"><?php echo htmlspecialchars($estudiante['año_escolar_nombre']); ?></span>
                                        </div>
                                    </td>
                                    <td>
                                        <?php if ($estudiante['grado_actual']): ?>
                                            <div class="mb-1">
                                                <small class="text-muted">Grado Actual:</small>
                                                <br>
                                                <span class="badge bg-success"><?php echo htmlspecialchars($estudiante['grado_actual']); ?></span>
                                            </div>
                                            <div class="mb-1">
                                                <small class="text-muted">Sección:</small>
                                                <br>
                                                <span class="badge bg-info"><?php echo htmlspecialchars($estudiante['seccion_actual']); ?></span>
                                            </div>
                                            <?php if ($estudiante['profesor_nombre']): ?>
                                                <div>
                                                    <small class="text-muted">Profesor:</small>
                                                    <br>
                                                    <small><?php echo htmlspecialchars($estudiante['profesor_nombre'] . ' ' . $estudiante['profesor_apellido']); ?></small>
                                                </div>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <span class="text-muted">No inscrito actualmente</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="text-center">
                                            <span class="badge <?php echo $literal_badge[$estudiante['literal_final']] ?? 'bg-secondary'; ?> fs-6">
                                                <?php echo $estudiante['literal_final']; ?>
                                            </span>
                                            <br>
                                            <small class="text-muted">
                                                <?php echo $estudiante['total_indicadores']; ?> indicadores
                                            </small>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="badge <?php echo $tipo_badge[$estudiante['tipo_adelantamiento']] ?? 'bg-secondary'; ?>">
                                            <?php echo $tipo_texto[$estudiante['tipo_adelantamiento']] ?? 'Desconocido'; ?>
                                        </span>
                                        <?php if ($estudiante['observaciones']): ?>
                                            <br>
                                            <small class="text-muted" title="<?php echo htmlspecialchars($estudiante['observaciones']); ?>">
                                                <i class="fas fa-comment"></i> Con observaciones
                                            </small>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <small>
                                            <?php echo date('d/m/Y', strtotime($estudiante['fecha_registro'])); ?>
                                            <br>
                                            <span class="text-muted">
                                                <?php echo date('H:i', strtotime($estudiante['fecha_registro'])); ?>
                                            </span>
                                        </small>
                                    </td>
                                    <td>
                                        <div class="btn-group btn-group-sm">
                                            <a href="../estudiantes/editar.php?id=<?php echo $estudiante['estudiante_id']; ?>" 
                                               class="btn btn-outline-primary" 
                                               title="Editar Estudiante">
                                                <i class="fas fa-user-edit"></i>
                                            </a>
                                            <button type="button" 
                                                    class="btn btn-outline-info" 
                                                    title="Ver Detalles"
                                                    onclick="verDetallesAdelantamiento(<?php echo $estudiante['id']; ?>)">
                                                <i class="fas fa-eye"></i>
                                            </button>
                                            <a href="generar_reporte_adelantamiento.php?id=<?php echo $estudiante['id']; ?>" 
                                               class="btn btn-outline-success" 
                                               title="Generar Reporte"
                                               target="_blank">
                                                <i class="fas fa-file-pdf"></i>
                                            </a>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div class="text-center py-4">
                    <i class="fas fa-user-graduate fa-3x text-muted mb-3"></i>
                    <h5>No hay estudiantes adelantados registrados</h5>
                    <p class="text-muted">
                        <?php if ($tabla_historial_existe): ?>
                            No se encontraron estudiantes adelantados con los filtros aplicados.
                        <?php else: ?>
                            La tabla de historial académico no está disponible.
                        <?php endif; ?>
                    </p>
                    <?php if ($tabla_historial_existe): ?>
                        <a href="../estudiantes/gestionar.php" class="btn btn-primary me-2">
                            <i class="fas fa-list me-2"></i>Ver Inscripciones
                        </a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Modal para detalles -->
<div class="modal fade" id="modalDetalles" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Detalles del Adelantamiento</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="detallesContenido">
                <!-- Contenido cargado via AJAX -->
            </div>
        </div>
    </div>
</div>

<script>
function verDetallesAdelantamiento(historialId) {
    fetch(`ver_detalles_adelantamiento.php?id=${historialId}`)
        .then(response => response.text())
        .then(html => {
            document.getElementById('detallesContenido').innerHTML = html;
            new bootstrap.Modal(document.getElementById('modalDetalles')).show();
        })
        .catch(error => {
            document.getElementById('detallesContenido').innerHTML = '<div class="alert alert-danger">Error al cargar los detalles</div>';
            new bootstrap.Modal(document.getElementById('modalDetalles')).show();
        });
}

// La ruta crear_tablas_historial.php no existe en el proyecto actual.
// Las tablas ya están creadas o se gestionan por migración manual.

document.addEventListener('DOMContentLoaded', function() {
    // Mejorar la experiencia de filtrado
    const filtros = document.querySelectorAll('#año_escolar_id, #grado, #tipo_adelantamiento');
    filtros.forEach(filtro => {
        filtro.addEventListener('change', function() {
            // Opcional: enviar automáticamente el formulario
            // this.form.submit();
        });
    });
});
</script>

<?php include '../../includes/footer.php'; ?>
