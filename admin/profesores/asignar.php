<?php
require_once '../../includes/auth.php';
require_once '../../includes/database.php';
checkAuth();
checkRole(['admin']);

if (!isset($_GET['id'])) {
    header('Location: gestionar.php');
    exit();
}

$profesor_id = $_GET['id'];
$mensaje = '';
$tipo_mensaje = '';

// Obtener datos del profesor
try {
    $stmt = $pdo->prepare("SELECT * FROM profesores WHERE id = ?");
    $stmt->execute([$profesor_id]);
    $profesor = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$profesor) {
        header('Location: gestionar.php');
        exit();
    }
} catch (PDOException $e) {
    error_log("Error en " . __FILE__ . ": " . $e->getMessage());
    $_SESSION['error_sistema'] = "OcurriÃ³ un error al procesar la solicitud. El equipo tÃ©cnico fue notificado.";
    header('Location: ' . ($_SERVER['HTTP_REFERER'] ?? app_url()));
    exit;
}

// Obtener años escolares activos
try {
    $stmt = $pdo->query("SELECT id, nombre FROM años_escolares WHERE estado = 'activo' ORDER BY nombre DESC");
    $años_escolares = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $años_escolares = [];
}

// Obtener secciones disponibles (sin profesor asignado o donde este profesor ya es titular)
try {
    $stmt = $pdo->prepare("
        SELECT s.*, ae.nombre as año_escolar_nombre,
               CASE 
                   WHEN s.profesor_id = ? THEN 'actual'
                   WHEN s.profesor_id IS NULL THEN 'disponible' 
                   ELSE 'ocupada'
               END as estado_asignacion
        FROM secciones s
        JOIN años_escolares ae ON s.año_escolar_id = ae.id
        WHERE ae.estado = 'activo'
        AND s.nivel = 'primaria'
        AND (s.profesor_id IS NULL OR s.profesor_id = ?)
        ORDER BY ae.nombre DESC, s.nombre
    ");
    $stmt->execute([$profesor_id, $profesor_id]);
    $secciones = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $secciones = [];
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

// Procesar asignaciÃ³n como profesor titular
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['asignar_titular'])) {
    $seccion_id = $_POST['seccion_id'];
    $año_escolar_id = $_POST['año_escolar_id'];
    
    if (!empty($seccion_id) && !empty($año_escolar_id)) {
        try {
            $pdo->beginTransaction();
            
            // Verificar que la secciÃ³n existe y es de primaria
            $stmt = $pdo->prepare("SELECT id, nivel FROM secciones WHERE id = ? AND año_escolar_id = ?");
            $stmt->execute([$seccion_id, $año_escolar_id]);
            $seccion = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$seccion) {
                throw new Exception("La secciÃ³n no existe o no pertenece al año escolar seleccionado");
            }
            
            if ($seccion['nivel'] !== 'primaria') {
                throw new Exception("Solo se pueden asignar profesores titulares a secciones de primaria");
            }
            
            // Verificar si la secciÃ³n ya tiene profesor titular
            $stmt = $pdo->prepare("SELECT profesor_id, nombre FROM secciones WHERE id = ?");
            $stmt->execute([$seccion_id]);
            $seccion_actual = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($seccion_actual['profesor_id'] && $seccion_actual['profesor_id'] != $profesor_id) {
                // Obtener nombre del profesor actual
                $stmt = $pdo->prepare("SELECT nombre, apellido FROM profesores WHERE id = ?");
                $stmt->execute([$seccion_actual['profesor_id']]);
                $profesor_actual = $stmt->fetch(PDO::FETCH_ASSOC);
                $nombre_profesor_actual = $profesor_actual ? $profesor_actual['nombre'] . ' ' . $profesor_actual['apellido'] : 'Otro profesor';
                
                throw new Exception("Esta secciÃ³n ya tiene como profesor titular a: " . $nombre_profesor_actual);
            }
            
            // Asignar profesor como titular de la secciÃ³n
            $stmt = $pdo->prepare("UPDATE secciones SET profesor_id = ? WHERE id = ?");
            $stmt->execute([$profesor_id, $seccion_id]);
            
            // Crear horarios automÃ¡ticos con todas las Ã¡reas de aprendizaje
            if (count($areas_aprendizaje) > 0) {
                // Horario estÃ¡ndar para primaria (lunes a viernes)
                $dias_semana = ['lunes', 'martes', 'miÃ©rcoles', 'jueves', 'viernes'];
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
                            
                            // Verificar si ya existe este horario
                            $stmt_check = $pdo->prepare("
                                SELECT id FROM horarios_asignaciones 
                                WHERE seccion_id = ? AND año_escolar_id = ? AND dia_semana = ? AND area_id = ?
                            ");
                            $stmt_check->execute([$seccion_id, $año_escolar_id, $dia, $area_id]);
                            
                            if (!$stmt_check->fetch()) {
                                $stmt_insert = $pdo->prepare("
                                    INSERT INTO horarios_asignaciones 
                                    (seccion_id, año_escolar_id, dia_semana, hora_inicio, hora_fin, area_id, profesor_id) 
                                    VALUES (?, ?, ?, ?, ?, ?, ?)
                                ");
                                $stmt_insert->execute([
                                    $seccion_id, $año_escolar_id, $dia,
                                    $horario[0], $horario[1], $area_id, $profesor_id
                                ]);
                            }
                            
                            $area_index++;
                            // Si llegamos al final de las Ã¡reas, reiniciamos
                            if ($area_index >= $total_areas) {
                                $area_index = 0;
                            }
                        }
                    }
                }
            }
            
            $pdo->commit();
            
            // Obtener nombre de la secciÃ³n para el mensaje
            $stmt = $pdo->prepare("SELECT nombre FROM secciones WHERE id = ?");
            $stmt->execute([$seccion_id]);
            $seccion_nombre = $stmt->fetch(PDO::FETCH_ASSOC)['nombre'];
            
            $mensaje = "âœ… Profesor asignado como titular de la secciÃ³n <strong>'$seccion_nombre'</strong> con " . count($areas_aprendizaje) . " Ã¡reas de aprendizaje";
            $tipo_mensaje = "success";
            
        } catch (Exception $e) {
            $pdo->rollBack();
            $mensaje = "âŒ Error al asignar profesor titular: " . $e->getMessage();
            $tipo_mensaje = "danger";
        }
    } else {
        $mensaje = "âš ï¸ Por favor seleccione una secciÃ³n y año escolar";
        $tipo_mensaje = "warning";
    }
}

// Procesar remociÃ³n como profesor titular
if (isset($_GET['remover_titular'])) {
    $seccion_id = $_GET['remover_titular'];
    
    try {
        $pdo->beginTransaction();
        
        // Verificar que el profesor es efectivamente el titular
        $stmt = $pdo->prepare("SELECT profesor_id, nombre FROM secciones WHERE id = ?");
        $stmt->execute([$seccion_id]);
        $seccion = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$seccion || $seccion['profesor_id'] != $profesor_id) {
            throw new Exception("No es posible remover esta asignaciÃ³n");
        }
        
        // Remover profesor como titular
        $stmt = $pdo->prepare("UPDATE secciones SET profesor_id = NULL WHERE id = ?");
        $stmt->execute([$seccion_id]);
        
        $pdo->commit();
        
        $mensaje = "âœ… Profesor removido como titular de la secciÃ³n <strong>'{$seccion['nombre']}'</strong>";
        $tipo_mensaje = "success";
        
    } catch (Exception $e) {
        $pdo->rollBack();
        $mensaje = "âŒ Error al remover profesor titular: " . $e->getMessage();
        $tipo_mensaje = "danger";
    }
}

// Obtener secciones donde el profesor es titular actualmente
try {
    $stmt = $pdo->prepare("
        SELECT s.*, ae.nombre as año_escolar_nombre,
               (SELECT COUNT(*) FROM horarios_asignaciones hs WHERE hs.seccion_id = s.id AND hs.año_escolar_id = s.año_escolar_id) as total_horarios,
               (SELECT COUNT(*) FROM inscripciones i WHERE i.seccion_id = s.id AND i.año_escolar_id = s.año_escolar_id AND i.status = 'activo') as total_estudiantes
        FROM secciones s
        JOIN años_escolares ae ON s.año_escolar_id = ae.id
        WHERE s.profesor_id = ?
        ORDER BY ae.nombre DESC, s.nombre
    ");
    $stmt->execute([$profesor_id]);
    $secciones_titular = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $secciones_titular = [];
}
?>

<?php include '../../includes/header.php'; ?>

<div class="content-wrapper" style="padding: 20px;">
    <?php include '../../includes/sidebar_admin.php'; ?>
    
    <div class="container-fluid">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h2>Asignar Secciones - Profesor Titular PRIMARIA</h2>
            <a href="gestionar.php" class="btn btn-outline-secondary">
                <i class="fas fa-arrow-left me-2"></i>Volver a Profesores
            </a>
        </div>

        <!-- InformaciÃ³n del profesor -->
        <div class="card mb-4">
            <div class="card-header bg-primary text-white">
                <h5 class="card-title mb-0">
                    <i class="fas fa-chalkboard-teacher me-2"></i>InformaciÃ³n del Profesor
                </h5>
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-8">
                        <h4><?php echo htmlspecialchars($profesor['nombre'] . ' ' . $profesor['apellido']); ?></h4>
                        <p class="mb-1"><strong>CÃ©dula:</strong> <?php echo htmlspecialchars($profesor['cedula'] ?? 'No especificada'); ?></p>
                        <p class="mb-1"><strong>Especialidad:</strong> 
                            <?php if ($profesor['especialidad']): ?>
                                <span class="badge bg-info"><?php echo htmlspecialchars($profesor['especialidad']); ?></span>
                            <?php else: ?>
                                <span class="text-muted">No especificada</span>
                            <?php endif; ?>
                        </p>
                        <p class="mb-0"><strong>Email:</strong> <?php echo htmlspecialchars($profesor['email'] ?? 'No especificado'); ?></p>
                    </div>
                    <div class="col-md-4 text-end">
                        <div class="mb-2">
                            <span class="badge bg-<?php echo $profesor['estado'] == 'activo' ? 'success' : 'secondary'; ?> fs-6">
                                <?php echo $profesor['estado'] == 'activo' ? 'Activo' : 'Inactivo'; ?>
                            </span>
                        </div>
                        <div class="text-muted">
                            <i class="fas fa-chalkboard me-1"></i>
                            <?php echo count($secciones_titular); ?> secciÃ³n(es) como titular
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <?php if ($mensaje): ?>
            <div class="alert alert-<?php echo $tipo_mensaje; ?> alert-dismissible fade show" role="alert">
                <?php echo $mensaje; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <!-- Sistema de primaria - InformaciÃ³n -->
        <div class="alert alert-info mb-4">
            <h6><i class="fas fa-info-circle me-2"></i>Sistema de Primaria - Profesor Titular</h6>
            <ul class="mb-0">
                <li><strong>Profesor Titular:</strong> Cada profesor puede ser titular de una o mÃ¡s secciones de primaria</li>
                <li><strong>AsignaciÃ³n AutomÃ¡tica:</strong> Al asignar una secciÃ³n, el profesor recibe TODAS las Ã¡reas de aprendizaje automÃ¡ticamente</li>
                <li><strong>Horario AutomÃ¡tico:</strong> Se genera un horario completo distribuyendo todas las Ã¡reas en la semana</li>
                <li><strong>Responsabilidades:</strong> El profesor titular es responsable de todas las materias bÃ¡sicas de la secciÃ³n</li>
            </ul>
        </div>

        <!-- Asignar nueva secciÃ³n -->
        <div class="card mb-4">
            <div class="card-header bg-success text-white">
                <h5 class="card-title mb-0">
                    <i class="fas fa-plus-circle me-2"></i>Asignar Nueva SecciÃ³n como Titular
                </h5>
            </div>
            <div class="card-body">
                <form method="POST" action="" id="formAsignacion">
                    <input type="hidden" name="asignar_titular" value="1">
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="año_escolar_id" class="form-label">Año Escolar *</label>
                            <select class="form-select" id="año_escolar_id" name="año_escolar_id" required>
                                <option value="">Seleccionar año escolar...</option>
                                <?php foreach ($años_escolares as $año): ?>
                                    <option value="<?php echo $año['id']; ?>">
                                        <?php echo htmlspecialchars($año['nombre']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="col-md-6 mb-3">
                            <label for="seccion_id" class="form-label">SecciÃ³n de Primaria *</label>
                            <select class="form-select" id="seccion_id" name="seccion_id" required>
                                <option value="">Seleccionar año escolar primero</option>
                            </select>
                            <div class="form-text" id="estado-seccion-texto"></div>
                        </div>
                    </div>

                    <!-- InformaciÃ³n de Ã¡reas que se asignarÃ¡n -->
                    <?php if (count($areas_aprendizaje) > 0): ?>
                    <div class="alert alert-warning">
                        <h6><i class="fas fa-exclamation-triangle me-2"></i>Ãreas que se asignarÃ¡n automÃ¡ticamente:</h6>
                        <div class="row mt-2">
                            <?php foreach ($areas_aprendizaje as $area): ?>
                                <div class="col-md-4 mb-1">
                                    <i class="fas fa-check text-success me-1"></i>
                                    <strong><?php echo htmlspecialchars($area['nombre']); ?></strong>
                                    <small class="text-muted">(<?php echo htmlspecialchars($area['codigo']); ?>)</small>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <div class="mt-2">
                            <small class="text-muted">
                                <i class="fas fa-clock me-1"></i>
                                Se generarÃ¡ un horario automÃ¡tico distribuyendo estas Ã¡reas de lunes a viernes.
                            </small>
                        </div>
                    </div>
                    <?php else: ?>
                    <div class="alert alert-danger">
                        <i class="fas fa-exclamation-triangle me-2"></i>
                        <strong>Advertencia:</strong> No hay Ã¡reas de aprendizaje configuradas para primaria. 
                        <a href="../areas_aprendizaje/agregar.php" class="alert-link">Configurar Ã¡reas primero</a>
                    </div>
                    <?php endif; ?>

                    <div class="d-grid">
                        <button type="submit" class="btn btn-success" <?php echo count($areas_aprendizaje) === 0 ? 'disabled' : ''; ?>>
                            <i class="fas fa-user-check me-2"></i>Asignar como Profesor Titular
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Secciones donde es titular actualmente -->
        <div class="card">
            <div class="card-header bg-primary text-white">
                <h5 class="card-title mb-0">
                    <i class="fas fa-list me-2"></i>Secciones como Profesor Titular
                </h5>
            </div>
            <div class="card-body">
                <?php if ($secciones_titular): ?>
                    <div class="table-responsive">
                        <table class="table table-striped table-hover">
                            <thead>
                                <tr>
                                    <th>Año Escolar</th>
                                    <th>SecciÃ³n</th>
                                    <th>Estudiantes</th>
                                    <th>Horarios</th>
                                    <th>Estado</th>
                                    <th>Acciones</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($secciones_titular as $seccion): ?>
                                    <tr>
                                        <td>
                                            <span class="badge bg-info"><?php echo htmlspecialchars($seccion['año_escolar_nombre']); ?></span>
                                        </td>
                                        <td>
                                            <strong><?php echo htmlspecialchars($seccion['nombre']); ?></strong>
                                            <br>
                                            <small class="text-muted">Capacidad: <?php echo $seccion['capacidad'] ?: 'Sin lÃ­mite'; ?></small>
                                        </td>
                                        <td>
                                            <span class="badge bg-<?php echo $seccion['total_estudiantes'] > 0 ? 'success' : 'secondary'; ?>">
                                                <?php echo $seccion['total_estudiantes']; ?> estudiantes
                                            </span>
                                        </td>
                                        <td>
                                            <span class="badge bg-primary"><?php echo $seccion['total_horarios']; ?> horarios</span>
                                        </td>
                                        <td>
                                            <span class="badge bg-success">Titular Activo</span>
                                        </td>
                                        <td>
                                            <div class="btn-group btn-group-sm">
                                                <!-- MÃ³dulos no implementados aÃºn: ../secciones/ver.php y ../horarios/gestionar.php -->
                                                <button type="button" 
                                                        class="btn btn-outline-danger" 
                                                        title="Remover como Titular"
                                                        onclick="confirmarRemocion(<?php echo $seccion['id']; ?>, '<?php echo htmlspecialchars($seccion['nombre']); ?>')">
                                                    <i class="fas fa-user-times"></i>
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
                        <i class="fas fa-chalkboard fa-3x text-muted mb-3"></i>
                        <h5>No es titular de ninguna secciÃ³n</h5>
                        <p class="text-muted">Este profesor no estÃ¡ asignado como titular de ninguna secciÃ³n de primaria.</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<script>
// Cargar secciones segÃºn el año escolar seleccionado
document.getElementById('año_escolar_id').addEventListener('change', function() {
    const añoEscolarId = this.value;
    const seccionSelect = document.getElementById('seccion_id');
    const estadoTexto = document.getElementById('estado-seccion-texto');
    
    if (añoEscolarId) {
        // Filtrar secciones del año escolar seleccionado
        const seccionesDisponibles = <?php echo json_encode($secciones); ?>;
        const seccionesFiltradas = seccionesDisponibles.filter(seccion => seccion.año_escolar_id == añoEscolarId);
        
        seccionSelect.innerHTML = '<option value="">Seleccionar secciÃ³n...</option>';
        
        if (seccionesFiltradas.length > 0) {
            seccionesFiltradas.forEach(seccion => {
                let textoEstado = '';
                let claseEstado = '';
                
                if (seccion.estado_asignacion === 'actual') {
                    textoEstado = ' (Ya es titular)';
                    claseEstado = 'text-warning';
                } else if (seccion.estado_asignacion === 'disponible') {
                    textoEstado = ' (Disponible)';
                    claseEstado = 'text-success';
                } else {
                    textoEstado = ' (Ocupada por otro profesor)';
                    claseEstado = 'text-danger';
                }
                
                seccionSelect.innerHTML += `
                    <option value="${seccion.id}" 
                            data-estado="${seccion.estado_asignacion}"
                            class="${claseEstado}">
                        ${seccion.nombre}${textoEstado}
                    </option>
                `;
            });
            
            estadoTexto.innerHTML = `<i class="fas fa-info-circle"></i> ${seccionesFiltradas.length} secciÃ³n(es) encontrada(s)`;
        } else {
            seccionSelect.innerHTML = '<option value="">No hay secciones disponibles</option>';
            estadoTexto.innerHTML = '<i class="fas fa-exclamation-triangle text-warning"></i> No hay secciones de primaria disponibles para este año escolar';
        }
    } else {
        seccionSelect.innerHTML = '<option value="">Seleccionar año escolar primero</option>';
        estadoTexto.innerHTML = '';
    }
});

// Mostrar informaciÃ³n detallada de la secciÃ³n seleccionada
document.getElementById('seccion_id').addEventListener('change', function() {
    const selectedOption = this.options[this.selectedIndex];
    const estado = selectedOption ? selectedOption.getAttribute('data-estado') : '';
    const estadoTexto = document.getElementById('estado-seccion-texto');
    
    if (estado === 'actual') {
        estadoTexto.innerHTML = '<i class="fas fa-check-circle text-warning"></i> Ya es titular de esta secciÃ³n - Puede reasignar';
    } else if (estado === 'disponible') {
        estadoTexto.innerHTML = '<i class="fas fa-check-circle text-success"></i> SecciÃ³n disponible para asignar';
    } else if (estado === 'ocupada') {
        estadoTexto.innerHTML = '<i class="fas fa-times-circle text-danger"></i> Esta secciÃ³n ya tiene otro profesor titular';
    } else {
        estadoTexto.innerHTML = '';
    }
});

// ValidaciÃ³n del formulario
document.getElementById('formAsignacion').addEventListener('submit', function(e) {
    const añoEscolar = document.getElementById('año_escolar_id').value;
    const seccion = document.getElementById('seccion_id').value;
    const selectedOption = document.getElementById('seccion_id').options[document.getElementById('seccion_id').selectedIndex];
    const estado = selectedOption ? selectedOption.getAttribute('data-estado') : '';
    
    if (!añoEscolar || !seccion) {
        e.preventDefault();
        alert('âš ï¸ Por favor seleccione año escolar y secciÃ³n');
        return false;
    }
    
    if (estado === 'ocupada') {
        e.preventDefault();
        alert('âŒ Esta secciÃ³n ya tiene otro profesor titular. No puede ser asignada.');
        return false;
    }
    
    <?php if (count($areas_aprendizaje) === 0): ?>
    if (!confirm('âš ï¸ ADVERTENCIA: No hay Ã¡reas de aprendizaje configuradas. Â¿Desea continuar de todos modos?')) {
        e.preventDefault();
        return false;
    }
    <?php endif; ?>
});

function confirmarRemocion(seccionId, seccionNombre) {
    if (confirm(`Â¿EstÃ¡s seguro de que deseas remover a este profesor como titular de la secciÃ³n "${seccionNombre}"?\n\nEsto eliminarÃ¡ su asignaciÃ³n pero mantendrÃ¡ los horarios.`)) {
        window.location.href = `asignar.php?id=<?php echo $profesor_id; ?>&remover_titular=${seccionId}`;
    }
}

// Inicializar la interfaz
document.addEventListener('DOMContentLoaded', function() {
    // Disparar cambio si hay año escolar seleccionado
    const añoEscolarSelect = document.getElementById('año_escolar_id');
    if (añoEscolarSelect.value) {
        añoEscolarSelect.dispatchEvent(new Event('change'));
    }
});
</script>

<?php include '../../includes/footer.php'; ?>
