<?php
require_once '../../includes/auth.php';
require_once '../../includes/database.php';
require_once '../../includes/database_helpers.php';
require_once '../../includes/functions_reportes.php';
require_once '../../includes/functions_evaluacion.php';

checkAuth();
checkRole(['admin']);

$tablasRequeridas = [
    'años_escolares',
    'lapsos',
    'secciones',
    'estudiantes',
    'inscripciones',
    'literales_lapso',
    'literales_finales',
];
$tablasFaltantes = [];
foreach ($tablasRequeridas as $tabla) {
    if (!databaseTableExists($pdo, $tabla)) {
        $tablasFaltantes[] = $tabla;
    }
}

$errores = [];
$añosEscolares = [];
$secciones = [];
$lapsosDisponibles = [];
$datosReporte = null;
$añoEscolarId = null;
$lapsoId = null;
$seccionId = null;
$busqueda = '';
$añoEscolarNombre = '';

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
            $añoEscolarId = (int) $valor;
            $añoEncontrado = false;
            foreach ($añosEscolares as $año) {
                if ((int) $año['id'] === $añoEscolarId) {
                    $añoEscolarNombre = $año['nombre'];
                    $añoEncontrado = true;
                    break;
                }
            }
            if (!$añoEncontrado) {
                $errores[] = 'El año escolar seleccionado no existe.';
                $añoEscolarId = null;
            }
        }
    }

    $lapsoRaw = $_GET['lapso_id'] ?? '';
    if (!is_string($lapsoRaw)) {
        $errores[] = 'El lapso seleccionado no es válido.';
    } elseif ($lapsoRaw !== '') {
        $valor = filter_var($lapsoRaw, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($valor === false) {
            $errores[] = 'El lapso seleccionado no es válido.';
        } else {
            $lapsoId = (int) $valor;
        }
    }

    $seccionRaw = $_GET['seccion_id'] ?? '';
    if (!is_string($seccionRaw)) {
        $errores[] = 'La sección seleccionada no es válida.';
    } elseif ($seccionRaw !== '') {
        $valor = filter_var($seccionRaw, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($valor === false) {
            $errores[] = 'La sección seleccionada no es válida.';
        } else {
            $seccionId = (int) $valor;
        }
    }

    $busquedaRaw = $_GET['busqueda'] ?? '';
    if (!is_string($busquedaRaw)) {
        $errores[] = 'El texto de búsqueda no es válido.';
    } else {
        $busqueda = trim($busquedaRaw);
        if (strlen($busqueda) > 120) {
            $errores[] = 'La búsqueda no puede superar 120 caracteres.';
        }
    }

    if ($añoEscolarId !== null) {
        $statement = $pdo->prepare(
            'SELECT `id`, `nombre` FROM `secciones`
             WHERE `año_escolar_id` = ? ORDER BY `nombre`, `id`'
        );
        $statement->execute([$añoEscolarId]);
        $secciones = $statement->fetchAll(PDO::FETCH_ASSOC);

        $statement = $pdo->prepare(
            'SELECT `id`, `nombre`, `numero` FROM `lapsos`
             WHERE `año_escolar_id` = ? ORDER BY `numero`, `id`'
        );
        $statement->execute([$añoEscolarId]);
        $lapsosDisponibles = $statement->fetchAll(PDO::FETCH_ASSOC);

        if ($seccionId !== null &&
            !in_array($seccionId, array_map('intval', array_column($secciones, 'id')), true)) {
            $errores[] = 'La sección no pertenece al año escolar seleccionado.';
            $seccionId = null;
        }
        if ($lapsoId !== null &&
            !in_array($lapsoId, array_map('intval', array_column($lapsosDisponibles, 'id')), true)) {
            $errores[] = 'El lapso no pertenece al año escolar seleccionado.';
            $lapsoId = null;
        }

        if ($errores === []) {
            $datosReporte = obtenerReporteLiterales(
                $pdo,
                $añoEscolarId,
                $lapsoId,
                $seccionId,
                $busqueda
            );
        }
    }
}

$coloresLiterales = [
    'A' => 'success',
    'B' => 'info',
    'C' => 'primary',
    'D' => 'warning',
    'E' => 'danger',
];

function escaparReporteLiterales($valor): string
{
    return htmlspecialchars((string) $valor, ENT_QUOTES, 'UTF-8');
}
?>
<?php include '../../includes/header.php'; ?>
<?php include '../../includes/sidebar_admin.php'; ?>

<main class="content-wrapper">
    <div class="container-fluid py-4">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-3 mb-4 no-print">
            <div>
                <h2 class="mb-1"><i class="fas fa-list-alt me-2"></i>Estudiantes y evaluación por literales</h2>
                <p class="text-muted mb-0">Consulta las evaluaciones por año escolar, lapso, sección o estudiante.</p>
            </div>
            <?php if ($datosReporte !== null): ?>
                <button type="button" class="btn btn-primary" onclick="window.print()">
                    <i class="fas fa-print me-2"></i>Imprimir / Guardar como PDF
                </button>
            <?php endif; ?>
        </div>

        <?php if ($tablasFaltantes !== []): ?>
            <div class="alert alert-danger" role="alert">
                No se puede generar el reporte: faltan tablas requeridas
                (<?php echo escaparReporteLiterales(implode(', ', $tablasFaltantes)); ?>).
            </div>
        <?php endif; ?>

        <?php foreach ($errores as $error): ?>
            <div class="alert alert-danger" role="alert"><?php echo escaparReporteLiterales($error); ?></div>
        <?php endforeach; ?>

        <?php if ($tablasFaltantes === []): ?>
            <form method="get" class="card mb-4 no-print">
                <div class="card-body">
                    <div class="row g-3 align-items-end">
                        <div class="col-md-3">
                            <label for="año_escolar_id" class="form-label">Año escolar</label>
                            <select class="form-select" id="año_escolar_id" name="año_escolar_id" required
                                    onchange="this.form.elements['lapso_id'].value = ''; this.form.elements['seccion_id'].value = ''; this.form.submit()">
                                <option value="">Seleccionar año</option>
                                <?php foreach ($añosEscolares as $año): ?>
                                    <option value="<?php echo (int) $año['id']; ?>"
                                        <?php echo $añoEscolarId === (int) $año['id'] ? 'selected' : ''; ?>>
                                        <?php echo escaparReporteLiterales($año['nombre']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label for="lapso_id" class="form-label">Lapso</label>
                            <select class="form-select" id="lapso_id" name="lapso_id"
                                    <?php echo $añoEscolarId === null ? 'disabled' : ''; ?>>
                                <option value="">Todos los lapsos</option>
                                <?php foreach ($lapsosDisponibles as $lapso): ?>
                                    <option value="<?php echo (int) $lapso['id']; ?>"
                                        <?php echo $lapsoId === (int) $lapso['id'] ? 'selected' : ''; ?>>
                                        <?php echo escaparReporteLiterales($lapso['nombre']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label for="seccion_id" class="form-label">Sección</label>
                            <select class="form-select" id="seccion_id" name="seccion_id"
                                    <?php echo $añoEscolarId === null ? 'disabled' : ''; ?>>
                                <option value="">Todas las secciones</option>
                                <?php foreach ($secciones as $seccion): ?>
                                    <option value="<?php echo (int) $seccion['id']; ?>"
                                        <?php echo $seccionId === (int) $seccion['id'] ? 'selected' : ''; ?>>
                                        <?php echo escaparReporteLiterales($seccion['nombre']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label for="busqueda" class="form-label">Estudiante</label>
                            <input class="form-control" type="search" id="busqueda" name="busqueda"
                                   maxlength="120" value="<?php echo escaparReporteLiterales($busqueda); ?>"
                                   placeholder="Nombre, apellido o cédula escolar"
                                   <?php echo $añoEscolarId === null ? 'disabled' : ''; ?>>
                        </div>
                        <div class="col-12 d-flex gap-2">
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-filter me-1"></i>Generar reporte
                            </button>
                            <a class="btn btn-outline-secondary" href="literales.php">Limpiar</a>
                        </div>
                    </div>
                </div>
            </form>
        <?php endif; ?>

        <?php if ($datosReporte !== null): ?>
            <?php
            $totalEvaluaciones = 0;
            foreach ($datosReporte['evaluaciones'] as $evaluacionesEstudiante) {
                $totalEvaluaciones += count($evaluacionesEstudiante);
            }
            ?>
            <section id="printable-report">
                <div class="mb-3">
                    <h2 class="h3 mb-1">Reporte de evaluación por literales</h2>
                    <div>
                        <strong>Año escolar:</strong> <?php echo escaparReporteLiterales($añoEscolarNombre); ?>
                        <?php if ($lapsoId !== null && $datosReporte['lapsos'] !== []): ?>
                            <span class="mx-2">|</span>
                            <strong>Lapso:</strong> <?php echo escaparReporteLiterales($datosReporte['lapsos'][0]['nombre']); ?>
                        <?php endif; ?>
                        <?php if ($seccionId !== null): ?>
                            <?php foreach ($secciones as $seccion): ?>
                                <?php if ((int) $seccion['id'] === $seccionId): ?>
                                    <span class="mx-2">|</span>
                                    <strong>Sección:</strong> <?php echo escaparReporteLiterales($seccion['nombre']); ?>
                                    <?php break; ?>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        <?php if ($busqueda !== ''): ?>
                            <span class="mx-2">|</span>
                            <strong>Búsqueda:</strong> <?php echo escaparReporteLiterales($busqueda); ?>
                        <?php endif; ?>
                    </div>
                    <small class="text-muted">Generado el <?php echo date('d/m/Y H:i'); ?></small>
                </div>

                <div class="row g-3 mb-3 report-summary">
                    <div class="col-sm-6">
                        <div class="border rounded p-3">
                            <strong>Estudiantes listados:</strong> <?php echo count($datosReporte['estudiantes']); ?>
                        </div>
                    </div>
                    <div class="col-sm-6">
                        <div class="border rounded p-3">
                            <strong>Literales de lapso registrados:</strong> <?php echo $totalEvaluaciones; ?>
                        </div>
                    </div>
                </div>

                <?php if ($datosReporte['estudiantes'] === []): ?>
                    <div class="alert alert-info" role="status">
                        No hay estudiantes que coincidan con los filtros seleccionados.
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-bordered table-striped align-middle">
                            <thead class="table-light">
                                <tr>
                                    <th>#</th>
                                    <th>Cédula escolar</th>
                                    <th>Estudiante</th>
                                    <th>Sección</th>
                                    <th>Estado</th>
                                    <?php foreach ($datosReporte['lapsos'] as $lapso): ?>
                                        <th><?php echo escaparReporteLiterales($lapso['nombre']); ?></th>
                                    <?php endforeach; ?>
                                    <th>Literal final</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($datosReporte['estudiantes'] as $indice => $estudiante): ?>
                                    <tr>
                                        <td><?php echo $indice + 1; ?></td>
                                        <td><?php echo escaparReporteLiterales($estudiante['cedula_escolar'] ?: '—'); ?></td>
                                        <td><?php echo escaparReporteLiterales(trim($estudiante['apellido'] . ', ' . $estudiante['nombre'])); ?></td>
                                        <td><?php echo escaparReporteLiterales($estudiante['seccion'] ?: '—'); ?></td>
                                        <td><?php
                                            $estados = [
                                                'activo' => 'Activo',
                                                'retirado' => 'Retirado',
                                                'graduado' => 'Graduado',
                                            ];
                                            echo escaparReporteLiterales($estados[$estudiante['status']] ?? '—');
                                        ?></td>
                                        <?php foreach ($datosReporte['lapsos'] as $lapso): ?>
                                            <?php
                                            $evaluacion = $datosReporte['evaluaciones'][(int) $estudiante['inscripcion_id']][(int) $lapso['id']] ?? null;
                                            ?>
                                            <td>
                                                <?php if ($evaluacion !== null): ?>
                                                    <span class="badge bg-<?php echo $coloresLiterales[$evaluacion['literal']] ?? 'secondary'; ?>">
                                                        <?php echo escaparReporteLiterales($evaluacion['literal']); ?>
                                                    </span>
                                                    <span><?php echo escaparReporteLiterales(literalATexto($evaluacion['literal'])); ?></span>
                                                    <?php if (!empty($evaluacion['observaciones'])): ?>
                                                        <small class="d-block"><?php echo nl2br(escaparReporteLiterales($evaluacion['observaciones'])); ?></small>
                                                    <?php endif; ?>
                                                    <?php if (!empty($evaluacion['fecha_evaluacion'])): ?>
                                                        <small class="d-block text-muted"><?php echo escaparReporteLiterales($evaluacion['fecha_evaluacion']); ?></small>
                                                    <?php endif; ?>
                                                <?php else: ?>
                                                    <span class="text-muted">Sin evaluar</span>
                                                <?php endif; ?>
                                            </td>
                                        <?php endforeach; ?>
                                        <td>
                                            <?php if (!empty($estudiante['literal_final'])): ?>
                                                <span class="badge bg-<?php echo $coloresLiterales[$estudiante['literal_final']] ?? 'secondary'; ?>">
                                                    <?php echo escaparReporteLiterales($estudiante['literal_final']); ?>
                                                </span>
                                                <span><?php echo escaparReporteLiterales(literalATexto($estudiante['literal_final'])); ?></span>
                                            <?php else: ?>
                                                <span class="text-muted">Sin evaluar</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </section>
        <?php elseif ($tablasFaltantes === [] && $añoEscolarId === null && $errores === []): ?>
            <div class="alert alert-info" role="status">
                Selecciona un año escolar para generar el reporte.
            </div>
        <?php endif; ?>
    </div>
</main>

<style>
@media print {
    @page {
        margin: 12mm;
    }
    body * {
        visibility: hidden !important;
    }
    #printable-report,
    #printable-report * {
        visibility: visible !important;
    }
    #printable-report {
        position: absolute;
        inset: 0;
        width: 100%;
        color: #000;
    }
    #printable-report .table-responsive {
        overflow: visible !important;
    }
    #printable-report table {
        width: 100%;
        font-size: 8pt;
    }
    #printable-report th,
    #printable-report td {
        padding: 0.25rem !important;
    }
}
</style>

<?php include '../../includes/footer.php'; ?>
