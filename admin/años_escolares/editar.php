<?php
require_once '../../includes/auth.php';
require_once '../../includes/database.php';
checkAuth();
checkRole(['admin']);

if (!isset($_GET['id'])) {
    header('Location: gestionar.php');
    exit();
}

$id = $_GET['id'];
$mensaje = '';
$tipo_mensaje = '';

// ============================================================
// PROCESAR ACCIONES AJAX (crear/editar/eliminar lapsos)
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_action'])) {
    header('Content-Type: application/json');
    
    try {
        // --- CREAR LAPSO ---
        if ($_POST['ajax_action'] === 'crear_lapso') {
            $numero = intval($_POST['numero']);
            $nombre = trim($_POST['nombre']);
            $fecha_inicio = $_POST['fecha_inicio'] ?: null;
            $fecha_fin = $_POST['fecha_fin'] ?: null;
            
            if (empty($nombre) || $numero < 1) {
                echo json_encode(['success' => false, 'error' => 'Nombre y número son obligatorios']);
                exit;
            }
            
            // Verificar que el número no esté duplicado en este año
            $stmt = $pdo->prepare("SELECT id FROM lapsos WHERE año_escolar_id = ? AND numero = ?");
            $stmt->execute([$id, $numero]);
            if ($stmt->fetch()) {
                echo json_encode(['success' => false, 'error' => "Ya existe un lapso con el número $numero en este año"]);
                exit;
            }
            
            $stmt = $pdo->prepare("
                INSERT INTO lapsos (año_escolar_id, numero, nombre, fecha_inicio, fecha_fin, estado) 
                VALUES (?, ?, ?, ?, ?, 'activo')
            ");
            $stmt->execute([$id, $numero, $nombre, $fecha_inicio, $fecha_fin]);
            
            echo json_encode(['success' => true, 'id' => $pdo->lastInsertId()]);
            exit;
        }
        
        // --- EDITAR LAPSO ---
        if ($_POST['ajax_action'] === 'editar_lapso') {
            $lapso_id = intval($_POST['lapso_id']);
            $numero = intval($_POST['numero']);
            $nombre = trim($_POST['nombre']);
            $fecha_inicio = $_POST['fecha_inicio'] ?: null;
            $fecha_fin = $_POST['fecha_fin'] ?: null;
            $estado = $_POST['estado'] ?? 'activo';
            
            // Verificar que el lapso pertenece a este año
            $stmt = $pdo->prepare("SELECT id FROM lapsos WHERE id = ? AND año_escolar_id = ?");
            $stmt->execute([$lapso_id, $id]);
            if (!$stmt->fetch()) {
                echo json_encode(['success' => false, 'error' => 'Lapso no encontrado o no pertenece a este año']);
                exit;
            }
            
            // Verificar número duplicado (excluyendo el mismo lapso)
            $stmt = $pdo->prepare("SELECT id FROM lapsos WHERE año_escolar_id = ? AND numero = ? AND id != ?");
            $stmt->execute([$id, $numero, $lapso_id]);
            if ($stmt->fetch()) {
                echo json_encode(['success' => false, 'error' => "Ya existe otro lapso con el número $numero"]);
                exit;
            }
            
            $stmt = $pdo->prepare("
                UPDATE lapsos 
                SET numero = ?, nombre = ?, fecha_inicio = ?, fecha_fin = ?, estado = ?
                WHERE id = ? AND año_escolar_id = ?
            ");
            $stmt->execute([$numero, $nombre, $fecha_inicio, $fecha_fin, $estado, $lapso_id, $id]);
            
            echo json_encode(['success' => true]);
            exit;
        }
        
        // --- ELIMINAR LAPSO ---
        if ($_POST['ajax_action'] === 'eliminar_lapso') {
            $lapso_id = intval($_POST['lapso_id']);
            
            // Verificar que no tenga evaluaciones
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM literales_lapso WHERE lapso_id = ?");
            $stmt->execute([$lapso_id]);
            $tiene_evaluaciones = $stmt->fetchColumn();
            
            if ($tiene_evaluaciones > 0) {
                echo json_encode([
                    'success' => false, 
                    'error' => "No se puede eliminar: el lapso ya tiene $tiene_evaluaciones evaluaciones registradas"
                ]);
                exit;
            }
            
            $stmt = $pdo->prepare("DELETE FROM lapsos WHERE id = ? AND año_escolar_id = ?");
            $stmt->execute([$lapso_id, $id]);
            
            echo json_encode(['success' => true]);
            exit;
        }
        
        echo json_encode(['success' => false, 'error' => 'Acción desconocida']);
        exit;
        
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        exit;
    }
}

// ============================================================
// CARGAR DATOS DEL AÑO ESCOLAR
// ============================================================
try {
    $stmt = $pdo->prepare("SELECT * FROM años_escolares WHERE id = ?");
    $stmt->execute([$id]);
    $año_escolar = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$año_escolar) {
        header('Location: gestionar.php');
        exit();
    }
} catch (PDOException $e) {
    error_log("Error en " . __FILE__ . ": " . $e->getMessage());
    $_SESSION['error_sistema'] = "Ocurrió un error al procesar la solicitud. El equipo técnico fue notificado.";
    header('Location: ' . ($_SERVER['HTTP_REFERER'] ?? app_url()));
    exit;
}

// Cargar lapsos del año con conteo de evaluaciones
try {
    $stmt = $pdo->prepare("
        SELECT l.*, 
               (SELECT COUNT(*) FROM literales_lapso ll WHERE ll.lapso_id = l.id) AS total_evaluaciones
        FROM lapsos l
        WHERE l.año_escolar_id = ?
        ORDER BY l.numero
    ");
    $stmt->execute([$id]);
    $lapsos = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $lapsos = [];
}

// Sugerencias
$ano_actual = date('Y');
$sugerencias = [
    "{$ano_actual}-" . ($ano_actual + 1),
    ($ano_actual + 1) . "-" . ($ano_actual + 2),
    "Año Escolar {$ano_actual}",
    "Año Escolar " . ($ano_actual + 1),
    "Periodo {$ano_actual}-" . ($ano_actual + 1)
];

// ============================================================
// PROCESAR FORMULARIO PRINCIPAL (datos del año)
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['ajax_action'])) {
    $nombre = trim($_POST['nombre'] ?? '');
    
    // Si el select tiene "personalizado", usar el campo personalizado
    if ($nombre === 'personalizado') {
        $nombre = trim($_POST['nombre_personalizado'] ?? '');
    }
    
    $fecha_inicio = $_POST['fecha_inicio'];
    $fecha_fin = $_POST['fecha_fin'];
    $estado = $_POST['estado'];
    
    if (!empty($nombre)) {
        try {
            if ($estado == 'activo' && $año_escolar['estado'] != 'activo') {
                $stmt = $pdo->prepare("UPDATE años_escolares SET estado = 'inactivo' WHERE estado = 'activo'");
                $stmt->execute();
            }
            
            $stmt = $pdo->prepare("UPDATE años_escolares SET nombre = ?, fecha_inicio = ?, fecha_fin = ?, estado = ? WHERE id = ?");
            $stmt->execute([$nombre, $fecha_inicio, $fecha_fin, $estado, $id]);
            
            $mensaje = "Año escolar \"$nombre\" actualizado correctamente";
            $tipo_mensaje = "success";
            
            $año_escolar['nombre'] = $nombre;
            $año_escolar['fecha_inicio'] = $fecha_inicio;
            $año_escolar['fecha_fin'] = $fecha_fin;
            $año_escolar['estado'] = $estado;
            
        } catch (PDOException $e) {
            $mensaje = "Error al actualizar año escolar: " . $e->getMessage();
            $tipo_mensaje = "danger";
        }
    } else {
        $mensaje = "Por favor complete el nombre del año escolar";
        $tipo_mensaje = "warning";
    }
}
?>

<?php include '../../includes/header.php'; ?>

<div class="content-wrapper" style="padding: 20px;">
    <?php include '../../includes/sidebar_admin.php'; ?>

    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2>Editar Año Escolar</h2>
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

    <!-- ============================================== -->
    <!-- CARD 1: DATOS DEL AÑO ESCOLAR                  -->
    <!-- ============================================== -->
    <div class="card mb-4">
        <div class="card-header bg-primary text-white">
            <h5 class="card-title mb-0">
                <i class="fas fa-calendar-alt me-2"></i>Datos del Año Escolar
            </h5>
        </div>
        <div class="card-body">
            <form method="POST" action="" id="formAnoEscolar">
                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label for="nombre" class="form-label">Nombre del Año Escolar *</label>
                        <select class="form-select" id="nombre" name="nombre" required onchange="actualizarFechas()">
                            <option value="">Seleccionar o personalizar...</option>
                            <optgroup label="Sugerencias Automáticas">
                                <?php foreach ($sugerencias as $sugerencia): ?>
                                    <option value="<?php echo htmlspecialchars($sugerencia); ?>" 
                                        <?php echo $año_escolar['nombre'] == $sugerencia ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($sugerencia); ?>
                                    </option>
                                <?php endforeach; ?>
                            </optgroup>
                            <optgroup label="Valor Actual">
                                <option value="<?php echo htmlspecialchars($año_escolar['nombre']); ?>" selected>
                                    ↳ <?php echo htmlspecialchars($año_escolar['nombre']); ?> (Actual)
                                </option>
                            </optgroup>
                            <optgroup label="Personalizado">
                                <option value="personalizado">↳ Escribir nombre personalizado</option>
                            </optgroup>
                        </select>
                    </div>
                    
                    <div class="col-md-6 mb-3">
                        <label for="estado" class="form-label">Estado *</label>
                        <select class="form-select" id="estado" name="estado" required>
                            <option value="planificado" <?php echo $año_escolar['estado'] == 'planificado' ? 'selected' : ''; ?>>Planificado</option>
                            <option value="activo" <?php echo $año_escolar['estado'] == 'activo' ? 'selected' : ''; ?>>Activo</option>
                            <option value="inactivo" <?php echo $año_escolar['estado'] == 'inactivo' ? 'selected' : ''; ?>>Inactivo</option>
                        </select>
                        <div class="form-text">
                            <i class="fas fa-exclamation-triangle me-1"></i>
                            Al activar, los demás años se desactivan
                        </div>
                    </div>
                </div>

                <div id="campoPersonalizado" class="mb-3" style="display: none;">
                    <label for="nombre_personalizado" class="form-label">Nombre Personalizado *</label>
                    <input type="text" class="form-control" id="nombre_personalizado" name="nombre_personalizado"
                           value="<?php echo htmlspecialchars($año_escolar['nombre']); ?>">
                </div>
                
                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label for="fecha_inicio" class="form-label">Fecha de Inicio</label>
                        <input type="date" class="form-control" id="fecha_inicio" name="fecha_inicio"
                               value="<?php echo htmlspecialchars($año_escolar['fecha_inicio'] ?? ''); ?>">
                    </div>
                    
                    <div class="col-md-6 mb-3">
                        <label for="fecha_fin" class="form-label">Fecha de Fin</label>
                        <input type="date" class="form-control" id="fecha_fin" name="fecha_fin"
                               value="<?php echo htmlspecialchars($año_escolar['fecha_fin'] ?? ''); ?>">
                    </div>
                </div>

                <div class="text-end">
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save me-2"></i>Actualizar Datos
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- ============================================== -->
    <!-- CARD 2: GESTIÓN DE LAPSOS                      -->
    <!-- ============================================== -->
    <div class="card">
        <div class="card-header bg-info text-white d-flex justify-content-between align-items-center">
            <h5 class="card-title mb-0">
                <i class="fas fa-calendar-check me-2"></i>
                Lapsos Académicos
                <span class="badge bg-light text-dark ms-2" id="badge-total-lapsos"><?php echo count($lapsos); ?></span>
            </h5>
            <button type="button" class="btn btn-light btn-sm" onclick="mostrarFormularioNuevo()">
                <i class="fas fa-plus me-1"></i>Nuevo Lapso
            </button>
        </div>
        <div class="card-body">
            
            <!-- Formulario inline para nuevo lapso -->
            <div id="form-nuevo-lapso" class="alert alert-info" style="display: none;">
                <h6 class="mb-3"><i class="fas fa-plus-circle me-2"></i>Nuevo Lapso</h6>
                <form id="formCrearLapso" class="row g-2">
                    <div class="col-md-2">
                        <label class="form-label small">Número *</label>
                        <input type="number" class="form-control form-control-sm" name="numero" 
                               min="1" max="10" value="<?php echo count($lapsos) + 1; ?>" required>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label small">Nombre *</label>
                        <input type="text" class="form-control form-control-sm" name="nombre" 
                               placeholder="Ej: Primer Lapso" required>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label small">Fecha Inicio</label>
                        <input type="date" class="form-control form-control-sm" name="fecha_inicio">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label small">Fecha Fin</label>
                        <input type="date" class="form-control form-control-sm" name="fecha_fin">
                    </div>
                    <div class="col-md-2 d-flex align-items-end">
                        <button type="submit" class="btn btn-success btn-sm w-100">
                            <i class="fas fa-save"></i> Guardar
                        </button>
                    </div>
                    <div class="col-12 text-end">
                        <button type="button" class="btn btn-outline-secondary btn-sm" onclick="ocultarFormularioNuevo()">
                            Cancelar
                        </button>
                    </div>
                </form>
            </div>
            
            <!-- Tabla de lapsos existentes -->
            <?php if (empty($lapsos)): ?>
                <div class="alert alert-warning text-center mb-0">
                    <i class="fas fa-exclamation-triangle me-2"></i>
                    Este año escolar no tiene lapsos configurados.
                    <button type="button" class="btn btn-sm btn-warning ms-2" onclick="mostrarFormularioNuevo()">
                        <i class="fas fa-plus"></i> Crear primer lapso
                    </button>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover align-middle">
                        <thead class="table-light">
                            <tr>
                                <th width="5%">#</th>
                                <th width="25%">Nombre</th>
                                <th width="13%">Inicio</th>
                                <th width="13%">Fin</th>
                                <th width="10%">Estado</th>
                                <th width="12%">Evaluaciones</th>
                                <th width="22%">Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($lapsos as $lapso): ?>
                                <tr id="fila-lapso-<?php echo $lapso['id']; ?>">
                                    <td><span class="badge bg-secondary"><?php echo $lapso['numero']; ?></span></td>
                                    <td><strong><?php echo htmlspecialchars($lapso['nombre']); ?></strong></td>
                                    <td><?php echo $lapso['fecha_inicio'] ? date('d/m/Y', strtotime($lapso['fecha_inicio'])) : '—'; ?></td>
                                    <td><?php echo $lapso['fecha_fin'] ? date('d/m/Y', strtotime($lapso['fecha_fin'])) : '—'; ?></td>
                                    <td>
                                        <span class="badge bg-<?php echo $lapso['estado'] == 'activo' ? 'success' : 'secondary'; ?>">
                                            <?php echo ucfirst($lapso['estado']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="badge bg-<?php echo $lapso['total_evaluaciones'] > 0 ? 'info' : 'light text-dark'; ?>">
                                            <?php echo $lapso['total_evaluaciones']; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <button type="button" class="btn btn-outline-primary btn-sm" 
                                                onclick='editarLapso(<?php echo json_encode($lapso, JSON_HEX_APOS | JSON_HEX_QUOT); ?>)'
                                                title="Editar">
                                            <i class="fas fa-edit"></i>
                                        </button>
                                        <button type="button" class="btn btn-outline-danger btn-sm" 
                                                onclick="eliminarLapso(<?php echo $lapso['id']; ?>, '<?php echo htmlspecialchars($lapso['nombre'], ENT_QUOTES); ?>')"
                                                title="Eliminar"
                                                <?php echo $lapso['total_evaluaciones'] > 0 ? 'disabled' : ''; ?>>
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                
                <div class="alert alert-info mb-0 mt-3 small">
                    <i class="fas fa-info-circle me-1"></i>
                    <strong>Nota:</strong> No se pueden eliminar lapsos que ya tengan evaluaciones registradas.
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- ============================================== -->
<!-- MODAL: EDITAR LAPSO                            -->
<!-- ============================================== -->
<div class="modal fade" id="modalEditarLapso" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-warning">
                <h5 class="modal-title"><i class="fas fa-edit me-2"></i>Editar Lapso</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form id="formEditarLapso">
                <div class="modal-body">
                    <input type="hidden" name="lapso_id" id="edit_lapso_id">
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label">Número *</label>
                            <input type="number" class="form-control" name="numero" id="edit_numero" 
                                   min="1" max="10" required>
                        </div>
                        <div class="col-md-8">
                            <label class="form-label">Nombre *</label>
                            <input type="text" class="form-control" name="nombre" id="edit_nombre" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Fecha Inicio</label>
                            <input type="date" class="form-control" name="fecha_inicio" id="edit_fecha_inicio">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Fecha Fin</label>
                            <input type="date" class="form-control" name="fecha_fin" id="edit_fecha_fin">
                        </div>
                        <div class="col-12">
                            <label class="form-label">Estado</label>
                            <select class="form-select" name="estado" id="edit_estado">
                                <option value="activo">Activo</option>
                                <option value="inactivo">Inactivo</option>
                            </select>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-warning">
                        <i class="fas fa-save me-1"></i>Actualizar
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
// ============================================================
// FUNCIONES DEL FORMULARIO PRINCIPAL
// ============================================================

function actualizarFechas() {
    const selectNombre = document.getElementById('nombre');
    const campoPersonalizado = document.getElementById('campoPersonalizado');
    const inputPersonalizado = document.getElementById('nombre_personalizado');
    
    if (selectNombre.value === 'personalizado') {
        campoPersonalizado.style.display = 'block';
        inputPersonalizado.focus();
    } else {
        campoPersonalizado.style.display = 'none';
    }
    
    if (selectNombre.value.match(/^\d{4}-\d{4}$/)) {
        const años = selectNombre.value.split('-');
        document.getElementById('fecha_inicio').value = `${años[0]}-09-16`;
        document.getElementById('fecha_fin').value = `${años[1]}-07-15`;
    }
}

document.getElementById('formAnoEscolar').addEventListener('submit', function(e) {
    const selectNombre = document.getElementById('nombre');
    const inputPersonalizado = document.getElementById('nombre_personalizado');
    
    if (selectNombre.value === 'personalizado') {
        if (!inputPersonalizado.value.trim()) {
            e.preventDefault();
            alert('Por favor ingrese el nombre personalizado del año escolar');
            inputPersonalizado.focus();
            return false;
        }
        selectNombre.value = inputPersonalizado.value.trim();
    }
    
    const fechaInicio = document.getElementById('fecha_inicio').value;
    const fechaFin = document.getElementById('fecha_fin').value;
    
    if (fechaInicio && fechaFin && fechaFin <= fechaInicio) {
        e.preventDefault();
        alert('La fecha de fin debe ser posterior a la fecha de inicio');
        return false;
    }
});

// ============================================================
// GESTIÓN DE LAPSOS (AJAX)
// ============================================================

function mostrarFormularioNuevo() {
    document.getElementById('form-nuevo-lapso').style.display = 'block';
    document.querySelector('#form-nuevo-lapso input[name="nombre"]').focus();
}

function ocultarFormularioNuevo() {
    document.getElementById('form-nuevo-lapso').style.display = 'none';
    document.getElementById('formCrearLapso').reset();
}

// Crear lapso
document.getElementById('formCrearLapso').addEventListener('submit', function(e) {
    e.preventDefault();
    const formData = new FormData(this);
    formData.append('ajax_action', 'crear_lapso');
    
    fetch('', { method: 'POST', body: formData })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                location.reload();
            } else {
                alert('Error: ' + data.error);
            }
        })
        .catch(err => alert('Error de conexión: ' + err));
});

// Abrir modal de edición
function editarLapso(lapso) {
    document.getElementById('edit_lapso_id').value = lapso.id;
    document.getElementById('edit_numero').value = lapso.numero;
    document.getElementById('edit_nombre').value = lapso.nombre;
    document.getElementById('edit_fecha_inicio').value = lapso.fecha_inicio || '';
    document.getElementById('edit_fecha_fin').value = lapso.fecha_fin || '';
    document.getElementById('edit_estado').value = lapso.estado;
    
    new bootstrap.Modal(document.getElementById('modalEditarLapso')).show();
}

// Guardar edición
document.getElementById('formEditarLapso').addEventListener('submit', function(e) {
    e.preventDefault();
    const formData = new FormData(this);
    formData.append('ajax_action', 'editar_lapso');
    
    fetch('', { method: 'POST', body: formData })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                location.reload();
            } else {
                alert('Error: ' + data.error);
            }
        })
        .catch(err => alert('Error de conexión: ' + err));
});

// Eliminar lapso
function eliminarLapso(lapsoId, nombre) {
    if (!confirm(`¿Eliminar el lapso "${nombre}"?\n\nEsta acción no se puede deshacer.`)) return;
    
    const formData = new FormData();
    formData.append('ajax_action', 'eliminar_lapso');
    formData.append('lapso_id', lapsoId);
    
    fetch('', { method: 'POST', body: formData })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                location.reload();
            } else {
                alert('Error: ' + data.error);
            }
        })
        .catch(err => alert('Error de conexión: ' + err));
}

// ============================================================
// INICIALIZACIÓN
// ============================================================
document.addEventListener('DOMContentLoaded', function() {
    // Detectar si el nombre actual está en sugerencias
    const nombreActual = "<?php echo htmlspecialchars($año_escolar['nombre'], ENT_QUOTES); ?>";
    const selectNombre = document.getElementById('nombre');
    let encontrado = false;
    
    for (let i = 0; i < selectNombre.options.length; i++) {
        if (selectNombre.options[i].value === nombreActual) {
            encontrado = true;
            break;
        }
    }
    
    if (!encontrado && nombreActual !== 'personalizado') {
        selectNombre.value = 'personalizado';
        actualizarFechas();
    }
});
</script>

<?php include '../../includes/footer.php'; ?>
