<?php
require_once '../../includes/auth.php';
require_once '../../includes/database.php';
checkAuth();
checkRole(['admin']);

$mensaje = '';
$tipo_mensaje = '';

// Procesar formulario de agregar/editar
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = $_POST['id'] ?? '';
    $nombre = trim($_POST['nombre']);
    $descripcion = trim($_POST['descripcion']);
    $peso = floatval($_POST['peso']);
    $orden = intval($_POST['orden']);
    $estado = $_POST['estado'];
    
    try {
        if ($id) {
            // Actualizar existente
            $stmt = $pdo->prepare("
                UPDATE tipos_evaluacion 
                SET nombre = ?, descripcion = ?, peso = ?, orden = ?, estado = ?
                WHERE id = ?
            ");
            $stmt->execute([$nombre, $descripcion, $peso, $orden, $estado, $id]);
            $mensaje = "Tipo de evaluación actualizado correctamente";
        } else {
            // Insertar nuevo
            $stmt = $pdo->prepare("
                INSERT INTO tipos_evaluacion (nombre, descripcion, peso, orden, estado)
                VALUES (?, ?, ?, ?, ?)
            ");
            $stmt->execute([$nombre, $descripcion, $peso, $orden, $estado]);
            $mensaje = "Tipo de evaluación agregado correctamente";
        }
        $tipo_mensaje = "success";
    } catch (PDOException $e) {
        $mensaje = "Error: " . $e->getMessage();
        $tipo_mensaje = "danger";
    }
}

// Procesar eliminación
if (isset($_GET['eliminar'])) {
    $id_eliminar = $_GET['eliminar'];
    
    try {
        // Verificar si hay notas usando este tipo de evaluación
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM notas WHERE tipo_evaluacion_id = ?");
        $stmt->execute([$id_eliminar]);
        $uso = $stmt->fetchColumn();
        
        if ($uso > 0) {
            $mensaje = "No se puede eliminar: Hay notas asociadas a este tipo de evaluación";
            $tipo_mensaje = "warning";
        } else {
            $stmt = $pdo->prepare("DELETE FROM tipos_evaluacion WHERE id = ?");
            $stmt->execute([$id_eliminar]);
            $mensaje = "Tipo de evaluación eliminado correctamente";
            $tipo_mensaje = "success";
        }
    } catch (PDOException $e) {
        $mensaje = "Error al eliminar: " . $e->getMessage();
        $tipo_mensaje = "danger";
    }
}

// Obtener todos los tipos de evaluación
try {
    $stmt = $pdo->query("SELECT * FROM tipos_evaluacion ORDER BY orden, nombre");
    $tipos_evaluacion = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $tipos_evaluacion = [];
}

// Obtener tipo específico para editar
$tipo_editar = null;
if (isset($_GET['editar'])) {
    try {
        $stmt = $pdo->prepare("SELECT * FROM tipos_evaluacion WHERE id = ?");
        $stmt->execute([$_GET['editar']]);
        $tipo_editar = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $tipo_editar = null;
    }
}
?>

<?php include '../../includes/header.php'; ?>

<div class="content-wrapper">
    <div class="container-fluid p-4">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h2><i class="fas fa-clipboard-list me-2"></i>Gestión de Tipos de Evaluación</h2>
        </div>

        <?php if ($mensaje): ?>
            <div class="alert alert-<?php echo $tipo_mensaje; ?> alert-dismissible fade show" role="alert">
                <?php echo $mensaje; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <div class="row">
            <!-- Formulario -->
            <div class="col-md-4">
                <div class="card">
                    <div class="card-header bg-primary text-white">
                        <h5 class="card-title mb-0">
                            <i class="fas fa-<?php echo $tipo_editar ? 'edit' : 'plus'; ?> me-2"></i>
                            <?php echo $tipo_editar ? 'Editar' : 'Agregar'; ?> Tipo de Evaluación
                        </h5>
                    </div>
                    <div class="card-body">
                        <form method="POST" id="formTipoEvaluacion">
                            <input type="hidden" name="id" value="<?php echo $tipo_editar['id'] ?? ''; ?>">
                            
                            <div class="mb-3">
                                <label for="nombre" class="form-label">Nombre *</label>
                                <input type="text" class="form-control" id="nombre" name="nombre" 
                                       value="<?php echo htmlspecialchars($tipo_editar['nombre'] ?? ''); ?>" 
                                       required maxlength="100">
                            </div>
                            
                            <div class="mb-3">
                                <label for="descripcion" class="form-label">Descripción</label>
                                <textarea class="form-control" id="descripcion" name="descripcion" 
                                          rows="3"><?php echo htmlspecialchars($tipo_editar['descripcion'] ?? ''); ?></textarea>
                            </div>
                            
                            <div class="mb-3">
                                <label for="peso" class="form-label">Peso en el promedio *</label>
                                <div class="input-group">
                                    <input type="number" class="form-control" id="peso" name="peso" 
                                           value="<?php echo $tipo_editar['peso'] ?? '1.00'; ?>" 
                                           min="0.01" max="1.00" step="0.01" required>
                                    <span class="input-group-text">%</span>
                                </div>
                                <div class="form-text">
                                    Ejemplo: 0.25 = 25% del promedio final. La suma de todos los pesos debe ser 1.00 (100%)
                                </div>
                                <div id="pesoFeedback" class="text-danger small"></div>
                            </div>
                            
                            <div class="mb-3">
                                <label for="orden" class="form-label">Orden de visualización *</label>
                                <input type="number" class="form-control" id="orden" name="orden" 
                                       value="<?php echo $tipo_editar['orden'] ?? '0'; ?>" 
                                       min="0" max="100" required>
                                <div class="form-text">Número para ordenar en listas (menor = primero)</div>
                            </div>
                            
                            <div class="mb-3">
                                <label for="estado" class="form-label">Estado *</label>
                                <select class="form-select" id="estado" name="estado" required>
                                    <option value="activo" <?php echo ($tipo_editar['estado'] ?? 'activo') == 'activo' ? 'selected' : ''; ?>>Activo</option>
                                    <option value="inactivo" <?php echo ($tipo_editar['estado'] ?? '') == 'inactivo' ? 'selected' : ''; ?>>Inactivo</option>
                                </select>
                            </div>
                            
                            <div class="d-grid gap-2">
                                <button type="submit" class="btn btn-primary">
                                    <i class="fas fa-save me-2"></i>
                                    <?php echo $tipo_editar ? 'Actualizar' : 'Guardar'; ?>
                                </button>
                                
                                <?php if ($tipo_editar): ?>
                                    <a href="gestionar.php" class="btn btn-outline-secondary">
                                        <i class="fas fa-times me-2"></i>Cancelar
                                    </a>
                                <?php endif; ?>
                            </div>
                        </form>
                    </div>
                </div>
            </div>

            <!-- Lista -->
            <div class="col-md-8">
                <div class="card">
                    <div class="card-header bg-success text-white">
                        <h5 class="card-title mb-0">
                            <i class="fas fa-list me-2"></i>
                            Tipos de Evaluación Registrados
                        </h5>
                    </div>
                    <div class="card-body">
                        <?php if ($tipos_evaluacion): ?>
                            <div class="table-responsive">
                                <table class="table table-striped table-hover">
                                    <thead class="table-dark">
                                        <tr>
                                            <th>Orden</th>
                                            <th>Nombre</th>
                                            <th>Descripción</th>
                                            <th>Peso</th>
                                            <th>Estado</th>
                                            <th>Acciones</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($tipos_evaluacion as $tipo): ?>
                                            <tr>
                                                <td>
                                                    <span class="badge bg-secondary"><?php echo $tipo['orden']; ?></span>
                                                </td>
                                                <td>
                                                    <strong><?php echo htmlspecialchars($tipo['nombre']); ?></strong>
                                                </td>
                                                <td>
                                                    <?php echo $tipo['descripcion'] ? htmlspecialchars(substr($tipo['descripcion'], 0, 50)) . '...' : '<span class="text-muted">Sin descripción</span>'; ?>
                                                </td>
                                                <td>
                                                    <span class="badge bg-info"><?php echo ($tipo['peso'] * 100); ?>%</span>
                                                </td>
                                                <td>
                                                    <span class="badge bg-<?php echo $tipo['estado'] == 'activo' ? 'success' : 'secondary'; ?>">
                                                        <?php echo ucfirst($tipo['estado']); ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <div class="btn-group btn-group-sm">
                                                        <a href="gestionar.php?editar=<?php echo $tipo['id']; ?>" 
                                                           class="btn btn-outline-primary" title="Editar">
                                                            <i class="fas fa-edit"></i>
                                                        </a>
                                                        <a href="gestionar.php?eliminar=<?php echo $tipo['id']; ?>" 
                                                           class="btn btn-outline-danger" 
                                                           onclick="return confirm('¿Estás seguro de eliminar este tipo de evaluación?')"
                                                           title="Eliminar">
                                                            <i class="fas fa-trash"></i>
                                                        </a>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                            
                            <!-- Estadísticas -->
                            <div class="row mt-4">
                                <div class="col-md-6">
                                    <div class="card bg-light">
                                        <div class="card-body text-center">
                                            <h4><?php echo count($tipos_evaluacion); ?></h4>
                                            <p class="mb-0 text-muted">Total de Tipos</p>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="card bg-light">
                                        <div class="card-body text-center">
                                            <h4><?php echo array_sum(array_column($tipos_evaluacion, 'peso')); ?></h4>
                                            <p class="mb-0 text-muted">Suma de Pesos</p>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                        <?php else: ?>
                            <div class="alert alert-info text-center">
                                <i class="fas fa-info-circle fa-2x mb-3"></i>
                                <h4>No hay tipos de evaluación registrados</h4>
                                <p class="mb-0">Agrega el primer tipo de evaluación usando el formulario.</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const form = document.getElementById('formTipoEvaluacion');
    const pesoInput = document.getElementById('peso');
    const pesoFeedback = document.getElementById('pesoFeedback');
    
    // Validar peso
    pesoInput.addEventListener('change', function() {
        const peso = parseFloat(this.value);
        if (peso < 0.01 || peso > 1.00) {
            pesoFeedback.textContent = 'El peso debe estar entre 0.01 y 1.00';
            this.classList.add('is-invalid');
        } else {
            pesoFeedback.textContent = '';
            this.classList.remove('is-invalid');
        }
    });
    
    // Validar formulario antes de enviar
    form.addEventListener('submit', function(e) {
        const peso = parseFloat(pesoInput.value);
        if (peso < 0.01 || peso > 1.00) {
            e.preventDefault();
            pesoFeedback.textContent = 'El peso debe estar entre 0.01 y 1.00';
            pesoInput.classList.add('is-invalid');
            pesoInput.focus();
        }
    });
});
</script>

<?php include '../../includes/footer.php'; ?>