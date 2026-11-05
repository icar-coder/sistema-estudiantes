<?php
require_once '../../includes/auth.php';
require_once '../../includes/database.php';
checkAuth();
checkRole(['admin']);
error_log("DEBUG seccion.php - seccion_id recibido: " . ($seccion_id ?? 'NULL'));
error_log("DEBUG seccion.php - GET completo: " . print_r($_GET, true));
$seccion_id = $_GET['seccion_id'] ?? null;

if ($seccion_id === null || $seccion_id === '') {
    error_log("DEBUG seccion.php - Redirigiendo a index porque seccion_id es null o vacío");
    header('Location: index.php');
    exit;
}

// Obtener información de la sección
try {
    $stmt = $pdo->prepare("
        SELECT s.*, ae.nombre as año_escolar, ae.id as año_id, g.nombre as grado_nombre
        FROM secciones s 
        JOIN años_escolares ae ON s.año_escolar_id = ae.id 
        LEFT JOIN grados g ON s.grado_id = g.id
        WHERE s.id = ?
    ");
    $stmt->execute([$seccion_id]);
    $seccion = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$seccion) {
        throw new Exception("Sección no encontrada");
    }
    
} catch (Exception $e) {
    $_SESSION['error'] = $e->getMessage();
    header('Location: index.php');
    exit;
}

// Obtener lapsos del año escolar
try {
    $stmt = $pdo->prepare("SELECT * FROM lapsos WHERE año_escolar_id = ? ORDER BY numero");
    $stmt->execute([$seccion['año_id']]);
    $lapsos = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Error al cargar lapsos: " . $e->getMessage());
    $lapsos = [];
}

// Obtener estudiantes de la sección
try {
    $stmt = $pdo->prepare("
        SELECT i.id as inscripcion_id, e.id, e.nombre, e.apellido, e.cedula_escolar
        FROM inscripciones i
        JOIN estudiantes e ON i.estudiante_id = e.id
        WHERE i.seccion_id = ? AND i.año_escolar_id = ? AND i.status = 'activo'
        ORDER BY e.apellido, e.nombre
    ");
    $stmt->execute([$seccion_id, $seccion['año_id']]);
    $estudiantes = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Error al cargar estudiantes: " . $e->getMessage());
    $estudiantes = [];
}

// Obtener áreas de aprendizaje para esta sección (basado en el nivel de la sección)
try {
    $stmt = $pdo->prepare("
        SELECT * FROM areas_aprendizaje 
        WHERE nivel = ? AND estado = 'activa'
        ORDER BY nombre
    ");
    $stmt->execute([$seccion['nivel']]);
    $areas_aprendizaje = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Error al cargar áreas de aprendizaje: " . $e->getMessage());
    $areas_aprendizaje = [];
}

// Obtener literales asignados (para mostrar resumen)
$literales_por_lapso = [];
foreach ($lapsos as $lapso) {
    try {
        $stmt = $pdo->prepare("
            SELECT literal, COUNT(*) as total 
            FROM literales_lapso 
            WHERE lapso_id = ? AND inscripcion_id IN (
                SELECT id FROM inscripciones WHERE seccion_id = ? AND año_escolar_id = ?
            )
            GROUP BY literal
        ");
        $stmt->execute([$lapso['id'], $seccion_id, $seccion['año_id']]);
        $literales_por_lapso[$lapso['id']] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $literales_por_lapso[$lapso['id']] = [];
    }
}

// Obtener estadísticas de evaluación por áreas
$evaluacion_areas_por_lapso = [];
foreach ($lapsos as $lapso) {
    try {
        $stmt = $pdo->prepare("
            SELECT ai.area_id, aa.nombre as area_nombre, 
                   COUNT(DISTINCT ll.inscripcion_id) as estudiantes_evaluados,
                   COUNT(*) as total_evaluaciones
            FROM areas_indicadores ai
            JOIN areas_aprendizaje aa ON ai.area_id = aa.id
            LEFT JOIN literales_lapso ll ON ll.lapso_id = ?
            WHERE aa.nivel = ? AND aa.estado = 'activa'
            GROUP BY ai.area_id, aa.nombre
        ");
        $stmt->execute([$lapso['id'], $seccion['nivel']]);
        $evaluacion_areas_por_lapso[$lapso['id']] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $evaluacion_areas_por_lapso[$lapso['id']] = [];
    }
}
?>

<?php include '../../includes/header.php'; ?>
<?php include '../../includes/sidebar_admin.php'; ?>

<div class="container-fluid">
    <!-- Header -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h2 class="mb-1">
                <i class="fas fa-users me-2"></i><?php echo htmlspecialchars($seccion['nombre']); ?>
            </h2>
            <p class="text-muted mb-0">
                Año Escolar: <?php echo htmlspecialchars($seccion['año_escolar']); ?> | 
                Grado: <?php echo htmlspecialchars($seccion['grado_nombre'] ?? 'N/A'); ?> |
                Nivel: <?php echo ucfirst($seccion['nivel']); ?> |
                Estudiantes: <?php echo count($estudiantes); ?>
            </p>
        </div>
        <a href="index.php" class="btn btn-outline-secondary">
            <i class="fas fa-arrow-left me-2"></i>Volver a Grados
        </a>
    </div>

    <!-- Cards de Lapsos -->
    <div class="row mb-4">
        <?php foreach ($lapsos as $lapso): ?>
            <div class="col-xl-3 col-md-6 mb-3">
                <div class="card h-100 border-0 shadow-sm">
                    <div class="card-header bg-info text-white py-3">
                        <h6 class="card-title mb-0 text-center">
                            <i class="fas fa-calendar-alt me-2"></i><?php echo htmlspecialchars($lapso['nombre']); ?>
                        </h6>
                    </div>
                    <div class="card-body text-center">
                        <?php
                        $total_evaluados = 0;
                        $literales_lapso = $literales_por_lapso[$lapso['id']] ?? [];
                        foreach ($literales_lapso as $literal) {
                            $total_evaluados += $literal['total'];
                        }
                        $porcentaje = count($estudiantes) > 0 ? round(($total_evaluados / count($estudiantes)) * 100) : 0;
                        ?>
                        
                        <h3 class="text-info mb-2"><?php echo $porcentaje; ?>%</h3>
                        <p class="text-muted mb-3">
                            <?php echo $total_evaluados; ?> de <?php echo count($estudiantes); ?> evaluados
                        </p>
                        
                        <div class="progress mb-3" style="height: 10px;">
                            <div class="progress-bar bg-info" style="width: <?php echo $porcentaje; ?>%"></div>
                        </div>
                    </div>
                    <div class="card-footer bg-light text-center">
                        <a href="evaluar_lapso.php?seccion_id=<?php echo $seccion_id; ?>&lapso_id=<?php echo $lapso['id']; ?>" 
                           class="btn btn-info btn-sm">
                            <i class="fas fa-edit me-1"></i>Evaluar Lapso
                        </a>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
        
        <!-- Card para Literal Final -->
        <div class="col-xl-3 col-md-6 mb-3">
            <div class="card h-100 border-0 shadow-sm">
                <div class="card-header bg-warning text-dark py-3">
                    <h6 class="card-title mb-0 text-center">
                        <i class="fas fa-trophy me-2"></i>Literal Final
                    </h6>
                </div>
                <div class="card-body text-center">
                    <?php
                    // Calcular progreso del literal final
                    $total_final_evaluados = 0;
                    try {
                        $stmt = $pdo->prepare("
                            SELECT COUNT(*) as total 
                            FROM literales_finales 
                            WHERE año_escolar_id = ? AND inscripcion_id IN (
                                SELECT id FROM inscripciones WHERE seccion_id = ? AND año_escolar_id = ?
                            )
                        ");
                        $stmt->execute([$seccion['año_id'], $seccion_id, $seccion['año_id']]);
                        $result = $stmt->fetch(PDO::FETCH_ASSOC);
                        $total_final_evaluados = $result['total'] ?? 0;
                    } catch (PDOException $e) {
                        $total_final_evaluados = 0;
                    }
                    $porcentaje_final = count($estudiantes) > 0 ? round(($total_final_evaluados / count($estudiantes)) * 100) : 0;
                    ?>
                    
                    <h3 class="text-warning mb-2"><?php echo $porcentaje_final; ?>%</h3>
                    <p class="text-muted mb-3">
                        <?php echo $total_final_evaluados; ?> de <?php echo count($estudiantes); ?> calculados
                    </p>
                    <div class="progress mb-3" style="height: 10px;">
                        <div class="progress-bar bg-warning" style="width: <?php echo $porcentaje_final; ?>%"></div>
                    </div>
                </div>
                <div class="card-footer bg-light text-center">
                    <a href="literal_final.php?seccion_id=<?php echo $seccion_id; ?>" 
                       class="btn btn-warning btn-sm">
                        <i class="fas fa-calculator me-1"></i>Calcular Final
                    </a>
                </div>
            </div>
        </div>
    </div>

    <!-- Información de Áreas de Aprendizaje -->
    <div class="card mb-4">
        <div class="card-header">
            <h5 class="card-title mb-0">
                <i class="fas fa-book me-2"></i>Áreas de Aprendizaje
            </h5>
        </div>
        <div class="card-body">
            <?php if (empty($areas_aprendizaje)): ?>
                <div class="alert alert-warning text-center">
                    <i class="fas fa-exclamation-triangle me-2"></i>
                    No hay áreas de aprendizaje configuradas para el nivel <?php echo ucfirst($seccion['nivel']); ?>.
                    <a href="../areas_aprendizaje/agregar.php" class="alert-link">Configurar áreas</a>
                </div>
            <?php else: ?>
                <div class="row">
                    <?php foreach ($areas_aprendizaje as $area): ?>
                        <div class="col-md-6 col-lg-4 mb-3">
                            <div class="card border">
                                <div class="card-body p-3">
                                    <h6 class="card-title mb-1">
                                        <span class="badge bg-primary me-2"><?php echo htmlspecialchars($area['codigo']); ?></span>
                                        <?php echo htmlspecialchars($area['nombre']); ?>
                                    </h6>
                                    <p class="card-text small text-muted mb-2">
                                        <?php echo htmlspecialchars($area['descripcion']); ?>
                                    </p>
                                    <div class="d-flex justify-content-between align-items-center">
                                        <small class="text-muted">
                                            <?php
                                            // Contar indicadores para esta área
                                            try {
                                                $stmt = $pdo->prepare("SELECT COUNT(*) as total FROM areas_indicadores WHERE area_id = ? AND estado = 'activo'");
                                                $stmt->execute([$area['id']]);
                                                $result = $stmt->fetch(PDO::FETCH_ASSOC);
                                                echo $result['total'] . ' indicadores';
                                            } catch (PDOException $e) {
                                                echo '0 indicadores';
                                            }
                                            ?>
                                        </small>
                                        <a href="../areas_aprendizaje/gestionar_indicadores.php?area_id=<?php echo $area['id']; ?>" 
                                           class="btn btn-sm btn-outline-primary">
                                            <i class="fas fa-eye me-1"></i>Ver
                                        </a>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Lista de Estudiantes -->
    <div class="card">
        <div class="card-header">
            <h5 class="card-title mb-0">
                <i class="fas fa-list me-2"></i>Estudiantes de la Sección
            </h5>
        </div>
        
        <div class="card-body">
            <?php if (empty($estudiantes)): ?>
                <div class="alert alert-info text-center">
                    <i class="fas fa-info-circle me-2"></i>
                    No hay estudiantes inscritos en esta sección.
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-striped table-hover">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Estudiante</th>
                                <th>Cédula Escolar</th>
                                <?php foreach ($lapsos as $lapso): ?>
                                    <th class="text-center"><?php echo htmlspecialchars($lapso['nombre']); ?></th>
                                <?php endforeach; ?>
                                <th class="text-center">Final</th>
                                <th class="text-center">Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($estudiantes as $index => $estudiante): ?>
                                <tr>
                                    <td><?php echo $index + 1; ?></td>
                                    <td>
                                        <strong><?php echo htmlspecialchars($estudiante['nombre'] . ' ' . $estudiante['apellido']); ?></strong>
                                    </td>
                                    <td>
                                        <?php if ($estudiante['cedula_escolar']): ?>
                                            <span class="badge bg-secondary"><?php echo htmlspecialchars($estudiante['cedula_escolar']); ?></span>
                                        <?php else: ?>
                                            <span class="text-muted">Sin cédula</span>
                                        <?php endif; ?>
                                    </td>
                                    
                                    <?php foreach ($lapsos as $lapso): ?>
                                        <td class="text-center">
                                            <?php
                                            // Buscar literal asignado
                                            $literal_asignado = '--';
                                            try {
                                                $stmt = $pdo->prepare("
                                                    SELECT literal FROM literales_lapso 
                                                    WHERE inscripcion_id = ? AND lapso_id = ?
                                                ");
                                                $stmt->execute([$estudiante['inscripcion_id'], $lapso['id']]);
                                                $result = $stmt->fetch(PDO::FETCH_ASSOC);
                                                if ($result) {
                                                    $literal_asignado = $result['literal'];
                                                    $badge_class = getLiteralBadgeClass($literal_asignado);
                                                    echo '<span class="badge ' . $badge_class . ' literal-badge">' . $literal_asignado . '</span>';
                                                } else {
                                                    echo '<span class="text-muted">--</span>';
                                                }
                                            } catch (PDOException $e) {
                                                echo '<span class="text-muted">--</span>';
                                            }
                                            ?>
                                        </td>
                                    <?php endforeach; ?>
                                    
                                    <td class="text-center">
                                        <?php
                                        // Buscar literal final
                                        try {
                                            $stmt = $pdo->prepare("
                                                SELECT literal_final FROM literales_finales 
                                                WHERE inscripcion_id = ? AND año_escolar_id = ?
                                            ");
                                            $stmt->execute([$estudiante['inscripcion_id'], $seccion['año_id']]);
                                            $result = $stmt->fetch(PDO::FETCH_ASSOC);
                                            if ($result) {
                                                $literal_final = $result['literal_final'];
                                                $badge_class = getLiteralBadgeClass($literal_final);
                                                echo '<span class="badge ' . $badge_class . ' literal-badge">' . $literal_final . '</span>';
                                            } else {
                                                echo '<span class="text-muted">--</span>';
                                            }
                                        } catch (PDOException $e) {
                                            echo '<span class="text-muted">--</span>';
                                        }
                                        ?>
                                    </td>
                                    <td class="text-center">
                                        <!-- Módulo no implementado actualmente: evaluar_estudiante.php -->
                                        <!--
                                        <a href="evaluar_estudiante.php?inscripcion_id=<?php echo $estudiante['inscripcion_id']; ?>&seccion_id=<?php echo $seccion_id; ?>" 
                                           class="btn btn-sm btn-outline-primary" title="Evaluar Estudiante">
                                            <i class="fas fa-edit"></i>
                                        </a>
                                        -->
                                        <span class="text-muted small">Sin acción</span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php
// Función auxiliar para determinar la clase del badge según el literal
function getLiteralBadgeClass($literal) {
    switch ($literal) {
        case 'A': return 'bg-success';
        case 'B': return 'bg-info';
        case 'C': return 'bg-warning';
        case 'D': return 'bg-orange';
        case 'E': return 'bg-danger';
        default: return 'bg-secondary';
    }
}
?>

<style>
.literal-badge {
    font-size: 0.9em;
    min-width: 30px;
}

.card {
    transition: transform 0.2s;
}

.card:hover {
    transform: translateY(-2px);
}

.bg-orange {
    background-color: #fd7e14 !important;
}
</style>

<?php include '../../includes/footer.php'; ?>