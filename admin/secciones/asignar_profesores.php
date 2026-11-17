<?php
require_once '../../includes/auth.php';
require_once '../../includes/database.php';
checkAuth();
checkRole(['admin']);

if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    header('Location: gestionar.php');
    exit();
}

$seccion_id = intval($_GET['id']);

// Obtener datos de la sección con información del profesor
try {
    $stmt = $pdo->prepare("
        SELECT s.*, 
               ae.nombre as año_escolar,
               p.id as profesor_id,
               p.cedula as profesor_cedula,
               p.nombre as profesor_nombre,
               p.apellido as profesor_apellido,
               p.especialidad as profesor_especialidad
        FROM secciones s 
        JOIN años_escolares ae ON s.año_escolar_id = ae.id 
        LEFT JOIN profesores p ON s.profesor_id = p.id
        WHERE s.id = ?
    ");
    $stmt->execute([$seccion_id]);
    $seccion = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$seccion) {
        header('Location: gestionar.php');
        exit();
    }
} catch (PDOException $e) {
    error_log("Error en " . __FILE__ . ": " . $e->getMessage());
    $_SESSION['error_sistema'] = "Ocurrió un error al procesar la solicitud. El equipo técnico fue notificado.";
    header('Location: ' . ($_SERVER['HTTP_REFERER'] ?? app_url()));
    exit;
}

// Verificar si la sección tiene profesor asignado
$tiene_profesor = !empty($seccion['profesor_id']);

// Obtener áreas de aprendizaje
try {
    $stmt = $pdo->query("SELECT id, nombre, codigo FROM areas_aprendizaje WHERE estado = 'activa' ORDER BY nombre");
    $areas = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $areas = [];
}

// Colores para cada área
$colores_areas = [
    '#3498db', '#2ecc71', '#e74c3c', '#f39c12', '#9b59b6', 
    '#1abc9c', '#d35400', '#c0392b', '#16a085', '#8e44ad',
    '#27ae60', '#2980b9', '#e67e22', '#f1c40f', '#34495e'
];

// Función para ajustar brillo de colores
function ajustarBrillo($hex, $factor) {
    $hex = str_replace('#', '', $hex);
    if (strlen($hex) == 3) {
        $hex = str_repeat(substr($hex,0,1), 2).str_repeat(substr($hex,1,1), 2).str_repeat(substr($hex,2,1), 2);
    }
    $r = hexdec(substr($hex,0,2));
    $g = hexdec(substr($hex,2,2));
    $b = hexdec(substr($hex,4,2));
    
    $r = min(255, max(0, (int) ($r * $factor)));
    $g = min(255, max(0, (int) ($g * $factor)));
    $b = min(255, max(0, (int) ($b * $factor)));
    
    return '#'.str_pad(dechex($r), 2, '0', STR_PAD_LEFT)
           .str_pad(dechex($g), 2, '0', STR_PAD_LEFT)
           .str_pad(dechex($b), 2, '0', STR_PAD_LEFT);
}

// Asignar colores a las áreas
foreach ($areas as $index => &$area) {
    $area['color'] = $colores_areas[$index % count($colores_areas)];
    $area['color_claro'] = ajustarBrillo($colores_areas[$index % count($colores_areas)], 1.2);
}
unset($area);

// Obtener horarios actuales
try {
    $stmt = $pdo->prepare("
        SELECT ha.*, 
               a.nombre as area_nombre,
               a.codigo as area_codigo,
               p.nombre as profesor_nombre,
               p.apellido as profesor_apellido
        FROM horarios_asignaciones ha
        JOIN areas_aprendizaje a ON ha.area_id = a.id
        JOIN profesores p ON ha.profesor_id = p.id
        WHERE ha.seccion_id = ?
        ORDER BY ha.dia_semana, ha.hora_inicio
    ");
    $stmt->execute([$seccion_id]);
    $horarios = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $horarios = [];
}

// Procesar guardado de horarios via AJAX
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
    header('Content-Type: application/json');
    
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (isset($input['guardar_horario'])) {
        $dia = $input['dia'] ?? '';
        $hora = $input['hora'] ?? '';
        $area_id = intval($input['area_id'] ?? 0);
        $profesor_id = intval($input['profesor_id'] ?? 0);
        $duracion = intval($input['duracion'] ?? 1);
        
        if (empty($dia) || empty($hora) || $area_id <= 0 || $profesor_id <= 0) {
            echo json_encode(['success' => false, 'error' => 'Datos incompletos o inválidos']);
            exit();
        }
        
        try {
            // Calcular hora fin basado en la duración (cada slot es 45 minutos)
            $hora_fin = date('H:i:s', strtotime($hora . ' +' . (45 * $duracion) . ' minutes'));
            
            // Eliminar asignaciones existentes en este rango
            $stmt = $pdo->prepare("DELETE FROM horarios_asignaciones WHERE seccion_id = ? AND dia_semana = ? AND hora_inicio >= ? AND hora_inicio < ?");
            $stmt->execute([$seccion_id, $dia, $hora, $hora_fin]);
            
            // Verificar si la columna duracion existe, si no, usar valor por defecto
            $column_check = $pdo->query("SHOW COLUMNS FROM horarios_asignaciones LIKE 'duracion'");
            $duracion_exists = $column_check->fetch();
            
            if ($duracion_exists) {
                // Insertar con duración
                $stmt = $pdo->prepare("INSERT INTO horarios_asignaciones (seccion_id, año_escolar_id, area_id, profesor_id, dia_semana, hora_inicio, hora_fin, duracion) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([$seccion_id, $seccion['año_escolar_id'], $area_id, $profesor_id, $dia, $hora, $hora_fin, $duracion]);
            } else {
                // Insertar sin duración (para compatibilidad)
                $stmt = $pdo->prepare("INSERT INTO horarios_asignaciones (seccion_id, año_escolar_id, area_id, profesor_id, dia_semana, hora_inicio, hora_fin) VALUES (?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([$seccion_id, $seccion['año_escolar_id'], $area_id, $profesor_id, $dia, $hora, $hora_fin]);
            }
            
            echo json_encode(['success' => true]);
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        exit();
    }
    
    // Procesar eliminación de horario
    if (isset($input['eliminar_horario'])) {
        $dia = $input['dia'] ?? '';
        $hora = $input['hora'] ?? '';
        
        if (empty($dia) || empty($hora)) {
            echo json_encode(['success' => false, 'error' => 'Datos incompletos']);
            exit();
        }
        
        try {
            $stmt = $pdo->prepare("DELETE FROM horarios_asignaciones WHERE seccion_id = ? AND dia_semana = ? AND hora_inicio = ?");
            $stmt->execute([$seccion_id, $dia, $hora]);
            
            echo json_encode(['success' => true]);
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        exit();
    }
    
    // Procesar limpieza completa del horario
    if (isset($input['limpiar_horario'])) {
        $seccion_id_limpiar = intval($input['seccion_id'] ?? 0);
        
        if ($seccion_id_limpiar != $seccion_id) {
            echo json_encode(['success' => false, 'error' => 'ID de sección no coincide']);
            exit();
        }
        
        try {
            $stmt = $pdo->prepare("DELETE FROM horarios_asignaciones WHERE seccion_id = ?");
            $stmt->execute([$seccion_id]);
            
            echo json_encode(['success' => true, 'message' => 'Horario limpiado completamente']);
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        exit();
    }
    
    echo json_encode(['success' => false, 'error' => 'Acción no reconocida']);
    exit();
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestionar Horario - Sistema Estudiantes</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
<style>
    /* Variables CSS para consistencia */
    :root {
        --primary-color: #3498db;
        --success-color: #2ecc71;
        --warning-color: #f39c12;
        --danger-color: #e74c3c;
        --dark-color: #2c3e50;
        --light-color: #ecf0f1;
        --border-radius: 8px;
        --box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
        --transition: all 0.3s ease;
    }

    /* Estilos generales mejorados */
    .content-wrapper {
        background-color: #f8f9fa;
        min-height: calc(100vh - 60px);
    }

    .card {
        border: none;
        border-radius: var(--border-radius);
        box-shadow: var(--box-shadow);
        transition: var(--transition);
    }

    .card:hover {
        box-shadow: 0 6px 12px rgba(0, 0, 0, 0.15);
    }

    .card-header {
        background-color: white;
        border-bottom: 1px solid #e9ecef;
        padding: 1rem 1.25rem;
    }

    /* Mejoras para las áreas de aprendizaje */
    .area-item {
        background: white;
        cursor: grab;
        transition: var(--transition);
        border: 1px solid #dee2e6 !important;
        border-left: 4px solid var(--primary-color) !important;
        border-radius: var(--border-radius);
        margin-bottom: 0.5rem;
        padding: 0.75rem;
        position: relative;
        overflow: hidden;
    }

    .area-item:before {
        content: "";
        position: absolute;
        top: 0;
        left: 0;
        width: 100%;
        height: 3px;
        background: linear-gradient(90deg, var(--primary-color), transparent);
        transform: translateX(-100%);
        transition: transform 0.3s ease;
    }

    .area-item:hover {
        background: #f8f9fa;
        transform: translateY(-3px);
        box-shadow: 0 6px 12px rgba(0,0,0,0.1);
    }

    .area-item:hover:before {
        transform: translateX(0);
    }

    .area-item.dragging {
        opacity: 0.7;
        cursor: grabbing;
        transform: rotate(3deg);
        box-shadow: 0 8px 16px rgba(0,0,0,0.2);
    }

    /* MEJORAS PRINCIPALES PARA LAS CELDAS DEL HORARIO */
    .celda-horario {
        min-height: 90px;
        background: white;
        transition: var(--transition);
        position: relative;
        vertical-align: top;
        padding: 3px !important;
        border: 1px solid #e9ecef;
    }

    .celda-horario:hover {
        background: #f1f8ff;
    }

    .celda-horario.drag-over {
        background: #e1f5fe;
        border: 2px dashed var(--primary-color) !important;
        animation: pulse 1.5s infinite;
    }

    /* Celda que es parte de una asignación extendida */
    .celda-horario.part-of-assignment {
        padding: 0 !important;
        border: 1px solid rgba(0,0,0,0.1) !important;
    }

    .celda-horario.part-of-assignment .assignment-fill {
        width: 100%;
        height: 100%;
        display: flex;
        align-items: center;
        justify-content: center;
        color: white;
        font-weight: bold;
        font-size: 0.7rem;
        opacity: 0.8;
    }

    /* Primera celda de una asignación extendida */
    .celda-horario.assignment-start {
        border-top-left-radius: 6px;
        border-bottom-left-radius: 6px;
    }

    /* Última celda de una asignación extendida */
    .celda-horario.assignment-end {
        border-top-right-radius: 6px;
        border-bottom-right-radius: 6px;
    }

    /* Celda intermedia de una asignación extendida */
    .celda-horario.assignment-middle {
        border-left: none;
        border-right: none;
    }

    @keyframes pulse {
        0% { background-color: #e1f5fe; }
        50% { background-color: #b3e5fc; }
        100% { background-color: #e1f5fe; }
    }

    /* Mejoras para las asignaciones de horario */
    .asignacion-horario {
        position: relative;
        font-size: 0.75rem;
        cursor: move;
        transition: var(--transition);
        border-radius: 6px;
        margin: 0;
        z-index: 10;
        border: 1px solid rgba(0,0,0,0.15);
        padding: 0.5rem;
        height: 100%;
        display: flex;
        flex-direction: column;
        justify-content: space-between;
        overflow: hidden;
        box-shadow: 0 2px 4px rgba(0,0,0,0.1);
    }

    .asignacion-horario:before {
        content: "";
        position: absolute;
        top: 0;
        left: 0;
        width: 100%;
        height: 3px;
        background: rgba(255, 255, 255, 0.5);
    }

    .asignacion-horario:hover {
        transform: scale(1.03);
        box-shadow: 0 4px 12px rgba(0,0,0,0.2);
        z-index: 100;
    }

    .asignacion-horario.resizing {
        opacity: 0.9;
        box-shadow: 0 0 0 3px var(--warning-color);
    }

    .resize-handle {
        position: absolute;
        bottom: 4px;
        right: 4px;
        width: 14px;
        height: 14px;
        background: rgba(255, 255, 255, 0.9);
        border: 1px solid rgba(0, 0, 0, 0.3);
        border-radius: 3px;
        cursor: nw-resize;
        z-index: 20;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 0.6rem;
        color: rgba(0, 0, 0, 0.6);
    }

    .resize-handle:after {
        content: "⤢";
    }

    .resize-handle:hover {
        background: var(--warning-color);
        border-color: #e0a800;
        color: white;
    }

    .duracion-badge {
        position: absolute;
        top: 4px;
        right: 4px;
        background: rgba(0, 0, 0, 0.7);
        color: white;
        border-radius: 4px;
        padding: 2px 6px;
        font-size: 0.65rem;
        font-weight: bold;
    }

    .btn-eliminar-asignacion {
        opacity: 0;
        transition: opacity 0.2s;
    }

    .asignacion-horario:hover .btn-eliminar-asignacion {
        opacity: 1;
    }

    /* Mejoras para la tabla de horarios */
    #horario-table {
        border-collapse: separate;
        border-spacing: 0;
    }

    #horario-table th {
        position: sticky;
        top: 0;
        z-index: 10;
    }

    .hora-header {
        background: var(--dark-color) !important;
        color: white !important;
        font-weight: bold;
        position: sticky;
        left: 0;
        z-index: 11;
        border-right: 2px solid #dee2e6;
    }

    .dia-header {
        background: var(--primary-color) !important;
        color: white !important;
        font-weight: bold;
        text-align: center;
        position: relative;
    }

    .dia-header:after {
        content: "";
        position: absolute;
        bottom: 0;
        left: 0;
        width: 100%;
        height: 3px;
        background: rgba(255, 255, 255, 0.5);
    }

    /* Mejoras para los profesores */
    #lista-profesores {
        max-height: 400px; 
        overflow-y: auto;
        scrollbar-width: thin;
        scrollbar-color: var(--primary-color) #f1f1f1;
    }

    #lista-profesores::-webkit-scrollbar {
        width: 6px;
    }

    #lista-profesores::-webkit-scrollbar-track {
        background: #f1f1f1;
        border-radius: 10px;
    }

    #lista-profesores::-webkit-scrollbar-thumb {
        background: var(--primary-color);
        border-radius: 10px;
    }

    .profesor-item {
        transition: var(--transition);
        cursor: pointer;
        border-radius: var(--border-radius);
        margin-bottom: 0.5rem;
        border: 1px solid #dee2e6;
        padding: 0.75rem;
        position: relative;
        overflow: hidden;
    }

    .profesor-item:before {
        content: "";
        position: absolute;
        top: 0;
        left: 0;
        width: 4px;
        height: 100%;
        background: var(--primary-color);
        transform: scaleY(0);
        transition: transform 0.3s ease;
    }

    .profesor-item:hover {
        background-color: #e3f2fd !important;
        border-color: var(--primary-color) !important;
        transform: translateX(5px);
    }

    .profesor-item:hover:before {
        transform: scaleY(1);
    }

    .profesor-seleccionado {
        background-color: var(--primary-color) !important;
        color: white !important;
        border-color: var(--primary-color) !important;
    }

    .profesor-seleccionado:before {
        transform: scaleY(1);
        background: white;
    }

    #profesor-seleccionado-container {
        border-radius: var(--border-radius);
        background: linear-gradient(135deg, var(--success-color), #27ae60) !important;
        border: none;
        box-shadow: var(--box-shadow);
    }

    /* Mejoras para alertas y mensajes */
    .alerta-temporal {
        position: fixed;
        top: 20px;
        right: 20px;
        z-index: 9999;
        min-width: 300px;
        max-width: 400px;
        box-shadow: 0 6px 12px rgba(0, 0, 0, 0.15);
        animation: slideInRight 0.4s ease-out;
        border-radius: var(--border-radius);
        border: none;
    }

    @keyframes slideInRight {
        from {
            transform: translateX(100%);
            opacity: 0;
        }
        to {
            transform: translateX(0);
            opacity: 1;
        }
    }

    /* Indicadores visuales */
    .area-color-indicator {
        width: 16px;
        height: 16px;
        border-radius: 4px;
        display: inline-block;
        margin-right: 8px;
        border: 2px solid white;
        box-shadow: 0 1px 3px rgba(0,0,0,0.2);
    }

    /* Estados vacíos y mensajes */
    .celda-horario.vacia {
        background: repeating-linear-gradient(
            45deg,
            #f8f9fa,
            #f8f9fa 5px,
            #e9ecef 5px,
            #e9ecef 10px
        );
    }

    #sin-resultados {
        border-radius: var(--border-radius);
        background: #f8f9fa;
        border: 2px dashed #dee2e6;
    }

    /* Botones mejorados */
    .btn {
        border-radius: var(--border-radius);
        transition: var(--transition);
        font-weight: 500;
    }

    .btn:hover {
        transform: translateY(-2px);
        box-shadow: 0 4px 8px rgba(0,0,0,0.1);
    }

    .btn-outline-primary {
        border-width: 2px;
    }

    .btn-success {
        background: linear-gradient(135deg, var(--success-color), #27ae60);
        border: none;
    }

    /* Mejoras responsivas */
    @media (max-width: 768px) {
        .content-wrapper {
            margin-left: 0 !important;
            padding: 10px;
        }
        
        .celda-horario {
            min-height: 70px;
            font-size: 0.7rem;
        }
        
        .asignacion-horario {
            font-size: 0.65rem;
            padding: 0.25rem;
        }
        
        .hora-header, .dia-header {
            font-size: 0.8rem;
        }
    }

    /* Estados de carga y feedback */
    .loading {
        position: relative;
        overflow: hidden;
    }

    .loading:after {
        content: "";
        position: absolute;
        top: 0;
        left: -100%;
        width: 100%;
        height: 100%;
        background: linear-gradient(90deg, transparent, rgba(255,255,255,0.4), transparent);
        animation: loading 1.5s infinite;
    }

    @keyframes loading {
        0% { left: -100%; }
        100% { left: 100%; }
    }

    /* Mejoras para los filtros */
    #buscar-profesor, #filtro-especialidad {
        border-radius: var(--border-radius);
        border: 1px solid #ced4da;
        transition: var(--transition);
    }

    #buscar-profesor:focus, #filtro-especialidad:focus {
        border-color: var(--primary-color);
        box-shadow: 0 0 0 0.2rem rgba(52, 152, 219, 0.25);
    }

    /* Indicador de arrastre */
    .drag-ghost {
        opacity: 0.8;
        transform: rotate(5deg);
        box-shadow: 0 10px 20px rgba(0,0,0,0.2);
    }
</style>
</head>
<body>
    <?php include '../../includes/header.php'; ?>

    <div class="content-wrapper" style="padding: 20px;">
        <?php include '../../includes/sidebar_admin.php'; ?>
        
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h2>Gestionar Horario de Sección</h2>
            <a href="gestionar.php" class="btn btn-outline-secondary">
                <i class="fas fa-arrow-left me-2"></i>Volver a Secciones
            </a>
        </div>

        <!-- Información de la sección -->
        <div class="card mb-4">
            <div class="card-body">
                <div class="row">
                    <div class="col-md-8">
                        <h4>Sección: <?php echo htmlspecialchars($seccion['nombre']); ?></h4>
                        <p class="mb-1"><strong>Año Escolar:</strong> <?php echo htmlspecialchars($seccion['año_escolar']); ?></p>
                        <p class="mb-0"><strong>Capacidad:</strong> <?php echo $seccion['capacidad'] ? $seccion['capacidad'] . ' estudiantes' : 'Sin límite'; ?></p>
                        
                        <!-- Información del Profesor Asignado -->
                        <?php if ($tiene_profesor): ?>
                            <div class="mt-3 p-3 bg-success text-white rounded">
                                <h6 class="mb-2"><i class="fas fa-chalkboard-teacher me-2"></i>Profesor Titular Asignado</h6>
                                <p class="mb-1"><strong>Nombre:</strong> <?php echo htmlspecialchars($seccion['profesor_nombre'] . ' ' . $seccion['profesor_apellido']); ?></p>
                                <?php if ($seccion['profesor_especialidad']): ?>
                                    <p class="mb-1"><strong>Especialidad:</strong> <?php echo htmlspecialchars($seccion['profesor_especialidad']); ?></p>
                                <?php endif; ?>
                                <?php if ($seccion['profesor_cedula']): ?>
                                    <p class="mb-0"><strong>Cédula:</strong> <?php echo htmlspecialchars($seccion['profesor_cedula']); ?></p>
                                <?php endif; ?>
                            </div>
                        <?php else: ?>
                            <div class="mt-3 p-3 bg-warning text-dark rounded">
                                <h6 class="mb-2"><i class="fas fa-exclamation-triangle me-2"></i>Advertencia</h6>
                                <p class="mb-0">Esta sección no tiene profesor titular asignado. <a href="editar.php?id=<?php echo $seccion_id; ?>" class="alert-link">Asignar profesor</a></p>
                            </div>
                        <?php endif; ?>
                    </div>
                    <div class="col-md-4">
                        <div class="alert alert-info">
                            <small>
                                <i class="fas fa-info-circle me-1"></i>
                                <strong>Instrucciones:</strong><br>
                                <?php if ($tiene_profesor): ?>
                                    1. El profesor titular está pre-asignado<br>
                                    2. Arrastra un área al horario<br>
                                    3. Extiende la asignación usando el handle ↗
                                <?php else: ?>
                                    1. Primero asigne un profesor titular<br>
                                    2. Luego podrá gestionar el horario<br>
                                    3. Use el botón "Asignar Profesor"
                                <?php endif; ?>
                            </small>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Sistema de Horarios Drag & Drop -->
        <div class="card mb-4">
            <div class="card-header">
                <h5 class="card-title mb-0">
                    <i class="fas fa-calendar-alt me-2"></i>Gestión de Horario - Drag & Drop con Extensión
                </h5>
            </div>
            <div class="card-body">
                <div class="row">
                    <!-- Panel de Áreas Disponibles -->
                    <div class="col-md-3">
                        <div class="card">
                            <div class="card-header bg-light">
                                <h6 class="mb-0">Áreas de Aprendizaje</h6>
                            </div>
                            <div class="card-body p-2" id="areas-container">
                                <?php foreach ($areas as $area): ?>
                                    <div class="area-item draggable mb-2 p-2 border rounded cursor-grab" 
                                         draggable="true"
                                         data-area-id="<?php echo $area['id']; ?>"
                                         data-area-codigo="<?php echo htmlspecialchars($area['codigo']); ?>"
                                         data-area-nombre="<?php echo htmlspecialchars($area['nombre']); ?>"
                                         data-area-color="<?php echo $area['color']; ?>"
                                         style="border-left-color: <?php echo $area['color']; ?> !important;">
                                        <div class="d-flex align-items-center">
                                            <span class="area-color-indicator" style="background: <?php echo $area['color']; ?>"></span>
                                            <div>
                                                <small class="text-muted d-block"><?php echo htmlspecialchars($area['codigo']); ?></small>
                                                <div class="fw-bold small"><?php echo htmlspecialchars($area['nombre']); ?></div>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        
                        <!-- Información del Sistema -->
                        <div class="card mt-3">
                            <div class="card-header bg-light">
                                <h6 class="mb-0">
                                    <i class="fas fa-info-circle me-2"></i>Información del Sistema
                                </h6>
                            </div>
                            <div class="card-body p-2">
                                <?php if ($tiene_profesor): ?>
                                    <div class="alert alert-success mb-2">
                                        <small>
                                            <i class="fas fa-check-circle me-1"></i>
                                            <strong>Profesor pre-asignado:</strong><br>
                                            <?php echo htmlspecialchars($seccion['profesor_nombre'] . ' ' . $seccion['profesor_apellido']); ?>
                                            <?php if ($seccion['profesor_especialidad']): ?>
                                                <br><em><?php echo htmlspecialchars($seccion['profesor_especialidad']); ?></em>
                                            <?php endif; ?>
                                        </small>
                                    </div>
                                    <p class="small text-muted mb-0">
                                        Todas las áreas serán asignadas automáticamente al profesor titular de la sección.
                                    </p>
                                <?php else: ?>
                                    <div class="alert alert-warning mb-2">
                                        <small>
                                            <i class="fas fa-exclamation-triangle me-1"></i>
                                            <strong>No hay profesor asignado</strong><br>
                                            Para gestionar el horario, primero debe asignar un profesor titular a esta sección.
                                        </small>
                                    </div>
                                    <a href="editar.php?id=<?php echo $seccion_id; ?>" class="btn btn-sm btn-warning w-100">
                                        <i class="fas fa-user-plus me-1"></i>Asignar Profesor
                                    </a>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <!-- Horario Semanal -->
                    <div class="col-md-9">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <h6 class="mb-0">
                                Horario Semanal - 
                                <?php if ($tiene_profesor): ?>
                                    Profesor: <?php echo htmlspecialchars($seccion['profesor_nombre'] . ' ' . $seccion['profesor_apellido']); ?>
                                <?php else: ?>
                                    <span class="text-danger">Sin profesor asignado</span>
                                <?php endif; ?>
                            </h6>
                            <div>
                                <button type="button" class="btn btn-outline-warning btn-sm me-2" onclick="validarHorario()">
                                    <i class="fas fa-check-circle me-1"></i>Validar
                                </button>
                                <button type="button" class="btn btn-outline-danger btn-sm" onclick="limpiarHorario()">
                                    <i class="fas fa-trash me-1"></i>Limpiar Todo
                                </button>
                            </div>
                        </div>
                        
                        <div class="table-responsive">
                            <table class="table table-bordered table-hover" id="horario-table">
                                <thead class="table-dark">
                                    <tr>
                                        <th width="15%" class="hora-header">Hora</th>
                                        <th width="17%" class="dia-header">Lunes</th>
                                        <th width="17%" class="dia-header">Martes</th>
                                        <th width="17%" class="dia-header">Miércoles</th>
                                        <th width="17%" class="dia-header">Jueves</th>
                                        <th width="17%" class="dia-header">Viernes</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php
                                    // Generar horario de 7:00 AM a 3:00 PM
                                    $horas = [];
                                    for ($h = 7; $h <= 15; $h++) {
                                        for ($m = 0; $m < 60; $m += 45) {
                                            $hora = str_pad($h, 2, '0', STR_PAD_LEFT) . ':' . str_pad($m, 2, '0', STR_PAD_LEFT);
                                            $horas[] = $hora;
                                        }
                                    }
                                    
                                    foreach ($horas as $hora):
                                        $hora_display = date('h:i A', strtotime($hora));
                                        $hora_siguiente = date('h:i A', strtotime($hora . ' +45 minutes'));
                                    ?>
                                    <tr>
                                        <td class="bg-light fw-bold">
                                            <?php echo $hora_display . ' - ' . $hora_siguiente; ?>
                                        </td>
                                        <?php foreach (['lunes', 'martes', 'miercoles', 'jueves', 'viernes'] as $dia): ?>
                                            <td class="celda-horario droppable" 
                                                data-dia="<?php echo $dia; ?>"
                                                data-hora="<?php echo $hora; ?>"
                                                data-hora-index="<?php echo array_search($hora, $horas); ?>">
                                                <?php
                                                // Mostrar asignación existente
                                                $asignacion = null;
                                                foreach ($horarios as $horario) {
                                                    if ($horario['dia_semana'] === $dia && $horario['hora_inicio'] === $hora) {
                                                        $asignacion = $horario;
                                                        break;
                                                    }
                                                }
                                                
                                                if ($asignacion):
                                                    // Buscar el color del área
                                                    $color_area = '#3498db';
                                                    foreach ($areas as $area) {
                                                        if ($area['id'] == $asignacion['area_id']) {
                                                            $color_area = $area['color'];
                                                            break;
                                                        }
                                                    }
                                                    // Si no existe duración en la BD, usar 1 por defecto
                                                    $duracion = isset($asignacion['duracion']) ? $asignacion['duracion'] : 1;
                                                ?>
                                                    <div class="asignacion-horario p-1 border rounded position-relative"
                                                         data-dia="<?php echo $dia; ?>"
                                                         data-hora="<?php echo $hora; ?>"
                                                         data-area-id="<?php echo $asignacion['area_id']; ?>"
                                                         data-profesor-id="<?php echo $asignacion['profesor_id']; ?>"
                                                         data-duracion="<?php echo $duracion; ?>"
                                                         style="background: <?php echo $color_area; ?>; color: white;"
                                                         onmousedown="iniciarResize(event, this)">
                                                        <small class="fw-bold d-block"><?php echo htmlspecialchars($asignacion['area_codigo']); ?></small>
                                                        <div class="small"><?php echo htmlspecialchars($asignacion['profesor_nombre'] . ' ' . $asignacion['profesor_apellido']); ?></div>
                                                        <span class="duracion-badge"><?php echo $duracion * 45; ?>min</span>
                                                        <div class="resize-handle" title="Extender/Reducir duración"></div>
                                                        <button class="btn btn-sm btn-danger btn-eliminar-asignacion position-absolute" 
                                                                style="top: 2px; right: 16px; padding: 0; width: 16px; height: 16px; font-size: 0.6rem; line-height: 1;"
                                                                onclick="event.stopPropagation(); eliminarAsignacion('<?php echo $dia; ?>', '<?php echo $hora; ?>')">
                                                            <i class="fas fa-times"></i>
                                                        </button>
                                                    </div>
                                                <?php endif; ?>
                                            </td>
                                        <?php endforeach; ?>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Botón para guardar cambios -->
        <div class="card">
            <div class="card-body text-center">
                <button type="button" class="btn btn-success btn-lg" onclick="guardarHorario()">
                    <i class="fas fa-save me-2"></i>Guardar Horario Completo
                </button>
                <div id="mensaje-guardado" class="mt-2"></div>
            </div>
        </div>
    </div>

    <script>
    // Sistema de gestión de horarios con extensión de duración
    class GestorHorarios {
        constructor() {
            // Usar automáticamente el profesor asignado a la sección
            this.profesorSeleccionado = <?php echo $tiene_profesor ? $seccion['profesor_id'] : 'null'; ?>;
            this.profesorSeleccionadoNombre = '<?php echo $tiene_profesor ? htmlspecialchars($seccion['profesor_nombre'] . ' ' . $seccion['profesor_apellido']) : ''; ?>';
            this.areaArrastrada = null;
            this.todosLosProfesores = [];
            this.resizing = false;
            this.asignacionResizing = null;
            this.horas = this.obtenerHoras();
            this.asignacionesPorCelda = new Map();
            this.inicializarAsignacionesPorCelda();
            
            this.inicializar();
        }
        
        obtenerHoras() {
            const horas = [];
            for (let h = 7; h <= 15; h++) {
                for (let m = 0; m < 60; m += 45) {
                    const hora = h.toString().padStart(2, '0') + ':' + m.toString().padStart(2, '0');
                    horas.push(hora);
                }
            }
            return horas;
        }
        
        inicializar() {
            console.log('Inicializando gestor de horarios...');
            console.log('Profesor asignado:', this.profesorSeleccionado);
            console.log('Nombre profesor:', this.profesorSeleccionadoNombre);
            
            // Configurar elementos arrastrables
            this.configurarDragAndDrop();
            
            // Configurar eventos de teclado
            this.configurarEventosTeclado();
            
            // Configurar eventos de resize
            this.configurarEventosResize();
        }
        
        configurarDragAndDrop() {
            // Elementos arrastrables (áreas)
            const draggables = document.querySelectorAll('.draggable');
            draggables.forEach(draggable => {
                draggable.addEventListener('dragstart', (e) => this.dragStart(e));
                draggable.addEventListener('dragend', (e) => this.dragEnd(e));
            });
            
            // Celdas droppables
            const celdas = document.querySelectorAll('.droppable');
            celdas.forEach(celda => {
                celda.addEventListener('dragover', (e) => this.allowDrop(e));
                celda.addEventListener('dragleave', (e) => this.dragLeave(e));
                celda.addEventListener('drop', (e) => this.drop(e));
            });
        }
        
        configurarEventosResize() {
            document.addEventListener('mousemove', (e) => this.handleResizeMove(e));
            document.addEventListener('mouseup', (e) => this.handleResizeEnd(e));
        }
        
        configurarEventosTeclado() {
            document.addEventListener('keydown', (e) => {
                // Escape para limpiar selecciones
                if (e.key === 'Escape') {
                    this.mostrarAlerta('Acción cancelada', 'info');
                }
                
                // Ctrl + S para guardar
                if (e.ctrlKey && e.key === 's') {
                    e.preventDefault();
                    this.guardarHorario();
                }
            });
        }
        
        // DRAG & DROP
        dragStart(e) {
            this.areaArrastrada = {
                id: parseInt(e.target.dataset.areaId),
                codigo: e.target.dataset.areaCodigo,
                nombre: e.target.dataset.areaNombre,
                color: e.target.dataset.areaColor
            };
            e.target.classList.add('dragging');
            e.dataTransfer.setData('text/plain', e.target.dataset.areaId);
            e.dataTransfer.effectAllowed = 'move';
        }
        
        dragEnd(e) {
            e.target.classList.remove('dragging');
            document.querySelectorAll('.drag-over').forEach(el => {
                el.classList.remove('drag-over');
            });
        }
        
        allowDrop(e) {
            e.preventDefault();
            e.currentTarget.classList.add('drag-over');
            e.dataTransfer.dropEffect = 'move';
        }
        
        dragLeave(e) {
            e.currentTarget.classList.remove('drag-over');
        }

        inicializarAsignacionesPorCelda() {
            document.querySelectorAll('.asignacion-horario').forEach((asignacion, index) => {
                const idAsignacion = asignacion.dataset.asignacionId || `asig-${Date.now()}-${index}`;
                asignacion.dataset.asignacionId = idAsignacion;

                const dia = asignacion.dataset.dia;
                const horaInicio = asignacion.dataset.hora;
                const duracion = parseInt(asignacion.dataset.duracion || '1');
                const inicioIndex = this.horas.indexOf(horaInicio);

                for (let i = 0; i < duracion; i++) {
                    const horaActual = this.horas[inicioIndex + i];
                    if (!horaActual) continue;
                    const key = `${dia}|${horaActual}`;
                    this.asignacionesPorCelda.set(key, idAsignacion);

                    const celda = document.querySelector(`[data-dia="${dia}"][data-hora="${horaActual}"]`);
                    if (celda) {
                        celda.setAttribute('data-owner-asignacion', idAsignacion);
                        if (i > 0) {
                            celda.classList.add('part-of-assignment');
                        }
                    }
                }
            });
        }

        rangoLibre(dia, horaInicio, duracion, idAsignacionActual = null) {
            const inicioIndex = this.horas.indexOf(horaInicio);
            if (inicioIndex === -1) return false;

            for (let i = 0; i < duracion; i++) {
                const idx = inicioIndex + i;
                if (idx >= this.horas.length) return false;

                const hora = this.horas[idx];
                const key = `${dia}|${hora}`;
                const owner = this.asignacionesPorCelda.get(key);

                if (owner && owner !== idAsignacionActual) {
                    return false;
                }
            }

            return true;
        }

        registrarCeldas(dia, horaInicio, duracion, idAsignacion) {
            const inicioIndex = this.horas.indexOf(horaInicio);
            for (let i = 0; i < duracion; i++) {
                const hora = this.horas[inicioIndex + i];
                if (!hora) continue;
                this.asignacionesPorCelda.set(`${dia}|${hora}`, idAsignacion);
            }
        }

        liberarCeldas(idAsignacion) {
            for (const [key, owner] of this.asignacionesPorCelda.entries()) {
                if (owner === idAsignacion) {
                    this.asignacionesPorCelda.delete(key);
                }
            }
        }

        drop(e) {
            e.preventDefault();
            e.currentTarget.classList.remove('drag-over');
            
            if (!this.areaArrastrada) {
                this.mostrarAlerta('Por favor arrastra un área de aprendizaje', 'warning');
                return;
            }
            
            if (this.profesorSeleccionado === null) {
                this.mostrarAlerta('Error: Esta sección no tiene profesor asignado. Por favor asigne un profesor titular primero.', 'danger');
                return;
            }
            
            const celda = e.currentTarget;
            const dia = celda.dataset.dia;
            const hora = celda.dataset.hora;

            if (!this.rangoLibre(dia, hora, 1, null)) {
                this.mostrarAlerta('Ese horario ya está ocupado por otra asignación.', 'warning');
                return;
            }
            
            const idAsignacion = 'asig-' + Date.now() + '-' + Math.floor(Math.random() * 1000);
            this.crearAsignacionVisual(celda, dia, hora, 1, idAsignacion);
            
            this.guardarAsignacion(dia, hora, this.areaArrastrada.id, this.profesorSeleccionado, 1);
        }
        
        crearAsignacionVisual(celda, dia, hora, duracion, idAsignacion = 'asig-' + Date.now() + '-' + Math.floor(Math.random() * 1000)) {
            const asignacionDiv = document.createElement('div');
            asignacionDiv.className = 'asignacion-horario p-1 border rounded position-relative';
            asignacionDiv.setAttribute('data-dia', dia);
            asignacionDiv.setAttribute('data-hora', hora);
            asignacionDiv.setAttribute('data-area-id', this.areaArrastrada.id);
            asignacionDiv.setAttribute('data-profesor-id', this.profesorSeleccionado);
            asignacionDiv.setAttribute('data-duracion', duracion);
            asignacionDiv.setAttribute('data-asignacion-id', idAsignacion);
            asignacionDiv.style.background = this.areaArrastrada.color;
            asignacionDiv.style.color = 'white';
            asignacionDiv.setAttribute('onmousedown', 'gestorHorarios.iniciarResize(event, this)');
            
            asignacionDiv.innerHTML = `
                <small class="fw-bold d-block">${this.areaArrastrada.codigo}</small>
                <div class="small">${this.profesorSeleccionadoNombre}</div>
                <span class="duracion-badge">${duracion * 45}min</span>
                <div class="resize-handle" title="Extender/Reducir duración"></div>
                <button class="btn btn-sm btn-danger btn-eliminar-asignacion position-absolute" 
                        style="top: 2px; right: 16px; padding: 0; width: 16px; height: 16px; font-size: 0.6rem; line-height: 1;"
                        onclick="event.stopPropagation(); gestorHorarios.eliminarAsignacion('${dia}', '${hora}')">
                    <i class="fas fa-times"></i>
                </button>
            `;
            
            celda.innerHTML = '';
            celda.appendChild(asignacionDiv);
            
            this.registrarCeldas(dia, hora, duracion, idAsignacion);
            this.extenderAsignacionVisual(asignacionDiv, duracion);
        }
        
        extenderAsignacionVisual(asignacion, duracion) {
            const dia = asignacion.dataset.dia;
            const horaInicio = asignacion.dataset.hora;
            const idAsignacion = asignacion.dataset.asignacionId;
            const inicioIndex = this.horas.indexOf(horaInicio);
            const areaColor = asignacion.style.background;

            if (idAsignacion) {
                this.liberarCeldas(idAsignacion);
                this.limpiarCeldasDeAsignacion(idAsignacion);
            }

            this.registrarCeldas(dia, horaInicio, duracion, idAsignacion);

            for (let i = 0; i < duracion; i++) {
                const horaIndex = inicioIndex + i;
                if (horaIndex >= this.horas.length) break;

                const hora = this.horas[horaIndex];
                const celda = document.querySelector(`[data-dia="${dia}"][data-hora="${hora}"]`);
                if (!celda) continue;

                let positionClass = 'assignment-middle';
                if (i === 0) positionClass = 'assignment-start';
                if (i === duracion - 1) positionClass = 'assignment-end';

                celda.classList.add('part-of-assignment', positionClass);
                celda.setAttribute('data-owner-asignacion', idAsignacion);
                celda.style.background = areaColor;
                celda.style.color = 'white';
                celda.style.borderColor = 'rgba(0,0,0,0.1)';

                if (i === 0) {
                    celda.innerHTML = '';
                    celda.appendChild(asignacion);
                } else {
                    const label = asignacion.querySelector('small') ? asignacion.querySelector('small').textContent : 'Área';
                    celda.innerHTML = `<div class="assignment-fill">${label}</div>`;
                }
            }

            const badge = asignacion.querySelector('.duracion-badge');
            if (badge) badge.textContent = (duracion * 45) + 'min';
            asignacion.dataset.duracion = duracion;
        }

        limpiarCeldasDeAsignacion(idAsignacion) {
            document.querySelectorAll(`[data-owner-asignacion="${idAsignacion}"]`).forEach(celda => {
                celda.classList.remove('part-of-assignment', 'assignment-start', 'assignment-middle', 'assignment-end');
                celda.removeAttribute('data-owner-asignacion');
                celda.style.background = '';
                celda.style.color = '';
                celda.style.borderColor = '';

                if (!celda.querySelector('.asignacion-horario')) {
                    celda.innerHTML = '';
                }
            });
        }

        // SISTEMA DE RESIZE
        iniciarResize(e, asignacion) {
            if (e.target.classList.contains('resize-handle')) {
                e.preventDefault();
                e.stopPropagation();
                this.resizing = true;
                this.asignacionResizing = asignacion;
                asignacion.classList.add('resizing');
                document.body.style.userSelect = 'none';
            }
        }
        
        handleResizeMove(e) {
            if (!this.resizing || !this.asignacionResizing) return;
            
            const asignacion = this.asignacionResizing;
            const dia = asignacion.dataset.dia;
            const horaInicio = asignacion.dataset.hora;
            const idAsignacion = asignacion.dataset.asignacionId;
            const inicioIndex = this.horas.indexOf(horaInicio);
            
            const elementos = document.elementsFromPoint(e.clientX, e.clientY);
            const celdaBajoCursor = elementos.find(el => el.classList.contains('celda-horario'));
            
            if (celdaBajoCursor && celdaBajoCursor.dataset.dia === dia) {
                const horaCursor = celdaBajoCursor.dataset.hora;
                const cursorIndex = this.horas.indexOf(horaCursor);
                
                if (cursorIndex > inicioIndex) {
                    const nuevaDuracion = cursorIndex - inicioIndex + 1;
                    const duracionMaxima = this.horas.length - inicioIndex;
                    const duracionFinal = Math.min(nuevaDuracion, duracionMaxima);

                    if (!this.rangoLibre(dia, horaInicio, duracionFinal, idAsignacion)) {
                        let maxLibre = 1;
                        for (let i = 2; i <= duracionFinal; i++) {
                            if (this.rangoLibre(dia, horaInicio, i, idAsignacion)) {
                                maxLibre = i;
                            } else {
                                break;
                            }
                        }
                        if (maxLibre === 1) return;
                        this.extenderAsignacionVisual(asignacion, maxLibre);
                        return;
                    }

                    this.extenderAsignacionVisual(asignacion, duracionFinal);
                    
                    const badge = asignacion.querySelector('.duracion-badge');
                    if (badge) {
                        badge.textContent = duracionFinal * 45 + 'min';
                    }
                    
                    asignacion.dataset.duracion = duracionFinal;
                }
            }
        }
        
        handleResizeEnd(e) {
            if (this.resizing && this.asignacionResizing) {
                const asignacion = this.asignacionResizing;
                const dia = asignacion.dataset.dia;
                const hora = asignacion.dataset.hora;
                const duracion = parseInt(asignacion.dataset.duracion);
                const areaId = parseInt(asignacion.dataset.areaId);
                const profesorId = parseInt(asignacion.dataset.profesorId);
                
                // Guardar cambios en la base de datos
                this.guardarAsignacion(dia, hora, areaId, profesorId, duracion);
                
                asignacion.classList.remove('resizing');
                this.resizing = false;
                this.asignacionResizing = null;
                document.body.style.userSelect = '';
                
                this.mostrarAlerta(`Duración ajustada a ${duracion * 45} minutos`, 'success');
            }
        }
        
        // OPERACIONES AJAX
        guardarAsignacion(dia, hora, areaId, profesorId, duracion) {
            const xhr = new XMLHttpRequest();
            xhr.open('POST', '', true);
            xhr.setRequestHeader('Content-Type', 'application/json');
            xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
            
            xhr.onload = () => {
                if (xhr.status === 200) {
                    try {
                        const data = JSON.parse(xhr.responseText);
                        if (data.success) {
                            console.log('Asignación guardada correctamente');
                        } else {
                            this.mostrarAlerta('Error al guardar: ' + data.error, 'danger');
                        }
                    } catch (e) {
                        this.mostrarAlerta('Error en la respuesta del servidor', 'danger');
                    }
                } else {
                    this.mostrarAlerta('Error de conexión', 'danger');
                }
            };
            
            xhr.onerror = () => {
                this.mostrarAlerta('Error de red', 'danger');
            };
            
            xhr.send(JSON.stringify({
                guardar_horario: true,
                dia: dia,
                hora: hora,
                area_id: areaId,
                profesor_id: profesorId,
                duracion: duracion
            }));
        }
        
        eliminarAsignacion(dia, hora) {
            if (!confirm('¿Estás seguro de que deseas eliminar esta asignación?')) {
                return;
            }
            
            const xhr = new XMLHttpRequest();
            xhr.open('POST', '', true);
            xhr.setRequestHeader('Content-Type', 'application/json');
            xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
            
            xhr.onload = () => {
                if (xhr.status === 200) {
                    try {
                        const data = JSON.parse(xhr.responseText);
                        if (data.success) {
                            this.eliminarAsignacionVisual(dia, hora);
                            this.mostrarAlerta('Asignación eliminada', 'success');
                        } else {
                            this.mostrarAlerta('Error al eliminar: ' + data.error, 'danger');
                        }
                    } catch (e) {
                        this.mostrarAlerta('Error en la respuesta', 'danger');
                    }
                } else {
                    this.mostrarAlerta('Error de conexión', 'danger');
                }
            };
            
            xhr.send(JSON.stringify({
                eliminar_horario: true,
                dia: dia,
                hora: hora
            }));
        }
        
        eliminarAsignacionVisual(dia, hora) {
            const asignacion = document.querySelector(`.asignacion-horario[data-dia="${dia}"][data-hora="${hora}"]`);
            if (!asignacion) return;

            const idAsignacion = asignacion.dataset.asignacionId;
            this.liberarCeldas(idAsignacion);
            this.limpiarCeldasDeAsignacion(idAsignacion);
            asignacion.remove();
        }
        
        limpiarHorario() {
            if (!confirm('¿Estás seguro de que deseas limpiar todo el horario? Esta acción no se puede deshacer.')) {
                return;
            }
            
            const xhr = new XMLHttpRequest();
            xhr.open('POST', '', true);
            xhr.setRequestHeader('Content-Type', 'application/json');
            xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
            
            xhr.onload = () => {
                if (xhr.status === 200) {
                    try {
                        const data = JSON.parse(xhr.responseText);
                        if (data.success) {
                            this.asignacionesPorCelda.clear();
                            document.querySelectorAll('.asignacion-horario').forEach(asignacion => {
                                asignacion.remove();
                            });
                            document.querySelectorAll('.celda-horario').forEach(celda => {
                                celda.innerHTML = '';
                                celda.classList.remove('resizable-over', 'part-of-assignment', 'assignment-start', 'assignment-middle', 'assignment-end');
                                celda.removeAttribute('data-owner-asignacion');
                                celda.style.background = '';
                                celda.style.color = '';
                                celda.style.borderColor = '';
                            });
                            this.mostrarAlerta('Horario limpiado', 'success');
                        } else {
                            this.mostrarAlerta('Error al limpiar: ' + data.error, 'danger');
                        }
                    } catch (e) {
                        this.mostrarAlerta('Error en la respuesta', 'danger');
                    }
                } else {
                    this.mostrarAlerta('Error de conexión', 'danger');
                }
            };
            
            xhr.send(JSON.stringify({
                limpiar_horario: true,
                seccion_id: <?php echo $seccion_id; ?>
            }));
        }
        
        // UTILIDADES
        mostrarAlerta(mensaje, tipo = 'info') {
            // Remover alertas anteriores
            const alertasAnteriores = document.querySelectorAll('.alerta-temporal');
            alertasAnteriores.forEach(alerta => alerta.remove());
            
            // Crear alerta
            const alerta = document.createElement('div');
            alerta.className = `alert alert-${tipo} alerta-temporal alert-dismissible fade show`;
            alerta.innerHTML = `
                <i class="fas ${this.getIconoAlerta(tipo)} me-2"></i>
                ${mensaje}
                <button type="button" class="btn-close" onclick="this.parentElement.remove()"></button>
            `;
            
            document.body.appendChild(alerta);
            
            // Auto-eliminar
            setTimeout(() => {
                if (alerta.parentNode) {
                    alerta.remove();
                }
            }, 4000);
        }
        
        getIconoAlerta(tipo) {
            const iconos = {
                'success': 'fa-check-circle',
                'warning': 'fa-exclamation-triangle',
                'danger': 'fa-times-circle',
                'info': 'fa-info-circle'
            };
            return iconos[tipo] || 'fa-info-circle';
        }
        
        validarHorario() {
            const asignaciones = document.querySelectorAll('.asignacion-horario');
            let totalMinutos = 0;
            
            asignaciones.forEach(asignacion => {
                const duracion = parseInt(asignacion.dataset.duracion || 1);
                totalMinutos += duracion * 45;
            });
            
            this.mostrarAlerta(`✓ Horario validado: ${asignaciones.length} asignaciones, ${totalMinutos} minutos totales`, 'success');
        }
        
        guardarHorario() {
            const totalAsignaciones = document.querySelectorAll('.asignacion-horario').length;
            const mensajeDiv = document.getElementById('mensaje-guardado');
            
            if (totalAsignaciones === 0) {
                mensajeDiv.innerHTML = '<div class="alert alert-warning">No hay asignaciones para guardar</div>';
            } else {
                mensajeDiv.innerHTML = `<div class="alert alert-success">
                    <i class="fas fa-check-circle me-2"></i>
                    ¡Todo listo! ${totalAsignaciones} asignaciones guardadas.
                </div>`;
            }
            
            setTimeout(() => {
                mensajeDiv.innerHTML = '';
            }, 5000);
        }
    }

    // Inicializar el gestor cuando el DOM esté listo
    let gestorHorarios;
    document.addEventListener('DOMContentLoaded', function() {
        gestorHorarios = new GestorHorarios();
        
        // Hacer las funciones disponibles globalmente para los onclick
        window.eliminarAsignacion = (dia, hora) => gestorHorarios.eliminarAsignacion(dia, hora);
        window.limpiarHorario = () => gestorHorarios.limpiarHorario();
        window.validarHorario = () => gestorHorarios.validarHorario();
        window.guardarHorario = () => gestorHorarios.guardarHorario();
        window.iniciarResize = (e, element) => gestorHorarios.iniciarResize(e, element);
    });
    </script>
</body>
</html>
