<?php
require_once '../../includes/auth.php';
require_once '../../includes/database.php';
checkAuth();
checkRole(['admin']);

$mensaje = '';
$tipo_mensaje = '';
$nueva_seccion_id = isset($_GET['nueva_seccion_id']) ? (int)$_GET['nueva_seccion_id'] : null;
$filtro_busqueda = trim($_GET['q'] ?? '');
$filtro_grado_id = isset($_GET['grado_id']) ? (int)$_GET['grado_id'] : 0;
$filtro_nivel = $_GET['nivel'] ?? '';
$selected_year_id = isset($_GET['año_escolar']) ? (int)$_GET['año_escolar'] : ($año_activo_id ?? null);

// Primero, obtener el año escolar activo
try {
    $stmt = $pdo->query("SELECT id, nombre FROM años_escolares WHERE estado = 'activo' LIMIT 1");
    $año_activo = $stmt->fetch(PDO::FETCH_ASSOC);
    $año_activo_id = $año_activo['id'] ?? null;
    
    if (!$año_activo_id) {
        $mensaje = "No hay un año escolar activo. Por favor, active un año escolar primero.";
        $tipo_mensaje = "warning";
    }
} catch (PDOException $e) {
    error_log("Error al obtener año activo: " . $e->getMessage());
    $año_activo_id = null;
}

// Primero, asegurarnos de que existan los grados base
try {
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM grados");
    $total_grados = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
    
    if ($total_grados == 0) {
        // Insertar grados base automáticamente
        $grados_base = [
            [1, 'Primer Grado', 'primaria', 1],
            [2, 'Segundo Grado', 'primaria', 2],
            [3, 'Tercer Grado', 'primaria', 3],
            [4, 'Cuarto Grado', 'primaria', 4],
            [5, 'Quinto Grado', 'primaria', 5],
            [6, 'Sexto Grado', 'primaria', 6]
        ];
        
        foreach ($grados_base as $grado) {
            $stmt = $pdo->prepare("INSERT INTO grados (numero, nombre, nivel, orden) VALUES (?, ?, ?, ?)");
            $stmt->execute($grado);
        }
    }
} catch (PDOException $e) {
    error_log("Error al verificar grados: " . $e->getMessage());
}

// Procesar eliminación
if (isset($_GET['eliminar'])) {
    $id = $_GET['eliminar'];
    
    try {
        // Verificar si la sección tiene estudiantes inscritos
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM inscripciones WHERE seccion_id = ?");
        $stmt->execute([$id]);
        $tiene_estudiantes = $stmt->fetchColumn();
        
        if ($tiene_estudiantes > 0) {
            $mensaje = "No se puede eliminar la sección porque tiene estudiantes inscritos";
            $tipo_mensaje = "warning";
        } else {
            $stmt = $pdo->prepare("DELETE FROM secciones WHERE id = ?");
            $stmt->execute([$id]);
            $mensaje = "Sección eliminada correctamente";
            $tipo_mensaje = "success";
        }
    } catch (PDOException $e) {
        $mensaje = "Error al eliminar sección: " . $e->getMessage();
        $tipo_mensaje = "danger";
    }
}

// Obtener años escolares para el filtro (solo mostrar activo e inactivos recientes)
try {
    $stmt = $pdo->query("SELECT id, nombre, estado FROM años_escolares ORDER BY estado = 'activo' DESC, nombre DESC");
    $años_escolares = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $años_escolares = [];
}

// Obtener grados con sus secciones (SOLO DEL AÑO ACTIVO)
try {
    $stmt = $pdo->prepare("
        SELECT g.*, 
               COUNT(s.id) as total_secciones,
               GROUP_CONCAT(CONCAT(s.nombre, '|', s.id, '|', ae.nombre) SEPARATOR ';;') as secciones_info
        FROM grados g
        LEFT JOIN secciones s ON g.id = s.grado_id AND s.año_escolar_id = ?
        LEFT JOIN años_escolares ae ON s.año_escolar_id = ae.id
        WHERE g.estado = 'activo'
        GROUP BY g.id
        ORDER BY g.orden
    ");
    $stmt->execute([$año_activo_id]);
    $grados = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $grados = [];
    error_log("Error al cargar grados: " . $e->getMessage());
}

// Obtener lista de secciones con información del año escolar
try {
    $query = "
        SELECT s.*, 
               ae.nombre as año_escolar,
               g.nombre as grado_nombre,
               COUNT(i.id) as total_estudiantes,
               (s.capacidad - COUNT(i.id)) as cupos_disponibles
        FROM secciones s 
        LEFT JOIN años_escolares ae ON s.año_escolar_id = ae.id 
        LEFT JOIN grados g ON s.grado_id = g.id
        LEFT JOIN inscripciones i ON s.id = i.seccion_id AND i.status = 'activo'
        WHERE 1 = 1
    ";

    $params = [];
    $selected_year_id = isset($_GET['año_escolar']) && !empty($_GET['año_escolar']) ? (int)$_GET['año_escolar'] : ($año_activo_id ?? null);

    if ($selected_year_id) {
        $query .= " AND s.año_escolar_id = ?";
        $params[] = $selected_year_id;
    }

    if (!empty($filtro_busqueda)) {
        $query .= " AND s.nombre LIKE ?";
        $params[] = '%' . $filtro_busqueda . '%';
    }

    if ($filtro_grado_id > 0) {
        $query .= " AND s.grado_id = ?";
        $params[] = $filtro_grado_id;
    }

    if (!empty($filtro_nivel)) {
        $query .= " AND s.nivel = ?";
        $params[] = $filtro_nivel;
    }

    $query .= " GROUP BY s.id ORDER BY g.orden, s.nombre";

    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $secciones = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $secciones = [];
    $mensaje = "Error al cargar secciones: " . $e->getMessage();
    $tipo_mensaje = "danger";
}
?>

<?php include '../../includes/header.php'; ?>

<div class="content-wrapper" style="padding: 20px;">
    <?php include '../../includes/sidebar_admin.php'; ?>
    
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2>Gestión de Secciones</h2>
        <?php if ($año_activo_id): ?>
            <div>
                <span class="badge bg-success me-3">
                    <i class="fas fa-calendar-alt me-1"></i>
                    Año Activo: <?php echo htmlspecialchars($año_activo['nombre'] ?? ''); ?>
                </span>
                <a href="agregar.php" class="btn btn-primary">
                    <i class="fas fa-plus me-2"></i>Nueva Sección
                </a>
            </div>
        <?php else: ?>
            <a href="../años_escolares/gestionar.php" class="btn btn-warning">
                <i class="fas fa-calendar-plus me-2"></i>Activar Año Escolar
            </a>
        <?php endif; ?>
    </div>

    <?php if ($nueva_seccion_id): ?>
        <div class="alert alert-success d-flex justify-content-between align-items-center flex-wrap gap-2" role="alert">
            <span><?php echo htmlspecialchars($_GET['mensaje'] ?? 'Sección creada correctamente'); ?></span>
            <a href="asignar_profesores.php?id=<?php echo $nueva_seccion_id; ?>" class="btn btn-sm btn-primary">
                <i class="fas fa-clock me-1"></i>Asignar Horario Ahora
            </a>
        </div>
    <?php elseif ($mensaje): ?>
        <div class="alert alert-<?php echo $tipo_mensaje; ?> alert-dismissible fade show" role="alert">
            <?php echo $mensaje; ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <?php if (!$año_activo_id): ?>
        <div class="alert alert-warning">
            <i class="fas fa-exclamation-triangle me-2"></i>
            No hay un año escolar activo. Debe activar un año escolar para gestionar las secciones.
            <a href="../años_escolares/gestionar.php" class="alert-link">Activar año escolar</a>
        </div>
    <?php else: ?>
        <!-- Cards de Grados -->
        <div class="row mb-5">
            <?php foreach ($grados as $grado): 
                $secciones_grado = [];
                if (!empty($grado['secciones_info'])) {
                    $secciones_data = explode(';;', $grado['secciones_info']);
                    foreach ($secciones_data as $seccion_data) {
                        if (!empty(trim($seccion_data))) {
                            list($nombre, $id, $año_escolar) = explode('|', $seccion_data);
                            $secciones_grado[] = [
                                'id' => $id,
                                'nombre' => $nombre,
                                'año_escolar' => $año_escolar
                            ];
                        }
                    }
                }
            ?>
            <div class="col-md-6 col-lg-4 mb-4">
                <div class="card h-100" style="border-left: 4px solid var(--bs-<?php echo getColorByGrado($grado['numero']); ?>);">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5 class="card-title mb-0 text-<?php echo getColorByGrado($grado['numero']); ?>">
                            <i class="fas fa-graduation-cap me-2"></i><?php echo htmlspecialchars($grado['nombre']); ?>
                        </h5>
                        <span class="badge bg-<?php echo getColorByGrado($grado['numero']); ?>"><?php echo $grado['total_secciones']; ?> secc.</span>
                    </div>
                    <div class="card-body">
                        <!-- Lista de secciones existentes -->
                        <?php if (!empty($secciones_grado)): ?>
                            <h6 class="text-muted mb-2">Secciones Activas:</h6>
                            <div class="mb-3">
                                <?php foreach ($secciones_grado as $seccion): ?>
                                    <div class="d-flex justify-content-between align-items-center mb-2 p-2 bg-light rounded">
                                        <div>
                                            <strong class="d-block"><?php echo htmlspecialchars($seccion['nombre']); ?></strong>
                                            <small class="text-muted"><?php echo htmlspecialchars($seccion['año_escolar']); ?></small>
                                        </div>
                                        <div class="btn-group btn-group-sm">
                                            <a href="editar.php?id=<?php echo $seccion['id']; ?>" 
                                               class="btn btn-outline-primary btn-sm" title="Editar">
                                                <i class="fas fa-edit"></i>
                                            </a>
                                            <a href="../inscripciones/gestionar.php?seccion_id=<?php echo $seccion['id']; ?>" 
                                               class="btn btn-outline-success btn-sm" title="Estudiantes">
                                                <i class="fas fa-users"></i>
                                            </a>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <div class="text-center py-3">
                                <i class="fas fa-door-open fa-2x text-muted mb-2"></i>
                                <p class="text-muted mb-0">No hay secciones creadas</p>
                                <small class="text-muted">para el año <?php echo htmlspecialchars($año_activo['nombre'] ?? ''); ?></small>
                            </div>
                        <?php endif; ?>
                    </div>
                    <div class="card-footer bg-transparent">
                        <div class="d-grid gap-2">
                            <a href="agregar.php?grado_id=<?php echo $grado['id']; ?>&año_escolar_id=<?php echo $año_activo_id; ?>" 
                               class="btn btn-<?php echo getColorByGrado($grado['numero']); ?> btn-sm">
                                <i class="fas fa-plus me-1"></i>Crear Sección
                            </a>
                        </div>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>

        <!-- Filtros -->
        <div class="card mb-4">
            <div class="card-header">
                <h6 class="card-title mb-0">Filtros</h6>
            </div>
            <div class="card-body">
                <form method="GET" class="row g-3">
                    <div class="col-md-3">
                        <label for="año_escolar" class="form-label">Año Escolar</label>
                        <select class="form-select" id="año_escolar" name="año_escolar">
                            <option value="<?php echo $año_activo_id; ?>" <?php echo ($selected_year_id == $año_activo_id) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($año_activo['nombre'] ?? ''); ?> (Activo)
                            </option>
                            <?php foreach ($años_escolares as $año): 
                                if ($año['id'] != $año_activo_id): ?>
                                <option value="<?php echo $año['id']; ?>" 
                                    <?php echo ($selected_year_id == $año['id']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($año['nombre']); ?>
                                    <?php echo ($año['estado'] == 'inactivo') ? '(Inactivo)' : ''; ?>
                                </option>
                            <?php endif; endforeach; ?>
                        </select>
                    </div>

                    <div class="col-md-3">
                        <label for="q" class="form-label">Buscar por nombre</label>
                        <input type="text" class="form-control" id="q" name="q"
                               value="<?php echo htmlspecialchars($filtro_busqueda); ?>"
                               placeholder="Ej: Primer Grado A">
                    </div>

                    <div class="col-md-3">
                        <label for="grado_id" class="form-label">Grado</label>
                        <select class="form-select" id="grado_id" name="grado_id">
                            <option value="0">Todos los grados</option>
                            <?php foreach ($grados as $g): ?>
                                <option value="<?php echo $g['id']; ?>" 
                                    <?php echo $filtro_grado_id == $g['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($g['nombre']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="col-md-3">
                        <label for="nivel" class="form-label">Nivel</label>
                        <select class="form-select" id="nivel" name="nivel">
                            <option value="">Todos los niveles</option>
                            <option value="primaria" <?php echo $filtro_nivel == 'primaria' ? 'selected' : ''; ?>>Primaria</option>
                            <option value="secundaria" <?php echo $filtro_nivel == 'secundaria' ? 'selected' : ''; ?>>Secundaria</option>
                        </select>
                    </div>

                    <div class="col-md-12 d-flex align-items-end justify-content-start gap-2">
                        <button type="submit" class="btn btn-outline-primary">
                            <i class="fas fa-filter me-1"></i>Filtrar
                        </button>
                        <a href="gestionar.php" class="btn btn-outline-secondary">
                            <i class="fas fa-times me-1"></i>Limpiar
                        </a>
                    </div>
                </form>
            </div>
        </div>

        <!-- Lista Completa de Secciones -->
        <div class="card">
            <div class="card-header">
                <h5 class="card-title mb-0">Lista de Secciones 
                    <?php if (!isset($_GET['año_escolar']) || $_GET['año_escolar'] == $año_activo_id): ?>
                        - Año Activo: <?php echo htmlspecialchars($año_activo['nombre'] ?? ''); ?>
                    <?php endif; ?>
                </h5>
            </div>
            <div class="card-body">
                <?php if ($secciones): ?>
                    <div class="table-responsive">
                        <table class="table table-striped table-hover">
                            <thead>
                                <tr>
                                    <th>Nombre</th>
                                    <th>Grado</th>
                                    <th>Año Escolar</th>
                                    <th>Capacidad</th>
                                    <th>Estudiantes</th>
                                    <th>Cupos Disponibles</th>
                                    <th>Ocupación</th>
                                    <th>Acciones</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($secciones as $seccion): 
                                    $ocupacion = $seccion['capacidad'] > 0 ? ($seccion['total_estudiantes'] / $seccion['capacidad']) * 100 : 0;
                                    $progress_class = $ocupacion >= 90 ? 'bg-danger' : ($ocupacion >= 70 ? 'bg-warning' : 'bg-success');
                                    
                                    // Calcular cupos disponibles
                                    $cupos_disponibles = $seccion['capacidad'] - $seccion['total_estudiantes'];
                                    $cupos_class = $cupos_disponibles <= 0 ? 'danger' : ($cupos_disponibles <= 5 ? 'warning' : 'success');
                                ?>
                                    <tr>
                                        <td>
                                            <strong><?php echo htmlspecialchars($seccion['nombre']); ?></strong>
                                        </td>
                                        <td>
                                            <span class="badge bg-primary"><?php echo htmlspecialchars($seccion['grado_nombre']); ?></span>
                                        </td>
                                        <td>
                                            <span class="badge <?php echo ($seccion['año_escolar'] == $año_activo['nombre']) ? 'bg-success' : 'bg-info'; ?>">
                                                <?php echo htmlspecialchars($seccion['año_escolar']); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php echo $seccion['capacidad'] ? $seccion['capacidad'] . ' estudiantes' : '<span class="text-muted">Sin límite</span>'; ?>
                                        </td>
                                        <td>
                                            <span class="badge bg-primary"><?php echo $seccion['total_estudiantes']; ?></span>
                                        </td>
                                        <td>
                                            <?php if ($seccion['capacidad']): ?>
                                                <span class="badge bg-<?php echo $cupos_class; ?>">
                                                    <?php echo $cupos_disponibles; ?> cupos
                                                </span>
                                            <?php else: ?>
                                                <span class="badge bg-secondary">Sin límite</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div class="progress" style="height: 20px;">
                                                <div class="progress-bar <?php echo $progress_class; ?>" 
                                                     role="progressbar" 
                                                     style="width: <?php echo $ocupacion; ?>%"
                                                     aria-valuenow="<?php echo $ocupacion; ?>" 
                                                     aria-valuemin="0" 
                                                     aria-valuemax="100">
                                                    <?php echo round($ocupacion); ?>%
                                                </div>
                                            </div>
                                        </td>
                                        <td>
                                            <div class="btn-group btn-group-sm">
                                                <a href="editar.php?id=<?php echo $seccion['id']; ?>" 
                                                   class="btn btn-outline-primary" 
                                                   title="Editar">
                                                    <i class="fas fa-edit"></i>
                                                </a>
                                                <a href="../inscripciones/gestionar.php?seccion_id=<?php echo $seccion['id']; ?>" 
                                                   class="btn btn-outline-success" 
                                                   title="Ver Estudiantes">
                                                    <i class="fas fa-users"></i>
                                                </a>
                                                
                                                <a href="asignar_profesores.php?id=<?php echo $seccion['id']; ?>" 
                                                   class="btn btn-sm btn-outline-info" 
                                                   title="Gestionar Horario">
                                                    <i class="fas fa-clock"></i> Horario
                                                </a>
                                                
                                                <a href="generar_constancia_seccion.php?seccion_id=<?php echo $seccion['id']; ?>" 
                                                   class="btn btn-outline-warning" 
                                                   title="Generar Constancias">
                                                    <i class="fas fa-file-pdf"></i>
                                                </a>
                                                <a href="generar_listado_seccion.php?seccion_id=<?php echo $seccion['id']; ?>" 
                                                   class="btn btn-outline-warning" 
                                                   title="Generar Listado Completo">
                                                    <i class="fas fa-list-alt"></i>
                                                </a>
                                                <button type="button" 
                                                        class="btn btn-outline-danger" 
                                                        title="Eliminar"
                                                        onclick="confirmarEliminacion(<?php echo $seccion['id']; ?>, '<?php echo htmlspecialchars($seccion['nombre']); ?>')">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <div class="text-center py-4">
                        <i class="fas fa-door-open fa-3x text-muted mb-3"></i>
                        <h5>No hay secciones registradas</h5>
                        <p class="text-muted">
                            <?php if (isset($_GET['año_escolar']) && $_GET['año_escolar'] != $año_activo_id): ?>
                                No hay secciones para el año escolar seleccionado.
                            <?php else: ?>
                                No hay secciones creadas para el año activo <?php echo htmlspecialchars($año_activo['nombre'] ?? ''); ?>.
                            <?php endif; ?>
                        </p>
                        <a href="agregar.php" class="btn btn-primary">
                            <i class="fas fa-plus me-2"></i>Agregar Primera Sección
                        </a>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>
</div>

<script>
function confirmarEliminacion(id, nombre) {
    if (confirm(`¿Estás seguro de que deseas eliminar la sección "${nombre}"?\n\nEsta acción no se puede deshacer.`)) {
        window.location.href = `gestionar.php?eliminar=${id}`;
    }
}
</script>

<style>
.card {
    transition: transform 0.2s ease-in-out;
}
.card:hover {
    transform: translateY(-2px);
}

/* Estilos para los badges de cupos */
.badge.bg-success { background-color: #28a745 !important; }
.badge.bg-warning { background-color: #ffc107 !important; color: #000 !important; }
.badge.bg-danger { background-color: #dc3545 !important; }

/* Mejorar el espaciado de los botones */
.btn-group-sm > .btn {
    padding: 0.25rem 0.5rem;
    font-size: 0.75rem;
}
</style>

<?php 
// Función auxiliar para colores por grado
function getColorByGrado($numero) {
    $colores = [
        1 => 'primary',
        2 => 'success', 
        3 => 'info',
        4 => 'warning',
        5 => 'danger',
        6 => 'secondary'
    ];
    return $colores[$numero] ?? 'dark';
}
?>

<?php include '../../includes/footer.php'; ?>
