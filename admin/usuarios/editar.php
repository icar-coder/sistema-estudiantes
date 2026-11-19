<?php
require_once '../../includes/auth.php';
require_once '../../includes/database.php';
require_once '../../includes/validaciones.php';
checkAuth();
checkRole(['admin']);
requireOnlineFeature($pdo, 'La administración de usuarios');

if (!isset($_GET['id'])) {
    header('Location: gestionar.php');
    exit();
}

$usuario_id = (int) $_GET['id'];
$mensaje = '';
$tipo_mensaje = '';
$usuario = null;
$perfil = null;

try {
    $stmt = $pdo->prepare("SELECT * FROM usuarios WHERE id = ?");
    $stmt->execute([$usuario_id]);
    $usuario = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$usuario) {
        header('Location: gestionar.php');
        exit();
    }

    if ($usuario['rol'] === 'profesor' && $usuario['relacion_id']) {
        $stmt = $pdo->prepare("SELECT * FROM profesores WHERE id = ?");
        $stmt->execute([$usuario['relacion_id']]);
        $perfil = $stmt->fetch(PDO::FETCH_ASSOC);
    } elseif ($usuario['rol'] === 'estudiante' && $usuario['relacion_id']) {
        $stmt = $pdo->prepare("SELECT * FROM estudiantes WHERE id = ?");
        $stmt->execute([$usuario['relacion_id']]);
        $perfil = $stmt->fetch(PDO::FETCH_ASSOC);
    }
} catch (PDOException $e) {
    error_log('Error al cargar usuario: ' . $e->getMessage());
    $_SESSION['usuario_mensaje'] = 'Ocurrió un error al cargar el usuario.';
    $_SESSION['usuario_tipo_mensaje'] = 'danger';
    header('Location: gestionar.php');
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $validador = new Validador($_POST);
        $validador->texto('username', 'Usuario', 3, 50, true);
        $validador->email('email', 'Email', false);
        $validador->opcion('rol', 'Rol', ['admin', 'profesor', 'estudiante'], true);
        $validador->opcion('estado', 'Estado', ['activo', 'inactivo'], true);

        if (!empty($_POST['password'] ?? '')) {
            $validador->texto('password', 'Contraseña', 6, 100, false);
        }

        $rolActual = $_POST['rol'] ?? $usuario['rol'];
        if ($rolActual === 'profesor') {
            $validador->texto('prof_nombre', 'Nombre del profesor', 2, 100, false);
            $validador->texto('prof_apellido', 'Apellido del profesor', 2, 100, false);
            $validador->cedula('prof_cedula', 'Cédula del profesor', 6, 20, false);
            $validador->telefono('prof_telefono', 'Teléfono', false);
        }

        if ($rolActual === 'estudiante') {
            $validador->texto('est_nombre', 'Nombre del estudiante', 2, 100, false);
            $validador->texto('est_apellido', 'Apellido del estudiante', 2, 100, false);
            $validador->cedulaEscolar('est_cedula_escolar', 'Cédula escolar', false);
        }

        if ($validador->hayErrores()) {
            throw new Exception($validador->getMensajeErrores());
        }

        $username = trim($_POST['username'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $rol = $_POST['rol'] ?? $usuario['rol'];
        $estado = $_POST['estado'] ?? 'activo';
        $password = trim($_POST['password'] ?? '');

        $pdo->beginTransaction();

        if ($username === '') {
            throw new Exception('El nombre de usuario es obligatorio.');
        }

        $stmt = $pdo->prepare("SELECT id FROM usuarios WHERE username = ? AND id != ?");
        $stmt->execute([$username, $usuario_id]);
        if ($stmt->fetch()) {
            throw new Exception("El usuario '$username' ya existe.");
        }

        $relacion_id = null;

        if ($rol === 'profesor') {
            $prof_cedula = trim($_POST['prof_cedula'] ?? '');
            $prof_nombre = trim($_POST['prof_nombre'] ?? '');
            $prof_apellido = trim($_POST['prof_apellido'] ?? '');

            if ($prof_nombre === '' || $prof_apellido === '') {
                throw new Exception('El nombre y apellido del profesor son obligatorios.');
            }

            if (!empty($usuario['relacion_id'])) {
                $stmt = $pdo->prepare("UPDATE profesores SET cedula = ?, nombre = ?, apellido = ?, fecha_nacimiento = ?, lugar_nacimiento = ?, direccion = ?, fecha_contratacion = ?, email = ?, telefono = ?, especialidad = ?, grado_academico = ? WHERE id = ?");
                $stmt->execute([
                    $prof_cedula,
                    $prof_nombre,
                    $prof_apellido,
                    $_POST['prof_fecha_nacimiento'] ?? null,
                    trim($_POST['lugar_nacimiento'] ?? ''),
                    trim($_POST['direccion'] ?? ''),
                    $_POST['fecha_contratacion'] ?? null,
                    trim($_POST['prof_email'] ?? ''),
                    trim($_POST['prof_telefono'] ?? ''),
                    trim($_POST['especialidad'] ?? ''),
                    trim($_POST['grado_academico'] ?? ''),
                    $usuario['relacion_id']
                ]);
                $relacion_id = $usuario['relacion_id'];
            } else {
                $stmt = $pdo->prepare("INSERT INTO profesores (cedula, nombre, apellido, fecha_nacimiento, lugar_nacimiento, direccion, fecha_contratacion, email, telefono, especialidad, grado_academico, estado) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'activo')");
                $stmt->execute([
                    $prof_cedula,
                    $prof_nombre,
                    $prof_apellido,
                    $_POST['prof_fecha_nacimiento'] ?? null,
                    trim($_POST['lugar_nacimiento'] ?? ''),
                    trim($_POST['direccion'] ?? ''),
                    $_POST['fecha_contratacion'] ?? null,
                    trim($_POST['prof_email'] ?? ''),
                    trim($_POST['prof_telefono'] ?? ''),
                    trim($_POST['especialidad'] ?? ''),
                    trim($_POST['grado_academico'] ?? '')
                ]);
                $relacion_id = $pdo->lastInsertId();
            }
        } elseif ($rol === 'estudiante') {
            $est_nombre = trim($_POST['est_nombre'] ?? '');
            $est_apellido = trim($_POST['est_apellido'] ?? '');

            if ($est_nombre === '' || $est_apellido === '') {
                throw new Exception('El nombre y apellido del estudiante son obligatorios.');
            }

            if (!empty($usuario['relacion_id'])) {
                $stmt = $pdo->prepare("UPDATE estudiantes SET cedula_escolar = ?, nombre = ?, apellido = ?, fecha_nacimiento = ?, lugar_nacimiento = ?, direccion = ?, telefono = ?, email = ? WHERE id = ?");
                $stmt->execute([
                    trim($_POST['est_cedula_escolar'] ?? ''),
                    $est_nombre,
                    $est_apellido,
                    $_POST['est_fecha_nacimiento'] ?? null,
                    trim($_POST['lugar_nacimiento'] ?? ''),
                    trim($_POST['direccion'] ?? ''),
                    trim($_POST['est_telefono'] ?? ''),
                    trim($_POST['est_email'] ?? ''),
                    $usuario['relacion_id']
                ]);
                $relacion_id = $usuario['relacion_id'];
            } else {
                $stmt = $pdo->prepare("INSERT INTO estudiantes (cedula_escolar, nombre, apellido, fecha_nacimiento, lugar_nacimiento, direccion, telefono, email, representante_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0)");
                $stmt->execute([
                    trim($_POST['est_cedula_escolar'] ?? ''),
                    $est_nombre,
                    $est_apellido,
                    $_POST['est_fecha_nacimiento'] ?? null,
                    trim($_POST['lugar_nacimiento'] ?? ''),
                    trim($_POST['direccion'] ?? ''),
                    trim($_POST['est_telefono'] ?? ''),
                    trim($_POST['est_email'] ?? '')
                ]);
                $relacion_id = $pdo->lastInsertId();
            }
        } else {
            $relacion_id = null;
        }

        $query = "UPDATE usuarios SET username = ?, email = ?, rol = ?, relacion_id = ?, estado = ?";
        $params = [$username, $email, $rol, $relacion_id, $estado];

        if ($password !== '') {
            $query .= ", password = ?";
            $params[] = password_hash($password, PASSWORD_DEFAULT);
        }

        $query .= " WHERE id = ?";
        $params[] = $usuario_id;

        $stmt = $pdo->prepare($query);
        $stmt->execute($params);

        $pdo->commit();

        $usuario['username'] = $username;
        $usuario['email'] = $email;
        $usuario['rol'] = $rol;
        $usuario['relacion_id'] = $relacion_id;
        $usuario['estado'] = $estado;

        $mensaje = 'Usuario actualizado correctamente.';
        $tipo_mensaje = 'success';
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $mensaje = 'Error: ' . $e->getMessage();
        $tipo_mensaje = 'danger';
    }
}

include '../../includes/header.php';
?>

<div class="content-wrapper" style="padding: 20px;">
    <?php include '../../includes/sidebar_admin.php'; ?>

    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2>Editar Usuario</h2>
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
            <h5 class="card-title mb-0">Editando: <?php echo htmlspecialchars($usuario['username'] ?? 'Usuario'); ?></h5>
        </div>
        <div class="card-body">
            <form method="POST" action="">
                <div class="row mb-4">
                    <div class="col-md-4">
                        <label for="rol" class="form-label">Rol *</label>
                        <select class="form-select" id="rol" name="rol" required>
                            <option value="admin" <?php echo ($usuario['rol'] ?? '') === 'admin' ? 'selected' : ''; ?>>Administrador</option>
                            <option value="profesor" <?php echo ($usuario['rol'] ?? '') === 'profesor' ? 'selected' : ''; ?>>Profesor</option>
                            <option value="estudiante" <?php echo ($usuario['rol'] ?? '') === 'estudiante' ? 'selected' : ''; ?>>Estudiante</option>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label for="username" class="form-label">Usuario *</label>
                        <input type="text" class="form-control" id="username" name="username" data-tipo="texto-corto" required value="<?php echo htmlspecialchars($usuario['username'] ?? ''); ?>">
                    </div>
                    <div class="col-md-4">
                        <label for="password" class="form-label">Nueva Contraseña</label>
                        <input type="text" class="form-control" id="password" name="password" data-tipo="password" placeholder="Dejar vacío para mantener la actual">
                    </div>
                </div>

                <div class="row mb-4">
                    <div class="col-md-6">
                        <label for="email" class="form-label">Email</label>
                        <input type="email" class="form-control" id="email" name="email" data-tipo="email" value="<?php echo htmlspecialchars($usuario['email'] ?? ''); ?>">
                    </div>
                    <div class="col-md-6">
                        <label for="estado" class="form-label">Estado</label>
                        <select class="form-select" id="estado" name="estado">
                            <option value="activo" <?php echo ($usuario['estado'] ?? 'activo') === 'activo' ? 'selected' : ''; ?>>Activo</option>
                            <option value="inactivo" <?php echo ($usuario['estado'] ?? 'activo') === 'inactivo' ? 'selected' : ''; ?>>Inactivo</option>
                        </select>
                    </div>
                </div>

                <div id="profesorFields" style="display: none;">
                    <hr>
                    <h5 class="mb-3">Información del Profesor</h5>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="prof_cedula" class="form-label">Cédula</label>
                            <input type="text" class="form-control" id="prof_cedula" name="prof_cedula" data-tipo="cedula" value="<?php echo htmlspecialchars($perfil['cedula'] ?? ''); ?>">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="prof_email" class="form-label">Email del Profesor</label>
                            <input type="email" class="form-control" id="prof_email" name="prof_email" data-tipo="email" value="<?php echo htmlspecialchars($perfil['email'] ?? ''); ?>">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="nombre_profesor" class="form-label">Nombre *</label>
                            <input type="text" class="form-control" id="nombre_profesor" name="prof_nombre" data-tipo="texto" value="<?php echo htmlspecialchars($perfil['nombre'] ?? ''); ?>">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="apellido_profesor" class="form-label">Apellido *</label>
                            <input type="text" class="form-control" id="apellido_profesor" name="prof_apellido" data-tipo="texto" value="<?php echo htmlspecialchars($perfil['apellido'] ?? ''); ?>">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="fecha_nacimiento_profesor" class="form-label">Fecha de Nacimiento</label>
                            <input type="date" class="form-control" id="fecha_nacimiento_profesor" name="prof_fecha_nacimiento" data-tipo="fecha" data-max-hoy="true" value="<?php echo htmlspecialchars($perfil['fecha_nacimiento'] ?? ''); ?>">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="prof_telefono" class="form-label">Teléfono</label>
                            <input type="text" class="form-control" id="prof_telefono" name="prof_telefono" data-tipo="telefono" value="<?php echo htmlspecialchars($perfil['telefono'] ?? ''); ?>">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="especialidad" class="form-label">Especialidad</label>
                            <input type="text" class="form-control" id="especialidad" name="especialidad" data-tipo="texto" value="<?php echo htmlspecialchars($perfil['especialidad'] ?? ''); ?>">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="grado_academico" class="form-label">Grado Académico</label>
                            <input type="text" class="form-control" id="grado_academico" name="grado_academico" data-tipo="texto" value="<?php echo htmlspecialchars($perfil['grado_academico'] ?? ''); ?>">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="fecha_contratacion" class="form-label">Fecha de Contratación</label>
                            <input type="date" class="form-control" id="fecha_contratacion" name="fecha_contratacion" data-tipo="fecha" data-max-hoy="true" value="<?php echo htmlspecialchars($perfil['fecha_contratacion'] ?? ''); ?>">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="lugar_nacimiento" class="form-label">Lugar de Nacimiento</label>
                            <input type="text" class="form-control" id="lugar_nacimiento" name="lugar_nacimiento" data-tipo="texto" value="<?php echo htmlspecialchars($perfil['lugar_nacimiento'] ?? ''); ?>">
                        </div>
                        <div class="col-12 mb-3">
                            <label for="direccion" class="form-label">Dirección</label>
                            <textarea class="form-control" id="direccion" name="direccion" rows="2" data-tipo="textarea"><?php echo htmlspecialchars($perfil['direccion'] ?? ''); ?></textarea>
                        </div>
                    </div>
                </div>

                <div id="estudianteFields" style="display: none;">
                    <hr>
                    <h5 class="mb-3">Información del Estudiante</h5>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="est_cedula_escolar" class="form-label">Cédula Escolar</label>
                            <input type="text" class="form-control" id="est_cedula_escolar" name="est_cedula_escolar" data-tipo="cedula-escolar" value="<?php echo htmlspecialchars($perfil['cedula_escolar'] ?? ''); ?>">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="est_email" class="form-label">Email del Estudiante</label>
                            <input type="email" class="form-control" id="est_email" name="est_email" data-tipo="email" value="<?php echo htmlspecialchars($perfil['email'] ?? ''); ?>">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="nombre_estudiante" class="form-label">Nombre *</label>
                            <input type="text" class="form-control" id="nombre_estudiante" name="est_nombre" data-tipo="texto" value="<?php echo htmlspecialchars($perfil['nombre'] ?? ''); ?>">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="apellido_estudiante" class="form-label">Apellido *</label>
                            <input type="text" class="form-control" id="apellido_estudiante" name="est_apellido" data-tipo="texto" value="<?php echo htmlspecialchars($perfil['apellido'] ?? ''); ?>">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="fecha_nacimiento_estudiante" class="form-label">Fecha de Nacimiento</label>
                            <input type="date" class="form-control" id="fecha_nacimiento_estudiante" name="est_fecha_nacimiento" data-tipo="fecha" data-max-hoy="true" value="<?php echo htmlspecialchars($perfil['fecha_nacimiento'] ?? ''); ?>">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="est_telefono" class="form-label">Teléfono</label>
                            <input type="text" class="form-control" id="est_telefono" name="est_telefono" data-tipo="telefono" value="<?php echo htmlspecialchars($perfil['telefono'] ?? ''); ?>">
                        </div>
                    </div>
                </div>

                <div class="d-grid gap-2 d-md-flex justify-content-md-end">
                    <a href="gestionar.php" class="btn btn-outline-secondary me-md-2">
                        <i class="fas fa-times me-2"></i>Cancelar
                    </a>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save me-2"></i>Guardar Cambios
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const rolSelect = document.getElementById('rol');
    const profesorFields = document.getElementById('profesorFields');
    const estudianteFields = document.getElementById('estudianteFields');

    function toggleRoleFields() {
        const rol = rolSelect.value;
        profesorFields.style.display = rol === 'profesor' ? 'block' : 'none';
        estudianteFields.style.display = rol === 'estudiante' ? 'block' : 'none';
    }

    rolSelect.addEventListener('change', toggleRoleFields);
    toggleRoleFields();
});
</script>

<script src="../../js/validaciones-formularios.js"></script>
<?php include '../../includes/footer.php'; ?>

