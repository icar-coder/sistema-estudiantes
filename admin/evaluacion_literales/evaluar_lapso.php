<?php
require_once '../../includes/auth.php';
require_once '../../includes/database.php';
checkAuth();
checkRole(['admin']);

$seccion_id = $_GET['seccion_id'] ?? null;
$lapso_id = $_GET['lapso_id'] ?? null;

if (!$seccion_id || !$lapso_id) {
    header('Location: index.php');
    exit;
}

// Obtener información de la sección y lapso
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
    
    $stmt = $pdo->prepare("SELECT * FROM lapsos WHERE id = ?");
    $stmt->execute([$lapso_id]);
    $lapso = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$lapso) {
        throw new Exception("Lapso no encontrado");
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

// Obtener áreas de aprendizaje con sus indicadores
$areas_con_indicadores = [];
try {
    $stmt = $pdo->prepare("
        SELECT aa.id as area_id, aa.nombre as area_nombre, aa.codigo,
               ai.id as indicador_id, ai.nombre as indicador_nombre, ai.descripcion as indicador_descripcion,
               ai.escala, ai.orden
        FROM areas_aprendizaje aa
        LEFT JOIN areas_indicadores ai ON aa.id = ai.area_id AND ai.estado = 'activo'
        WHERE aa.nivel = ? AND aa.estado = 'activa'
        ORDER BY aa.nombre, ai.orden
    ");
    $stmt->execute([$seccion['nivel']]);
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

// Obtener evaluaciones previas
$evaluaciones_previas = [];
try {
    $stmt = $pdo->prepare("
        SELECT ll.inscripcion_id, ll.literal, ll.observaciones
        FROM literales_lapso ll
        WHERE ll.lapso_id = ? AND ll.inscripcion_id IN (
            SELECT id FROM inscripciones WHERE seccion_id = ?
        )
    ");
    $stmt->execute([$lapso_id, $seccion_id]);
    $resultados = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($resultados as $resultado) {
        $evaluaciones_previas[$resultado['inscripcion_id']] = [
            'literal' => $resultado['literal'],
            'observaciones' => $resultado['observaciones']
        ];
    }
} catch (PDOException $e) {
    error_log("Error al cargar evaluaciones previas: " . $e->getMessage());
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
    'bg-dark bg-opacity-10',
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
    'bg-warning bg-opacity-10'
];

// Procesar guardado de evaluaciones
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['guardar_evaluacion'])) {
    $inscripcion_id = $_POST['inscripcion_id'];
    $literal = $_POST['literal'];
    $observaciones = trim($_POST['observaciones'] ?? '');
    
    if (!empty($literal)) {
        try {
            $upsertClause = databaseUpsertClause(
                $pdo,
                ['inscripcion_id', 'lapso_id'],
                ['literal', 'observaciones', 'evaluado_por', 'fecha_evaluacion']
            );
            $stmt = $pdo->prepare("
                INSERT INTO literales_lapso (inscripcion_id, lapso_id, literal, observaciones, evaluado_por, fecha_evaluacion) 
                VALUES (?, ?, ?, ?, ?, CURDATE())
                {$upsertClause}
            ");
            
            $stmt->execute([
                $inscripcion_id,
                $lapso_id,
                $literal,
                $observaciones,
                $_SESSION['user_id']
            ]);
            
            $_SESSION['mensaje'] = "Evaluación guardada correctamente";
            $_SESSION['tipo_mensaje'] = "success";
            
        } catch (PDOException $e) {
            $_SESSION['mensaje'] = "Error al guardar evaluación: " . $e->getMessage();
            $_SESSION['tipo_mensaje'] = "danger";
        }
    } else {
        $_SESSION['mensaje'] = "Debe seleccionar un literal";
        $_SESSION['tipo_mensaje'] = "warning";
    }
    
    header("Location: evaluar_lapso.php?seccion_id=$seccion_id&lapso_id=$lapso_id&estudiante=$inscripcion_id");
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
                <i class="fas fa-edit me-2"></i>Evaluación por Indicadores
            </h2>
            <p class="text-muted mb-0">
                Sección: <?php echo htmlspecialchars($seccion['nombre']); ?> | 
                Grado: <?php echo htmlspecialchars($seccion['grado_nombre'] ?? 'N/A'); ?> |
                Lapso: <?php echo htmlspecialchars($lapso['nombre']); ?>
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
                    <div class="row g-2" id="lista-estudiantes">
                        <?php foreach ($estudiantes as $index => $estudiante): 
                            $evaluacion_prev = $evaluaciones_previas[$estudiante['inscripcion_id']] ?? null;
                            $color_index = $index % count($colores_fondo);
                            $color_clase = $colores_fondo[$color_index];
                            $activo = ($_GET['estudiante'] ?? '') == $estudiante['inscripcion_id'] ? 'active' : '';
                        ?>
                            <div class="col-6">
                                <div class="card student-mini-card <?php echo $color_clase; ?> <?php echo $activo; ?> 
                                    <?php echo $evaluacion_prev ? 'evaluated' : ''; ?>" 
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
                                            <?php if ($evaluacion_prev): ?>
                                                <span class="badge bg-success"><?php echo $evaluacion_prev['literal']; ?></span>
                                            <?php else: ?>
                                                <span class="badge bg-secondary">Pendiente</span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <!-- Resumen de Evaluación -->
            <div class="card mt-3">
                <div class="card-header bg-info text-white">
                    <h6 class="card-title mb-0">
                        <i class="fas fa-chart-bar me-2"></i>Resumen
                    </h6>
                </div>
                <div class="card-body">
                    <?php
                    $conteo_literales = ['A' => 0, 'B' => 0, 'C' => 0, 'D' => 0, 'E' => 0, 'sin' => 0];
                    foreach ($estudiantes as $estudiante) {
                        $literal_actual = $evaluaciones_previas[$estudiante['inscripcion_id']] ?? null;
                        if ($literal_actual) {
                            $conteo_literales[$literal_actual['literal']]++;
                        } else {
                            $conteo_literales['sin']++;
                        }
                    }
                    ?>
                    <div class="small">
                        <?php foreach (['A', 'B', 'C', 'D', 'E'] as $literal): ?>
                            <div class="d-flex justify-content-between mb-1">
                                <span><?php echo $literal; ?> - <?php echo getLiteralNombre($literal); ?></span>
                                <span class="badge bg-<?php echo getLiteralColor($literal); ?>">
                                    <?php echo $conteo_literales[$literal]; ?>
                                </span>
                            </div>
                        <?php endforeach; ?>
                        <div class="d-flex justify-content-between mb-1">
                            <span>Sin evaluar</span>
                            <span class="badge bg-secondary"><?php echo $conteo_literales['sin']; ?></span>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Panel de Evaluación (Formulario dinámico) -->
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
                $evaluacion_actual = $evaluaciones_previas[$estudiante_actual['inscripcion_id']] ?? null;
                $color_index = array_search($estudiante_actual['inscripcion_id'], array_column($estudiantes, 'inscripcion_id')) % count($colores_fondo);
                $color_clase = $colores_fondo[$color_index];
                ?>
                
                <div class="card <?php echo $color_clase; ?> border-0">
                    <div class="card-header bg-transparent">
                        <div class="d-flex justify-content-between align-items-center">
                            <h4 class="card-title mb-0">
                                <i class="fas fa-user-graduate me-2"></i>
                                <?php echo htmlspecialchars($estudiante_actual['nombre'] . ' ' . $estudiante_actual['apellido']); ?>
                            </h4>
                            <div>
                                <?php if ($evaluacion_actual): ?>
                                    <span class="badge bg-<?php echo getLiteralColor($evaluacion_actual['literal']); ?> fs-6">
                                        Literal: <?php echo $evaluacion_actual['literal']; ?>
                                    </span>
                                <?php else: ?>
                                    <span class="badge bg-secondary fs-6">Sin evaluar</span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    
                    <form method="POST" action="" id="form-evaluacion">
                        <input type="hidden" name="guardar_evaluacion" value="1">
                        <input type="hidden" name="inscripcion_id" value="<?php echo $estudiante_actual['inscripcion_id']; ?>">
                        
                        <div class="card-body">
                            <!-- Información del Estudiante -->
                            <div class="row mb-4">
                                <div class="col-md-6">
                                    <strong>Cédula:</strong> 
                                    <?php echo $estudiante_actual['cedula_escolar'] ? htmlspecialchars($estudiante_actual['cedula_escolar']) : 'Sin cédula'; ?>
                                </div>
                                <div class="col-md-6">
                                    <strong>Número:</strong> 
                                    <?php echo array_search($estudiante_actual['inscripcion_id'], array_column($estudiantes, 'inscripcion_id')) + 1; ?>
                                </div>
                            </div>

                            <!-- Indicadores por Áreas -->
                            <?php if (empty($areas_con_indicadores)): ?>
                                <div class="alert alert-warning">
                                    No hay áreas de aprendizaje configuradas.
                                </div>
                            <?php else: ?>
                                <div class="accordion" id="accordionAreas">
                                    <?php foreach ($areas_con_indicadores as $area_id => $area): ?>
                                        <div class="accordion-item">
                                            <h2 class="accordion-header">
                                                <button class="accordion-button collapsed" type="button" 
                                                        data-bs-toggle="collapse" 
                                                        data-bs-target="#area<?php echo $area_id; ?>">
                                                    <span class="badge bg-primary me-2"><?php echo htmlspecialchars($area['codigo']); ?></span>
                                                    <?php echo htmlspecialchars($area['nombre']); ?>
                                                    <span class="badge bg-secondary ms-2"><?php echo count($area['indicadores']); ?> indicadores</span>
                                                </button>
                                            </h2>
                                            <div id="area<?php echo $area_id; ?>" class="accordion-collapse collapse" 
                                                 data-bs-parent="#accordionAreas">
                                                <div class="accordion-body">
                                                    <?php if (empty($area['indicadores'])): ?>
                                                        <p class="text-muted">No hay indicadores</p>
                                                    <?php else: ?>
                                                        <div class="table-responsive">
                                                            <table class="table table-sm table-hover">
                                                                <thead>
                                                                    <tr>
                                                                        <th width="60%">Indicador</th>
                                                                        <th width="40%">Evaluación</th>
                                                                    </tr>
                                                                </thead>
                                                                <tbody>
                                                                    <?php foreach ($area['indicadores'] as $indicador): ?>
                                                                        <tr>
                                                                            <td>
                                                                                <strong><?php echo htmlspecialchars($indicador['nombre']); ?></strong>
                                                                                <?php if (!empty($indicador['descripcion'])): ?>
                                                                                    <br><small class="text-muted"><?php echo htmlspecialchars($indicador['descripcion']); ?></small>
                                                                                <?php endif; ?>
                                                                            </td>
                                                                            <td>
                                                                                <select class="form-select form-select-sm escala-select" 
                                                                                        name="escala_<?php echo $indicador['id']; ?>"
                                                                                        data-indicador="<?php echo $indicador['id']; ?>">
                                                                                    <option value="">Seleccionar...</option>
                                                                                    <option value="LE">LE - Logrado con Excelencia</option>
                                                                                    <option value="L">L - Logrado</option>
                                                                                    <option value="PA">PA - Proceso Avanzado</option>
                                                                                    <option value="IP">IP - Iniciado en el Proceso</option>
                                                                                    <option value="NL">NL - No Logrado</option>
                                                                                </select>
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
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>

                            <!-- Literal Final y Observaciones -->
                            <div class="row mt-4">
                                <div class="col-md-6">
                                    <label class="form-label fw-bold">Literal Final:</label>
                                    <select class="form-select literal-final" name="literal" required>
                                        <option value="">Seleccionar literal...</option>
                                        <option value="A" <?php echo ($evaluacion_actual && $evaluacion_actual['literal'] === 'A') ? 'selected' : ''; ?>>A - Excelente</option>
                                        <option value="B" <?php echo ($evaluacion_actual && $evaluacion_actual['literal'] === 'B') ? 'selected' : ''; ?>>B - Muy Bueno</option>
                                        <option value="C" <?php echo ($evaluacion_actual && $evaluacion_actual['literal'] === 'C') ? 'selected' : ''; ?>>C - Bueno</option>
                                        <option value="D" <?php echo ($evaluacion_actual && $evaluacion_actual['literal'] === 'D') ? 'selected' : ''; ?>>D - Regular</option>
                                        <option value="E" <?php echo ($evaluacion_actual && $evaluacion_actual['literal'] === 'E') ? 'selected' : ''; ?>>E - Deficiente</option>
                                    </select>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label fw-bold">Observaciones:</label>
                                    <textarea class="form-control" name="observaciones" rows="3" 
                                              placeholder="Observaciones generales del desempeño..."
                                              maxlength="255"><?php echo $evaluacion_actual ? htmlspecialchars($evaluacion_actual['observaciones']) : ''; ?></textarea>
                                </div>
                            </div>
                        </div>
                        
                        <div class="card-footer bg-transparent">
                            <div class="d-flex justify-content-between align-items-center">
                                <div>
                                    <small class="text-muted">
                                        <i class="fas fa-info-circle me-1"></i>
                                        Los cambios se guardan automáticamente al cambiar de estudiante
                                    </small>
                                </div>
                                <div>
                                    <button type="submit" class="btn btn-success">
                                        <i class="fas fa-save me-2"></i>Guardar Evaluación
                                    </button>
                                </div>
                            </div>
                        </div>
                    </form>
                </div>
            <?php else: ?>
                <div class="card">
                    <div class="card-body text-center py-5">
                        <i class="fas fa-user-slash fa-3x text-muted mb-3"></i>
                        <h5 class="text-muted">Seleccione un estudiante</h5>
                        <p class="text-muted">Haga clic en una tarjeta de estudiante para comenzar la evaluación.</p>
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

.student-mini-card.evaluated {
    border-left: 4px solid #198754;
}

.student-avatar {
    color: #6c757d;
}

.accordion-button:not(.collapsed) {
    background-color: #e7f1ff;
    color: #0d6efd;
}

.escala-select {
    border-width: 2px;
}

/* Colores de fondo para las cards */
.bg-opacity-10 {
    --bs-bg-opacity: 0.1;
}
</style>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Cambiar estudiante al hacer clic en mini-card
    document.querySelectorAll('.student-mini-card').forEach(card => {
        card.addEventListener('click', function() {
            const inscripcionId = this.dataset.inscripcion;
            const nombre = this.dataset.nombre;
            
            // Remover clase active de todas las cards
            document.querySelectorAll('.student-mini-card').forEach(c => {
                c.classList.remove('active');
            });
            
            // Agregar clase active a la card clickeada
            this.classList.add('active');
            
            // Redirigir para cargar el estudiante
            window.location.href = `evaluar_lapso.php?seccion_id=<?php echo $seccion_id; ?>&lapso_id=<?php echo $lapso_id; ?>&estudiante=${inscripcionId}`;
        });
    });

    // Auto-guardar al cambiar de estudiante (prevención de pérdida de datos)
    let datosModificados = false;
    
    document.querySelectorAll('select, textarea').forEach(elemento => {
        elemento.addEventListener('change', function() {
            datosModificados = true;
        });
    });

    // Confirmar antes de cambiar de estudiante si hay datos sin guardar
    document.querySelectorAll('.student-mini-card').forEach(card => {
        card.addEventListener('click', function(e) {
            if (datosModificados) {
                if (!confirm('Tiene cambios sin guardar. ¿Desea continuar sin guardar?')) {
                    e.preventDefault();
                    return;
                }
            }
        });
    });

    // Marcar como guardado al enviar el formulario
    document.getElementById('form-evaluacion')?.addEventListener('submit', function() {
        datosModificados = false;
    });

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