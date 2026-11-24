<?php
require_once '../../includes/auth.php';
require_once '../../includes/database.php';
require_once '../../includes/functions_plan_anual.php';
checkAuth();
checkRole(['admin']);

planAnualMigrarCatalogosExistentes($pdo);

$añoId = isset($_GET['año_escolar_id']) ? (int) $_GET['año_escolar_id'] : 0;
$mensaje = '';
$tipoMensaje = '';

if (empty($_SESSION['csrf_plan_anual'])) {
    $_SESSION['csrf_plan_anual'] = bin2hex(random_bytes(32));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $añoId = (int) ($_POST['año_escolar_id'] ?? 0);
    $accion = trim((string) ($_POST['accion'] ?? ''));
    $token = $_POST['csrf_token'] ?? '';
    if (!is_string($token) || !hash_equals($_SESSION['csrf_plan_anual'], $token)) {
        $mensaje = 'La solicitud venció o no es válida. Recargue la página e inténtelo de nuevo.';
        $tipoMensaje = 'danger';
    } elseif (!planAnualEsEditable($pdo, $añoId)) {
        $mensaje = 'Solo se puede modificar el plan del año escolar activo.';
        $tipoMensaje = 'danger';
    } else {
        try {
            if ($accion === 'agregar_area') {
                $nombre = trim((string) ($_POST['nombre_area'] ?? ''));
                $codigo = strtoupper(trim((string) ($_POST['codigo_area'] ?? '')));
                $descripcion = trim((string) ($_POST['descripcion_area'] ?? ''));
                $nivel = trim((string) ($_POST['nivel_area'] ?? 'primaria'));
                if ($nombre === '' || $codigo === '' ||
                    !in_array($nivel, ['inicial', 'primaria', 'media'], true)) {
                    throw new InvalidArgumentException('Complete los datos válidos del área.');
                }

                $stmt = $pdo->prepare(
                    'SELECT 1 FROM areas_aprendizaje aa
                     JOIN plan_anual_areas pea ON pea.area_id = aa.id
                     WHERE pea.año_escolar_id = ? AND aa.codigo = ? LIMIT 1'
                );
                $stmt->execute([$añoId, $codigo]);
                if ($stmt->fetchColumn() !== false) {
                    throw new InvalidArgumentException('Ya existe un área con ese código en este año.');
                }

                $pdo->beginTransaction();
                $areaIdNueva = planAnualInsertarArea($pdo, [
                    'nombre' => $nombre,
                    'codigo' => $codigo,
                    'descripcion' => $descripcion,
                    'nivel' => $nivel,
                    'estado' => 'activa',
                ], $añoId);
                planAnualAgregarArea($pdo, $añoId, $areaIdNueva);
                $pdo->commit();
                $mensaje = 'Área agregada y asignada al año.';
                $tipoMensaje = 'success';
            } elseif ($accion === 'editar_area') {
                $areaId = (int) ($_POST['area_id'] ?? 0);
                $nombre = trim((string) ($_POST['nombre_area'] ?? ''));
                $codigo = strtoupper(trim((string) ($_POST['codigo_area'] ?? '')));
                $descripcion = trim((string) ($_POST['descripcion_area'] ?? ''));
                $nivel = trim((string) ($_POST['nivel_area'] ?? 'primaria'));
                if ($areaId < 1 || !planAnualAreaAsignada($pdo, $añoId, $areaId)) {
                    throw new InvalidArgumentException('El área no pertenece al plan seleccionado.');
                }
                if ($nombre === '' || $codigo === '' ||
                    !in_array($nivel, ['inicial', 'primaria', 'media'], true)) {
                    throw new InvalidArgumentException('Complete los datos válidos del área.');
                }
                $stmt = $pdo->prepare('SELECT codigo FROM areas_aprendizaje WHERE id = ?');
                $stmt->execute([$areaId]);
                $codigoOriginal = (string) $stmt->fetchColumn();
                $clonaciones = planAnualAislarCatalogo($pdo, $añoId);
                $areaId = $clonaciones['areas'][$areaId] ?? $areaId;
                $stmt = $pdo->prepare('SELECT codigo FROM areas_aprendizaje WHERE id = ?');
                $stmt->execute([$areaId]);
                $codigoActual = (string) $stmt->fetchColumn();
                if ($codigo === $codigoOriginal && $codigoActual !== $codigoOriginal) {
                    $codigo = $codigoActual;
                } elseif ($codigo !== $codigoActual &&
                    planAnualCodigoTieneIndiceUnico($pdo) &&
                    planAnualExisteCodigo($pdo, $codigo)) {
                    throw new InvalidArgumentException(
                        'Ese código ya está utilizado por otra área y debe ser único en la base de datos.'
                    );
                }
                $stmt = $pdo->prepare(
                    'SELECT 1 FROM areas_aprendizaje aa
                     JOIN plan_anual_areas pea ON pea.area_id = aa.id
                     WHERE pea.año_escolar_id = ? AND aa.codigo = ? AND aa.id <> ? LIMIT 1'
                );
                $stmt->execute([$añoId, $codigo, $areaId]);
                if ($stmt->fetchColumn() !== false) {
                    throw new InvalidArgumentException('Ya existe un área con ese código en este año.');
                }
                $stmt = $pdo->prepare(
                    'UPDATE areas_aprendizaje
                     SET nombre = ?, codigo = ?, descripcion = ?, nivel = ?
                     WHERE id = ?'
                );
                $stmt->execute([$nombre, $codigo, $descripcion, $nivel, $areaId]);
                $mensaje = 'Área modificada sin alterar los otros años.';
                $tipoMensaje = 'success';
            } elseif ($accion === 'agregar_indicador') {
                $areaId = (int) ($_POST['area_id'] ?? 0);
                $nombre = trim((string) ($_POST['nombre_indicador'] ?? ''));
                $descripcion = trim((string) ($_POST['descripcion_indicador'] ?? ''));
                $orden = max(0, (int) ($_POST['orden_indicador'] ?? 0));
                if ($areaId < 1 || !planAnualAreaAsignada($pdo, $añoId, $areaId) || $nombre === '') {
                    throw new InvalidArgumentException('Seleccione un área del plan y complete el indicador.');
                }
                $clonaciones = planAnualAislarCatalogo($pdo, $añoId);
                $areaId = $clonaciones['areas'][$areaId] ?? $areaId;
                $pdo->beginTransaction();
                $indicadorId = planAnualInsertarIndicador($pdo, [
                    'nombre' => $nombre,
                    'descripcion' => $descripcion,
                    'escala' => 'L',
                    'orden' => $orden,
                    'estado' => 'activo',
                ], $areaId, $añoId);
                planAnualAgregarIndicador($pdo, $añoId, $indicadorId);
                $pdo->commit();
                $mensaje = 'Indicador agregado al plan anual.';
                $tipoMensaje = 'success';
            } elseif ($accion === 'editar_indicador') {
                $indicadorId = (int) ($_POST['indicador_id'] ?? 0);
                $nombre = trim((string) ($_POST['nombre_indicador'] ?? ''));
                $descripcion = trim((string) ($_POST['descripcion_indicador'] ?? ''));
                $orden = max(0, (int) ($_POST['orden_indicador'] ?? 0));
                if ($indicadorId < 1 ||
                    !planAnualIndicadorAsignado($pdo, $añoId, $indicadorId) ||
                    $nombre === '') {
                    throw new InvalidArgumentException('Complete un indicador válido del plan.');
                }
                $clonaciones = planAnualAislarCatalogo($pdo, $añoId);
                $indicadorId = $clonaciones['indicadores'][$indicadorId] ?? $indicadorId;
                $stmt = $pdo->prepare(
                    'UPDATE areas_indicadores
                     SET nombre = ?, descripcion = ?, orden = ?
                     WHERE id = ?'
                );
                $stmt->execute([$nombre, $descripcion, $orden, $indicadorId]);
                $mensaje = 'Indicador modificado sin alterar los otros años.';
                $tipoMensaje = 'success';
            } elseif ($accion === 'guardar_plan') {
                $copiarDesde = (int) ($_POST['copiar_desde'] ?? 0);
                if ($copiarDesde > 0) {
                    planAnualCopiarPlan($pdo, $copiarDesde, $añoId);
                    $mensaje = 'Plan anual copiado con áreas e indicadores independientes.';
                } else {
                    $areasSeleccionadas = $_POST['areas'] ?? [];
                    $indicadoresSeleccionados = $_POST['indicadores'] ?? [];
                    if (!is_array($areasSeleccionadas) || !is_array($indicadoresSeleccionados)) {
                        throw new InvalidArgumentException('La selección del plan no es válida.');
                    }
                    $areasSeleccionadas = array_values(array_unique(array_filter(
                        array_map('intval', $areasSeleccionadas),
                        function ($id) {
                            return $id > 0;
                        }
                    )));
                    $indicadoresSeleccionados = array_values(array_unique(array_filter(
                        array_map('intval', $indicadoresSeleccionados),
                        function ($id) {
                            return $id > 0;
                        }
                    )));

                    $areaIdsExistentes = [];
                    if ($areasSeleccionadas !== []) {
                        $placeholders = implode(',', array_fill(0, count($areasSeleccionadas), '?'));
                        $stmt = $pdo->prepare(
                            'SELECT id FROM areas_aprendizaje WHERE id IN (' . $placeholders . ')'
                        );
                        $stmt->execute($areasSeleccionadas);
                        $areaIdsExistentes = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
                    }
                    if (count($areaIdsExistentes) !== count($areasSeleccionadas)) {
                        throw new InvalidArgumentException('La selección incluye áreas que no existen.');
                    }

                    $indicadoresPorArea = [];
                    if ($indicadoresSeleccionados !== []) {
                        $placeholders = implode(',', array_fill(0, count($indicadoresSeleccionados), '?'));
                        $stmt = $pdo->prepare(
                            'SELECT id, area_id FROM areas_indicadores
                             WHERE id IN (' . $placeholders . ')'
                        );
                        $stmt->execute($indicadoresSeleccionados);
                        $indicadoresPorArea = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    }
                    if (count($indicadoresPorArea) !== count($indicadoresSeleccionados)) {
                        throw new InvalidArgumentException('La selección incluye indicadores que no existen.');
                    }
                    foreach ($indicadoresPorArea as $indicador) {
                        if (!in_array((int) $indicador['area_id'], $areasSeleccionadas, true)) {
                            throw new InvalidArgumentException(
                                'Los indicadores seleccionados deben pertenecer a áreas incluidas en el plan.'
                            );
                        }
                    }

                    $pdo->beginTransaction();
                    planAnualGuardarAsociaciones(
                        $pdo,
                        $añoId,
                        $areasSeleccionadas,
                        $indicadoresSeleccionados
                    );
                    $pdo->commit();
                    $mensaje = 'Plan anual guardado correctamente.';
                }
                $tipoMensaje = 'success';
            } else {
                throw new InvalidArgumentException('La acción solicitada no es válida.');
            }
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('Error al configurar el plan anual: ' . $e->getMessage());
            $mensaje = $e instanceof PDOException
                ? 'No se pudo guardar el plan anual. Verifique los datos y vuelva a intentarlo.'
                : $e->getMessage();
            $tipoMensaje = 'danger';
        }
    }
}

$años = $pdo->query(
    "SELECT id, nombre, estado FROM años_escolares
     ORDER BY estado = 'activo' DESC, nombre DESC"
)->fetchAll(PDO::FETCH_ASSOC);

$año = null;
if ($añoId > 0) {
    $año = planAnualObtenerAño($pdo, $añoId);
}

if (!$año && $años !== []) {
    $año = $años[0];
    $añoId = (int) $año['id'];
}

$esEditable = $año !== null && $año['estado'] === 'activo';

$areas = [];
$indicadores = [];
if ($añoId > 0) {
    $stmt = $pdo->prepare(
        "SELECT aa.id, aa.codigo, aa.nombre, aa.descripcion, aa.nivel, aa.estado
         FROM areas_aprendizaje aa
         WHERE (? = 1 AND aa.estado = 'activa') OR EXISTS (
             SELECT 1 FROM plan_anual_areas pea
             WHERE pea.año_escolar_id = ? AND pea.area_id = aa.id
         )
         ORDER BY aa.nivel, aa.nombre"
    );
    $stmt->execute([$esEditable ? 1 : 0, $añoId]);
    $areas = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $pdo->prepare(
        "SELECT ai.id, ai.area_id, ai.nombre, ai.descripcion, ai.orden, ai.estado,
                aa.nombre AS area_nombre
         FROM areas_indicadores ai
         JOIN areas_aprendizaje aa ON aa.id = ai.area_id
         WHERE (? = 1 AND ai.estado = 'activo') OR EXISTS (
             SELECT 1 FROM plan_anual_indicadores pei
             WHERE pei.año_escolar_id = ? AND pei.indicador_id = ai.id
         )
         ORDER BY aa.nombre, ai.orden, ai.id"
    );
    $stmt->execute([$esEditable ? 1 : 0, $añoId]);
    $indicadores = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

$areasAsignadas = [];
if ($añoId > 0) {
    $stmt = $pdo->prepare(
        'SELECT area_id FROM plan_anual_areas WHERE año_escolar_id = ?'
    );
    $stmt->execute([$añoId]);
    $areasAsignadas = array_fill_keys(
        array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN)),
        true
    );
}

$indicadoresAsignados = [];
if ($añoId > 0) {
    $stmt = $pdo->prepare(
        'SELECT indicador_id FROM plan_anual_indicadores WHERE año_escolar_id = ?'
    );
    $stmt->execute([$añoId]);
    $indicadoresAsignados = array_fill_keys(
        array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN)),
        true
    );
}

function escaparPlan($valor): string
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
                <h2 class="mb-1"><i class="fas fa-sitemap me-2"></i>Plan de áreas e indicadores</h2>
                <p class="text-muted mb-0">
                    Consulta los planes de cualquier año y modifica únicamente el año activo.
                </p>
            </div>
            <a href="gestionar.php" class="btn btn-outline-secondary">
                <i class="fas fa-arrow-left me-1"></i>Volver
            </a>
        </div>

        <?php if ($mensaje !== ''): ?>
            <div class="alert alert-<?php echo $tipoMensaje; ?>">
                <?php echo escaparPlan($mensaje); ?>
            </div>
        <?php endif; ?>

        <form method="get" class="card mb-4">
            <div class="card-body">
                <div class="row g-3 align-items-end">
                    <div class="col-md-6">
                        <label for="año_escolar_id" class="form-label">Año escolar</label>
                        <select class="form-select" id="año_escolar_id" name="año_escolar_id" onchange="this.form.submit()">
                            <?php foreach ($años as $anio): ?>
                                <option value="<?php echo (int) $anio['id']; ?>" <?php echo $añoId === (int) $anio['id'] ? 'selected' : ''; ?>>
                                    <?php echo escaparPlan($anio['nombre']); ?>
                                    <?php echo $anio['estado'] === 'activo' ? ' (Activo)' : ''; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
            </div>
        </form>

        <?php if ($año): ?>
            <?php if ($esEditable): ?>
                <div class="card mb-4">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <strong>Agregar área nueva</strong>
                        <span class="text-muted small">Se asignará solo a <?php echo escaparPlan($año['nombre']); ?></span>
                    </div>
                    <div class="card-body">
                        <form method="post" class="row g-3 align-items-end">
                            <input type="hidden" name="csrf_token" value="<?php echo escaparPlan($_SESSION['csrf_plan_anual']); ?>">
                            <input type="hidden" name="año_escolar_id" value="<?php echo $añoId; ?>">
                            <input type="hidden" name="accion" value="agregar_area">
                            <div class="col-md-3">
                                <label class="form-label" for="nombre_area_nueva">Nombre</label>
                                <input class="form-control" id="nombre_area_nueva" name="nombre_area" required>
                            </div>
                            <div class="col-md-2">
                                <label class="form-label" for="codigo_area_nueva">Código</label>
                                <input class="form-control" id="codigo_area_nueva" name="codigo_area" maxlength="20" required>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label" for="descripcion_area_nueva">Descripción</label>
                                <input class="form-control" id="descripcion_area_nueva" name="descripcion_area">
                            </div>
                            <div class="col-md-2">
                                <label class="form-label" for="nivel_area_nueva">Nivel</label>
                                <select class="form-select" id="nivel_area_nueva" name="nivel_area">
                                    <option value="primaria">Primaria</option>
                                    <option value="inicial">Inicial</option>
                                    <option value="media">Media</option>
                                </select>
                            </div>
                            <div class="col-md-2">
                                <button type="submit" class="btn btn-success w-100">
                                    <i class="fas fa-plus me-1"></i>Agregar área
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            <?php else: ?>
                <div class="alert alert-info">
                    Este año está en modo consulta. Solo se puede editar el año escolar activo.
                </div>
            <?php endif; ?>

            <form id="plan-anual-form" method="post">
                <input type="hidden" name="csrf_token" value="<?php echo escaparPlan($_SESSION['csrf_plan_anual']); ?>">
                <input type="hidden" name="año_escolar_id" value="<?php echo $añoId; ?>">
                <input type="hidden" name="accion" value="guardar_plan">
            </form>
            <div class="card">
                <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
                    <strong>Plan de <?php echo escaparPlan($año['nombre']); ?></strong>
                    <?php if ($esEditable): ?>
                        <div class="d-flex flex-wrap gap-2">
                            <select class="form-select form-select-sm" name="copiar_desde" form="plan-anual-form">
                                <option value="">Copiar desde otro año</option>
                                <?php foreach ($años as $anio): ?>
                                    <?php if ((int) $anio['id'] !== $añoId): ?>
                                        <option value="<?php echo (int) $anio['id']; ?>">
                                            <?php echo escaparPlan($anio['nombre']); ?>
                                        </option>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </select>
                            <button type="submit" class="btn btn-sm btn-outline-primary" form="plan-anual-form"
                                    onclick="return this.form.elements['copiar_desde'].value !== '' || confirm('¿Guardar las áreas e indicadores seleccionados?')">
                                <i class="fas fa-save me-1"></i>Guardar plan
                            </button>
                        </div>
                    <?php endif; ?>
                </div>
                <?php if ($esEditable): ?>
                    <div class="card-body border-bottom">
                        <small class="text-muted">
                            Al copiar otro año, el nuevo plan tendrá copias independientes. No se puede reemplazar un plan que ya tenga historial académico.
                        </small>
                    </div>
                <?php endif; ?>
                <div class="card-body">
                    <?php if ($areas === []): ?>
                        <div class="text-center text-muted py-4">
                            <i class="fas fa-book-open fa-2x mb-2"></i>
                            <p class="mb-0">Este año todavía no tiene áreas asignadas.</p>
                        </div>
                    <?php else: ?>
                        <div class="row">
                            <?php foreach ($areas as $area): ?>
                                <?php $areaId = (int) $area['id']; ?>
                                <?php $areaAsignada = isset($areasAsignadas[$areaId]); ?>
                                <div class="col-lg-6 mb-4">
                                    <div class="card h-100">
                                        <div class="card-header">
                                            <div class="d-flex justify-content-between align-items-start gap-2">
                                                <div class="form-check">
                                                    <?php if ($esEditable): ?>
                                                        <input class="form-check-input area-check"
                                                               type="checkbox"
                                                               name="areas[]"
                                                               form="plan-anual-form"
                                                               value="<?php echo $areaId; ?>"
                                                               id="area_<?php echo $areaId; ?>"
                                                               <?php echo $areaAsignada ? 'checked' : ''; ?>>
                                                    <?php endif; ?>
                                                    <label class="form-check-label fw-bold" for="area_<?php echo $areaId; ?>">
                                                        <?php echo escaparPlan($area['codigo'] ? $area['codigo'] . ' - ' : ''); ?>
                                                        <?php echo escaparPlan($area['nombre']); ?>
                                                    </label>
                                                </div>
                                                <span class="badge <?php echo $areaAsignada ? 'bg-success' : 'bg-secondary'; ?>">
                                                    <?php echo $areaAsignada ? 'En el plan' : 'Disponible'; ?>
                                                </span>
                                            </div>
                                            <?php if ($esEditable): ?>
                                                <details class="mt-2">
                                                    <summary class="small text-primary">Modificar área</summary>
                                                    <form method="post" class="mt-2">
                                                        <input type="hidden" name="csrf_token" value="<?php echo escaparPlan($_SESSION['csrf_plan_anual']); ?>">
                                                        <input type="hidden" name="año_escolar_id" value="<?php echo $añoId; ?>">
                                                        <input type="hidden" name="accion" value="editar_area">
                                                        <input type="hidden" name="area_id" value="<?php echo $areaId; ?>">
                                                        <div class="row g-2">
                                                            <div class="col-md-5">
                                                                <input class="form-control form-control-sm" name="nombre_area"
                                                                       value="<?php echo escaparPlan($area['nombre']); ?>" required>
                                                            </div>
                                                            <div class="col-md-3">
                                                                <input class="form-control form-control-sm" name="codigo_area"
                                                                       value="<?php echo escaparPlan($area['codigo']); ?>" required>
                                                            </div>
                                                            <div class="col-md-4">
                                                                <select class="form-select form-select-sm" name="nivel_area">
                                                                    <?php foreach (['inicial', 'primaria', 'media'] as $nivelArea): ?>
                                                                        <option value="<?php echo $nivelArea; ?>" <?php echo ($area['nivel'] ?? '') === $nivelArea ? 'selected' : ''; ?>>
                                                                            <?php echo ucfirst($nivelArea); ?>
                                                                        </option>
                                                                    <?php endforeach; ?>
                                                                </select>
                                                            </div>
                                                            <div class="col-12">
                                                                <textarea class="form-control form-control-sm" name="descripcion_area"
                                                                          rows="2"><?php echo escaparPlan($area['descripcion'] ?? ''); ?></textarea>
                                                            </div>
                                                            <div class="col-12">
                                                                <button type="submit" class="btn btn-sm btn-outline-primary">
                                                                    Guardar área
                                                                </button>
                                                            </div>
                                                        </div>
                                                    </form>
                                                </details>
                                            <?php endif; ?>
                                        </div>
                                        <div class="card-body">
                                            <?php $hayIndicadores = false; ?>
                                            <?php foreach ($indicadores as $indicador): ?>
                                                <?php if ((int) $indicador['area_id'] === $areaId): ?>
                                                    <?php $hayIndicadores = true; ?>
                                                    <?php $indicadorId = (int) $indicador['id']; ?>
                                                    <div class="d-flex justify-content-between align-items-start gap-2 mb-2">
                                                        <div class="form-check">
                                                            <?php if ($esEditable && $areaAsignada): ?>
                                                                <input class="form-check-input indicador-check indicador-area-<?php echo $areaId; ?>"
                                                                       type="checkbox"
                                                                       name="indicadores[]"
                                                                       form="plan-anual-form"
                                                                       value="<?php echo $indicadorId; ?>"
                                                                       id="indicador_<?php echo $indicadorId; ?>"
                                                                       <?php echo isset($indicadoresAsignados[$indicadorId]) ? 'checked' : ''; ?>>
                                                            <?php endif; ?>
                                                            <label class="form-check-label" for="indicador_<?php echo $indicadorId; ?>">
                                                                <?php echo escaparPlan(trim((string) ($indicador['descripcion'] ?: $indicador['nombre']))); ?>
                                                            </label>
                                                        </div>
                                                        <?php if (isset($indicadoresAsignados[$indicadorId])): ?>
                                                            <span class="badge bg-success">En el plan</span>
                                                        <?php elseif ($esEditable && $areaAsignada): ?>
                                                            <span class="badge bg-secondary">Disponible</span>
                                                        <?php endif; ?>
                                                    </div>
                                                    <?php if ($esEditable && $areaAsignada && isset($indicadoresAsignados[$indicadorId])): ?>
                                                        <details class="ms-4 mb-2">
                                                            <summary class="small text-secondary">Modificar indicador</summary>
                                                            <form method="post" class="border rounded p-2 mt-1">
                                                                <input type="hidden" name="csrf_token" value="<?php echo escaparPlan($_SESSION['csrf_plan_anual']); ?>">
                                                                <input type="hidden" name="año_escolar_id" value="<?php echo $añoId; ?>">
                                                                <input type="hidden" name="accion" value="editar_indicador">
                                                                <input type="hidden" name="indicador_id" value="<?php echo $indicadorId; ?>">
                                                                <div class="mb-2">
                                                                    <input class="form-control form-control-sm" name="nombre_indicador"
                                                                           value="<?php echo escaparPlan($indicador['nombre']); ?>" required>
                                                                </div>
                                                                <div class="mb-2">
                                                                    <textarea class="form-control form-control-sm" name="descripcion_indicador"
                                                                              rows="2"><?php echo escaparPlan($indicador['descripcion'] ?? ''); ?></textarea>
                                                                </div>
                                                                <div class="d-flex gap-2">
                                                                    <input type="number" min="0" class="form-control form-control-sm"
                                                                           name="orden_indicador" value="<?php echo (int) ($indicador['orden'] ?? 0); ?>"
                                                                           style="max-width: 100px;">
                                                                    <button type="submit" class="btn btn-sm btn-outline-primary">
                                                                        Guardar
                                                                    </button>
                                                                </div>
                                                            </form>
                                                        </details>
                                                    <?php endif; ?>
                                                <?php endif; ?>
                                            <?php endforeach; ?>
                                            <?php if ($esEditable && $areaAsignada): ?>
                                                <details class="mt-3">
                                                    <summary class="text-success">Agregar indicador a esta área</summary>
                                                    <form method="post" class="border rounded p-3 mt-2">
                                                        <input type="hidden" name="csrf_token" value="<?php echo escaparPlan($_SESSION['csrf_plan_anual']); ?>">
                                                        <input type="hidden" name="año_escolar_id" value="<?php echo $añoId; ?>">
                                                        <input type="hidden" name="accion" value="agregar_indicador">
                                                        <input type="hidden" name="area_id" value="<?php echo $areaId; ?>">
                                                        <div class="mb-2">
                                                            <label class="form-label small">Nombre del indicador</label>
                                                            <input class="form-control form-control-sm" name="nombre_indicador" required>
                                                        </div>
                                                        <div class="mb-2">
                                                            <label class="form-label small">Descripción</label>
                                                            <textarea class="form-control form-control-sm" name="descripcion_indicador" rows="2"></textarea>
                                                        </div>
                                                        <div class="d-flex gap-2">
                                                            <input type="number" min="0" class="form-control form-control-sm"
                                                                   name="orden_indicador" value="0" style="max-width: 100px;">
                                                            <button type="submit" class="btn btn-sm btn-success">
                                                                Agregar indicador
                                                            </button>
                                                        </div>
                                                    </form>
                                                </details>
                                            <?php endif; ?>
                                            <?php if (!$hayIndicadores): ?>
                                                <span class="text-muted">Esta área no tiene indicadores activos.</span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        <?php else: ?>
            <div class="alert alert-warning">No hay años escolares para configurar.</div>
        <?php endif; ?>
    </div>
</main>

<script>
var areaChecks = document.querySelectorAll('.area-check');
areaChecks.forEach(function (areaCheck) {
    areaCheck.addEventListener('change', function () {
        var areaId = this.value;
        document.querySelectorAll('.indicador-area-' + areaId).forEach(function (indicatorCheck) {
            indicatorCheck.checked = areaCheck.checked;
        });
    });
});
</script>

<?php include '../../includes/footer.php'; ?>
