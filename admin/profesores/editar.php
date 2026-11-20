<?php
require_once '../../includes/auth.php';
require_once '../../includes/database.php';
require_once '../../includes/validaciones.php';
checkAuth();
checkRole(['admin']);

$id = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if ($id === false) {
    header('Location: gestionar.php');
    exit;
}

$stmt = $pdo->prepare('SELECT * FROM profesores WHERE id = ?');
$stmt->execute([$id]);
$profesor = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$profesor) {
    header('Location: gestionar.php');
    exit;
}

$mensaje = '';
$tipo_mensaje = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $validador = new Validador($_POST);
    $validador->cedula('cedula', 'Cédula', 6, 20, false);
    $validador->texto('nombre', 'Nombre', 2, 100, true);
    $validador->texto('apellido', 'Apellido', 2, 100, true);
    $validador->fecha('fecha_nacimiento', 'Fecha de nacimiento', false, true);
    $validador->texto('lugar_nacimiento', 'Lugar de nacimiento', 1, 100, false);
    $validador->email('email', 'Email', false);
    $validador->telefono('telefono', 'Teléfono', false);
    $validador->texto('especialidad', 'Especialidad', 1, 100, false);
    $validador->texto('grado_academico', 'Grado académico', 1, 100, false);
    $validador->opcion('estado', 'Estado', ['activo', 'inactivo', 'licencia'], true);

    $datos = [
        'cedula' => trim((string) ($_POST['cedula'] ?? '')),
        'nombre' => trim((string) ($_POST['nombre'] ?? '')),
        'apellido' => trim((string) ($_POST['apellido'] ?? '')),
        'fecha_nacimiento' => $_POST['fecha_nacimiento'] ?? '',
        'lugar_nacimiento' => trim((string) ($_POST['lugar_nacimiento'] ?? '')),
        'direccion' => trim((string) ($_POST['direccion'] ?? '')),
        'fecha_contratacion' => $_POST['fecha_contratacion'] ?? '',
        'email' => trim((string) ($_POST['email'] ?? '')),
        'telefono' => trim((string) ($_POST['telefono'] ?? '')),
        'especialidad' => trim((string) ($_POST['especialidad'] ?? '')),
        'grado_academico' => trim((string) ($_POST['grado_academico'] ?? '')),
        'estado' => $_POST['estado'] ?? '',
    ];

    $fechaContratacion = $validador->fecha('fecha_contratacion', 'Fecha de contratación', false, true);
    if ($validador->hayErrores()) {
        $mensaje = $validador->getMensajeErrores();
        $tipo_mensaje = 'warning';
    } else {
        try {
            $update = $pdo->prepare(
                'UPDATE profesores
                 SET cedula = ?, nombre = ?, apellido = ?, fecha_nacimiento = ?,
                     lugar_nacimiento = ?, direccion = ?, fecha_contratacion = ?,
                     email = ?, telefono = ?, especialidad = ?, grado_academico = ?, estado = ?
                 WHERE id = ?'
            );
            $update->execute([
                $datos['cedula'] !== '' ? $datos['cedula'] : null,
                $datos['nombre'],
                $datos['apellido'],
                $datos['fecha_nacimiento'] !== '' ? $datos['fecha_nacimiento'] : null,
                $datos['lugar_nacimiento'] !== '' ? $datos['lugar_nacimiento'] : null,
                $datos['direccion'] !== '' ? $datos['direccion'] : null,
                $fechaContratacion,
                $datos['email'] !== '' ? $datos['email'] : null,
                $datos['telefono'] !== '' ? $datos['telefono'] : null,
                $datos['especialidad'] !== '' ? $datos['especialidad'] : null,
                $datos['grado_academico'] !== '' ? $datos['grado_academico'] : null,
                $datos['estado'],
                $id,
            ]);
            $profesor = array_merge($profesor, $datos, [
                'fecha_nacimiento' => $datos['fecha_nacimiento'],
                'fecha_contratacion' => $fechaContratacion,
            ]);
            $mensaje = 'Profesor actualizado correctamente.';
            $tipo_mensaje = 'success';
        } catch (PDOException $exception) {
            if ($exception->getCode() === '23000') {
                $mensaje = 'No se pudo actualizar: la cédula ya está registrada.';
                $tipo_mensaje = 'warning';
            } else {
                error_log('Error al actualizar profesor: ' . $exception->getMessage());
                $mensaje = 'Ocurrió un error al actualizar el profesor.';
                $tipo_mensaje = 'danger';
            }
        }
    }

    if ($validador->hayErrores()) {
        $profesor = array_merge($profesor, $datos);
    }
}

function escaparCampoProfesor(array $profesor, string $campo): string
{
    return htmlspecialchars((string) ($profesor[$campo] ?? ''), ENT_QUOTES, 'UTF-8');
}
?>
<?php include '../../includes/header.php'; ?>
<div class="content-wrapper" style="padding: 20px;">
    <?php include '../../includes/sidebar_admin.php'; ?>
    <div class="p-4">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h2>Editar Profesor</h2>
            <a href="gestionar.php" class="btn btn-outline-secondary">Volver a la lista</a>
        </div>

        <?php if ($mensaje !== ''): ?>
            <div class="alert alert-<?php echo htmlspecialchars($tipo_mensaje, ENT_QUOTES, 'UTF-8'); ?>" role="alert">
                <?php echo $mensaje; ?>
            </div>
        <?php endif; ?>

        <div class="card">
            <div class="card-header">
                <h5 class="card-title mb-0">
                    Editando: <?php echo escaparCampoProfesor($profesor, 'nombre') . ' ' . escaparCampoProfesor($profesor, 'apellido'); ?>
                </h5>
            </div>
            <div class="card-body">
                <form method="POST" action="">
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="cedula" class="form-label">Cédula</label>
                            <input type="text" class="form-control" id="cedula" name="cedula"
                                   value="<?php echo escaparCampoProfesor($profesor, 'cedula'); ?>" maxlength="20">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="estado" class="form-label">Estado *</label>
                            <select class="form-select" id="estado" name="estado" required>
                                <?php foreach (['activo' => 'Activo', 'inactivo' => 'Inactivo', 'licencia' => 'En Licencia'] as $valor => $etiqueta): ?>
                                    <option value="<?php echo $valor; ?>" <?php echo $profesor['estado'] === $valor ? 'selected' : ''; ?>>
                                        <?php echo $etiqueta; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="nombre" class="form-label">Nombre *</label>
                            <input type="text" class="form-control" id="nombre" name="nombre" required maxlength="100"
                                   value="<?php echo escaparCampoProfesor($profesor, 'nombre'); ?>">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="apellido" class="form-label">Apellido *</label>
                            <input type="text" class="form-control" id="apellido" name="apellido" required maxlength="100"
                                   value="<?php echo escaparCampoProfesor($profesor, 'apellido'); ?>">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="fecha_nacimiento" class="form-label">Fecha de nacimiento</label>
                            <input type="date" class="form-control" id="fecha_nacimiento" name="fecha_nacimiento"
                                   value="<?php echo escaparCampoProfesor($profesor, 'fecha_nacimiento'); ?>">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="lugar_nacimiento" class="form-label">Lugar de nacimiento</label>
                            <input type="text" class="form-control" id="lugar_nacimiento" name="lugar_nacimiento" maxlength="100"
                                   value="<?php echo escaparCampoProfesor($profesor, 'lugar_nacimiento'); ?>">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="email" class="form-label">Correo electrónico</label>
                            <input type="email" class="form-control" id="email" name="email" maxlength="255"
                                   value="<?php echo escaparCampoProfesor($profesor, 'email'); ?>">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="telefono" class="form-label">Teléfono</label>
                            <input type="text" class="form-control" id="telefono" name="telefono" maxlength="20"
                                   value="<?php echo escaparCampoProfesor($profesor, 'telefono'); ?>">
                        </div>
                        <div class="col-12 mb-3">
                            <label for="direccion" class="form-label">Dirección</label>
                            <textarea class="form-control" id="direccion" name="direccion" rows="3"><?php echo escaparCampoProfesor($profesor, 'direccion'); ?></textarea>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="fecha_contratacion" class="form-label">Fecha de contratación</label>
                            <input type="date" class="form-control" id="fecha_contratacion" name="fecha_contratacion"
                                   value="<?php echo escaparCampoProfesor($profesor, 'fecha_contratacion'); ?>">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="especialidad" class="form-label">Especialidad</label>
                            <input type="text" class="form-control" id="especialidad" name="especialidad" maxlength="100"
                                   value="<?php echo escaparCampoProfesor($profesor, 'especialidad'); ?>">
                        </div>
                        <div class="col-12 mb-3">
                            <label for="grado_academico" class="form-label">Grado académico</label>
                            <input type="text" class="form-control" id="grado_academico" name="grado_academico" maxlength="100"
                                   value="<?php echo escaparCampoProfesor($profesor, 'grado_academico'); ?>">
                        </div>
                    </div>
                    <div class="d-flex justify-content-end gap-2">
                        <a href="gestionar.php" class="btn btn-outline-secondary">Cancelar</a>
                        <button type="submit" class="btn btn-primary">Actualizar profesor</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
<script src="../../js/validaciones-formularios.js"></script>
<?php include '../../includes/footer.php'; ?>
