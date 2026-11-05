<?php
// admin/inscripciones/ver_detalles_adelantamiento.php
require_once '../../includes/auth.php';
require_once '../../includes/database.php';
checkAuth();
checkRole(['admin']);

$historial_id = $_GET['id'] ?? null;

if (!$historial_id) {
    echo '<div class="alert alert-danger">No se especificó el ID del adelantamiento.</div>';
    exit;
}

try {
    // 1. Datos del historial académico
    $stmt = $pdo->prepare("
        SELECT 
            ha.*,
            e.cedula_escolar,
            e.nombre AS estudiante_nombre,
            e.apellido AS estudiante_apellido,
            e.fecha_nacimiento,
            e.genero,
            ae.nombre AS año_escolar,
            g.nombre AS grado_anterior,
            g.numero AS grado_numero
        FROM historial_academico ha
        JOIN estudiantes e ON ha.estudiante_id = e.id
        JOIN años_escolares ae ON ha.año_escolar_id = ae.id
        JOIN grados g ON ha.grado_id = g.id
        WHERE ha.id = ?
    ");
    $stmt->execute([$historial_id]);
    $adelanto = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$adelanto) {
        echo '<div class="alert alert-warning">Adelantamiento no encontrado.</div>';
        exit;
    }
    
    // 2. Indicadores evaluados
    $stmt = $pdo->prepare("
        SELECT 
            ai.nombre AS indicador_nombre,
            aa.nombre AS area_nombre,
            aa.codigo AS area_codigo,
            hi.valoracion
        FROM historial_indicadores hi
        JOIN areas_indicadores ai ON hi.indicador_id = ai.id
        JOIN areas_aprendizaje aa ON ai.area_id = aa.id
        WHERE hi.historial_id = ?
        ORDER BY aa.nombre, ai.orden
    ");
    $stmt->execute([$historial_id]);
    $indicadores = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // 3. Situación actual del estudiante (inscripción activa)
    $stmt = $pdo->prepare("
        SELECT 
            s.nombre AS seccion_actual,
            g_act.nombre AS grado_actual,
            ae_act.nombre AS año_actual,
            p.nombre AS profesor_nombre,
            p.apellido AS profesor_apellido
        FROM inscripciones i
        JOIN secciones s ON i.seccion_id = s.id
        JOIN grados g_act ON s.grado_id = g_act.id
        JOIN años_escolares ae_act ON i.año_escolar_id = ae_act.id
        LEFT JOIN profesores p ON s.profesor_id = p.id
        WHERE i.estudiante_id = ? AND i.status = 'activo'
        ORDER BY i.fecha_inscripcion DESC
        LIMIT 1
    ");
    $stmt->execute([$adelanto['estudiante_id']]);
    $situacion_actual = $stmt->fetch(PDO::FETCH_ASSOC);
    
} catch (PDOException $e) {
    echo '<div class="alert alert-danger">Error al cargar los datos: ' . htmlspecialchars($e->getMessage()) . '</div>';
    exit;
}

// Etiquetas y colores
$tipos = [
    'academico'     => ['label' => 'Mérito Académico',                'badge' => 'success', 'icon' => 'fa-trophy'],
    'transferencia' => ['label' => 'Transferencia de Otra Institución', 'badge' => 'info',    'icon' => 'fa-exchange-alt'],
    'otros'         => ['label' => 'Otros Motivos',                    'badge' => 'warning', 'icon' => 'fa-ellipsis-h']
];
$tipo_info = $tipos[$adelanto['tipo_adelantamiento']] ?? ['label' => 'No especificado', 'badge' => 'secondary', 'icon' => 'fa-question'];

$literales_color = ['A' => 'success', 'B' => 'info', 'C' => 'primary', 'D' => 'warning', 'E' => 'danger'];
$color_literal = $literales_color[$adelanto['literal_final']] ?? 'secondary';

$edad = $adelanto['fecha_nacimiento'] 
    ? (new DateTime($adelanto['fecha_nacimiento']))->diff(new DateTime())->y 
    : null;
?>

<!-- Encabezado del estudiante -->
<div class="card mb-3 border-0 bg-light">
    <div class="card-body py-3">
        <div class="d-flex align-items-center">
            <div class="me-3">
                <div class="rounded-circle bg-primary text-white d-flex align-items-center justify-content-center"
                     style="width: 60px; height: 60px; font-size: 1.5rem;">
                    <i class="fas fa-user-graduate"></i>
                </div>
            </div>
            <div class="flex-grow-1">
                <h5 class="mb-1">
                    <?php echo htmlspecialchars($adelanto['estudiante_apellido'] . ', ' . $adelanto['estudiante_nombre']); ?>
                </h5>
                <div class="text-muted small">
                    <?php if ($adelanto['cedula_escolar']): ?>
                        <i class="fas fa-id-card me-1"></i><?php echo htmlspecialchars($adelanto['cedula_escolar']); ?>
                    <?php endif; ?>
                    <?php if ($edad !== null): ?>
                        &nbsp;|&nbsp;<i class="fas fa-birthday-cake me-1"></i><?php echo $edad; ?> años
                    <?php endif; ?>
                    <?php if ($adelanto['genero']): ?>
                        &nbsp;|&nbsp;<i class="fas fa-<?php echo $adelanto['genero'] === 'masculino' ? 'mars' : 'venus'; ?> me-1"></i>
                        <?php echo ucfirst($adelanto['genero']); ?>
                    <?php endif; ?>
                </div>
            </div>
            <div>
                <span class="badge bg-<?php echo $color_literal; ?> fs-5 px-3 py-2">
                    Literal <?php echo htmlspecialchars($adelanto['literal_final']); ?>
                </span>
            </div>
        </div>
    </div>
</div>

<!-- Datos del adelantamiento -->
<div class="row g-3 mb-3">
    <div class="col-md-6">
        <div class="card h-100">
            <div class="card-header bg-white">
                <h6 class="mb-0 text-primary">
                    <i class="fas fa-info-circle me-2"></i>Información del Adelantamiento
                </h6>
            </div>
            <div class="card-body py-2">
                <table class="table table-sm table-borderless mb-0">
                    <tr>
                        <td width="45%" class="text-muted">Tipo:</td>
                        <td>
                            <span class="badge bg-<?php echo $tipo_info['badge']; ?>">
                                <i class="fas <?php echo $tipo_info['icon']; ?> me-1"></i>
                                <?php echo htmlspecialchars($tipo_info['label']); ?>
                            </span>
                        </td>
                    </tr>
                    <tr>
                        <td class="text-muted">Grado anterior:</td>
                        <td><strong><?php echo htmlspecialchars($adelanto['grado_anterior']); ?></strong></td>
                    </tr>
                    <tr>
                        <td class="text-muted">Año escolar:</td>
                        <td><?php echo htmlspecialchars($adelanto['año_escolar']); ?></td>
                    </tr>
                    <tr>
                        <td class="text-muted">Fecha de registro:</td>
                        <td><?php echo date('d/m/Y H:i', strtotime($adelanto['fecha_registro'])); ?></td>
                    </tr>
                </table>
            </div>
        </div>
    </div>
    
    <div class="col-md-6">
        <div class="card h-100">
            <div class="card-header bg-white">
                <h6 class="mb-0 text-success">
                    <i class="fas fa-map-marker-alt me-2"></i>Situación Actual
                </h6>
            </div>
            <div class="card-body py-2">
                <?php if ($situacion_actual): ?>
                    <table class="table table-sm table-borderless mb-0">
                        <tr>
                            <td width="45%" class="text-muted">Grado actual:</td>
                            <td><strong><?php echo htmlspecialchars($situacion_actual['grado_actual']); ?></strong></td>
                        </tr>
                        <tr>
                            <td class="text-muted">Sección:</td>
                            <td>
                                <span class="badge bg-info">
                                    <?php echo htmlspecialchars($situacion_actual['seccion_actual']); ?>
                                </span>
                            </td>
                        </tr>
                        <tr>
                            <td class="text-muted">Año escolar:</td>
                            <td><?php echo htmlspecialchars($situacion_actual['año_actual']); ?></td>
                        </tr>
                        <?php if ($situacion_actual['profesor_nombre']): ?>
                        <tr>
                            <td class="text-muted">Profesor titular:</td>
                            <td><?php echo htmlspecialchars($situacion_actual['profesor_nombre'] . ' ' . $situacion_actual['profesor_apellido']); ?></td>
                        </tr>
                        <?php endif; ?>
                    </table>
                <?php else: ?>
                    <div class="alert alert-warning py-2 mb-0 small">
                        <i class="fas fa-exclamation-triangle me-1"></i>
                        El estudiante no tiene una inscripción activa actualmente.
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Observaciones -->
<?php if (!empty($adelanto['observaciones'])): ?>
<div class="card mb-3">
    <div class="card-header bg-white">
        <h6 class="mb-0">
            <i class="fas fa-comment-alt me-2"></i>Observaciones
        </h6>
    </div>
    <div class="card-body py-2">
        <p class="mb-0 small"><?php echo nl2br(htmlspecialchars($adelanto['observaciones'])); ?></p>
    </div>
</div>
<?php endif; ?>

<!-- Indicadores evaluados -->
<div class="card">
    <div class="card-header bg-white d-flex justify-content-between align-items-center">
        <h6 class="mb-0">
            <i class="fas fa-list-check me-2"></i>Indicadores Evaluados
        </h6>
        <span class="badge bg-secondary"><?php echo count($indicadores); ?> indicador(es)</span>
    </div>
    <div class="card-body p-0">
        <?php if (!empty($indicadores)): ?>
            <div class="table-responsive">
                <table class="table table-sm table-hover mb-0">
                    <thead class="table-light">
                        <tr>
                            <th width="10%">Área</th>
                            <th width="65%">Indicador</th>
                            <th width="25%" class="text-center">Valoración</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $area_actual = '';
                        foreach ($indicadores as $ind):
                            $nueva_area = ($area_actual !== $ind['area_nombre']);
                            if ($nueva_area) $area_actual = $ind['area_nombre'];
                            
                            $colores_val = ['LE' => 'success', 'L' => 'info', 'PA' => 'primary', 'IP' => 'warning', 'NL' => 'danger'];
                            $color_val = $colores_val[$ind['valoracion']] ?? 'secondary';
                        ?>
                        <tr>
                            <td>
                                <span class="badge bg-dark small"><?php echo htmlspecialchars($ind['area_codigo']); ?></span>
                            </td>
                            <td>
                                <?php if ($nueva_area): ?>
                                    <div class="small text-muted mb-1">
                                        <strong><?php echo htmlspecialchars($ind['area_nombre']); ?></strong>
                                    </div>
                                <?php endif; ?>
                                <span class="small"><?php echo htmlspecialchars($ind['indicador_nombre']); ?></span>
                            </td>
                            <td class="text-center">
                                <span class="badge bg-<?php echo $color_val; ?>">
                                    <?php echo htmlspecialchars($ind['valoracion']); ?>
                                </span>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <div class="p-2 small text-muted border-top">
                <strong>LEYENDA:</strong>
                <span class="badge bg-success">LE</span> Logrado con Excelencia ·
                <span class="badge bg-info">L</span> Logrado ·
                <span class="badge bg-primary">PA</span> Proceso Avanzado ·
                <span class="badge bg-warning">IP</span> Iniciado en el Proceso ·
                <span class="badge bg-danger">NL</span> No Logrado
            </div>
        <?php else: ?>
            <div class="p-4 text-center text-muted">
                <i class="fas fa-inbox fa-2x mb-2"></i>
                <p class="mb-0 small">No se registraron indicadores para este adelantamiento.</p>
            </div>
        <?php endif; ?>
    </div>
</div>