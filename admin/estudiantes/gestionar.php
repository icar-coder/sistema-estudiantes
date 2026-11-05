<?php
require_once '../../includes/auth.php';
require_once '../../includes/database.php';
checkAuth();
checkRole(['admin']);

$mensaje = '';
$tipo_mensaje = '';

// Procesar eliminaciÃ³n
if (isset($_GET['eliminar'])) {
    $id = $_GET['eliminar'];
    $tipo = $_GET['tipo'] ?? 'estudiante'; // Tipo: estudiante o representante
    
    try {
        if ($tipo === 'estudiante') {
            // Verificar si el estudiante tiene inscripciones
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM inscripciones WHERE estudiante_id = ?");
            $stmt->execute([$id]);
            $tiene_inscripciones = $stmt->fetchColumn();
            
            if ($tiene_inscripciones > 0) {
                $mensaje = "No se puede eliminar el estudiante porque tiene inscripciones activas";
                $tipo_mensaje = "warning";
            } else {
                $stmt = $pdo->prepare("DELETE FROM estudiantes WHERE id = ?");
                $stmt->execute([$id]);
                $mensaje = "Estudiante eliminado correctamente";
                $tipo_mensaje = "success";
            }
        } elseif ($tipo === 'representante') {
            // Verificar si el representante tiene estudiantes asociados
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM estudiantes WHERE representante_id = ?");
            $stmt->execute([$id]);
            $tiene_estudiantes = $stmt->fetchColumn();
            
            if ($tiene_estudiantes > 0) {
                $mensaje = "No se puede eliminar el representante porque tiene estudiantes asociados";
                $tipo_mensaje = "warning";
            } else {
                $stmt = $pdo->prepare("DELETE FROM representantes WHERE id = ?");
                $stmt->execute([$id]);
                $mensaje = "Representante eliminado correctamente";
                $tipo_mensaje = "success";
            }
        }
    } catch (PDOException $e) {
        $mensaje = "Error al eliminar: " . $e->getMessage();
        $tipo_mensaje = "danger";
    }
}

// FunciÃ³n para obtener informaciÃ³n completa del estudiante
function obtenerInfoEstudianteCompleta($pdo, $estudiante_id) {
    $sql = "
        SELECT 
            e.*,
            r.id as rep_id,
            r.cedula as rep_cedula,
            r.nombre as rep_nombre,
            r.apellido as rep_apellido,
            r.parentesco as rep_parentesco,
            r.telefono as rep_telefono,
            r.email as rep_email,
            r.profesion as rep_profesion,
            r.lugar_trabajo as rep_lugar_trabajo,
            s.nombre as seccion_nombre,
            ae.nombre as año_escolar,
            i.fecha_inscripcion,
            i.status as inscripcion_status
        FROM estudiantes e
        LEFT JOIN representantes r ON e.representante_id = r.id
        LEFT JOIN inscripciones i ON e.id = i.estudiante_id AND i.status = 'activo'
        LEFT JOIN secciones s ON i.seccion_id = s.id
        LEFT JOIN años_escolares ae ON i.año_escolar_id = ae.id
        WHERE e.id = ?
        ORDER BY i.fecha_inscripcion DESC
        LIMIT 1
    ";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$estudiante_id]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

// FunciÃ³n para obtener representantes adicionales
function obtenerRepresentantesAdicionales($pdo, $estudiante_id) {
    $sql = "
        SELECT 
            r.*,
            er.parentesco,
            er.es_principal
        FROM estudiantes_representantes er
        JOIN representantes r ON er.representante_id = r.id
        WHERE er.estudiante_id = ? AND er.es_principal = 0
        ORDER BY er.parentesco
    ";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$estudiante_id]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// FunciÃ³n para obtener estudiantes de un representante
function obtenerEstudiantesPorRepresentante($pdo, $representante_id) {
    $sql = "
        SELECT 
            e.*,
            er.parentesco,
            s.nombre as seccion_nombre,
            ae.nombre as año_escolar,
            i.status as inscripcion_status
        FROM estudiantes_representantes er
        JOIN estudiantes e ON er.estudiante_id = e.id
        LEFT JOIN inscripciones i ON e.id = i.estudiante_id AND i.status = 'activo'
        LEFT JOIN secciones s ON i.seccion_id = s.id
        LEFT JOIN años_escolares ae ON i.año_escolar_id = ae.id
        WHERE er.representante_id = ?
        ORDER BY e.nombre, e.apellido
    ";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$representante_id]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// FunciÃ³n para verificar si un estudiante tiene literal final en el año actual
function tieneLiteralFinal($pdo, $estudiante_id, $año_escolar_id) {
    $sql = "
        SELECT COUNT(*) as total 
        FROM literales_finales lf
        JOIN inscripciones i ON lf.inscripcion_id = i.id
        WHERE i.estudiante_id = ? AND lf.año_escolar_id = ?
    ";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$estudiante_id, $año_escolar_id]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    
    return $result['total'] > 0;
}

// FunciÃ³n para obtener el año escolar actual
function obtenerAñoEscolarActual($pdo) {
    $sql = "SELECT id FROM años_escolares WHERE estado = 'activo' LIMIT 1";
    $stmt = $pdo->query($sql);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    
    return $result ? $result['id'] : null;
}

// Procesar solicitud de informaciÃ³n detallada
if (isset($_GET['info_estudiante'])) {
    $estudiante_id = $_GET['info_estudiante'];
    $estudiante_info = obtenerInfoEstudianteCompleta($pdo, $estudiante_id);
    $representantes_adicionales = obtenerRepresentantesAdicionales($pdo, $estudiante_id);
    
    header('Content-Type: application/json');
    echo json_encode([
        'estudiante' => $estudiante_info,
        'representantes_adicionales' => $representantes_adicionales
    ]);
    exit();
}

// Procesar solicitud de informaciÃ³n de representante
if (isset($_GET['info_representante'])) {
    $representante_id = $_GET['info_representante'];
    $estudiantes = obtenerEstudiantesPorRepresentante($pdo, $representante_id);
    
    header('Content-Type: application/json');
    echo json_encode([
        'estudiantes' => $estudiantes
    ]);
    exit();
}

// Obtener año escolar actual
$año_escolar_actual = obtenerAñoEscolarActual($pdo);

// ParÃ¡metros de bÃºsqueda
$busqueda = $_GET['busqueda'] ?? '';
$filtro_tipo = $_GET['filtro_tipo'] ?? 'estudiantes'; // estudiantes o representantes
$filtro_campo = $_GET['filtro_campo'] ?? 'nombre';

// Construir consulta base para estudiantes
$where_estudiantes = [];
$params_estudiantes = [];

if ($busqueda) {
    if ($filtro_tipo === 'estudiantes') {
        $campos_estudiante = [
            "e.cedula_escolar LIKE ?",
            "e.nombre LIKE ?",
            "e.apellido LIKE ?",
            "CONCAT(e.nombre, ' ', e.apellido) LIKE ?",
            "CONCAT(e.apellido, ' ', e.nombre) LIKE ?",
            "r.nombre LIKE ?",
            "r.apellido LIKE ?",
            "r.cedula LIKE ?",
            "CONCAT(r.nombre, ' ', r.apellido) LIKE ?"
        ];

        $where_estudiantes[] = "(" . implode(" OR ", $campos_estudiante) . ")";
        $like = "%$busqueda%";
        foreach ($campos_estudiante as $campo) {
            $params_estudiantes[] = $like;
        }
    }
}

// Construir consulta base para representantes
$where_representantes = [];
$params_representantes = [];

if ($busqueda && $filtro_tipo === 'representantes') {
    $campos_representante = [
        "r.cedula LIKE ?",
        "r.nombre LIKE ?",
        "r.apellido LIKE ?",
        "CONCAT(r.nombre, ' ', r.apellido) LIKE ?",
        "r.telefono LIKE ?",
        "r.telefono_alternativo LIKE ?",
        "r.email LIKE ?"
    ];

    $where_representantes[] = "(" . implode(" OR ", $campos_representante) . ")";
    $like = "%$busqueda%";
    foreach ($campos_representante as $campo) {
        $params_representantes[] = $like;
    }
}

// Obtener lista de estudiantes
try {
    $sql_estudiantes = "
        SELECT e.*, 
               COUNT(i.id) as total_inscripciones,
               r.nombre as rep_nombre,
               r.apellido as rep_apellido,
               r.cedula as rep_cedula,
               r.telefono as rep_telefono
        FROM estudiantes e 
        LEFT JOIN inscripciones i ON e.id = i.estudiante_id 
        LEFT JOIN representantes r ON e.representante_id = r.id
    ";
    
    if (!empty($where_estudiantes)) {
        $sql_estudiantes .= " WHERE " . implode(" AND ", $where_estudiantes);
    }
    
    $sql_estudiantes .= " GROUP BY e.id ORDER BY e.apellido, e.nombre";
    
    $stmt = $pdo->prepare($sql_estudiantes);
    $stmt->execute($params_estudiantes);
    $estudiantes = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $estudiantes = [];
    $mensaje = "Error al cargar estudiantes: " . $e->getMessage();
    $tipo_mensaje = "danger";
}

// Obtener lista de representantes
try {
    $sql_representantes = "
        SELECT r.*, 
               COUNT(e.id) as total_estudiantes
        FROM representantes r 
        LEFT JOIN estudiantes e ON r.id = e.representante_id
    ";
    
    if (!empty($where_representantes)) {
        $sql_representantes .= " WHERE " . implode(" AND ", $where_representantes);
    }
    
    $sql_representantes .= " GROUP BY r.id ORDER BY r.apellido, r.nombre";
    
    $stmt = $pdo->prepare($sql_representantes);
    $stmt->execute($params_representantes);
    $representantes = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $representantes = [];
    if (empty($mensaje)) {
        $mensaje = "Error al cargar representantes: " . $e->getMessage();
        $tipo_mensaje = "danger";
    }
}
?>

<?php include '../../includes/header.php'; ?>

<div class="content-wrapper" style="padding: 20px;">
    <?php include '../../includes/sidebar_admin.php'; ?>
    
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2>GestiÃ³n de Estudiantes y Representantes</h2>
        <div>
            <a href="agregar.php" class="btn btn-primary">
                <i class="fas fa-user-plus me-2"></i>Nuevo Estudiante
            </a>
        </div>
    </div>

    <?php if ($mensaje): ?>
        <div class="alert alert-<?php echo $tipo_mensaje; ?> alert-dismissible fade show" role="alert">
            <?php echo $mensaje; ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- Filtros de BÃºsqueda -->
    <div class="card mb-4">
        <div class="card-header">
            <h5 class="card-title mb-0">
                <i class="fas fa-search me-2"></i>BÃºsqueda y Filtros
            </h5>
        </div>
        <div class="card-body">
            <form method="GET" action="" class="row g-3">
                <div class="col-md-3">
                    <label for="filtro_tipo" class="form-label">Tipo</label>
                    <select class="form-select" id="filtro_tipo" name="filtro_tipo" onchange="actualizarFiltros()">
                        <option value="estudiantes" <?php echo $filtro_tipo === 'estudiantes' ? 'selected' : ''; ?>>Estudiantes</option>
                        <option value="representantes" <?php echo $filtro_tipo === 'representantes' ? 'selected' : ''; ?>>Representantes</option>
                    </select>
                </div>
                
                <div class="col-md-3">
                    <label for="filtro_campo" class="form-label">Buscar por</label>
                    <select class="form-select" id="filtro_campo" name="filtro_campo">
                        <!-- Opciones se actualizan con JavaScript -->
                    </select>
                </div>
                
                <div class="col-md-4">
                    <label for="busqueda" class="form-label">TÃ©rmino de bÃºsqueda</label>
                    <input type="text" class="form-control" id="busqueda" name="busqueda" 
                           value="<?php echo htmlspecialchars($busqueda); ?>" 
                           placeholder="Ingrese texto para buscar...">
                </div>
                
                <div class="col-md-2 d-flex align-items-end">
                    <button type="submit" class="btn btn-primary w-100">
                        <i class="fas fa-search me-2"></i>Buscar
                    </button>
                </div>
                
                <?php if ($busqueda): ?>
                <div class="col-12">
                    <a href="gestionar.php" class="btn btn-outline-secondary btn-sm">
                        <i class="fas fa-times me-2"></i>Limpiar filtros
                    </a>
                    <small class="text-muted ms-2">
                        Mostrando resultados para: "<?php echo htmlspecialchars($busqueda); ?>"
                    </small>
                </div>
                <?php endif; ?>
            </form>
        </div>
    </div>

    <!-- Pestañas para Estudiantes y Representantes -->
    <ul class="nav nav-tabs mb-4" id="myTab" role="tablist">
        <li class="nav-item" role="presentation">
            <button class="nav-link <?php echo $filtro_tipo === 'estudiantes' ? 'active' : ''; ?>" 
                    id="estudiantes-tab" data-bs-toggle="tab" data-bs-target="#estudiantes" 
                    type="button" role="tab" aria-controls="estudiantes" aria-selected="true">
                <i class="fas fa-user-graduate me-2"></i>Estudiantes
                <span class="badge bg-primary ms-1"><?php echo count($estudiantes); ?></span>
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link <?php echo $filtro_tipo === 'representantes' ? 'active' : ''; ?>" 
                    id="representantes-tab" data-bs-toggle="tab" data-bs-target="#representantes" 
                    type="button" role="tab" aria-controls="representantes" aria-selected="false">
                <i class="fas fa-user-friends me-2"></i>Representantes
                <span class="badge bg-info ms-1"><?php echo count($representantes); ?></span>
            </button>
        </li>
    </ul>

    <div class="tab-content" id="myTabContent">
        <!-- Pestaña de Estudiantes -->
        <div class="tab-pane fade <?php echo $filtro_tipo === 'estudiantes' ? 'show active' : ''; ?>" 
             id="estudiantes" role="tabpanel" aria-labelledby="estudiantes-tab">
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="card-title mb-0">
                        <i class="fas fa-user-graduate me-2"></i>Lista de Estudiantes
                    </h5>
                    <div class="d-flex align-items-center gap-2">
                        <button type="button" class="btn btn-sm btn-outline-dark" onclick="exportarListado()">
                            <i class="fas fa-file-excel me-1"></i>Exportar listado
                        </button>
                        <span class="badge bg-primary">
                            Total: <?php echo count($estudiantes); ?>
                        </span>
                    </div>
                </div>
                <div class="card-body">
                    <?php if ($estudiantes): ?>
                        <div class="table-responsive">
                            <table class="table table-striped table-hover">
                                <thead>
                                    <tr>
                                        <th>CÃ©dula Escolar</th>
                                        <th>Nombre Completo</th>
                                        <th>Fecha Nacimiento</th>
                                        <th>Representante</th>
                                        <th>Contacto Rep.</th>
                                        <th>Inscripciones</th>
                                        <th>Fecha Registro</th>
                                        <th>Acciones</th>
                                        <th>Documentos</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($estudiantes as $estudiante): 
                                        $puede_generar_boleta = $año_escolar_actual && tieneLiteralFinal($pdo, $estudiante['id'], $año_escolar_actual);
                                    ?>
                                        <tr>
                                            <td>
                                                <span class="badge bg-secondary"><?php echo htmlspecialchars($estudiante['cedula_escolar']); ?></span>
                                            </td>
                                            <td>
                                                <strong><?php echo htmlspecialchars($estudiante['nombre'] . ' ' . $estudiante['apellido']); ?></strong>
                                                <?php if ($estudiante['genero']): ?>
                                                    <br><small class="text-muted"><?php echo ucfirst($estudiante['genero']); ?></small>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php echo $estudiante['fecha_nacimiento'] ? date('d/m/Y', strtotime($estudiante['fecha_nacimiento'])) : 'No especificada'; ?>
                                            </td>
                                            <td>
                                                <?php if ($estudiante['rep_nombre']): ?>
                                                    <strong><?php echo htmlspecialchars($estudiante['rep_nombre'] . ' ' . $estudiante['rep_apellido']); ?></strong>
                                                    <br><small class="text-muted">C.I: <?php echo htmlspecialchars($estudiante['rep_cedula']); ?></small>
                                                <?php else: ?>
                                                    <span class="text-danger">Sin representante</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if ($estudiante['rep_telefono']): ?>
                                                    <small><?php echo htmlspecialchars($estudiante['rep_telefono']); ?></small>
                                                <?php else: ?>
                                                    <span class="text-muted">No tiene</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <span class="badge bg-info"><?php echo $estudiante['total_inscripciones']; ?></span>
                                            </td>
                                            <td>
                                                <?php echo date('d/m/Y', strtotime($estudiante['created_at'])); ?>
                                            </td>
                                            <td>
                                                <div class="btn-group btn-group-sm">
                                                    <button type="button" 
                                                            class="btn btn-outline-info" 
                                                            title="Ver InformaciÃ³n Completa"
                                                            onclick="mostrarInfoEstudiante(<?php echo $estudiante['id']; ?>)">
                                                        <i class="fas fa-eye"></i>
                                                    </button>
                                                    <a href="editar.php?id=<?php echo $estudiante['id']; ?>" 
                                                       class="btn btn-outline-primary" 
                                                       title="Editar">
                                                        <i class="fas fa-edit"></i>
                                                    </a>
                                                    <a href="../inscripciones/inscribir.php?estudiante_id=<?php echo $estudiante['id']; ?>" 
                                                       class="btn btn-outline-success" 
                                                       title="Inscribir">
                                                        <i class="fas fa-user-plus"></i>
                                                    </a>
                                                    <button type="button" 
                                                            class="btn btn-outline-danger" 
                                                            title="Eliminar"
                                                            onclick="confirmarEliminacion(<?php echo $estudiante['id']; ?>, '<?php echo htmlspecialchars($estudiante['nombre'] . ' ' . $estudiante['apellido']); ?>', 'estudiante')">
                                                        <i class="fas fa-trash"></i>
                                                    </button>
                                                </div>
                                            </td>
                                            <td>
                                                <div class="btn-group-vertical btn-group-sm">
                                                    <!-- BotÃ³n para Boleta -->
                                                    <button type="button" 
                                                            class="btn <?php echo $puede_generar_boleta ? 'btn-warning' : 'btn-outline-warning'; ?>" 
                                                            title="Generar Boleta"
                                                            onclick="generarBoleta(<?php echo $estudiante['id']; ?>, '<?php echo htmlspecialchars($estudiante['nombre'] . ' ' . $estudiante['apellido']); ?>', <?php echo $puede_generar_boleta ? 'true' : 'false'; ?>)">
                                                        <i class="fas fa-file-alt"></i> Boleta
                                                    </button>
                                                    
                                                    <!-- BotÃ³n para Carta de Buena Conducta -->
                                                    <button type="button" 
                                                            class="btn btn-outline-success" 
                                                            title="Generar Carta de Buena Conducta"
                                                            onclick="generarBuenaConducta(<?php echo $estudiante['id']; ?>)">
                                                        <i class="fas fa-file-contract"></i> Conducta
                                                    </button>

                                                    <!-- BotÃ³n para Ficha del estudiante -->
                                                    <button type="button" 
                                                            class="btn btn-outline-dark" 
                                                            title="Exportar Ficha del Estudiante"
                                                            onclick="exportarFicha(<?php echo $estudiante['id']; ?>)">
                                                        <i class="fas fa-id-card"></i> Ficha
                                                    </button>
                                                    
                                                    <!-- BotÃ³n para Carta de Retiro -->
                                                    <button type="button" 
                                                            class="btn btn-outline-info" 
                                                            title="Generar Carta de Retiro"
                                                            onclick="generarCartaRetiro(<?php echo $estudiante['id']; ?>)">
                                                        <i class="fas fa-file-export"></i> Retiro
                                                    </button>
                                                    
                                                    <!-- BotÃ³n para Carta de Estudio -->
                                                    <button type="button" 
                                                            class="btn btn-outline-primary" 
                                                            title="Generar Carta de Estudio"
                                                            onclick="generarCartaEstudio(<?php echo $estudiante['id']; ?>)">
                                                        <i class="fas fa-file-certificate"></i> Estudio
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
                            <i class="fas fa-users fa-3x text-muted mb-3"></i>
                            <h5>No hay estudiantes registrados</h5>
                            <p class="text-muted"><?php echo $busqueda ? 'No se encontraron estudiantes con los criterios de bÃºsqueda.' : 'Comienza agregando el primer estudiante al sistema.'; ?></p>
                            <?php if (!$busqueda): ?>
                                <a href="agregar.php" class="btn btn-primary">
                                    <i class="fas fa-user-plus me-2"></i>Agregar Primer Estudiante
                                </a>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Pestaña de Representantes -->
        <div class="tab-pane fade <?php echo $filtro_tipo === 'representantes' ? 'show active' : ''; ?>" 
             id="representantes" role="tabpanel" aria-labelledby="representantes-tab">
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="card-title mb-0">
                        <i class="fas fa-user-friends me-2"></i>Lista de Representantes
                    </h5>
                    <span class="badge bg-info">
                        Total: <?php echo count($representantes); ?>
                    </span>
                </div>
                <div class="card-body">
                    <?php if ($representantes): ?>
                        <div class="table-responsive">
                            <table class="table table-striped table-hover">
                                <thead>
                                    <tr>
                                        <th>CÃ©dula</th>
                                        <th>Nombre Completo</th>
                                        <th>Parentesco</th>
                                        <th>TelÃ©fonos</th>
                                        <th>Email</th>
                                        <th>Estudiantes</th>
                                        <th>ProfesiÃ³n</th>
                                        <th>Acciones</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($representantes as $representante): ?>
                                        <tr>
                                            <td>
                                                <span class="badge bg-secondary"><?php echo htmlspecialchars($representante['cedula']); ?></span>
                                            </td>
                                            <td>
                                                <strong><?php echo htmlspecialchars($representante['nombre'] . ' ' . $representante['apellido']); ?></strong>
                                                <?php if ($representante['genero']): ?>
                                                    <br><small class="text-muted"><?php echo ucfirst($representante['genero']); ?></small>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <span class="badge bg-light text-dark"><?php echo ucfirst($representante['parentesco']); ?></span>
                                            </td>
                                            <td>
                                                <div>
                                                    <small><strong>Principal:</strong> <?php echo htmlspecialchars($representante['telefono']); ?></small>
                                                </div>
                                                <?php if ($representante['telefono_alternativo']): ?>
                                                    <div>
                                                        <small><strong>Alternativo:</strong> <?php echo htmlspecialchars($representante['telefono_alternativo']); ?></small>
                                                    </div>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if ($representante['email']): ?>
                                                    <small><?php echo htmlspecialchars($representante['email']); ?></small>
                                                <?php else: ?>
                                                    <span class="text-muted">No tiene</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <span class="badge bg-info"><?php echo $representante['total_estudiantes']; ?></span>
                                            </td>
                                            <td>
                                                <?php if ($representante['profesion']): ?>
                                                    <small><?php echo htmlspecialchars($representante['profesion']); ?></small>
                                                    <?php if ($representante['lugar_trabajo']): ?>
                                                        <br><small class="text-muted"><?php echo htmlspecialchars($representante['lugar_trabajo']); ?></small>
                                                    <?php endif; ?>
                                                <?php else: ?>
                                                    <span class="text-muted">No especificada</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <div class="btn-group btn-group-sm">
                                                    <button type="button" 
                                                            class="btn btn-outline-info" 
                                                            title="Ver Estudiantes Asociados"
                                                            onclick="mostrarInfoRepresentante(<?php echo $representante['id']; ?>)">
                                                        <i class="fas fa-eye"></i>
                                                    </button>
                                                    <a href="editar_representante.php?id=<?php echo $representante['id']; ?>" 
                                                       class="btn btn-outline-primary" 
                                                       title="Editar">
                                                        <i class="fas fa-edit"></i>
                                                    </a>
                                                    <button type="button" 
                                                            class="btn btn-outline-danger" 
                                                            title="Eliminar"
                                                            onclick="confirmarEliminacion(<?php echo $representante['id']; ?>, '<?php echo htmlspecialchars($representante['nombre'] . ' ' . $representante['apellido']); ?>', 'representante')">
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
                            <i class="fas fa-user-friends fa-3x text-muted mb-3"></i>
                            <h5>No hay representantes registrados</h5>
                            <p class="text-muted"><?php echo $busqueda ? 'No se encontraron representantes con los criterios de bÃºsqueda.' : 'Los representantes se crean automÃ¡ticamente al agregar estudiantes.'; ?></p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Modal para informaciÃ³n del estudiante -->
<div class="modal fade" id="modalInfoEstudiante" tabindex="-1" aria-labelledby="modalInfoEstudianteLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title" id="modalInfoEstudianteLabel">
                    <i class="fas fa-user-graduate me-2"></i>InformaciÃ³n Completa del Estudiante
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body" id="contenidoInfoEstudiante">
                <!-- Contenido se carga dinÃ¡micamente -->
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cerrar</button>
            </div>
        </div>
    </div>
</div>

<!-- Modal para informaciÃ³n del representante -->
<div class="modal fade" id="modalInfoRepresentante" tabindex="-1" aria-labelledby="modalInfoRepresentanteLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-info text-white">
                <h5 class="modal-title" id="modalInfoRepresentanteLabel">
                    <i class="fas fa-user-friends me-2"></i>Estudiantes del Representante
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body" id="contenidoInfoRepresentante">
                <!-- Contenido se carga dinÃ¡micamente -->
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cerrar</button>
            </div>
        </div>
    </div>
</div>

<!-- Modal para generar carta de retiro -->
<div class="modal fade" id="modalCartaRetiro" tabindex="-1" aria-labelledby="modalCartaRetiroLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-info text-white">
                <h5 class="modal-title" id="modalCartaRetiroLabel">
                    <i class="fas fa-file-export me-2"></i>Generar Carta de Retiro
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <form id="formCartaRetiro">
                    <input type="hidden" id="estudiante_id_retiro" name="estudiante_id">
                    <div class="mb-3">
                        <label for="causa_retiro" class="form-label">Causa de Retiro</label>
                        <select class="form-select" id="causa_retiro" name="causa_retiro" required>
                            <option value="CAMBIO DE INSTITUCION">CAMBIO DE INSTITUCION</option>
                            <option value="TRASLADO DE DOMICILIO">TRASLADO DE DOMICILIO</option>
                            <option value="PROBLEMAS DE SALUD">PROBLEMAS DE SALUD</option>
                            <option value="SITUACION ECONOMICA">SITUACION ECONOMICA</option>
                            <option value="OTRO">OTRO</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label for="observaciones_retiro" class="form-label">Observaciones (Opcional)</label>
                        <textarea class="form-control" id="observaciones_retiro" name="observaciones" rows="3" placeholder="Especificar si seleccionÃ³ OTRO..."></textarea>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                <button type="button" class="btn btn-primary" onclick="generarCartaRetiroConfirm()">
                    <i class="fas fa-download me-2"></i>Generar Carta
                </button>
            </div>
        </div>
    </div>
</div>

<script>
function confirmarEliminacion(id, nombre, tipo) {
    const tipoTexto = tipo === 'estudiante' ? 'estudiante' : 'representante';
    if (confirm(`Â¿EstÃ¡s seguro de que deseas eliminar al ${tipoTexto} "${nombre}"?\n\nEsta acciÃ³n no se puede deshacer.`)) {
        window.location.href = `gestionar.php?eliminar=${id}&tipo=${tipo}`;
    }
}

function actualizarFiltros() {
    const tipo = document.getElementById('filtro_tipo').value;
    const filtroCampo = document.getElementById('filtro_campo');
    
    if (tipo === 'estudiantes') {
        filtroCampo.innerHTML = `
            <option value="nombre">Nombre del estudiante</option>
            <option value="cedula">CÃ©dula escolar</option>
            <option value="representante">Representante</option>
        `;
    } else {
        filtroCampo.innerHTML = `
            <option value="nombre">Nombre del representante</option>
            <option value="cedula">CÃ©dula del representante</option>
            <option value="telefono">TelÃ©fono</option>
        `;
    }
}

function exportarListado() {
    window.open('../../exportar_listado.php', '_blank');
}

function exportarFicha(estudianteId) {
    window.open(`../../exportar_ficha.php?estudiante_id=${estudianteId}`, '_blank');
}

// FunciÃ³n para generar boleta
function generarBoleta(estudianteId, estudianteNombre, tieneLiteral) {
    if (!tieneLiteral) {
        alert('No se puede generar la boleta porque el estudiante no tiene literal final registrado para el año escolar actual.');
        return;
    }
    
    // Abrir en nueva pestaña el generador de boletas
    window.open(`../boletas/generar_boleta.php?estudiante_id=${estudianteId}`, '_blank');
}

// FunciÃ³n para generar carta de buena conducta
function generarBuenaConducta(estudianteId) {
    // Abrir en nueva pestaña el generador de carta de buena conducta
    window.open(`../documentos/generar_buena_conducta.php?estudiante_id=${estudianteId}`, '_blank');
}

// FunciÃ³n para abrir modal de carta de retiro
function generarCartaRetiro(estudianteId) {
    document.getElementById('estudiante_id_retiro').value = estudianteId;
    const modal = new bootstrap.Modal(document.getElementById('modalCartaRetiro'));
    modal.show();
}

// FunciÃ³n para confirmar generaciÃ³n de carta de retiro
function generarCartaRetiroConfirm() {
    const estudianteId = document.getElementById('estudiante_id_retiro').value;
    const causa = document.getElementById('causa_retiro').value;
    const observaciones = document.getElementById('observaciones_retiro').value;
    
    // Construir URL con parÃ¡metros
    let url = `../documentos/generar_carta_retiro.php?estudiante_id=${estudianteId}&causa=${encodeURIComponent(causa)}`;
    if (observaciones) {
        url += `&observaciones=${encodeURIComponent(observaciones)}`;
    }
    
    // Cerrar modal y abrir generador
    const modal = bootstrap.Modal.getInstance(document.getElementById('modalCartaRetiro'));
    modal.hide();
    
    // Abrir en nueva pestaña
    window.open(url, '_blank');
}

// FunciÃ³n para generar carta de estudio
function generarCartaEstudio(estudianteId) {
    // Abrir en nueva pestaña el generador de carta de estudio
    window.open(`../documentos/generar_carta_estudio.php?estudiante_id=${estudianteId}`, '_blank');
}

// FunciÃ³n para mostrar informaciÃ³n del estudiante
function mostrarInfoEstudiante(estudianteId) {
    // Mostrar loading
    document.getElementById('contenidoInfoEstudiante').innerHTML = `
        <div class="text-center py-4">
            <div class="spinner-border text-primary" role="status">
                <span class="visually-hidden">Cargando...</span>
            </div>
            <p class="mt-2">Cargando informaciÃ³n del estudiante...</p>
        </div>
    `;
    
    const modal = new bootstrap.Modal(document.getElementById('modalInfoEstudiante'));
    modal.show();
    
    // Hacer peticiÃ³n AJAX para obtener informaciÃ³n
    fetch(`gestionar.php?info_estudiante=${estudianteId}`)
        .then(response => response.json())
        .then(data => {
            const estudiante = data.estudiante;
            const representantesAdicionales = data.representantes_adicionales;
            
            let contenido = `
                <div class="row">
                    <div class="col-md-6">
                        <h6 class="border-bottom pb-2">Datos Personales</h6>
                        <p><strong>CÃ©dula Escolar:</strong> ${estudiante.cedula_escolar}</p>
                        <p><strong>Nombre Completo:</strong> ${estudiante.nombre} ${estudiante.apellido}</p>
                        <p><strong>GÃ©nero:</strong> ${estudiante.genero ? estudiante.genero.charAt(0).toUpperCase() + estudiante.genero.slice(1) : 'No especificado'}</p>
                        <p><strong>Fecha Nacimiento:</strong> ${estudiante.fecha_nacimiento ? new Date(estudiante.fecha_nacimiento).toLocaleDateString() : 'No especificada'}</p>
                        <p><strong>Lugar Nacimiento:</strong> ${estudiante.lugar_nacimiento || 'No especificado'}</p>
                    </div>
                    <div class="col-md-6">
                        <h6 class="border-bottom pb-2">InformaciÃ³n de Contacto</h6>
                        <p><strong>TelÃ©fono:</strong> ${estudiante.telefono || 'No especificado'}</p>
                        <p><strong>Email:</strong> ${estudiante.email || 'No especificado'}</p>
                        <p><strong>DirecciÃ³n:</strong> ${estudiante.direccion || 'No especificada'}</p>
                        <p><strong>Escuela Anterior:</strong> ${estudiante.escuela_anterior || 'No especificada'}</p>
                    </div>
                </div>
                
                <div class="row mt-3">
                    <div class="col-md-6">
                        <h6 class="border-bottom pb-2">InformaciÃ³n Familiar</h6>
                        <p><strong>Tipo de Vivienda:</strong> ${estudiante.tipo_vivienda || 'No especificado'}</p>
                        <p><strong>Personas en el Hogar:</strong> ${estudiante.personas_conviven || 'No especificado'}</p>
                        <p><strong>Habilidades Especiales:</strong> ${estudiante.habilidades_especiales || 'Ninguna especificada'}</p>
                    </div>
                    <div class="col-md-6">
                        <h6 class="border-bottom pb-2">InformaciÃ³n MÃ©dica</h6>
                        <p><strong>Enfermedades:</strong> ${estudiante.enfermedades || 'Ninguna'}</p>
                        <p><strong>Alergias:</strong> ${estudiante.alergias || 'Ninguna'}</p>
                        <p><strong>Medicamentos:</strong> ${estudiante.medicamentos || 'Ninguno'}</p>
                    </div>
                </div>
            `;
            
            // InformaciÃ³n de inscripciÃ³n
            if (estudiante.seccion_nombre) {
                contenido += `
                    <div class="row mt-3">
                        <div class="col-12">
                            <h6 class="border-bottom pb-2">InscripciÃ³n Actual</h6>
                            <p><strong>SecciÃ³n:</strong> ${estudiante.seccion_nombre}</p>
                            <p><strong>Año Escolar:</strong> ${estudiante.año_escolar}</p>
                            <p><strong>Fecha InscripciÃ³n:</strong> ${new Date(estudiante.fecha_inscripcion).toLocaleDateString()}</p>
                            <p><strong>Estado:</strong> <span class="badge bg-success">${estudiante.inscripcion_status}</span></p>
                        </div>
                    </div>
                `;
            }
            
            // Representante principal
            if (estudiante.rep_nombre) {
                contenido += `
                    <div class="row mt-3">
                        <div class="col-12">
                            <h6 class="border-bottom pb-2">Representante Principal</h6>
                            <div class="card">
                                <div class="card-body">
                                    <p><strong>Nombre:</strong> ${estudiante.rep_nombre} ${estudiante.rep_apellido}</p>
                                    <p><strong>CÃ©dula:</strong> ${estudiante.rep_cedula}</p>
                                    <p><strong>Parentesco:</strong> ${estudiante.rep_parentesco ? estudiante.rep_parentesco.charAt(0).toUpperCase() + estudiante.rep_parentesco.slice(1) : 'No especificado'}</p>
                                    <p><strong>TelÃ©fono:</strong> ${estudiante.rep_telefono || 'No especificado'}</p>
                                    <p><strong>Email:</strong> ${estudiante.rep_email || 'No especificado'}</p>
                                    <p><strong>ProfesiÃ³n:</strong> ${estudiante.rep_profesion || 'No especificada'}</p>
                                    <p><strong>Lugar Trabajo:</strong> ${estudiante.rep_lugar_trabajo || 'No especificado'}</p>
                                </div>
                            </div>
                        </div>
                    </div>
                `;
            }
            
            // Representantes adicionales
            if (representantesAdicionales.length > 0) {
                contenido += `
                    <div class="row mt-3">
                        <div class="col-12">
                            <h6 class="border-bottom pb-2">Representantes Adicionales</h6>
                            ${representantesAdicionales.map(rep => `
                                <div class="card mb-2">
                                    <div class="card-body">
                                        <p><strong>Nombre:</strong> ${rep.nombre} ${rep.apellido}</p>
                                        <p><strong>CÃ©dula:</strong> ${rep.cedula}</p>
                                        <p><strong>Parentesco:</strong> ${rep.parentesco ? rep.parentesco.charAt(0).toUpperCase() + rep.parentesco.slice(1) : 'No especificado'}</p>
                                        <p><strong>TelÃ©fono:</strong> ${rep.telefono || 'No especificado'}</p>
                                        <p><strong>Email:</strong> ${rep.email || 'No especificado'}</p>
                                    </div>
                                </div>
                            `).join('')}
                        </div>
                    </div>
                `;
            }
            
            // Observaciones
            if (estudiante.observaciones) {
                contenido += `
                    <div class="row mt-3">
                        <div class="col-12">
                            <h6 class="border-bottom pb-2">Observaciones</h6>
                            <p>${estudiante.observaciones}</p>
                        </div>
                    </div>
                `;
            }
            
            document.getElementById('contenidoInfoEstudiante').innerHTML = contenido;
        })
        .catch(error => {
            document.getElementById('contenidoInfoEstudiante').innerHTML = `
                <div class="alert alert-danger">
                    <i class="fas fa-exclamation-triangle me-2"></i>
                    Error al cargar la informaciÃ³n del estudiante: ${error.message}
                </div>
            `;
        });
}

// FunciÃ³n para mostrar informaciÃ³n del representante
function mostrarInfoRepresentante(representanteId) {
    // Mostrar loading
    document.getElementById('contenidoInfoRepresentante').innerHTML = `
        <div class="text-center py-4">
            <div class="spinner-border text-info" role="status">
                <span class="visually-hidden">Cargando...</span>
            </div>
            <p class="mt-2">Cargando informaciÃ³n del representante...</p>
        </div>
    `;
    
    const modal = new bootstrap.Modal(document.getElementById('modalInfoRepresentante'));
    modal.show();
    
    // Hacer peticiÃ³n AJAX para obtener informaciÃ³n
    fetch(`gestionar.php?info_representante=${representanteId}`)
        .then(response => response.json())
        .then(data => {
            const estudiantes = data.estudiantes;
            
            if (estudiantes.length === 0) {
                document.getElementById('contenidoInfoRepresentante').innerHTML = `
                    <div class="text-center py-4">
                        <i class="fas fa-user-graduate fa-3x text-muted mb-3"></i>
                        <h5>No tiene estudiantes asociados</h5>
                        <p class="text-muted">Este representante no tiene estudiantes asignados actualmente.</p>
                    </div>
                `;
                return;
            }
            
            let contenido = `
                <div class="table-responsive">
                    <table class="table table-striped table-hover">
                        <thead>
                            <tr>
                                <th>CÃ©dula Escolar</th>
                                <th>Estudiante</th>
                                <th>Parentesco</th>
                                <th>SecciÃ³n</th>
                                <th>Año Escolar</th>
                                <th>Estado</th>
                                <th>Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
            `;
            
            estudiantes.forEach(estudiante => {
                contenido += `
                    <tr>
                        <td><span class="badge bg-secondary">${estudiante.cedula_escolar}</span></td>
                        <td>
                            <strong>${estudiante.nombre} ${estudiante.apellido}</strong>
                            ${estudiante.genero ? `<br><small class="text-muted">${estudiante.genero.charAt(0).toUpperCase() + estudiante.genero.slice(1)}</small>` : ''}
                        </td>
                        <td><span class="badge bg-light text-dark">${estudiante.parentesco ? estudiante.parentesco.charAt(0).toUpperCase() + estudiante.parentesco.slice(1) : 'No especificado'}</span></td>
                        <td>${estudiante.seccion_nombre || 'No inscrito'}</td>
                        <td>${estudiante.año_escolar || 'No asignado'}</td>
                        <td><span class="badge ${estudiante.inscripcion_status === 'activo' ? 'bg-success' : 'bg-warning'}">${estudiante.inscripcion_status || 'No inscrito'}</span></td>
                        <td>
                            <div class="btn-group btn-group-sm">
                                <a href="editar.php?id=${estudiante.id}" class="btn btn-outline-primary" title="Editar">
                                    <i class="fas fa-edit"></i>
                                </a>
                                <button type="button" class="btn btn-outline-info" title="Ver InformaciÃ³n" onclick="mostrarInfoEstudiante(${estudiante.id})">
                                    <i class="fas fa-eye"></i>
                                </button>
                            </div>
                        </td>
                    </tr>
                `;
            });
            
            contenido += `
                        </tbody>
                    </table>
                </div>
                <div class="mt-3">
                    <p class="text-muted"><small>Total de estudiantes: ${estudiantes.length}</small></p>
                </div>
            `;
            
            document.getElementById('contenidoInfoRepresentante').innerHTML = contenido;
        })
        .catch(error => {
            document.getElementById('contenidoInfoRepresentante').innerHTML = `
                <div class="alert alert-danger">
                    <i class="fas fa-exclamation-triangle me-2"></i>
                    Error al cargar la informaciÃ³n del representante: ${error.message}
                </div>
            `;
        });
}

// Inicializar filtros al cargar la pÃ¡gina
document.addEventListener('DOMContentLoaded', function() {
    actualizarFiltros();
    
    // Activar la pestaña correcta segÃºn el tipo de filtro
    const tipo = '<?php echo $filtro_tipo; ?>';
    if (tipo === 'representantes') {
        const tab = new bootstrap.Tab(document.getElementById('representantes-tab'));
        tab.show();
    }
});
</script>

<?php include '../../includes/footer.php'; ?>
