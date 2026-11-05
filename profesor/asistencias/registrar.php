<?php
require_once '../../includes/auth.php';
require_once '../../includes/database.php';
checkAuth();
checkRole(['profesor']);

$mensaje = '';
$tipo_mensaje = '';
$profesor_id = $_SESSION['relacion_id'];

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
    error_log("Error al cargar asignaciones: " . $e->getMessage());
}

// Filtros
$seccion_filtro = $_GET['seccion_id'] ?? '';
$materia_filtro = $_GET['materia_id'] ?? '';
$fecha_filtro = $_GET['fecha'] ?? date('Y-m-d');

// Obtener estudiantes y asistencias si hay filtros seleccionados
$estudiantes = [];
$asistencias_existentes = [];

if ($seccion_filtro && $materia_filtro) {
    // Verificar que el profesor tenga acceso a esta sección y materia
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
                WHERE i.seccion_id = ? AND i.año_escolar_id IN (
                    SELECT id FROM años_escolares WHERE estado = 'activo'
                ) AND i.status = 'activo'
                ORDER BY e.apellido, e.nombre
            ");
            $stmt->execute([$seccion_filtro]);
            $estudiantes = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
        } catch (PDOException $e) {
            $estudiantes = [];
            error_log("Error al cargar estudiantes: " . $e->getMessage());
        }
        
        // Obtener asistencias existentes para esta fecha
        try {
            $stmt = $pdo->prepare("
                SELECT a.inscripcion_id, a.estado, a.observaciones
                FROM asistencias a
                WHERE a.materia_id = ? AND a.fecha = ?
            ");
            $stmt->execute([$materia_filtro, $fecha_filtro]);
            $asistencias_temp = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            foreach ($asistencias_temp as $asistencia) {
                $asistencias_existentes[$asistencia['inscripcion_id']] = [
                    'estado' => $asistencia['estado'],
                    'observaciones' => $asistencia['observaciones']
                ];
            }
        } catch (PDOException $e) {
            $asistencias_existentes = [];
        }
    }
}

// Procesar guardado de asistencias
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['guardar_asistencias'])) {
    $seccion_id = $_POST['seccion_id'];
    $materia_id = $_POST['materia_id'];
    $fecha = $_POST['fecha'];
    $user_id = $_SESSION['user_id'];
    
    $asistencias_guardadas = 0;
    $errores = 0;
    
    foreach ($_POST['asistencias'] as $inscripcion_id => $estado) {
        $observaciones = trim($_POST['observaciones'][$inscripcion_id] ?? '');
        
        try {
            if ($estado === '') {
                // Eliminar registro si no se seleccionó estado
                $stmt = $pdo->prepare("DELETE FROM asistencias WHERE inscripcion_id = ? AND materia_id = ? AND fecha = ?");
                $stmt->execute([$inscripcion_id, $materia_id, $fecha]);
            } else {
                // Insertar o actualizar asistencia
                $stmt = $pdo->prepare("
                    INSERT INTO asistencias (inscripcion_id, materia_id, fecha, estado, observaciones, registrado_por) 
                    VALUES (?, ?, ?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE 
                        estado = VALUES(estado),
                        observaciones = VALUES(observaciones),
                        registrado_por = VALUES(registrado_por),
                        created_at = CURRENT_TIMESTAMP
                ");
                $stmt->execute([$inscripcion_id, $materia_id, $fecha, $estado, $observaciones, $user_id]);
            }
            $asistencias_guardadas++;
        } catch (PDOException $e) {
            $errores++;
            error_log("Error guardando asistencia: " . $e->getMessage());
        }
    }
    
    if ($errores === 0) {
        $mensaje = "Asistencias guardadas correctamente ($asistencias_guardadas registros)";
        $tipo_mensaje = "success";
    } else {
        $mensaje = "Se guardaron $asistencias_guardadas asistencias, pero hubo $errores errores";
        $tipo_mensaje = "warning";
    }
    
    // Recargar asistencias existentes después de guardar
    if ($seccion_filtro && $materia_filtro) {
        try {
            $stmt = $pdo->prepare("
                SELECT a.inscripcion_id, a.estado, a.observaciones
                FROM asistencias a
                WHERE a.materia_id = ? AND a.fecha = ?
            ");
            $stmt->execute([$materia_filtro, $fecha_filtro]);
            $asistencias_temp = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            $asistencias_existentes = [];
            foreach ($asistencias_temp as $asistencia) {
                $asistencias_existentes[$asistencia['inscripcion_id']] = [
                    'estado' => $asistencia['estado'],
                    'observaciones' => $asistencia['observaciones']
                ];
            }
        } catch (PDOException $e) {
            $asistencias_existentes = [];
        }
    }
}
?>

<?php include '../../includes/header.php'; ?>

    <?php include '../../includes/sidebar_profesor.php'; ?>
    
    <div class="content-wrapper">

 
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h2>Registro de Asistencias</h2>
            </div>

            <?php if ($mensaje): ?>
                <div class="alert alert-<?php echo $tipo_mensaje; ?> alert-dismissible fade show" role="alert">
                    <?php echo $mensaje; ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <!-- Filtros -->
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="card-title mb-0">Seleccionar Grupo y Fecha</h5>
                </div>
                <div class="card-body">
                    <form method="GET" class="row g-3">
                        <div class="col-md-4">
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
                        <div class="col-md-4">
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
                        <div class="col-md-4">
                            <label for="fecha" class="form-label">Fecha</label>
                            <input type="date" class="form-control" id="fecha" name="fecha" 
                                   value="<?php echo htmlspecialchars($fecha_filtro); ?>" required>
                        </div>
                        <div class="col-12">
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-search me-2"></i>Cargar Lista de Estudiantes
                            </button>
                            <?php if ($seccion_filtro && $materia_filtro): ?>
                                <a href="registrar.php" class="btn btn-outline-secondary ms-2">
                                    <i class="fas fa-times me-2"></i>Limpiar
                                </a>
                            <?php endif; ?>
                        </div>
                    </form>
                </div>
            </div>

            <?php if ($seccion_filtro && $materia_filtro && $estudiantes): ?>
                <!-- Formulario de asistencias -->
                <div class="card">
                    <div class="card-header">
                        <h5 class="card-title mb-0">
                            Registro de Asistencias - 
                            <?php 
                            $seccion_nombre = '';
                            $materia_nombre = '';
                            foreach ($asignaciones as $asig) {
                                if ($asig['seccion_id'] == $seccion_filtro && $asig['materia_id'] == $materia_filtro) {
                                    $seccion_nombre = $asig['seccion_nombre'];
                                    $materia_nombre = $asig['materia_nombre'];
                                    break;
                                }
                            }
                            echo htmlspecialchars("$seccion_nombre - $materia_nombre - " . date('d/m/Y', strtotime($fecha_filtro)));
                            ?>
                        </h5>
                    </div>
                    <div class="card-body">
                        <form method="POST" action="">
                            <input type="hidden" name="guardar_asistencias" value="1">
                            <input type="hidden" name="seccion_id" value="<?php echo $seccion_filtro; ?>">
                            <input type="hidden" name="materia_id" value="<?php echo $materia_filtro; ?>">
                            <input type="hidden" name="fecha" value="<?php echo $fecha_filtro; ?>">
                            
                            <div class="table-responsive">
                                <table class="table table-striped table-hover">
                                    <thead>
                                        <tr>
                                            <th width="5%">#</th>
                                            <th width="25%">Estudiante</th>
                                            <th width="15%">Cédula</th>
                                            <th width="20%">Asistencia</th>
                                            <th width="35%">Observaciones</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($estudiantes as $index => $estudiante): 
                                            $asistencia_existente = $asistencias_existentes[$estudiante['inscripcion_id']] ?? null;
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
                                                    <select class="form-select form-select-sm" 
                                                            name="asistencias[<?php echo $estudiante['inscripcion_id']; ?>]">
                                                        <option value="">-- Seleccionar --</option>
                                                        <option value="presente" <?php echo ($asistencia_existente && $asistencia_existente['estado'] == 'presente') ? 'selected' : ''; ?>>
                                                            ✅ Presente
                                                        </option>
                                                        <option value="ausente" <?php echo ($asistencia_existente && $asistencia_existente['estado'] == 'ausente') ? 'selected' : ''; ?>>
                                                            ❌ Ausente
                                                        </option>
                                                        <option value="tardanza" <?php echo ($asistencia_existente && $asistencia_existente['estado'] == 'tardanza') ? 'selected' : ''; ?>>
                                                            ⏰ Tardanza
                                                        </option>
                                                        <option value="justificado" <?php echo ($asistencia_existente && $asistencia_existente['estado'] == 'justificado') ? 'selected' : ''; ?>>
                                                            📄 Justificado
                                                        </option>
                                                    </select>
                                                </td>
                                                <td>
                                                    <input type="text" 
                                                           class="form-control form-control-sm" 
                                                           name="observaciones[<?php echo $estudiante['inscripcion_id']; ?>]"
                                                           value="<?php echo $asistencia_existente ? htmlspecialchars($asistencia_existente['observaciones']) : ''; ?>"
                                                           placeholder="Observaciones...">
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                            
                            <div class="d-grid gap-2 d-md-flex justify-content-md-end mt-3">
                                <button type="submit" class="btn btn-success">
                                    <i class="fas fa-save me-2"></i>Guardar Todas las Asistencias
                                </button>
                                <!-- Módulo de reporte no implementado aún: reporte.php -->
                            </div>
                        </form>
                    </div>
                </div>
                
                <!-- Resumen rápido -->
                <div class="row mt-4">
                    <div class="col-md-3">
                        <div class="card bg-success text-white">
                            <div class="card-body text-center p-3">
                                <h4 class="mb-0">
                                    <?php 
                                    $presentes = array_filter($asistencias_existentes, function($a) {
                                        return $a['estado'] == 'presente';
                                    });
                                    echo count($presentes);
                                    ?>
                                </h4>
                                <small>Presentes</small>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card bg-danger text-white">
                            <div class="card-body text-center p-3">
                                <h4 class="mb-0">
                                    <?php 
                                    $ausentes = array_filter($asistencias_existentes, function($a) {
                                        return $a['estado'] == 'ausente';
                                    });
                                    echo count($ausentes);
                                    ?>
                                </h4>
                                <small>Ausentes</small>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card bg-warning text-dark">
                            <div class="card-body text-center p-3">
                                <h4 class="mb-0">
                                    <?php 
                                    $tardanzas = array_filter($asistencias_existentes, function($a) {
                                        return $a['estado'] == 'tardanza';
                                    });
                                    echo count($tardanzas);
                                    ?>
                                </h4>
                                <small>Tardanzas</small>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card bg-info text-white">
                            <div class="card-body text-center p-3">
                                <h4 class="mb-0">
                                    <?php 
                                    $justificados = array_filter($asistencias_existentes, function($a) {
                                        return $a['estado'] == 'justificado';
                                    });
                                    echo count($justificados);
                                    ?>
                                </h4>
                                <small>Justificados</small>
                            </div>
                        </div>
                    </div>
                </div>
                
            <?php elseif ($seccion_filtro && $materia_filtro && empty($estudiantes)): ?>
                <div class="alert alert-warning">
                    <i class="fas fa-exclamation-triangle me-2"></i>
                    No hay estudiantes inscritos en esta sección o no tienes acceso a esta asignación.
                </div>
            <?php endif; ?>
        </div>
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

// Establecer fecha actual por defecto si no hay fecha seleccionada
document.addEventListener('DOMContentLoaded', function() {
    const fechaInput = document.getElementById('fecha');
    if (!fechaInput.value) {
        fechaInput.value = '<?php echo date('Y-m-d'); ?>';
    }
});
</script>

<?php include '../../includes/footer.php'; ?>