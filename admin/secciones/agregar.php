<?php
require_once '../../includes/auth.php';
require_once '../../includes/database.php';
checkAuth();
checkRole(['admin']);

$mensaje = '';
$tipo_mensaje = '';

// Obtener el grado_id desde la URL (si viene de las cards)
$grado_id_preseleccionado = $_GET['grado_id'] ?? null;

// Obtener años escolares - SOLO EL ACTIVO
try {
    $stmt = $pdo->query("SELECT id, nombre FROM años_escolares WHERE estado = 'activo' ORDER BY nombre DESC");
    $años_escolares = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Obtener el año escolar activo (el primero de la lista)
    $año_escolar_activo = $años_escolares[0] ?? null;
    
} catch (PDOException $e) {
    $años_escolares = [];
    $año_escolar_activo = null;
}

// Obtener profesores activos
try {
    $stmt = $pdo->query("SELECT id, cedula, nombre, apellido, especialidad FROM profesores WHERE estado = 'activo' ORDER BY nombre, apellido");
    $profesores = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $profesores = [];
}

// Obtener grados activos desde la base de datos
try {
    $stmt = $pdo->query("SELECT id, numero, nombre FROM grados WHERE estado = 'activo' ORDER BY orden");
    $grados = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $grados = [];
    error_log("Error al cargar grados: " . $e->getMessage());
}

// Si viene un grado_id por GET, validar que existe
$grado_seleccionado = null;
if ($grado_id_preseleccionado) {
    foreach ($grados as $grado) {
        if ($grado['id'] == $grado_id_preseleccionado) {
            $grado_seleccionado = $grado;
            break;
        }
    }
    
    // Si el grado no existe, mostrar error
    if (!$grado_seleccionado) {
        $_SESSION['mensaje'] = "El grado seleccionado no existe";
        $_SESSION['tipo_mensaje'] = "danger";
        header('Location: gestionar.php');
        exit;
    }
}

// Obtener especialidades únicas para el filtro
$especialidades = [];
foreach ($profesores as $profesor) {
    if ($profesor['especialidad'] && !in_array($profesor['especialidad'], $especialidades)) {
        $especialidades[] = $profesor['especialidad'];
    }
}
sort($especialidades);

// Obtener áreas de aprendizaje para primaria
try {
    $stmt = $pdo->query("SELECT id, nombre, codigo FROM areas_aprendizaje WHERE nivel = 'primaria' AND estado = 'activa' ORDER BY nombre");
    $areas_aprendizaje = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $areas_aprendizaje = [];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Si viene de un grado específico, usar ese grado_id, sino usar el del formulario
    $grado_id = $grado_id_preseleccionado ?: $_POST['grado_id'];
    $letra = $_POST['letra'];
    $año_escolar_id = $_POST['año_escolar_id'];
    $capacidad = $_POST['capacidad'] ?: null;
    $profesor_id = $_POST['profesor_id'] ?? null;
    $nivel = 'primaria';
    $tipo_grado = 'grado';
    
    // Obtener nombre del grado para construir el nombre de la sección
    $nombre_grado = '';
    foreach ($grados as $grado) {
        if ($grado['id'] == $grado_id) {
            $nombre_grado = $grado['nombre'];
            break;
        }
    }
    
    $nombre = $nombre_grado . ' ' . $letra;
    
    // DEBUG DETALLADO
    error_log("=== DEBUG DETALLADO DEL FORMULARIO ===");
    error_log("grado_id: " . ($grado_id ?: 'VACÍO'));
    error_log("nombre_grado: " . ($nombre_grado ?: 'VACÍO'));
    error_log("letra: " . ($letra ?: 'VACÍO'));
    error_log("año_escolar_id: " . ($año_escolar_id ?: 'VACÍO'));
    error_log("profesor_id: " . ($profesor_id ?: 'VACÍO'));
    error_log("capacidad: " . ($capacidad ?: 'NULL'));
    error_log("Todos los POST: " . print_r($_POST, true));
    error_log("=====================================");
    
    // Validar campos obligatorios con más detalle
    $campos_faltantes = [];
    if (empty($grado_id)) $campos_faltantes[] = "Grado";
    if (empty($letra)) $campos_faltantes[] = "Letra";
    if (empty($año_escolar_id)) $campos_faltantes[] = "Año Escolar";
    if (empty($profesor_id)) $campos_faltantes[] = "Profesor Titular";
    
    if (empty($campos_faltantes)) {
        try {
            $pdo->beginTransaction();
            
            $stmt = $pdo->prepare("SELECT id FROM secciones WHERE nombre = ? AND año_escolar_id = ?");
            $stmt->execute([$nombre, $año_escolar_id]);
            
            if ($stmt->fetch()) {
                $mensaje = "Ya existe una sección con el nombre '$nombre' en este año escolar";
                $tipo_mensaje = "warning";
            } else {
                // Incluir grado_id en el INSERT
                $stmt = $pdo->prepare("INSERT INTO secciones (nombre, año_escolar_id, grado_id, capacidad, nivel, tipo_grado, profesor_id) VALUES (?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([$nombre, $año_escolar_id, $grado_id, $capacidad, $nivel, $tipo_grado, $profesor_id]);
                $seccion_id = $pdo->lastInsertId();
                
                error_log("DEBUG - Sección creada con ID: " . $seccion_id);
                
                // Crear horario automático
                if (count($areas_aprendizaje) > 0) {
                    $dias_semana = ['lunes', 'martes', 'miercoles', 'jueves', 'viernes'];
                    $horarios_base = [
                        ['07:30:00', '08:15:00'],
                        ['08:15:00', '09:00:00'],
                        ['09:00:00', '09:45:00'],
                        ['09:45:00', '10:30:00'],
                        ['10:30:00', '11:15:00'],
                        ['11:15:00', '12:00:00']
                    ];
                    
                    $area_index = 0;
                    $total_areas = count($areas_aprendizaje);
                    
                    foreach ($dias_semana as $dia) {
                        foreach ($horarios_base as $horario) {
                            if ($area_index < $total_areas) {
                                $area_id = $areas_aprendizaje[$area_index]['id'];
                                $stmt = $pdo->prepare("INSERT INTO horarios_asignaciones (seccion_id, año_escolar_id, dia_semana, hora_inicio, hora_fin, area_id, profesor_id) VALUES (?, ?, ?, ?, ?, ?, ?)");
                                $stmt->execute([$seccion_id, $año_escolar_id, $dia, $horario[0], $horario[1], $area_id, $profesor_id]);
                                $area_index++;
                                if ($area_index >= $total_areas) $area_index = 0;
                            }
                        }
                    }
                    error_log("DEBUG - Horarios creados para " . $total_areas . " áreas");
                }
                
                $pdo->commit();
                $mensaje = "Sección '$nombre' agregada correctamente con " . count($areas_aprendizaje) . " áreas de aprendizaje";
                $tipo_mensaje = "success";
                $_POST = [];

                header("Location: gestionar.php?mensaje=" . urlencode($mensaje) . "&tipo=" . urlencode($tipo_mensaje) . "&nueva_seccion_id=" . (int)$seccion_id);
                exit;
            }
        } catch (PDOException $e) {
            $pdo->rollBack();
            $mensaje = "Error al agregar sección: " . $e->getMessage();
            $tipo_mensaje = "danger";
            error_log("ERROR - " . $e->getMessage());
        }
    } else {
        $mensaje = "Por favor complete los campos obligatorios. Faltan: " . implode(", ", $campos_faltantes);
        $tipo_mensaje = "warning";
        error_log("DEBUG - Campos faltantes: " . implode(", ", $campos_faltantes));
    }
}
?>

<?php include '../../includes/header.php'; ?>

<div class="content-wrapper" style="padding: 20px;">
    <?php include '../../includes/sidebar_admin.php'; ?>
    
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2>
            <?php if ($grado_seleccionado): ?>
                Agregar Sección - <?php echo htmlspecialchars($grado_seleccionado['nombre']); ?>
            <?php else: ?>
                Agregar Nueva Sección - PRIMARIA
            <?php endif; ?>
        </h2>
        <a href="gestionar.php" class="btn btn-outline-secondary">
            <i class="fas fa-arrow-left me-2"></i>Volver a la lista
        </a>
    </div>

    <?php if ($mensaje): ?>
        <div class="alert alert-<?php echo $tipo_mensaje; ?> alert-dismissible fade show" role="alert">
            <?php echo $mensaje; ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- Checklist de Configuración -->
    <div class="card mb-4 border-warning">
        <div class="card-header bg-warning text-dark">
            <h5 class="card-title mb-0">
                <i class="fas fa-clipboard-check me-2"></i>Checklist de Configuración del Sistema
            </h5>
        </div>
        <div class="card-body">
            <div class="row">
                <?php
                // Verificar configuraciones necesarias
                $configuraciones = [
                    'años_escolares' => [
                        'nombre' => 'Año Escolar',
                        'icono' => 'fas fa-calendar-alt',
                        'estado' => false,
                        'enlace' => '../años_escolares/gestionar.php',
                        'descripcion' => 'Configure el año escolar activo'
                    ],
                    'areas_aprendizaje' => [
                        'nombre' => 'Áreas de Aprendizaje',
                        'icono' => 'fas fa-book',
                        'estado' => false,
                        'enlace' => '../areas_aprendizaje/gestionar.php',
                        'descripcion' => 'Defina las materias/áreas del sistema'
                    ],
                    'profesores' => [
                        'nombre' => 'Profesores',
                        'icono' => 'fas fa-chalkboard-teacher',
                        'estado' => false,
                        'enlace' => '../profesores/gestionar.php',
                        'descripcion' => 'Registre los profesores del plantel'
                    ],
                    'grados' => [
                        'nombre' => 'Grados',
                        'icono' => 'fas fa-graduation-cap',
                        'estado' => false,
                        'enlace' => 'gestionar.php',
                        'descripcion' => 'Configure los grados del sistema'
                    ]
                ];

                // Verificar estado actual
                try {
                    // Verificar años escolares
                    $stmt = $pdo->query("SELECT COUNT(*) as total FROM años_escolares WHERE estado = 'activo'");
                    $configuraciones['años_escolares']['estado'] = $stmt->fetch()['total'] > 0;
                    
                    // Verificar áreas de aprendizaje
                    $stmt = $pdo->query("SELECT COUNT(*) as total FROM areas_aprendizaje WHERE estado = 'activa'");
                    $configuraciones['areas_aprendizaje']['estado'] = $stmt->fetch()['total'] > 0;
                    
                    // Verificar profesores
                    $stmt = $pdo->query("SELECT COUNT(*) as total FROM profesores WHERE estado = 'activo'");
                    $configuraciones['profesores']['estado'] = $stmt->fetch()['total'] > 0;
                    
                    // Verificar grados
                    $stmt = $pdo->query("SELECT COUNT(*) as total FROM grados WHERE estado = 'activo'");
                    $configuraciones['grados']['estado'] = $stmt->fetch()['total'] > 0;
                    
                } catch (PDOException $e) {
                    error_log("Error verificando configuraciones: " . $e->getMessage());
                }

                // Calcular progreso
                $total_configuraciones = count($configuraciones);
                $configuraciones_completadas = 0;
                foreach ($configuraciones as $config) {
                    if ($config['estado']) {
                        $configuraciones_completadas++;
                    }
                }
                $porcentaje_completo = $total_configuraciones > 0 ? round(($configuraciones_completadas / $total_configuraciones) * 100) : 0;
                ?>

                <!-- Barra de progreso -->
                <div class="col-12 mb-3">
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <span class="fw-bold">Progreso de Configuración:</span>
                        <span class="badge bg-<?php echo $porcentaje_completo == 100 ? 'success' : 'warning'; ?>">
                            <?php echo $configuraciones_completadas; ?>/<?php echo $total_configuraciones; ?> completado
                        </span>
                    </div>
                    <div class="progress" style="height: 10px;">
                        <div class="progress-bar bg-<?php echo $porcentaje_completo == 100 ? 'success' : 'warning'; ?>" 
                             style="width: <?php echo $porcentaje_completo; ?>%">
                        </div>
                    </div>
                </div>

                <!-- Items de configuración -->
                <?php foreach ($configuraciones as $key => $config): ?>
                    <div class="col-md-6 col-lg-3 mb-3">
                        <div class="card h-100 border-<?php echo $config['estado'] ? 'success' : 'secondary'; ?>">
                            <div class="card-body text-center">
                                <div class="mb-3">
                                    <i class="<?php echo $config['icono']; ?> fa-2x text-<?php echo $config['estado'] ? 'success' : 'secondary'; ?>"></i>
                                </div>
                                <h6 class="card-title"><?php echo $config['nombre']; ?></h6>
                                <p class="card-text small text-muted"><?php echo $config['descripcion']; ?></p>
                                
                                <?php if ($config['estado']): ?>
                                    <span class="badge bg-success">
                                        <i class="fas fa-check me-1"></i>Completado
                                    </span>
                                <?php else: ?>
                                    <span class="badge bg-warning text-dark">
                                        <i class="fas fa-clock me-1"></i>Pendiente
                                    </span>
                                    <div class="mt-2">
                                        <a href="<?php echo $config['enlace']; ?>" class="btn btn-sm btn-outline-primary">
                                            Configurar
                                        </a>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <!-- Mensaje según el progreso -->
            <div class="mt-3">
                <?php if ($porcentaje_completo == 100): ?>
                    <div class="alert alert-success mb-0">
                        <i class="fas fa-check-circle me-2"></i>
                        <strong>¡Configuración completa!</strong> El sistema está listo para funcionar.
                    </div>
                <?php elseif ($porcentaje_completo >= 75): ?>
                    <div class="alert alert-info mb-0">
                        <i class="fas fa-info-circle me-2"></i>
                        <strong>¡Buen progreso!</strong> Solo faltan algunos ajustes para completar la configuración.
                    </div>
                <?php elseif ($porcentaje_completo >= 50): ?>
                    <div class="alert alert-warning mb-0">
                        <i class="fas fa-exclamation-triangle me-2"></i>
                        <strong>Configuración a medias.</strong> Complete las configuraciones pendientes para un funcionamiento óptimo.
                    </div>
                <?php else: ?>
                    <div class="alert alert-danger mb-0">
                        <i class="fas fa-exclamation-circle me-2"></i>
                        <strong>Configuración básica requerida.</strong> Configure los elementos esenciales para comenzar.
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-header bg-primary text-white">
            <h5 class="card-title mb-0">
                <i class="fas fa-chalkboard-teacher me-2"></i>
                <?php if ($grado_seleccionado): ?>
                    Datos de la Sección - <?php echo htmlspecialchars($grado_seleccionado['nombre']); ?>
                <?php else: ?>
                    Datos de la Sección - NIVEL PRIMARIA
                <?php endif; ?>
            </h5>
        </div>
        <div class="card-body">
            <form method="POST" action="" id="form-seccion">
                <div class="row">
                    <div class="col-md-12 mb-3">
                        <div class="alert alert-info">
                            <strong><i class="fas fa-info-circle me-2"></i>NIVEL PRIMARIA:</strong> 
                            Sistema de profesor titular que imparte todas las áreas de aprendizaje básicas.
                        </div>
                    </div>
                    
                    <!-- CAMBIO: Si viene de un grado específico, mostrar información fija -->
                    <?php if ($grado_seleccionado): ?>
                        <div class="col-md-3 mb-3">
                            <label class="form-label">Grado *</label>
                            <div class="form-control bg-light">
                                <strong><?php echo htmlspecialchars($grado_seleccionado['nombre']); ?></strong>
                            </div>
                            <input type="hidden" name="grado_id" value="<?php echo $grado_seleccionado['id']; ?>">
                            <div class="form-text">Grado pre-seleccionado</div>
                        </div>
                    <?php else: ?>
                        <!-- Si no viene de un grado específico, mostrar select normal -->
                        <div class="col-md-3 mb-3">
                            <label for="grado_id" class="form-label">Grado *</label>
                            <select class="form-select" id="grado_id" name="grado_id" required>
                                <option value="">Seleccionar grado...</option>
                                <?php foreach ($grados as $grado): ?>
                                    <option value="<?php echo $grado['id']; ?>" 
                                        <?php echo ($_POST['grado_id'] ?? '') == $grado['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($grado['nombre']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    <?php endif; ?>
                    
                    <div class="col-md-2 mb-3">
                        <label for="letra" class="form-label">Letra *</label>
                        <select class="form-select" id="letra" name="letra" required>
                            <option value="">Letra...</option>
                            <?php 
                            $letras = range('A', 'Z');
                            foreach ($letras as $letra): 
                            ?>
                                <option value="<?php echo $letra; ?>" 
                                    <?php echo ($_POST['letra'] ?? '') == $letra ? 'selected' : ''; ?>>
                                    <?php echo $letra; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <!-- CAMBIO: Año escolar preseleccionado automáticamente -->
                    <div class="col-md-4 mb-3">
                        <label for="año_escolar_id" class="form-label">Año Escolar *</label>
                        <?php if ($año_escolar_activo): ?>
                            <div class="form-control bg-success text-white">
                                <strong><?php echo htmlspecialchars($año_escolar_activo['nombre']); ?></strong>
                                <small class="d-block">Año escolar activo</small>
                            </div>
                            <input type="hidden" name="año_escolar_id" value="<?php echo $año_escolar_activo['id']; ?>">
                            <div class="form-text">Año escolar activo preseleccionado</div>
                        <?php else: ?>
                            <select class="form-select" id="año_escolar_id" name="año_escolar_id" required>
                                <option value="">Seleccionar año escolar...</option>
                                <?php foreach ($años_escolares as $año): ?>
                                    <option value="<?php echo $año['id']; ?>" 
                                        <?php echo ($_POST['año_escolar_id'] ?? '') == $año['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($año['nombre']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <div class="form-text text-warning">
                                <i class="fas fa-exclamation-triangle me-1"></i>
                                No hay año escolar activo configurado
                            </div>
                        <?php endif; ?>
                    </div>
                    
                    <div class="col-md-3 mb-3">
                        <label for="capacidad" class="form-label">Capacidad Máxima</label>
                        <select class="form-select" id="capacidad" name="capacidad">
                            <option value="">Sin límite específico</option>
                            <?php 
                            $capacidades = [20, 25, 30, 35];
                            foreach ($capacidades as $cap): 
                            ?>
                                <option value="<?php echo $cap; ?>" 
                                    <?php echo ($_POST['capacidad'] ?? '') == $cap ? 'selected' : ''; ?>>
                                    Máximo <?php echo $cap; ?> estudiantes
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <div class="form-text">Recomendado: 25-30 estudiantes</div>
                    </div>
                </div>
                
                <!-- SECCIÓN MEJORADA DE SELECCIÓN DE PROFESOR -->
                <div class="card mb-4">
                    <div class="card-header bg-warning">
                        <h6 class="card-title mb-0 text-dark">
                            <i class="fas fa-search me-2"></i>Selección del Profesor Titular *
                        </h6>
                    </div>
                    <div class="card-body">
                        <!-- Filtros de búsqueda -->
                        <div class="row mb-3">
                            <div class="col-md-6">
                                <label for="filtro-busqueda" class="form-label">Buscar Profesor</label>
                                <div class="input-group">
                                    <input type="text" class="form-control" id="filtro-busqueda" 
                                           placeholder="Buscar por nombre, apellido o cédula...">
                                    <button type="button" class="btn btn-outline-secondary" onclick="limpiarBusqueda()">
                                        <i class="fas fa-times"></i>
                                    </button>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <label for="filtro-especialidad" class="form-label">Filtrar por Especialidad</label>
                                <select class="form-select" id="filtro-especialidad">
                                    <option value="">Todas las especialidades</option>
                                    <?php foreach ($especialidades as $especialidad): ?>
                                        <option value="<?php echo htmlspecialchars($especialidad); ?>">
                                            <?php echo htmlspecialchars($especialidad); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        
                        <!-- Contador de resultados -->
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <small class="text-muted">
                                <span id="contador-profesores"><?php echo count($profesores); ?></span> profesores disponibles
                            </small>
                            <button type="button" class="btn btn-sm btn-outline-primary" onclick="mostrarTodos()">
                                <i class="fas fa-eye me-1"></i>Mostrar todos
                            </button>
                        </div>
                        
                        <!-- Lista de profesores con filtro -->
                        <div class="mb-3">
                            <label class="form-label">Seleccionar Profesor Titular *</label>
                            <div style="max-height: 200px; overflow-y: auto; border: 1px solid #dee2e6; border-radius: 0.375rem; padding: 10px;">
                                <?php if (count($profesores) > 0): ?>
                                    <?php foreach ($profesores as $profesor): ?>
                                        <div class="form-check profesor-item mb-2" 
                                             data-nombre="<?php echo htmlspecialchars($profesor['nombre'] . ' ' . $profesor['apellido']); ?>"
                                             data-cedula="<?php echo htmlspecialchars($profesor['cedula'] ?? ''); ?>"
                                             data-especialidad="<?php echo htmlspecialchars($profesor['especialidad'] ?? ''); ?>">
                                            <input class="form-check-input" type="radio" name="profesor_id" 
                                                   id="profesor_<?php echo $profesor['id']; ?>" 
                                                   value="<?php echo $profesor['id']; ?>"
                                                   <?php echo ($_POST['profesor_id'] ?? '') == $profesor['id'] ? 'checked' : ''; ?>>
                                            <label class="form-check-label w-100" for="profesor_<?php echo $profesor['id']; ?>">
                                                <div class="d-flex justify-content-between align-items-center">
                                                    <span>
                                                        <strong><?php echo htmlspecialchars($profesor['nombre'] . ' ' . $profesor['apellido']); ?></strong>
                                                        <?php if ($profesor['cedula']): ?>
                                                            <small class="text-muted">(<?php echo htmlspecialchars($profesor['cedula']); ?>)</small>
                                                        <?php endif; ?>
                                                    </span>
                                                    <?php if ($profesor['especialidad']): ?>
                                                        <span class="badge bg-info"><?php echo htmlspecialchars($profesor['especialidad']); ?></span>
                                                    <?php endif; ?>
                                                </div>
                                            </label>
                                        </div>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <div class="text-center text-muted py-3">
                                        <i class="fas fa-users-slash fa-2x mb-2"></i>
                                        <p>No hay profesores activos disponibles</p>
                                        <a href="../profesores/agregar.php" class="btn btn-sm btn-outline-primary">
                                            <i class="fas fa-plus me-1"></i>Agregar Profesor
                                        </a>
                                    </div>
                                <?php endif; ?>
                            </div>
                            <div class="form-text">
                                <i class="fas fa-lightbulb me-1"></i>
                                El profesor seleccionado será asignado a TODAS las áreas de aprendizaje de esta sección
                            </div>
                        </div>
                        
                        <!-- Profesor seleccionado -->
                        <div class="alert alert-success" id="profesor-seleccionado" style="display: none;">
                            <strong><i class="fas fa-check-circle me-2"></i>Profesor seleccionado:</strong>
                            <span id="nombre-profesor-seleccionado"></span>
                        </div>
                    </div>
                </div>
                
                <div class="row">
                    <div class="col-md-12 mb-3">
                        <label class="form-label">Vista Previa de la Sección</label>
                        <div class="form-control" style="background-color: #f8f9fa; font-weight: bold;">
                            <span id="vista-previa-nombre" class="text-muted">
                                Nombre de la sección aparecerá aquí...
                            </span>
                        </div>
                    </div>
                </div>
                
                <!-- Campos ocultos fijos para primaria -->
                <input type="hidden" name="nivel" value="primaria">
                <input type="hidden" name="tipo_grado" value="grado">
                
                <!-- Información de áreas de aprendizaje -->
                <?php if (count($areas_aprendizaje) > 0): ?>
                <div class="card mt-4">
                    <div class="card-header bg-light">
                        <h6 class="card-title mb-0">
                            <i class="fas fa-book me-2"></i>Áreas de Aprendizaje Asignadas Automáticamente
                        </h6>
                    </div>
                    <div class="card-body">
                        <div class="alert alert-success">
                            <small>
                                <i class="fas fa-check-circle me-1"></i>
                                <strong>Configuración Automática:</strong> El profesor titular será asignado a TODAS estas áreas de aprendizaje.
                            </small>
                        </div>
                        
                        <div class="row">
                            <?php foreach ($areas_aprendizaje as $area): ?>
                            <div class="col-md-4 mb-2">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" checked disabled>
                                    <label class="form-check-label">
                                        <strong><?php echo htmlspecialchars($area['nombre']); ?></strong>
                                        <?php if ($area['codigo']): ?>
                                            <small class="text-muted">(<?php echo htmlspecialchars($area['codigo']); ?>)</small>
                                        <?php endif; ?>
                                    </label>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
                <?php else: ?>
                <div class="alert alert-warning mt-4">
                    <i class="fas fa-exclamation-triangle me-2"></i>
                    <strong>Advertencia:</strong> No hay áreas de aprendizaje configuradas para primaria.
                    <a href="../areas_aprendizaje/agregar.php" class="alert-link">Configurar áreas de aprendizaje</a>
                </div>
                <?php endif; ?>
                
                <div class="d-grid gap-2 d-md-flex justify-content-md-end mt-4">
                    <button type="reset" class="btn btn-outline-secondary me-md-2" onclick="limpiarFormulario()">
                        <i class="fas fa-undo me-2"></i>Limpiar
                    </button>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save me-2"></i>Guardar Sección y Horario
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
// Datos de profesores para filtrado
const profesoresData = [
    <?php foreach ($profesores as $profesor): ?>
    {
        id: <?php echo $profesor['id']; ?>,
        nombre: "<?php echo htmlspecialchars($profesor['nombre'] . ' ' . $profesor['apellido']); ?>",
        cedula: "<?php echo htmlspecialchars($profesor['cedula'] ?? ''); ?>",
        especialidad: "<?php echo htmlspecialchars($profesor['especialidad'] ?? ''); ?>"
    },
    <?php endforeach; ?>
];

// Datos de grados para vista previa
const gradosData = {
    <?php foreach ($grados as $grado): ?>
    "<?php echo $grado['id']; ?>": "<?php echo htmlspecialchars($grado['nombre']); ?>",
    <?php endforeach; ?>
};

// Función para actualizar vista previa
function actualizarVistaPrevia() {
    <?php if ($grado_seleccionado): ?>
        // Si viene de un grado específico, usar ese grado
        const gradoId = <?php echo $grado_seleccionado['id']; ?>;
    <?php else: ?>
        // Si no, usar el select
        const gradoId = document.getElementById('grado_id').value;
    <?php endif; ?>
    
    const letra = document.getElementById('letra').value;
    const vistaPrevia = document.getElementById('vista-previa-nombre');
    
    if (gradoId && letra) {
        const nombreGrado = gradosData[gradoId] || '';
        vistaPrevia.textContent = `${nombreGrado} ${letra}`;
        vistaPrevia.className = 'text-success';
    } else {
        vistaPrevia.textContent = 'Nombre de la sección aparecerá aquí...';
        vistaPrevia.className = 'text-muted';
    }
}

// Función para filtrar profesores
function filtrarProfesores() {
    const busqueda = document.getElementById('filtro-busqueda').value.toLowerCase();
    const especialidad = document.getElementById('filtro-especialidad').value;
    const items = document.querySelectorAll('.profesor-item');
    let contador = 0;
    
    items.forEach(item => {
        const nombre = item.getAttribute('data-nombre').toLowerCase();
        const cedula = item.getAttribute('data-cedula').toLowerCase();
        const esp = item.getAttribute('data-especialidad');
        
        const coincideBusqueda = nombre.includes(busqueda) || cedula.includes(busqueda);
        const coincideEspecialidad = !especialidad || esp === especialidad;
        
        if (coincideBusqueda && coincideEspecialidad) {
            item.style.display = 'block';
            contador++;
        } else {
            item.style.display = 'none';
        }
    });
    
    document.getElementById('contador-profesores').textContent = contador;
}

// Función para limpiar búsqueda
function limpiarBusqueda() {
    document.getElementById('filtro-busqueda').value = '';
    document.getElementById('filtro-especialidad').value = '';
    filtrarProfesores();
}

// Función para mostrar todos
function mostrarTodos() {
    document.getElementById('filtro-busqueda').value = '';
    document.getElementById('filtro-especialidad').value = '';
    filtrarProfesores();
}

// Función para actualizar profesor seleccionado
function actualizarProfesorSeleccionado() {
    const profesorSeleccionado = document.querySelector('input[name="profesor_id"]:checked');
    const divSeleccionado = document.getElementById('profesor-seleccionado');
    const nombreSpan = document.getElementById('nombre-profesor-seleccionado');
    
    if (profesorSeleccionado) {
        const profesorId = profesorSeleccionado.value;
        const profesor = profesoresData.find(p => p.id == profesorId);
        
        if (profesor) {
            nombreSpan.textContent = profesor.nombre;
            if (profesor.especialidad) {
                nombreSpan.innerHTML += ` <span class="badge bg-info">${profesor.especialidad}</span>`;
            }
            if (profesor.cedula) {
                nombreSpan.innerHTML += ` <small class="text-muted">(${profesor.cedula})</small>`;
            }
            divSeleccionado.style.display = 'block';
        }
    } else {
        divSeleccionado.style.display = 'none';
    }
}

function limpiarFormulario() {
    document.getElementById('vista-previa-nombre').textContent = 'Nombre de la sección aparecerá aquí...';
    document.getElementById('vista-previa-nombre').className = 'text-muted';
    document.getElementById('profesor-seleccionado').style.display = 'none';
}

// Event listeners
document.addEventListener('DOMContentLoaded', function() {
    // Filtros
    document.getElementById('filtro-busqueda').addEventListener('input', filtrarProfesores);
    document.getElementById('filtro-especialidad').addEventListener('change', filtrarProfesores);
    
    // Letras
    document.getElementById('letra').addEventListener('change', actualizarVistaPrevia);
    
    <?php if (!$grado_seleccionado): ?>
        // Solo agregar evento al select de grado si no viene pre-seleccionado
        document.getElementById('grado_id').addEventListener('change', actualizarVistaPrevia);
    <?php endif; ?>
    
    // Profesores seleccionados
    document.querySelectorAll('input[name="profesor_id"]').forEach(radio => {
        radio.addEventListener('change', actualizarProfesorSeleccionado);
    });
    
    // Inicializar
    actualizarVistaPrevia();
    actualizarProfesorSeleccionado();
});

// Validación adicional antes de enviar
document.getElementById('form-seccion').addEventListener('submit', function(e) {
    <?php if ($grado_seleccionado): ?>
        const gradoId = <?php echo $grado_seleccionado['id']; ?>;
    <?php else: ?>
        const gradoId = document.getElementById('grado_id').value;
    <?php endif; ?>
    
    const letra = document.getElementById('letra').value;
    const añoEscolar = document.getElementById('año_escolar_id').value;
    const profesor = document.querySelector('input[name="profesor_id"]:checked');
    
    console.log("Validando formulario:");
    console.log("Grado ID:", gradoId);
    console.log("Letra:", letra);
    console.log("Año escolar:", añoEscolar);
    console.log("Profesor:", profesor ? profesor.value : "NO SELECCIONADO");
    
    let errores = [];
    
    if (!gradoId) errores.push("• Seleccione el grado");
    if (!letra) errores.push("• Seleccione la letra de la sección");
    if (!añoEscolar) errores.push("• Seleccione el año escolar");
    if (!profesor) errores.push("• Seleccione un profesor titular");
    
    if (errores.length > 0) {
        e.preventDefault();
        alert('Por favor complete los siguientes campos obligatorios:\n\n' + errores.join('\n'));
        return false;
    }
    
    <?php if (count($areas_aprendizaje) === 0): ?>
    if (!confirm('ADVERTENCIA: No hay áreas de aprendizaje configuradas para primaria. ¿Desea continuar de todos modos?')) {
        e.preventDefault();
        return false;
    }
    <?php endif; ?>
    
    // Mostrar confirmación final
    const nombreGrado = gradosData[gradoId] || '';
    const nombreSeccion = nombreGrado + ' ' + letra;
    const profesorNombre = profesor ? document.querySelector('label[for="profesor_' + profesor.value + '"]').textContent.trim() : '';
    
    if (!confirm(`¿Está seguro de crear la sección "${nombreSeccion}"?\n\nProfesor: ${profesorNombre}`)) {
        e.preventDefault();
        return false;
    }
});
</script>

<?php include '../../includes/footer.php'; ?>
