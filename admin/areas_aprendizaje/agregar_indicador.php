<?php
// admin/areas_aprendizaje/agregar_indicador.php
require_once '../../includes/auth.php';
require_once '../../includes/database.php';
checkAuth();
checkRole(['admin']);

$area_id = $_GET['area_id'] ?? null;
$mensaje = '';
$tipo_mensaje = '';

// Validar que el área existe
if ($area_id) {
    try {
        $stmt = $pdo->prepare("SELECT * FROM areas_aprendizaje WHERE id = ? AND estado = 'activa'");
        $stmt->execute([$area_id]);
        $area = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$area) {
            $_SESSION['mensaje'] = "Área de aprendizaje no encontrada o inactiva";
            $_SESSION['tipo_mensaje'] = "danger";
            header('Location: gestionar_indicadores.php');
            exit;
        }
    } catch (PDOException $e) {
        $_SESSION['mensaje'] = "Error al cargar el área: " . $e->getMessage();
        $_SESSION['tipo_mensaje'] = "danger";
        header('Location: gestionar_indicadores.php');
        exit;
    }
} else {
    $_SESSION['mensaje'] = "No se especificó el área";
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
                INSERT INTO areas_indicadores (area_id, nombre, descripcion, escala, orden) 
                VALUES (?, ?, ?, ?, ?)
            ");
            $stmt->execute([$area_id, $nombre, $descripcion, $escala, $orden]);
            
            $_SESSION['mensaje'] = "Indicador agregado correctamente";
            $_SESSION['tipo_mensaje'] = "success";
            header("Location: gestionar_indicadores.php");
            exit;
            
        } catch (PDOException $e) {
            $mensaje = "Error al agregar indicador: " . $e->getMessage();
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
            <i class="fas fa-plus-circle me-2"></i>Agregar Indicador
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
                <div class="card-header bg-primary text-white">
                    <h5 class="card-title mb-0">
                        <i class="fas fa-bullseye me-2"></i>Nuevo Indicador para <?php echo htmlspecialchars($area['nombre']); ?>
                    </h5>
                </div>
                <div class="card-body">
                    <form method="POST" action="">
                        <div class="mb-3">
                            <label for="nombre" class="form-label">Nombre del Indicador *</label>
                            <textarea class="form-control" id="nombre" name="nombre" rows="3" 
                                      placeholder="Describa el indicador de logro..." 
                                      required maxlength="500"><?php echo htmlspecialchars($_POST['nombre'] ?? ''); ?></textarea>
                            <div class="form-text">Describa claramente qué debe lograr el estudiante (máx. 500 caracteres)</div>
                        </div>
                        
                        <div class="mb-3">
                            <label for="descripcion" class="form-label">Descripción Adicional</label>
                            <textarea class="form-control" id="descripcion" name="descripcion" rows="2"
                                      placeholder="Detalles adicionales del indicador (opcional)..."><?php echo htmlspecialchars($_POST['descripcion'] ?? ''); ?></textarea>
                            <div class="form-text">Información complementaria sobre el indicador</div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="escala" class="form-label">Escala de Evaluación *</label>
                                <select class="form-select" id="escala" name="escala" required>
                                    <option value="LE" <?php echo ($_POST['escala'] ?? 'L') == 'LE' ? 'selected' : ''; ?>>LE - Logrado con Excelencia</option>
                                    <option value="L" <?php echo ($_POST['escala'] ?? 'L') == 'L' ? 'selected' : ''; ?>>L - Logrado</option>
                                    <option value="PA" <?php echo ($_POST['escala'] ?? 'L') == 'PA' ? 'selected' : ''; ?>>PA - Proceso Avanzado</option>
                                    <option value="IP" <?php echo ($_POST['escala'] ?? 'L') == 'IP' ? 'selected' : ''; ?>>IP - Iniciado en el Proceso</option>
                                    <option value="NL" <?php echo ($_POST['escala'] ?? 'L') == 'NL' ? 'selected' : ''; ?>>NL - No Logrado</option>
                                </select>
                                <div class="form-text">Escala de evaluación para este indicador</div>
                            </div>
                            
                            <div class="col-md-6 mb-3">
                                <label for="orden" class="form-label">Orden de Visualización</label>
                                <input type="number" class="form-control" id="orden" name="orden" 
                                       value="<?php echo htmlspecialchars($_POST['orden'] ?? '0'); ?>" 
                                       min="0" max="100">
                                <div class="form-text">Número para ordenar los indicadores (0 = primero)</div>
                            </div>
                        </div>
                        
                        <div class="d-grid gap-2 d-md-flex justify-content-md-end">
                            <a href="gestionar_indicadores.php" class="btn btn-outline-secondary me-md-2">
                                <i class="fas fa-times me-2"></i>Cancelar
                            </a>
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-save me-2"></i>Guardar Indicador
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
                    <h6><?php echo htmlspecialchars($area['nombre']); ?></h6>
                    <p class="small text-muted mb-2"><?php echo htmlspecialchars($area['descripcion']); ?></p>
                    <div class="d-flex justify-content-between small">
                        <span><strong>Código:</strong> <?php echo htmlspecialchars($area['codigo']); ?></span>
                        <span><strong>Nivel:</strong> <?php echo ucfirst($area['nivel']); ?></span>
                    </div>
                </div>
            </div>
            
            <div class="card mt-3">
                <div class="card-header bg-success text-white">
                    <h6 class="card-title mb-0">
                        <i class="fas fa-lightbulb me-2"></i>Escalas de Evaluación
                    </h6>
                </div>
                <div class="card-body">
                    <div class="small">
                        <p><strong>LE</strong> - Logrado con Excelencia<br>
                        <small class="text-muted">Supera ampliamente lo esperado</small></p>
                        
                        <p><strong>L</strong> - Logrado<br>
                        <small class="text-muted">Cumple con lo esperado</small></p>
                        
                        <p><strong>PA</strong> - Proceso Avanzado<br>
                        <small class="text-muted">Está cerca de lograrlo</small></p>
                        
                        <p><strong>IP</strong> - Iniciado en el Proceso<br>
                        <small class="text-muted">Está comenzando a desarrollarlo</small></p>
                        
                        <p><strong>NL</strong> - No Logrado<br>
                        <small class="text-muted">No ha alcanzado el indicador</small></p>
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
    contador.textContent = '0/500 caracteres';
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
    
    // Disparar evento inicial
    nombreTextarea.dispatchEvent(new Event('input'));
});
</script>

<?php include '../../includes/footer.php'; ?>
