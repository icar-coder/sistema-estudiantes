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

// Obtener datos del estudiante con informaciÃ³n del representante
try {
    $stmt = $pdo->prepare("
        SELECT e.*, r.*, r.id as representante_id
        FROM estudiantes e 
        LEFT JOIN representantes r ON e.representante_id = r.id 
        WHERE e.id = ?
    ");
    $stmt->execute([$id]);
    $estudiante = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$estudiante) {
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
    try {
        $pdo->beginTransaction();
        
        // ========== ACTUALIZAR REPRESENTANTE ==========
        $representante_id = $estudiante['representante_id'];
        
        // Validar campos obligatorios del representante
        $campos_obligatorios_rep = [
            'representante_cedula', 
            'representante_nombre', 
            'representante_apellido', 
            'representante_parentesco',
            'representante_telefono'
        ];
        
        foreach ($campos_obligatorios_rep as $campo) {
            if (empty(trim($_POST[$campo] ?? ''))) {
                throw new Exception("El campo {$campo} del representante es obligatorio");
            }
        }
        
        // Validar formato de cÃ©dula del representante
        $cedula_rep = trim($_POST['representante_cedula']);
        if (!preg_match('/^[0-9]{6,20}$/', $cedula_rep)) {
            throw new Exception("La cÃ©dula del representante debe contener solo nÃºmeros (6-20 dÃ­gitos)");
        }
        
        // Validar telÃ©fono del representante
        $telefono_rep = trim($_POST['representante_telefono']);
        if (!preg_match('/^[0-9+\-\s]{10,20}$/', $telefono_rep)) {
            throw new Exception("El telÃ©fono del representante debe tener entre 10 y 20 caracteres");
        }
        
        // Actualizar representante
        $stmt = $pdo->prepare("UPDATE representantes SET 
            cedula = ?, nombre = ?, apellido = ?, fecha_nacimiento = ?, genero = ?, 
            parentesco = ?, profesion = ?, lugar_trabajo = ?, telefono = ?, 
            telefono_alternativo = ?, email = ?, direccion = ?, updated_at = CURRENT_TIMESTAMP 
            WHERE id = ?");
        
        $stmt->execute([
            $cedula_rep,
            trim($_POST['representante_nombre']),
            trim($_POST['representante_apellido']),
            $_POST['representante_fecha_nacimiento'] ?: null,
            $_POST['representante_genero'] ?: null,
            $_POST['representante_parentesco'],
            trim($_POST['representante_profesion'] ?? ''),
            trim($_POST['representante_lugar_trabajo'] ?? ''),
            $telefono_rep,
            trim($_POST['representante_telefono_alternativo'] ?? ''),
            trim($_POST['representante_email'] ?? ''),
            trim($_POST['representante_direccion'] ?? ''),
            $representante_id
        ]);
        
        // ========== ACTUALIZAR ESTUDIANTE ==========
        // Validar datos del estudiante
        if (empty($_POST['nombre']) || empty($_POST['apellido'])) {
            throw new Exception("El nombre y apellido del estudiante son obligatorios");
        }
        
        // Validar formato de cÃ©dula escolar
        $cedula_escolar = trim($_POST['cedula_escolar']);
        if (!preg_match('/^[A-Z0-9]{3,20}$/', $cedula_escolar)) {
            throw new Exception("La cÃ©dula escolar debe contener solo letras y nÃºmeros (3-20 caracteres)");
        }
        
        // Verificar si la cÃ©dula escolar ya existe (excluyendo el estudiante actual)
        $stmt = $pdo->prepare("SELECT id FROM estudiantes WHERE cedula_escolar = ? AND id != ?");
        $stmt->execute([$cedula_escolar, $id]);
        if ($stmt->fetch()) {
            throw new Exception("La cÃ©dula escolar ya existe. Por favor use otra.");
        }
        
        // Actualizar estudiante
        $stmt = $pdo->prepare("UPDATE estudiantes SET 
            cedula_escolar = ?, nombre = ?, apellido = ?, fecha_nacimiento = ?, genero = ?, 
            lugar_nacimiento = ?, direccion = ?, telefono = ?, email = ?, escuela_anterior = ?, 
            enfermedades = ?, alergias = ?, medicamentos = ?, observaciones = ?, updated_at = CURRENT_TIMESTAMP 
            WHERE id = ?");
        
        $stmt->execute([
            $cedula_escolar,
            trim($_POST['nombre']),
            trim($_POST['apellido']),
            $_POST['fecha_nacimiento'] ?: null,
            $_POST['genero'] ?: null,
            trim($_POST['lugar_nacimiento'] ?? ''),
            trim($_POST['direccion'] ?? ''),
            trim($_POST['telefono'] ?? ''),
            trim($_POST['email'] ?? ''),
            trim($_POST['escuela_anterior'] ?? ''),
            trim($_POST['enfermedades'] ?? ''),
            trim($_POST['alergias'] ?? ''),
            trim($_POST['medicamentos'] ?? ''),
            trim($_POST['observaciones'] ?? ''),
            $id
        ]);
        
        $pdo->commit();
        
        $mensaje = "Estudiante y representante actualizados correctamente";
        $tipo_mensaje = "success";
        
        // Actualizar datos locales
        $estudiante['cedula_escolar'] = $cedula_escolar;
        $estudiante['nombre'] = trim($_POST['nombre']);
        $estudiante['apellido'] = trim($_POST['apellido']);
        $estudiante['fecha_nacimiento'] = $_POST['fecha_nacimiento'];
        $estudiante['genero'] = $_POST['genero'];
        $estudiante['lugar_nacimiento'] = trim($_POST['lugar_nacimiento'] ?? '');
        $estudiante['direccion'] = trim($_POST['direccion'] ?? '');
        $estudiante['telefono'] = trim($_POST['telefono'] ?? '');
        $estudiante['email'] = trim($_POST['email'] ?? '');
        $estudiante['escuela_anterior'] = trim($_POST['escuela_anterior'] ?? '');
        $estudiante['enfermedades'] = trim($_POST['enfermedades'] ?? '');
        $estudiante['alergias'] = trim($_POST['alergias'] ?? '');
        $estudiante['medicamentos'] = trim($_POST['medicamentos'] ?? '');
        $estudiante['observaciones'] = trim($_POST['observaciones'] ?? '');
        
        // Datos del representante
        $estudiante['cedula'] = $cedula_rep;
        $estudiante['representante_nombre'] = trim($_POST['representante_nombre']);
        $estudiante['representante_apellido'] = trim($_POST['representante_apellido']);
        $estudiante['fecha_nacimiento'] = $_POST['representante_fecha_nacimiento'];
        $estudiante['genero'] = $_POST['representante_genero'];
        $estudiante['parentesco'] = $_POST['representante_parentesco'];
        $estudiante['profesion'] = trim($_POST['representante_profesion'] ?? '');
        $estudiante['lugar_trabajo'] = trim($_POST['representante_lugar_trabajo'] ?? '');
        $estudiante['telefono'] = $telefono_rep;
        $estudiante['telefono_alternativo'] = trim($_POST['representante_telefono_alternativo'] ?? '');
        $estudiante['email'] = trim($_POST['representante_email'] ?? '');
        $estudiante['direccion'] = trim($_POST['representante_direccion'] ?? '');
        
    } catch (Exception $e) {
        $pdo->rollBack();
        $mensaje = "Error: " . $e->getMessage();
        $tipo_mensaje = "danger";
    }
}
?>

<?php include '../../includes/header.php'; ?>

<div class="content-wrapper" style="padding: 20px;">
    <?php include '../../includes/sidebar_admin.php'; ?>
    
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2>Editar Estudiante</h2>
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

    <form method="POST" action="" id="formEstudiante">
        <!-- SecciÃ³n Datos del Representante -->
        <div class="card mb-4">
            <div class="card-header bg-info text-white">
                <h5 class="card-title mb-0">
                    <i class="fas fa-user-friends me-2"></i>Datos del Representante *
                    <small class="float-end">Campos obligatorios</small>
                </h5>
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-4 mb-3">
                        <label for="representante_cedula" class="form-label">CÃ©dula del Representante *</label>
                        <input type="text" class="form-control" id="representante_cedula" name="representante_cedula"
                               value="<?php echo htmlspecialchars($estudiante['cedula'] ?? ''); ?>"
                               maxlength="20" pattern="[0-9]+" required
                               placeholder="Ej: 12345678">
                        <div class="form-text">Solo nÃºmeros, sin puntos ni espacios</div>
                    </div>
                    
                    <div class="col-md-4 mb-3">
                        <label for="representante_parentesco" class="form-label">Parentesco *</label>
                        <select class="form-select" id="representante_parentesco" name="representante_parentesco" required>
                            <option value="">Seleccionar...</option>
                            <option value="madre" <?php echo ($estudiante['parentesco'] ?? '') == 'madre' ? 'selected' : ''; ?>>Madre</option>
                            <option value="padre" <?php echo ($estudiante['parentesco'] ?? '') == 'padre' ? 'selected' : ''; ?>>Padre</option>
                            <option value="abuelo" <?php echo ($estudiante['parentesco'] ?? '') == 'abuelo' ? 'selected' : ''; ?>>Abuelo</option>
                            <option value="abuela" <?php echo ($estudiante['parentesco'] ?? '') == 'abuela' ? 'selected' : ''; ?>>Abuela</option>
                            <option value="tio" <?php echo ($estudiante['parentesco'] ?? '') == 'tio' ? 'selected' : ''; ?>>TÃ­o</option>
                            <option value="tia" <?php echo ($estudiante['parentesco'] ?? '') == 'tia' ? 'selected' : ''; ?>>TÃ­a</option>
                            <option value="hermano" <?php echo ($estudiante['parentesco'] ?? '') == 'hermano' ? 'selected' : ''; ?>>Hermano</option>
                            <option value="hermana" <?php echo ($estudiante['parentesco'] ?? '') == 'hermana' ? 'selected' : ''; ?>>Hermana</option>
                            <option value="otro" <?php echo ($estudiante['parentesco'] ?? '') == 'otro' ? 'selected' : ''; ?>>Otro</option>
                        </select>
                    </div>
                    
                    <div class="col-md-4 mb-3">
                        <label for="representante_telefono" class="form-label">TelÃ©fono Principal *</label>
                        <input type="tel" class="form-control" id="representante_telefono" name="representante_telefono"
                               value="<?php echo htmlspecialchars($estudiante['telefono'] ?? ''); ?>"
                               maxlength="20" pattern="[0-9+\-\s]+" required
                               placeholder="Ej: 0412-1234567">
                    </div>
                </div>
                
                <div class="row">
                    <div class="col-md-4 mb-3">
                        <label for="representante_nombre" class="form-label">Nombre del Representante *</label>
                        <input type="text" class="form-control" id="representante_nombre" name="representante_nombre"
                               value="<?php echo htmlspecialchars($estudiante['representante_nombre'] ?? $estudiante['nombre'] ?? ''); ?>"
                               maxlength="100" pattern="[A-Za-zÃÃ‰ÃÃ“ÃšÃ¡Ã©Ã­Ã³ÃºÃ‘ñ\s]+" required
                               placeholder="Ej: Carmen">
                    </div>
                    
                    <div class="col-md-4 mb-3">
                        <label for="representante_apellido" class="form-label">Apellido del Representante *</label>
                        <input type="text" class="form-control" id="representante_apellido" name="representante_apellido"
                               value="<?php echo htmlspecialchars($estudiante['representante_apellido'] ?? $estudiante['apellido'] ?? ''); ?>"
                               maxlength="100" pattern="[A-Za-zÃÃ‰ÃÃ“ÃšÃ¡Ã©Ã­Ã³ÃºÃ‘ñ\s]+" required
                               placeholder="Ej: RodrÃ­guez">
                    </div>
                    
                    <div class="col-md-4 mb-3">
                        <label for="representante_genero" class="form-label">GÃ©nero</label>
                        <select class="form-select" id="representante_genero" name="representante_genero">
                            <option value="">Seleccionar...</option>
                            <option value="masculino" <?php echo ($estudiante['genero'] ?? '') == 'masculino' ? 'selected' : ''; ?>>Masculino</option>
                            <option value="femenino" <?php echo ($estudiante['genero'] ?? '') == 'femenino' ? 'selected' : ''; ?>>Femenino</option>
                        </select>
                    </div>
                </div>
                
                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label for="representante_profesion" class="form-label">ProfesiÃ³n</label>
                        <input type="text" class="form-control" id="representante_profesion" name="representante_profesion"
                               value="<?php echo htmlspecialchars($estudiante['profesion'] ?? ''); ?>"
                               maxlength="100"
                               placeholder="Ej: Ingeniero">
                    </div>
                    
                    <div class="col-md-6 mb-3">
                        <label for="representante_lugar_trabajo" class="form-label">Lugar de Trabajo</label>
                        <input type="text" class="form-control" id="representante_lugar_trabajo" name="representante_lugar_trabajo"
                               value="<?php echo htmlspecialchars($estudiante['lugar_trabajo'] ?? ''); ?>"
                               maxlength="255"
                               placeholder="Ej: Empresa XYZ">
                    </div>
                </div>
                
                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label for="representante_telefono_alternativo" class="form-label">TelÃ©fono Alternativo</label>
                        <input type="tel" class="form-control" id="representante_telefono_alternativo" name="representante_telefono_alternativo"
                               value="<?php echo htmlspecialchars($estudiante['telefono_alternativo'] ?? ''); ?>"
                               maxlength="20" pattern="[0-9+\-\s]+"
                               placeholder="Ej: 0414-7654321">
                    </div>
                    
                    <div class="col-md-6 mb-3">
                        <label for="representante_email" class="form-label">Email del Representante</label>
                        <input type="email" class="form-control" id="representante_email" name="representante_email"
                               value="<?php echo htmlspecialchars($estudiante['email'] ?? ''); ?>"
                               maxlength="255"
                               placeholder="Ej: representante@ejemplo.com">
                    </div>
                </div>
                
                <div class="mb-3">
                    <label for="representante_direccion" class="form-label">DirecciÃ³n del Representante</label>
                    <textarea class="form-control" id="representante_direccion" name="representante_direccion" rows="2"
                              maxlength="500" placeholder="DirecciÃ³n completa del representante"><?php echo htmlspecialchars($estudiante['direccion'] ?? ''); ?></textarea>
                </div>
                
                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label for="representante_fecha_nacimiento" class="form-label">Fecha de Nacimiento</label>
                        <input type="date" class="form-control" id="representante_fecha_nacimiento" name="representante_fecha_nacimiento"
                               value="<?php echo htmlspecialchars($estudiante['fecha_nacimiento'] ?? ''); ?>">
                    </div>
                </div>
            </div>
        </div>

        <!-- SecciÃ³n Datos del Estudiante -->
        <div class="card mb-4">
            <div class="card-header bg-primary text-white">
                <h5 class="card-title mb-0">
                    <i class="fas fa-user-graduate me-2"></i>Datos del Estudiante
                    <small class="float-end">Editando: <?php echo htmlspecialchars($estudiante['nombre'] . ' ' . $estudiante['apellido']); ?></small>
                </h5>
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label for="cedula_escolar" class="form-label">CÃ©dula Escolar *</label>
                        <input type="text" class="form-control" id="cedula_escolar" name="cedula_escolar"
                               value="<?php echo htmlspecialchars($estudiante['cedula_escolar'] ?? ''); ?>"
                               maxlength="20" pattern="[A-Za-z0-9]+" required
                               placeholder="Identificador Ãºnico">
                        <div class="form-text">Identificador Ãºnico del estudiante</div>
                    </div>
                    
                    <div class="col-md-6 mb-3">
                        <label for="fecha_nacimiento" class="form-label">Fecha de Nacimiento</label>
                        <input type="date" class="form-control" id="fecha_nacimiento" name="fecha_nacimiento"
                               value="<?php echo htmlspecialchars($estudiante['fecha_nacimiento'] ?? ''); ?>"
                               onchange="calcularEdad()">
                        <div class="form-text" id="edad_texto">
                            <?php 
                            if ($estudiante['fecha_nacimiento']) {
                                $nacimiento = new DateTime($estudiante['fecha_nacimiento']);
                                $hoy = new DateTime();
                                $edad = $hoy->diff($nacimiento)->y;
                                echo "Edad: {$edad} años";
                            }
                            ?>
                        </div>
                    </div>
                </div>
                
                <div class="row">
                    <div class="col-md-4 mb-3">
                        <label for="nombre" class="form-label">Nombre *</label>
                        <input type="text" class="form-control" id="nombre" name="nombre" required
                               value="<?php echo htmlspecialchars($estudiante['nombre']); ?>"
                               maxlength="100" pattern="[A-Za-zÃÃ‰ÃÃ“ÃšÃ¡Ã©Ã­Ã³ÃºÃ‘ñ\s]+"
                               placeholder="Ej: MarÃ­a">
                    </div>
                    
                    <div class="col-md-4 mb-3">
                        <label for="apellido" class="form-label">Apellido *</label>
                        <input type="text" class="form-control" id="apellido" name="apellido" required
                               value="<?php echo htmlspecialchars($estudiante['apellido']); ?>"
                               maxlength="100" pattern="[A-Za-zÃÃ‰ÃÃ“ÃšÃ¡Ã©Ã­Ã³ÃºÃ‘ñ\s]+"
                               placeholder="Ej: GonzÃ¡lez">
                    </div>
                    
                    <div class="col-md-4 mb-3">
                        <label for="genero" class="form-label">GÃ©nero</label>
                        <select class="form-select" id="genero" name="genero">
                            <option value="">Seleccionar...</option>
                            <option value="masculino" <?php echo ($estudiante['genero'] ?? '') == 'masculino' ? 'selected' : ''; ?>>Masculino</option>
                            <option value="femenino" <?php echo ($estudiante['genero'] ?? '') == 'femenino' ? 'selected' : ''; ?>>Femenino</option>
                        </select>
                    </div>
                </div>
                
                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label for="lugar_nacimiento" class="form-label">Lugar de Nacimiento</label>
                        <input type="text" class="form-control" id="lugar_nacimiento" name="lugar_nacimiento"
                               value="<?php echo htmlspecialchars($estudiante['lugar_nacimiento'] ?? ''); ?>"
                               maxlength="100"
                               placeholder="Ej: Caracas, Venezuela">
                    </div>
                    
                    <div class="col-md-6 mb-3">
                        <label for="telefono" class="form-label">TelÃ©fono del Estudiante</label>
                        <input type="tel" class="form-control" id="telefono" name="telefono"
                               value="<?php echo htmlspecialchars($estudiante['telefono'] ?? ''); ?>"
                               maxlength="20" pattern="[0-9+\-\s]+"
                               placeholder="Ej: 0412-1234567">
                    </div>
                </div>
                
                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label for="email" class="form-label">Email del Estudiante</label>
                        <input type="email" class="form-control" id="email" name="email"
                               value="<?php echo htmlspecialchars($estudiante['email'] ?? ''); ?>"
                               maxlength="255"
                               placeholder="Ej: estudiante@ejemplo.com">
                    </div>
                    
                    <div class="col-md-6 mb-3">
                        <label for="escuela_anterior" class="form-label">Escuela Anterior</label>
                        <input type="text" class="form-control" id="escuela_anterior" name="escuela_anterior"
                               value="<?php echo htmlspecialchars($estudiante['escuela_anterior'] ?? ''); ?>"
                               maxlength="255"
                               placeholder="Ej: Escuela BÃ¡sica Nacional">
                    </div>
                </div>
                
                <div class="mb-3">
                    <label for="direccion" class="form-label">DirecciÃ³n del Estudiante</label>
                    <textarea class="form-control" id="direccion" name="direccion" rows="2"
                              maxlength="500" placeholder="DirecciÃ³n completa del estudiante"><?php echo htmlspecialchars($estudiante['direccion'] ?? ''); ?></textarea>
                </div>
                
                <div class="row">
                    <div class="col-md-4 mb-3">
                        <label for="enfermedades" class="form-label">Enfermedades/Condiciones</label>
                        <textarea class="form-control" id="enfermedades" name="enfermedades" rows="2"
                                  maxlength="500" placeholder="Enfermedades crÃ³nicas o condiciones especiales"><?php echo htmlspecialchars($estudiante['enfermedades'] ?? ''); ?></textarea>
                    </div>
                    
                    <div class="col-md-4 mb-3">
                        <label for="alergias" class="form-label">Alergias</label>
                        <textarea class="form-control" id="alergias" name="alergias" rows="2"
                                  maxlength="500" placeholder="Alergias conocidas"><?php echo htmlspecialchars($estudiante['alergias'] ?? ''); ?></textarea>
                    </div>
                    
                    <div class="col-md-4 mb-3">
                        <label for="medicamentos" class="form-label">Medicamentos</label>
                        <textarea class="form-control" id="medicamentos" name="medicamentos" rows="2"
                                  maxlength="500" placeholder="Medicamentos de uso regular"><?php echo htmlspecialchars($estudiante['medicamentos'] ?? ''); ?></textarea>
                    </div>
                </div>
                
                <div class="mb-3">
                    <label for="observaciones" class="form-label">Observaciones Generales</label>
                    <textarea class="form-control" id="observaciones" name="observaciones" rows="3"
                              maxlength="1000" placeholder="Observaciones adicionales"><?php echo htmlspecialchars($estudiante['observaciones'] ?? ''); ?></textarea>
                </div>
            </div>
        </div>

        <div class="d-grid gap-2 d-md-flex justify-content-md-end">
            <a href="gestionar.php" class="btn btn-outline-secondary me-md-2">
                <i class="fas fa-times me-2"></i>Cancelar
            </a>
            <button type="submit" class="btn btn-primary">
                <i class="fas fa-save me-2"></i>Actualizar Estudiante
            </button>
        </div>
    </form>
    
    <!-- InformaciÃ³n adicional -->
    <div class="card mt-4">
        <div class="card-header">
            <h6 class="card-title mb-0">InformaciÃ³n del Registro</h6>
        </div>
        <div class="card-body">
            <div class="row">
                <div class="col-md-4">
                    <strong>ID del Estudiante:</strong> <?php echo $estudiante['id']; ?>
                </div>
                <div class="col-md-4">
                    <strong>Fecha de Registro:</strong> <?php echo date('d/m/Y H:i', strtotime($estudiante['created_at'])); ?>
                </div>
                <div class="col-md-4">
                    <strong>Ãšltima ActualizaciÃ³n:</strong> 
                    <?php echo $estudiante['updated_at'] ? date('d/m/Y H:i', strtotime($estudiante['updated_at'])) : 'No actualizado'; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
function calcularEdad() {
    const fechaNacimiento = document.getElementById('fecha_nacimiento').value;
    if (fechaNacimiento) {
        const nacimiento = new Date(fechaNacimiento);
        const hoy = new Date();
        let edad = hoy.getFullYear() - nacimiento.getFullYear();
        const mes = hoy.getMonth() - nacimiento.getMonth();
        
        if (mes < 0 || (mes === 0 && hoy.getDate() < nacimiento.getDate())) {
            edad--;
        }
        
        document.getElementById('edad_texto').textContent = `Edad: ${edad} años`;
    } else {
        document.getElementById('edad_texto').textContent = '';
    }
}

// Validaciones en tiempo real
document.addEventListener('DOMContentLoaded', function() {
    // Validar solo letras en campos de nombre
    const camposTexto = document.querySelectorAll('input[pattern="[A-Za-zÃÃ‰ÃÃ“ÃšÃ¡Ã©Ã­Ã³ÃºÃ‘ñ\\s]+"]');
    camposTexto.forEach(campo => {
        campo.addEventListener('input', function() {
            this.value = this.value.replace(/[^A-Za-zÃÃ‰ÃÃ“ÃšÃ¡Ã©Ã­Ã³ÃºÃ‘ñ\s]/g, '');
        });
    });

    // Validar solo nÃºmeros en campos de cÃ©dula
    const camposCedula = document.querySelectorAll('input[pattern="[0-9]+"]');
    camposCedula.forEach(campo => {
        campo.addEventListener('input', function() {
            this.value = this.value.replace(/[^0-9]/g, '');
        });
    });

    // Validar telÃ©fonos
    const camposTelefono = document.querySelectorAll('input[pattern="[0-9+\\-\\s]+"]');
    camposTelefono.forEach(campo => {
        campo.addEventListener('input', function() {
            this.value = this.value.replace(/[^0-9+\-\s]/g, '');
        });
    });

    // Validar cÃ©dula escolar (solo letras y nÃºmeros)
    document.getElementById('cedula_escolar').addEventListener('input', function() {
        this.value = this.value.replace(/[^A-Za-z0-9]/g, '');
    });
});
</script>

<?php include '../../includes/footer.php'; ?>
