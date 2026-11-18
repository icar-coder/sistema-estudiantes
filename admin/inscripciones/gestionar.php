<?php
require_once '../../includes/auth.php';
require_once '../../includes/database.php';
checkAuth();
checkRole(['admin']);

$mensaje = '';
$tipo_mensaje = '';

// Procesar cambio de estado
if (isset($_GET['cambiar_estado'])) {
    $id = $_GET['cambiar_estado'];
    $nuevo_status = $_GET['status'];
    
    try {
        $stmt = $pdo->prepare("UPDATE inscripciones SET status = ? WHERE id = ?");
        $stmt->execute([$nuevo_status, $id]);
        
        $mensaje = "Estado de inscripción actualizado correctamente";
        $tipo_mensaje = "success";
    } catch (PDOException $e) {
        $mensaje = "Error al actualizar inscripción: " . $e->getMessage();
        $tipo_mensaje = "danger";
    }
}

// Filtros
$filtro_seccion = $_GET['seccion_id'] ?? '';
$filtro_status = $_GET['status'] ?? '';
$filtro_año_escolar = $_GET['año_escolar_id'] ?? '';
$filtro_grado = $_GET['grado'] ?? '';
$filtro_tipo_estudiante = $_GET['tipo_estudiante'] ?? '';
$filtro_busqueda = trim($_GET['q'] ?? '');

// Obtener años escolares
try {
    $stmt = $pdo->query("SELECT id, nombre FROM años_escolares ORDER BY nombre DESC");
    $años_escolares = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $años_escolares = [];
}

// Obtener secciones (para filtro)
try {
    $sql_secciones = "SELECT s.id, s.nombre, ae.nombre as año_escolar, s.nivel
                     FROM secciones s 
                     JOIN años_escolares ae ON s.año_escolar_id = ae.id 
                     WHERE s.nivel = 'primaria'
                     ORDER BY ae.nombre DESC, s.nombre";
    $stmt = $pdo->query($sql_secciones);
    $secciones = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $secciones = [];
}

// Verificar si existe la tabla historial_academico para identificar estudiantes adelantados
$tabla_historial_existe = false;
try {
    $tabla_historial_existe = databaseTableExists($pdo, 'historial_academico');
} catch (PDOException $e) {
    $tabla_historial_existe = false;
}

// Construir consulta con filtros - CORREGIDA
$sql = "
    SELECT i.*, 
           e.id as estudiante_id,
           e.cedula_escolar,
           e.nombre as estudiante_nombre,
           e.apellido as estudiante_apellido,
           e.fecha_nacimiento,
           e.genero,
           s.nombre as seccion_nombre,
           s.capacidad,
           s.nivel as seccion_nivel,
           ae.nombre as año_escolar,
           p.nombre as profesor_nombre,
           p.apellido as profesor_apellido,
           (SELECT COUNT(*) FROM inscripciones i2 WHERE i2.seccion_id = s.id AND i2.status = 'activo') as total_inscritos,
           g.nombre as grado_nombre,
           g.numero as grado_numero
";

// Agregar información de adelantamiento si la tabla existe - CORREGIDO
if ($tabla_historial_existe) {
    $sql .= ",
           (SELECT COUNT(*) FROM historial_academico ha 
            WHERE ha.estudiante_id = e.id 
           ) as es_adelantado,
           (SELECT ha.literal_final FROM historial_academico ha 
            WHERE ha.estudiante_id = e.id 
            ORDER BY ha.fecha_registro DESC LIMIT 1
           ) as literal_anterior
    ";
} else {
    $sql .= ",
           0 as es_adelantado,
           NULL as literal_anterior
    ";
}

$sql .= "
    FROM inscripciones i
    JOIN estudiantes e ON i.estudiante_id = e.id
    JOIN secciones s ON i.seccion_id = s.id
    JOIN grados g ON s.grado_id = g.id
    JOIN años_escolares ae ON i.año_escolar_id = ae.id
    LEFT JOIN profesores p ON s.profesor_id = p.id
    WHERE s.nivel = 'primaria'
";

$params = [];

if ($filtro_seccion) {
    $sql .= " AND i.seccion_id = ?";
    $params[] = $filtro_seccion;
}

if ($filtro_status) {
    $sql .= " AND i.status = ?";
    $params[] = $filtro_status;
}

if ($filtro_año_escolar) {
    $sql .= " AND i.año_escolar_id = ?";
    $params[] = $filtro_año_escolar;
}

if ($filtro_grado) {
    $sql .= " AND g.numero = ?";
    $params[] = $filtro_grado;
}

if ($filtro_tipo_estudiante) {
    if ($filtro_tipo_estudiante === 'adelantado') {
        $sql .= " AND (SELECT COUNT(*) FROM historial_academico ha WHERE ha.estudiante_id = e.id) > 0";
    } elseif ($filtro_tipo_estudiante === 'natural') {
        $sql .= " AND (SELECT COUNT(*) FROM historial_academico ha WHERE ha.estudiante_id = e.id) = 0";
    }
}

if ($filtro_busqueda) {
    $sql .= " AND (e.nombre LIKE ? OR e.apellido LIKE ? OR e.cedula_escolar LIKE ?)";
    $like = "%$filtro_busqueda%";
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
}

$sql .= " ORDER BY ae.nombre DESC, s.nombre, e.apellido, e.nombre";

// Obtener inscripciones
try {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $inscripciones = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $inscripciones = [];
    $mensaje = "Error al cargar inscripciones: " . $e->getMessage();
    $tipo_mensaje = "danger";
}

// Calcular estadísticas
$total_activos = 0;
$total_retirados = 0;
$total_graduados = 0;
$total_adelantados = 0;
$total_naturales = 0;

foreach ($inscripciones as $inscripcion) {
    switch ($inscripcion['status']) {
        case 'activo': $total_activos++; break;
        case 'retirado': $total_retirados++; break;
        case 'graduado': $total_graduados++; break;
    }
    
    if ($inscripcion['es_adelantado'] > 0) {
        $total_adelantados++;
    } else {
        $total_naturales++;
    }
}
?>

<?php include '../../includes/header.php'; ?>

<div class="content-wrapper" style="padding: 20px;">
    <?php include '../../includes/sidebar_admin.php'; ?>
    
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2>🎓 Gestión de Inscripciones - PRIMARIA</h2>
        <div>
            <a href="inscribir.php" class="btn btn-primary me-2">
                <i class="fas fa-user-plus me-2"></i>Nueva Inscripción
            </a>
            <?php if ($tabla_historial_existe): ?>
                <a href="gestionar_adelantos.php" class="btn btn-outline-success">
                    <i class="fas fa-forward me-2"></i>Gestionar Adelantos
                </a>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($mensaje): ?>
        <div class="alert alert-<?php echo $tipo_mensaje; ?> alert-dismissible fade show" role="alert">
            <?php echo $mensaje; ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- Estadísticas rápidas -->
    <div class="row mb-4">
        <div class="col-md-2">
            <div class="card bg-primary text-white">
                <div class="card-body text-center">
                    <h3><?php echo count($inscripciones); ?></h3>
                    <p class="mb-0">Total Inscritos</p>
                </div>
            </div>
        </div>
        <div class="col-md-2">
            <div class="card bg-success text-white">
                <div class="card-body text-center">
                    <h3><?php echo $total_activos; ?></h3>
                    <p class="mb-0">Activos</p>
                </div>
            </div>
        </div>
        <div class="col-md-2">
            <div class="card bg-danger text-white">
                <div class="card-body text-center">
                    <h3><?php echo $total_retirados; ?></h3>
                    <p class="mb-0">Retirados</p>
                </div>
            </div>
        </div>
        <div class="col-md-2">
            <div class="card bg-warning text-white">
                <div class="card-body text-center">
                    <h3><?php echo $total_graduados; ?></h3>
                    <p class="mb-0">Graduados</p>
                </div>
            </div>
        </div>
        <div class="col-md-2">
            <div class="card bg-info text-white">
                <div class="card-body text-center">
                    <h3><?php echo $total_naturales; ?></h3>
                    <p class="mb-0">Naturales</p>
                </div>
            </div>
        </div>
        <div class="col-md-2">
            <div class="card bg-secondary text-white">
                <div class="card-body text-center">
                    <h3><?php echo $total_adelantados; ?></h3>
                    <p class="mb-0">Adelantados</p>
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
                <div class="col-md-2">
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
                <div class="col-md-2">
                    <label for="seccion_id" class="form-label">Sección</label>
                    <select class="form-select" id="seccion_id" name="seccion_id">
                        <option value="">Todas las secciones</option>
                        <?php foreach ($secciones as $seccion): ?>
                            <option value="<?php echo $seccion['id']; ?>" 
                                <?php echo $filtro_seccion == $seccion['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($seccion['nombre'] . ' - ' . $seccion['año_escolar']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label for="grado" class="form-label">Grado</label>
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
                <div class="col-md-2">
                    <label for="status" class="form-label">Estado</label>
                    <select class="form-select" id="status" name="status">
                        <option value="">Todos los estados</option>
                        <option value="activo" <?php echo $filtro_status == 'activo' ? 'selected' : ''; ?>>Activo</option>
                        <option value="retirado" <?php echo $filtro_status == 'retirado' ? 'selected' : ''; ?>>Retirado</option>
                        <option value="graduado" <?php echo $filtro_status == 'graduado' ? 'selected' : ''; ?>>Graduado</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label for="tipo_estudiante" class="form-label">Tipo Estudiante</label>
                    <select class="form-select" id="tipo_estudiante" name="tipo_estudiante">
                        <option value="">Todos los tipos</option>
                        <option value="natural" <?php echo $filtro_tipo_estudiante == 'natural' ? 'selected' : ''; ?>>Natural</option>
                        <option value="adelantado" <?php echo $filtro_tipo_estudiante == 'adelantado' ? 'selected' : ''; ?>>Adelantado</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label for="q" class="form-label">Buscar estudiante</label>
                    <input type="text" class="form-control" id="q" name="q"
                           value="<?php echo htmlspecialchars($filtro_busqueda); ?>"
                           placeholder="Nombre, apellido o cédula">
                </div>
                <div class="col-md-2">
                    <label class="form-label d-block">&nbsp;</label>
                    <button type="submit" class="btn btn-outline-primary w-100">
                        <i class="fas fa-filter me-1"></i>Filtrar
                    </button>
                </div>
                <div class="col-12">
                    <a href="gestionar.php" class="btn btn-outline-secondary">
                        <i class="fas fa-times me-1"></i>Limpiar Filtros
                    </a>
                    <?php if ($inscripciones): ?>
                        <span class="text-muted ms-2">
                            <i class="fas fa-info-circle me-1"></i>
                            Mostrando <?php echo count($inscripciones); ?> inscripción(es)
                            <?php if ($filtro_tipo_estudiante): ?>
                                - Tipo: <?php echo $filtro_tipo_estudiante == 'adelantado' ? 'Adelantados' : 'Naturales'; ?>
                            <?php endif; ?>
                        </span>
                    <?php endif; ?>
                </div>
            </form>
        </div>
    </div>

    <div class="card">
        <div class="card-header">
            <h5 class="card-title mb-0">
                <i class="fas fa-list me-2"></i>Lista de Inscripciones - Nivel Primaria
                <?php if ($total_adelantados > 0): ?>
                    <span class="badge bg-secondary ms-2"><?php echo $total_adelantados; ?> adelantado(s)</span>
                <?php endif; ?>
            </h5>
        </div>
        <div class="card-body">
            <?php if ($inscripciones): ?>
                <div class="table-responsive">
                    <table class="table table-striped table-hover">
                        <thead>
                            <tr>
                                <th>Estudiante</th>
                                <th>Información</th>
                                <th>Sección/Grado</th>
                                <th>Profesor Titular</th>
                                <th>Año Escolar</th>
                                <th>Fecha</th>
                                <th>Capacidad</th>
                                <th>Estado</th>
                                <th>Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($inscripciones as $inscripcion): 
                                $ocupacion = $inscripcion['capacidad'] > 0 ? ($inscripcion['total_inscritos'] / $inscripcion['capacidad']) * 100 : 0;
                                $edad = $inscripcion['fecha_nacimiento'] ? floor((time() - strtotime($inscripcion['fecha_nacimiento'])) / 31556926) : null;
                                $es_adelantado = $inscripcion['es_adelantado'] > 0;
                            ?>
                                <tr>
                                    <td>
                                        <div class="d-flex align-items-center">
                                            <?php if ($es_adelantado): ?>
                                                <span class="badge bg-warning me-2" title="Estudiante Adelantado">
                                                    <i class="fas fa-forward"></i>
                                                </span>
                                            <?php endif; ?>
                                            <div>
                                                <strong><?php echo htmlspecialchars($inscripcion['estudiante_nombre'] . ' ' . $inscripcion['estudiante_apellido']); ?></strong>
                                                <?php if ($es_adelantado && $inscripcion['literal_anterior']): ?>
                                                    <br>
                                                    <small class="text-success">
                                                        <i class="fas fa-star me-1"></i>
                                                        Literal anterior: <?php echo $inscripcion['literal_anterior']; ?>
                                                    </small>
                                                <?php endif; ?>
                                                <br>
                                                <small class="text-muted">
                                                    ID: <?php echo $inscripcion['estudiante_id']; ?>
                                                    <?php if ($es_adelantado): ?>
                                                        | <span class="text-warning">Adelantado</span>
                                                    <?php else: ?>
                                                        | <span class="text-info">Natural</span>
                                                    <?php endif; ?>
                                                </small>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <?php if ($inscripcion['cedula_escolar']): ?>
                                            <span class="badge bg-secondary"><?php echo htmlspecialchars($inscripcion['cedula_escolar']); ?></span>
                                            <br>
                                        <?php endif; ?>
                                        <?php if ($edad): ?>
                                            <small class="text-muted">
                                                <?php echo $edad; ?> años
                                                <?php if ($inscripcion['genero']): ?>
                                                    | <?php echo $inscripcion['genero'] == 'masculino' ? '♂' : '♀'; ?>
                                                <?php endif; ?>
                                            </small>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="badge bg-info"><?php echo htmlspecialchars($inscripcion['seccion_nombre']); ?></span>
                                        <br>
                                        <small class="text-muted">
                                            <?php echo htmlspecialchars($inscripcion['grado_nombre']); ?>
                                            <br>
                                            <?php echo $inscripcion['total_inscritos']; ?>/<?php echo $inscripcion['capacidad'] ?: '∞'; ?>
                                            <?php if ($inscripcion['capacidad'] > 0): ?>
                                                <div class="progress mt-1" style="height: 5px;">
                                                    <div class="progress-bar <?php echo $ocupacion >= 90 ? 'bg-danger' : ($ocupacion >= 75 ? 'bg-warning' : 'bg-success'); ?>" 
                                                         style="width: <?php echo min($ocupacion, 100); ?>%">
                                                    </div>
                                                </div>
                                            <?php endif; ?>
                                        </small>
                                    </td>
                                    <td>
                                        <?php if ($inscripcion['profesor_nombre']): ?>
                                            <small>
                                                <?php echo htmlspecialchars($inscripcion['profesor_nombre'] . ' ' . $inscripcion['profesor_apellido']); ?>
                                            </small>
                                        <?php else: ?>
                                            <span class="text-muted">Sin asignar</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="badge bg-primary"><?php echo htmlspecialchars($inscripcion['año_escolar']); ?></span>
                                    </td>
                                    <td>
                                        <?php echo date('d/m/Y', strtotime($inscripcion['fecha_inscripcion'])); ?>
                                    </td>
                                    <td>
                                        <?php echo $inscripcion['capacidad'] ? $inscripcion['capacidad'] . ' est.' : 'Sin límite'; ?>
                                    </td>
                                    <td>
                                        <?php 
                                        $badge_class = [
                                            'activo' => 'bg-success',
                                            'retirado' => 'bg-danger',
                                            'graduado' => 'bg-warning'
                                        ];
                                        $estado_text = [
                                            'activo' => 'Activo',
                                            'retirado' => 'Retirado',
                                            'graduado' => 'Graduado'
                                        ];
                                        ?>
                                        <span class="badge <?php echo $badge_class[$inscripcion['status']] ?? 'bg-secondary'; ?>">
                                            <?php echo $estado_text[$inscripcion['status']] ?? 'Desconocido'; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div class="btn-group btn-group-sm">
                                            <a href="../estudiantes/editar.php?id=<?php echo $inscripcion['estudiante_id']; ?>" 
                                               class="btn btn-outline-primary" 
                                               title="Editar Estudiante">
                                                <i class="fas fa-user-edit"></i>
                                            </a>
                                            
                                            <?php if ($inscripcion['status'] == 'activo'): ?>
                                                <!-- Botón para Adelantar Estudiante -->
                                                <a href="adelantar_estudiante.php?inscripcion_id=<?php echo $inscripcion['id']; ?>&estudiante_id=<?php echo $inscripcion['estudiante_id']; ?>" 
                                                   class="btn btn-outline-success" 
                                                   title="Adelantar Estudiante">
                                                    <i class="fas fa-forward"></i>
                                                </a>
                                                
                                                <a href="generar_constancia.php?id=<?php echo $inscripcion['id']; ?>" 
                                                   class="btn btn-outline-info" 
                                                   title="Generar Constancia"
                                                   target="_blank">
                                                    <i class="fas fa-file-pdf"></i>
                                                </a>
                                                <a href="generar_carta_retiro.php?id=<?php echo $inscripcion['id']; ?>" 
                                                   class="btn btn-outline-warning" 
                                                   title="Carta de Retiro">
                                                    <i class="fas fa-user-times"></i>
                                                </a>
                                                <a href="gestionar.php?cambiar_estado=<?php echo $inscripcion['id']; ?>&status=retirado" 
                                                   class="btn btn-outline-danger" 
                                                   title="Marcar como Retirado"
                                                   onclick="return confirm('¿Estás seguro de marcar como RETIRADO a <?php echo htmlspecialchars($inscripcion['estudiante_nombre']); ?>?')">
                                                    <i class="fas fa-sign-out-alt"></i>
                                                </a>
                                            <?php else: ?>
                                                <a href="gestionar.php?cambiar_estado=<?php echo $inscripcion['id']; ?>&status=activo" 
                                                   class="btn btn-outline-success" 
                                                   title="Reactivar Inscripción"
                                                   onclick="return confirm('¿Estás seguro de REACTIVAR la inscripción de <?php echo htmlspecialchars($inscripcion['estudiante_nombre']); ?>?')">
                                                    <i class="fas fa-redo"></i>
                                                </a>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div class="text-center py-4">
                    <i class="fas fa-user-plus fa-3x text-muted mb-3"></i>
                    <h5>No hay inscripciones registradas</h5>
                    <p class="text-muted">No se encontraron inscripciones con los filtros aplicados.</p>
                    <a href="inscribir.php" class="btn btn-primary me-2">
                        <i class="fas fa-user-plus me-2"></i>Nueva Inscripción
                    </a>
                    <a href="gestionar.php" class="btn btn-outline-secondary">
                        <i class="fas fa-times me-2"></i>Limpiar Filtros
                    </a>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Mejorar la experiencia de filtrado
    const filtros = document.querySelectorAll('#año_escolar_id, #seccion_id, #grado, #status, #tipo_estudiante');
    filtros.forEach(filtro => {
        filtro.addEventListener('change', function() {
            // Opcional: enviar automáticamente el formulario
            // this.form.submit();
        });
    });
    
    // Mostrar información de capacidad
    const progressBars = document.querySelectorAll('.progress-bar');
    progressBars.forEach(bar => {
        const width = parseInt(bar.style.width);
        if (width >= 90) {
            bar.title = 'Capacidad casi llena (' + width + '%)';
        } else if (width >= 75) {
            bar.title = 'Capacidad en advertencia (' + width + '%)';
        }
    });
});
</script>

<?php include '../../includes/footer.php'; ?>
