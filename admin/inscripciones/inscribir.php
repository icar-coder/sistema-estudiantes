<?php
require_once '../../includes/auth.php';
require_once '../../includes/database.php';
checkAuth();
checkRole(['admin']);

$mensaje = '';
$tipo_mensaje = '';

// Obtener estudiante_id si viene por GET (desde gestiÃ³n)
$estudiante_pre_seleccionado = null;
$estudiante_id_from_get = $_GET['estudiante_id'] ?? null;

if ($estudiante_id_from_get) {
    try {
        $stmt = $pdo->prepare("SELECT * FROM estudiantes WHERE id = ?");
        $stmt->execute([$estudiante_id_from_get]);
        $estudiante_pre_seleccionado = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        // Si hay error, simplemente no pre-selecciona
    }
}

// Si viene estudiante_id por GET, usarlo como valor por defecto en el POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($_POST['estudiante_id']) && $estudiante_id_from_get) {
    $_POST['estudiante_id'] = $estudiante_id_from_get;
}

// Obtener años escolares activos
try {
    $stmt = $pdo->query("SELECT id, nombre FROM años_escolares WHERE estado = 'activo' ORDER BY nombre DESC");
    $años_escolares = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $años_escolares = [];
}

// Obtener estudiantes sin inscripciÃ³n activa en el año escolar seleccionado
$estudiantes_sin_inscripcion = [];
if (isset($_GET['año_escolar_id']) && !empty($_GET['año_escolar_id'])) {
    $año_escolar_id = $_GET['año_escolar_id'];
    try {
        $stmt = $pdo->prepare("
            SELECT e.* 
            FROM estudiantes e
            WHERE e.id NOT IN (
                SELECT i.estudiante_id 
                FROM inscripciones i 
                WHERE i.año_escolar_id = ? AND i.status = 'activo'
            )
            ORDER BY e.apellido, e.nombre
        ");
        $stmt->execute([$año_escolar_id]);
        $estudiantes_sin_inscripcion = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $estudiantes_sin_inscripcion = [];
    }
}

// Obtener Ã¡reas de aprendizaje para primaria
try {
    $stmt = $pdo->query("
        SELECT id, nombre, codigo 
        FROM areas_aprendizaje 
        WHERE nivel = 'primaria' 
        AND estado = 'activa' 
        ORDER BY nombre
    ");
    $areas_aprendizaje = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $areas_aprendizaje = [];
}

// Procesar inscripciÃ³n
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $estudiante_id = $_POST['estudiante_id'];
    $seccion_id = $_POST['seccion_id'];
    $año_escolar_id = $_POST['año_escolar_id'];
    
    if (!empty($estudiante_id) && !empty($seccion_id) && !empty($año_escolar_id)) {
        try {
            // Verificar si ya existe una inscripciÃ³n activa
            $stmt = $pdo->prepare("SELECT id FROM inscripciones WHERE estudiante_id = ? AND año_escolar_id = ? AND status = 'activo'");
            $stmt->execute([$estudiante_id, $año_escolar_id]);
            
            if ($stmt->fetch()) {
                $mensaje = "El estudiante ya tiene una inscripciÃ³n activa en este año escolar";
                $tipo_mensaje = "warning";
            } else {
                // Verificar capacidad de la secciÃ³n
                $stmt = $pdo->prepare("
                    SELECT s.capacidad, COUNT(i.id) as inscritos 
                    FROM secciones s 
                    LEFT JOIN inscripciones i ON s.id = i.seccion_id AND i.status = 'activo'
                    WHERE s.id = ?
                    GROUP BY s.id
                ");
                $stmt->execute([$seccion_id]);
                $seccion_info = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($seccion_info && $seccion_info['capacidad'] && $seccion_info['inscritos'] >= $seccion_info['capacidad']) {
                    $mensaje = "La secciÃ³n seleccionada ha alcanzado su capacidad mÃ¡xima";
                    $tipo_mensaje = "warning";
                } else {
                    // Iniciar transacciÃ³n
                    $pdo->beginTransaction();
                    
                    try {
                        // 1. Crear inscripciÃ³n principal
                        $stmt = $pdo->prepare("INSERT INTO inscripciones (estudiante_id, seccion_id, año_escolar_id, fecha_inscripcion) VALUES (?, ?, ?, CURDATE())");
                        $stmt->execute([$estudiante_id, $seccion_id, $año_escolar_id]);
                        $inscripcion_id = $pdo->lastInsertId();
                        
                        // 2. Obtener informaciÃ³n del estudiante y secciÃ³n para el mensaje
                        $stmt = $pdo->prepare("
                            SELECT e.nombre, e.apellido, s.nombre as seccion_nombre 
                            FROM estudiantes e 
                            JOIN secciones s ON s.id = ?
                            WHERE e.id = ?
                        ");
                        $stmt->execute([$seccion_id, $estudiante_id]);
                        $info_inscripcion = $stmt->fetch(PDO::FETCH_ASSOC);
                        
                        $mensaje = "âœ… Estudiante <strong>{$info_inscripcion['nombre']} {$info_inscripcion['apellido']}</strong> inscrito correctamente en la secciÃ³n <strong>{$info_inscripcion['seccion_nombre']}</strong>";
                        
                        if (count($areas_aprendizaje) > 0) {
                            $mensaje .= " con " . count($areas_aprendizaje) . " Ã¡reas de aprendizaje";
                        }
                        
                        // Confirmar transacciÃ³n
                        $pdo->commit();
                        $tipo_mensaje = "success";
                        
                        // Limpiar formulario
                        $_POST = [];
                        
                    } catch (Exception $e) {
                        $pdo->rollBack();
                        throw $e;
                    }
                }
            }
        } catch (PDOException $e) {
            $mensaje = "âŒ Error al inscribir estudiante: " . $e->getMessage();
            $tipo_mensaje = "danger";
        }
    } else {
        $mensaje = "âš ï¸ Por favor complete todos los campos obligatorios";
        $tipo_mensaje = "warning";
    }
}
?>

<?php include '../../includes/header.php'; ?>

<div class="content-wrapper" style="padding: 20px;">
    <?php include '../../includes/sidebar_admin.php'; ?>
    
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2>Inscribir Estudiante - PRIMARIA</h2>
        <a href="gestionar.php" class="btn btn-outline-secondary">
            <i class="fas fa-arrow-left me-2"></i>Volver a Inscripciones
        </a>
    </div>

    <?php if ($mensaje): ?>
        <div class="alert alert-<?php echo $tipo_mensaje; ?> alert-dismissible fade show" role="alert">
            <?php echo $mensaje; ?>
            
            <?php if ($tipo_mensaje === "success" && isset($inscripcion_id)): ?>
                <div class="mt-3">
                    <a href="generar_constancia.php?id=<?php echo $inscripcion_id; ?>" 
                       class="btn btn-sm btn-success me-2" target="_blank">
                        <i class="fas fa-file-pdf me-1"></i>Generar Constancia de InscripciÃ³n
                    </a>
                    <a href="gestionar.php" class="btn btn-sm btn-outline-secondary">
                        <i class="fas fa-list me-1"></i>Ver Todas las Inscripciones
                    </a>
                </div>
            <?php endif; ?>
            
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <div class="card">
        <div class="card-header bg-primary text-white">
            <h5 class="card-title mb-0">
                <i class="fas fa-user-plus me-2"></i>Nueva InscripciÃ³n - Sistema Primaria
            </h5>
        </div>
        <div class="card-body">
            <!-- InformaciÃ³n del sistema -->
            <div class="alert alert-info mb-4">
                <h6><i class="fas fa-info-circle me-2"></i>Sistema de Primaria</h6>
                <ul class="mb-0">
                    <li><strong>Profesor Titular:</strong> Cada secciÃ³n tiene un profesor que imparte TODAS las Ã¡reas</li>
                    <li><strong>AsignaciÃ³n AutomÃ¡tica:</strong> El estudiante recibe automÃ¡ticamente todas las Ã¡reas de aprendizaje</li>
                    <li><strong>EvaluaciÃ³n por Proyectos:</strong> Sistema basado en proyectos por lapso con evaluaciÃ³n literal (A-E)</li>
                </ul>
            </div>

            <form method="GET" action="" class="mb-4">
                <!-- Mantener el estudiante_id en el formulario GET -->
                <?php if ($estudiante_id_from_get): ?>
                    <input type="hidden" name="estudiante_id" value="<?php echo htmlspecialchars($estudiante_id_from_get); ?>">
                <?php endif; ?>
                
                <div class="row">
                    <div class="col-md-8">
                        <label for="año_escolar_id" class="form-label">Seleccionar Año Escolar *</label>
                        <select class="form-select" id="año_escolar_id" name="año_escolar_id" required onchange="this.form.submit()">
                            <option value="">Seleccionar año escolar...</option>
                            <?php foreach ($años_escolares as $año): ?>
                                <option value="<?php echo $año['id']; ?>" 
                                    <?php echo ($_GET['año_escolar_id'] ?? '') == $año['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($año['nombre']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-4 d-flex align-items-end">
                        <?php if (isset($_GET['año_escolar_id']) && !empty($_GET['año_escolar_id'])): ?>
                            <a href="inscribir.php<?php echo $estudiante_id_from_get ? '?estudiante_id=' . $estudiante_id_from_get : ''; ?>" class="btn btn-outline-secondary">
                                <i class="fas fa-times me-1"></i>Cambiar Año
                            </a>
                        <?php endif; ?>
                    </div>
                </div>
            </form>

            <?php if (isset($_GET['año_escolar_id']) && !empty($_GET['año_escolar_id'])): ?>
                <form method="POST" action="" id="form-inscripcion">
                    <input type="hidden" name="año_escolar_id" value="<?php echo htmlspecialchars($_GET['año_escolar_id']); ?>">
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="estudiante_id" class="form-label">Seleccionar Estudiante *</label>
                            <select class="form-select" id="estudiante_id" name="estudiante_id" required>
                                <option value="">Seleccionar estudiante...</option>
                                <?php foreach ($estudiantes_sin_inscripcion as $estudiante): ?>
                                    <option value="<?php echo $estudiante['id']; ?>" 
                                        <?php 
                                            // Pre-seleccionar si viene de gestiÃ³n
                                            if (($estudiante_pre_seleccionado && $estudiante['id'] == $estudiante_pre_seleccionado['id']) || 
                                                ($_POST['estudiante_id'] ?? '') == $estudiante['id']) {
                                                echo 'selected';
                                            }
                                        ?>>
                                        <?php echo htmlspecialchars($estudiante['nombre'] . ' ' . $estudiante['apellido']); ?>
                                        <?php if ($estudiante['cedula_escolar']): ?>
                                            (<?php echo htmlspecialchars($estudiante['cedula_escolar']); ?>)
                                        <?php endif; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <div class="form-text">
                                <i class="fas fa-users me-1"></i>
                                <?php echo count($estudiantes_sin_inscripcion); ?> estudiantes disponibles para inscripciÃ³n
                                <?php if ($estudiante_pre_seleccionado): ?>
                                    <span class="text-success ms-2">
                                        <i class="fas fa-check"></i> Estudiante pre-seleccionado desde gestiÃ³n
                                    </span>
                                <?php endif; ?>
                            </div>
                        </div>
                        
                        <div class="col-md-6 mb-3">
                            <label for="seccion_id" class="form-label">Seleccionar SecciÃ³n *</label>
                            <select class="form-select" id="seccion_id" name="seccion_id" required onchange="actualizarInfoSeccion()">
                                <option value="">Seleccionar secciÃ³n...</option>
                            </select>
                            <div class="form-text" id="info-capacidad">
                                Seleccione una secciÃ³n para ver la capacidad disponible
                            </div>
                        </div>
                    </div>

                    <!-- InformaciÃ³n de la secciÃ³n seleccionada -->
                    <div id="info-seccion" class="card mt-3" style="display: none;">
                        <div class="card-header bg-light">
                            <h6 class="card-title mb-0">
                                <i class="fas fa-chalkboard-teacher me-2"></i>InformaciÃ³n de la SecciÃ³n
                            </h6>
                        </div>
                        <div class="card-body">
                            <div id="detalles-seccion"></div>
                        </div>
                    </div>

                    <!-- Ãreas de aprendizaje que se asignarÃ¡n -->
                    <?php if (count($areas_aprendizaje) > 0): ?>
                    <div class="card mt-4">
                        <div class="card-header bg-success text-white">
                            <h6 class="card-title mb-0">
                                <i class="fas fa-book me-2"></i>Ãreas de Aprendizaje que se AsignarÃ¡n
                            </h6>
                        </div>
                        <div class="card-body">
                            <div class="alert alert-warning">
                                <i class="fas fa-exclamation-triangle me-2"></i>
                                <strong>Importante:</strong> El estudiante serÃ¡ inscrito automÃ¡ticamente en TODAS las Ã¡reas de aprendizaje bÃ¡sicas de primaria.
                            </div>
                            <div class="row">
                                <?php foreach ($areas_aprendizaje as $area): ?>
                                    <div class="col-md-4 mb-2">
                                        <div class="form-check">
                                            <input class="form-check-input" type="checkbox" checked disabled>
                                            <label class="form-check-label">
                                                <strong><?php echo htmlspecialchars($area['nombre']); ?></strong>
                                                <small class="text-muted">(<?php echo htmlspecialchars($area['codigo']); ?>)</small>
                                            </label>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                    <?php else: ?>
                    <div class="alert alert-danger mt-4">
                        <i class="fas fa-exclamation-triangle me-2"></i>
                        <strong>Advertencia:</strong> No hay Ã¡reas de aprendizaje configuradas para primaria. 
                        <a href="../areas_aprendizaje/agregar.php" class="alert-link">Configurar Ã¡reas primero</a>
                    </div>
                    <?php endif; ?>

                    <div class="d-grid gap-2 d-md-flex justify-content-md-end mt-4">
                        <a href="../estudiantes/agregar.php" class="btn btn-outline-info me-md-2">
                            <i class="fas fa-user-plus me-2"></i>Agregar Nuevo Estudiante
                        </a>
                        <button type="submit" class="btn btn-primary" id="btn-inscribir" <?php echo count($areas_aprendizaje) === 0 ? 'disabled' : ''; ?>>
                            <i class="fas fa-save me-2"></i>Inscribir Estudiante
                        </button>
                    </div>
                </form>
            <?php else: ?>
                <div class="text-center py-4">
                    <i class="fas fa-calendar-alt fa-3x text-muted mb-3"></i>
                    <h5>Seleccione un año escolar</h5>
                    <p class="text-muted">Primero debe seleccionar el año escolar para continuar con la inscripciÃ³n.</p>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <?php if (isset($_GET['año_escolar_id']) && empty($estudiantes_sin_inscripcion)): ?>
        <div class="card mt-4">
            <div class="card-body text-center">
                <i class="fas fa-users fa-3x text-muted mb-3"></i>
                <h5>No hay estudiantes disponibles</h5>
                <p class="text-muted">Todos los estudiantes ya estÃ¡n inscritos en este año escolar o no hay estudiantes registrados.</p>
                <a href="../estudiantes/agregar.php" class="btn btn-primary me-2">
                    <i class="fas fa-user-plus me-2"></i>Agregar Nuevo Estudiante
                </a>
                <a href="../estudiantes/gestionar.php" class="btn btn-outline-secondary">
                    <i class="fas fa-users me-2"></i>Gestionar Estudiantes
                </a>
            </div>
        </div>
    <?php endif; ?>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const añoEscolarId = <?php echo isset($_GET['año_escolar_id']) ? $_GET['año_escolar_id'] : 'null'; ?>;
    
    if (añoEscolarId) {
        cargarSecciones(añoEscolarId);
    }
});

function cargarSecciones(añoEscolarId) {
    const seccionSelect = document.getElementById('seccion_id');
    const infoCapacidad = document.getElementById('info-capacidad');
    
    seccionSelect.innerHTML = '<option value="">Cargando secciones...</option>';
    seccionSelect.disabled = true;
    
    fetch(`../../includes/ajax_secciones_completo.php?año_escolar_id=${añoEscolarId}`)
        .then(response => {
            if (!response.ok) {
                throw new Error('Error al cargar secciones');
            }
            return response.json();
        })
        .then(data => {
            const secciones = Array.isArray(data) ? data : [];

            seccionSelect.innerHTML = '<option value="">Seleccionar secciÃ³n...</option>';
            
            if (!secciones.length) {
                seccionSelect.innerHTML = '<option value="">No hay secciones disponibles</option>';
                return;
            }
            
            secciones.forEach(seccion => {
                const inscritos = parseInt(seccion.inscritos) || 0;
                const capacidad = seccion.capacidad ? parseInt(seccion.capacidad) : null;
                const ocupacion = capacidad > 0 ? Math.round((inscritos / capacidad) * 100) : 0;
                
                seccionSelect.innerHTML += `
                    <option value="${seccion.id}" 
                            data-capacidad="${capacidad || ''}" 
                            data-inscritos="${inscritos}"
                            data-grado="${seccion.nombre}">
                        ${seccion.nombre} (${inscritos}/${capacidad || 'âˆž'} - ${ocupacion}% ocupado)
                    </option>
                `;
            });
            
            seccionSelect.disabled = false;
            
            <?php if (isset($_POST['seccion_id'])): ?>
                seccionSelect.value = '<?php echo $_POST['seccion_id']; ?>';
                actualizarInfoSeccion();
            <?php endif; ?>
        })
        .catch(error => {
            console.error('Error:', error);
            seccionSelect.innerHTML = '<option value="">Error al cargar secciones</option>';
            infoCapacidad.innerHTML = `<span class="text-danger">${error.message}</span>`;
        });
}

function actualizarInfoSeccion() {
    const seccionSelect = document.getElementById('seccion_id');
    const selectedOption = seccionSelect.options[seccionSelect.selectedIndex];
    const infoCapacidad = document.getElementById('info-capacidad');
    const infoSeccion = document.getElementById('info-seccion');
    const detallesSeccion = document.getElementById('detalles-seccion');
    
    if (selectedOption.value) {
        const capacidad = selectedOption.getAttribute('data-capacidad');
        const inscritos = selectedOption.getAttribute('data-inscritos');
        const disponible = capacidad ? capacidad - inscritos : 'ilimitados';
        const profesor = 'Sin asignar';
        const grado = selectedOption.getAttribute('data-grado');
        const ocupacion = capacidad ? Math.round((inscritos / capacidad) * 100) : 0;
        
        infoCapacidad.innerHTML = `
            <strong>Capacidad:</strong> ${capacidad || 'Sin lÃ­mite'} | 
            <strong>Inscritos:</strong> ${inscritos} | 
            <strong>Disponible:</strong> ${disponible}
        `;
        
        // Mostrar informaciÃ³n detallada de la secciÃ³n
        detallesSeccion.innerHTML = `
            <div class="row">
                <div class="col-md-4">
                    <strong>Grado:</strong> ${grado}
                </div>
                <div class="col-md-4">
                    <strong>Profesor Titular:</strong> ${profesor}
                </div>
                <div class="col-md-4">
                    <strong>OcupaciÃ³n:</strong> 
                    <div class="progress mt-1" style="height: 8px;">
                        <div class="progress-bar ${ocupacion >= 90 ? 'bg-danger' : (ocupacion >= 75 ? 'bg-warning' : 'bg-success')}" 
                             style="width: ${Math.min(ocupacion, 100)}%"
                             title="${ocupacion}% ocupado">
                        </div>
                    </div>
                    <small class="text-muted">${ocupacion}% ocupado</small>
                </div>
            </div>
            ${capacidad && ocupacion >= 90 ? `
            <div class="alert alert-warning mt-2 mb-0">
                <i class="fas fa-exclamation-triangle me-1"></i>
                <strong>Alerta:</strong> Esta secciÃ³n estÃ¡ casi llena (${ocupacion}% ocupado)
            </div>
            ` : ''}
        `;
        
        infoSeccion.style.display = 'block';
    } else {
        infoCapacidad.innerHTML = 'Seleccione una secciÃ³n para ver la capacidad disponible';
        infoSeccion.style.display = 'none';
    }
}

// ValidaciÃ³n del formulario
document.getElementById('form-inscripcion').addEventListener('submit', function(e) {
    const estudianteId = document.getElementById('estudiante_id').value;
    const seccionId = document.getElementById('seccion_id').value;
    
    if (!estudianteId || !seccionId) {
        e.preventDefault();
        alert('âš ï¸ Por favor complete todos los campos obligatorios');
        return false;
    }
    
    <?php if (count($areas_aprendizaje) === 0): ?>
    if (!confirm('âš ï¸ ADVERTENCIA: No hay Ã¡reas de aprendizaje configuradas. Â¿Desea continuar de todos modos?')) {
        e.preventDefault();
        return false;
    }
    <?php endif; ?>
});
</script>

<?php include '../../includes/footer.php'; ?>
