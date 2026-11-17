<?php
require_once '../../includes/auth.php';
require_once '../../includes/database.php';
require_once '../../includes/functions_notas.php';
checkAuth();
checkRole(['admin']);

$mensaje = '';
$tipo_mensaje = '';

// Obtener años escolares
try {
    $stmt = $pdo->query("SELECT id, nombre, estado FROM años_escolares ORDER BY fecha_inicio DESC");
    $años_escolares = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $años_escolares = [];
}

// Verificar estudiantes sin literales finales
$estudiantes_sin_literales = [];
$estudiantes_recuperacion = [];

if (isset($_GET['verificar_estudiantes'])) {
    $año_origen_id = $_GET['año_origen_id'];
    $estudiantes_sin_literales = obtenerEstudiantesSinLiterales($año_origen_id);
    $estudiantes_recuperacion = obtenerEstudiantesParaRecuperacion($año_origen_id);
}

// Procesar avance anual
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['procesar_avance'])) {
    $año_origen_id = $_POST['año_origen_id'];
    $año_destino_id = $_POST['año_destino_id'];
    $opcion_sin_literales = $_POST['opcion_sin_literales'] ?? 'excluir';
    $opcion_recuperacion = $_POST['opcion_recuperacion'] ?? 'repetir';
    
    if ($año_origen_id && $año_destino_id && $año_origen_id != $año_destino_id) {
        $resultado = procesarAvanceAnual($año_origen_id, $año_destino_id, $opcion_sin_literales, $opcion_recuperacion);
        
        if ($resultado !== false) {
            $mensaje = "Avance anual procesado exitosamente. ";
            $mensaje .= "Total estudiantes: {$resultado['total_estudiantes']}, ";
            $mensaje .= "Aprobados: {$resultado['aprobados']}, ";
            $mensaje .= "Reprobados: {$resultado['reprobados']}, ";
            $mensaje .= "Sin literales: {$resultado['sin_literales']}, ";
            $mensaje .= "En recuperación: {$resultado['recuperacion']}";
            $tipo_mensaje = "success";
            
            $reporte = $resultado; // Guardar para mostrar detalles
        } else {
            $mensaje = "Error al procesar el avance anual";
            $tipo_mensaje = "danger";
        }
    } else {
        $mensaje = "Seleccione años escolares válidos y diferentes";
        $tipo_mensaje = "warning";
    }
}


// Función para obtener estudiantes que necesitan recuperación

?>

<?php include '../../includes/header.php'; ?>

<div class="content-wrapper" style="padding: 20px;">
    <?php include '../../includes/sidebar_admin.php'; ?>
    
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2>🎓 Avance Anual de Estudiantes</h2>
        <a href="gestionar.php" class="btn btn-outline-secondary">
            <i class="fas fa-arrow-left me-2"></i>Volver a Años Escolares
        </a>
    </div>

    <?php if ($mensaje): ?>
        <div class="alert alert-<?php echo $tipo_mensaje; ?> alert-dismissible fade show" role="alert">
            <?php echo $mensaje; ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <div class="row">
        <!-- Formulario Principal -->
        <div class="col-md-6">
            <div class="card">
                <div class="card-header bg-primary text-white">
                    <h5 class="card-title mb-0">Procesar Avance Anual</h5>
                </div>
                <div class="card-body">
                    <form method="GET" action="" class="mb-4">
                        <div class="row g-3">
                            <div class="col-md-8">
                                <label for="año_origen_id" class="form-label">Año Escolar a Verificar</label>
                                <select class="form-select" id="año_origen_id" name="año_origen_id" required>
                                    <option value="">Seleccionar año escolar...</option>
                                    <?php foreach ($años_escolares as $año): ?>
                                        <option value="<?php echo $año['id']; ?>" 
                                            <?php echo isset($_GET['año_origen_id']) && $_GET['año_origen_id'] == $año['id'] ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($año['nombre']); ?>
                                            <?php if ($año['estado'] == 'activo'): ?>
                                                (Activo)
                                            <?php endif; ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label d-block">&nbsp;</label>
                                <button type="submit" name="verificar_estudiantes" class="btn btn-outline-primary w-100">
                                    <i class="fas fa-search me-1"></i>Verificar
                                </button>
                            </div>
                        </div>
                    </form>

                    <?php if (isset($_GET['verificar_estudiantes']) && $_GET['año_origen_id']): ?>
                    <form method="POST" action="" id="form-avance">
                        <input type="hidden" name="año_origen_id" value="<?php echo $_GET['año_origen_id']; ?>">
                        
                        <div class="mb-3">
                            <label for="año_destino_id" class="form-label">Año Escolar de Destino *</label>
                            <select class="form-select" id="año_destino_id" name="año_destino_id" required>
                                <option value="">Seleccionar año destino...</option>
                                <?php foreach ($años_escolares as $año): ?>
                                    <?php if ($año['id'] != $_GET['año_origen_id']): ?>
                                        <option value="<?php echo $año['id']; ?>">
                                            <?php echo htmlspecialchars($año['nombre']); ?>
                                        </option>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <!-- Opciones para estudiantes sin literales -->
                        <?php if (count($estudiantes_sin_literales) > 0): ?>
                        <div class="mb-3">
                            <label class="form-label">Estudiantes sin Literal Final (<?php echo count($estudiantes_sin_literales); ?>)</label>
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="opcion_sin_literales" value="excluir" id="excluir_sin_literales" checked>
                                <label class="form-check-label" for="excluir_sin_literales">
                                    ❌ Excluir del avance (repetirán año)
                                </label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="opcion_sin_literales" value="forzar_avance" id="forzar_avance">
                                <label class="form-check-label" for="forzar_avance">
                                    ⚠ï¸ Forzar avance (asignar literal C automáticamente)
                                </label>
                            </div>
                            <div class="mt-2">
                                <a href="../evaluacion_literales/literal_final.php?año_escolar_id=<?php echo $_GET['año_origen_id']; ?>" 
                                   class="btn btn-sm btn-outline-warning" target="_blank">
                                    <i class="fas fa-edit me-1"></i>Agregar Literales Faltantes
                                </a>
                            </div>
                        </div>
                        <?php endif; ?>

                        <!-- Opciones para estudiantes en recuperación -->
                        <?php if (count($estudiantes_recuperacion) > 0): ?>
                        <div class="mb-3">
                            <label class="form-label">Estudiantes con Literal D/E (<?php echo count($estudiantes_recuperacion); ?>)</label>
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="opcion_recuperacion" value="repetir" id="repetir_recuperacion" checked>
                                <label class="form-check-label" for="repetir_recuperacion">
                                    🔄 Repetir año automáticamente
                                </label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="opcion_recuperacion" value="recuperacion" id="recuperacion">
                                <label class="form-check-label" for="recuperacion">
                                    📝 Dar oportunidad de recuperación
                                </label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="opcion_recuperacion" value="forzar_promocion" id="forzar_promocion">
                                <label class="form-check-label" for="forzar_promocion">
                                    ✅ Forzar promoción (casos especiales)
                                </label>
                            </div>
                        </div>
                        <?php endif; ?>
                        
                        <div class="alert alert-warning">
                            <strong><i class="fas fa-exclamation-triangle me-2"></i>Advertencia:</strong>
                            <ul class="mb-0 mt-2">
                                <li>Esta acción no se puede deshacer</li>
                                <li>Se crearán nuevas inscripciones automáticamente</li>
                                <li>Los estudiantes reprobados repetirán el mismo grado</li>
                                <li>Se generará un reporte detallado del proceso</li>
                            </ul>
                        </div>
                        
                        <button type="submit" name="procesar_avance" class="btn btn-success w-100" 
                                onclick="return confirmAvance()">
                            <i class="fas fa-play me-2"></i>Procesar Avance Anual
                        </button>
                    </form>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        
        <!-- Panel de Información y Resultados de Verificación -->
        <div class="col-md-6">
            <?php if (isset($_GET['verificar_estudiantes']) && $_GET['año_origen_id']): ?>
                <!-- Estudiantes sin literales -->
                <?php if (count($estudiantes_sin_literales) > 0): ?>
                <div class="card mb-4">
                    <div class="card-header bg-danger text-white">
                        <h6 class="card-title mb-0">
                            <i class="fas fa-exclamation-circle me-2"></i>
                            Estudiantes sin Literal Final (<?php echo count($estudiantes_sin_literales); ?>)
                        </h6>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive" style="max-height: 200px;">
                            <table class="table table-sm table-hover">
                                <thead>
                                    <tr>
                                        <th>Estudiante</th>
                                        <th>Sección</th>
                                        <th>Grado</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($estudiantes_sin_literales as $estudiante): ?>
                                    <tr>
                                        <td>
                                            <small><?php echo htmlspecialchars($estudiante['nombre'] . ' ' . $estudiante['apellido']); ?></small>
                                        </td>
                                        <td>
                                            <span class="badge bg-secondary"><?php echo htmlspecialchars($estudiante['seccion']); ?></span>
                                        </td>
                                        <td>
                                            <small><?php echo htmlspecialchars($estudiante['grado']); ?></small>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
                <?php else: ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle me-2"></i>
                    Todos los estudiantes tienen literal final asignado.
                </div>
                <?php endif; ?>

                <!-- Estudiantes en recuperación -->
                <?php if (count($estudiantes_recuperacion) > 0): ?>
                <div class="card">
                    <div class="card-header bg-warning text-dark">
                        <h6 class="card-title mb-0">
                            <i class="fas fa-clock me-2"></i>
                            Estudiantes para Recuperación (<?php echo count($estudiantes_recuperacion); ?>)
                        </h6>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive" style="max-height: 200px;">
                            <table class="table table-sm table-hover">
                                <thead>
                                    <tr>
                                        <th>Estudiante</th>
                                        <th>Sección</th>
                                        <th>Literal</th>
                                        <th>Observaciones</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($estudiantes_recuperacion as $estudiante): ?>
                                    <tr>
                                        <td>
                                            <small><?php echo htmlspecialchars($estudiante['nombre'] . ' ' . $estudiante['apellido']); ?></small>
                                        </td>
                                        <td>
                                            <span class="badge bg-secondary"><?php echo htmlspecialchars($estudiante['seccion']); ?></span>
                                        </td>
                                        <td>
                                            <span class="badge bg-danger"><?php echo $estudiante['literal_final']; ?></span>
                                        </td>
                                        <td>
                                            <small class="text-muted">
                                                <?php echo $estudiante['observaciones_finales'] ? htmlspecialchars(substr($estudiante['observaciones_finales'], 0, 50) . '...') : 'Sin observaciones'; ?>
                                            </small>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
                <?php else: ?>
                <div class="alert alert-info">
                    <i class="fas fa-info-circle me-2"></i>
                    No hay estudiantes que requieran recuperación.
                </div>
                <?php endif; ?>
            <?php else: ?>
                <!-- Información general cuando no hay verificación -->
                <div class="card">
                    <div class="card-header bg-info text-white">
                        <h5 class="card-title mb-0">Información del Proceso</h5>
                    </div>
                    <div class="card-body">
                        <h6>📋 Pasos Recomendados:</h6>
                        <ol>
                            <li><strong>Seleccione el año escolar</strong> y haga clic en "Verificar"</li>
                            <li><strong>Revise los estudiantes</strong> sin literal final o en recuperación</li>
                            <li><strong>Configure las opciones</strong> para cada caso especial</li>
                            <li><strong>Procese el avance anual</strong> cuando todo esté listo</li>
                        </ol>
                        
                        <h6>🎯 Criterios de Promoción:</h6>
                        <ul>
                            <li><strong>Literal A-C:</strong> Avanza al siguiente grado</li>
                            <li><strong>Literal D-E:</strong> Requiere recuperación o repite</li>
                            <li><strong>Sin literal:</strong> No puede avanzar automáticamente</li>
                        </ul>
                        
                        <div class="alert alert-success">
                            <strong><i class="fas fa-lightbulb me-2"></i>Recomendación:</strong>
                            <br>Realice una copia de seguridad de la base de datos antes de ejecutar este proceso.
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Reporte de Resultados (se mantiene igual) -->
    <?php if (isset($reporte)): ?>
    <div class="card mt-4">
        <div class="card-header bg-success text-white">
            <h5 class="card-title mb-0">
                <i class="fas fa-chart-bar me-2"></i>Reporte de Avance Anual
            </h5>
        </div>
        <div class="card-body">
            <div class="row text-center mb-4">
                <div class="col-md-3">
                    <div class="card bg-primary text-white">
                        <div class="card-body">
                            <h3><?php echo $reporte['total_estudiantes']; ?></h3>
                            <p class="mb-0">Total Estudiantes</p>
                        </div>
                    </div>
                </div>
                <div class="col-md-2">
                    <div class="card bg-success text-white">
                        <div class="card-body">
                            <h3><?php echo $reporte['aprobados']; ?></h3>
                            <p class="mb-0">Aprobados</p>
                        </div>
                    </div>
                </div>
                <div class="col-md-2">
                    <div class="card bg-danger text-white">
                        <div class="card-body">
                            <h3><?php echo $reporte['reprobados']; ?></h3>
                            <p class="mb-0">Reprobados</p>
                        </div>
                    </div>
                </div>
                <div class="col-md-2">
                    <div class="card bg-warning text-white">
                        <div class="card-body">
                            <h3><?php echo $reporte['sin_literales']; ?></h3>
                            <p class="mb-0">Sin Literales</p>
                        </div>
                    </div>
                </div>
                <div class="col-md-2">
                    <div class="card bg-info text-white">
                        <div class="card-body">
                            <h3><?php echo $reporte['recuperacion']; ?></h3>
                            <p class="mb-0">Recuperación</p>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Tabla de detalles (se mantiene igual) -->
            <div class="table-responsive">
                <table class="table table-striped table-hover">
                    <thead>
                        <tr>
                            <th>Estudiante</th>
                            <th>Cédula</th>
                            <th>Sección Actual</th>
                            <th>Literal</th>
                            <th>Resultado</th>
                            <th>Nueva Sección</th>
                            <th>Observaciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($reporte['detalles'] as $detalle): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($detalle['estudiante']); ?></td>
                            <td><?php echo htmlspecialchars($detalle['cedula']); ?></td>
                            <td><?php echo htmlspecialchars($detalle['seccion_actual']); ?></td>
                            <td>
                                <span class="badge 
                                    <?php echo in_array($detalle['literal_final'], ['A','B','C']) ? 'bg-success' : 'bg-danger'; ?>">
                                    <?php echo $detalle['literal_final'] ?: 'SIN LITERAL'; ?>
                                </span>
                            </td>
                            <td>
                                <?php if ($detalle['aprueba_ano']): ?>
                                    <span class="badge bg-success">APROBADO</span>
                                <?php else: ?>
                                    <span class="badge bg-danger">REPROBADO</span>
                                <?php endif; ?>
                            </td>
                            <td><?php echo htmlspecialchars($detalle['nueva_seccion']); ?></td>
                            <td>
                                <small class="text-muted">
                                    <?php echo htmlspecialchars($detalle['observaciones'] ?? ''); ?>
                                </small>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            
            <div class="text-end mt-3">
                <button class="btn btn-outline-primary" onclick="window.print()">
                    <i class="fas fa-print me-2"></i>Imprimir Reporte
                </button>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>

<script>
function confirmAvance() {
    const añoOrigen = document.getElementById('año_origen_id')?.value;
    const añoDestino = document.getElementById('año_destino_id')?.value;
    
    if (!añoOrigen || !añoDestino) {
        alert('Seleccione ambos años escolares');
        return false;
    }
    
    if (añoOrigen === añoDestino) {
        alert('Los años de origen y destino deben ser diferentes');
        return false;
    }
    
    return confirm('¿Está seguro de procesar el avance anual? Esta acción es irreversible y afectará a todos los estudiantes.');
}

document.addEventListener('DOMContentLoaded', function() {
    // Validación adicional
    const formAvance = document.getElementById('form-avance');
    if (formAvance) {
        formAvance.addEventListener('submit', function(e) {
            if (!confirmAvance()) {
                e.preventDefault();
                return false;
            }
        });
    }
});
</script>

<?php include '../../includes/footer.php'; ?>
