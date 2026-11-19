<?php
require_once '../../includes/auth.php';
require_once '../../includes/database.php';
require_once '../../includes/database_helpers.php';
require_once '../../includes/functions_asistencias.php';

checkAuth();
checkRole(['admin']);

$tablasRequeridas = ['años_escolares', 'secciones', 'estudiantes', 'inscripciones', 'asistencias'];
$tablasFaltantes = [];
foreach ($tablasRequeridas as $tabla) {
    if (!databaseTableExists($pdo, $tabla)) {
        $tablasFaltantes[] = $tabla;
    }
}

if (empty($_SESSION['csrf_admin_asistencias'])) {
    $_SESSION['csrf_admin_asistencias'] = bin2hex(random_bytes(32));
}

$redirigir = function (?int $añoId, ?int $seccionId, string $fecha): void {
    $parametros = [];
    if ($añoId !== null) {
        $parametros['año_escolar_id'] = $añoId;
    }
    if ($seccionId !== null) {
        $parametros['seccion_id'] = $seccionId;
    }
    if (esFechaAsistenciaValida($fecha)) {
        $parametros['fecha'] = $fecha;
    }

    $url = app_url('admin/asistencias/gestionar.php');
    if ($parametros !== []) {
        $url .= '?' . http_build_query($parametros);
    }
    header('Location: ' . $url);
    exit;
};

$redirigirRango = function (
    ?int $añoId,
    ?int $seccionId,
    string $fechaInicio,
    string $fechaFin
): void {
    $parametros = [];
    $parametros['buscar_rango'] = 1;
    if ($añoId !== null) {
        $parametros['rango_año_escolar_id'] = $añoId;
    }
    if ($seccionId !== null) {
        $parametros['rango_seccion_id'] = $seccionId;
    }
    if (esFechaAsistenciaValida($fechaInicio)) {
        $parametros['fecha_inicio'] = $fechaInicio;
    }
    if (esFechaAsistenciaValida($fechaFin)) {
        $parametros['fecha_fin'] = $fechaFin;
    }

    $url = app_url('admin/asistencias/gestionar.php');
    if ($parametros !== []) {
        $url .= '?' . http_build_query($parametros);
    }
    header('Location: ' . $url);
    exit;
};

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $tokenEnviado = $_POST['csrf_token'] ?? '';
    $registradoPor = null;
    if (!isSqliteDatabase($pdo) && isset($_SESSION['user_id']) &&
        filter_var($_SESSION['user_id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) !== false) {
        $registradoPor = (int) $_SESSION['user_id'];
    }

    if (($_POST['accion'] ?? '') === 'guardar_rango') {
        $añoRaw = $_POST['rango_año_escolar_id'] ?? '';
        $seccionRaw = $_POST['rango_seccion_id'] ?? '';
        $fechaInicioRaw = $_POST['fecha_inicio'] ?? '';
        $fechaFinRaw = $_POST['fecha_fin'] ?? '';
        $añoId = is_string($añoRaw)
            ? filter_var($añoRaw, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])
            : false;
        $seccionId = is_string($seccionRaw)
            ? filter_var($seccionRaw, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])
            : false;
        $fechaInicio = is_string($fechaInicioRaw) ? $fechaInicioRaw : '';
        $fechaFin = is_string($fechaFinRaw) ? $fechaFinRaw : '';
        $mensaje = '';
        $tipoMensaje = 'danger';

        if (!is_string($tokenEnviado) ||
            !hash_equals($_SESSION['csrf_admin_asistencias'], $tokenEnviado)) {
            $mensaje = 'La solicitud expiró. Recarga la página e inténtalo nuevamente.';
        } elseif ($tablasFaltantes !== []) {
            $mensaje = 'No se pueden guardar los cambios porque faltan tablas requeridas.';
        } elseif ($añoId === false || $seccionId === false ||
            !esFechaAsistenciaValida($fechaInicio) || !esFechaAsistenciaValida($fechaFin) ||
            $fechaInicio > $fechaFin) {
            $mensaje = 'El año, la sección o el rango de fechas no son válidos.';
        } elseif (!is_array($_POST['asistencias'] ?? null) ||
            !is_array($_POST['observaciones'] ?? null)) {
            $mensaje = 'La lista de asistencias enviada no es válida.';
        } else {
            try {
                $procesados = guardarAsistenciasAdminEnRango(
                    $pdo,
                    (int) $añoId,
                    (int) $seccionId,
                    $fechaInicio,
                    $fechaFin,
                    $_POST['asistencias'],
                    $_POST['observaciones'],
                    $registradoPor
                );
                $mensaje = "Se actualizaron $procesados registro(s) de asistencia.";
                $tipoMensaje = 'success';
            } catch (InvalidArgumentException $exception) {
                $mensaje = $exception->getMessage();
            } catch (PDOException $exception) {
                error_log('Error al editar asistencias por rango: ' . $exception->getMessage());
                $mensaje = 'No se pudieron guardar los cambios del rango. No se aplicaron cambios.';
            }
        }

        $_SESSION['mensaje_admin_asistencias'] = $mensaje;
        $_SESSION['tipo_mensaje_admin_asistencias'] = $tipoMensaje;
        $redirigirRango(
            $añoId === false ? null : (int) $añoId,
            $seccionId === false ? null : (int) $seccionId,
            $fechaInicio,
            $fechaFin
        );
    }

    if (($_POST['accion'] ?? '') !== 'guardar_diario') {
        $_SESSION['mensaje_admin_asistencias'] = 'La acción solicitada no es válida.';
        $_SESSION['tipo_mensaje_admin_asistencias'] = 'danger';
        $redirigir(null, null, date('Y-m-d'));
    }

    $añoRaw = $_POST['año_escolar_id'] ?? '';
    $seccionRaw = $_POST['seccion_id'] ?? '';
    $fechaRaw = $_POST['fecha'] ?? '';
    $añoId = is_string($añoRaw)
        ? filter_var($añoRaw, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])
        : false;
    $seccionId = is_string($seccionRaw)
        ? filter_var($seccionRaw, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])
        : false;
    $fecha = is_string($fechaRaw) ? $fechaRaw : '';
    $mensaje = '';
    $tipoMensaje = 'danger';

    if (!is_string($tokenEnviado) ||
        !hash_equals($_SESSION['csrf_admin_asistencias'], $tokenEnviado)) {
        $mensaje = 'La solicitud expiró. Recarga la página e inténtalo nuevamente.';
    } elseif ($tablasFaltantes !== []) {
        $mensaje = 'No se puede guardar la asistencia porque faltan tablas requeridas.';
    } elseif ($añoId === false || $seccionId === false || !esFechaAsistenciaValida($fecha)) {
        $mensaje = 'Selecciona un año escolar, una sección y una fecha válidos.';
    } elseif (!is_array($_POST['asistencias'] ?? null) ||
        !is_array($_POST['observaciones'] ?? null)) {
        $mensaje = 'La lista de asistencias enviada no es válida.';
    } else {
        try {
            $procesados = guardarListaAsistenciaAdmin(
                $pdo,
                (int) $añoId,
                (int) $seccionId,
                $fecha,
                $_POST['asistencias'],
                $_POST['observaciones'],
                $registradoPor
            );
            $mensaje = "Asistencias guardadas para $procesados estudiante(s).";
            $tipoMensaje = 'success';
        } catch (InvalidArgumentException $exception) {
            $mensaje = $exception->getMessage();
        } catch (PDOException $exception) {
            error_log('Error al guardar asistencias del administrador: ' . $exception->getMessage());
            $mensaje = 'No se pudieron guardar las asistencias. No se aplicaron cambios.';
        }
    }

    $_SESSION['mensaje_admin_asistencias'] = $mensaje;
    $_SESSION['tipo_mensaje_admin_asistencias'] = $tipoMensaje;
    $redirigir(
        $añoId === false ? null : (int) $añoId,
        $seccionId === false ? null : (int) $seccionId,
        $fecha
    );
}

$mensaje = $_SESSION['mensaje_admin_asistencias'] ?? '';
$tipoMensaje = $_SESSION['tipo_mensaje_admin_asistencias'] ?? 'info';
unset($_SESSION['mensaje_admin_asistencias'], $_SESSION['tipo_mensaje_admin_asistencias']);

$errores = [];
$erroresRango = [];
$añosEscolares = [];
$secciones = [];
$seccionesRango = [];
$listaAsistencia = null;
$registrosRango = null;
$añoId = null;
$seccionId = null;
$fechaRaw = $_GET['fecha'] ?? date('Y-m-d');
$fecha = is_string($fechaRaw) ? $fechaRaw : '';
$rangoAñoId = null;
$rangoSeccionId = null;
$fechaInicioRaw = $_GET['fecha_inicio'] ?? date('Y-m-01');
$fechaFinRaw = $_GET['fecha_fin'] ?? date('Y-m-d');
$fechaInicio = is_string($fechaInicioRaw) ? $fechaInicioRaw : '';
$fechaFin = is_string($fechaFinRaw) ? $fechaFinRaw : '';
$buscarRango = ($_GET['buscar_rango'] ?? '') === '1';

if ($tablasFaltantes === []) {
    $añosEscolares = $pdo->query(
        'SELECT `id`, `nombre` FROM `años_escolares` ORDER BY `nombre` DESC, `id` DESC'
    )->fetchAll(PDO::FETCH_ASSOC);

    $añoRaw = $_GET['año_escolar_id'] ?? '';
    if (!is_string($añoRaw)) {
        $errores[] = 'El año escolar seleccionado no es válido.';
    } elseif ($añoRaw !== '') {
        $valor = filter_var($añoRaw, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($valor === false) {
            $errores[] = 'El año escolar seleccionado no es válido.';
        } else {
            foreach ($añosEscolares as $año) {
                if ((int) $año['id'] === (int) $valor) {
                    $añoId = (int) $valor;
                    break;
                }
            }
            if ($añoId === null) {
                $errores[] = 'El año escolar seleccionado no existe.';
            }
        }
    }

    if (!esFechaAsistenciaValida($fecha)) {
        $errores[] = 'La fecha seleccionada no es válida.';
        $fecha = date('Y-m-d');
    }

    if ($añoId !== null) {
        $statement = $pdo->prepare(
            'SELECT `id`, `nombre` FROM `secciones`
             WHERE `año_escolar_id` = ? ORDER BY `nombre`, `id`'
        );
        $statement->execute([$añoId]);
        $secciones = $statement->fetchAll(PDO::FETCH_ASSOC);

        $seccionRaw = $_GET['seccion_id'] ?? '';
        if (!is_string($seccionRaw)) {
            $errores[] = 'La sección seleccionada no es válida.';
        } elseif ($seccionRaw !== '') {
            $valor = filter_var($seccionRaw, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($valor === false ||
                !in_array((int) $valor, array_map('intval', array_column($secciones, 'id')), true)) {
                $errores[] = 'La sección no pertenece al año escolar seleccionado.';
            } else {
                $seccionId = (int) $valor;
            }
        }

        if ($seccionId !== null && $errores === []) {
            $listaAsistencia = obtenerListaAsistenciaAdmin($pdo, $añoId, $seccionId, $fecha);
        }
    }

    $rangoAñoRaw = $_GET['rango_año_escolar_id'] ?? '';
    if (!is_string($rangoAñoRaw)) {
        $erroresRango[] = 'El año escolar del rango no es válido.';
    } elseif ($rangoAñoRaw !== '') {
        $valor = filter_var($rangoAñoRaw, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($valor === false) {
            $erroresRango[] = 'El año escolar del rango no es válido.';
        } else {
            foreach ($añosEscolares as $año) {
                if ((int) $año['id'] === (int) $valor) {
                    $rangoAñoId = (int) $valor;
                    break;
                }
            }
            if ($rangoAñoId === null) {
                $erroresRango[] = 'El año escolar del rango no existe.';
            }
        }
    }

    if ($rangoAñoId !== null) {
        $statement = $pdo->prepare(
            'SELECT `id`, `nombre` FROM `secciones`
             WHERE `año_escolar_id` = ? ORDER BY `nombre`, `id`'
        );
        $statement->execute([$rangoAñoId]);
        $seccionesRango = $statement->fetchAll(PDO::FETCH_ASSOC);

        $rangoSeccionRaw = $_GET['rango_seccion_id'] ?? '';
        if (!is_string($rangoSeccionRaw)) {
            $erroresRango[] = 'La sección del rango no es válida.';
        } elseif ($rangoSeccionRaw !== '') {
            $valor = filter_var($rangoSeccionRaw, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($valor === false ||
                !in_array((int) $valor, array_map('intval', array_column($seccionesRango, 'id')), true)) {
                $erroresRango[] = 'La sección no pertenece al año escolar seleccionado.';
            } else {
                $rangoSeccionId = (int) $valor;
            }
        }
    } elseif (isset($_GET['rango_seccion_id']) && $_GET['rango_seccion_id'] !== '') {
        $erroresRango[] = 'Selecciona un año escolar antes de elegir la sección.';
    }

    if ($buscarRango) {
        if ($rangoAñoId === null || $rangoSeccionId === null) {
            $erroresRango[] = 'Selecciona un año escolar y una sección para buscar.';
        }
        if (!esFechaAsistenciaValida($fechaInicio) || !esFechaAsistenciaValida($fechaFin) ||
            $fechaInicio > $fechaFin) {
            $erroresRango[] = 'Indica un rango de fechas válido; la fecha inicial no puede ser posterior a la final.';
        }

        if ($erroresRango === []) {
            $registrosRango = obtenerAsistenciasAdminEnRango(
                $pdo,
                $rangoAñoId,
                $rangoSeccionId,
                $fechaInicio,
                $fechaFin
            );
        }
    }
}

$nombresEstados = [
    'presente' => 'Presente',
    'ausente' => 'Ausente',
    'justificado' => 'Justificado',
];
?>
<?php include '../../includes/header.php'; ?>
<?php include '../../includes/sidebar_admin.php'; ?>

<main class="content-wrapper">
    <div class="container-fluid py-4">
        <h2 class="mb-4"><i class="fas fa-clipboard-check me-2"></i>Registrar y editar asistencias</h2>

        <?php if ($tablasFaltantes !== []): ?>
            <div class="alert alert-danger" role="alert">
                Faltan tablas requeridas para registrar asistencias:
                <?php echo htmlspecialchars(implode(', ', $tablasFaltantes), ENT_QUOTES, 'UTF-8'); ?>.
            </div>
        <?php endif; ?>

        <?php if ($mensaje !== ''): ?>
            <div class="alert alert-<?php echo htmlspecialchars($tipoMensaje, ENT_QUOTES, 'UTF-8'); ?>" role="alert">
                <?php echo htmlspecialchars($mensaje, ENT_QUOTES, 'UTF-8'); ?>
            </div>
        <?php endif; ?>

        <?php foreach ($errores as $error): ?>
            <div class="alert alert-danger" role="alert">
                <?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?>
            </div>
        <?php endforeach; ?>

        <?php if ($tablasFaltantes === []): ?>
            <form method="get" class="card mb-4">
                <div class="card-body row g-3 align-items-end">
                    <div class="col-md-4">
                        <label for="año_escolar_id" class="form-label">Año escolar</label>
                        <select class="form-select" id="año_escolar_id" name="año_escolar_id" required
                                onchange="this.form.elements['seccion_id'].value = ''; this.form.submit()">
                            <option value="">Seleccionar año...</option>
                            <?php foreach ($añosEscolares as $año): ?>
                                <option value="<?php echo (int) $año['id']; ?>"
                                    <?php echo $añoId === (int) $año['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($año['nombre'], ENT_QUOTES, 'UTF-8'); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label for="seccion_id" class="form-label">Sección</label>
                        <select class="form-select" id="seccion_id" name="seccion_id"
                                <?php echo $añoId === null ? 'disabled' : ''; ?> required>
                            <option value="">Seleccionar sección...</option>
                            <?php foreach ($secciones as $seccion): ?>
                                <option value="<?php echo (int) $seccion['id']; ?>"
                                    <?php echo $seccionId === (int) $seccion['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($seccion['nombre'], ENT_QUOTES, 'UTF-8'); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label for="fecha" class="form-label">Fecha</label>
                        <input type="date" class="form-control" id="fecha" name="fecha"
                               value="<?php echo htmlspecialchars($fecha, ENT_QUOTES, 'UTF-8'); ?>" required>
                    </div>
                    <div class="col-12">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-list me-1"></i>Cargar estudiantes
                        </button>
                        <a class="btn btn-outline-secondary" href="gestionar.php">Limpiar</a>
                    </div>
                </div>
            </form>

            <?php if ($listaAsistencia !== null && $listaAsistencia === []): ?>
                <div class="alert alert-info" role="status">
                    No hay estudiantes activos inscritos en esa sección y año escolar.
                </div>
            <?php elseif ($listaAsistencia !== null): ?>
                <?php
                $conteos = ['presente' => 0, 'ausente' => 0, 'justificado' => 0, 'sin_registro' => 0];
                foreach ($listaAsistencia as $estudiante) {
                    $estado = $estudiante['estado'] ?? '';
                    if (isset($conteos[$estado])) {
                        $conteos[$estado]++;
                    } else {
                        $conteos['sin_registro']++;
                    }
                }
                ?>
                <div class="row g-3 mb-4">
                    <?php foreach ([
                        'presente' => ['Presente', 'success'],
                        'ausente' => ['Ausente', 'danger'],
                        'justificado' => ['Justificado', 'info'],
                        'sin_registro' => ['Sin registrar', 'secondary'],
                    ] as $estado => $resumen): ?>
                        <div class="col-6 col-lg-3">
                            <div class="card border-start border-4 border-<?php echo $resumen[1]; ?>">
                                <div class="card-body py-2">
                                    <strong><?php echo $resumen[0]; ?>:</strong>
                                    <?php echo $conteos[$estado]; ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <form method="post" class="card">
                    <input type="hidden" name="csrf_token"
                           value="<?php echo htmlspecialchars($_SESSION['csrf_admin_asistencias'], ENT_QUOTES, 'UTF-8'); ?>">
                    <input type="hidden" name="accion" value="guardar_diario">
                    <input type="hidden" name="año_escolar_id" value="<?php echo $añoId; ?>">
                    <input type="hidden" name="seccion_id" value="<?php echo $seccionId; ?>">
                    <input type="hidden" name="fecha" value="<?php echo htmlspecialchars($fecha, ENT_QUOTES, 'UTF-8'); ?>">
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-striped align-middle">
                                <thead>
                                    <tr>
                                        <th>#</th>
                                        <th>Estudiante</th>
                                        <th>Cédula escolar</th>
                                        <th>Asistencia</th>
                                        <th>Observaciones</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($listaAsistencia as $indice => $estudiante): ?>
                                        <?php $inscripcionId = (int) $estudiante['inscripcion_id']; ?>
                                        <tr>
                                            <td><?php echo $indice + 1; ?></td>
                                            <td><?php echo htmlspecialchars(
                                                $estudiante['apellido'] . ', ' . $estudiante['nombre'],
                                                ENT_QUOTES,
                                                'UTF-8'
                                            ); ?></td>
                                            <td><?php echo htmlspecialchars(
                                                $estudiante['cedula_escolar'] ?: '—',
                                                ENT_QUOTES,
                                                'UTF-8'
                                            ); ?></td>
                                            <td>
                                                <select class="form-select" name="asistencias[<?php echo $inscripcionId; ?>]">
                                                    <option value="">Sin registrar / quitar registro</option>
                                                    <?php foreach ($nombresEstados as $valor => $nombre): ?>
                                                        <option value="<?php echo $valor; ?>"
                                                            <?php echo ($estudiante['estado'] ?? '') === $valor ? 'selected' : ''; ?>>
                                                            <?php echo $nombre; ?>
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </td>
                                            <td>
                                                <input class="form-control" type="text"
                                                       name="observaciones[<?php echo $inscripcionId; ?>]"
                                                       value="<?php echo htmlspecialchars(
                                                           $estudiante['observaciones'] ?? '',
                                                           ENT_QUOTES,
                                                           'UTF-8'
                                                       ); ?>"
                                                       maxlength="1000">
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <button type="submit" class="btn btn-success">
                            <i class="fas fa-save me-1"></i>Guardar asistencias
                        </button>
                    </div>
                </form>
            <?php endif; ?>

            <section id="buscar-rango" class="mt-5">
                <h3 class="h4 mb-3">Buscar y editar asistencias por rango de fechas</h3>
                <form method="get" class="card mb-4">
                    <div class="card-body row g-3 align-items-end">
                        <div class="col-md-3">
                            <label for="rango_año_escolar_id" class="form-label">Año escolar</label>
                            <select class="form-select" id="rango_año_escolar_id"
                                    name="rango_año_escolar_id" required
                                    onchange="this.form.elements['rango_seccion_id'].value = ''; this.form.submit()">
                                <option value="">Seleccionar año...</option>
                                <?php foreach ($añosEscolares as $año): ?>
                                    <option value="<?php echo (int) $año['id']; ?>"
                                        <?php echo $rangoAñoId === (int) $año['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($año['nombre'], ENT_QUOTES, 'UTF-8'); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label for="rango_seccion_id" class="form-label">Sección</label>
                            <select class="form-select" id="rango_seccion_id" name="rango_seccion_id"
                                    <?php echo $rangoAñoId === null ? 'disabled' : ''; ?> required>
                                <option value="">Seleccionar sección...</option>
                                <?php foreach ($seccionesRango as $seccion): ?>
                                    <option value="<?php echo (int) $seccion['id']; ?>"
                                        <?php echo $rangoSeccionId === (int) $seccion['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($seccion['nombre'], ENT_QUOTES, 'UTF-8'); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label for="fecha_inicio" class="form-label">Desde</label>
                            <input type="date" class="form-control" id="fecha_inicio" name="fecha_inicio"
                                   value="<?php echo htmlspecialchars($fechaInicio, ENT_QUOTES, 'UTF-8'); ?>" required>
                        </div>
                        <div class="col-md-2">
                            <label for="fecha_fin" class="form-label">Hasta</label>
                            <input type="date" class="form-control" id="fecha_fin" name="fecha_fin"
                                   value="<?php echo htmlspecialchars($fechaFin, ENT_QUOTES, 'UTF-8'); ?>" required>
                        </div>
                        <div class="col-md-2">
                            <button type="submit" name="buscar_rango" value="1" class="btn btn-primary w-100">
                                <i class="fas fa-search me-1"></i>Buscar
                            </button>
                        </div>
                    </div>
                </form>

                <?php foreach ($erroresRango as $error): ?>
                    <div class="alert alert-danger" role="alert">
                        <?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?>
                    </div>
                <?php endforeach; ?>

                <?php if ($registrosRango !== null && $registrosRango === []): ?>
                    <div class="alert alert-info" role="status">
                        No hay asistencias registradas en ese rango para la sección seleccionada.
                    </div>
                <?php elseif ($registrosRango !== null): ?>
                    <p class="text-muted">
                        Se encontraron <?php echo count($registrosRango); ?> registro(s).
                        Puedes editar el estado, las observaciones o seleccionar “Sin registrar” para quitarlo.
                    </p>
                    <form method="post" class="card">
                        <input type="hidden" name="csrf_token"
                               value="<?php echo htmlspecialchars($_SESSION['csrf_admin_asistencias'], ENT_QUOTES, 'UTF-8'); ?>">
                        <input type="hidden" name="accion" value="guardar_rango">
                        <input type="hidden" name="rango_año_escolar_id" value="<?php echo $rangoAñoId; ?>">
                        <input type="hidden" name="rango_seccion_id" value="<?php echo $rangoSeccionId; ?>">
                        <input type="hidden" name="fecha_inicio"
                               value="<?php echo htmlspecialchars($fechaInicio, ENT_QUOTES, 'UTF-8'); ?>">
                        <input type="hidden" name="fecha_fin"
                               value="<?php echo htmlspecialchars($fechaFin, ENT_QUOTES, 'UTF-8'); ?>">
                        <div class="card-body">
                            <div class="table-responsive">
                                <table class="table table-striped align-middle">
                                    <thead>
                                        <tr>
                                            <th>Fecha</th>
                                            <th>Estudiante</th>
                                            <th>Cédula escolar</th>
                                            <th>Asistencia</th>
                                            <th>Observaciones</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($registrosRango as $registro): ?>
                                            <?php
                                            $inscripcionId = (int) $registro['inscripcion_id'];
                                            $fechaRegistro = $registro['fecha'];
                                            ?>
                                            <tr>
                                                <td><?php echo htmlspecialchars($fechaRegistro, ENT_QUOTES, 'UTF-8'); ?></td>
                                                <td><?php echo htmlspecialchars(
                                                    $registro['apellido'] . ', ' . $registro['nombre'],
                                                    ENT_QUOTES,
                                                    'UTF-8'
                                                ); ?></td>
                                                <td><?php echo htmlspecialchars(
                                                    $registro['cedula_escolar'] ?: '—',
                                                    ENT_QUOTES,
                                                    'UTF-8'
                                                ); ?></td>
                                                <td>
                                                    <select class="form-select"
                                                            name="asistencias[<?php echo $inscripcionId; ?>][<?php echo htmlspecialchars($fechaRegistro, ENT_QUOTES, 'UTF-8'); ?>]">
                                                        <option value="">Sin registrar / quitar registro</option>
                                                        <?php foreach ($nombresEstados as $valor => $nombre): ?>
                                                            <option value="<?php echo $valor; ?>"
                                                                <?php echo $registro['estado'] === $valor ? 'selected' : ''; ?>>
                                                                <?php echo $nombre; ?>
                                                            </option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                </td>
                                                <td>
                                                    <input class="form-control" type="text"
                                                           name="observaciones[<?php echo $inscripcionId; ?>][<?php echo htmlspecialchars($fechaRegistro, ENT_QUOTES, 'UTF-8'); ?>]"
                                                           value="<?php echo htmlspecialchars(
                                                               $registro['observaciones'] ?? '',
                                                               ENT_QUOTES,
                                                               'UTF-8'
                                                           ); ?>"
                                                           maxlength="1000">
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                            <button type="submit" class="btn btn-success">
                                <i class="fas fa-save me-1"></i>Guardar cambios del rango
                            </button>
                        </div>
                    </form>
                <?php endif; ?>
            </section>
        <?php endif; ?>
    </div>
</main>

<?php include '../../includes/footer.php'; ?>
