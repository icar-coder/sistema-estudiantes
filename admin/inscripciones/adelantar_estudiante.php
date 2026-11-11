<?php
require_once '../../includes/auth.php';
require_once '../../includes/database.php';
checkAuth();
checkRole(['admin']);

$inscripcion_id = $_GET['inscripcion_id'] ?? null;
$estudiante_id = $_GET['estudiante_id'] ?? null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['adelantar_estudiante'])) {
    if (!$inscripcion_id) $inscripcion_id = $_POST['inscripcion_id'] ?? null;
    if (!$estudiante_id) $estudiante_id = $_POST['estudiante_id'] ?? null;
}

if (!$inscripcion_id || !$estudiante_id) {
    header("Location: gestionar.php");
    exit;
}

// Obtener datos del estudiante y su inscripciÃ³n actual
$stmt = $pdo->prepare("
    SELECT e.*, i.*, s.nombre as seccion_actual, s.grado_id as grado_actual_id, s.id as seccion_actual_id,
           g.nombre as grado_actual, g.numero as grado_numero_actual,
           ae.id as año_escolar_id, ae.nombre as año_escolar
    FROM estudiantes e
    JOIN inscripciones i ON e.id = i.estudiante_id
    JOIN secciones s ON i.seccion_id = s.id
    JOIN grados g ON s.grado_id = g.id
    JOIN años_escolares ae ON i.año_escolar_id = ae.id
    WHERE i.id = ? AND e.id = ?
");
$stmt->execute([$inscripcion_id, $estudiante_id]);
$datos_estudiante = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$datos_estudiante) {
    header("Location: gestionar.php");
    exit;
}

// Obtener año escolar ACTIVO
$stmt = $pdo->prepare("SELECT id, nombre FROM años_escolares WHERE estado = 'activo' LIMIT 1");
$stmt->execute();
$año_escolar_activo = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$año_escolar_activo) {
    error_log("Error en " . __FILE__ . ": No hay año escolar activo disponible.");
    $_SESSION['error_sistema'] = "OcurriÃ³ un error al procesar la solicitud. El equipo tÃ©cnico fue notificado.";
    header('Location: ' . ($_SERVER['HTTP_REFERER'] ?? app_url()));
    exit;
}

// Obtener grados disponibles para adelantar (solo grados superiores)
$stmt = $pdo->prepare("
    SELECT * FROM grados 
    WHERE estado = 'activo' AND orden > ?
    ORDER BY orden
");
$stmt->execute([$datos_estudiante['grado_actual_id']]);
$grados_superiores = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Obtener Ã¡reas de aprendizaje e indicadores
$areas_aprendizaje = [];
$stmt = $pdo->query("SELECT * FROM areas_aprendizaje WHERE estado = 'activa' ORDER BY nombre");
$areas_aprendizaje = $stmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($areas_aprendizaje as &$area) {
    $stmt = $pdo->prepare("SELECT * FROM areas_indicadores WHERE area_id = ? AND estado = 'activo' ORDER BY orden");
    $stmt->execute([$area['id']]);
    $area['indicadores'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

$mensaje = '';
$tipo_mensaje = '';

// Procesar adelantamiento
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['adelantar_estudiante'])) {
    try {
        $pdo->beginTransaction();
        
        $nuevo_grado_id = $_POST['nuevo_grado_id'];
        $seccion_id = $_POST['seccion_id'];
        $literal_final = $_POST['literal_final'];
        $observaciones = trim($_POST['observaciones'] ?? '');
        $tipo_adelantamiento = $_POST['tipo_adelantamiento'] ?? 'transferencia';
        
        // Validar
        if (!$nuevo_grado_id || !$seccion_id || !$literal_final) {
            throw new Exception("Complete todos los campos obligatorios");
        }
        
        // Verificar que la nueva secciÃ³n pertenezca al grado seleccionado
        $stmt = $pdo->prepare("SELECT grado_id FROM secciones WHERE id = ?");
        $stmt->execute([$seccion_id]);
        $seccion_destino = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$seccion_destino || $seccion_destino['grado_id'] != $nuevo_grado_id) {
            throw new Exception("La secciÃ³n seleccionada no pertenece al grado destino.");
        }
        
        // Guardar historial acadÃ©mico del grado anterior
        $stmt = $pdo->prepare("
            INSERT INTO historial_academico 
            (estudiante_id, año_escolar_id, grado_id, literal_final, tipo_adelantamiento, observaciones) 
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $estudiante_id,
            $datos_estudiante['año_escolar_id'],
            $datos_estudiante['grado_actual_id'],
            $literal_final,
            $tipo_adelantamiento,
            $observaciones
        ]);
        $historial_id = $pdo->lastInsertId();
        
        // Guardar indicadores de evaluaciÃ³n
        if (isset($_POST['indicadores']) && is_array($_POST['indicadores'])) {
            foreach ($_POST['indicadores'] as $indicador_id => $valoracion) {
                if (!empty($valoracion)) {
                    $stmt = $pdo->prepare("
                        INSERT INTO historial_indicadores 
                        (historial_id, indicador_id, valoracion) 
                        VALUES (?, ?, ?)
                    ");
                    $stmt->execute([$historial_id, $indicador_id, $valoracion]);
                }
            }
        }
        
        // 2. ACTUALIZAR la inscripciÃ³n existente (no crear nueva)
        $stmt = $pdo->prepare("
            UPDATE inscripciones 
            SET seccion_id = ?, fecha_inscripcion = CURDATE() 
            WHERE id = ?
        ");
        $stmt->execute([$seccion_id, $inscripcion_id]);
        
        $pdo->commit();
        
        $_SESSION['mensaje'] = "âœ… Estudiante adelantado correctamente al siguiente grado";
        $_SESSION['tipo_mensaje'] = "success";
        header("Location: gestionar.php");
        exit;
        
    } catch (Exception $e) {
        $pdo->rollBack();
        $mensaje = "âŒ Error: " . $e->getMessage();
        $tipo_mensaje = "danger";
    }
}

// Obtener informaciÃ³n del grado actual para mostrar
$grado_actual_numero = $datos_estudiante['grado_numero_actual'] ?? 1;
$grado_destino_sugerido = $grado_actual_numero + 1;
?>

<?php include '../../includes/header.php'; ?>

<div class="content-wrapper" style="padding: 20px;">
    <?php include '../../includes/sidebar_admin.php'; ?>
    
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2>ðŸŽ“ Adelantar Estudiante</h2>
        <a href="gestionar.php" class="btn btn-outline-secondary">
            <i class="fas fa-arrow-left me-2"></i>Volver
        </a>
    </div>

    <?php if ($mensaje): ?>
        <div class="alert alert-<?php echo $tipo_mensaje; ?>"><?php echo $mensaje; ?></div>
    <?php endif; ?>

    <!-- InformaciÃ³n del Estudiante -->
    <div class="card mb-4">
        <div class="card-header bg-primary text-white">
            <h5 class="card-title mb-0">
                <i class="fas fa-user-graduate me-2"></i>Estudiante a Adelantar
            </h5>
        </div>
        <div class="card-body">
            <div class="row">
                <div class="col-md-6">
                    <p><strong>Estudiante:</strong> <?php echo htmlspecialchars($datos_estudiante['nombre'] . ' ' . $datos_estudiante['apellido']); ?></p>
                    <p><strong>CÃ©dula Escolar:</strong> <?php echo htmlspecialchars($datos_estudiante['cedula_escolar']); ?></p>
                    <p><strong>Año Escolar:</strong> <?php echo htmlspecialchars($datos_estudiante['año_escolar']); ?></p>
                </div>
                <div class="col-md-6">
                    <p><strong>Grado Actual:</strong> <?php echo htmlspecialchars($datos_estudiante['grado_actual']); ?></p>
                    <p><strong>SecciÃ³n Actual:</strong> <?php echo htmlspecialchars($datos_estudiante['seccion_actual']); ?></p>
                    <p><strong>Grado Sugerido:</strong> <span class="badge bg-success"><?php echo $grado_destino_sugerido; ?>Â° Grado</span></p>
                </div>
            </div>
        </div>
    </div>

    <form method="POST" action="">
        <input type="hidden" name="inscripcion_id" value="<?php echo $inscripcion_id; ?>">
        <input type="hidden" name="estudiante_id" value="<?php echo $estudiante_id; ?>">
        
        <!-- Tipo de Adelantamiento -->
        <div class="card mb-4">
            <div class="card-header bg-info text-white">
                <h5 class="card-title mb-0">
                    <i class="fas fa-cog me-2"></i>Tipo de Adelantamiento
                </h5>
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-6">
                        <label for="tipo_adelantamiento" class="form-label">Motivo del Adelantamiento *</label>
                        <select class="form-select" id="tipo_adelantamiento" name="tipo_adelantamiento" required>
                            <option value="transferencia">ðŸ“š Transferencia de otra escuela</option>
                            <option value="academico">ðŸ† MÃ©rito acadÃ©mico (adelanto interno)</option>
                            <option value="otros">ðŸ”§ Otros motivos</option>
                        </select>
                        <div class="form-text">
                            <strong>Transferencia:</strong> Para estudiantes que vienen de otra instituciÃ³n<br>
                            <strong>MÃ©rito acadÃ©mico:</strong> Para estudiantes de la escuela con desempeño sobresaliente<br>
                            <strong>Otros:</strong> Casos especiales o adecuaciones curriculares
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- SelecciÃ³n del Nuevo Grado -->
        <div class="card mb-4">
            <div class="card-header bg-success text-white">
                <h5 class="card-title mb-0">
                    <i class="fas fa-forward me-2"></i>Seleccionar Nuevo Grado y SecciÃ³n
                </h5>
            </div>
            <div class="card-body">
                <div class="alert alert-info">
                    <i class="fas fa-info-circle me-2"></i>
                    El estudiante serÃ¡ <strong>transferido a una nueva secciÃ³n</strong> dentro del mismo año escolar <?php echo htmlspecialchars($datos_estudiante['año_escolar']); ?>
                </div>
                
                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label for="nuevo_grado_id" class="form-label">Nuevo Grado *</label>
                        <select class="form-select" id="nuevo_grado_id" name="nuevo_grado_id" required>
                            <option value="">Seleccionar grado...</option>
                            <?php foreach ($grados_superiores as $grado): ?>
                                <option value="<?php echo $grado['id']; ?>" 
                                    <?php echo ($grado['numero'] == $grado_destino_sugerido) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($grado['nombre']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <div class="form-text">Seleccione el grado al que serÃ¡ adelantado</div>
                    </div>
                    <div class="col-md-6 mb-3">
                        <label for="seccion_id" class="form-label">Nueva SecciÃ³n *</label>
                        <select class="form-select" id="seccion_id" name="seccion_id" required disabled>
                            <option value="">Primero seleccione un grado</option>
                        </select>
                        <div class="form-text" id="seccion_info">Las secciones se cargarÃ¡n segÃºn el grado seleccionado</div>
                    </div>
                </div>
            </div>
        </div>

        <!-- EvaluaciÃ³n del Grado Anterior -->
        <div class="card mb-4">
            <div class="card-header bg-warning text-dark">
                <h5 class="card-title mb-0">
                    <i class="fas fa-history me-2"></i>EvaluaciÃ³n del Grado Anterior - <?php echo htmlspecialchars($datos_estudiante['grado_actual']); ?>
                </h5>
            </div>
            <div class="card-body">
                <div class="alert alert-info">
                    <i class="fas fa-info-circle me-2"></i>
                    Complete la evaluaciÃ³n final del estudiante en el grado actual o anterior.
                </div>
                
                <!-- Literal Final -->
                <div class="row mb-4">
                    <div class="col-md-6">
                        <label for="literal_final" class="form-label">Literal Final General *</label>
                        <select class="form-select" id="literal_final" name="literal_final" required>
                            <option value="">Seleccionar literal...</option>
                            <option value="A">A (Excelente - 90-100 puntos)</option>
                            <option value="B">B (Bueno - 80-89 puntos)</option>
                            <option value="C">C (Aprobado - 70-79 puntos)</option>
                            <option value="D">D (Reprobado - 0-69 puntos)</option>
                        </select>
                    </div>
                </div>

                <!-- Indicadores por Ãrea -->
                <h6 class="mb-3">Indicadores de EvaluaciÃ³n por Ãrea</h6>
                <?php foreach ($areas_aprendizaje as $area): ?>
                <div class="card mb-3">
                    <div class="card-header bg-light">
                        <h6 class="card-title mb-0"><?php echo htmlspecialchars($area['nombre']); ?></h6>
                    </div>
                    <div class="card-body">
                        <?php foreach ($area['indicadores'] as $indicador): ?>
                        <div class="row mb-2">
                            <div class="col-md-8">
                                <label class="form-label"><?php echo htmlspecialchars($indicador['nombre']); ?></label>
                                <?php if ($indicador['descripcion']): ?>
                                    <div class="form-text"><?php echo htmlspecialchars($indicador['descripcion']); ?></div>
                                <?php endif; ?>
                            </div>
                            <div class="col-md-4">
                                <select class="form-select" name="indicadores[<?php echo $indicador['id']; ?>]">
                                    <option value="">Seleccionar...</option>
                                    <option value="LE">LE (Logro Esperado)</option>
                                    <option value="L">L (Logrado)</option>
                                    <option value="PA">PA (Proceso Avanzado)</option>
                                    <option value="IP">IP (Inicio del Proceso)</option>
                                    <option value="NL">NL (No Logrado)</option>
                                </select>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endforeach; ?>

                <!-- Observaciones -->
                <div class="row mt-4">
                    <div class="col-12">
                        <label for="observaciones" class="form-label">Observaciones Generales</label>
                        <textarea class="form-control" id="observaciones" name="observaciones" rows="3" 
                                  placeholder="Observaciones sobre el rendimiento acadÃ©mico, conducta, escuela de origen, etc..."><?php echo htmlspecialchars($_POST['observaciones'] ?? ''); ?></textarea>
                    </div>
                </div>
            </div>
        </div>

        <div class="text-center mt-4">
            <button type="submit" name="adelantar_estudiante" class="btn btn-success btn-lg">
                <i class="fas fa-check me-2"></i>Confirmar Adelantamiento
            </button>
            <a href="gestionar.php" class="btn btn-secondary btn-lg ms-2">Cancelar</a>
        </div>
    </form>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const gradoSelect = document.getElementById('nuevo_grado_id');
    const seccionSelect = document.getElementById('seccion_id');
    const seccionInfo = document.getElementById('seccion_info');

    if (gradoSelect && seccionSelect) {
        gradoSelect.addEventListener('change', function() {
            const gradoId = this.value;
            
            if (gradoId) {
                seccionSelect.disabled = false;
                seccionSelect.innerHTML = '<option value="">Cargando secciones...</option>';
                
                fetch(`cargar_secciones_adelanto.php?grado_id=${gradoId}&año_escolar_id=<?php echo $datos_estudiante['año_escolar_id']; ?>`)
                    .then(response => {
                        if (!response.ok) {
                            throw new Error('Error en la respuesta del servidor');
                        }
                        return response.json();
                    })
                    .then(data => {
                        if (data.success && data.secciones && data.secciones.length > 0) {
                            seccionSelect.innerHTML = '<option value="">Seleccionar secciÃ³n...</option>';
                            data.secciones.forEach(seccion => {
                                const option = document.createElement('option');
                                option.value = seccion.id;
                                
                                let textoSeccion = seccion.nombre;
                                if (seccion.capacidad !== null) {
                                    textoSeccion += ` - ${seccion.cupos_disponibles} cupos disponibles`;
                                } else {
                                    textoSeccion += ' - Cupos Ilimitados';
                                }
                                
                                option.textContent = textoSeccion;
                                seccionSelect.appendChild(option);
                            });
                            seccionInfo.innerHTML = `<span class="text-success">${data.secciones.length} secciÃ³n(es) disponible(s)</span>`;
                        } else {
                            seccionSelect.innerHTML = '<option value="">No hay secciones disponibles</option>';
                            seccionInfo.innerHTML = '<span class="text-danger">No hay secciones disponibles para este grado</span>';
                        }
                    })
                    .catch(error => {
                        console.error('Error:', error);
                        seccionSelect.innerHTML = '<option value="">Error al cargar secciones</option>';
                        seccionInfo.innerHTML = '<span class="text-danger">Error al cargar las secciones</span>';
                    });
            } else {
                seccionSelect.disabled = true;
                seccionSelect.innerHTML = '<option value="">Primero seleccione un grado</option>';
                seccionInfo.innerHTML = 'Las secciones se cargarÃ¡n segÃºn el grado seleccionado';
            }
        });
        
        // Disparar el cambio si ya hay un grado seleccionado por defecto
        if (gradoSelect.value) {
            gradoSelect.dispatchEvent(new Event('change'));
        }
    }
});
</script>

<?php include '../../includes/footer.php'; ?>
