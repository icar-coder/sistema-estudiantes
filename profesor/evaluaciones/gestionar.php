<?php
require_once '../../includes/auth.php';
require_once '../../includes/database.php';
checkAuth();
checkRole(['profesor']);

$mensaje = '';
$tipo_mensaje = '';
$profesor_id = $_SESSION['relacion_id'];

// Obtener tipos de evaluación
try {
    $stmt = $pdo->query("SELECT * FROM tipos_evaluacion WHERE estado = 'activo' ORDER BY orden");
    $tipos_evaluacion = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $tipos_evaluacion = [];
}

// Obtener las secciones y materias asignadas al profesor
try {
    $stmt = $pdo->prepare("
        SELECT psm.*, 
               s.nombre as seccion_nombre,
               s.id as seccion_id,
               m.nombre as materia_nombre,
               m.codigo as materia_codigo,
               m.id as materia_id, 
               ae.nombre as año_escolar,
               ae.id as año_escolar_id
        FROM profesores_secciones_materias psm
        JOIN secciones s ON psm.seccion_id = s.id
        JOIN materias m ON psm.materia_id = m.id
        JOIN años_escolares ae ON psm.año_escolar_id = ae.id
        WHERE psm.profesor_id = ? AND ae.estado = 'activo'
        ORDER BY ae.nombre DESC, s.nombre, m.nombre
    ");
    $stmt->execute([$profesor_id]);
    $asignaciones = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $asignaciones = [];
}

// Obtener lapsos del año activo
try {
    $stmt_año = $pdo->query("SELECT id FROM años_escolares WHERE estado = 'activo' LIMIT 1");
    $año_activo = $stmt_año->fetch(PDO::FETCH_ASSOC);
    
    if ($año_activo) {
        $stmt = $pdo->prepare("
            SELECT l.*, ae.nombre as año_escolar
            FROM lapsos l
            JOIN años_escolares ae ON l.año_escolar_id = ae.id
            WHERE l.año_escolar_id = ? AND l.estado = 'activo'
            ORDER BY l.numero
        ");
        $stmt->execute([$año_activo['id']]);
        $lapsos = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $lapsos = [];
    }
} catch (PDOException $e) {
    $lapsos = [];
}

// Filtros
$seccion_filtro = $_GET['seccion_id'] ?? '';
$materia_filtro = $_GET['materia_id'] ?? '';
$lapso_filtro = $_GET['lapso_id'] ?? '';
$tipo_evaluacion_filtro = $_GET['tipo_evaluacion_id'] ?? '';

// Obtener estudiantes y notas si hay filtros seleccionados
$estudiantes = [];
$notas_existentes = [];

if ($seccion_filtro && $materia_filtro && $lapso_filtro && $tipo_evaluacion_filtro) {
    // Verificar acceso del profesor
    $acceso_permitido = false;
    foreach ($asignaciones as $asignacion) {
        if ($asignacion['seccion_id'] == $seccion_filtro && $asignacion['materia_id'] == $materia_filtro) {
            $acceso_permitido = true;
            break;
        }
    }
    
    if ($acceso_permitido) {
        // Obtener estudiantes de la sección
        try {
            $stmt = $pdo->prepare("
                SELECT i.id as inscripcion_id, 
                       e.id as estudiante_id,
                       e.cedula_escolar,
                       e.nombre, 
                       e.apellido
                FROM inscripciones i
                JOIN estudiantes e ON i.estudiante_id = e.id
                WHERE i.seccion_id = ? AND i.año_escolar_id = ? AND i.status = 'activo'
                ORDER BY e.apellido, e.nombre
            ");
            
            $stmt_año = $pdo->query("SELECT id FROM años_escolares WHERE estado = 'activo' LIMIT 1");
            $año_activo = $stmt_año->fetch(PDO::FETCH_ASSOC);
            
            if ($año_activo) {
                $stmt->execute([$seccion_filtro, $año_activo['id']]);
                $estudiantes = $stmt->fetchAll(PDO::FETCH_ASSOC);
            }
            
        } catch (PDOException $e) {
            $estudiantes = [];
        }
        
        // Obtener notas existentes para esta evaluación
        try {
            $stmt = $pdo->prepare("
                SELECT n.inscripcion_id, n.valor_decimal, n.comentario as observaciones
                FROM notas n
                WHERE n.materia_id = ? AND n.lapso_id = ? AND n.tipo_evaluacion_id = ?
            ");
            $stmt->execute([$materia_filtro, $lapso_filtro, $tipo_evaluacion_filtro]);
            $notas_temp = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            foreach ($notas_temp as $nota) {
                $notas_existentes[$nota['inscripcion_id']] = [
                    'valor' => $nota['valor_decimal'],
                    'observaciones' => $nota['observaciones']
                ];
            }
        } catch (PDOException $e) {
            $notas_existentes = [];
        }
    }
}

// Función para convertir nota numérica a letras
function convertirNotaLetras($nota) {
    if ($nota >= 19.0) return "DIECINUEVE A VEINTE";
    if ($nota >= 18.0) return "DIECIOCHO";
    if ($nota >= 17.0) return "DIECISIETE";
    if ($nota >= 16.0) return "DIECISÉIS";
    if ($nota >= 15.0) return "QUINCE";
    if ($nota >= 14.0) return "CATORCE";
    if ($nota >= 13.0) return "TRECE";
    if ($nota >= 12.0) return "DOCE";
    if ($nota >= 11.0) return "ONCE";
    if ($nota >= 10.0) return "DIEZ";
    if ($nota >= 9.0) return "NUEVE";
    if ($nota >= 8.0) return "OCHO";
    if ($nota >= 7.0) return "SIETE";
    if ($nota >= 6.0) return "SEIS";
    if ($nota >= 5.0) return "CINCO";
    if ($nota >= 4.0) return "CUATRO";
    if ($nota >= 3.0) return "TRES";
    if ($nota >= 2.0) return "DOS";
    if ($nota >= 1.0) return "UNO";
    return "CERO";
}

// Procesar guardado de notas
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['guardar_notas'])) {
    $lapso_id = $_POST['lapso_id'];
    $seccion_id = $_POST['seccion_id'];
    $materia_id = $_POST['materia_id'];
    $tipo_evaluacion_id = $_POST['tipo_evaluacion_id'];
    
    $notas_guardadas = 0;
    $errores = 0;
    
    foreach ($_POST['notas'] as $inscripcion_id => $valor_nota) {
        $valor_nota = trim($valor_nota);
        $observaciones = trim($_POST['observaciones'][$inscripcion_id] ?? '');
        
        if ($valor_nota === '' || (is_numeric($valor_nota) && $valor_nota >= 0 && $valor_nota <= 20)) {
            try {
                if ($valor_nota === '') {
                    // Eliminar nota si está vacía
                    $stmt = $pdo->prepare("
                        DELETE FROM notas 
                        WHERE inscripcion_id = ? AND materia_id = ? AND lapso_id = ? AND tipo_evaluacion_id = ?
                    ");
                    $stmt->execute([$inscripcion_id, $materia_id, $lapso_id, $tipo_evaluacion_id]);
                } else {
                    // Convertir a decimal
                    $valor_decimal = number_format(floatval($valor_nota), 2, '.', '');
                    $valor_letra = convertirNotaLetras($valor_decimal);
                    
                    // Insertar o actualizar nota individual
                    $stmt = $pdo->prepare("
                        INSERT INTO notas (inscripcion_id, materia_id, lapso_id, tipo_evaluacion_id, valor_decimal, valor_letra, comentario, es_promedio) 
                        VALUES (?, ?, ?, ?, ?, ?, ?, 0)
                        ON DUPLICATE KEY UPDATE 
                            valor_decimal = VALUES(valor_decimal),
                            valor_letra = VALUES(valor_letra),
                            comentario = VALUES(comentario),
                            fecha_registro = CURRENT_TIMESTAMP
                    ");
                    $stmt->execute([$inscripcion_id, $materia_id, $lapso_id, $tipo_evaluacion_id, $valor_decimal, $valor_letra, $observaciones]);
                }
                $notas_guardadas++;
            } catch (PDOException $e) {
                $errores++;
                error_log("Error guardando nota: " . $e->getMessage());
            }
        } else {
            $errores++;
        }
    }
    
    if ($errores === 0) {
        $mensaje = "Notas guardadas correctamente ($notas_guardadas registros)";
        $tipo_mensaje = "success";
    } else {
        $mensaje = "Se guardaron $notas_guardadas notas, pero hubo $errores errores";
        $tipo_mensaje = "warning";
    }
    
    // Redirigir para evitar reenvío del formulario
    header("Location: gestionar.php?" . http_build_query($_GET));
    exit;
}
?>

<?php include '../../includes/header.php'; ?>

    <?php include '../../includes/sidebar_profesor.php'; ?>
    
    <div class="content-wrapper">
    <div class="container-fluid p-4">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h2><i class="fas fa-chart-line me-2"></i>Gestión de Evaluaciones</h2>
        </div>

        <?php if ($mensaje): ?>
            <div class="alert alert-<?php echo $tipo_mensaje; ?> alert-dismissible fade show" role="alert">
                <?php echo $mensaje; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <!-- Filtros -->
        <div class="card mb-4">
            <div class="card-header bg-primary text-white">
                <h5 class="card-title mb-0"><i class="fas fa-filter me-2"></i>Seleccionar Grupo y Evaluación</h5>
            </div>
            <div class="card-body">
                <form method="GET" class="row g-3">
                    <div class="col-md-3">
                        <label for="seccion_id" class="form-label">Sección</label>
                        <select class="form-select" id="seccion_id" name="seccion_id" required>
                            <option value="">Seleccionar sección...</option>
                            <?php foreach ($asignaciones as $asignacion): ?>
                                <option value="<?php echo $asignacion['seccion_id']; ?>" 
                                    <?php echo $seccion_filtro == $asignacion['seccion_id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($asignacion['seccion_nombre'] . ' - ' . $asignacion['año_escolar']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label for="materia_id" class="form-label">Materia</label>
                        <select class="form-select" id="materia_id" name="materia_id" required>
                            <option value="">Seleccionar sección primero</option>
                            <?php if ($seccion_filtro): ?>
                                <?php 
                                $materias_filtradas = array_filter($asignaciones, function($a) use ($seccion_filtro) {
                                    return $a['seccion_id'] == $seccion_filtro;
                                });
                                ?>
                                <?php foreach ($materias_filtradas as $materia): ?>
                                    <option value="<?php echo $materia['materia_id']; ?>" 
                                        <?php echo $materia_filtro == $materia['materia_id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($materia['materia_nombre'] . ' (' . $materia['materia_codigo'] . ')'); ?>
                                    </option>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label for="lapso_id" class="form-label">Lapso</label>
                        <select class="form-select" id="lapso_id" name="lapso_id" required>
                            <option value="">Seleccionar lapso...</option>
                            <?php foreach ($lapsos as $lapso): ?>
                                <option value="<?php echo $lapso['id']; ?>" 
                                    <?php echo $lapso_filtro == $lapso['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($lapso['nombre'] . ' - ' . $lapso['año_escolar']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label for="tipo_evaluacion_id" class="form-label">Tipo de Evaluación</label>
                        <select class="form-select" id="tipo_evaluacion_id" name="tipo_evaluacion_id" required>
                            <option value="">Seleccionar tipo...</option>
                            <?php foreach ($tipos_evaluacion as $tipo): ?>
                                <option value="<?php echo $tipo['id']; ?>" 
                                    <?php echo $tipo_evaluacion_filtro == $tipo['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($tipo['nombre'] . ' (' . ($tipo['peso'] * 100) . '%)'); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-12">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-search me-2"></i>Cargar Estudiantes
                        </button>
                        <?php if ($seccion_filtro && $materia_filtro && $lapso_filtro && $tipo_evaluacion_filtro): ?>
                            <a href="gestionar.php" class="btn btn-outline-secondary ms-2">
                                <i class="fas fa-times me-2"></i>Limpiar
                            </a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
        </div>

        <?php if ($seccion_filtro && $materia_filtro && $lapso_filtro && $tipo_evaluacion_filtro && $estudiantes): ?>
            <!-- Formulario de notas -->
            <div class="card">
                <div class="card-header bg-success text-white">
                    <h5 class="card-title mb-0">
                        <i class="fas fa-edit me-2"></i>
                        Registro de Evaluación - 
                        <?php 
                        $seccion_nombre = '';
                        $materia_nombre = '';
                        $tipo_evaluacion_nombre = '';
                        
                        foreach ($asignaciones as $asig) {
                            if ($asig['seccion_id'] == $seccion_filtro && $asig['materia_id'] == $materia_filtro) {
                                $seccion_nombre = $asig['seccion_nombre'];
                                $materia_nombre = $asig['materia_nombre'];
                                break;
                            }
                        }
                        
                        foreach ($lapsos as $lapso) {
                            if ($lapso['id'] == $lapso_filtro) {
                                $lapso_nombre = $lapso['nombre'];
                                break;
                            }
                        }
                        
                        foreach ($tipos_evaluacion as $tipo) {
                            if ($tipo['id'] == $tipo_evaluacion_filtro) {
                                $tipo_evaluacion_nombre = $tipo['nombre'];
                                break;
                            }
                        }
                        
                        echo htmlspecialchars("$seccion_nombre - $materia_nombre - $lapso_nombre - $tipo_evaluacion_nombre");
                        ?>
                    </h5>
                </div>
                <div class="card-body">
                    <form method="POST" action="">
                        <input type="hidden" name="guardar_notas" value="1">
                        <input type="hidden" name="seccion_id" value="<?php echo $seccion_filtro; ?>">
                        <input type="hidden" name="materia_id" value="<?php echo $materia_filtro; ?>">
                        <input type="hidden" name="lapso_id" value="<?php echo $lapso_filtro; ?>">
                        <input type="hidden" name="tipo_evaluacion_id" value="<?php echo $tipo_evaluacion_filtro; ?>">
                        
                        <div class="table-responsive">
                            <table class="table table-striped table-hover">
                                <thead class="table-dark">
                                    <tr>
                                        <th width="5%">#</th>
                                        <th width="25%">Estudiante</th>
                                        <th width="15%">Cédula</th>
                                        <th width="15%">Nota (0-20)</th>
                                        <th width="40%">Observaciones</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($estudiantes as $index => $estudiante): 
                                        $nota_existente = $notas_existentes[$estudiante['inscripcion_id']] ?? null;
                                    ?>
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
                                            <td>
                                                <input type="number" 
                                                       class="form-control form-control-sm" 
                                                       name="notas[<?php echo $estudiante['inscripcion_id']; ?>]"
                                                       value="<?php echo $nota_existente ? htmlspecialchars($nota_existente['valor']) : ''; ?>"
                                                       min="0" max="20" step="0.01"
                                                       placeholder="0.00 - 20.00">
                                            </td>
                                            <td>
                                                <input type="text" 
                                                       class="form-control form-control-sm" 
                                                       name="observaciones[<?php echo $estudiante['inscripcion_id']; ?>]"
                                                       value="<?php echo $nota_existente ? htmlspecialchars($nota_existente['observaciones']) : ''; ?>"
                                                       placeholder="Observaciones...">
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        
                        <div class="d-grid gap-2 d-md-flex justify-content-md-end mt-3">
                            <button type="submit" class="btn btn-success">
                                <i class="fas fa-save me-2"></i>Guardar Todas las Notas
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        <?php elseif ($seccion_filtro && $materia_filtro && $lapso_filtro && $tipo_evaluacion_filtro && empty($estudiantes)): ?>
            <div class="alert alert-warning">
                <i class="fas fa-exclamation-triangle me-2"></i>
                No hay estudiantes inscritos en esta sección o no tienes acceso a esta asignación.
            </div>
        <?php endif; ?>
    </div>
</div>

<script>
// Actualizar materias cuando se cambia la sección
document.getElementById('seccion_id').addEventListener('change', function() {
    const seccionId = this.value;
    const materiaSelect = document.getElementById('materia_id');
    
    if (seccionId) {
        materiaSelect.innerHTML = '<option value="">Seleccionar materia...</option>';
        <?php foreach ($asignaciones as $asig): ?>
            if (<?php echo $asig['seccion_id']; ?> == seccionId) {
                materiaSelect.innerHTML += '<option value="<?php echo $asig['materia_id']; ?>"><?php echo $asig['materia_nombre'] . ' (' . $asig['materia_codigo'] . ')'; ?></option>';
            }
        <?php endforeach; ?>
    } else {
        materiaSelect.innerHTML = '<option value="">Seleccionar sección primero</option>';
    }
});
</script>

<?php include '../../includes/footer.php'; ?>