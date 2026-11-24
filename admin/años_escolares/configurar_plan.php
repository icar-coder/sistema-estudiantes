<?php
require_once '../../includes/auth.php';
require_once '../../includes/database.php';
require_once '../../includes/database_helpers.php';
checkAuth();
checkRole(['admin']);

function crearTablasPlanAnual(PDO $pdo): void
{
    if (isSqliteDatabase($pdo)) {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS años_escolares_areas (
                año_escolar_id INTEGER NOT NULL,
                area_id INTEGER NOT NULL,
                PRIMARY KEY (año_escolar_id, area_id)
            )'
        );
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS años_escolares_indicadores (
                año_escolar_id INTEGER NOT NOT NULL,
                indicador_id INTEGER NOT NULL,
                PRIMARY KEY (año_escolar_id, indicador_id)
            )'
        );
    } else {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS `años_escolares_areas` (
                `año_escolar_id` INT(11) NOT NULL,
                `area_id` INT(11) NOT NULL,
                PRIMARY KEY (`año_escolar_id`, `area_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS `años_escolares_indicadores` (
                `año_escolar_id` INT(11) NOT NULL,
                `indicador_id` INT(11) NOT NULL,
                PRIMARY KEY (`año_escolar_id`, `indicador_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );
    }
}

crearTablasPlanAnual($pdo);

$añoId = isset($_GET['año_escolar_id']) ? (int) $_GET['año_escolar_id'] : 0;
$mensaje = '';
$tipoMensaje = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $añoId = (int) ($_POST['año_escolar_id'] ?? 0);
    $accion = trim((string) ($_POST['accion'] ?? ''));
    if ($accion === 'agregar_area') {
        $nombre = trim((string) ($_POST['nombre_area'] ?? ''));
        $codigo = strtoupper(trim((string) ($_POST['codigo_area'] ?? '')));
        $descripcion = trim((string) ($_POST['descripcion_area'] ?? ''));
        $nivel = trim((string) ($_POST['nivel_area'] ?? 'primaria'));
        if ($nombre === '' || $codigo === '') {
            $mensaje = 'Complete el nombre y el código del área.';
            $tipoMensaje = 'danger';
        } else {
            try {
                $stmt = $pdo->prepare(
                    'SELECT id FROM areas_aprendizaje WHERE codigo = ? LIMIT 1'
                );
                $stmt->execute([$codigo]);
                if ($stmt->fetch()) {
                    $mensaje = 'Ya existe un área con ese código.';
                    $tipoMensaje = 'danger';
                } else {
                    $stmt = $pdo->prepare(
                        "INSERT INTO areas_aprendizaje
                         (nombre, codigo, descripcion, nivel, estado)
                         VALUES (?, ?, ?, ?, 'activa')"
                    );
                    $stmt->execute([$nombre, $codigo, $descripcion, $nivel]);
                    $areaIdNueva = (int) $pdo->lastInsertId();
                    $stmt = $pdo->prepare(
                        'INSERT INTO años_escolares_areas (año_escolar_id, area_id)
                         VALUES (?, ?)'
                    );
                    $stmt->execute([$añoId, $areaIdNueva]);
                    $mensaje = 'Área agregada y asignada al año.';
                    $tipoMensaje = 'success';
                }
            } catch (Throwable $e) {
                $mensaje = 'No se pudo agregar el área.';
                $tipoMensaje = 'danger';
            }
        }
    } elseif ($accion === 'editar_area') {
        $areaId = (int) ($_POST['area_id'] ?? 0);
        $nombre = trim((string) ($_POST['nombre_area'] ?? ''));
        $codigo = strtoupper(trim((string) ($_POST['codigo_area'] ?? '')));
        $descripcion = trim((string) ($_POST['descripcion_area'] ?? ''));
        $nivel = trim((string) ($_POST['nivel_area'] ?? 'primaria'));
        if ($areaId < 1 || $nombre === '' || $codigo === '') {
            $mensaje = 'Complete los datos del área.';
            $tipoMensaje = 'danger';
        } else {
            try {
                $stmt = $pdo->prepare(
                    'UPDATE areas_aprendizaje
                     SET nombre = ?, codigo = ?, descripcion = ?, nivel = ?
                     WHERE id = ?'
                );
                $stmt->execute([$nombre, $codigo, $descripcion, $nivel, $areaId]);
                $mensaje = 'Área modificada correctamente.';
                $tipoMensaje = 'success';
            } catch (Throwable $e) {
                $mensaje = 'No se pudo modificar el área.';
                $tipoMensaje = 'danger';
            }
        }
    } elseif ($accion === 'agregar_indicador') {
        $areaId = (int) ($_POST['area_id'] ?? 0);
        $nombre = trim((string) ($_POST['nombre_indicador'] ?? ''));
        $descripcion = trim((string) ($_POST['descripcion_indicador'] ?? ''));
        $orden = max(0, (int) ($_POST['orden_indicador'] ?? 0));
        if ($areaId < 1 || $nombre === '') {
            $mensaje = 'Complete el texto del indicador.';
            $tipoMensaje = 'danger';
        } else {
            try {
                $stmt = $pdo->prepare(
                    "INSERT INTO areas_indicadores
                     (area_id, nombre, descripcion, escala, orden, estado)
                     VALUES (?, ?, ?, 'L', ?, 'activo')"
                );
                $stmt->execute([$areaId, $nombre, $descripcion, $orden]);
                $indicadorId = (int) $pdo->lastInsertId();
                $stmt = $pdo->prepare(
                    'INSERT INTO años_escolares_indicadores
                     (año_escolar_id, indicador_id) VALUES (?, ?)'
                );
                $stmt->execute([$añoId, $indicadorId]);
                $mensaje = 'Indicador agregado y asignado al año.';
                $tipoMensaje = 'success';
            } catch (Throwable $e) {
                $mensaje = 'No se pudo agregar el indicador.';
                $tipoMensaje = 'danger';
            }
        }
    } elseif ($accion === 'editar_indicador') {
        $indicadorId = (int) ($_POST['indicador_id'] ?? 0);
        $nombre = trim((string) ($_POST['nombre_indicador'] ?? ''));
        $descripcion = trim((string) ($_POST['descripcion_indicador'] ?? ''));
        $orden = max(0, (int) ($_POST['orden_indicador'] ?? 0));
        if ($indicadorId < 1 || $nombre === '') {
            $mensaje = 'Complete el texto del indicador.';
            $tipoMensaje = 'danger';
        } else {
            try {
                $stmt = $pdo->prepare(
                    'UPDATE areas_indicadores
                     SET nombre = ?, descripcion = ?, orden = ?
                     WHERE id = ?'
                );
                $stmt->execute([$nombre, $descripcion, $orden, $indicadorId]);
                $mensaje = 'Indicador modificado correctamente.';
                $tipoMensaje = 'success';
            } catch (Throwable $e) {
                $mensaje = 'No se pudo modificar el indicador.';
                $tipoMensaje = 'danger';
            }
        }
    } elseif ($accion === 'guardar_plan') {
    $areasSeleccionadas = $_POST['areas'] ?? [];
    $indicadoresSeleccionados = $_POST['indicadores'] ?? [];
    $copiarDesde = (int) ($_POST['copiar_desde'] ?? 0);

    if ($añoId < 1) {
        $mensaje = 'Seleccione un año escolar válido.';
        $tipoMensaje = 'danger';
    } elseif ($copiarDesde > 0) {
        try {
            $pdo->beginTransaction();
            $pdo->prepare(
                'DELETE FROM años_escolares_areas WHERE año_escolar_id = ?'
            )->execute([$añoId]);
            $pdo->prepare(
                'DELETE FROM años_escolares_indicadores WHERE año_escolar_id = ?'
            )->execute([$añoId]);

            $areasCopiar = $pdo->prepare(
                'SELECT area_id FROM años_escolares_areas WHERE año_escolar_id = ?'
            );
            $areasCopiar->execute([$copiarDesde]);
            $insertArea = $pdo->prepare(
                'INSERT INTO años_escolares_areas (año_escolar_id, area_id) VALUES (?, ?)'
            );
            foreach ($areasCopiar->fetchAll(PDO::FETCH_COLUMN) as $areaId) {
                $insertArea->execute([$añoId, (int) $areaId]);
            }

            $indicadoresCopiar = $pdo->prepare(
                'SELECT indicador_id FROM años_escolares_indicadores WHERE año_escolar_id = ?'
            );
            $indicadoresCopiar->execute([$copiarDesde]);
            $insertIndicador = $pdo->prepare(
                'INSERT INTO años_escolares_indicadores (año_escolar_id, indicador_id) VALUES (?, ?)'
            );
            foreach ($indicadoresCopiar->fetchAll(PDO::FETCH_COLUMN) as $indicadorId) {
                $insertIndicador->execute([$añoId, (int) $indicadorId]);
            }
            $pdo->commit();
            $mensaje = 'Plan anual copiado correctamente.';
            $tipoMensaje = 'success';
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $mensaje = 'No se pudo copiar el plan anual.';
            $tipoMensaje = 'danger';
        }
    } else {
        if (!is_array($areasSeleccionadas) || !is_array($indicadoresSeleccionados)) {
            $mensaje = 'Selección de plan inválida.';
            $tipoMensaje = 'danger';
        } else {
            try {
                $pdo->beginTransaction();
                $pdo->prepare(
                    'DELETE FROM años_escolares_areas WHERE año_escolar_id = ?'
                )->execute([$añoId]);
                $pdo->prepare(
                    'DELETE FROM años_escolares_indicadores WHERE año_escolar_id = ?'
                )->execute([$añoId]);

                $insertArea = $pdo->prepare(
                    'INSERT INTO años_escolares_areas (año_escolar_id, area_id) VALUES (?, ?)'
                );
                foreach (array_unique(array_map('intval', $areasSeleccionadas)) as $areaId) {
                    if ($areaId > 0) {
                        $insertArea->execute([$añoId, $areaId]);
                    }
                }

                $insertIndicador = $pdo->prepare(
                    'INSERT INTO años_escolares_indicadores (año_escolar_id, indicador_id) VALUES (?, ?)'
                );
                foreach (array_unique(array_map('intval', $indicadoresSeleccionados)) as $indicadorId) {
                    if ($indicadorId > 0) {
                        $insertIndicador->execute([$añoId, $indicadorId]);
                    }
                }
                $pdo->commit();
                $mensaje = 'Plan anual guardado correctamente.';
                $tipoMensaje = 'success';
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $mensaje = 'No se pudo guardar el plan anual.';
                $tipoMensaje = 'danger';
            }
        }
    }
}
}

$años = $pdo->query(
    'SELECT id, nombre FROM años_escolares ORDER BY nombre DESC'
)->fetchAll(PDO::FETCH_ASSOC);

$año = null;
if ($añoId > 0) {
    $stmt = $pdo->prepare('SELECT id, nombre FROM años_escolares WHERE id = ?');
    $stmt->execute([$añoId]);
    $año = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

if (!$año && $años !== []) {
    $año = $años[0];
    $añoId = (int) $año['id'];
}

$areas = $pdo->query(
    "SELECT id, codigo, nombre, descripcion, nivel
     FROM areas_aprendizaje
     WHERE estado = 'activa'
     ORDER BY nivel, nombre"
)->fetchAll(PDO::FETCH_ASSOC);

$indicadores = $pdo->query(
    "SELECT ai.id, ai.area_id, ai.nombre, ai.descripcion, ai.orden,
            aa.nombre AS area_nombre
     FROM areas_indicadores ai
     JOIN areas_aprendizaje aa ON aa.id = ai.area_id
     WHERE ai.estado = 'activo' AND aa.estado = 'activa'
     ORDER BY aa.nombre, ai.orden, ai.id"
)->fetchAll(PDO::FETCH_ASSOC);

$areasAsignadas = [];
if ($añoId > 0) {
    $stmt = $pdo->prepare(
        'SELECT area_id FROM años_escolares_areas WHERE año_escolar_id = ?'
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
        'SELECT indicador_id FROM años_escolares_indicadores WHERE año_escolar_id = ?'
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
                    Configuración anual independiente del catálogo general.
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
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
            </div>
        </form>

        <div class="card mb-4">
            <div class="card-header d-flex justify-content-between align-items-center">
                <strong>Agregar área nueva</strong>
                <span class="text-muted small">Se creará y se asignará automáticamente a este año</span>
            </div>
            <div class="card-body">
                <form method="post" class="row g-3 align-items-end">
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

        <form method="post" class="card">
            <input type="hidden" name="año_escolar_id" value="<?php echo $añoId; ?>">
            <input type="hidden" name="accion" value="guardar_plan">
            <div class="card-header d-flex justify-content-between align-items-center">
                <strong>Plan de <?php echo escaparPlan($año['nombre'] ?? ''); ?></strong>
                <div class="d-flex gap-2">
                    <select class="form-select form-select-sm" name="copiar_desde">
                        <option value="">Copiar desde otro año</option>
                        <?php foreach ($años as $anio): ?>
                            <?php if ((int) $anio['id'] !== $añoId): ?>
                                <option value="<?php echo (int) $anio['id']; ?>">
                                    <?php echo escaparPlan($anio['nombre']); ?>
                                </option>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </select>
                    <button type="submit" class="btn btn-sm btn-outline-primary"
                            onclick="return this.form.elements['copiar_desde'].value !== '' || confirm('¿Guardar las áreas e indicadores seleccionados?')">
                        <i class="fas fa-save me-1"></i>Guardar
                    </button>
                </div>
            </div>
            <div class="card-body">
                <div class="row">
                    <?php foreach ($areas as $area): ?>
                        <?php $areaId = (int) $area['id']; ?>
                        <div class="col-lg-6 mb-4">
                            <div class="card h-100">
                                <div class="card-header">
                                    <div class="form-check">
                                        <input class="form-check-input area-check"
                                               type="checkbox"
                                               name="areas[]"
                                               value="<?php echo $areaId; ?>"
                                               id="area_<?php echo $areaId; ?>"
                                               <?php echo isset($areasAsignadas[$areaId]) ? 'checked' : ''; ?>>
                                        <label class="form-check-label fw-bold" for="area_<?php echo $areaId; ?>">
                                            <?php echo escaparPlan($area['codigo'] ? $area['codigo'] . ' - ' : ''); ?>
                                            <?php echo escaparPlan($area['nombre']); ?>
                                        </label>
                                    </div>
                                    <details class="mt-2">
                                        <summary class="small text-primary">Modificar área</summary>
                                        <form method="post" class="mt-2">
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
                                </div>
                                <div class="card-body">
                                    <?php $hayIndicadores = false; ?>
                                    <?php foreach ($indicadores as $indicador): ?>
                                        <?php if ((int) $indicador['area_id'] === $areaId): ?>
                                            <?php $hayIndicadores = true; ?>
                                            <?php $indicadorId = (int) $indicador['id']; ?>
                                            <div class="form-check mb-2">
                                                <input class="form-check-input indicador-check indicador-area-<?php echo $areaId; ?>"
                                                       type="checkbox"
                                                       name="indicadores[]"
                                                       value="<?php echo $indicadorId; ?>"
                                                       id="indicador_<?php echo $indicadorId; ?>"
                                                       <?php echo isset($indicadoresAsignados[$indicadorId]) ? 'checked' : ''; ?>>
                                                <label class="form-check-label" for="indicador_<?php echo $indicadorId; ?>">
                                                    <?php echo escaparPlan(trim((string) ($indicador['descripcion'] ?: $indicador['nombre']))); ?>
                                                </label>
                                            </div>
                                            <details class="ms-4 mb-2">
                                                <summary class="small text-secondary">Modificar indicador</summary>
                                                <form method="post" class="border rounded p-2 mt-1">
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
                                    <?php endforeach; ?>
                                    <details class="mt-3">
                                        <summary class="text-success">Agregar indicador a esta área</summary>
                                        <form method="post" class="border rounded p-3 mt-2">
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
                                    <?php if (!$hayIndicadores): ?>
                                        <span class="text-muted">Esta área no tiene indicadores activos.</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </form>
    </div>
</main>

<script>
document.querySelectorAll('.area-check').forEach(function (areaCheck) {
    areaCheck.addEventListener('change', function () {
        var areaId = this.value;
        document.querySelectorAll('.indicador-area-' + areaId).forEach(function (indicator) {
            indicator.checked = areaCheck.checked;
        });
    });
});
</script>

<?php include '../../includes/footer.php'; ?>
