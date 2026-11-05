<?php
require_once '../../includes/auth.php';
require_once '../../includes/database.php';
checkAuth();
checkRole(['admin']);

$mensaje = '';
$tipo_mensaje = '';

// Obtener el Ãºltimo año escolar para sugerencias
try {
    $stmt = $pdo->query("SELECT nombre FROM años_escolares ORDER BY id DESC LIMIT 1");
    $ultimo_ano = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $ultimo_ano = null;
}

// Calcular sugerencias automÃ¡ticas
$ano_actual = date('Y');
$ano_siguiente = $ano_actual + 1;
$ano_anterior = $ano_actual - 1;

$sugerencias = [
    "{$ano_actual}-{$ano_siguiente}",
    "{$ano_siguiente}-" . ($ano_siguiente + 1),
    "Año Escolar {$ano_actual}",
    "Año Escolar {$ano_siguiente}",
    "Periodo {$ano_actual}-{$ano_siguiente}"
];

// ConfiguraciÃ³n por defecto para lapsos
$configuracion_lapsos = [
    [
        'nombre' => 'Primer Lapso',
        'numero' => 1,
        'fecha_inicio' => $ano_actual . '-09-16',
        'fecha_fin' => $ano_siguiente . '-01-13',
        'proyecto_obligatorio' => true,
        'nombre_proyecto' => 'Proyecto del Primer Lapso'
    ],
    [
        'nombre' => 'Segundo Lapso',
        'numero' => 2,
        'fecha_inicio' => $ano_siguiente . '-01-06',
        'fecha_fin' => $ano_siguiente . '-04-04',
        'proyecto_obligatorio' => true,
        'nombre_proyecto' => 'Proyecto del Segundo Lapso'
    ],
    [
        'nombre' => 'Tercer Lapso',
        'numero' => 3,
        'fecha_inicio' => $ano_siguiente . '-04-14',
        'fecha_fin' => $ano_siguiente . '-07-15',
        'proyecto_obligatorio' => true,
        'nombre_proyecto' => 'Proyecto del Tercer Lapso'
    ]
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Sanitizar y validar datos
    $nombre = trim($_POST['nombre'] ?? '');
    $fecha_inicio = $_POST['fecha_inicio'] ?? '';
    $fecha_fin = $_POST['fecha_fin'] ?? '';
    $estado = $_POST['estado'] ?? 'planificado';
    
    // Si se seleccionÃ³ "personalizado", usar el campo personalizado
    if ($nombre === 'personalizado') {
        $nombre = trim($_POST['nombre_personalizado'] ?? '');
    }
    
    // Datos de lapsos
    $lapsos = [];
    if (isset($_POST['lapsos']) && is_array($_POST['lapsos'])) {
        foreach ($_POST['lapsos'] as $lapso) {
            if (!empty($lapso['nombre'])) {
                $lapsos[] = [
                    'nombre' => trim($lapso['nombre']),
                    'numero' => intval($lapso['numero']),
                    'fecha_inicio' => $lapso['fecha_inicio'],
                    'fecha_fin' => $lapso['fecha_fin'],
                    'proyecto_obligatorio' => isset($lapso['proyecto_obligatorio']) ? (bool)$lapso['proyecto_obligatorio'] : true,
                    'nombre_proyecto' => trim($lapso['nombre_proyecto'] ?? 'Proyecto del ' . $lapso['nombre'])
                ];
            }
        }
    }
    
    // Validaciones
    $errores = [];
    
    if (empty($nombre)) {
        $errores[] = "Por favor complete el nombre del año escolar";
    }
    
    if (count($lapsos) < 1) {
        $errores[] = "Debe definir al menos un lapso";
    }
    
    // Validar que las fechas de los lapsos estÃ©n dentro del rango del año escolar
    if ($fecha_inicio && $fecha_fin) {
        foreach ($lapsos as $index => $lapso) {
            if ($lapso['fecha_inicio'] && $lapso['fecha_inicio'] < $fecha_inicio) {
                $errores[] = "La fecha de inicio del {$lapso['nombre']} no puede ser anterior a la fecha de inicio del año escolar";
            }
            if ($lapso['fecha_fin'] && $lapso['fecha_fin'] > $fecha_fin) {
                $errores[] = "La fecha de fin del {$lapso['nombre']} no puede ser posterior a la fecha de fin del año escolar";
            }
            if ($lapso['fecha_inicio'] && $lapso['fecha_fin'] && $lapso['fecha_fin'] <= $lapso['fecha_inicio']) {
                $errores[] = "La fecha de fin del {$lapso['nombre']} debe ser posterior a la fecha de inicio";
            }
        }
    }
    
    if (empty($errores)) {
        try {
            $pdo->beginTransaction();
            
            // Verificar si la tabla necesita reparaciÃ³n
            $stmt = $pdo->query("SHOW TABLE STATUS LIKE 'años_escolares'");
            $table_status = $stmt->fetch(PDO::FETCH_ASSOC);
            
            // Si se estÃ¡ activando este año, desactivar los demÃ¡s
            if ($estado == 'activo') {
                $stmt = $pdo->prepare("UPDATE años_escolares SET estado = 'inactivo' WHERE estado = 'activo'");
                $stmt->execute();
            }
            
            // SOLUCIÃ“N AL PROBLEMA: Obtener el siguiente ID manualmente
            $max_id_stmt = $pdo->query("SELECT COALESCE(MAX(id), 0) + 1 as next_id FROM años_escolares");
            $next_id = $max_id_stmt->fetch(PDO::FETCH_ASSOC)['next_id'];
            
            // Insertar año escolar con ID manual para evitar el error
            $stmt = $pdo->prepare("INSERT INTO años_escolares (id, nombre, fecha_inicio, fecha_fin, estado) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$next_id, $nombre, $fecha_inicio, $fecha_fin, $estado]);
            $año_escolar_id = $next_id;
            
            if (!$año_escolar_id) {
                throw new PDOException("No se pudo obtener el ID del año escolar insertado");
            }
            
            // Insertar lapsos y proyectos
            foreach ($lapsos as $lapso) {
                // TambiÃ©n para lapsos: obtener siguiente ID manualmente
                $max_lapso_stmt = $pdo->query("SELECT COALESCE(MAX(id), 0) + 1 as next_id FROM lapsos");
                $next_lapso_id = $max_lapso_stmt->fetch(PDO::FETCH_ASSOC)['next_id'];
                
                $stmt = $pdo->prepare("INSERT INTO lapsos (id, año_escolar_id, numero, nombre, fecha_inicio, fecha_fin, estado) VALUES (?, ?, ?, ?, ?, ?, 'activo')");
                $stmt->execute([$next_lapso_id, $año_escolar_id, $lapso['numero'], $lapso['nombre'], $lapso['fecha_inicio'], $lapso['fecha_fin']]);
                $lapso_id = $next_lapso_id;
                
                // Crear proyecto automÃ¡ticamente si es obligatorio
                if ($lapso['proyecto_obligatorio'] && $lapso_id) {
                    $max_proyecto_stmt = $pdo->query("SELECT COALESCE(MAX(id), 0) + 1 as next_id FROM proyectos_lapso");
                    $next_proyecto_id = $max_proyecto_stmt->fetch(PDO::FETCH_ASSOC)['next_id'];
                    
                    $stmt = $pdo->prepare("INSERT INTO proyectos_lapso (id, lapso_id, seccion_id, nombre, tipo_proyecto, descripcion, fecha_realizacion, estado) VALUES (?, ?, NULL, ?, 'cultural', 'Proyecto correspondiente al lapso', CURDATE(), 'planificado')");
                    $stmt->execute([$next_proyecto_id, $lapso_id, $lapso['nombre_proyecto']]);
                }
            }
            
            $pdo->commit();
            
            $mensaje = "Año escolar \"$nombre\" agregado correctamente con " . count($lapsos) . " lapsos";
            $tipo_mensaje = "success";
            
            // Redireccionar para evitar reenvÃ­o del formulario
            header("Location: gestionar.php?mensaje=" . urlencode($mensaje) . "&tipo=" . $tipo_mensaje);
            exit;
            
        } catch (PDOException $e) {
            $pdo->rollBack();
            $mensaje = "Error al agregar año escolar: " . $e->getMessage();
            $tipo_mensaje = "danger";
            
            // Log del error para debugging
            error_log("Error en agregar_ano_escolar: " . $e->getMessage());
        }
    } else {
        $mensaje = implode("<br>", $errores);
        $tipo_mensaje = "warning";
    }
}
?>

<?php include '../../includes/header.php'; ?>

<div class="content-wrapper" style="padding: 20px;">
    <?php include '../../includes/sidebar_admin.php'; ?>

    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2>Agregar Nuevo Año Escolar</h2>
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

    <div class="card">
        <div class="card-header">
            <h5 class="card-title mb-0">Datos del Año Escolar</h5>
        </div>
        <div class="card-body">
            <form method="POST" action="" id="formAnoEscolar">
                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label for="nombre" class="form-label">Nombre del Año Escolar *</label>
                        <select class="form-select" id="nombre" name="nombre" required onchange="actualizarFechas()">
                            <option value="">Seleccionar o personalizar...</option>
                            <optgroup label="Sugerencias AutomÃ¡ticas">
                                <?php foreach ($sugerencias as $sugerencia): ?>
                                    <option value="<?php echo htmlspecialchars($sugerencia); ?>" 
                                        <?php echo ($_POST['nombre'] ?? '') == $sugerencia ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($sugerencia); ?>
                                    </option>
                                <?php endforeach; ?>
                            </optgroup>
                            <optgroup label="Personalizado">
                                <option value="personalizado" <?php echo ($_POST['nombre'] ?? '') == 'personalizado' ? 'selected' : ''; ?>>â†³ Escribir nombre personalizado</option>
                            </optgroup>
                        </select>
                        <div class="form-text">
                            <i class="fas fa-lightbulb me-1"></i>
                            Seleccione una sugerencia o elija "Personalizado" para escribir su propio nombre
                        </div>
                    </div>
                    
                    <div class="col-md-6 mb-3">
                        <label for="estado" class="form-label">Estado *</label>
                        <select class="form-select" id="estado" name="estado" required>
                            <option value="planificado" <?php echo ($_POST['estado'] ?? 'planificado') == 'planificado' ? 'selected' : ''; ?>>Planificado</option>
                            <option value="activo" <?php echo ($_POST['estado'] ?? '') == 'activo' ? 'selected' : ''; ?>>Activo</option>
                            <option value="inactivo" <?php echo ($_POST['estado'] ?? '') == 'inactivo' ? 'selected' : ''; ?>>Inactivo</option>
                        </select>
                        <div class="form-text">
                            <i class="fas fa-exclamation-triangle me-1"></i>
                            <strong>Nota:</strong> Al activar un año escolar, los demÃ¡s se desactivarÃ¡n automÃ¡ticamente
                        </div>
                    </div>
                </div>

                <!-- Campo para nombre personalizado -->
                <div id="campoPersonalizado" class="mb-3" style="display: <?php echo ($_POST['nombre'] ?? '') == 'personalizado' ? 'block' : 'none'; ?>;">
                    <label for="nombre_personalizado" class="form-label">Nombre Personalizado *</label>
                    <input type="text" class="form-control" id="nombre_personalizado" name="nombre_personalizado"
                           value="<?php echo htmlspecialchars($_POST['nombre_personalizado'] ?? ''); ?>"
                           placeholder="Ej: Ciclo Escolar 2024, Periodo Enero-Diciembre 2024">
                    <div class="form-text">Escriba el nombre que desea usar para este año escolar</div>
                </div>
                
                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label for="fecha_inicio" class="form-label">Fecha de Inicio</label>
                        <input type="date" class="form-control" id="fecha_inicio" name="fecha_inicio"
                               value="<?php echo htmlspecialchars($_POST['fecha_inicio'] ?? ''); ?>">
                        <div class="form-text">
                            <button type="button" class="btn btn-sm btn-outline-secondary mt-1" onclick="sugerirFechaInicio()">
                                <i class="fas fa-magic me-1"></i>Sugerir fecha tÃ­pica
                            </button>
                        </div>
                    </div>
                    
                    <div class="col-md-6 mb-3">
                        <label for="fecha_fin" class="form-label">Fecha de Fin</label>
                        <input type="date" class="form-control" id="fecha_fin" name="fecha_fin"
                               value="<?php echo htmlspecialchars($_POST['fecha_fin'] ?? ''); ?>">
                        <div class="form-text">
                            <button type="button" class="btn btn-sm btn-outline-secondary mt-1" onclick="sugerirFechaFin()">
                                <i class="fas fa-magic me-1"></i>Sugerir fecha tÃ­pica
                            </button>
                        </div>
                    </div>
                </div>

                <!-- SecciÃ³n de Lapsos -->
                <div class="card mt-4">
                    <div class="card-header bg-light">
                        <h6 class="card-title mb-0">
                            <i class="fas fa-calendar-alt me-2"></i>ConfiguraciÃ³n de Lapsos
                        </h6>
                    </div>
                    <div class="card-body">
                        <div class="alert alert-info">
                            <small>
                                <i class="fas fa-info-circle me-1"></i>
                                <strong>InformaciÃ³n:</strong> Configure los lapsos acadÃ©micos para este año escolar. 
                                Los lapsos se crearÃ¡n automÃ¡ticamente al guardar el año escolar.
                            </small>
                        </div>
                        
                        <div id="lapsos-container">
                            <?php 
                            $lapsos_data = isset($_POST['lapsos']) ? $_POST['lapsos'] : $configuracion_lapsos;
                            foreach ($lapsos_data as $index => $lapso): 
                            ?>
                            <div class="lapso-item border rounded p-3 mb-3">
                                <div class="row">
                                    <div class="col-md-3 mb-3">
                                        <label class="form-label">NÃºmero del Lapso *</label>
                                        <input type="number" class="form-control lapso-numero" name="lapsos[<?php echo $index; ?>][numero]" 
                                               value="<?php echo htmlspecialchars($lapso['numero'] ?? ($index + 1)); ?>" min="1" max="10" required>
                                    </div>
                                    <div class="col-md-5 mb-3">
                                        <label class="form-label">Nombre del Lapso *</label>
                                        <input type="text" class="form-control lapso-nombre" name="lapsos[<?php echo $index; ?>][nombre]" 
                                               value="<?php echo htmlspecialchars($lapso['nombre'] ?? ''); ?>" 
                                               placeholder="Ej: Primer Lapso, Segundo Lapso" required>
                                    </div>
                                    <div class="col-md-4 mb-3">
                                        <label class="form-label">Acciones</label>
                                        <div>
                                            <?php if ($index > 0): ?>
                                                <button type="button" class="btn btn-sm btn-outline-danger" onclick="eliminarLapso(this)">
                                                    <i class="fas fa-trash me-1"></i>Eliminar
                                                </button>
                                            <?php else: ?>
                                                <small class="text-muted">Lapso principal</small>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>

                                <!-- SECCIÃ“N CONFIGURACIÃ“N DEL PROYECTO -->
                                <div class="row bg-light py-2 rounded mb-3">
                                    <div class="col-12 mb-2">
                                        <h6 class="mb-3 text-primary">
                                            <i class="fas fa-project-diagram me-2"></i>ConfiguraciÃ³n del Proyecto
                                        </h6>
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">Â¿Requiere Proyecto? *</label>
                                        <select class="form-select proyecto-obligatorio" name="lapsos[<?php echo $index; ?>][proyecto_obligatorio]" required onchange="toggleProyectoNombre(this)">
                                            <option value="1" <?php echo ($lapso['proyecto_obligatorio'] ?? true) ? 'selected' : ''; ?>>SÃ­</option>
                                            <option value="0" <?php echo !($lapso['proyecto_obligatorio'] ?? true) ? 'selected' : ''; ?>>No</option>
                                        </select>
                                        <div class="form-text">Â¿Este lapso incluye proyecto de evaluaciÃ³n?</div>
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">Nombre del Proyecto *</label>
                                        <input type="text" class="form-control proyecto-nombre" name="lapsos[<?php echo $index; ?>][nombre_proyecto]" 
                                               value="<?php echo htmlspecialchars($lapso['nombre_proyecto'] ?? 'Proyecto del ' . ($lapso['nombre'] ?? '')); ?>" 
                                               placeholder="Ej: IndÃ­genas del Mundo, Ecosistemas Venezolanos" required>
                                        <div class="form-text">Nombre especÃ­fico del proyecto para este lapso</div>
                                    </div>
                                </div>

                                <div class="row">
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">Fecha Inicio</label>
                                        <input type="date" class="form-control lapso-fecha-inicio" name="lapsos[<?php echo $index; ?>][fecha_inicio]" 
                                               value="<?php echo htmlspecialchars($lapso['fecha_inicio'] ?? ''); ?>">
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">Fecha Fin</label>
                                        <input type="date" class="form-control lapso-fecha-fin" name="lapsos[<?php echo $index; ?>][fecha_fin]" 
                                               value="<?php echo htmlspecialchars($lapso['fecha_fin'] ?? ''); ?>">
                                    </div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        
                        <div class="d-flex justify-content-between align-items-center">
                            <small class="text-muted">
                                <i class="fas fa-info-circle me-1"></i>
                                Total de lapsos: <span id="contador-lapsos"><?php echo count($lapsos_data); ?></span>
                            </small>
                            <button type="button" class="btn btn-sm btn-outline-primary" onclick="agregarLapso()">
                                <i class="fas fa-plus me-1"></i>Agregar Lapso
                            </button>
                        </div>
                    </div>
                </div>

                <!-- InformaciÃ³n de ayuda -->
                <div class="alert alert-info mt-4">
                    <h6><i class="fas fa-info-circle me-2"></i>InformaciÃ³n del Sistema</h6>
                    <small>
                        <strong>Año actual:</strong> <?php echo $ano_actual; ?> | 
                        <strong>Ãšltimo año registrado:</strong> <?php echo $ultimo_ano ? htmlspecialchars($ultimo_ano['nombre']) : 'Ninguno'; ?>
                    </small>
                </div>
                
                <div class="d-grid gap-2 d-md-flex justify-content-md-end mt-4">
                    <button type="reset" class="btn btn-outline-secondary me-md-2" onclick="limpiarFormulario()">
                        <i class="fas fa-undo me-2"></i>Limpiar
                    </button>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save me-2"></i>Guardar Año Escolar con Lapsos
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
// FunciÃ³n para actualizar fechas segÃºn la selecciÃ³n
function actualizarFechas() {
    const selectNombre = document.getElementById('nombre');
    const campoPersonalizado = document.getElementById('campoPersonalizado');
    const inputPersonalizado = document.getElementById('nombre_personalizado');
    
    if (selectNombre.value === 'personalizado') {
        // Mostrar campo personalizado
        campoPersonalizado.style.display = 'block';
        inputPersonalizado.focus();
    } else {
        // Ocultar campo personalizado
        campoPersonalizado.style.display = 'none';
    }
    
    // Si se selecciona una sugerencia con formato año-año, sugerir fechas
    if (selectNombre.value.match(/^\d{4}-\d{4}$/)) {
        const años = selectNombre.value.split('-');
        sugerirFechaInicio(años[0]);
        sugerirFechaFin(años[1]);
    }
}

// FunciÃ³n para sugerir fecha de inicio tÃ­pica (Septiembre del año seleccionado)
function sugerirFechaInicio(ano) {
    const anoBase = ano || new Date().getFullYear();
    const fechaInicio = document.getElementById('fecha_inicio');
    
    // Fecha tÃ­pica de inicio: 16 de Septiembre del año base
    const fechaSugerida = `${anoBase}-09-16`;
    fechaInicio.value = fechaSugerida;
    
    // Actualizar fechas de lapsos si estÃ¡n vacÃ­as
    actualizarFechasLapsos(anoBase);
}

// FunciÃ³n para sugerir fecha de fin tÃ­pica (Julio del año siguiente)
function sugerirFechaFin(ano) {
    const anoSiguiente = ano || new Date().getFullYear() + 1;
    const fechaFin = document.getElementById('fecha_fin');
    
    // Fecha tÃ­pica de fin: 15 de Julio del año siguiente
    const fechaSugerida = `${anoSiguiente}-07-15`;
    fechaFin.value = fechaSugerida;
}

// FunciÃ³n para actualizar fechas de lapsos automÃ¡ticamente
function actualizarFechasLapsos(anoBase) {
    const anoSiguiente = parseInt(anoBase) + 1;
    const lapsos = document.querySelectorAll('.lapso-item');
    
    // Fechas tÃ­picas para lapsos en Venezuela
    const fechasLapsos = [
        { inicio: `${anoBase}-09-16`, fin: `${anoSiguiente}-01-13` },
        { inicio: `${anoSiguiente}-01-06`, fin: `${anoSiguiente}-04-04` },
        { inicio: `${anoSiguiente}-04-14`, fin: `${anoSiguiente}-07-15` }
    ];
    
    lapsos.forEach((lapso, index) => {
        if (fechasLapsos[index]) {
            const inputInicio = lapso.querySelector('.lapso-fecha-inicio');
            const inputFin = lapso.querySelector('.lapso-fecha-fin');
            
            if (inputInicio && !inputInicio.value) {
                inputInicio.value = fechasLapsos[index].inicio;
            }
            if (inputFin && !inputFin.value) {
                inputFin.value = fechasLapsos[index].fin;
            }
        }
    });
}

// FunciÃ³n para agregar nuevo lapso
function agregarLapso() {
    const container = document.getElementById('lapsos-container');
    const lapsoCount = container.children.length;
    const nuevoIndex = lapsoCount;
    
    const nombresSugeridos = ['Primer Lapso', 'Segundo Lapso', 'Tercer Lapso', 'Cuarto Lapso', 'Quinto Lapso'];
    const nombreLapso = nombresSugeridos[nuevoIndex] || `Lapso ${nuevoIndex + 1}`;
    const nombreProyecto = `Proyecto del ${nombreLapso}`;
    
    const nuevoLapso = document.createElement('div');
    nuevoLapso.className = 'lapso-item border rounded p-3 mb-3';
    nuevoLapso.innerHTML = `
        <div class="row">
            <div class="col-md-3 mb-3">
                <label class="form-label">NÃºmero del Lapso *</label>
                <input type="number" class="form-control lapso-numero" name="lapsos[${nuevoIndex}][numero]" 
                       value="${nuevoIndex + 1}" min="1" max="10" required>
            </div>
            <div class="col-md-5 mb-3">
                <label class="form-label">Nombre del Lapso *</label>
                <input type="text" class="form-control lapso-nombre" name="lapsos[${nuevoIndex}][nombre]" 
                       value="${nombreLapso}" required>
            </div>
            <div class="col-md-4 mb-3">
                <label class="form-label">Acciones</label>
                <div>
                    <button type="button" class="btn btn-sm btn-outline-danger" onclick="eliminarLapso(this)">
                        <i class="fas fa-trash me-1"></i>Eliminar
                    </button>
                </div>
            </div>
        </div>

        <!-- SECCIÃ“N CONFIGURACIÃ“N DEL PROYECTO -->
        <div class="row bg-light py-2 rounded mb-3">
            <div class="col-12 mb-2">
                <h6 class="mb-3 text-primary">
                    <i class="fas fa-project-diagram me-2"></i>ConfiguraciÃ³n del Proyecto
                </h6>
            </div>
            <div class="col-md-6 mb-3">
                <label class="form-label">Â¿Requiere Proyecto? *</label>
                <select class="form-select proyecto-obligatorio" name="lapsos[${nuevoIndex}][proyecto_obligatorio]" required onchange="toggleProyectoNombre(this)">
                    <option value="1" selected>SÃ­</option>
                    <option value="0">No</option>
                </select>
                <div class="form-text">Â¿Este lapso incluye proyecto de evaluaciÃ³n?</div>
            </div>
            <div class="col-md-6 mb-3">
                <label class="form-label">Nombre del Proyecto *</label>
                <input type="text" class="form-control proyecto-nombre" name="lapsos[${nuevoIndex}][nombre_proyecto]" 
                       value="${nombreProyecto}" 
                       placeholder="Ej: IndÃ­genas del Mundo, Ecosistemas Venezolanos" required>
                <div class="form-text">Nombre especÃ­fico del proyecto para este lapso</div>
            </div>
            <div class="col-12">
                <div class="alert alert-info py-2 mb-0">
                    <small>
                        <i class="fas fa-info-circle me-1"></i>
                        <strong>InformaciÃ³n:</strong> El proyecto serÃ¡ creado automÃ¡ticamente con indicadores de evaluaciÃ³n.
                    </small>
                </div>
            </div>
        </div>

        <div class="row">
            <div class="col-md-6 mb-3">
                <label class="form-label">Fecha Inicio</label>
                <input type="date" class="form-control lapso-fecha-inicio" name="lapsos[${nuevoIndex}][fecha_inicio]">
                <div class="form-text">
                    <button type="button" class="btn btn-sm btn-outline-secondary mt-1" onclick="sugerirFechaLapso(this, 'inicio')">
                        <i class="fas fa-magic me-1"></i>Sugerir fecha
                    </button>
                </div>
            </div>
            <div class="col-md-6 mb-3">
                <label class="form-label">Fecha Fin</label>
                <input type="date" class="form-control lapso-fecha-fin" name="lapsos[${nuevoIndex}][fecha_fin]">
                <div class="form-text">
                    <button type="button" class="btn btn-sm btn-outline-secondary mt-1" onclick="sugerirFechaLapso(this, 'fin')">
                        <i class="fas fa-magic me-1"></i>Sugerir fecha
                    </button>
                </div>
            </div>
        </div>
    `;
    
    container.appendChild(nuevoLapso);
    actualizarContadorLapsos();
    
    // Sugerir fechas automÃ¡ticamente para el nuevo lapso
    setTimeout(() => {
        sugerirFechasLapsoAutomatico(nuevoLapso, nuevoIndex);
    }, 100);
}

// FunciÃ³n auxiliar para sugerir fechas automÃ¡ticamente
function sugerirFechasLapsoAutomatico(lapsoElement, index) {
    const fechaInicioInput = lapsoElement.querySelector('.lapso-fecha-inicio');
    const fechaFinInput = lapsoElement.querySelector('.lapso-fecha-fin');
    
    if (!fechaInicioInput.value && !fechaFinInput.value) {
        const añoBase = new Date().getFullYear();
        const fechasSugeridas = [
            { inicio: `${añoBase}-09-16`, fin: `${añoBase + 1}-01-13` },
            { inicio: `${añoBase + 1}-01-06`, fin: `${añoBase + 1}-04-04` },
            { inicio: `${añoBase + 1}-04-14`, fin: `${añoBase + 1}-07-15` },
            { inicio: `${añoBase + 1}-07-22`, fin: `${añoBase + 1}-10-15` },
            { inicio: `${añoBase + 1}-10-20`, fin: `${añoBase + 2}-01-20` }
        ];
        
        if (fechasSugeridas[index]) {
            fechaInicioInput.value = fechasSugeridas[index].inicio;
            fechaFinInput.value = fechasSugeridas[index].fin;
        }
    }
}

// FunciÃ³n para mostrar/ocultar campo de nombre de proyecto
function toggleProyectoNombre(selectElement) {
    const lapsoItem = selectElement.closest('.lapso-item');
    const proyectoNombreInput = lapsoItem.querySelector('.proyecto-nombre');
    const proyectoNombreLabel = lapsoItem.querySelector('.form-label[for*="nombre_proyecto"]');
    
    if (selectElement.value === '0') {
        proyectoNombreInput.disabled = true;
        proyectoNombreInput.required = false;
        proyectoNombreLabel.innerHTML = 'Nombre del Proyecto <small class="text-muted">(Opcional)</small>';
        proyectoNombreInput.placeholder = 'Proyecto deshabilitado para este lapso';
    } else {
        proyectoNombreInput.disabled = false;
        proyectoNombreInput.required = true;
        proyectoNombreLabel.innerHTML = 'Nombre del Proyecto *';
        proyectoNombreInput.placeholder = 'Ej: IndÃ­genas del Mundo, Ecosistemas Venezolanos';
    }
}

// FunciÃ³n para sugerir fecha especÃ­fica de lapso
function sugerirFechaLapso(buttonElement, tipo) {
    const lapsoItem = buttonElement.closest('.lapso-item');
    const fechaInput = tipo === 'inicio' 
        ? lapsoItem.querySelector('.lapso-fecha-inicio') 
        : lapsoItem.querySelector('.lapso-fecha-fin');
    
    const añoBase = new Date().getFullYear();
    const numeroLapso = parseInt(lapsoItem.querySelector('.lapso-numero').value) || 1;
    
    const fechasSugeridas = {
        inicio: [
            `${añoBase}-09-16`,
            `${añoBase + 1}-01-06`, 
            `${añoBase + 1}-04-14`,
            `${añoBase + 1}-07-22`,
            `${añoBase + 1}-10-20`
        ],
        fin: [
            `${añoBase + 1}-01-13`,
            `${añoBase + 1}-04-04`,
            `${añoBase + 1}-07-15`,
            `${añoBase + 1}-10-15`,
            `${añoBase + 2}-01-20`
        ]
    };
    
    const fechaIndex = Math.min(numeroLapso - 1, 4);
    fechaInput.value = fechasSugeridas[tipo][fechaIndex];
}

// FunciÃ³n para eliminar lapso
function eliminarLapso(boton) {
    const lapsoItem = boton.closest('.lapso-item');
    if (document.querySelectorAll('.lapso-item').length > 1) {
        lapsoItem.remove();
        actualizarContadorLapsos();
        reindexarLapsos();
    } else {
        alert('Debe haber al menos un lapso');
    }
}

// FunciÃ³n para reindexar lapsos despuÃ©s de eliminar
function reindexarLapsos() {
    const lapsos = document.querySelectorAll('.lapso-item');
    lapsos.forEach((lapso, index) => {
        // Actualizar nombres de los campos
        const inputs = lapso.querySelectorAll('input, select, textarea');
        inputs.forEach(input => {
            const name = input.getAttribute('name');
            if (name && name.includes('lapsos[')) {
                const newName = name.replace(/lapsos\[\d+\]/, `lapsos[${index}]`);
                input.setAttribute('name', newName);
            }
        });
        
        // Actualizar nÃºmero del lapso
        const numeroInput = lapso.querySelector('.lapso-numero');
        if (numeroInput) {
            numeroInput.value = index + 1;
        }
        
        // Actualizar nombre sugerido
        const nombreInput = lapso.querySelector('.lapso-nombre');
        if (nombreInput && !nombreInput.value.startsWith('Lapso Especial')) {
            const nombresSugeridos = ['Primer Lapso', 'Segundo Lapso', 'Tercer Lapso', 'Cuarto Lapso', 'Quinto Lapso'];
            nombreInput.value = nombresSugeridos[index] || `Lapso ${index + 1}`;
        }
    });
}

// FunciÃ³n para actualizar contador de lapsos
function actualizarContadorLapsos() {
    const contador = document.getElementById('contador-lapsos');
    const totalLapsos = document.querySelectorAll('.lapso-item').length;
    contador.textContent = totalLapsos;
}

// FunciÃ³n para limpiar el formulario
function limpiarFormulario() {
    if (confirm('Â¿EstÃ¡ seguro de que desea limpiar todo el formulario? Se perderÃ¡n todos los datos ingresados.')) {
        document.getElementById('formAnoEscolar').reset();
        document.getElementById('campoPersonalizado').style.display = 'none';
        
        // Restaurar lapsos por defecto
        const container = document.getElementById('lapsos-container');
        container.innerHTML = '';
        
        // Agregar los 3 lapsos por defecto
        for (let i = 0; i < 3; i++) {
            agregarLapso();
        }
        
        actualizarContadorLapsos();
    }
}

// Validar formulario antes de enviar
document.getElementById('formAnoEscolar').addEventListener('submit', function(e) {
    const selectNombre = document.getElementById('nombre');
    const inputPersonalizado = document.getElementById('nombre_personalizado');
    
    if (selectNombre.value === 'personalizado') {
        if (!inputPersonalizado.value.trim()) {
            e.preventDefault();
            alert('Por favor ingrese el nombre personalizado del año escolar');
            inputPersonalizado.focus();
            return false;
        }
    }
    
    // Validar que fecha fin sea posterior a fecha inicio
    const fechaInicio = document.getElementById('fecha_inicio').value;
    const fechaFin = document.getElementById('fecha_fin').value;
    
    if (fechaInicio && fechaFin && fechaFin <= fechaInicio) {
        e.preventDefault();
        alert('La fecha de fin debe ser posterior a la fecha de inicio');
        return false;
    }
    
    // Validar que haya al menos un lapso
    const totalLapsos = document.querySelectorAll('.lapso-item').length;
    if (totalLapsos < 1) {
        e.preventDefault();
        alert('Debe definir al menos un lapso');
        return false;
    }
    
    // Validar nÃºmeros de lapso Ãºnicos
    const numerosLapsos = new Set();
    const numeroInputs = document.querySelectorAll('.lapso-numero');
    let numerosUnicos = true;
    
    numeroInputs.forEach(input => {
        const numero = parseInt(input.value);
        if (numerosLapsos.has(numero)) {
            numerosUnicos = false;
            input.focus();
        }
        numerosLapsos.add(numero);
    });
    
    if (!numerosUnicos) {
        e.preventDefault();
        alert('Los nÃºmeros de lapso deben ser Ãºnicos');
        return false;
    }
    
    return true;
});

// Inicializar el formulario
document.addEventListener('DOMContentLoaded', function() {
    actualizarFechas();
    actualizarContadorLapsos();
    
    // Inicializar el estado de los proyectos
    document.querySelectorAll('.proyecto-obligatorio').forEach(select => {
        toggleProyectoNombre(select);
    });
});
</script>

<?php include '../../includes/footer.php'; ?>
