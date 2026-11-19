<?php
require_once '../../includes/auth.php';
require_once '../../includes/database.php';
require_once '../../includes/validaciones.php';
checkAuth();
checkRole(['admin']);
requireOnlineFeature($pdo, 'La administración de usuarios');

$mensaje = '';
$tipo_mensaje = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $validador = new Validador($_POST);
        $validador->opcion('rol', 'Rol', ['admin', 'profesor', 'estudiante'], true);
        $validador->texto('username', 'Usuario', 3, 50, true);
        $validador->email('email', 'Email', false);
        $validador->texto('password', 'Contraseña', 6, 100, true);

        if ($validador->hayErrores()) {
            throw new Exception($validador->getMensajeErrores());
        }

        $rol = $_POST['rol'];
        $username = trim($_POST['username']);
        $password = trim($_POST['password']);
        $email = trim($_POST['email']);

        $pdo->beginTransaction();

        $password_hash = password_hash($password, PASSWORD_DEFAULT);
        $relacion_id = null;

        if ($rol === 'profesor') {
            $prof_cedula = trim($_POST['prof_cedula'] ?? $_POST['cedula'] ?? '');
            $prof_nombre = trim($_POST['prof_nombre'] ?? $_POST['nombre'] ?? '');
            $prof_apellido = trim($_POST['prof_apellido'] ?? $_POST['apellido'] ?? '');
            $prof_fecha_nacimiento = $_POST['prof_fecha_nacimiento'] ?? $_POST['fecha_nacimiento'] ?? null;
            $prof_lugar_nacimiento = trim($_POST['lugar_nacimiento'] ?? '');
            $prof_direccion = trim($_POST['direccion'] ?? '');
            $prof_fecha_contratacion = $_POST['fecha_contratacion'] ?? null;
            $prof_email = trim($_POST['prof_email'] ?? $_POST['email_profesor'] ?? '');
            $prof_telefono = trim($_POST['prof_telefono'] ?? $_POST['telefono'] ?? '');
            $prof_especialidad = trim($_POST['especialidad'] ?? '');
            $prof_grado_academico = trim($_POST['grado_academico'] ?? '');

            $stmt = $pdo->prepare("
                INSERT INTO profesores (cedula, nombre, apellido, fecha_nacimiento, lugar_nacimiento,
                                      direccion, fecha_contratacion, email, telefono, especialidad,
                                      grado_academico, estado)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'activo')
            ");
            $stmt->execute([
                $prof_cedula,
                $prof_nombre,
                $prof_apellido,
                $prof_fecha_nacimiento,
                $prof_lugar_nacimiento,
                $prof_direccion,
                $prof_fecha_contratacion,
                $prof_email,
                $prof_telefono,
                $prof_especialidad,
                $prof_grado_academico
            ]);
            $relacion_id = $pdo->lastInsertId();

        } elseif ($rol === 'estudiante') {
            $est_cedula_escolar = trim($_POST['est_cedula_escolar'] ?? $_POST['cedula_escolar'] ?? '');
            $est_nombre = trim($_POST['est_nombre'] ?? $_POST['nombre'] ?? '');
            $est_apellido = trim($_POST['est_apellido'] ?? $_POST['apellido'] ?? '');
            $est_fecha_nacimiento = $_POST['est_fecha_nacimiento'] ?? $_POST['fecha_nacimiento'] ?? null;

            $stmt = $pdo->prepare("
                INSERT INTO estudiantes (cedula_escolar, nombre, apellido, fecha_nacimiento)
                VALUES (?, ?, ?, ?)
            ");
            $stmt->execute([
                $est_cedula_escolar,
                $est_nombre,
                $est_apellido,
                $est_fecha_nacimiento
            ]);
            $relacion_id = $pdo->lastInsertId();
        }

        $stmt = $pdo->prepare("
            INSERT INTO usuarios (username, password, email, rol, relacion_id, estado)
            VALUES (?, ?, ?, ?, ?, 'activo')
        ");
        $stmt->execute([$username, $password_hash, $email, $rol, $relacion_id]);

        $pdo->commit();

        $mensaje = "✅ Usuario creado exitosamente: $username";
        $tipo_mensaje = "success";

    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $mensaje = "❌ " . $e->getMessage();
        $tipo_mensaje = "danger";
    }
}
?>

<?php include '../../includes/header.php'; ?>

<div class="content-wrapper" style="padding: 20px;">
    <?php include '../../includes/sidebar_admin.php'; ?>
    
            <h2 class="mb-4">Crear Nuevo Usuario</h2>

            <?php if ($mensaje): ?>
                <div class="alert alert-<?php echo $tipo_mensaje; ?> alert-dismissible fade show" role="alert">
                    <?php echo $mensaje; ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <div class="card">
                <div class="card-header">
                    <h5 class="card-title mb-0">Información del Usuario</h5>
                </div>
                <div class="card-body">
                    <form method="POST" action="" id="userForm">
                        <!-- Información Básica del Usuario -->
                        <div class="row mb-4">
                            <div class="col-md-4">
                                <label for="rol" class="form-label">Rol *</label>
                                <select class="form-select" id="rol" name="rol" required>
                                    <option value="">Seleccionar rol...</option>
                                    <option value="admin">Administrador</option>
                                    <option value="profesor">Profesor</option>
                                    <option value="estudiante">Estudiante</option>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label for="username" class="form-label">Usuario *</label>
                                <input type="text" class="form-control" id="username" name="username" maxlength="50" data-tipo="texto-corto" pattern="[A-Za-z0-9_.-]+" required>
                            </div>
                            <div class="col-md-4">
                                <label for="password" class="form-label">Contraseña *</label>
                                <input type="password" class="form-control" id="password" name="password" maxlength="100" minlength="6" data-tipo="password" required>
                            </div>
                        </div>

                        <div class="row mb-4">
                            <div class="col-md-6">
                                <label for="email" class="form-label">Email</label>
                                <input type="email" class="form-control" id="email" name="email" data-tipo="email">
                            </div>
                        </div>

                        <!-- Información de Profesor (oculto por defecto) -->
                        <div id="profesorFields" style="display: none;">
                            <hr>
                            <h5 class="mb-3">Información del Profesor</h5>
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label for="prof_cedula" class="form-label">Cédula</label>
                                    <input type="text" class="form-control" id="prof_cedula" name="prof_cedula" maxlength="10" data-tipo="cedula" pattern="[0-9]{6,10}">
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label for="prof_email" class="form-label">Email del Profesor</label>
                                    <input type="email" class="form-control" id="prof_email" name="prof_email" maxlength="255" data-tipo="email">
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label for="nombre_profesor" class="form-label">Nombre *</label>
                                    <input type="text" class="form-control" id="nombre_profesor" name="prof_nombre" maxlength="100" data-tipo="texto" pattern="[A-Za-zÁÉÍÓÚáéíóúÑñ\s'.-]+">
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label for="apellido_profesor" class="form-label">Apellido *</label>
                                    <input type="text" class="form-control" id="apellido_profesor" name="prof_apellido" maxlength="100" data-tipo="texto" pattern="[A-Za-zÁÉÍÓÚáéíóúÑñ\s'.-]+">
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label for="fecha_nacimiento_profesor" class="form-label">Fecha de Nacimiento</label>
                                    <input type="date" class="form-control" id="fecha_nacimiento_profesor" name="prof_fecha_nacimiento" data-tipo="fecha" data-max-hoy="true">
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label for="prof_telefono" class="form-label">Teléfono</label>
                                    <input type="text" class="form-control" id="prof_telefono" name="prof_telefono" maxlength="15" data-tipo="telefono" pattern="[0-9+\-\s]{10,15}">
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label for="especialidad" class="form-label">Especialidad</label>
                                    <input type="text" class="form-control" id="especialidad" name="especialidad" maxlength="100" data-tipo="texto" pattern="[A-Za-zÁÉÍÓÚáéíóúÑñ\s'.-]+">
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label for="grado_academico" class="form-label">Grado Académico</label>
                                    <input type="text" class="form-control" id="grado_academico" name="grado_academico" maxlength="100" data-tipo="texto" pattern="[A-Za-zÁÉÍÓÚáéíóúÑñ\s'.-]+">
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label for="fecha_contratacion" class="form-label">Fecha de Contratación</label>
                                    <input type="date" class="form-control" id="fecha_contratacion" name="fecha_contratacion" data-tipo="fecha" data-max-hoy="true">
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label for="lugar_nacimiento" class="form-label">Lugar de Nacimiento</label>
                                    <input type="text" class="form-control" id="lugar_nacimiento" name="lugar_nacimiento" maxlength="100" data-tipo="texto" pattern="[A-Za-zÁÉÍÓÚáéíóúÑñ\s'.,-]+">
                                </div>
                                <div class="col-12 mb-3">
                                    <label for="direccion" class="form-label">Dirección</label>
                                    <textarea class="form-control" id="direccion" name="direccion" rows="2" maxlength="500" data-tipo="textarea"></textarea>
                                </div>
                            </div>
                        </div>

                        <!-- Información de Estudiante (oculto por defecto) -->
                        <div id="estudianteFields" style="display: none;">
                            <hr>
                            <h5 class="mb-3">Información del Estudiante</h5>
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label for="est_cedula_escolar" class="form-label">Cédula Escolar</label>
                                    <input type="text" class="form-control" id="est_cedula_escolar" name="est_cedula_escolar" maxlength="20" data-tipo="cedula-escolar">
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label for="nombre_estudiante" class="form-label">Nombre *</label>
                                    <input type="text" class="form-control" id="nombre_estudiante" name="est_nombre" maxlength="100" data-tipo="texto" pattern="[A-Za-zÁÉÍÓÚáéíóúÑñ\s'.-]+">
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label for="apellido_estudiante" class="form-label">Apellido *</label>
                                    <input type="text" class="form-control" id="apellido_estudiante" name="est_apellido" maxlength="100" data-tipo="texto" pattern="[A-Za-zÁÉÍÓÚáéíóúÑñ\s'.-]+">
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label for="fecha_nacimiento_estudiante" class="form-label">Fecha de Nacimiento</label>
                                    <input type="date" class="form-control" id="fecha_nacimiento_estudiante" name="est_fecha_nacimiento" data-tipo="fecha" data-max-hoy="true">
                                </div>
                            </div>
                        </div>

                        <div class="d-grid gap-2 d-md-flex justify-content-md-end">
                            <button type="submit" class="btn btn-primary btn-lg">
                                <i class="fas fa-save me-2"></i>Crear Usuario
                            </button>
                            <a href="gestionar.php" class="btn btn-outline-secondary btn-lg">
                                <i class="fas fa-arrow-left me-2"></i>Cancelar
                            </a>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
document.getElementById('rol').addEventListener('change', function() {
    const rol = this.value;
    const profesorFields = document.querySelectorAll('#profesorFields input, #profesorFields textarea');
    const estudianteFields = document.querySelectorAll('#estudianteFields input, #estudianteFields textarea');

    document.getElementById('profesorFields').style.display = 'none';
    document.getElementById('estudianteFields').style.display = 'none';

    profesorFields.forEach(field => {
        field.disabled = rol !== 'profesor';
    });
    estudianteFields.forEach(field => {
        field.disabled = rol !== 'estudiante';
    });

    if (rol === 'profesor') {
        document.getElementById('profesorFields').style.display = 'block';
    } else if (rol === 'estudiante') {
        document.getElementById('estudianteFields').style.display = 'block';
    }

    if (rol !== 'profesor') {
        profesorFields.forEach(field => {
            field.value = '';
        });
    }
    if (rol !== 'estudiante') {
        estudianteFields.forEach(field => {
            field.value = '';
        });
    }
});

window.addEventListener('DOMContentLoaded', function () {
    const rolSelect = document.getElementById('rol');
    if (rolSelect && rolSelect.value) {
        rolSelect.dispatchEvent(new Event('change'));
    }
});

// Generar username automáticamente basado en nombre y apellido
document.addEventListener('DOMContentLoaded', function() {
    // Para profesor
    document.getElementById('nombre_profesor').addEventListener('blur', generarUsername);
    document.getElementById('apellido_profesor').addEventListener('blur', generarUsername);
    
    // Para estudiante
    document.getElementById('nombre_estudiante').addEventListener('blur', generarUsername);
    document.getElementById('apellido_estudiante').addEventListener('blur', generarUsername);
});

function generarUsername() {
    const rol = document.getElementById('rol').value;
    let nombre = '';
    let apellido = '';
    
    if (rol === 'profesor') {
        nombre = document.getElementById('nombre_profesor').value;
        apellido = document.getElementById('apellido_profesor').value;
    } else if (rol === 'estudiante') {
        nombre = document.getElementById('nombre_estudiante').value;
        apellido = document.getElementById('apellido_estudiante').value;
    }
    
    if (nombre && apellido) {
        // Crear username: primera letra del nombre + apellido (en minúsculas, sin espacios)
        const username = (nombre.charAt(0) + apellido).toLowerCase().replace(/\s+/g, '');
        document.getElementById('username').value = username;
        
        // También llenar email si está vacío
        if (!document.getElementById('email').value) {
            document.getElementById('email').value = username + '@escuela.edu';
        }
    }
}
</script>

<style>
.sidebar {
   
    min-height: 100vh;
}
.nav-link {
    color: rgba(255,255,255,0.8) !important;
    padding: 12px 15px;
    border-radius: 5px;
    margin-bottom: 5px;
    transition: all 0.3s;
}
.nav-link:hover, .nav-link.active {
    background: rgba(255,255,255,0.1);
    color: white !important;
}
.card {
    border: none;
    box-shadow: 0 2px 10px rgba(0,0,0,0.1);
}
</style>

<script src="../../js/validaciones-formularios.js"></script>
<?php include '../../includes/footer.php'; ?>