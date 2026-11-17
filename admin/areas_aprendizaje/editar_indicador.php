<?php
// admin/areas_aprendizaje/editar_indicador.php
require_once '../../includes/auth.php';
require_once '../../includes/database.php';
checkAuth();
checkRole(['admin']);

$indicador_id = $_GET['id'] ?? null;
$mensaje = '';
$tipo_mensaje = '';

// Cargar datos del indicador
if ($indicador_id) {
    try {
        $stmt = $pdo->prepare("
            SELECT ai.*, aa.nombre as area_nombre, aa.codigo as area_codigo, aa.descripcion as area_descripcion
            FROM areas_indicadores ai
            JOIN areas_aprendizaje aa ON ai.area_id = aa.id
            WHERE ai.id = ?
        ");
        $stmt->execute([$indicador_id]);
        $indicador = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$indicador) {
            $_SESSION['mensaje'] = "Indicador no encontrado";
            $_SESSION['tipo_mensaje'] = "danger";
            header('Location: gestionar_indicadores.php');
            exit;
        }
    } catch (PDOException $e) {
        $_SESSION['mensaje'] = "Error al cargar el indicador: " . $e->getMessage();
        $_SESSION['tipo_mensaje'] = "danger";
        header('Location: gestionar_indicadores.php');
        exit;
    }
} else {
    $_SESSION['mensaje'] = "No se especificó el indicador";
    $_SESSION['tipo_mensaje'] = "danger";
    header('Location: gestionar_indicadores.php');
    exit;
}

// Procesar formulario
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nombre = trim($_POST['nombre']);
    $descripcion = trim($_POST['descripcion'] ?? '');
    $escala = $_POST['escala'];
    $orden = intval($_POST['orden'] ?? 0);
    
    $errores = [];
    
    if (empty($nombre)) {
        $errores[] = "El nombre del indicador es obligatorio";
    }
    
    if (strlen($nombre) > 500) {
        $errores[] = "El nombre del indicador es demasiado largo (máx. 500 caracteres)";
    }
    
    if (empty($errores)) {
        try {
            $stmt = $pdo->prepare("
                UPDATE areas_indicadores 
                SET nombre = ?, descripcion = ?, escala = ?, orden = ?
                WHERE id = ?
            ");
            $stmt->execute([$nombre, $descripcion, $escala, $orden, $indicador_id]);
            
            $_SESSION['mensaje'] = "Indicador actualizado correctamente";
            $_SESSION['tipo_mensaje'] = "success";
            header("Location: gestionar_indicadores.php");
            exit;
            
        } catch (PDOException $e) {
            $mensaje = "Error al actualizar indicador: " . $e->getMessage();
            $tipo_mensaje = "danger";
        }
    } else {
        $mensaje = implode("<br>", $errores);
        $tipo_mensaje = "warning";
    }
}
?>

<?php include '../../includes/header.php'; ?>

<div class="content-wrapper" style="padding: 20px;">
    <?php include '../../includes/sidebar_admin.php'; ?>
    
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2>
            <i class="fas fa-edit me-2"></i>Editar Indicador
        </h2>
        <div>
            <a href="gestionar_indicadores.php" class="btn btn-outline-secondary">
                <i class="fas fa-arrow-left me-2"></i>Volver a Indicadores
            </a>
        </div>
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
                        <i class="fas fa-bullseye me-2"></i>Editar Indicador - <?php echo htmlspecialchars($indicador['area_nombre']); ?>
                    </h5>
                </div>
                <div class="card-body">
                    <form method="POST" action="">
                        <div class="mb-3">
                            <label for="nombre" class="form-label">Nombre del Indicador *</label>
                            <textarea class="form-control" id="nombre" name="nombre" rows="3" 
                                      placeholder="Describa el indicador de logro..." 
                                      required maxlength="500"><?php echo htmlspecialchars($_POST['nombre'] ?? $indicador['nombre']); ?></textarea>
                            <div class="form-text">Describa claramente qué debe lograr el estudiante (máx. 500 caracteres)</div>
                        </div>
                        
                        <div class="mb-3">
                            <label for="descripcion" class="form-label">Descripción Adicional</label>
                            <textarea class="form-control" id="descripcion" name="descripcion" rows="2"
                                      placeholder="Detalles adicionales del indicador (opcional)..."><?php echo htmlspecialchars($_POST['descripcion'] ?? $indicador['descripcion']); ?></textarea>
                            <div class="form-text">Información complementaria sobre el indicador</div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="escala" class="form-label">Escala de Evaluación *</label>
                                <select class="form-select" id="escala" name="escala" required>
                                    <option value="LE" <?php echo ($_POST['escala'] ?? $indicador['escala']) == 'LE' ? 'selected' : ''; ?>>LE - Logrado con Excelencia</option>
                                    <option value="L" <?php echo ($_POST['escala'] ?? $indicador['escala']) == 'L' ? 'selected' : ''; ?>>L - Logrado</option>
                                    <option value="PA" <?php echo ($_POST['escala'] ?? $indicador['escala']) == 'PA' ? 'selected' : ''; ?>>PA - Proceso Avanzado</option>
                                    <option value="IP" <?php echo ($_POST['escala'] ?? $indicador['escala']) == 'IP' ? 'selected' : ''; ?>>IP - Iniciado en el Proceso</option>
                                    <option value="NL" <?php echo ($_POST['escala'] ?? $indicador['escala']) == 'NL' ? 'selected' : ''; ?>>NL - No Logrado</option>
                                </select>
                                <div class="form-text">Escala de evaluación para este indicador</div>
                            </div>
                            
                            <div class="col-md-6 mb-3">
                                <label for="orden" class="form-label">Orden de Visualización</label>
                                <input type="number" class="form-control" id="orden" name="orden" 
                                       value="<?php echo htmlspecialchars($_POST['orden'] ?? $indicador['orden']); ?>" 
                                       min="0" max="100">
                                <div class="form-text">Número para ordenar los indicadores (0 = primero)</div>
                            </div>
                        </div>
                        
                        <div class="d-grid gap-2 d-md-flex justify-content-md-end">
                            <a href="gestionar_indicadores.php" class="btn btn-outline-secondary me-md-2">
                                <i class="fas fa-times me-2"></i>Cancelar
                            </a>
                            <button type="submit" class="btn btn-warning">
                                <i class="fas fa-save me-2"></i>Actualizar Indicador
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
                    <h6><?php echo htmlspecialchars($indicador['area_nombre']); ?></h6>
                    <p class="small text-muted mb-2"><?php echo htmlspecialchars($indicador['area_descripcion']); ?></p>
                    <div class="d-flex justify-content-between small">
                        <span><strong>Código:</strong> <?php echo htmlspecialchars($indicador['area_codigo']); ?></span>
                    </div>
                </div>
            </div>
            
            <div class="card mt-3">
                <div class="card-header bg-success text-white">
                    <h6 class="card-title mb-0">
                        <i class="fas fa-lightbulb me-2"></i>Información del Indicador
                    </h6>
                </div>
                <div class="card-body">
                    <div class="small">
                        <p><strong>Creado:</strong><br>
                        <small class="text-muted"><?php echo date('d/m/Y H:i', strtotime($indicador['created_at'])); ?></small></p>
                        
                        <p><strong>Estado:</strong><br>
                        <span class="badge bg-<?php echo $indicador['estado'] == 'activo' ? 'success' : 'secondary'; ?>">
                            <?php echo ucfirst($indicador['estado']); ?>
                        </span></p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Contador de caracteres para el nombre
    const nombreTextarea = document.getElementById('nombre');
    const contador = document.createElement('div');
    contador.className = 'form-text text-end';
    contador.textContent = nombreTextarea.value.length + '/500 caracteres';
    nombreTextarea.parentNode.appendChild(contador);
    
    nombreTextarea.addEventListener('input', function() {
        const longitud = this.value.length;
        contador.textContent = `${longitud}/500 caracteres`;
        
        if (longitud > 500) {
            contador.className = 'form-text text-end text-danger';
        } else {
            contador.className = 'form-text text-end';
        }
    });
});
</script>

<?php include '../../includes/footer.php'; ?>
