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

// Obtener datos de la materia
try {
    $stmt = $pdo->prepare("SELECT * FROM materias WHERE id = ?");
    $stmt->execute([$id]);
    $materia = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$materia) {
        header('Location: gestionar.php');
        exit();
    }
} catch (PDOException $e) {
    error_log("Error en " . __FILE__ . ": " . $e->getMessage());
    $_SESSION['error_sistema'] = "OcurriÃ³ un error al procesar la solicitud. El equipo tÃ©cnico fue notificado.";
    header('Location: ' . ($_SERVER['HTTP_REFERER'] ?? '/SistemaEstudiantes/'));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nombre = trim($_POST['nombre']);
    $codigo = trim($_POST['codigo']);
    $descripcion = trim($_POST['descripcion']);
    $nivel = $_POST['nivel'];
    $estado = $_POST['estado'];
    
    if (!empty($nombre)) {
        try {
            $stmt = $pdo->prepare("UPDATE materias SET 
                nombre = ?, codigo = ?, descripcion = ?, nivel = ?, estado = ?
                WHERE id = ?");
            
            $stmt->execute([
                $nombre, $codigo, $descripcion, $nivel, $estado, $id
            ]);
            
            $mensaje = "Materia actualizada correctamente";
            $tipo_mensaje = "success";
            
            // Actualizar datos locales
            $materia = array_merge($materia, [
                'nombre' => $nombre,
                'codigo' => $codigo,
                'descripcion' => $descripcion,
                'nivel' => $nivel,
                'estado' => $estado
            ]);
            
        } catch (PDOException $e) {
            if ($e->getCode() == 23000) {
                $mensaje = "Error: El cÃ³digo de materia ya existe";
            } else {
                $mensaje = "Error al actualizar materia: " . $e->getMessage();
            }
            $tipo_mensaje = "danger";
        }
    } else {
        $mensaje = "Por favor complete los campos obligatorios";
        $tipo_mensaje = "warning";
    }
}
?>

<?php include '../../includes/header.php'; ?>

<div class="content-wrapper" style="padding: 20px;">
    <?php include '../../includes/sidebar_admin.php'; ?>
    
        <div class="p-4">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h2>Editar Materia</h2>
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

            <div class="card">
                <div class="card-header">
                    <h5 class="card-title mb-0">Editando: <?php echo htmlspecialchars($materia['nombre']); ?></h5>
                </div>
                <div class="card-body">
                    <form method="POST" action="">
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="nombre" class="form-label">Nombre de la Materia *</label>
                                <input type="text" class="form-control" id="nombre" name="nombre" required
                                       value="<?php echo htmlspecialchars($materia['nombre']); ?>"
                                       placeholder="Ej: MatemÃ¡ticas, Lengua y Literatura">
                            </div>
                            
                            <div class="col-md-6 mb-3">
                                <label for="codigo" class="form-label">CÃ³digo (Opcional)</label>
                                <input type="text" class="form-control" id="codigo" name="codigo"
                                       value="<?php echo htmlspecialchars($materia['codigo'] ?? ''); ?>"
                                       placeholder="Ej: MAT, LL, CN">
                                <div class="form-text">CÃ³digo corto para identificar la materia</div>
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="nivel" class="form-label">Nivel Educativo *</label>
                                <select class="form-select" id="nivel" name="nivel" required>
                                    <option value="primaria" <?php echo $materia['nivel'] == 'primaria' ? 'selected' : ''; ?>>Primaria</option>
                                    <option value="inicial" <?php echo $materia['nivel'] == 'inicial' ? 'selected' : ''; ?>>Inicial</option>
                                    <option value="media" <?php echo $materia['nivel'] == 'media' ? 'selected' : ''; ?>>Media (Secundaria)</option>
                                </select>
                            </div>
                            
                            <div class="col-md-6 mb-3">
                                <label for="estado" class="form-label">Estado *</label>
                                <select class="form-select" id="estado" name="estado" required>
                                    <option value="activa" <?php echo $materia['estado'] == 'activa' ? 'selected' : ''; ?>>Activa</option>
                                    <option value="inactiva" <?php echo $materia['estado'] == 'inactiva' ? 'selected' : ''; ?>>Inactiva</option>
                                </select>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label for="descripcion" class="form-label">DescripciÃ³n (Opcional)</label>
                            <textarea class="form-control" id="descripcion" name="descripcion" rows="3"
                                      placeholder="DescripciÃ³n detallada de la materia"><?php echo htmlspecialchars($materia['descripcion'] ?? ''); ?></textarea>
                        </div>
                        
                        <div class="d-grid gap-2 d-md-flex justify-content-md-end">
                            <a href="gestionar.php" class="btn btn-outline-secondary me-md-2">
                                <i class="fas fa-times me-2"></i>Cancelar
                            </a>
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-save me-2"></i>Actualizar Materia
                            </button>
                        </div>
                    </form>
                </div>
            </div>
            
            <!-- InformaciÃ³n adicional -->
            <div class="card mt-4">
                <div class="card-header">
                    <h6 class="card-title mb-0">InformaciÃ³n del Registro</h6>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-6">
                            <strong>ID de la Materia:</strong> <?php echo $materia['id']; ?>
                        </div>
                        <div class="col-md-6">
                            <strong>Fecha de Registro:</strong> 
                            <?php echo $materia['created_at'] ? date('d/m/Y H:i', strtotime($materia['created_at'])) : 'No disponible'; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include '../../includes/footer.php'; ?>
