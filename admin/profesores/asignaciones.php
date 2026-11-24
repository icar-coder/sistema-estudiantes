<?php
require_once '../../includes/auth.php';
require_once '../../includes/database.php';
require_once '../../includes/functions_asignaciones_profesores.php';

checkAuth();
checkRole(['admin']);

if (empty($_SESSION['csrf_asignaciones_profesores'])) {
    $_SESSION['csrf_asignaciones_profesores'] = bin2hex(random_bytes(32));
}

$mensaje = $_SESSION['mensaje_asignaciones_profesores'] ?? '';
$tipoMensaje = $_SESSION['tipo_mensaje_asignaciones_profesores'] ?? 'info';
unset($_SESSION['mensaje_asignaciones_profesores'], $_SESSION['tipo_mensaje_asignaciones_profesores']);
$error = '';
$años = [];
$grados = [];
$profesores = [];
$secciones = [];
$añoId = null;
$gradoId = null;
$profesorPreseleccionado = null;
$seccionPreseleccionada = null;

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $accion = $_POST['accion'] ?? '';
    $añoRaw = $_POST['año_escolar_id'] ?? '';
    $profesorRaw = $_POST['profesor_id'] ?? '';
    $añoId = is_string($añoRaw)
        ? filter_var($añoRaw, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])
        : false;
    $profesorId = $profesorRaw === ''
        ? null
        : (is_string($profesorRaw)
            ? filter_var($profesorRaw, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])
            : false);
    $gradoRaw = $_POST['grado_id'] ?? '';
    $gradoId = is_string($gradoRaw)
        ? filter_var($gradoRaw, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])
        : false;
    $seccionRaw = $_POST['seccion_id'] ?? '';
    $seccionId = is_string($seccionRaw)
        ? filter_var($seccionRaw, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])
        : false;
    $token = $_POST['csrf_token'] ?? '';
    $tipoMensaje = 'danger';

    try {
        if (!is_string($token) ||
            !hash_equals($_SESSION['csrf_asignaciones_profesores'], $token)) {
            throw new InvalidArgumentException('La solicitud expiró. Recarga la página e inténtalo nuevamente.');
        }
        if ($añoId === false || $profesorId === false) {
            throw new InvalidArgumentException('El año escolar o profesor seleccionado no es válido.');
        }

        if (!in_array($accion, ['asignar_grado', 'asignar_seccion'], true)) {
            throw new InvalidArgumentException('La acción solicitada no es válida.');
        }
        $pdo->beginTransaction();
        if ($accion === 'asignar_grado') {
            if ($gradoId === false || $profesorId === null) {
                throw new InvalidArgumentException('Selecciona un grado y un profesor para asignar.');
            }

            $resultado = guardarAsignacionProfesorAGrado(
                $pdo,
                (int) $añoId,
                (int) $gradoId,
                (int) $profesorId
            );

            $pdo->commit();
            $mensaje = $resultado['actualizadas'] > 0
                ? "Se asignó el profesor a {$resultado['actualizadas']} sección(es) del grado."
                : "Las {$resultado['secciones']} sección(es) del grado ya tenían ese profesor; no hubo cambios.";
            $tipoMensaje = 'success';
        } elseif ($accion === 'asignar_seccion') {
            if ($seccionId === false || $gradoId === false) {
                throw new InvalidArgumentException('La sección seleccionada no es válida.');
            }

            guardarAsignacionProfesorSeccion(
                $pdo,
                (int) $añoId,
                (int) $gradoId,
                (int) $seccionId,
                $profesorId === null ? null : (int) $profesorId,
            );
            $pdo->commit();
            $mensaje = $profesorId === null
                ? 'Se quitó el profesor titular de la sección.'
                : 'Se actualizó el profesor titular de la sección.';
            $tipoMensaje = 'success';
        }
    } catch (InvalidArgumentException $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $mensaje = $exception->getMessage();
    } catch (PDOException $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('Error al asignar profesores por grado o sección: ' . $exception->getMessage());
        $mensaje = 'No se pudieron guardar las asignaciones. No se aplicaron cambios.';
    }

    $_SESSION['mensaje_asignaciones_profesores'] = $mensaje;
    $_SESSION['tipo_mensaje_asignaciones_profesores'] = $tipoMensaje;
    $parametros = [];
    if ($añoId !== false) {
        $parametros['año_escolar_id'] = (int) $añoId;
    }
    if ($gradoId !== false) {
        $parametros['grado_id'] = (int) $gradoId;
    }
    if ($seccionId !== false) {
        $parametros['seccion_id'] = (int) $seccionId;
    }
    if ($profesorId !== false && $profesorId !== null) {
        $parametros['profesor_id'] = (int) $profesorId;
    }
    header('Location: asignaciones.php' . ($parametros ? '?' . http_build_query($parametros) : ''));
    exit;
}

try {
    $años = $pdo->query(
        "SELECT `id`, `nombre` FROM `años_escolares`
         WHERE `estado` = 'activo' ORDER BY `nombre` DESC, `id` DESC"
    )->fetchAll(PDO::FETCH_ASSOC);

    $añoRaw = $_GET['año_escolar_id'] ?? '';
    if (is_string($añoRaw) && $añoRaw !== '') {
        $valor = filter_var($añoRaw, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        foreach ($años as $año) {
            if ($valor !== false && (int) $año['id'] === (int) $valor) {
                $añoId = (int) $valor;
                break;
            }
        }
        if ($añoId === null) {
            $error = 'El año escolar seleccionado no está activo o no existe.';
        }
    } elseif ($años !== []) {
        $añoId = (int) $años[0]['id'];
    }

    if ($añoId !== null) {
        $gradoQuery = $pdo->prepare(
            'SELECT g.`id`, g.`nombre`, g.`nivel`, COUNT(s.`id`) AS `total_secciones`
             FROM `grados` g
             JOIN `secciones` s ON s.`grado_id` = g.`id` AND s.`año_escolar_id` = ?
             WHERE g.`estado` = ?
             GROUP BY g.`id`, g.`nombre`, g.`nivel`, g.`orden`
             ORDER BY g.`orden`, g.`id`'
        );
        $gradoQuery->execute([$añoId, 'activo']);
        $grados = $gradoQuery->fetchAll(PDO::FETCH_ASSOC);

        $profesorQuery = $pdo->prepare(
            "SELECT p.`id`, p.`nombre`, p.`apellido`, p.`estado`,
                    COUNT(DISTINCT s.`id`) AS `total_secciones`,
                    COUNT(DISTINCT s.`grado_id`) AS `total_grados`
             FROM `profesores` p
             LEFT JOIN `secciones` s
               ON s.`profesor_id` = p.`id` AND s.`año_escolar_id` = ?
             GROUP BY p.`id`, p.`nombre`, p.`apellido`, p.`estado`
             ORDER BY p.`estado` = 'activo' DESC, p.`apellido`, p.`nombre`"
        );
        $profesorQuery->execute([$añoId]);
        $profesores = $profesorQuery->fetchAll(PDO::FETCH_ASSOC);

        $gradoRaw = $_GET['grado_id'] ?? '';
        if (is_string($gradoRaw) && $gradoRaw !== '') {
            $valor = filter_var($gradoRaw, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            foreach ($grados as $grado) {
                if ($valor !== false && (int) $grado['id'] === (int) $valor) {
                    $gradoId = (int) $valor;
                    break;
                }
            }
            if ($gradoId === null && $error === '') {
                $error = 'El grado seleccionado no tiene secciones en este año escolar.';
            }
        }

        $profesorFiltroRaw = $_GET['profesor_id'] ?? '';
        if (is_string($profesorFiltroRaw) && $profesorFiltroRaw !== '') {
            $valor = filter_var($profesorFiltroRaw, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($valor !== false) {
                foreach ($profesores as $profesor) {
                    if ((int) $profesor['id'] === (int) $valor) {
                        $profesorPreseleccionado = (int) $valor;
                        break;
                    }
                }
            }
        }

        $seccionFiltroRaw = $_GET['seccion_id'] ?? '';
        if ($gradoId !== null && is_string($seccionFiltroRaw) && $seccionFiltroRaw !== '') {
            $valor = filter_var($seccionFiltroRaw, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($valor !== false) {
                $seccionPreseleccionada = (int) $valor;
            }
        }

        if ($gradoId !== null) {
            $seccionQuery = $pdo->prepare(
                'SELECT s.`id`, s.`nombre`, p.`id` AS `profesor_id`,
                        p.`nombre` AS `profesor_nombre`, p.`apellido` AS `profesor_apellido`,
                        p.`estado` AS `profesor_estado`
                 FROM `secciones` s
                 LEFT JOIN `profesores` p ON p.`id` = s.`profesor_id`
                 WHERE s.`año_escolar_id` = ? AND s.`grado_id` = ?
                 ORDER BY s.`nombre`, s.`id`'
            );
            $seccionQuery->execute([$añoId, $gradoId]);
            $secciones = $seccionQuery->fetchAll(PDO::FETCH_ASSOC);
        }
    }
} catch (PDOException $exception) {
    error_log('Error al cargar asignaciones de profesores: ' . $exception->getMessage());
    $error = 'No se pudieron cargar las asignaciones de profesores.';
}
?>

<?php include '../../includes/header.php'; ?>
<?php include '../../includes/sidebar_admin.php'; ?>

<main class="content-wrapper">
    <div class="container-fluid py-4">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-3 mb-4">
            <div>
                <h2><i class="fas fa-chalkboard-teacher me-2"></i>Asignar profesores</h2>
                <p class="text-muted mb-0">Asigna por grado y ajusta cada sección cuando sea necesario.</p>
            </div>
            <a href="gestionar.php" class="btn btn-outline-secondary">
                <i class="fas fa-users me-1"></i>Ver profesores
            </a>
        </div>

        <?php if ($mensaje !== ''): ?>
            <div class="alert alert-<?php echo htmlspecialchars($tipoMensaje, ENT_QUOTES, 'UTF-8'); ?>" role="status">
                <?php echo htmlspecialchars($mensaje, ENT_QUOTES, 'UTF-8'); ?>
            </div>
        <?php endif; ?>
        <?php if ($error !== ''): ?>
            <div class="alert alert-danger" role="alert">
                <?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?>
            </div>
        <?php endif; ?>

        <?php if ($años === []): ?>
            <div class="alert alert-warning">No hay un año escolar activo para administrar asignaciones.</div>
        <?php elseif ($añoId !== null): ?>
            <form method="get" class="card mb-4">
                <div class="card-body row g-3 align-items-end">
                    <div class="col-md-6">
                        <label for="año_escolar_id" class="form-label">Año escolar activo</label>
                        <select class="form-select" id="año_escolar_id" name="año_escolar_id" required
                                onchange="this.form.submit()">
                            <?php foreach ($años as $año): ?>
                                <option value="<?php echo (int) $año['id']; ?>"
                                    <?php echo $añoId === (int) $año['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($año['nombre'], ENT_QUOTES, 'UTF-8'); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php if ($profesorPreseleccionado !== null): ?>
                        <input type="hidden" name="profesor_id" value="<?php echo $profesorPreseleccionado; ?>">
                    <?php endif; ?>
                </div>
            </form>

            <?php if ($grados === []): ?>
                <div class="alert alert-info">No hay secciones vinculadas a grados en este año escolar.</div>
            <?php else: ?>
                <div class="card mb-4">
                    <div class="card-header">
                        <h3 class="h5 mb-0">Asignación general por grado</h3>
                    </div>
                    <div class="card-body">
                        <p class="text-muted">
                            Esta acción asigna el profesor a todas las secciones existentes del grado.
                            Luego puedes cambiar la asignación de una sección individualmente.
                        </p>
                        <form method="get" class="row g-3 align-items-end mb-4">
                            <input type="hidden" name="año_escolar_id" value="<?php echo $añoId; ?>">
                            <div class="col-md-6">
                                <label for="grado_id" class="form-label">Grado</label>
                                <select class="form-select" id="grado_id" name="grado_id" required
                                        onchange="this.form.submit()">
                                    <option value="">Seleccionar grado...</option>
                                    <?php foreach ($grados as $grado): ?>
                                        <option value="<?php echo (int) $grado['id']; ?>"
                                            <?php echo $gradoId === (int) $grado['id'] ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($grado['nombre'], ENT_QUOTES, 'UTF-8'); ?>
                                            (<?php echo (int) $grado['total_secciones']; ?> secciones)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </form>

                        <?php if ($gradoId !== null): ?>
                            <?php if (count(array_filter($profesores, function ($profesor) {
                                return $profesor['estado'] === 'activo';
                            })) === 0): ?>
                                <div class="alert alert-warning">No hay profesores activos para asignar en este año.</div>
                            <?php else: ?>
                            <form method="post" class="row g-3 align-items-end">
                                <input type="hidden" name="csrf_token"
                                       value="<?php echo htmlspecialchars($_SESSION['csrf_asignaciones_profesores'], ENT_QUOTES, 'UTF-8'); ?>">
                                <input type="hidden" name="accion" value="asignar_grado">
                                <input type="hidden" name="año_escolar_id" value="<?php echo $añoId; ?>">
                                <input type="hidden" name="grado_id" value="<?php echo $gradoId; ?>">
                                <div class="col-md-8">
                                    <label for="profesor_grado_id" class="form-label">Profesor activo</label>
                                    <select class="form-select" id="profesor_grado_id" name="profesor_id" required>
                                        <option value="">Seleccionar profesor...</option>
                                        <?php foreach ($profesores as $profesor): ?>
                                            <?php if ($profesor['estado'] === 'activo'): ?>
                                                <option value="<?php echo (int) $profesor['id']; ?>"
                                                    <?php echo $profesorPreseleccionado === (int) $profesor['id'] ? 'selected' : ''; ?>>
                                                    <?php echo htmlspecialchars(
                                                        $profesor['apellido'] . ', ' . $profesor['nombre'],
                                                        ENT_QUOTES,
                                                        'UTF-8'
                                                    ); ?>
                                                    — <?php echo (int) $profesor['total_grados']; ?> grado(s),
                                                    <?php echo (int) $profesor['total_secciones']; ?> sección(es)
                                                </option>
                                            <?php endif; ?>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-4 d-grid">
                                    <button type="submit" class="btn btn-primary">
                                        <i class="fas fa-user-check me-1"></i>Asignar a todo el grado
                                    </button>
                                </div>
                            </form>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </div>

                <?php if ($gradoId !== null): ?>
                    <div class="card">
                        <div class="card-header">
                            <h3 class="h5 mb-0">Asignación por sección</h3>
                        </div>
                        <div class="table-responsive">
                            <table class="table table-striped align-middle mb-0">
                                <thead>
                                    <tr>
                                        <th>Sección</th>
                                        <th>Profesor titular actual</th>
                                        <th>Asignar o cambiar profesor</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($secciones as $seccion): ?>
                                        <tr class="<?php echo $seccionPreseleccionada === (int) $seccion['id'] ? 'table-primary' : ''; ?>">
                                            <td><strong><?php echo htmlspecialchars($seccion['nombre'], ENT_QUOTES, 'UTF-8'); ?></strong></td>
                                            <td>
                                                <?php if ($seccion['profesor_id'] !== null): ?>
                                                    <?php echo htmlspecialchars(
                                                        $seccion['profesor_apellido'] . ', ' . $seccion['profesor_nombre'],
                                                        ENT_QUOTES,
                                                        'UTF-8'
                                                    ); ?>
                                                    <?php if ($seccion['profesor_estado'] !== 'activo'): ?>
                                                        <span class="badge bg-warning text-dark">No activo</span>
                                                    <?php endif; ?>
                                                <?php else: ?>
                                                    <span class="text-muted">Sin asignar</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <form method="post" class="d-flex flex-wrap gap-2">
                                                    <input type="hidden" name="csrf_token"
                                                           value="<?php echo htmlspecialchars($_SESSION['csrf_asignaciones_profesores'], ENT_QUOTES, 'UTF-8'); ?>">
                                                    <input type="hidden" name="accion" value="asignar_seccion">
                                                    <input type="hidden" name="año_escolar_id" value="<?php echo $añoId; ?>">
                                                    <input type="hidden" name="grado_id" value="<?php echo $gradoId; ?>">
                                                    <input type="hidden" name="seccion_id" value="<?php echo (int) $seccion['id']; ?>">
                                                    <select class="form-select" name="profesor_id" aria-label="Profesor para <?php echo htmlspecialchars($seccion['nombre'], ENT_QUOTES, 'UTF-8'); ?>">
                                                        <option value="">Sin asignar</option>
                                                        <?php foreach ($profesores as $profesor): ?>
                                                            <?php if ($profesor['estado'] === 'activo' ||
                                                                (int) $profesor['id'] === (int) $seccion['profesor_id']): ?>
                                                                <option value="<?php echo (int) $profesor['id']; ?>"
                                                                    <?php echo (int) $profesor['id'] === (int) $seccion['profesor_id'] ? 'selected' : ''; ?>>
                                                                    <?php echo htmlspecialchars(
                                                                        $profesor['apellido'] . ', ' . $profesor['nombre'],
                                                                        ENT_QUOTES,
                                                                        'UTF-8'
                                                                    ); ?>
                                                                    <?php echo $profesor['estado'] === 'activo' ? '' : ' (No activo)'; ?>
                                                                </option>
                                                            <?php endif; ?>
                                                        <?php endforeach; ?>
                                                    </select>
                                                    <button type="submit" class="btn btn-outline-primary text-nowrap">
                                                        Guardar
                                                    </button>
                                                </form>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</main>

<?php include '../../includes/footer.php'; ?>
