
<?php
require_once '../../includes/auth.php';
require_once '../../includes/database.php';
require_once '../../includes/validaciones.php';
checkAuth();
checkRole(['admin']);

$mensaje = '';
$tipo_mensaje = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $validador = new Validador($_POST);
    $validador->cedula('cedula', 'CÃ©dula', 6, 20, false);
    $validador->texto('nombre', 'Nombre', 2, 100, true);
    $validador->texto('apellido', 'Apellido', 2, 100, true);
    $validador->fecha('fecha_nacimiento', 'Fecha de nacimiento', false, true);
    $validador->texto('lugar_nacimiento', 'Lugar de nacimiento', 1, 100, false);
    $validador->email('email', 'Email', false);
    $validador->telefono('telefono', 'TelÃ©fono', false);
    $validador->texto('especialidad', 'Especialidad', 1, 100, false);
    $validador->texto('grado_academico', 'Grado acadÃ©mico', 1, 100, false);
    $validador->opcion('estado', 'Estado', ['activo', 'inactivo', 'licencia'], true);

    $cedula = trim($_POST['cedula'] ?? '');
    $nombre = trim($_POST['nombre'] ?? '');
    $apellido = trim($_POST['apellido'] ?? '');
    $fecha_nacimiento = $_POST['fecha_nacimiento'] ?? null;
    $lugar_nacimiento = trim($_POST['lugar_nacimiento'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $telefono = trim($_POST['telefono'] ?? '');
    $direccion = trim($_POST['direccion'] ?? '');
    $fecha_contratacion = $_POST['fecha_contratacion'] ?? null;
    $especialidad = trim($_POST['especialidad'] ?? '');
    $grado_academico = trim($_POST['grado_academico'] ?? '');
    $estado = $_POST['estado'] ?? 'activo';
    
    if ($validador->hayErrores()) {
        $mensaje = $validador->getMensajeErrores();
        $tipo_mensaje = 'warning';
    } elseif (!empty($nombre) && !empty($apellido)) {
        try {
            // CORRECTO - No incluir el campo 'id'
		$stmt = $pdo->prepare("INSERT INTO profesores (cedula, nombre, apellido, fecha_nacimiento, lugar_nacimiento, direccion, fecha_contratacion, email, telefono, especialidad, grado_academico, estado) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

		$stmt->execute([
			$cedula,
			$nombre, 
			$apellido,
			$fecha_nacimiento,
			$lugar_nacimiento,
			$direccion,
			$fecha_contratacion,
			$email,
			$telefono,
			$especialidad,
			$grado_academico,
			$estado
		]);
            
            $mensaje = "Profesor agregado correctamente";
            $tipo_mensaje = "success";
            
            // Limpiar campos
            $_POST = [];
        } catch (PDOException $e) {
            if ($e->getCode() == 23000) {
                $mensaje = "Error: La cÃ©dula ya existe en el sistema";
            } else {
                $mensaje = "Error al agregar profesor: " . $e->getMessage();
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
    
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h2>Agregar Nuevo Profesor</h2>
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
                    <h5 class="card-title mb-0">Datos del Profesor</h5>
                </div>
                <div class="card-body">
                    <form method="POST" action="">
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="cedula" class="form-label">CÃ©dula de Identidad</label>
                                <input type="text" class="form-control" id="cedula" name="cedula" data-tipo="cedula"
                                       value="<?php echo htmlspecialchars($_POST['cedula'] ?? ''); ?>"
                                       maxlength="10" pattern="[0-9]{6,10}"
                                       placeholder="Ej: V-12345678">
                                <div class="form-text">NÃºmero de cÃ©dula del profesor</div>
                            </div>
                            
                            <div class="col-md-6 mb-3">
                                <label for="estado" class="form-label">Estado</label>
                                <select class="form-select" id="estado" name="estado" required>
                                    <option value="activo" <?php echo ($_POST['estado'] ?? 'activo') == 'activo' ? 'selected' : ''; ?>>Activo</option>
                                    <option value="inactivo" <?php echo ($_POST['estado'] ?? '') == 'inactivo' ? 'selected' : ''; ?>>Inactivo</option>
                                    <option value="licencia" <?php echo ($_POST['estado'] ?? '') == 'licencia' ? 'selected' : ''; ?>>En Licencia</option>
                                </select>
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="nombre" class="form-label">Nombre *</label>
                                <input type="text" class="form-control" id="nombre" name="nombre" data-tipo="texto" required
                                       value="<?php echo htmlspecialchars($_POST['nombre'] ?? ''); ?>"
                                       placeholder="Ej: MarÃ­a">
                            </div>
                            
                            <div class="col-md-6 mb-3">
                                <label for="apellido" class="form-label">Apellido *</label>
                                <input type="text" class="form-control" id="apellido" name="apellido" data-tipo="texto" required
                                       value="<?php echo htmlspecialchars($_POST['apellido'] ?? ''); ?>"
                                       placeholder="Ej: GonzÃ¡lez">
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="fecha_nacimiento" class="form-label">Fecha de Nacimiento</label>
                                <input type="date" class="form-control" id="fecha_nacimiento" name="fecha_nacimiento" data-tipo="fecha" data-max-hoy="true"
                                       value="<?php echo htmlspecialchars($_POST['fecha_nacimiento'] ?? ''); ?>">
                            </div>
                            
                            <div class="col-md-6 mb-3">
                                <label for="lugar_nacimiento" class="form-label">Lugar de Nacimiento</label>
                                <input type="text" class="form-control" id="lugar_nacimiento" name="lugar_nacimiento" data-tipo="texto"
                                       value="<?php echo htmlspecialchars($_POST['lugar_nacimiento'] ?? ''); ?>"
                                       placeholder="Ej: Municipio Infante, Estado GuÃ¡rico">
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="email" class="form-label">Correo ElectrÃ³nico</label>
                                <input type="email" class="form-control" id="email" name="email" data-tipo="email"
                                       value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>"
                                       placeholder="Ej: profesor@colegio.edu">
                            </div>
                            
                            <div class="col-md-6 mb-3">
                                <label for="telefono" class="form-label">TelÃ©fono</label>
                                <input type="text" class="form-control" id="telefono" name="telefono" data-tipo="telefono"
                                       value="<?php echo htmlspecialchars($_POST['telefono'] ?? ''); ?>"
                                       maxlength="15" pattern="[0-9+\-\s]{10,15}"
                                       placeholder="Ej: 0412-1234567">
                            </div>
                        </div>

                        <div class="mb-3">
                            <label for="direccion" class="form-label">DirecciÃ³n</label>
                            <textarea class="form-control" id="direccion" name="direccion" rows="3" data-tipo="textarea"
                                      placeholder="DirecciÃ³n completa del profesor"><?php echo htmlspecialchars($_POST['direccion'] ?? ''); ?></textarea>
                        </div>

                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="fecha_contratacion" class="form-label">Fecha de ContrataciÃ³n</label>
                                <input type="date" class="form-control" id="fecha_contratacion" name="fecha_contratacion" data-tipo="fecha" data-max-hoy="true"
                                       value="<?php echo htmlspecialchars($_POST['fecha_contratacion'] ?? ''); ?>">
                            </div>
                            
                            <div class="col-md-6 mb-3">
                                <label for="especialidad" class="form-label">Especialidad</label>
                                <input type="text" class="form-control" id="especialidad" name="especialidad" data-tipo="texto"
                                       value="<?php echo htmlspecialchars($_POST['especialidad'] ?? ''); ?>"
                                       placeholder="Ej: MatemÃ¡ticas, Lengua, Ciencias">
                            </div>
                        </div>

                        <div class="mb-3">
                            <label for="grado_academico" class="form-label">Grado AcadÃ©mico</label>
                            <input type="text" class="form-control" id="grado_academico" name="grado_academico" data-tipo="texto"
                                   value="<?php echo htmlspecialchars($_POST['grado_academico'] ?? ''); ?>"
                                   placeholder="Ej: Licenciado en EducaciÃ³n, Profesor">
                        </div>
                        
                        <div class="d-grid gap-2 d-md-flex justify-content-md-end">
                            <button type="reset" class="btn btn-outline-secondary me-md-2">
                                <i class="fas fa-undo me-2"></i>Limpiar
                            </button>
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-save me-2"></i>Guardar Profesor
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="../../js/validaciones-formularios.js"></script>
<?php include '../../includes/footer.php'; ?>
