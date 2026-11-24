<?php
require __DIR__ . '/redirigir_plan_anual.php';
// admin/areas_aprendizaje/agregar.php
require_once '../../includes/auth.php';
require_once '../../includes/database.php';
checkAuth();
checkRole(['admin']);

$mensaje = '';
$tipo_mensaje = '';

// Áreas de aprendizaje predefinidas para primaria en Venezuela
$areas_predefinidas = [
    [
        'nombre' => 'Lengua y Literatura',
        'codigo' => 'LL',
        'descripcion' => 'Desarrollo de habilidades de lectura, escritura y comunicación'
    ],
    [
        'nombre' => 'Matemática',
        'codigo' => 'MAT',
        'descripcion' => 'Pensamiento lógico-matemático y resolución de problemas'
    ],
    [
        'nombre' => 'Ciencias Naturales',
        'codigo' => 'CN',
        'descripcion' => 'Conocimiento del entorno natural y científico'
    ],
    [
        'nombre' => 'Ciencias Sociales',
        'codigo' => 'CS',
        'descripcion' => 'Estudio de la sociedad, historia y geografía'
    ],
    [
        'nombre' => 'Educación Física',
        'codigo' => 'EF',
        'descripcion' => 'Desarrollo físico y deportivo'
    ],
    [
        'nombre' => 'Arte y Patrimonio',
        'codigo' => 'ART',
        'descripcion' => 'Expresión artística y cultural'
    ],
    [
        'nombre' => 'Formación Ciudadana',
        'codigo' => 'FC',
        'descripcion' => 'Valores, convivencia y ciudadanía'
    ],
    [
        'nombre' => 'Inglés',
        'codigo' => 'ING',
        'descripcion' => 'Lengua extranjera - Inglés'
    ]
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nombre = trim($_POST['nombre']);
    $codigo = trim($_POST['codigo']);
    $descripcion = trim($_POST['descripcion']);
    $nivel = $_POST['nivel'];
    $estado = $_POST['estado'];
    
    // Validaciones
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
    
    // Verificar si ya existe un área con el mismo código
    if (empty($errores)) {
        try {
            $stmt = $pdo->prepare("SELECT id FROM areas_aprendizaje WHERE codigo = ?");
            $stmt->execute([$codigo]);
            if ($stmt->fetch()) {
                $errores[] = "Ya existe un área de aprendizaje con el código '$codigo'";
            }
        } catch (PDOException $e) {
            $errores[] = "Error al verificar código: " . $e->getMessage();
        }
    }
    
    if (empty($errores)) {
        try {
            $stmt = $pdo->prepare("INSERT INTO areas_aprendizaje (nombre, codigo, descripcion, nivel, estado) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$nombre, $codigo, $descripcion, $nivel, $estado]);
            
            $mensaje = "Área de aprendizaje '$nombre' agregada correctamente";
            $tipo_mensaje = "success";
            
            // Limpiar campos excepto nivel y estado
            $_POST['nombre'] = '';
            $_POST['codigo'] = '';
            $_POST['descripcion'] = '';
            
        } catch (PDOException $e) {
            $mensaje = "Error al agregar área de aprendizaje: " . $e->getMessage();
            $tipo_mensaje = "danger";
        }
    } else {
        $mensaje = implode("<br>", $errores);
        $tipo_mensaje = "warning";
    }
}

// Procesar carga masiva de áreas predefinidas
if (isset($_GET['cargar_predefinidas'])) {
    try {
        $pdo->beginTransaction();
        $areas_agregadas = 0;
        
        foreach ($areas_predefinidas as $area) {
            // Verificar si ya existe
            $stmt = $pdo->prepare("SELECT id FROM areas_aprendizaje WHERE codigo = ?");
            $stmt->execute([$area['codigo']]);
            
            if (!$stmt->fetch()) {
                $stmt = $pdo->prepare("INSERT INTO areas_aprendizaje (nombre, codigo, descripcion, nivel, estado) VALUES (?, ?, ?, 'primaria', 'activa')");
                $stmt->execute([$area['nombre'], $area['codigo'], $area['descripcion']]);
                $areas_agregadas++;
            }
        }
        
        $pdo->commit();
        $mensaje = "Se cargaron $areas_agregadas áreas de aprendizaje predefinidas para primaria";
        $tipo_mensaje = "success";
        
    } catch (PDOException $e) {
        $pdo->rollBack();
        $mensaje = "Error al cargar áreas predefinidas: " . $e->getMessage();
        $tipo_mensaje = "danger";
    }
}
?>

<?php include '../../includes/header.php'; ?>

<div class="content-wrapper" style="padding: 20px;">
    <?php include '../../includes/sidebar_admin.php'; ?>
    
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2>Agregar Área de Aprendizaje</h2>
        <div>
            <a href="gestionar.php" class="btn btn-outline-secondary me-2">
                <i class="fas fa-list me-2"></i>Ver Todas
            </a>
            <a href="agregar.php?cargar_predefinidas=1" class="btn btn-success" 
               onclick="return confirm('¿Está seguro de cargar las áreas predefinidas para primaria?')">
                <i class="fas fa-bolt me-2"></i>Cargar Predefinidas
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
        <!-- Formulario para agregar área -->
        <div class="col-md-6">
            <div class="card">
                <div class="card-header bg-primary text-white">
                    <h5 class="card-title mb-0">
                        <i class="fas fa-plus-circle me-2"></i>Nueva Área de Aprendizaje
                    </h5>
                </div>
                <div class="card-body">
                    <form method="POST" action="" id="form-area">
                        <div class="mb-3">
                            <label for="nombre" class="form-label">Nombre del Área *</label>
                            <input type="text" class="form-control" id="nombre" name="nombre" 
                                   value="<?php echo htmlspecialchars($_POST['nombre'] ?? ''); ?>" 
                                   placeholder="Ej: Matemática, Lengua y Literatura" required>
                            <div class="form-text">Nombre completo del área de aprendizaje</div>
                        </div>
                        
                        <div class="mb-3">
                            <label for="codigo" class="form-label">Código *</label>
                            <input type="text" class="form-control" id="codigo" name="codigo" 
                                   value="<?php echo htmlspecialchars($_POST['codigo'] ?? ''); ?>" 
                                   placeholder="Ej: MAT, LL, CN" maxlength="10" required>
                            <div class="form-text">Código único abreviado (máx. 10 caracteres)</div>
                        </div>
                        
                        <div class="mb-3">
                            <label for="descripcion" class="form-label">Descripción</label>
                            <textarea class="form-control" id="descripcion" name="descripcion" 
                                      rows="3" placeholder="Breve descripción del área..."><?php echo htmlspecialchars($_POST['descripcion'] ?? ''); ?></textarea>
                            <div class="form-text">Objetivos y contenido del área (opcional)</div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="nivel" class="form-label">Nivel Educativo *</label>
                                <select class="form-select" id="nivel" name="nivel" required>
                                    <option value="">Seleccionar nivel...</option>
                                    <option value="inicial" <?php echo ($_POST['nivel'] ?? '') == 'inicial' ? 'selected' : ''; ?>>Educación Inicial</option>
                                    <option value="primaria" <?php echo ($_POST['nivel'] ?? 'primaria') == 'primaria' ? 'selected' : ''; ?>>Primaria</option>
                                    <option value="media" <?php echo ($_POST['nivel'] ?? '') == 'media' ? 'selected' : ''; ?>>Media General</option>
                                </select>
                                <div class="form-text">Nivel al que pertenece esta área</div>
                            </div>
                            
                            <div class="col-md-6 mb-3">
                                <label for="estado" class="form-label">Estado *</label>
                                <select class="form-select" id="estado" name="estado" required>
                                    <option value="activa" <?php echo ($_POST['estado'] ?? 'activa') == 'activa' ? 'selected' : ''; ?>>Activa</option>
                                    <option value="inactiva" <?php echo ($_POST['estado'] ?? '') == 'inactiva' ? 'selected' : ''; ?>>Inactiva</option>
                                </select>
                                <div class="form-text">Las áreas inactivas no estarán disponibles</div>
                            </div>
                        </div>
                        
                        <div class="d-grid gap-2 d-md-flex justify-content-md-end">
                            <button type="reset" class="btn btn-outline-secondary me-md-2">
                                <i class="fas fa-undo me-2"></i>Limpiar
                            </button>
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-save me-2"></i>Guardar Área
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
        
        <!-- Áreas predefinidas -->
        <div class="col-md-6">
            <div class="card">
                <div class="card-header bg-success text-white">
                    <h5 class="card-title mb-0">
                        <i class="fas fa-lightbulb me-2"></i>Áreas Predefinidas - Primaria
                    </h5>
                </div>
                <div class="card-body">
                    <div class="alert alert-info">
                        <small>
                            <i class="fas fa-info-circle me-1"></i>
                            <strong>Áreas recomendadas</strong> para el sistema de educación primaria en Venezuela.
                        </small>
                    </div>
                    
                    <div class="table-responsive" style="max-height: 400px; overflow-y: auto;">
                        <table class="table table-sm table-hover">
                            <thead>
                                <tr>
                                    <th>Código</th>
                                    <th>Nombre</th>
                                    <th>Descripción</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($areas_predefinidas as $area): ?>
                                <tr>
                                    <td><span class="badge bg-primary"><?php echo htmlspecialchars($area['codigo']); ?></span></td>
                                    <td><strong><?php echo htmlspecialchars($area['nombre']); ?></strong></td>
                                    <td><small class="text-muted"><?php echo htmlspecialchars($area['descripcion']); ?></small></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    
                    <div class="mt-3">
                        <a href="agregar.php?cargar_predefinidas=1" class="btn btn-success w-100" 
                           onclick="return confirm('¿Está seguro de cargar todas las áreas predefinidas para primaria?')">
                            <i class="fas fa-bolt me-2"></i>Cargar Todas las Áreas Predefinidas
                        </a>
                        <div class="form-text text-center">
                            Crea automáticamente todas las áreas recomendadas para primaria
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
// Generar código automáticamente basado en el nombre
document.getElementById('nombre').addEventListener('blur', function() {
    const nombre = this.value.trim();
    const codigoInput = document.getElementById('codigo');
    
    // Solo generar código si el campo código está vacío
    if (nombre && !codigoInput.value) {
        // Generar código a partir de las primeras letras de cada palabra
        const palabras = nombre.split(' ');
        let codigo = '';
        
        for (let palabra of palabras) {
            if (palabra.length > 0) {
                // Tomar la primera letra en mayúscula
                const letra = palabra[0].toUpperCase();
                // Si la palabra es "y", "de", "la", etc., omitir
                if (!['Y', 'DE', 'LA', 'EL', 'LOS', 'LAS', 'DEL'].includes(letra)) {
                    codigo += letra;
                }
            }
        }
        
        // Limitar a 10 caracteres máximo
        codigoInput.value = codigo.substring(0, 10);
    }
});

// Validación del formulario
document.getElementById('form-area').addEventListener('submit', function(e) {
    const nombre = document.getElementById('nombre').value.trim();
    const codigo = document.getElementById('codigo').value.trim();
    const nivel = document.getElementById('nivel').value;
    
    if (!nombre || !codigo || !nivel) {
        e.preventDefault();
        alert('Por favor complete todos los campos obligatorios (*)');
        return false;
    }
    
    // Validar que el código solo contenga letras y números
    if (!/^[A-Z0-9]+$/.test(codigo)) {
        e.preventDefault();
        alert('El código solo puede contener letras mayúsculas y números');
        document.getElementById('codigo').focus();
        return false;
    }
});
</script>

<?php include '../../includes/footer.php'; ?>
