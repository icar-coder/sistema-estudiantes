<?php
require_once '../../includes/auth.php';
require_once '../../includes/database.php';
checkAuth();
checkRole(['admin']);

$mensaje = '';
$tipo_mensaje = '';

if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    header('Location: gestionar.php');
    exit();
}

$seccion_id = intval($_GET['id']);

// Obtener datos de la sección actual
try {
    $stmt = $pdo->prepare("
        SELECT s.*, ae.nombre as año_escolar 
        FROM secciones s 
        JOIN años_escolares ae ON s.año_escolar_id = ae.id 
        WHERE s.id = ?
    ");
    $stmt->execute([$seccion_id]);
    $seccion = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$seccion) {
        header('Location: gestionar.php');
        exit();
    }
} catch (PDOException $e) {
    error_log("Error en " . __FILE__ . ": " . $e->getMessage());
    $_SESSION['error_sistema'] = "Ocurrió un error al procesar la solicitud. El equipo técnico fue notificado.";
    header('Location: ' . ($_SERVER['HTTP_REFERER'] ?? app_url()));
    exit;
}

// Obtener años escolares
try {
    $stmt = $pdo->query("SELECT id, nombre FROM años_escolares ORDER BY nombre DESC");
    $años_escolares = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $años_escolares = [];
}

// Obtener profesores activos
try {
    $stmt = $pdo->query("SELECT id, cedula, nombre, apellido, especialidad FROM profesores WHERE estado = 'activo' ORDER BY nombre, apellido");
    $profesores = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $profesores = [];
}

// Procesar actualización
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $grado = $_POST['grado'];
    $letra = $_POST['letra'];
    $año_escolar_id = $_POST['año_escolar_id'];
    $capacidad = $_POST['capacidad'] ?: null;
    $profesor_id = $_POST['profesor_id'] ?: null;
    
    $nombre = $grado . ' ' . $letra;
    
    // Validar campos obligatorios
    $campos_faltantes = [];
    if (empty($grado)) $campos_faltantes[] = "Grado";
    if (empty($letra)) $campos_faltantes[] = "Letra";
    if (empty($año_escolar_id)) $campos_faltantes[] = "Año Escolar";
    if (empty($profesor_id)) $campos_faltantes[] = "Profesor Titular";
    
    if (empty($campos_faltantes)) {
        try {
            // Verificar si ya existe otra sección con el mismo nombre en el mismo año escolar
            $stmt = $pdo->prepare("SELECT id FROM secciones WHERE nombre = ? AND año_escolar_id = ? AND id != ?");
            $stmt->execute([$nombre, $año_escolar_id, $seccion_id]);
            
            if ($stmt->fetch()) {
                $mensaje = "Ya existe otra sección con el nombre '$nombre' en este año escolar";
                $tipo_mensaje = "warning";
            } else {
                // Actualizar la sección
                $stmt = $pdo->prepare("UPDATE secciones SET nombre = ?, año_escolar_id = ?, capacidad = ?, profesor_id = ? WHERE id = ?");
                $stmt->execute([$nombre, $año_escolar_id, $capacidad, $profesor_id, $seccion_id]);
                
                $mensaje = "Sección actualizada correctamente";
                $tipo_mensaje = "success";
                
                // Actualizar datos locales
                $seccion['nombre'] = $nombre;
                $seccion['año_escolar_id'] = $año_escolar_id;
                $seccion['capacidad'] = $capacidad;
                $seccion['profesor_id'] = $profesor_id;
            }
        } catch (PDOException $e) {
            $mensaje = "Error al actualizar sección: " . $e->getMessage();
            $tipo_mensaje = "danger";
        }
    } else {
        $mensaje = "Por favor complete los campos obligatorios. Faltan: " . implode(", ", $campos_faltantes);
        $tipo_mensaje = "warning";
    }
}

// Extraer grado y letra del nombre actual
$grado_actual = '';
$letra_actual = '';
if (!empty($seccion['nombre'])) {
    $partes = explode(' ', $seccion['nombre']);
    if (count($partes) >= 2) {
        $grado_actual = $partes[0] . ' ' . $partes[1]; // "1er Grado"
        $letra_actual = end($partes); // "A"
    }
}
?>

<?php include '../../includes/header.php'; ?>

<div class="content-wrapper" style="padding: 20px;">
    <?php include '../../includes/sidebar_admin.php'; ?>
    
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2>Editar Sección</h2>
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
        <div class="card-header bg-primary text-white">
            <h5 class="card-title mb-0">
                <i class="fas fa-edit me-2"></i>Editar Sección: <?php echo htmlspecialchars($seccion['nombre']); ?>
            </h5>
        </div>
        <div class="card-body">
            <form method="POST" action="">
                <div class="row">
                    <div class="col-md-3 mb-3">
                        <label for="grado" class="form-label">Grado *</label>
                        <select class="form-select" id="grado" name="grado" required>
                            <option value="">Seleccionar grado...</option>
                            <option value="1er Grado" <?php echo ($grado_actual == '1er Grado') ? 'selected' : ''; ?>>1er Grado</option>
                            <option value="2do Grado" <?php echo ($grado_actual == '2do Grado') ? 'selected' : ''; ?>>2do Grado</option>
                            <option value="3er Grado" <?php echo ($grado_actual == '3er Grado') ? 'selected' : ''; ?>>3er Grado</option>
                            <option value="4to Grado" <?php echo ($grado_actual == '4to Grado') ? 'selected' : ''; ?>>4to Grado</option>
                            <option value="5to Grado" <?php echo ($grado_actual == '5to Grado') ? 'selected' : ''; ?>>5to Grado</option>
                            <option value="6to Grado" <?php echo ($grado_actual == '6to Grado') ? 'selected' : ''; ?>>6to Grado</option>
                        </select>
                    </div>
                    
                    <div class="col-md-2 mb-3">
                        <label for="letra" class="form-label">Letra *</label>
                        <select class="form-select" id="letra" name="letra" required>
                            <option value="">Letra...</option>
                            <?php 
                            $letras = range('A', 'Z');
                            foreach ($letras as $letra): 
                            ?>
                                <option value="<?php echo $letra; ?>" 
                                    <?php echo ($letra_actual == $letra) ? 'selected' : ''; ?>>
                                    <?php echo $letra; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="col-md-4 mb-3">
                        <label for="año_escolar_id" class="form-label">Año Escolar *</label>
                        <select class="form-select" id="año_escolar_id" name="año_escolar_id" required>
                            <option value="">Seleccionar año escolar...</option>
                            <?php foreach ($años_escolares as $año): ?>
                                <option value="<?php echo $año['id']; ?>" 
                                    <?php echo ($seccion['año_escolar_id'] == $año['id']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($año['nombre']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="col-md-3 mb-3">
                        <label for="capacidad" class="form-label">Capacidad Máxima</label>
                        <select class="form-select" id="capacidad" name="capacidad">
                            <option value="">Sin límite específico</option>
                            <?php 
                            $capacidades = [20, 25, 30, 35];
                            foreach ($capacidades as $cap): 
                            ?>
                                <option value="<?php echo $cap; ?>" 
                                    <?php echo ($seccion['capacidad'] == $cap) ? 'selected' : ''; ?>>
                                    Máximo <?php echo $cap; ?> estudiantes
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                
                <!-- Selección de Profesor -->
                <div class="card mb-4">
                    <div class="card-header bg-warning">
                        <h6 class="card-title mb-0 text-dark">
                            <i class="fas fa-chalkboard-teacher me-2"></i>Profesor Titular *
                        </h6>
                    </div>
                    <div class="card-body">
                        <div class="mb-3">
                            <label class="form-label">Seleccionar Profesor Titular *</label>
                            <div style="max-height: 200px; overflow-y: auto; border: 1px solid #dee2e6; border-radius: 0.375rem; padding: 10px;">
                                <?php if (count($profesores) > 0): ?>
                                    <?php foreach ($profesores as $profesor): ?>
                                        <div class="form-check profesor-item mb-2">
                                            <input class="form-check-input" type="radio" name="profesor_id" 
                                                   id="profesor_<?php echo $profesor['id']; ?>" 
                                                   value="<?php echo $profesor['id']; ?>"
                                                   <?php echo ($seccion['profesor_id'] == $profesor['id']) ? 'checked' : ''; ?>>
                                            <label class="form-check-label w-100" for="profesor_<?php echo $profesor['id']; ?>">
                                                <div class="d-flex justify-content-between align-items-center">
                                                    <span>
                                                        <strong><?php echo htmlspecialchars($profesor['nombre'] . ' ' . $profesor['apellido']); ?></strong>
                                                        <?php if ($profesor['cedula']): ?>
                                                            <small class="text-muted">(<?php echo htmlspecialchars($profesor['cedula']); ?>)</small>
                                                        <?php endif; ?>
                                                    </span>
                                                    <?php if ($profesor['especialidad']): ?>
                                                        <span class="badge bg-info"><?php echo htmlspecialchars($profesor['especialidad']); ?></span>
                                                    <?php endif; ?>
                                                </div>
                                            </label>
                                        </div>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <div class="text-center text-muted py-3">
                                        <i class="fas fa-users-slash fa-2x mb-2"></i>
                                        <p>No hay profesores activos disponibles</p>
                                        <a href="../profesores/agregar.php" class="btn btn-sm btn-outline-primary">
                                            <i class="fas fa-plus me-1"></i>Agregar Profesor
                                        </a>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Vista previa -->
                <div class="row">
                    <div class="col-md-12 mb-3">
                        <label class="form-label">Vista Previa de la Sección</label>
                        <div class="form-control" style="background-color: #f8f9fa; font-weight: bold;">
                            <span id="vista-previa-nombre">
                                <?php echo htmlspecialchars($seccion['nombre']); ?>
                            </span>
                        </div>
                    </div>
                </div>
                
                <div class="d-grid gap-2 d-md-flex justify-content-md-end">
                    <a href="gestionar.php" class="btn btn-outline-secondary me-md-2">
                        <i class="fas fa-times me-2"></i>Cancelar
                    </a>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save me-2"></i>Actualizar Sección
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
// Actualizar vista previa en tiempo real
function actualizarVistaPrevia() {
    const grado = document.getElementById('grado').value;
    const letra = document.getElementById('letra').value;
    const vistaPrevia = document.getElementById('vista-previa-nombre');
    
    if (grado && letra) {
        vistaPrevia.textContent = `${grado} ${letra}`;
        vistaPrevia.className = 'text-success';
    } else {
        vistaPrevia.textContent = 'Nombre de la sección aparecerá aquí...';
        vistaPrevia.className = 'text-muted';
    }
}

document.addEventListener('DOMContentLoaded', function() {
    document.getElementById('grado').addEventListener('change', actualizarVistaPrevia);
    document.getElementById('letra').addEventListener('change', actualizarVistaPrevia);
});
</script>

<?php include '../../includes/footer.php'; ?>
