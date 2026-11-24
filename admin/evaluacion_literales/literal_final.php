<?php
require_once '../../includes/auth.php';
require_once '../../includes/database.php';
require_once '../../includes/functions_plan_anual.php';
require_once '../../includes/functions_evaluacion.php';
checkAuth();
checkRole(['admin']);
planAnualMigrarCatalogosExistentes($pdo);

$seccion_id = $_GET['seccion_id'] ?? null;

if (!$seccion_id) {
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

// Obtener lapsos del año escolar
try {
    $stmt = $pdo->prepare("SELECT * FROM lapsos WHERE año_escolar_id = ? ORDER BY numero");
    $stmt->execute([$seccion['año_id']]);
    $lapsos = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Error al cargar lapsos: " . $e->getMessage());
    $lapsos = [];
}

// Obtener áreas de aprendizaje con sus indicadores
$areas_con_indicadores = [];
try {
    $stmt = $pdo->prepare("
        SELECT aa.id as area_id, aa.nombre as area_nombre, aa.codigo,
               ai.id as indicador_id, ai.nombre as indicador_nombre, ai.descripcion as indicador_descripcion,
               ai.escala, ai.orden
        FROM plan_anual_areas pea
        JOIN areas_aprendizaje aa ON aa.id = pea.area_id
        LEFT JOIN plan_anual_indicadores pei
          ON pei.año_escolar_id = pea.año_escolar_id
        LEFT JOIN areas_indicadores ai
          ON ai.id = pei.indicador_id AND ai.area_id = aa.id
        WHERE pea.año_escolar_id = ? AND aa.nivel = ?
        ORDER BY aa.nombre, ai.orden
    ");
    $stmt->execute([$seccion['año_id'], $seccion['nivel']]);
    $resultados = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($resultados as $row) {
        $area_id = $row['area_id'];
        if (!isset($areas_con_indicadores[$area_id])) {
            $areas_con_indicadores[$area_id] = [
                'nombre' => $row['area_nombre'],
                'codigo' => $row['codigo'],
                'indicadores' => []
            ];
        }
        
        if ($row['indicador_id']) {
            $areas_con_indicadores[$area_id]['indicadores'][] = [
                'id' => $row['indicador_id'],
                'nombre' => $row['indicador_nombre'],
                'descripcion' => $row['indicador_descripcion'],
                'escala' => $row['escala'],
                'orden' => $row['orden']
            ];
        }
    }
} catch (PDOException $e) {
    error_log("Error al cargar áreas e indicadores: " . $e->getMessage());
}

// NOTA: el sistema actual almacena los indicadores en historial_indicadores,
// pero el cálculo del literal final se basa en literales por lapso.
// Para evitar romper la pantalla, se mantiene este bloque deshabilitado.
$evaluaciones_indicadores = [];
try {
    // Si más adelante se reactivan indicadores históricos, usar:
    // SELECT hi.historial_id, hi.indicador_id, hi.valoracion AS escala_asignada
    // FROM historial_indicadores hi
    // JOIN historial_academico ha ON hi.historial_id = ha.id
    // WHERE ha.estudiante_id IN (
    //     SELECT estudiante_id FROM inscripciones WHERE seccion_id = ? AND año_escolar_id = ?
    // );
    $evaluaciones_indicadores = [];
} catch (PDOException $e) {
    error_log("Historial de indicadores no disponible: " . $e->getMessage());
    $evaluaciones_indicadores = [];
}

// Obtener literales de todos los lapsos y finales para cada estudiante
$literales_estudiantes = [];
foreach ($estudiantes as $estudiante) {
    $literales_estudiantes[$estudiante['inscripcion_id']] = [
        'lapsos' => [],
        'final' => null
    ];
    
    foreach ($lapsos as $lapso) {
        $literal = obtenerLiteralLapso($pdo, $estudiante['inscripcion_id'], $lapso['id']);
        $literales_estudiantes[$estudiante['inscripcion_id']]['lapsos'][$lapso['id']] = $literal;
    }
    
    // Obtener literal final si existe
    $literal_final = obtenerLiteralFinal($pdo, $estudiante['inscripcion_id'], $seccion['año_id']);
    $literales_estudiantes[$estudiante['inscripcion_id']]['final'] = $literal_final;
}

// Colores de fondo para las cards de estudiantes
$colores_fondo = [
    'bg-primary bg-opacity-10',
    'bg-success bg-opacity-10', 
    'bg-info bg-opacity-10',
    'bg-warning bg-opacity-10',
    'bg-danger bg-opacity-10',
    'bg-secondary bg-opacity-10',
    'bg-dark bg-opacity-10',
    'bg-primary bg-opacity-10',
    'bg-success bg-opacity-10',
    'bg-info bg-opacity-10',
    'bg-warning bg-opacity-10',
    'bg-danger bg-opacity-10',
    'bg-secondary bg-opacity-10',
    'bg-dark bg-opacity-10'
];

// Función para calcular literal final automáticamente
function calcularLiteralAutomatico($literales_lapsos) {
    if (empty($literales_lapsos)) {
        return null;
    }
    
    $valores = [
        'A' => 5,
        'B' => 4, 
        'C' => 3,
        'D' => 2,
        'E' => 1
    ];
    
    $suma = 0;
    $contador = 0;
    
    foreach ($literales_lapsos as $literal_data) {
        if ($literal_data && isset($valores[$literal_data['literal']])) {
            $suma += $valores[$literal_data['literal']];
            $contador++;
        }
    }
    
    if ($contador === 0) {
        return null;
    }
    
    $promedio = $suma / $contador;
    
    // Asignar literal según el promedio
    if ($promedio >= 4.5) return 'A';
    if ($promedio >= 3.5) return 'B';
    if ($promedio >= 2.5) return 'C';
    if ($promedio >= 1.5) return 'D';
    return 'E';
}

// Procesar guardado de evaluaciones
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['guardar_indicadores'])) {
        // El modelo actual no usa indicadores en esta pantalla; se deja desactivado.
        $_SESSION['mensaje'] = "La evaluación de indicadores quedó desactivada en este flujo. El cálculo del literal final se hace por lapsos.";
        $_SESSION['tipo_mensaje'] = "info";
        
    } elseif (isset($_POST['calcular_automatico'])) {
        // Calcular automáticamente todos los literales finales
        $calculados = 0;
        
        foreach ($estudiantes as $estudiante) {
            $literales_lapsos = [];
            foreach ($lapsos as $lapso) {
                if (isset($literales_estudiantes[$estudiante['inscripcion_id']]['lapsos'][$lapso['id']])) {
                    $literales_lapsos[] = $literales_estudiantes[$estudiante['inscripcion_id']]['lapsos'][$lapso['id']];
                }
            }
            
            $literal_calculado = calcularLiteralAutomatico($literales_lapsos);
            
            if ($literal_calculado) {
                try {
                    $upsertClause = databaseUpsertClause(
                        $pdo,
                        ['inscripcion_id', 'año_escolar_id'],
                        ['literal_final', 'observaciones_finales', 'evaluado_por', 'fecha_evaluacion']
                    );
                    $stmt = $pdo->prepare("
                        INSERT INTO literales_finales (inscripcion_id, año_escolar_id, literal_final, observaciones_finales, evaluado_por, fecha_evaluacion) 
                        VALUES (?, ?, ?, ?, ?, CURDATE())
                        {$upsertClause}
                    ");
                    
                    $observaciones = "Calculado automáticamente basado en el promedio de literales de los lapsos.";
                    
                    $stmt->execute([
                        $estudiante['inscripcion_id'],
                        $seccion['año_id'],
                        $literal_calculado,
                        $observaciones,
                        $_SESSION['user_id']
                    ]);
                    
                    $calculados++;
                    
                } catch (PDOException $e) {
                    error_log("Error guardando literal final: " . $e->getMessage());
                }
            }
        }
        
        $_SESSION['mensaje'] = "Literales finales calculados automáticamente para $calculados estudiantes";
        $_SESSION['tipo_mensaje'] = "success";
        
    } elseif (isset($_POST['guardar_final'])) {
        // Guardar literal final individualmente
        $inscripcion_id = $_POST['inscripcion_id'];
        $literal = $_POST['literal'];
        $observaciones = trim($_POST['observaciones'] ?? '');
        
        if (!empty($literal)) {
            try {
                $upsertClause = databaseUpsertClause(
                    $pdo,
                    ['inscripcion_id', 'año_escolar_id'],
                    ['literal_final', 'observaciones_finales', 'evaluado_por', 'fecha_evaluacion']
                );
                $stmt = $pdo->prepare("
                    INSERT INTO literales_finales (inscripcion_id, año_escolar_id, literal_final, observaciones_finales, evaluado_por, fecha_evaluacion) 
                    VALUES (?, ?, ?, ?, ?, CURDATE())
                    {$upsertClause}
                ");
                
                $stmt->execute([
                    $inscripcion_id,
                    $seccion['año_id'],
                    $literal,
                    $observaciones,
                    $_SESSION['user_id']
                ]);
                
                $_SESSION['mensaje'] = "Literal final guardado correctamente";
                $_SESSION['tipo_mensaje'] = "success";
                
            } catch (PDOException $e) {
                $_SESSION['mensaje'] = "Error al guardar literal final: " . $e->getMessage();
                $_SESSION['tipo_mensaje'] = "danger";
            }
        } else {
            $_SESSION['mensaje'] = "Debe seleccionar un literal";
            $_SESSION['tipo_mensaje'] = "warning";
        }
    }
    
    header("Location: literal_final.php?seccion_id=$seccion_id&estudiante=$inscripcion_id");
    exit;
}
?>

<?php include '../../includes/header.php'; ?>
<?php include '../../includes/sidebar_admin.php'; ?>

<div class="container-fluid">
    <!-- Header -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h2 class="mb-1">
                <i class="fas fa-trophy me-2"></i>Literal Final del Año
            </h2>
            <p class="text-muted mb-0">
                Sección: <?php echo htmlspecialchars($seccion['nombre']); ?> | 
                Grado: <?php echo htmlspecialchars($seccion['grado_nombre'] ?? 'N/A'); ?> |
                Año: <?php echo htmlspecialchars($seccion['año_escolar']); ?>
            </p>
        </div>
        <div>
            <a href="seccion.php?seccion_id=<?php echo $seccion_id; ?>" class="btn btn-outline-secondary">
                <i class="fas fa-arrow-left me-2"></i>Volver a Sección
            </a>
        </div>
    </div>

    <?php if (isset($_SESSION['mensaje'])): ?>
        <div class="alert alert-<?php echo $_SESSION['tipo_mensaje']; ?> alert-dismissible fade show" role="alert">
            <?php echo $_SESSION['mensaje']; ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php unset($_SESSION['mensaje'], $_SESSION['tipo_mensaje']); ?>
    <?php endif; ?>

    <!-- Resumen General -->
    <div class="row mb-4">
        <div class="col-md-3">
            <div class="card border-0 bg-primary text-white">
                <div class="card-body text-center py-3">
                    <h3 class="mb-1"><?php echo count($estudiantes); ?></h3>
                    <p class="mb-0">Estudiantes</p>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-0 bg-success text-white">
                <div class="card-body text-center py-3">
                    <?php
                    $completos = 0;
                    foreach ($estudiantes as $estudiante) {
                        if ($literales_estudiantes[$estudiante['inscripcion_id']]['final']) {
                            $completos++;
                        }
                    }
                    ?>
                    <h3 class="mb-1"><?php echo $completos; ?></h3>
                    <p class="mb-0">Con Final</p>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-0 bg-warning text-dark">
                <div class="card-body text-center py-3">
                    <?php
                    $listos = 0;
                    foreach ($estudiantes as $estudiante) {
                        $todos_lapsos = true;
                        foreach ($lapsos as $lapso) {
                            if (!$literales_estudiantes[$estudiante['inscripcion_id']]['lapsos'][$lapso['id']]) {
                                $todos_lapsos = false;
                                break;
                            }
                        }
                        if ($todos_lapsos && !$literales_estudiantes[$estudiante['inscripcion_id']]['final']) {
                            $listos++;
                        }
                    }
                    ?>
                    <h3 class="mb-1"><?php echo $listos; ?></h3>
                    <p class="mb-0">Listos para Calcular</p>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-0 bg-info text-white">
                <div class="card-body text-center py-3">
                    <?php
                    $con_indicadores = 0;
                    ?>
                    <h3 class="mb-1"><?php echo $con_indicadores; ?></h3>
                    <p class="mb-0">Con Indicadores</p>
                </div>
            </div>
        </div>
    </div>

    <!-- Panel Principal -->
    <div class="row">
        <!-- Panel de Estudiantes (Cards pequeñas) -->
        <div class="col-md-4 col-lg-3">
            <div class="card">
                <div class="card-header bg-primary text-white">
                    <h6 class="card-title mb-0">
                        <i class="fas fa-users me-2"></i>Estudiantes
                        <span class="badge bg-light text-dark ms-2"><?php echo count($estudiantes); ?></span>
                    </h6>
                </div>
                <div class="card-body p-2" style="max-height: 600px; overflow-y: auto;">
                    <!-- Botón de Cálculo Automático -->
                    <div class="mb-3">
                        <form method="POST" class="d-grid">
                            <button type="submit" name="calcular_automatico" class="btn btn-success btn-sm" 
                                    <?php echo empty($estudiantes) ? 'disabled' : ''; ?>>
                                <i class="fas fa-bolt me-1"></i>Calcular Automático
                            </button>
                        </form>
                    </div>

                    <!-- Lista de Estudiantes -->
                    <div class="row g-2" id="lista-estudiantes">
                        <?php foreach ($estudiantes as $index => $estudiante): 
                            $evaluacion_final = $literales_estudiantes[$estudiante['inscripcion_id']]['final'];
                            $color_index = $index % count($colores_fondo);
                            $color_clase = $colores_fondo[$color_index];
                            $activo = ($_GET['estudiante'] ?? '') == $estudiante['inscripcion_id'] ? 'active' : '';
                            
                            // Verificar si tiene todos los lapsos
                            $todos_lapsos = true;
                            foreach ($lapsos as $lapso) {
                                if (!$literales_estudiantes[$estudiante['inscripcion_id']]['lapsos'][$lapso['id']]) {
                                    $todos_lapsos = false;
                                    break;
                                }
                            }
                        ?>
                            <div class="col-6">
                                <div class="card student-mini-card <?php echo $color_clase; ?> <?php echo $activo; ?>" 
                                    data-inscripcion="<?php echo $estudiante['inscripcion_id']; ?>"
                                    data-nombre="<?php echo htmlspecialchars($estudiante['nombre'] . ' ' . $estudiante['apellido']); ?>">
                                    <div class="card-body p-2 text-center">
                                        <div class="student-avatar mb-1">
                                            <i class="fas fa-user fa-lg"></i>
                                        </div>
                                        <h6 class="card-title mb-1 small text-truncate">
                                            <?php echo htmlspecialchars($estudiante['nombre'] . ' ' . $estudiante['apellido']); ?>
                                        </h6>
                                        <div class="student-status">
                                            <?php if ($evaluacion_final): ?>
                                                <span class="badge bg-<?php echo getLiteralColor($evaluacion_final['literal_final']); ?>">
                                                    <?php echo $evaluacion_final['literal_final']; ?>
                                                </span>
                                            <?php elseif ($todos_lapsos): ?>
                                                <span class="badge bg-warning text-dark">Listo</span>
                                            <?php else: ?>
                                                <span class="badge bg-danger">Faltan</span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <!-- Resumen de Literales -->
            <div class="card mt-3">
                <div class="card-header bg-info text-white">
                    <h6 class="card-title mb-0">
                        <i class="fas fa-chart-pie me-2"></i>Distribución
                    </h6>
                </div>
                <div class="card-body">
                    <?php
                    $conteo_literales = ['A' => 0, 'B' => 0, 'C' => 0, 'D' => 0, 'E' => 0, 'sin' => 0];
                    foreach ($estudiantes as $estudiante) {
                        $literal_final = $literales_estudiantes[$estudiante['inscripcion_id']]['final'];
                        if ($literal_final) {
                            $conteo_literales[$literal_final['literal_final']]++;
                        } else {
                            $conteo_literales['sin']++;
                        }
                    }
                    ?>
                    <div class="small">
                        <?php foreach (['A', 'B', 'C', 'D', 'E'] as $literal): ?>
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <div class="d-flex align-items-center">
                                    <span class="badge bg-<?php echo getLiteralColor($literal); ?> me-2"><?php echo $literal; ?></span>
                                    <span><?php echo getLiteralNombre($literal); ?></span>
                                </div>
                                <span class="fw-bold"><?php echo $conteo_literales[$literal]; ?></span>
                            </div>
                        <?php endforeach; ?>
                        <hr>
                        <div class="d-flex justify-content-between align-items-center">
                            <span class="text-muted">Sin evaluar</span>
                            <span class="badge bg-secondary"><?php echo $conteo_literales['sin']; ?></span>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Panel de Evaluación Final -->
        <div class="col-md-8 col-lg-9">
            <?php 
            $estudiante_actual_id = $_GET['estudiante'] ?? ($estudiantes[0]['inscripcion_id'] ?? null);
            $estudiante_actual = null;
            
            if ($estudiante_actual_id) {
                foreach ($estudiantes as $est) {
                    if ($est['inscripcion_id'] == $estudiante_actual_id) {
                        $estudiante_actual = $est;
                        break;
                    }
                }
            }
            ?>

            <?php if ($estudiante_actual): ?>
                <?php 
                $evaluacion_final = $literales_estudiantes[$estudiante_actual['inscripcion_id']]['final'];
                $color_index = array_search($estudiante_actual['inscripcion_id'], array_column($estudiantes, 'inscripcion_id')) % count($colores_fondo);
                $color_clase = $colores_fondo[$color_index];
                ?>
                
                <div class="card <?php echo $color_clase; ?> border-0 shadow">
                    <div class="card-header bg-transparent">
                        <div class="d-flex justify-content-between align-items-center">
                            <h4 class="card-title mb-0">
                                <i class="fas fa-user-graduate me-2"></i>
                                <?php echo htmlspecialchars($estudiante_actual['nombre'] . ' ' . $estudiante_actual['apellido']); ?>
                            </h4>
                            <div>
                                <?php if ($evaluacion_final): ?>
                                    <span class="badge bg-<?php echo getLiteralColor($evaluacion_final['literal_final']); ?> fs-6">
                                        Literal Final: <?php echo $evaluacion_final['literal_final']; ?>
                                    </span>
                                <?php else: ?>
                                    <span class="badge bg-secondary fs-6">Sin literal final</span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    
                    <div class="card-body">
                        <!-- Información del Estudiante -->
                        <div class="row mb-4">
                            <div class="col-md-4">
                                <strong>Cédula:</strong> 
                                <?php echo $estudiante_actual['cedula_escolar'] ? htmlspecialchars($estudiante_actual['cedula_escolar']) : 'Sin cédula'; ?>
                            </div>
                            <div class="col-md-4">
                                <strong>Número:</strong> 
                                <?php echo array_search($estudiante_actual['inscripcion_id'], array_column($estudiantes, 'inscripcion_id')) + 1; ?>
                            </div>
                            <div class="col-md-4">
                                <strong>Estado:</strong> 
                                <?php
                                $todos_lapsos = true;
                                $lapsos_faltantes = [];
                                foreach ($lapsos as $lapso) {
                                    if (!$literales_estudiantes[$estudiante_actual['inscripcion_id']]['lapsos'][$lapso['id']]) {
                                        $todos_lapsos = false;
                                        $lapsos_faltantes[] = $lapso['nombre'];
                                    }
                                }
                                
                                if ($evaluacion_final) {
                                    echo '<span class="badge bg-success">Completo</span>';
                                } elseif ($todos_lapsos) {
                                    echo '<span class="badge bg-warning text-dark">Listo para calcular</span>';
                                } else {
                                    echo '<span class="badge bg-danger">Faltan lapsos: ' . implode(', ', $lapsos_faltantes) . '</span>';
                                }
                                ?>
                            </div>
                        </div>

                        <!-- Resumen de Lapsos -->
                        <div class="card mb-4">
                            <div class="card-header bg-light">
                                <h6 class="card-title mb-0">
                                    <i class="fas fa-calendar-alt me-2"></i>Literales por Lapso
                                </h6>
                            </div>
                            <div class="card-body">
                                <div class="row text-center">
                                    <?php foreach ($lapsos as $lapso): 
                                        $literal_lapso = $literales_estudiantes[$estudiante_actual['inscripcion_id']]['lapsos'][$lapso['id']];
                                    ?>
                                        <div class="col-md-4 mb-3">
                                            <div class="border rounded p-3">
                                                <h6 class="text-primary mb-2"><?php echo $lapso['nombre']; ?></h6>
                                                <?php if ($literal_lapso): ?>
                                                    <span class="badge bg-<?php echo getLiteralColor($literal_lapso['literal']); ?> fs-5">
                                                        <?php echo $literal_lapso['literal']; ?>
                                                    </span>
                                                <?php else: ?>
                                                    <span class="text-muted">--</span>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>

                        <!-- Cálculo Sugerido y Literal Final -->
                        <?php
                        $literales_lapsos = [];
                        foreach ($lapsos as $lapso) {
                            if ($literales_estudiantes[$estudiante_actual['inscripcion_id']]['lapsos'][$lapso['id']]) {
                                $literales_lapsos[] = $literales_estudiantes[$estudiante_actual['inscripcion_id']]['lapsos'][$lapso['id']];
                            }
                        }
                        $literal_sugerido = calcularLiteralAutomatico($literales_lapsos);
                        ?>

                        <?php if ($literal_sugerido): ?>
                            <div class="alert alert-info">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <i class="fas fa-lightbulb me-2"></i>
                                        <strong>Sugerencia automática:</strong> 
                                        Basado en los literales de los lapsos, el literal sugerido es 
                                        <span class="badge bg-<?php echo getLiteralColor($literal_sugerido); ?> fs-6">
                                            <?php echo $literal_sugerido; ?>
                                        </span>
                                    </div>
                                    <button type="button" class="btn btn-outline-info btn-sm" 
                                            onclick="document.querySelector('select[name=\"literal\"]').value = '<?php echo $literal_sugerido; ?>';">
                                        Usar Sugerencia
                                    </button>
                                </div>
                            </div>
                        <?php endif; ?>

                        <!-- Asignación de Literal Final -->
                        <div class="card">
                            <div class="card-header bg-success text-white">
                                <h5 class="card-title mb-0">
                                    <i class="fas fa-trophy me-2"></i>Literal Final del Año
                                </h5>
                            </div>
                            <div class="card-body">
                                <form method="POST" action="" id="form-final">
                                    <input type="hidden" name="guardar_final" value="1">
                                    <input type="hidden" name="inscripcion_id" value="<?php echo $estudiante_actual['inscripcion_id']; ?>">
                                    
                                    <div class="row">
                                        <div class="col-md-6">
                                            <div class="mb-3">
                                                <label class="form-label fw-bold fs-5">Literal Final:</label>
                                                <select class="form-select form-select-lg literal-final" name="literal" required>
                                                    <option value="">Seleccionar literal final...</option>
                                                    <option value="A" <?php echo ($evaluacion_final && $evaluacion_final['literal_final'] === 'A') ? 'selected' : ''; ?>>A - Excelente</option>
                                                    <option value="B" <?php echo ($evaluacion_final && $evaluacion_final['literal_final'] === 'B') ? 'selected' : ''; ?>>B - Muy Bueno</option>
                                                    <option value="C" <?php echo ($evaluacion_final && $evaluacion_final['literal_final'] === 'C') ? 'selected' : ''; ?>>C - Bueno</option>
                                                    <option value="D" <?php echo ($evaluacion_final && $evaluacion_final['literal_final'] === 'D') ? 'selected' : ''; ?>>D - Regular</option>
                                                    <option value="E" <?php echo ($evaluacion_final && $evaluacion_final['literal_final'] === 'E') ? 'selected' : ''; ?>>E - Deficiente</option>
                                                </select>
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="mb-3">
                                                <label class="form-label fw-bold fs-5">Observaciones Finales:</label>
                                                <textarea class="form-control" name="observaciones" rows="3" 
                                                          placeholder="Observaciones generales del desempeño anual..."
                                                          maxlength="255"><?php echo $evaluacion_final ? htmlspecialchars($evaluacion_final['observaciones_finales']) : ''; ?></textarea>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <div class="text-end">
                                        <button type="submit" class="btn btn-success btn-lg">
                                            <i class="fas fa-save me-2"></i>Guardar Literal Final
                                        </button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
            <?php else: ?>
                <div class="card">
                    <div class="card-body text-center py-5">
                        <i class="fas fa-user-slash fa-3x text-muted mb-3"></i>
                        <h5 class="text-muted">Seleccione un estudiante</h5>
                        <p class="text-muted">Haga clic en una tarjeta de estudiante para asignar el literal final.</p>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php
// Funciones auxiliares
function getLiteralColor($literal) {
    switch ($literal) {
        case 'A': return 'success';
        case 'B': return 'info';
        case 'C': return 'primary';
        case 'D': return 'warning';
        case 'E': return 'danger';
        default: return 'secondary';
    }
}

function getLiteralNombre($literal) {
    switch ($literal) {
        case 'A': return 'Excelente';
        case 'B': return 'Muy Bueno';
        case 'C': return 'Bueno';
        case 'D': return 'Regular';
        case 'E': return 'Deficiente';
        default: return 'Sin evaluar';
    }
}
?>

<style>
.student-mini-card {
    cursor: pointer;
    transition: all 0.3s ease;
    height: 100px;
}

.student-mini-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 8px rgba(0,0,0,0.2);
}

.student-mini-card.active {
    border: 3px solid #0d6efd !important;
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(13, 110, 253, 0.3);
}

.student-avatar {
    color: #6c757d;
}

.bg-opacity-10 {
    --bs-bg-opacity: 0.1;
}

.literal-final {
    border-width: 3px;
    font-weight: 600;
}

.literal-final:focus {
    box-shadow: 0 0 0 0.25rem rgba(13, 110, 253, 0.25);
}

.escala-select {
    border-width: 2px;
    font-size: 0.8rem;
}

.accordion-button:not(.collapsed) {
    background-color: #e7f1ff;
    color: #0c63e4;
}

.table th {
    font-size: 0.8rem;
    font-weight: 600;
}
</style>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Cambiar estudiante al hacer clic en mini-card
    document.querySelectorAll('.student-mini-card').forEach(card => {
        card.addEventListener('click', function() {
            const inscripcionId = this.dataset.inscripcion;
            
            // Remover clase active de todas las cards
            document.querySelectorAll('.student-mini-card').forEach(c => {
                c.classList.remove('active');
            });
            
            // Agregar clase active a la card clickeada
            this.classList.add('active');
            
            // Redirigir para cargar el estudiante
            window.location.href = `literal_final.php?seccion_id=<?php echo $seccion_id; ?>&estudiante=${inscripcionId}`;
        });
    });

    // Efecto visual en los selects
    function aplicarEstilosSelects() {
        // Literal final
        const selectLiteral = document.querySelector('.literal-final');
        if (selectLiteral) {
            selectLiteral.addEventListener('change', function() {
                this.classList.remove('border-success', 'border-info', 'border-primary', 'border-warning', 'border-danger');
                switch(this.value) {
                    case 'A': this.classList.add('border-success'); break;
                    case 'B': this.classList.add('border-info'); break;
                    case 'C': this.classList.add('border-primary'); break;
                    case 'D': this.classList.add('border-warning'); break;
                    case 'E': this.classList.add('border-danger'); break;
                }
            });
            selectLiteral.dispatchEvent(new Event('change'));
        }

        // Escalas de indicadores
        document.querySelectorAll('.escala-select').forEach(select => {
            select.addEventListener('change', function() {
                this.classList.remove('border-success', 'border-info', 'border-primary', 'border-warning', 'border-danger');
                switch(this.value) {
                    case 'LE': this.classList.add('border-success'); break;
                    case 'L': this.classList.add('border-info'); break;
                    case 'PA': this.classList.add('border-primary'); break;
                    case 'IP': this.classList.add('border-warning'); break;
                    case 'NL': this.classList.add('border-danger'); break;
                }
            });
            select.dispatchEvent(new Event('change'));
        });
    }

    // Aplicar estilos cuando se cargue el modal de áreas
    const accordion = document.getElementById('accordionAreas');
    if (accordion) {
        accordion.addEventListener('shown.bs.collapse', aplicarEstilosSelects);
    }

    // Aplicar estilos iniciales
    aplicarEstilosSelects();

    // Navegación con teclado
    document.addEventListener('keydown', function(e) {
        if (e.altKey) {
            const cards = Array.from(document.querySelectorAll('.student-mini-card'));
            const cardActiva = document.querySelector('.student-mini-card.active');
            const indexActual = cards.indexOf(cardActiva);
            
            if (e.key === 'ArrowRight' && indexActual < cards.length - 1) {
                cards[indexActual + 1].click();
            } else if (e.key === 'ArrowLeft' && indexActual > 0) {
                cards[indexActual - 1].click();
            }
        }
    });
});
</script>

<?php include '../../includes/footer.php'; ?>