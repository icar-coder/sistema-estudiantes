<?php
require_once '../../includes/auth.php';
require_once '../../includes/database.php';
require_once '../../includes/functions_plan_anual.php';
checkAuth();
checkRole(['admin']);

planAnualMigrarCatalogosExistentes($pdo);

$mensaje = '';
$tipoMensaje = '';
$estudianteId = isset($_GET['estudiante_id']) ? (int) $_GET['estudiante_id'] : 0;
$anioId = isset($_GET['año_escolar_id']) ? (int) $_GET['año_escolar_id'] : 0;
$gradoId = isset($_GET['grado_id']) ? (int) $_GET['grado_id'] : 0;
$momento = isset($_GET['momento']) ? (int) $_GET['momento'] : 1;
$momento = in_array($momento, [1, 2, 3], true) ? $momento : 1;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $estudianteId = (int) ($_POST['estudiante_id'] ?? 0);
    $anioId = (int) ($_POST['año_escolar_id'] ?? 0);
    $gradoId = (int) ($_POST['grado_id'] ?? 0);
    $momento = (int) ($_POST['momento'] ?? 1);
    $literalFinal = trim((string) ($_POST['literal_final'] ?? ''));
    $observaciones = trim((string) ($_POST['observaciones'] ?? ''));
    $proyecto = trim((string) ($_POST['proyecto'] ?? ''));
    $rasgos = trim((string) ($_POST['rasgos'] ?? ''));
    $observacionesMomento = trim((string) ($_POST['observaciones_momento'] ?? ''));
    $diasLaborados = trim((string) ($_POST['dias_laborados'] ?? ''));
    $asistencias = trim((string) ($_POST['asistencias'] ?? ''));
    $inasistencias = trim((string) ($_POST['inasistencias'] ?? ''));
    $indicadores = $_POST['indicadores'] ?? [];

    if ($estudianteId < 1 || $anioId < 1 || $gradoId < 1 ||
        !in_array($literalFinal, ['A', 'B', 'C', 'D', 'E'], true) ||
        !in_array($momento, [1, 2, 3], true) ||
        !is_array($indicadores)) {
        $mensaje = 'Complete el estudiante, año, grado, literal y momento.';
        $tipoMensaje = 'danger';
    } else {
        try {
            $pdo->beginTransaction();

            $indicadoresPermitidos = [];
            foreach (planAnualObtenerAreas($pdo, $anioId) as $areaPermitida) {
                foreach (planAnualObtenerIndicadores($pdo, $anioId, (int) $areaPermitida['id']) as $indicadorPermitido) {
                    $indicadoresPermitidos[(int) $indicadorPermitido['id']] = true;
                }
            }
            foreach ($indicadores as $indicadorId => $valoracion) {
                if (trim((string) $valoracion) === '') {
                    continue;
                }
                if (!ctype_digit((string) $indicadorId) ||
                    !isset($indicadoresPermitidos[(int) $indicadorId]) ||
                    !in_array(strtoupper(trim((string) $valoracion)), ['LE', 'L', 'PA', 'IP', 'NL'], true)) {
                    throw new InvalidArgumentException(
                        'La evaluación contiene indicadores que no pertenecen al plan de ese año.'
                    );
                }
            }

            $stmt = $pdo->prepare(
                'SELECT id FROM historial_academico
                 WHERE estudiante_id = ? AND año_escolar_id = ?
                 ORDER BY id DESC LIMIT 1'
            );
            $stmt->execute([$estudianteId, $anioId]);
            $historialId = (int) $stmt->fetchColumn();

            if ($historialId > 0) {
                $stmt = $pdo->prepare(
                    'UPDATE historial_academico
                     SET grado_id = ?, literal_final = ?, observaciones = ?
                     WHERE id = ?'
                );
                $stmt->execute([$gradoId, $literalFinal, $observaciones, $historialId]);
            } else {
                $stmt = $pdo->prepare(
                    'INSERT INTO historial_academico
                     (estudiante_id, año_escolar_id, grado_id, literal_final, tipo_adelantamiento, observaciones)
                     VALUES (?, ?, ?, ?, ?, ?)'
                );
                $stmt->execute([
                    $estudianteId,
                    $anioId,
                    $gradoId,
                    $literalFinal,
                    'academico',
                    $observaciones,
                ]);
                $historialId = (int) $pdo->lastInsertId();
            }

            $tieneMomento = databaseTableExists($pdo, 'historial_indicadores') &&
                databaseColumnExists($pdo, 'historial_indicadores', 'momento');

            if ($tieneMomento) {
                $stmt = $pdo->prepare(
                    'SELECT id FROM historial_indicadores
                     WHERE historial_id = ? AND momento = ?'
                );
                $stmt->execute([$historialId, $momento]);
                $stmt = $pdo->prepare(
                    'DELETE FROM historial_indicadores
                     WHERE historial_id = ? AND momento = ?'
                );
                $stmt->execute([$historialId, $momento]);
            } else {
                $stmt = $pdo->prepare('DELETE FROM historial_indicadores WHERE historial_id = ?');
                $stmt->execute([$historialId]);
            }

            foreach ($indicadores as $indicadorId => $valoracion) {
                $valoracion = strtoupper(trim((string) $valoracion));
                if (!preg_match('/^\d+$/', (string) $indicadorId) ||
                    !in_array($valoracion, ['LE', 'L', 'PA', 'IP', 'NL'], true)) {
                    continue;
                }
                if ($tieneMomento) {
                    $stmt = $pdo->prepare(
                        'INSERT INTO historial_indicadores
                         (historial_id, indicador_id, momento, valoracion)
                         VALUES (?, ?, ?, ?)'
                    );
                    $stmt->execute([$historialId, (int) $indicadorId, $momento, $valoracion]);
                } else {
                    $stmt = $pdo->prepare(
                        'INSERT INTO historial_indicadores
                         (historial_id, indicador_id, valoracion)
                         VALUES (?, ?, ?)'
                    );
                    $stmt->execute([$historialId, (int) $indicadorId, $valoracion]);
                }
            }

            if (databaseTableExists($pdo, 'historial_momentos')) {
                $diasLaborados = $diasLaborados === '' ? null : (int) $diasLaborados;
                $asistencias = $asistencias === '' ? null : (int) $asistencias;
                $inasistencias = $inasistencias === '' ? null : (int) $inasistencias;
                $stmt = $pdo->prepare(
                    'INSERT INTO historial_momentos
                     (historial_id, momento, proyecto, rasgos, observaciones,
                      dias_laborados, asistencias, inasistencias)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                     ON DUPLICATE KEY UPDATE
                      proyecto = VALUES(proyecto),
                      rasgos = VALUES(rasgos),
                      observaciones = VALUES(observaciones),
                      dias_laborados = VALUES(dias_laborados),
                      asistencias = VALUES(asistencias),
                      inasistencias = VALUES(inasistencias)'
                );
                $stmt->execute([
                    $historialId,
                    $momento,
                    $proyecto,
                    $rasgos,
                    $observacionesMomento,
                    $diasLaborados,
                    $asistencias,
                    $inasistencias,
                ]);
            }

            $pdo->commit();
            $mensaje = 'Historial e indicadores guardados correctamente.';
            $tipoMensaje = 'success';
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('Error cargando historial académico: ' . $e->getMessage());
            $mensaje = 'No se pudo guardar el historial académico.';
            $tipoMensaje = 'danger';
        }
    }
}

$estudiantes = $pdo->query(
    'SELECT id, cedula_escolar, nombre, apellido
     FROM estudiantes
     ORDER BY apellido, nombre'
)->fetchAll(PDO::FETCH_ASSOC);
$años = $pdo->query(
    'SELECT id, nombre FROM años_escolares ORDER BY nombre DESC'
)->fetchAll(PDO::FETCH_ASSOC);
$grados = $pdo->query(
    'SELECT id, nombre, numero FROM grados ORDER BY orden, numero'
)->fetchAll(PDO::FETCH_ASSOC);

$areas = $anioId > 0 ? planAnualObtenerAreas($pdo, $anioId) : [];
$indicadoresPorArea = [];
foreach ($areas as $area) {
    $indicadoresPorArea[(int) $area['id']] = planAnualObtenerIndicadores(
        $pdo,
        $anioId,
        (int) $area['id']
    );
}

$historialExistente = null;
$indicadoresExistentes = [];
$momentoExistente = null;
if ($estudianteId > 0 && $anioId > 0) {
    $stmt = $pdo->prepare(
        'SELECT * FROM historial_academico
         WHERE estudiante_id = ? AND año_escolar_id = ?
         ORDER BY id DESC LIMIT 1'
    );
    $stmt->execute([$estudianteId, $anioId]);
    $historialExistente = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

    if ($historialExistente) {
        $gradoId = $gradoId ?: (int) $historialExistente['grado_id'];
        $tieneMomento = databaseTableExists($pdo, 'historial_indicadores') &&
            databaseColumnExists($pdo, 'historial_indicadores', 'momento');
        if ($tieneMomento) {
            $stmt = $pdo->prepare(
                'SELECT indicador_id, valoracion
                 FROM historial_indicadores
                 WHERE historial_id = ? AND momento = ?'
            );
            $stmt->execute([(int) $historialExistente['id'], $momento]);
        } else {
            $stmt = $pdo->prepare(
                'SELECT indicador_id, valoracion
                 FROM historial_indicadores
                 WHERE historial_id = ?'
            );
            $stmt->execute([(int) $historialExistente['id']]);
        }
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $fila) {
            $indicadoresExistentes[(int) $fila['indicador_id']] = $fila['valoracion'];
        }

        if (databaseTableExists($pdo, 'historial_momentos')) {
            $stmt = $pdo->prepare(
                'SELECT * FROM historial_momentos
                 WHERE historial_id = ? AND momento = ? LIMIT 1'
            );
            $stmt->execute([(int) $historialExistente['id'], $momento]);
            $momentoExistente = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        }
    }
}

function escaparHistorial($valor): string
{
    return htmlspecialchars((string) $valor, ENT_QUOTES, 'UTF-8');
}
?>
<?php include '../../includes/header.php'; ?>
<?php include '../../includes/sidebar_admin.php'; ?>

<main class="content-wrapper">
    <div class="container-fluid py-4">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h2 class="mb-1"><i class="fas fa-history me-2"></i>Cargar historial e indicadores</h2>
                <p class="text-muted mb-0">Carga datos académicos de años anteriores o momentos históricos.</p>
            </div>
            <a href="gestionar.php" class="btn btn-outline-secondary">
                <i class="fas fa-arrow-left me-1"></i>Volver
            </a>
        </div>

        <?php if ($mensaje !== ''): ?>
            <div class="alert alert-<?php echo $tipoMensaje; ?>" role="alert">
                <?php echo escaparHistorial($mensaje); ?>
            </div>
        <?php endif; ?>

        <form method="get" class="card mb-4">
            <div class="card-header bg-light"><strong>Datos a cargar</strong></div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-lg-4">
                        <label for="estudiante_id" class="form-label">Estudiante</label>
                        <select class="form-select" id="estudiante_id" name="estudiante_id" required>
                            <option value="">Seleccionar estudiante</option>
                            <?php foreach ($estudiantes as $estudiante): ?>
                                <option value="<?php echo (int) $estudiante['id']; ?>"
                                    <?php echo $estudianteId === (int) $estudiante['id'] ? 'selected' : ''; ?>>
                                    <?php echo escaparHistorial($estudiante['cedula_escolar'] . ' - ' . $estudiante['apellido'] . ', ' . $estudiante['nombre']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-lg-3">
                        <label for="año_escolar_id" class="form-label">Año escolar</label>
                        <select class="form-select" id="año_escolar_id" name="año_escolar_id" required>
                            <option value="">Seleccionar año</option>
                            <?php foreach ($años as $anio): ?>
                                <option value="<?php echo (int) $anio['id']; ?>"
                                    <?php echo $anioId === (int) $anio['id'] ? 'selected' : ''; ?>>
                                    <?php echo escaparHistorial($anio['nombre']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-lg-3">
                        <label for="grado_id" class="form-label">Grado cursado</label>
                        <select class="form-select" id="grado_id" name="grado_id" required>
                            <option value="">Seleccionar grado</option>
                            <?php foreach ($grados as $grado): ?>
                                <option value="<?php echo (int) $grado['id']; ?>"
                                    <?php echo $gradoId === (int) $grado['id'] ? 'selected' : ''; ?>>
                                    <?php echo escaparHistorial($grado['nombre']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-lg-2">
                        <label for="momento" class="form-label">Momento</label>
                        <select class="form-select" id="momento" name="momento">
                            <?php foreach ([1, 2, 3] as $numeroMomento): ?>
                                <option value="<?php echo $numeroMomento; ?>" <?php echo $momento === $numeroMomento ? 'selected' : ''; ?>>
                                    Momento <?php echo $numeroMomento; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <button type="submit" class="btn btn-primary mt-3">
                    <i class="fas fa-search me-1"></i>Cargar formulario
                </button>
            </div>
        </form>

        <?php if ($estudianteId > 0 && $anioId > 0 && $gradoId > 0): ?>
            <form method="post" class="card">
                <input type="hidden" name="estudiante_id" value="<?php echo $estudianteId; ?>">
                <input type="hidden" name="año_escolar_id" value="<?php echo $anioId; ?>">
                <input type="hidden" name="grado_id" value="<?php echo $gradoId; ?>">
                <input type="hidden" name="momento" value="<?php echo $momento; ?>">

                <div class="card-header bg-primary text-white">
                    <strong>Evaluación del momento <?php echo $momento; ?></strong>
                </div>
                <div class="card-body">
                    <div class="row g-3 mb-3">
                        <div class="col-md-2">
                            <label for="literal_final" class="form-label">Literal final del año</label>
                            <select class="form-select" id="literal_final" name="literal_final" required>
                                <option value="">Seleccionar</option>
                                <?php foreach (['A', 'B', 'C', 'D', 'E'] as $literal): ?>
                                    <option value="<?php echo $literal; ?>"
                                        <?php echo ($historialExistente['literal_final'] ?? '') === $literal ? 'selected' : ''; ?>>
                                        <?php echo $literal; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-10">
                            <label for="observaciones" class="form-label">Observaciones del año</label>
                            <textarea class="form-control" id="observaciones" name="observaciones" rows="2"><?php echo escaparHistorial($historialExistente['observaciones'] ?? ''); ?></textarea>
                        </div>
                    </div>

                    <div class="row g-3 mb-4">
                        <div class="col-md-6">
                            <label for="proyecto" class="form-label">Proyecto</label>
                            <input type="text" class="form-control" id="proyecto" name="proyecto"
                                   value="<?php echo escaparHistorial($momentoExistente['proyecto'] ?? ''); ?>">
                        </div>
                        <div class="col-md-6">
                            <label for="rasgos" class="form-label">Rasgos</label>
                            <input type="text" class="form-control" id="rasgos" name="rasgos"
                                   value="<?php echo escaparHistorial($momentoExistente['rasgos'] ?? ''); ?>">
                        </div>
                        <div class="col-md-8">
                            <label for="observaciones_momento" class="form-label">Observaciones del momento</label>
                            <textarea class="form-control" id="observaciones_momento" name="observaciones_momento" rows="2"><?php echo escaparHistorial($momentoExistente['observaciones'] ?? ''); ?></textarea>
                        </div>
                        <div class="col-md-4">
                            <div class="row g-2">
                                <div class="col-4">
                                    <label for="dias_laborados" class="form-label">Laborados</label>
                                    <input type="number" min="0" class="form-control" id="dias_laborados" name="dias_laborados"
                                           value="<?php echo escaparHistorial($momentoExistente['dias_laborados'] ?? ''); ?>">
                                </div>
                                <div class="col-4">
                                    <label for="asistencias" class="form-label">Asistencias</label>
                                    <input type="number" min="0" class="form-control" id="asistencias" name="asistencias"
                                           value="<?php echo escaparHistorial($momentoExistente['asistencias'] ?? ''); ?>">
                                </div>
                                <div class="col-4">
                                    <label for="inasistencias" class="form-label">Inasistencias</label>
                                    <input type="number" min="0" class="form-control" id="inasistencias" name="inasistencias"
                                           value="<?php echo escaparHistorial($momentoExistente['inasistencias'] ?? ''); ?>">
                                </div>
                            </div>
                        </div>
                    </div>

                    <?php
                    $indicadoresParaOcr = [];
                    foreach ($areas as $areaOcr) {
                        foreach ($indicadoresPorArea[(int) $areaOcr['id']] ?? [] as $indicadorOcr) {
                            $indicadoresParaOcr[] = [
                                'id' => (int) $indicadorOcr['id'],
                                'text' => trim((string) ($indicadorOcr['descripcion'] ?: $indicadorOcr['nombre'])),
                            ];
                        }
                    }
                    ?>
                    <section
                        id="historial-ocr"
                        class="card border-info mb-4"
                        data-worker-url="<?php echo escaparHistorial(app_url('js/ocr/assets/worker.min.js')); ?>"
                        data-core-url="<?php echo escaparHistorial(app_url('js/ocr/assets/tesseract-core-simd-lstm.wasm.js')); ?>"
                        data-language-url="<?php echo escaparHistorial(app_url('js/ocr/assets/')); ?>"
                        data-indicators="<?php echo escaparHistorial(json_encode($indicadoresParaOcr, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE)); ?>"
                    >
                        <div class="card-header bg-info-subtle">
                            <strong><i class="fas fa-file-image me-2"></i>Lectura local de la tabla de indicadores</strong>
                        </div>
                        <div class="card-body">
                            <p class="small text-muted">
                                Las imágenes se procesan en este equipo y no se envían a Gemini. El OCR buscará las X
                                bajo las columnas LE, L, PA, IP y NL; revisa las sugerencias antes de aplicarlas.
                            </p>
                            <div class="row g-3 align-items-end">
                                <div class="col-lg-6">
                                    <label for="historial-ocr-files" class="form-label">Fotos o páginas del registro</label>
                                    <input type="file" id="historial-ocr-files" class="form-control"
                                           accept="image/jpeg,image/png,image/webp" multiple>
                                    <div class="form-text">Hasta 5 imágenes JPG, PNG o WebP; máximo 12 MB cada una.</div>
                                </div>
                                <div class="col-lg-3">
                                    <label for="historial-ocr-rotation" class="form-label">Rotación</label>
                                    <select id="historial-ocr-rotation" class="form-select">
                                        <option value="0">Sin rotar</option>
                                        <option value="90">90° a la derecha</option>
                                        <option value="180">180°</option>
                                        <option value="270">90° a la izquierda</option>
                                    </select>
                                </div>
                                <div class="col-lg-3">
                                    <button type="button" class="btn btn-outline-primary w-100" id="historial-ocr-read">
                                        <i class="fas fa-search me-1"></i>Analizar localmente
                                    </button>
                                </div>
                            </div>
                            <progress id="historial-ocr-progress" class="w-100 mt-3" max="100" value="0"></progress>
                            <p id="historial-ocr-status" class="small mt-2 mb-0" role="status" aria-live="polite"></p>
                            <div id="historial-ocr-results" class="mt-3" hidden>
                                <div class="d-flex justify-content-between align-items-center gap-2 flex-wrap mb-2">
                                    <strong>Sugerencias detectadas</strong>
                                    <button type="button" class="btn btn-sm btn-success" id="historial-ocr-apply" disabled>
                                        Aplicar sugerencias
                                    </button>
                                </div>
                                <div class="table-responsive">
                                    <table class="table table-sm table-bordered align-middle mb-0">
                                        <thead class="table-light">
                                            <tr><th>Indicador del plan</th><th>Valoración detectada</th><th>Coincidencia aproximada</th></tr>
                                        </thead>
                                        <tbody id="historial-ocr-suggestions"></tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </section>

                    <?php foreach ($areas as $area): ?>
                        <?php $indicadores = $indicadoresPorArea[(int) $area['id']] ?? []; ?>
                        <?php if ($indicadores !== []): ?>
                            <div class="card mb-3">
                                <div class="card-header">
                                    <strong><?php echo escaparHistorial($area['nombre']); ?></strong>
                                </div>
                                <div class="table-responsive">
                                    <table class="table table-bordered table-sm align-middle mb-0">
                                        <thead class="table-light">
                                            <tr>
                                                <th>Indicador</th>
                                                <th style="width: 160px;">Valoración</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($indicadores as $indicador): ?>
                                                <tr>
                                                    <td>
                                                        <?php echo escaparHistorial(trim((string) ($indicador['descripcion'] ?: $indicador['nombre']))); ?>
                                                    </td>
                                                    <td>
                                                        <select class="form-select form-select-sm"
                                                                name="indicadores[<?php echo (int) $indicador['id']; ?>]">
                                                            <option value="">Sin evaluación</option>
                                                            <?php foreach (['LE', 'L', 'PA', 'IP', 'NL'] as $valoracion): ?>
                                                                <option value="<?php echo $valoracion; ?>"
                                                                    <?php echo ($indicadoresExistentes[(int) $indicador['id']] ?? '') === $valoracion ? 'selected' : ''; ?>>
                                                                    <?php echo $valoracion; ?>
                                                                </option>
                                                            <?php endforeach; ?>
                                                        </select>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </div>
                <div class="card-footer d-flex justify-content-end">
                    <button type="submit" class="btn btn-success">
                        <i class="fas fa-save me-1"></i>Guardar historial
                    </button>
                </div>
            </form>
        <?php endif; ?>
    </div>
</main>

<script id="historial-ocr-library" src="<?php echo escaparHistorial(app_url('js/ocr/assets/tesseract.min.js')); ?>"></script>
<script src="<?php echo escaparHistorial(app_url('js/ocr/historial_indicadores.js')); ?>"></script>

<?php include '../../includes/footer.php'; ?>
