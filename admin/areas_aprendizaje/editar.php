<?php
require __DIR__ . '/redirigir_plan_anual.php';
// admin/areas_aprendizaje/editar.php
require_once '../../includes/auth.php';
require_once '../../includes/database.php';
checkAuth();
checkRole(['admin']);

$area_id = $_GET['id'] ?? null;
$mensaje = '';
$tipo_mensaje = '';

if (!$area_id) {
    $_SESSION['mensaje'] = "No se especificó el área";
    $_SESSION['tipo_mensaje'] = "danger";
    header('Location: gestionar.php');
    exit;
}

// Cargar datos del área
try {
    $stmt = $pdo->prepare("SELECT * FROM areas_aprendizaje WHERE id = ?");
    $stmt->execute([$area_id]);
    $area = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$area) {
        $_SESSION['mensaje'] = "Área de aprendizaje no encontrada";
        $_SESSION['tipo_mensaje'] = "danger";
        header('Location: gestionar.php');
        exit;
    }
} catch (PDOException $e) {
    $_SESSION['mensaje'] = "Error al cargar el área: " . $e->getMessage();
    $_SESSION['tipo_mensaje'] = "danger";
    header('Location: gestionar.php');
    exit;
}

// Procesar formulario
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nombre = trim($_POST['nombre'] ?? '');
    $codigo = trim($_POST['codigo'] ?? '');
    $descripcion = trim($_POST['descripcion'] ?? '');
    $nivel = $_POST['nivel'] ?? '';
    $estado = $_POST['estado'] ?? 'activa';
    
    $errores = [];
    
    if (empty($nombre)) {
        $errores[] = "El nombre del área es obligatorio";
    }
    if (empty($codigo)) {
        $errores[] = "El código del área es obligatorio";
    }
    if (empty($nivel)) {
        $errores[] = "El nivel educativo es obligatorio";
    }
    
    // Verificar código duplicado (excluyendo esta área)
    if (empty($errores)) {
        try {
            $stmt = $pdo->prepare("SELECT id FROM areas_aprendizaje WHERE codigo = ? AND id != ?");
            $stmt->execute([$codigo, $area_id]);
            if ($stmt->fetch()) {
                $errores[] = "Ya existe otra área con el código '$codigo'";
            }
        } catch (PDOException $e) {
            $errores[] = "Error al verificar código: " . $e->getMessage();
        }
    }
    
    if (empty($errores)) {
        try {
            $stmt = $pdo->prepare("
                UPDATE areas_aprendizaje 
                SET nombre = ?, codigo = ?, descripcion = ?, nivel = ?, estado = ?
                WHERE id = ?
            ");
            $stmt->execute([$nombre, $codigo, $descripcion, $nivel, $estado, $area_id]);
            
            $mensaje = "Área de aprendizaje actualizada correctamente";
            $tipo_mensaje = "success";
            
            // Actualizar datos locales
            $area['nombre'] = $nombre;
            $area['codigo'] = $codigo;
            $area['descripcion'] = $descripcion;
            $area['nivel'] = $nivel;
            $area['estado'] = $estado;
            
        } catch (PDOException $e) {
            $mensaje = "Error al actualizar área: " . $e->getMessage();
            $tipo_mensaje = "danger";
        }
    } else {
        $mensaje = implode("<br>", $errores);
        $tipo_mensaje = "warning";
    }
}

// Contar indicadores asociados
try {
    $stmt = $pdo->prepare("SELECT COUNT(*) as total FROM areas_indicadores WHERE area_id = ? AND estado = 'activo'");
    $stmt->execute([$area_id]);
    $total_indicadores = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
} catch (PDOException $e) {
    $total_indicadores = 0;
}
?>

<?php include '../../includes/header.php'; ?>

<div class="content-wrapper" style="padding: 20px;">
    <?php include '../../includes/sidebar_admin.php'; ?>
    
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2>
            <i class="fas fa-edit me-2"></i>Editar Área de Aprendizaje
        </h2>
        <a href="gestionar.php" class="btn btn-outline-secondary">
            <i class="fas fa-arrow-left me-2"></i>Volver a la Lista
        </a>
    </div>

    <?php if ($mensaje): ?>
        <div class="alert alert-<?php echo $tipo_mensaje; ?> alert-dismissible fade show" role="alert">
            <?php echo $mensaje; ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <div class="row">
        <div class="col-md-8">
            <div class="card">
                <div class="card-header bg-warning text-dark">
                    <h5 class="card-title mb-0">
                        <i class="fas fa-pencil-alt me-2"></i>
                        Editando: <?php echo htmlspecialchars($area['nombre']); ?>
                    </h5>
                </div>
                <div class="card-body">
                    <form method="POST" action="" id="form-area">
                        <div class="mb-3">
                            <label for="nombre" class="form-label">Nombre del Área *</label>
                            <input type="text" class="form-control" id="nombre" name="nombre" 
                                   value="<?php echo htmlspecialchars($area['nombre']); ?>" 
                                   placeholder="Ej: Matemática, Lengua y Literatura" required>
                        </div>
                        
                        <div class="mb-3">
                            <label for="codigo" class="form-label">Código *</label>
                            <input type="text" class="form-control" id="codigo" name="codigo" 
                                   value="<?php echo htmlspecialchars($area['codigo']); ?>" 
                                   placeholder="Ej: MAT, LL, CN" maxlength="10" required>
                            <div class="form-text">Código único (máx. 10 caracteres, solo mayúsculas y números)</div>
                        </div>
                        
                        <div class="mb-3">
                            <label for="descripcion" class="form-label">Descripción</label>
                            <textarea class="form-control" id="descripcion" name="descripcion" 
                                      rows="3" placeholder="Breve descripción del área..."><?php echo htmlspecialchars($area['descripcion'] ?? ''); ?></textarea>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="nivel" class="form-label">Nivel Educativo *</label>
                                <select class="form-select" id="nivel" name="nivel" required>
                                    <option value="inicial" <?php echo $area['nivel'] == 'inicial' ? 'selected' : ''; ?>>Educación Inicial</option>
                                    <option value="primaria" <?php echo $area['nivel'] == 'primaria' ? 'selected' : ''; ?>>Primaria</option>
                                    <option value="media" <?php echo $area['nivel'] == 'media' ? 'selected' : ''; ?>>Media General</option>
                                </select>
                            </div>
                            
                            <div class="col-md-6 mb-3">
                                <label for="estado" class="form-label">Estado *</label>
                                <select class="form-select" id="estado" name="estado" required>
                                    <option value="activa" <?php echo $area['estado'] == 'activa' ? 'selected' : ''; ?>>Activa</option>
                                    <option value="inactiva" <?php echo $area['estado'] == 'inactiva' ? 'selected' : ''; ?>>Inactiva</option>
                                </select>
                            </div>
                        </div>
                        
                        <div class="d-grid gap-2 d-md-flex justify-content-md-end">
                            <a href="gestionar.php" class="btn btn-outline-secondary me-md-2">
                                <i class="fas fa-times me-2"></i>Cancelar
                            </a>
                            <button type="submit" class="btn btn-warning">
                                <i class="fas fa-save me-2"></i>Actualizar Área
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
        
        <div class="col-md-4">
            <div class="card">
                <div class="card-header bg-info text-white">
                    <h6 class="card-title mb-0">
                        <i class="fas fa-info-circle me-2"></i>Información del Área
                    </h6>
                </div>
                <div class="card-body">
                    <div class="small">
                        <p><strong>ID:</strong> <?php echo $area['id']; ?></p>
                        <p><strong>Indicadores activos:</strong> 
                            <span class="badge bg-primary"><?php echo $total_indicadores; ?></span>
                        </p>
                        <p><strong>Creada:</strong><br>
                            <small class="text-muted">
                                <?php echo $area['created_at'] ? date('d/m/Y H:i', strtotime($area['created_at'])) : 'No disponible'; ?>
                            </small>
                        </p>
                    </div>
                </div>
            </div>
            
            <div class="card mt-3">
                <div class="card-header bg-primary text-white">
                    <h6 class="card-title mb-0">
                        <i class="fas fa-tasks me-2"></i>Acciones Relacionadas
                    </h6>
                </div>
                <div class="card-body">
                    <div class="d-grid gap-2">
                        <a href="gestionar_indicadores.php" class="btn btn-outline-primary btn-sm">
                            <i class="fas fa-list-check me-1"></i>Gestionar Indicadores
                        </a>
                        <a href="agregar_indicador.php?area_id=<?php echo $area['id']; ?>" 
                           class="btn btn-outline-success btn-sm">
                            <i class="fas fa-plus me-1"></i>Agregar Nuevo Indicador
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
document.getElementById('form-area').addEventListener('submit', function(e) {
    const nombre = document.getElementById('nombre').value.trim();
    const codigo = document.getElementById('codigo').value.trim().toUpperCase();
    const nivel = document.getElementById('nivel').value;
    
    if (!nombre || !codigo || !nivel) {
        e.preventDefault();
        alert('Por favor complete todos los campos obligatorios (*)');
        return false;
    }
    
    if (!/^[A-Z0-9]+$/.test(codigo)) {
        e.preventDefault();
        alert('El código solo puede contener letras mayúsculas y números');
        document.getElementById('codigo').focus();
        return false;
    }
    
    // Auto-uppercase del código
    document.getElementById('codigo').value = codigo;
});
</script>

<?php include '../../includes/footer.php'; ?>
