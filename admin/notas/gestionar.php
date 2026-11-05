<?php
require_once '../../includes/auth.php';
require_once '../../includes/database.php';
require_once '../../includes/functions_notas.php'; // <-- AGREGAR ESTA LÍNEA
checkAuth();
checkRole(['admin']);

$mensaje = '';
$tipo_mensaje = '';

// Filtros
$seccion_filtro = $_GET['seccion_id'] ?? '';
$materia_filtro = $_GET['materia_id'] ?? '';
$lapso_filtro = $_GET['lapso_id'] ?? '';
$tipo_evaluacion_filtro = $_GET['tipo_evaluacion_id'] ?? '';

// Obtener secciones, materias, lapsos y tipos de evaluación
try {
    // Secciones con información de nivel
    $stmt = $pdo->query("
        SELECT s.*, ae.nombre as año_escolar, s.nivel
        FROM secciones s 
        JOIN años_escolares ae ON s.año_escolar_id = ae.id 
        WHERE ae.estado = 'activo' 
        ORDER BY s.nombre
    ");
    $secciones = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Materias
    $stmt = $pdo->query("SELECT * FROM materias WHERE estado = 'activa' ORDER BY nombre");
    $materias = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Lapsos del año activo
    $stmt_año = $pdo->query("SELECT id FROM años_escolares WHERE estado = 'activo' LIMIT 1");
    $año_activo = $stmt_año->fetch(PDO::FETCH_ASSOC);
    
    if ($año_activo) {
        $stmt = $pdo->prepare("
            SELECT l.*, ae.nombre as año_escolar
            FROM lapsos l
            JOIN años_escolares ae ON l.año_escolar_id = ae.id
            WHERE l.año_escolar_id = ?
            ORDER BY l.numero
        ");
        $stmt->execute([$año_activo['id']]);
        $lapsos = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $lapsos = [];
    }

    // Tipos de evaluación
    $stmt = $pdo->query("
        SELECT * FROM tipos_evaluacion 
        WHERE estado = 'activo' 
        ORDER BY orden, nombre
    ");
    $tipos_evaluacion = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    error_log("Error al cargar datos: " . $e->getMessage());
    $secciones = $materias = $lapsos = $tipos_evaluacion = [];
}

// Determinar si la sección seleccionada es primaria o secundaria
$nivel_seccion = '';
if ($seccion_filtro) {
    foreach ($secciones as $seccion) {
        if ($seccion['id'] == $seccion_filtro) {
            $nivel_seccion = $seccion['nivel'];
            break;
        }
    }
}

// Obtener materias asignadas a la sección seleccionada
$materias_seccion = [];
if ($seccion_filtro) {
    try {
        $stmt = $pdo->prepare("
            SELECT DISTINCT m.id, m.nombre, m.codigo
            FROM materias m
            JOIN profesores_secciones_materias psm ON m.id = psm.materia_id
            WHERE psm.seccion_id = ? AND psm.año_escolar_id = ?
            ORDER BY m.nombre
        ");
        
        $stmt_año = $pdo->query("SELECT id FROM años_escolares WHERE estado = 'activo' LIMIT 1");
        $año_activo = $stmt_año->fetch(PDO::FETCH_ASSOC);
        
        if ($año_activo) {
            $stmt->execute([$seccion_filtro, $año_activo['id']]);
            $materias_seccion = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
    } catch (PDOException $e) {
        error_log("Error al obtener materias de la sección: " . $e->getMessage());
        $materias_seccion = [];
    }
}

// Si es primaria y hay materias asignadas, seleccionar la primera por defecto
if ($seccion_filtro && $nivel_seccion === 'primaria' && !empty($materias_seccion) && empty($materia_filtro)) {
    $materia_filtro = $materias_seccion[0]['id'];
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
        
        // Validar que la nota esté en el rango 0-20 o esté vacía
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
                    
                    // Convertir a letras (opcional)
                    $valor_letra = convertirNotaLetras($valor_decimal);
                    
                    // Insertar o actualizar nota
                    $stmt = $pdo->prepare("
                        INSERT INTO notas (inscripcion_id, materia_id, lapso_id, tipo_evaluacion_id, valor_decimal, valor_letra, comentario) 
                        VALUES (?, ?, ?, ?, ?, ?, ?)
                        ON DUPLICATE KEY UPDATE 
                            valor_decimal = VALUES(valor_decimal),
                            valor_letra = VALUES(valor_letra),
                            comentario = VALUES(comentario),
                            fecha_registro = CURRENT_TIMESTAMP
                    ");
                    $stmt->execute([
                        $inscripcion_id, 
                        $materia_id, 
                        $lapso_id, 
                        $tipo_evaluacion_id,
                        $valor_decimal, 
                        $valor_letra, 
                        $observaciones
                    ]);
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
// Función para calcular y mostrar promedios
function mostrarPromediosEstudiantes($estudiantes, $materia_filtro, $lapso_filtro) {
    global $pdo;
    
    if (empty($estudiantes)) return;
    
    echo '<div class="card mt-4">';
    echo '<div class="card-header">';
    echo '<h5 class="card-title mb-0"><i class="fas fa-chart-line me-2"></i>Promedios de Estudiantes</h5>';
    echo '</div>';
    echo '<div class="card-body">';
    echo '<div class="table-responsive">';
    echo '<table class="table table-sm table-striped">';
    echo '<thead>';
    echo '<tr>';
    echo '<th>Estudiante</th>';
    echo '<th>Promedio Actual</th>';
    echo '<th>Total Evaluaciones</th>';
    echo '<th>Estado</th>';
    echo '</tr>';
    echo '</thead>';
    echo '<tbody>';
    
    foreach ($estudiantes as $estudiante) {
        $promedio_data = calcularPromedioMateriaLapso(
            $estudiante['inscripcion_id'], 
            $materia_filtro, 
            $lapso_filtro
        );
        
        $promedio = $promedio_data['promedio'];
        $total_evaluaciones = $promedio_data['total_evaluaciones'];
        
        // Determinar color según el promedio
        $color_clase = 'text-muted';
        $estado = 'Sin notas';
        
        if ($promedio !== null) {
            if ($promedio >= 10.0) {
                $color_clase = 'text-success';
                $estado = 'Aprobado';
            } else {
                $color_clase = 'text-danger';
                $estado = 'Reprobado';
            }
        }
        
        echo '<tr>';
        echo '<td><strong>' . htmlspecialchars($estudiante['nombre'] . ' ' . $estudiante['apellido']) . '</strong></td>';
        echo '<td><span class="' . $color_clase . ' fw-bold">' . ($promedio ?? 'N/A') . '</span></td>';
        echo '<td>' . $total_evaluaciones . '</td>';
        echo '<td><span class="badge bg-' . ($estado === 'Aprobado' ? 'success' : ($estado === 'Reprobado' ? 'danger' : 'secondary')) . '">' . $estado . '</span></td>';
        echo '</tr>';
    }
    
    echo '</tbody>';
    echo '</table>';
    echo '</div>';
    echo '</div>';
    echo '</div>';
}
// Obtener estudiantes y notas si hay filtros seleccionados
$estudiantes = [];
$notas_existentes = [];

if ($seccion_filtro && $materia_filtro && $lapso_filtro && $tipo_evaluacion_filtro) {
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
        
        // Necesitamos el año escolar activo
        $stmt_año = $pdo->query("SELECT id FROM años_escolares WHERE estado = 'activo' LIMIT 1");
        $año_activo = $stmt_año->fetch(PDO::FETCH_ASSOC);
        
        if ($año_activo) {
            $stmt->execute([$seccion_filtro, $año_activo['id']]);
            $estudiantes = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
        
    } catch (PDOException $e) {
        $estudiantes = [];
        error_log("Error al cargar estudiantes: " . $e->getMessage());
    }
    
    // Obtener notas existentes para este tipo de evaluación específico
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
        error_log("Error al cargar notas existentes: " . $e->getMessage());
    }
}
?>

<?php include '../../includes/header.php'; ?>
    <?php include '../../includes/sidebar_admin.php'; ?>

    <div class="container-fluid">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h2>Gestión de Notas - Administrador</h2>
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
                <h5 class="card-title mb-0">Seleccionar Grupo y Evaluación</h5>
            </div>
            <div class="card-body">
                <form method="GET" class="row g-3" id="filtroForm">
                    <div class="col-md-3">
                        <label for="seccion_id" class="form-label">Sección</label>
                        <select class="form-select" id="seccion_id" name="seccion_id" required>
                            <option value="">Seleccionar sección...</option>
                            <?php foreach ($secciones as $seccion): ?>
                                <option value="<?php echo $seccion['id']; ?>" 
                                    <?php echo $seccion_filtro == $seccion['id'] ? 'selected' : ''; ?>
                                    data-nivel="<?php echo $seccion['nivel']; ?>">
                                    <?php echo htmlspecialchars($seccion['nombre'] . ' - ' . $seccion['año_escolar']); ?>
                                    (<?php echo ucfirst($seccion['nivel']); ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="col-md-3">
                        <label for="materia_id" class="form-label">Materia</label>
                        <select class="form-select" id="materia_id" name="materia_id" required>
                            <option value="">Seleccionar materia...</option>
                            <?php if ($seccion_filtro && !empty($materias_seccion)): ?>
                                <!-- Mostrar solo materias asignadas a esta sección -->
                                <?php foreach ($materias_seccion as $materia): ?>
                                    <option value="<?php echo $materia['id']; ?>" 
                                        <?php echo $materia_filtro == $materia['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($materia['nombre'] . ' (' . $materia['codigo'] . ')'); ?>
                                    </option>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <!-- Mostrar todas las materias cuando no hay sección seleccionada -->
                                <?php foreach ($materias as $materia): ?>
                                    <option value="<?php echo $materia['id']; ?>" 
                                        <?php echo $materia_filtro == $materia['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($materia['nombre'] . ' (' . $materia['codigo'] . ')'); ?>
                                    </option>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </select>
                        <?php if ($seccion_filtro && $nivel_seccion === 'primaria'): ?>
                            <div class="form-text text-info">
                                <i class="fas fa-info-circle"></i> 
                                <?php if (!empty($materias_seccion)): ?>
                                    Materias asignadas a esta sección de primaria
                                <?php else: ?>
                                    No hay materias asignadas a esta sección
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
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
                                    <?php echo htmlspecialchars($tipo['nombre'] . ' (Peso: ' . $tipo['peso'] . ')'); ?>
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
                <div class="card-header">
                    <h5 class="card-title mb-0">
                        Registro de Notas - 
                        <?php 
                        $seccion_nombre = '';
                        $materia_nombre = '';
                        $lapso_nombre = '';
                        $tipo_evaluacion_nombre = '';
                        
                        foreach ($secciones as $sec) {
                            if ($sec['id'] == $seccion_filtro) {
                                $seccion_nombre = $sec['nombre'];
                                break;
                            }
                        }
                        
                        // Buscar nombre de la materia en las materias de la sección o en todas las materias
                        $materia_encontrada = false;
                        foreach ($materias_seccion as $mat) {
                            if ($mat['id'] == $materia_filtro) {
                                $materia_nombre = $mat['nombre'];
                                $materia_encontrada = true;
                                break;
                            }
                        }
                        
                        if (!$materia_encontrada) {
                            foreach ($materias as $mat) {
                                if ($mat['id'] == $materia_filtro) {
                                    $materia_nombre = $mat['nombre'];
                                    break;
                                }
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
                        <span class="badge bg-<?php echo $nivel_seccion === 'primaria' ? 'info' : 'warning'; ?> ms-2">
                            <?php echo ucfirst($nivel_seccion); ?>
                        </span>
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
                                <thead>
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
			<?php 
// Mostrar promedios después del formulario de notas
if ($seccion_filtro && $materia_filtro && $lapso_filtro && $tipo_evaluacion_filtro && $estudiantes) {
    mostrarPromediosEstudiantes($estudiantes, $materia_filtro, $lapso_filtro);
}
?>
        <?php elseif ($seccion_filtro && $materia_filtro && $lapso_filtro && $tipo_evaluacion_filtro && empty($estudiantes)): ?>
            <div class="alert alert-warning">
                <i class="fas fa-exclamation-triangle me-2"></i>
                No hay estudiantes inscritos en esta sección o no se encontraron estudiantes activos.
            </div>
        <?php endif; ?>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const seccionSelect = document.getElementById('seccion_id');
    const materiaSelect = document.getElementById('materia_id');
    const filtroForm = document.getElementById('filtroForm');
    
    // Cargar materias cuando cambia la sección
    seccionSelect.addEventListener('change', function() {
        const seccionId = this.value;
        
        if (seccionId) {
            // Realizar petición AJAX para obtener las materias de la sección
            fetch(`../../includes/ajax_materias_seccion.php?seccion_id=${seccionId}`)
                .then(response => response.json())
                .then(data => {
                    materiaSelect.innerHTML = '<option value="">Seleccionar materia...</option>';
                    
                    if (data.length > 0) {
                        data.forEach(materia => {
                            materiaSelect.innerHTML += `<option value="${materia.id}">${materia.nombre} (${materia.codigo})</option>`;
                        });
                    } else {
                        materiaSelect.innerHTML += '<option value="">No hay materias asignadas</option>';
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    materiaSelect.innerHTML = '<option value="">Error al cargar materias</option>';
                });
        } else {
            materiaSelect.innerHTML = '<option value="">Seleccionar sección primero</option>';
        }
    });
    
    // Auto-submit cuando se selecciona una sección (solo para primaria)
    seccionSelect.addEventListener('change', function() {
        const selectedOption = this.options[this.selectedIndex];
        const nivel = selectedOption.getAttribute('data-nivel');
        
        if (nivel === 'primaria' && this.value) {
            // Esperar un momento para que se carguen las materias antes de enviar
            setTimeout(() => {
                filtroForm.submit();
            }, 500);
        }
    });
});
</script>

<?php include '../../includes/footer.php'; ?>