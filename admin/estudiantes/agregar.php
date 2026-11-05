<?php
// Archivo: agregar.php
require_once '../../includes/auth.php';
require_once '../../includes/database.php';
require_once '../../includes/validaciones.php';
checkAuth();
checkRole(['admin']);

$mensaje = '';
$tipo_mensaje = '';
$mostrar_formulario_completo = false;
$representante_existente = null;
$cedula_rep_actual = '';

// Generar cÃ©dula escolar automÃ¡tica
function generarCedulaEscolar($pdo) {
    $year = date('Y');
    $stmt = $pdo->prepare("SELECT COUNT(*) as total FROM estudiantes WHERE cedula_escolar LIKE ?");
    $stmt->execute(["EST{$year}%"]);
    $result = $stmt->fetch();
    $numero = $result['total'] + 1;
    return "EST{$year}" . str_pad($numero, 3, '0', STR_PAD_LEFT);
}

// FunciÃ³n para buscar representante existente
function buscarRepresentante($pdo, $cedula) {
    $stmt = $pdo->prepare("SELECT * FROM representantes WHERE cedula = ?");
    $stmt->execute([$cedula]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

// FunciÃ³n para obtener el año escolar activo
function obtenerAnoEscolarActivo($pdo) {
    $stmt = $pdo->prepare("SELECT id, nombre FROM años_escolares WHERE estado = 'activo' LIMIT 1");
    $stmt->execute();
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

// FunciÃ³n para obtener secciones disponibles para un grado
function obtenerSeccionesDisponibles($pdo, $ano_escolar_id, $grado) {
    $stmt = $pdo->prepare("
        SELECT s.id, s.nombre, s.capacidad, 
               COUNT(i.id) as estudiantes_inscritos,
               (s.capacidad - COUNT(i.id)) as cupos_disponibles,
               p.nombre as profesor_nombre, p.apellido as profesor_apellido
        FROM secciones s 
        LEFT JOIN inscripciones i ON s.id = i.seccion_id AND i.status = 'activo'
        LEFT JOIN profesores p ON s.profesor_id = p.id
        WHERE s.año_escolar_id = ? 
        AND s.nombre LIKE ? 
        AND s.nivel = 'primaria'
        GROUP BY s.id
        HAVING cupos_disponibles > 0 OR s.capacidad IS NULL
        ORDER BY s.nombre
    ");
    
    $likeGrado = $grado . '%';
    $stmt->execute([$ano_escolar_id, $likeGrado]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// FunciÃ³n para crear nueva secciÃ³n automÃ¡ticamente
function crearNuevaSeccion($pdo, $ano_escolar_id, $grado, $profesor_default_id = null) {
    try {
        // Obtener la Ãºltima letra usada para este grado
        $stmt = $pdo->prepare("
            SELECT nombre FROM secciones 
            WHERE año_escolar_id = ? AND nombre LIKE ?
            ORDER BY nombre DESC LIMIT 1
        ");
        $likeGrado = $grado . '%';
        $stmt->execute([$ano_escolar_id, $likeGrado]);
        $ultimaSeccion = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Determinar nueva letra
        $nuevaLetra = 'A';
        if ($ultimaSeccion) {
            $partes = explode(' ', $ultimaSeccion['nombre']);
            $ultimaLetra = end($partes);
            if (strlen($ultimaLetra) === 1 && ctype_alpha($ultimaLetra)) {
                $nuevaLetra = chr(ord($ultimaLetra) + 1);
                if ($nuevaLetra > 'Z') {
                    $nuevaLetra = 'A'; // Volver al inicio si se pasa de Z
                }
            }
        }
        
        $nombreSeccion = $grado . ' ' . $nuevaLetra;
        $capacidad = 25; // Capacidad por defecto
        
        // Buscar profesor disponible si no se proporciona uno
        if (!$profesor_default_id) {
            $stmt = $pdo->prepare("
                SELECT id FROM profesores 
                WHERE estado = 'activo' 
                AND especialidad LIKE '%primaria%' 
                LIMIT 1
            ");
            $stmt->execute();
            $profesor = $stmt->fetch(PDO::FETCH_ASSOC);
            $profesor_default_id = $profesor ? $profesor['id'] : null;
        }
        
        // Crear nueva secciÃ³n
        $stmt = $pdo->prepare("
            INSERT INTO secciones 
            (nombre, año_escolar_id, capacidad, nivel, tipo_grado, profesor_id) 
            VALUES (?, ?, ?, 'primaria', 'grado', ?)
        ");
        
        $stmt->execute([$nombreSeccion, $ano_escolar_id, $capacidad, $profesor_default_id]);
        $nuevaSeccionId = $pdo->lastInsertId();
        
        return [
            'id' => $nuevaSeccionId,
            'nombre' => $nombreSeccion,
            'capacidad' => $capacidad,
            'cupos_disponibles' => $capacidad,
            'nueva' => true
        ];
        
    } catch (Exception $e) {
        throw new Exception("Error al crear nueva secciÃ³n: " . $e->getMessage());
    }
}

// FunciÃ³n para inscribir estudiante en secciÃ³n
function inscribirEstudiante($pdo, $estudiante_id, $seccion_id, $ano_escolar_id) {
    $stmt = $pdo->prepare("
        INSERT INTO inscripciones 
        (estudiante_id, seccion_id, año_escolar_id, fecha_inscripcion, status) 
        VALUES (?, ?, ?, CURDATE(), 'activo')
    ");
    
    return $stmt->execute([$estudiante_id, $seccion_id, $ano_escolar_id]);
}

// Procesar bÃºsqueda inicial de representante
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['buscar_representante'])) {
    $cedula_rep = trim($_POST['representante_cedula'] ?? '');
    $cedula_rep_actual = $cedula_rep;
    
    if (empty($cedula_rep)) {
        $mensaje = "Por favor ingrese la cÃ©dula del representante";
        $tipo_mensaje = "warning";
    } elseif (!preg_match('/^[0-9]{6,20}$/', $cedula_rep)) {
        $mensaje = "La cÃ©dula debe contener solo nÃºmeros (6-20 dÃ­gitos)";
        $tipo_mensaje = "danger";
    } else {
        $representante_existente = buscarRepresentante($pdo, $cedula_rep);
        
        if ($representante_existente) {
            $mensaje = "Representante encontrado: " . $representante_existente['nombre'] . " " . $representante_existente['apellido'];
            $tipo_mensaje = "success";
            $mostrar_formulario_completo = true;
        } else {
            $mensaje = "CÃ©dula no encontrada. Complete los datos del nuevo representante.";
            $tipo_mensaje = "info";
            $mostrar_formulario_completo = true;
        }
    }
}

// Procesar formulario completo (cuando el representante ya estÃ¡ verificado)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['guardar_estudiante'])) {
    try {
        $validador = new Validador($_POST);
        $validador->texto('representante_nombre', 'Nombre del representante', 2, 100, true);
        $validador->texto('representante_apellido', 'Apellido del representante', 2, 100, true);
        $validador->cedula('representante_cedula', 'CÃ©dula del representante', 6, 20, true);
        $validador->texto('representante_profesion', 'ProfesiÃ³n', 1, 100, false);
        $validador->texto('representante_lugar_trabajo', 'Lugar de trabajo', 1, 255, false);
        $validador->telefono('representante_telefono_numero', 'TelÃ©fono del representante', true);
        $validador->texto('nombre', 'Nombre del estudiante', 2, 100, true);
        $validador->texto('apellido', 'Apellido del estudiante', 2, 100, true);
        $validador->cedulaEscolar('cedula_escolar', 'CÃ©dula escolar', false);
        $validador->fecha('fecha_nacimiento', 'Fecha de nacimiento', false, true);
        $validador->texto('lugar_nacimiento', 'Lugar de nacimiento', 1, 100, false);
        $validador->texto('escuela_anterior', 'Escuela anterior', 1, 255, false);
        $validador->texto('habilidades_especiales', 'Habilidades especiales', 1, 500, false);

        if ($validador->hayErrores()) {
            throw new Exception($validador->getMensajeErrores());
        }

        if (mb_strlen($_POST['nombre'] ?? '') > 100) {
            throw new Exception("El nombre del estudiante no puede exceder 100 caracteres.");
        }
        if (mb_strlen($_POST['apellido'] ?? '') > 100) {
            throw new Exception("El apellido del estudiante no puede exceder 100 caracteres.");
        }
        if (!empty($_POST['lugar_nacimiento']) && !preg_match('/^[A-Za-zÁÉÍÓÚáéíóúÑñ\s\'.,-]+$/u', $_POST['lugar_nacimiento'])) {
            throw new Exception("El lugar de nacimiento solo admite letras.");
        }
        if (!empty($_POST['representante_profesion']) && !preg_match('/^[A-Za-zÁÉÍÓÚáéíóúÑñ\s\'.-]+$/u', $_POST['representante_profesion'])) {
            throw new Exception("La profesión del representante solo admite letras.");
        }
        if (mb_strlen($_POST['escuela_anterior'] ?? '') > 255) {
            throw new Exception("La escuela anterior no puede exceder 255 caracteres.");
        }

        $pdo->beginTransaction();
        
        // Obtener ID del representante (existente o nuevo)
        $representante_id = null;
        $cedula_rep = trim($_POST['representante_cedula']);
        $cedula_rep_actual = $cedula_rep;
        
        // Verificar si estamos usando representante existente
        $representante_existente = buscarRepresentante($pdo, $cedula_rep);
        
        if ($representante_existente) {
            // Usar representante existente
            $representante_id = $representante_existente['id'];
        } else {
            // Validar campos obligatorios para nuevo representante
            $campos_obligatorios_rep = [
                'representante_nombre', 
                'representante_apellido', 
                'representante_parentesco',
                'representante_telefono_codigo',
                'representante_telefono_numero'
            ];
            
            foreach ($campos_obligatorios_rep as $campo) {
                if (empty(trim($_POST[$campo] ?? ''))) {
                    throw new Exception("El campo {$campo} del representante es obligatorio");
                }
            }
            
            // Validar telÃ©fono del representante
            $telefono_rep = $_POST['representante_telefono_codigo'] . $_POST['representante_telefono_numero'];
            if (!preg_match('/^[0-9]{11}$/', $telefono_rep)) {
                throw new Exception("El telÃ©fono del representante debe tener 11 dÃ­gitos");
            }
            
            // Insertar nuevo representante
            $stmt = $pdo->prepare("INSERT INTO representantes 
                (cedula, nombre, apellido, fecha_nacimiento, genero, parentesco, profesion, lugar_trabajo, telefono, telefono_alternativo, email, direccion) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            
            $stmt->execute([
                $cedula_rep,
                trim($_POST['representante_nombre']),
                trim($_POST['representante_apellido']),
                $_POST['representante_fecha_nacimiento'] ?: null,
                $_POST['representante_genero'] ?: null,
                $_POST['representante_parentesco'],
                trim($_POST['representante_profesion'] ?? ''),
                trim($_POST['representante_lugar_trabajo'] ?? ''),
                $telefono_rep,
                trim($_POST['representante_telefono_alternativo'] ?? ''),
                trim($_POST['representante_email'] ?? ''),
                trim($_POST['representante_direccion'] ?? '')
            ]);
            
            $representante_id = $pdo->lastInsertId();
            $representante_existente = buscarRepresentante($pdo, $cedula_rep); // Recargar datos
        }
        
        // VALIDAR DATOS DEL ESTUDIANTE
        if (empty($_POST['nombre']) || empty($_POST['apellido'])) {
            throw new Exception("El nombre y apellido del estudiante son obligatorios");
        }
        
        // Validar documentos presentados
        if (empty($_POST['documentos_presentados']) || !is_array($_POST['documentos_presentados'])) {
            throw new Exception("Debe seleccionar al menos un documento presentado");
        }
        
        // Generar cÃ©dula escolar si no se proporciona
        $cedula_escolar = trim($_POST['cedula_escolar']);
        if (empty($cedula_escolar)) {
            $cedula_escolar = generarCedulaEscolar($pdo);
        }
        
        // Validar formato de cÃ©dula escolar
        if (!preg_match('/^[A-Z0-9]{3,20}$/', $cedula_escolar)) {
            throw new Exception("La cÃ©dula escolar debe contener solo letras y nÃºmeros (3-20 caracteres)");
        }
        
        // Verificar si la cÃ©dula escolar ya existe
        $stmt = $pdo->prepare("SELECT id FROM estudiantes WHERE cedula_escolar = ?");
        $stmt->execute([$cedula_escolar]);
        if ($stmt->fetch()) {
            throw new Exception("La cÃ©dula escolar ya existe. Por favor use otra.");
        }
        
        // Insertar estudiante
        $stmt = $pdo->prepare("INSERT INTO estudiantes 
            (cedula_escolar, nombre, apellido, fecha_nacimiento, genero, lugar_nacimiento, direccion, telefono, email, escuela_anterior, enfermedades, alergias, medicamentos, observaciones, representante_id, tipo_vivienda, personas_conviven, habilidades_especiales, documentos_presentados) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        
        $stmt->execute([
            $cedula_escolar,
            trim($_POST['nombre']),
            trim($_POST['apellido']),
            $_POST['fecha_nacimiento'] ?: null,
            $_POST['genero'] ?: null,
            trim($_POST['lugar_nacimiento'] ?? ''),
            trim($_POST['direccion'] ?? ''),
            trim($_POST['telefono'] ?? ''),
            trim($_POST['email'] ?? ''),
            trim($_POST['escuela_anterior'] ?? ''),
            trim($_POST['enfermedades'] ?? ''),
            trim($_POST['alergias'] ?? ''),
            trim($_POST['medicamentos'] ?? ''),
            trim($_POST['observaciones'] ?? ''),
            $representante_id,
            $_POST['tipo_vivienda'] ?? '',
            $_POST['personas_conviven'] ?? '',
            trim($_POST['habilidades_especiales'] ?? ''),
            implode(',', $_POST['documentos_presentados'] ?? [])
        ]);
        
        $estudiante_id = $pdo->lastInsertId();
        
        // PROCESAR INSCRIPCIÃ“N AUTOMÃTICA EN SECCIÃ“N
        $grado_seleccionado = $_POST['grado_inscripcion'] ?? '1er Grado';
        $ano_escolar_activo = obtenerAnoEscolarActivo($pdo);
        
        if (!$ano_escolar_activo) {
            throw new Exception("No hay año escolar activo. Configure un año escolar primero.");
        }
        
        // Buscar secciones disponibles para el grado seleccionado
        $secciones_disponibles = obtenerSeccionesDisponibles($pdo, $ano_escolar_activo['id'], $grado_seleccionado);
        $seccion_inscripcion = null;
        
        if (count($secciones_disponibles) > 0) {
            // Usar la primera secciÃ³n disponible
            $seccion_inscripcion = $secciones_disponibles[0];
            $info_inscripcion = "SecciÃ³n existente: {$seccion_inscripcion['nombre']}";
        } else {
            // Crear nueva secciÃ³n automÃ¡ticamente
            $seccion_inscripcion = crearNuevaSeccion($pdo, $ano_escolar_activo['id'], $grado_seleccionado);
            $info_inscripcion = "Nueva secciÃ³n creada: {$seccion_inscripcion['nombre']}";
        }
        
        // Inscribir estudiante en la secciÃ³n
        $inscripcion_exitosa = inscribirEstudiante(
            $pdo, 
            $estudiante_id, 
            $seccion_inscripcion['id'], 
            $ano_escolar_activo['id']
        );
        
        if (!$inscripcion_exitosa) {
            throw new Exception("Error al inscribir estudiante en la secciÃ³n");
        }
        
        // Procesar representantes adicionales si existen
        if (isset($_POST['representantes_adicionales']) && is_array($_POST['representantes_adicionales'])) {
            foreach ($_POST['representantes_adicionales'] as $rep_adicional) {
                if (!empty($rep_adicional['cedula']) && !empty($rep_adicional['nombre'])) {
                    // Verificar si el representante adicional ya existe
                    $rep_existente = buscarRepresentante($pdo, $rep_adicional['cedula']);
                    $rep_adicional_id = null;
                    
                    if (!$rep_existente) {
                        // Insertar nuevo representante adicional
                        $stmt_rep = $pdo->prepare("INSERT INTO representantes 
                            (cedula, nombre, apellido, parentesco, telefono, email, direccion) 
                            VALUES (?, ?, ?, ?, ?, ?, ?)");
                        
                        $telefono_completo = $rep_adicional['telefono_codigo'] . $rep_adicional['telefono_numero'];
                        
                        $stmt_rep->execute([
                            $rep_adicional['cedula'],
                            trim($rep_adicional['nombre']),
                            trim($rep_adicional['apellido']),
                            $rep_adicional['parentesco'],
                            $telefono_completo,
                            trim($rep_adicional['email'] ?? ''),
                            trim($rep_adicional['direccion'] ?? '')
                        ]);
                        
                        $rep_adicional_id = $pdo->lastInsertId();
                    } else {
                        $rep_adicional_id = $rep_existente['id'];
                    }
                    
                    // Insertar relaciÃ³n estudiante-representante adicional
                    $stmt_rel = $pdo->prepare("INSERT INTO estudiantes_representantes 
                        (estudiante_id, representante_id, parentesco, es_principal) 
                        VALUES (?, ?, ?, ?)");
                    
                    $stmt_rel->execute([
                        $estudiante_id,
                        $rep_adicional_id,
                        $rep_adicional['parentesco'],
                        0 // No es el representante principal
                    ]);
                }
            }
        }

        // Insertar el representante principal en la tabla de relaciÃ³n
        $stmt_principal = $pdo->prepare("INSERT INTO estudiantes_representantes 
            (estudiante_id, representante_id, parentesco, es_principal) 
            VALUES (?, ?, ?, ?)");
            
        $stmt_principal->execute([
            $estudiante_id,
            $representante_id,
            $_POST['representante_parentesco'] ?? 'madre',
            1 // Es el representante principal
        ]);
        
        $pdo->commit();
        
        $mensaje = "âœ… Estudiante agregado e inscrito correctamente.<br>
                   <strong>CÃ©dula escolar:</strong> {$cedula_escolar}<br>
                   <strong>Estudiante:</strong> {$_POST['nombre']} {$_POST['apellido']}<br>
                   <strong>Grado:</strong> {$grado_seleccionado}<br>
                   <strong>SecciÃ³n:</strong> {$seccion_inscripcion['nombre']}<br>
                   <strong>Representante:</strong> " . ($representante_existente ? $representante_existente['nombre'] . ' ' . $representante_existente['apellido'] : $_POST['representante_nombre'] . ' ' . $_POST['representante_apellido']);
        $tipo_mensaje = "success";
        
        // Limpiar campos para nuevo registro
        $_POST = [];
        $mostrar_formulario_completo = false;
        $cedula_rep_actual = '';
        
    } catch (Exception $e) {
        $pdo->rollBack();
        $mensaje = "âŒ Error: " . $e->getMessage();
        $tipo_mensaje = "danger";
        $mostrar_formulario_completo = true;
    }
}

// Obtener cÃ©dula sugerida
$cedula_sugerida = generarCedulaEscolar($pdo);

// Obtener año escolar activo para mostrar informaciÃ³n
$ano_escolar_activo = obtenerAnoEscolarActivo($pdo);

// Opciones predefinidas para comboboxes
$codigos_telefonicos = ['0412', '0414', '0416', '0424', '0426'];
$tipos_vivienda = ['Propia', 'Alquilada', 'Familiar', 'Otro'];
$grados_disponibles = ['1er Grado', '2do Grado', '3er Grado', '4to Grado', '5to Grado', '6to Grado'];
$documentos_requeridos = [
    'acta_nacimiento' => 'Acta de Nacimiento',
    'cedula_representante' => 'CÃ©dula del Representante',
    'fotos' => 'Fotos Tipo Carnet',
    'certificado_medico' => 'Certificado MÃ©dico',
    'boleta_anterior' => 'Boleta del Año Anterior',
    'partida_bautismo' => 'Partida de Bautismo'
];
?>

<?php include '../../includes/header.php'; ?>

<div class="content-wrapper" style="padding: 20px;">
    <?php include '../../includes/sidebar_admin.php'; ?>
    
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2>Agregar Nuevo Estudiante</h2>
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

    <!-- Paso 1: BÃºsqueda del Representante -->
    <?php if (!$mostrar_formulario_completo): ?>
    <div class="card mb-4">
        <div class="card-header bg-primary text-white">
            <h5 class="card-title mb-0">
                <i class="fas fa-search me-2"></i>Paso 1: Buscar Representante Principal
            </h5>
        </div>
        <div class="card-body">
            <div class="row">
                <div class="col-md-8">
                    <p class="text-muted">
                        <i class="fas fa-info-circle me-2"></i>
                        Ingrese la cÃ©dula del representante principal. Si ya existe en el sistema, se cargarÃ¡n sus datos automÃ¡ticamente.
                    </p>
                </div>
            </div>
            <form method="POST" action="" id="formBuscarRepresentante">
                <div class="row">
                    <div class="col-md-8">
                        <label for="representante_cedula" class="form-label">CÃ©dula del Representante Principal *</label>
                        <input type="text" class="form-control" id="representante_cedula" name="representante_cedula" data-tipo="cedula"
                               value="<?php echo htmlspecialchars($cedula_rep_actual); ?>"
                               maxlength="20" pattern="[0-9]+" required
                               placeholder="Ingrese la cÃ©dula del representante (solo nÃºmeros)">
                        <div class="form-text">Solo nÃºmeros, sin puntos ni espacios</div>
                    </div>
                    <div class="col-md-4 d-flex align-items-end">
                        <button type="submit" name="buscar_representante" class="btn btn-primary w-100">
                            <i class="fas fa-search me-2"></i>Buscar Representante
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>
    <?php endif; ?>

    <!-- Paso 2: Formulario Completo (solo se muestra despuÃ©s de buscar representante) -->
    <?php if ($mostrar_formulario_completo): ?>
    <form method="POST" action="" id="formEstudiante">
        <input type="hidden" name="representante_cedula" value="<?php echo htmlspecialchars($cedula_rep_actual); ?>">
        
        <!-- InformaciÃ³n del Año Escolar y Grado -->
        <div class="card mb-4">
            <div class="card-header bg-success text-white">
                <h5 class="card-title mb-0">
                    <i class="fas fa-calendar-alt me-2"></i>InscripciÃ³n Escolar
                </h5>
            </div>
            <div class="card-body">
                <?php if ($ano_escolar_activo): ?>
                    <div class="alert alert-info">
                        <div class="row">
                            <div class="col-md-6">
                                <span id="ano-escolar-info">
                                    <strong>Año Escolar Activo:</strong> <?php echo htmlspecialchars($ano_escolar_activo['nombre']); ?>
                                </span>
                            </div>
                            <div class="col-md-6">
                                <strong>Sistema:</strong> InscripciÃ³n AutomÃ¡tica en Primera SecciÃ³n Disponible
                            </div>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="grado_inscripcion" class="form-label">Grado a Inscribir *</label>
                            <select class="form-select" id="grado_inscripcion" name="grado_inscripcion" required>
                                <option value="">Seleccionar grado...</option>
                                <?php foreach ($grados_disponibles as $grado): ?>
                                    <option value="<?php echo $grado; ?>" 
                                        <?php echo ($_POST['grado_inscripcion'] ?? '1er Grado') == $grado ? 'selected' : ''; ?>>
                                        <?php echo $grado; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <div class="form-text">
                                El estudiante serÃ¡ inscrito automÃ¡ticamente en la primera secciÃ³n disponible de este grado
                            </div>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">InformaciÃ³n de Secciones en Tiempo Real</label>
                            <div id="info_secciones" class="form-control" style="background-color: #f8f9fa; min-height: 80px; max-height: 200px; overflow-y: auto;">
                                <small class="text-muted">
                                    Seleccione un grado para ver las secciones disponibles...
                                </small>
                            </div>
                            <div class="form-text">
                                <i class="fas fa-sync-alt me-1"></i>La informaciÃ³n se actualiza automÃ¡ticamente
                            </div>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="alert alert-warning">
                        <i class="fas fa-exclamation-triangle me-2"></i>
                        <strong>No hay año escolar activo.</strong> 
                        <a href="../años_escolares/gestionar.php" class="alert-link">Configure un año escolar activo</a> 
                        antes de inscribir estudiantes.
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- InformaciÃ³n del Representante Principal -->
        <div class="card mb-4">
            <div class="card-header bg-info text-white">
                <h5 class="card-title mb-0">
                    <i class="fas fa-user-friends me-2"></i>Datos del Representante Principal
                    <?php if ($representante_existente): ?>
                        <span class="badge bg-success float-end">Representante Existente</span>
                    <?php else: ?>
                        <span class="badge bg-warning float-end">Nuevo Representante</span>
                    <?php endif; ?>
                </h5>
            </div>
            <div class="card-body">
                <?php if ($representante_existente): ?>
                    <div class="alert alert-success">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <i class="fas fa-check-circle me-2"></i>
                                <strong>Representante encontrado en el sistema</strong>
                            </div>
                            <button type="button" class="btn btn-sm btn-outline-success" onclick="volverABuscar()">
                                <i class="fas fa-search me-1"></i>Buscar Otro
                            </button>
                        </div>
                        <hr>
                        <div class="row">
                            <div class="col-md-3">
                                <strong>Nombre:</strong><br>
                                <?php echo htmlspecialchars($representante_existente['nombre'] . ' ' . $representante_existente['apellido']); ?>
                            </div>
                            <div class="col-md-2">
                                <strong>CÃ©dula:</strong><br>
                                <?php echo htmlspecialchars($representante_existente['cedula']); ?>
                            </div>
                            <div class="col-md-2">
                                <strong>Parentesco:</strong><br>
                                <?php echo ucfirst($representante_existente['parentesco']); ?>
                            </div>
                            <div class="col-md-3">
                                <strong>TelÃ©fono:</strong><br>
                                <?php echo htmlspecialchars($representante_existente['telefono']); ?>
                            </div>
                            <div class="col-md-2">
                                <strong>Email:</strong><br>
                                <?php echo htmlspecialchars($representante_existente['email'] ?? 'No especificado'); ?>
                            </div>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="alert alert-warning">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <i class="fas fa-exclamation-triangle me-2"></i>
                                <strong>Representante no encontrado</strong> - Complete los datos del nuevo representante
                            </div>
                            <button type="button" class="btn btn-sm btn-outline-warning" onclick="volverABuscar()">
                                <i class="fas fa-search me-1"></i>Buscar Otro
                            </button>
                        </div>
                    </div>
                    
                    <!-- Campos para nuevo representante principal -->
                    <div class="row">
                        <div class="col-md-4 mb-3">
                            <label for="representante_nombre" class="form-label">Nombre del Representante *</label>
                            <input type="text" class="form-control" id="representante_nombre" name="representante_nombre"
                                   value="<?php echo htmlspecialchars($_POST['representante_nombre'] ?? ''); ?>"
                                   maxlength="100" pattern="[A-Za-zÃÃ‰ÃÃ“ÃšÃ¡Ã©Ã­Ã³ÃºÃ‘ñ\s]+" required
                                   placeholder="Ej: Carmen">
                        </div>
                        
                        <div class="col-md-4 mb-3">
                            <label for="representante_apellido" class="form-label">Apellido del Representante *</label>
                            <input type="text" class="form-control" id="representante_apellido" name="representante_apellido"
                                   value="<?php echo htmlspecialchars($_POST['representante_apellido'] ?? ''); ?>"
                                   maxlength="100" pattern="[A-Za-zÃÃ‰ÃÃ“ÃšÃ¡Ã©Ã­Ã³ÃºÃ‘ñ\s]+" required
                                   placeholder="Ej: RodrÃ­guez">
                        </div>
                        
                        <div class="col-md-4 mb-3">
                            <label for="representante_parentesco" class="form-label">Parentesco *</label>
                            <select class="form-select" id="representante_parentesco" name="representante_parentesco" required>
                                <option value="">Seleccionar...</option>
                                <option value="madre" <?php echo ($_POST['representante_parentesco'] ?? '') == 'madre' ? 'selected' : ''; ?>>Madre</option>
                                <option value="padre" <?php echo ($_POST['representante_parentesco'] ?? '') == 'padre' ? 'selected' : ''; ?>>Padre</option>
                                <option value="abuelo" <?php echo ($_POST['representante_parentesco'] ?? '') == 'abuelo' ? 'selected' : ''; ?>>Abuelo</option>
                                <option value="abuela" <?php echo ($_POST['representante_parentesco'] ?? '') == 'abuela' ? 'selected' : ''; ?>>Abuela</option>
                                <option value="tio" <?php echo ($_POST['representante_parentesco'] ?? '') == 'tio' ? 'selected' : ''; ?>>TÃ­o</option>
                                <option value="tia" <?php echo ($_POST['representante_parentesco'] ?? '') == 'tia' ? 'selected' : ''; ?>>TÃ­a</option>
                                <option value="hermano" <?php echo ($_POST['representante_parentesco'] ?? '') == 'hermano' ? 'selected' : ''; ?>>Hermano</option>
                                <option value="hermana" <?php echo ($_POST['representante_parentesco'] ?? '') == 'hermana' ? 'selected' : ''; ?>>Hermana</option>
                                <option value="otro" <?php echo ($_POST['representante_parentesco'] ?? '') == 'otro' ? 'selected' : ''; ?>>Otro</option>
                            </select>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-3 mb-3">
                            <label for="representante_telefono_codigo" class="form-label">TelÃ©fono Principal *</label>
                            <div class="input-group">
                                <select class="form-select" id="representante_telefono_codigo" name="representante_telefono_codigo" style="max-width: 100px;" required>
                                    <option value="">CÃ³digo</option>
                                    <?php foreach ($codigos_telefonicos as $codigo): ?>
                                        <option value="<?php echo $codigo; ?>" <?php echo ($_POST['representante_telefono_codigo'] ?? '') == $codigo ? 'selected' : ''; ?>>
                                            <?php echo $codigo; ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <input type="text" class="form-control" id="representante_telefono_numero" name="representante_telefono_numero" data-tipo="numero"
                                       value="<?php echo htmlspecialchars($_POST['representante_telefono_numero'] ?? ''); ?>"
                                       maxlength="7" pattern="[0-9]+" required
                                       placeholder="1234567">
                            </div>
                            <div class="form-text">Ej: 0412-1234567</div>
                        </div>
                        
                        <div class="col-md-3 mb-3">
                            <label for="representante_telefono_alternativo" class="form-label">TelÃ©fono Alternativo</label>
                            <div class="input-group">
                                <select class="form-select" name="representante_telefono_alternativo_codigo" style="max-width: 100px;">
                                    <option value="">CÃ³digo</option>
                                    <?php foreach ($codigos_telefonicos as $codigo): ?>
                                        <option value="<?php echo $codigo; ?>"><?php echo $codigo; ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <input type="text" class="form-control" name="representante_telefono_alternativo_numero"
                                       maxlength="7" pattern="[0-9]+"
                                       placeholder="7654321">
                            </div>
                        </div>
                        
                        <div class="col-md-6 mb-3">
                            <label for="representante_email" class="form-label">Email del Representante</label>
                            <input type="email" class="form-control" id="representante_email" name="representante_email"
                                   value="<?php echo htmlspecialchars($_POST['representante_email'] ?? ''); ?>"
                                   maxlength="255"
                                   placeholder="Ej: representante@ejemplo.com">
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="representante_profesion" class="form-label">ProfesiÃ³n</label>
                            <input type="text" class="form-control" id="representante_profesion" name="representante_profesion" data-tipo="texto"
                                   value="<?php echo htmlspecialchars($_POST['representante_profesion'] ?? ''); ?>"
                                   maxlength="100" pattern="[A-Za-zÁÉÍÓÚáéíóúÑñ\s'.-]+"
                                   placeholder="Ej: Ingeniero">
                        </div>
                        
                        <div class="col-md-6 mb-3">
                            <label for="representante_lugar_trabajo" class="form-label">Lugar de Trabajo</label>
                            <input type="text" class="form-control" id="representante_lugar_trabajo" name="representante_lugar_trabajo" data-tipo="texto-largo"
                                   value="<?php echo htmlspecialchars($_POST['representante_lugar_trabajo'] ?? ''); ?>"
                                   maxlength="255"
                                   placeholder="Ej: Empresa XYZ">
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="representante_fecha_nacimiento" class="form-label">Fecha de Nacimiento</label>
                            <input type="date" class="form-control" id="representante_fecha_nacimiento" name="representante_fecha_nacimiento"
                                   value="<?php echo htmlspecialchars($_POST['representante_fecha_nacimiento'] ?? ''); ?>">
                        </div>
                        
                        <div class="col-md-6 mb-3">
                            <label for="representante_genero" class="form-label">GÃ©nero</label>
                            <select class="form-select" id="representante_genero" name="representante_genero">
                                <option value="">Seleccionar...</option>
                                <option value="masculino" <?php echo ($_POST['representante_genero'] ?? '') == 'masculino' ? 'selected' : ''; ?>>Masculino</option>
                                <option value="femenino" <?php echo ($_POST['representante_genero'] ?? '') == 'femenino' ? 'selected' : ''; ?>>Femenino</option>
                            </select>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label for="representante_direccion" class="form-label">DirecciÃ³n del Representante</label>
                        <textarea class="form-control" id="representante_direccion" name="representante_direccion" rows="2"
                                  maxlength="500" placeholder="DirecciÃ³n completa del representante"><?php echo htmlspecialchars($_POST['representante_direccion'] ?? ''); ?></textarea>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Representantes Adicionales -->
        <div class="card mb-4">
            <div class="card-header bg-secondary text-white">
                <h5 class="card-title mb-0">
                    <i class="fas fa-users me-2"></i>Representantes Adicionales (Opcional)
                </h5>
            </div>
            <div class="card-body">
                <div id="representantes_adicionales_container">
                    <p class="text-muted">Puede agregar representantes adicionales como padre, madre, abuelos, etc.</p>
                </div>
                <button type="button" class="btn btn-outline-primary mt-2" onclick="agregarRepresentanteAdicional()">
                    <i class="fas fa-plus me-2"></i>Agregar Representante Adicional
                </button>
            </div>
        </div>

        <!-- SecciÃ³n Datos del Estudiante -->
        <div class="card mb-4">
            <div class="card-header bg-primary text-white">
                <h5 class="card-title mb-0">
                    <i class="fas fa-user-graduate me-2"></i>Datos del Estudiante
                </h5>
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label for="cedula_escolar" class="form-label">CÃ©dula Escolar *</label>
                        <input type="text" class="form-control" id="cedula_escolar" name="cedula_escolar" data-tipo="cedula-escolar"
                               value="<?php echo htmlspecialchars($_POST['cedula_escolar'] ?? $cedula_sugerida); ?>"
                               maxlength="20" pattern="[A-Za-z0-9]+" required
                               placeholder="Se generarÃ¡ automÃ¡ticamente">
                        <div class="form-text">Identificador Ãºnico del estudiante</div>
                    </div>
                    
                    <div class="col-md-6 mb-3">
                        <label for="fecha_nacimiento" class="form-label">Fecha de Nacimiento</label>
                        <input type="date" class="form-control" id="fecha_nacimiento" name="fecha_nacimiento" data-tipo="fecha" data-max-hoy="true"
                               value="<?php echo htmlspecialchars($_POST['fecha_nacimiento'] ?? ''); ?>"
                               onchange="calcularEdad()">
                        <div class="form-text" id="edad_texto"></div>
                    </div>
                </div>
                
                <div class="row">
                    <div class="col-md-4 mb-3">
                        <label for="nombre" class="form-label">Nombre *</label>
                        <input type="text" class="form-control" id="nombre" name="nombre" data-tipo="texto" required
                               value="<?php echo htmlspecialchars($_POST['nombre'] ?? ''); ?>"
                               maxlength="100" pattern="[A-Za-zÃÃ‰ÃÃ“ÃšÃ¡Ã©Ã­Ã³ÃºÃ‘ñ\s]+"
                               placeholder="Ej: MarÃ­a">
                    </div>
                    
                    <div class="col-md-4 mb-3">
                        <label for="apellido" class="form-label">Apellido *</label>
                        <input type="text" class="form-control" id="apellido" name="apellido" data-tipo="texto" required
                               value="<?php echo htmlspecialchars($_POST['apellido'] ?? ''); ?>"
                               maxlength="100" pattern="[A-Za-zÃÃ‰ÃÃ“ÃšÃ¡Ã©Ã­Ã³ÃºÃ‘ñ\s]+"
                               placeholder="Ej: GonzÃ¡lez">
                    </div>
                    
                    <div class="col-md-4 mb-3">
                        <label for="genero" class="form-label">GÃ©nero</label>
                        <select class="form-select" id="genero" name="genero">
                            <option value="">Seleccionar...</option>
                            <option value="masculino" <?php echo ($_POST['genero'] ?? '') == 'masculino' ? 'selected' : ''; ?>>Masculino</option>
                            <option value="femenino" <?php echo ($_POST['genero'] ?? '') == 'femenino' ? 'selected' : ''; ?>>Femenino</option>
                        </select>
                    </div>
                </div>
                
                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label for="lugar_nacimiento" class="form-label">Lugar de Nacimiento</label>
                        <input type="text" class="form-control" id="lugar_nacimiento" name="lugar_nacimiento" data-tipo="texto"
                               value="<?php echo htmlspecialchars($_POST['lugar_nacimiento'] ?? ''); ?>"
                               maxlength="100" pattern="[A-Za-zÁÉÍÓÚáéíóúÑñ\s'.,-]+"
                               placeholder="Ej: Caracas, Venezuela">
                    </div>
                    
                    <div class="col-md-6 mb-3">
                        <label for="telefono" class="form-label">TelÃ©fono del Estudiante</label>
                        <div class="input-group">
                            <select class="form-select" name="telefono_codigo" style="max-width: 100px;">
                                <option value="">CÃ³digo</option>
                                <?php foreach ($codigos_telefonicos as $codigo): ?>
                                    <option value="<?php echo $codigo; ?>"><?php echo $codigo; ?></option>
                                <?php endforeach; ?>
                                </select>
                                <input type="text" class="form-control" name="telefono_numero" data-tipo="numero"
                                       maxlength="7" pattern="[0-9]+"
                                       placeholder="1234567">
                        </div>
                    </div>
                </div>
                
                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label for="email" class="form-label">Email del Estudiante</label>
                        <input type="email" class="form-control" id="email" name="email"
                               value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>"
                               maxlength="255"
                               placeholder="Ej: estudiante@ejemplo.com">
                    </div>
                    
                    <div class="col-md-6 mb-3">
                        <label for="escuela_anterior" class="form-label">Escuela Anterior</label>
                        <input type="text" class="form-control" id="escuela_anterior" name="escuela_anterior" data-tipo="texto-largo"
                               value="<?php echo htmlspecialchars($_POST['escuela_anterior'] ?? ''); ?>"
                               maxlength="255"
                               placeholder="Ej: Escuela BÃ¡sica Nacional">
                    </div>
                </div>
                
                <div class="mb-3">
                    <label for="direccion" class="form-label">DirecciÃ³n del Estudiante</label>
                    <textarea class="form-control" id="direccion" name="direccion" rows="2"
                              maxlength="500" placeholder="DirecciÃ³n completa del estudiante"><?php echo htmlspecialchars($_POST['direccion'] ?? ''); ?></textarea>
                </div>

                <!-- InformaciÃ³n del Grupo Familiar -->
                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label for="tipo_vivienda" class="form-label">Tipo de Vivienda</label>
                        <select class="form-select" id="tipo_vivienda" name="tipo_vivienda">
                            <option value="">Seleccionar...</option>
                            <?php foreach ($tipos_vivienda as $tipo): ?>
                                <option value="<?php echo $tipo; ?>" <?php echo ($_POST['tipo_vivienda'] ?? '') == $tipo ? 'selected' : ''; ?>>
                                    <?php echo $tipo; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="col-md-6 mb-3">
                        <label for="personas_conviven" class="form-label">Personas que ConvÃ­ven en el Hogar</label>
                        <input type="number" class="form-control" id="personas_conviven" name="personas_conviven"
                               value="<?php echo htmlspecialchars($_POST['personas_conviven'] ?? ''); ?>"
                               min="1" max="20"
                               placeholder="Ej: 4">
                    </div>
                </div>

                <!-- Documentos Presentados -->
                <div class="mb-3">
                    <label class="form-label">Documentos Presentados *</label>
                    <div class="row">
                        <?php foreach ($documentos_requeridos as $key => $documento): ?>
                            <div class="col-md-4 mb-2">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" name="documentos_presentados[]"
                                           value="<?php echo $key; ?>" id="doc_<?php echo $key; ?>"
                                           <?php echo (isset($_POST['documentos_presentados']) && in_array($key, $_POST['documentos_presentados'])) ? 'checked' : ''; ?>>
                                    <label class="form-check-label" for="doc_<?php echo $key; ?>">
                                        <?php echo $documento; ?>
                                    </label>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- Habilidades Especiales -->
                <div class="mb-3">
                    <label for="habilidades_especiales" class="form-label">Habilidades Especiales o Talentos</label>
                    <textarea class="form-control" id="habilidades_especiales" name="habilidades_especiales" rows="3" data-tipo="textarea"
                              maxlength="500" placeholder="Describa cualquier habilidad especial, talento o destreza que posea el estudiante (mÃºsica, deportes, arte, etc.)"><?php echo htmlspecialchars($_POST['habilidades_especiales'] ?? ''); ?></textarea>
                </div>
                
                <!-- InformaciÃ³n MÃ©dica -->
                <div class="row">
                    <div class="col-md-4 mb-3">
                        <label for="enfermedades" class="form-label">Enfermedades o Condiciones</label>
                        <textarea class="form-control" id="enfermedades" name="enfermedades" rows="2"
                                  maxlength="500" placeholder="Enfermedades crÃ³nicas o condiciones especiales"><?php echo htmlspecialchars($_POST['enfermedades'] ?? ''); ?></textarea>
                    </div>
                    
                    <div class="col-md-4 mb-3">
                        <label for="alergias" class="form-label">Alergias</label>
                        <textarea class="form-control" id="alergias" name="alergias" rows="2"
                                  maxlength="500" placeholder="Alergias a medicamentos, alimentos, etc."><?php echo htmlspecialchars($_POST['alergias'] ?? ''); ?></textarea>
                    </div>
                    
                    <div class="col-md-4 mb-3">
                        <label for="medicamentos" class="form-label">Medicamentos</label>
                        <textarea class="form-control" id="medicamentos" name="medicamentos" rows="2"
                                  maxlength="500" placeholder="Medicamentos que toma regularmente"><?php echo htmlspecialchars($_POST['medicamentos'] ?? ''); ?></textarea>
                    </div>
                </div>
                
                <div class="mb-3">
                    <label for="observaciones" class="form-label">Observaciones Generales</label>
                    <textarea class="form-control" id="observaciones" name="observaciones" rows="3"
                              maxlength="1000" placeholder="Observaciones adicionales sobre el estudiante"><?php echo htmlspecialchars($_POST['observaciones'] ?? ''); ?></textarea>
                </div>
            </div>
        </div>

        <!-- Botones de acciÃ³n -->
        <div class="d-flex justify-content-between">
            <button type="button" class="btn btn-secondary" onclick="volverABuscar()">
                <i class="fas fa-arrow-left me-2"></i>Volver a BÃºsqueda
            </button>
            
            <div>
                <button type="reset" class="btn btn-outline-danger me-2">
                    <i class="fas fa-undo me-2"></i>Limpiar
                </button>
                <button type="submit" name="guardar_estudiante" class="btn btn-success">
                    <i class="fas fa-save me-2"></i>Guardar Estudiante
                </button>
            </div>
        </div>
    </form>
    <?php endif; ?>
</div>

<!-- Template para representantes adicionales (hidden) -->
<div id="template_representante_adicional" style="display: none;">
    <div class="representante-adicional card mb-3">
        <div class="card-header bg-light d-flex justify-content-between align-items-center">
            <h6 class="mb-0">Representante Adicional</h6>
            <button type="button" class="btn btn-sm btn-outline-danger" onclick="removerRepresentante(this)">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <div class="card-body">
            <div class="row">
                <div class="col-md-3 mb-2">
                    <label class="form-label">CÃ©dula *</label>
                    <input type="text" class="form-control" name="representantes_adicionales[][cedula]" 
                           maxlength="20" pattern="[0-9]+" required
                           placeholder="Solo nÃºmeros">
                </div>
                <div class="col-md-3 mb-2">
                    <label class="form-label">Nombre *</label>
                    <input type="text" class="form-control" name="representantes_adicionales[][nombre]" 
                           maxlength="100" pattern="[A-Za-zÃÃ‰ÃÃ“ÃšÃ¡Ã©Ã­Ã³ÃºÃ‘ñ\s]+" required
                           placeholder="Ej: JosÃ©">
                </div>
                <div class="col-md-3 mb-2">
                    <label class="form-label">Apellido *</label>
                    <input type="text" class="form-control" name="representantes_adicionales[][apellido]" 
                           maxlength="100" pattern="[A-Za-zÃÃ‰ÃÃ“ÃšÃ¡Ã©Ã­Ã³ÃºÃ‘ñ\s]+" required
                           placeholder="Ej: PÃ©rez">
                </div>
                <div class="col-md-3 mb-2">
                    <label class="form-label">Parentesco *</label>
                    <select class="form-select" name="representantes_adicionales[][parentesco]" required>
                        <option value="">Seleccionar...</option>
                        <option value="madre">Madre</option>
                        <option value="padre">Padre</option>
                        <option value="abuelo">Abuelo</option>
                        <option value="abuela">Abuela</option>
                        <option value="tio">TÃ­o</option>
                        <option value="tia">TÃ­a</option>
                        <option value="hermano">Hermano</option>
                        <option value="hermana">Hermana</option>
                        <option value="otro">Otro</option>
                    </select>
                </div>
            </div>
            <div class="row">
                <div class="col-md-4 mb-2">
                    <label class="form-label">TelÃ©fono *</label>
                    <div class="input-group">
                        <select class="form-select" name="representantes_adicionales[][telefono_codigo]" style="max-width: 100px;" required>
                            <option value="">CÃ³digo</option>
                            <?php foreach ($codigos_telefonicos as $codigo): ?>
                                <option value="<?php echo $codigo; ?>"><?php echo $codigo; ?></option>
                            <?php endforeach; ?>
                        </select>
                        <input type="text" class="form-control" name="representantes_adicionales[][telefono_numero]"
                               maxlength="7" pattern="[0-9]+" required
                               placeholder="1234567">
                    </div>
                </div>
                <div class="col-md-4 mb-2">
                    <label class="form-label">Email</label>
                    <input type="email" class="form-control" name="representantes_adicionales[][email]"
                           maxlength="255"
                           placeholder="ejemplo@email.com">
                </div>
                <div class="col-md-4 mb-2">
                    <label class="form-label">DirecciÃ³n</label>
                    <input type="text" class="form-control" name="representantes_adicionales[][direccion]"
                           maxlength="255"
                           placeholder="DirecciÃ³n completa">
                </div>
            </div>
        </div>
    </div>
</div>

<script>
// FunciÃ³n para volver a la bÃºsqueda inicial
function volverABuscar() {
    window.location.href = 'agregar.php';
}

// FunciÃ³n para calcular edad
function calcularEdad() {
    const fechaNacimiento = document.getElementById('fecha_nacimiento').value;
    if (fechaNacimiento) {
        const nacimiento = new Date(fechaNacimiento);
        const hoy = new Date();
        let edad = hoy.getFullYear() - nacimiento.getFullYear();
        const mes = hoy.getMonth() - nacimiento.getMonth();
        
        if (mes < 0 || (mes === 0 && hoy.getDate() < nacimiento.getDate())) {
            edad--;
        }
        
        document.getElementById('edad_texto').textContent = `Edad: ${edad} años`;
    } else {
        document.getElementById('edad_texto').textContent = '';
    }
}

// FunciÃ³n para agregar representante adicional
function agregarRepresentanteAdicional() {
    const container = document.getElementById('representantes_adicionales_container');
    const template = document.getElementById('template_representante_adicional');
    const nuevoRepresentante = template.cloneNode(true);
    
    nuevoRepresentante.style.display = 'block';
    nuevoRepresentante.classList.remove('d-none');
    
    container.appendChild(nuevoRepresentante);
}

// FunciÃ³n para remover representante adicional
function removerRepresentante(boton) {
    const card = boton.closest('.representante-adicional');
    card.remove();
}

// FunciÃ³n para cargar informaciÃ³n de secciones disponibles CON DATOS REALES
function cargarInfoSecciones() {
    const grado = document.getElementById('grado_inscripcion').value;
    const infoDiv = document.getElementById('info_secciones');
    
    if (!grado) {
        infoDiv.innerHTML = '<small class="text-muted">Seleccione un grado para ver las secciones disponibles...</small>';
        return;
    }
    
    // Mostrar loading
    infoDiv.innerHTML = `
        <div class="text-center">
            <div class="spinner-border spinner-border-sm" role="status">
                <span class="visually-hidden">Cargando...</span>
            </div>
            <small class="ms-2">Buscando secciones disponibles para ${grado}...</small>
        </div>
    `;
    
    // Hacer peticiÃ³n AJAX para obtener datos reales
    fetch(`../../includes/ajax_secciones.php?grado=${encodeURIComponent(grado)}&ano_escolar_id=<?php echo $ano_escolar_activo ? $ano_escolar_activo['id'] : ''; ?>`)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                let html = `<strong>Secciones disponibles para ${grado}:</strong><br>`;
                
                if (data.secciones.length > 0) {
                    data.secciones.forEach(seccion => {
                        const cuposDisponibles = seccion.capacidad ? 
                            (seccion.capacidad - seccion.estudiantes_inscritos) : 
                            'Sin lÃ­mite';
                            
                        const estado = (seccion.capacidad === null || cuposDisponibles > 0) ? 
                            `<span class="badge bg-success">${cuposDisponibles} ${seccion.capacidad ? 'cupos' : 'cupos ilimitados'}</span>` : 
                            `<span class="badge bg-danger">Llena</span>`;
                            
                        const profesor = seccion.profesor_nombre ? 
                            `<br><small class="text-muted">Prof: ${seccion.profesor_nombre} ${seccion.profesor_apellido}</small>` : 
                            '';
                            
                        html += `
                            <div class="mb-1">
                                ${seccion.nombre}: ${estado}
                                ${profesor}
                            </div>
                        `;
                    });
                    
                    // Contar secciones con cupos disponibles
                    const seccionesConCupos = data.secciones.filter(seccion => 
                        seccion.capacidad === null || (seccion.capacidad - seccion.estudiantes_inscritos) > 0
                    ).length;
                    
                    html += `
                        <small class="text-info mt-2 d-block">
                            <i class="fas fa-info-circle"></i> 
                            ${seccionesConCupos > 0 ? 
                                `El estudiante serÃ¡ inscrito en la primera secciÃ³n con cupos disponibles (${seccionesConCupos} secciones disponibles).` : 
                                'Todas las secciones estÃ¡n llenas. Se crearÃ¡ una nueva secciÃ³n automÃ¡ticamente.'}
                        </small>
                    `;
                } else {
                    html = `
                        <div class="text-center text-warning">
                            <i class="fas fa-exclamation-triangle"></i>
                            <strong>No hay secciones creadas para ${grado}</strong><br>
                            <small>Se crearÃ¡n automÃ¡ticamente cuando sea necesario.</small>
                        </div>
                    `;
                }
                
                infoDiv.innerHTML = html;
            } else {
                infoDiv.innerHTML = `
                    <div class="text-center text-danger">
                        <i class="fas fa-exclamation-circle"></i>
                        <small>Error al cargar secciones: ${data.error}</small>
                    </div>
                `;
            }
        })
        .catch(error => {
            infoDiv.innerHTML = `
                <div class="text-center text-danger">
                    <i class="fas fa-exclamation-circle"></i>
                    <small>Error de conexiÃ³n: ${error.message}</small>
                </div>
            `;
        });
}

// Inicializar cÃ¡lculos al cargar la pÃ¡gina
document.addEventListener('DOMContentLoaded', function() {
    calcularEdad();
    
    // Cargar informaciÃ³n de secciones cuando cambie el grado
    const gradoSelect = document.getElementById('grado_inscripcion');
    if (gradoSelect) {
        gradoSelect.addEventListener('change', cargarInfoSecciones);
        // Cargar informaciÃ³n inicial si ya hay un grado seleccionado
        if (gradoSelect.value && <?php echo $ano_escolar_activo ? 'true' : 'false'; ?>) {
            setTimeout(() => {
                cargarInfoSecciones();
            }, 500);
        }
    }
    
    // ValidaciÃ³n de formulario
    const form = document.getElementById('formEstudiante');
    if (form) {
        form.addEventListener('submit', function(e) {
            // Validar que al menos un documento estÃ© seleccionado
            const documentos = document.querySelectorAll('input[name="documentos_presentados[]"]:checked');
            if (documentos.length === 0) {
                e.preventDefault();
                alert('Debe seleccionar al menos un documento presentado.');
                return false;
            }
            
            // Validar telÃ©fonos
            const telefonos = document.querySelectorAll('input[name$="[telefono_numero]"]');
            for (let tel of telefonos) {
                if (tel.value && !/^[0-9]{7}$/.test(tel.value)) {
                    e.preventDefault();
                    alert('Los nÃºmeros de telÃ©fono deben tener exactamente 7 dÃ­gitos.');
                    tel.focus();
                    return false;
                }
            }
            
            return true;
        });
    }
});
</script>

<script src="../../js/validaciones-formularios.js"></script>
<?php include '../../includes/footer.php'; ?>
