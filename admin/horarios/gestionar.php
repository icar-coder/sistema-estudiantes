<?php
require_once '../../includes/auth.php';
require_once '../../includes/database.php';
checkAuth();
checkRole(['admin']);

$mensaje_error = '';
$años_escolares = [];
$secciones = [];
$filtro_busqueda = trim($_GET['q'] ?? '');
$hay_filtro_año = isset($_GET['año_escolar']);
$año_seleccionado = $hay_filtro_año ? (int) $_GET['año_escolar'] : 0;

try {
    $stmt = $pdo->query("
        SELECT id, nombre, estado
        FROM años_escolares
        ORDER BY estado = 'activo' DESC, nombre DESC
    ");
    $años_escolares = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (!$hay_filtro_año) {
        foreach ($años_escolares as $año) {
            if ($año['estado'] === 'activo') {
                $año_seleccionado = (int) $año['id'];
                break;
            }
        }
    }

    $query = "
        SELECT
            s.id,
            s.nombre,
            s.nivel,
            s.capacidad,
            ae.nombre AS año_escolar,
            g.nombre AS grado_nombre,
            p.nombre AS profesor_nombre,
            p.apellido AS profesor_apellido,
            (
                SELECT COUNT(*)
                FROM horarios_asignaciones ha
                WHERE ha.seccion_id = s.id
                  AND ha.año_escolar_id = s.año_escolar_id
            ) AS total_horarios
        FROM secciones s
        JOIN años_escolares ae ON ae.id = s.año_escolar_id
        LEFT JOIN grados g ON g.id = s.grado_id
        LEFT JOIN profesores p ON p.id = s.profesor_id
        WHERE 1 = 1
    ";
    $parametros = [];

    if ($año_seleccionado > 0) {
        $query .= " AND s.año_escolar_id = ?";
        $parametros[] = $año_seleccionado;
    }

    if ($filtro_busqueda !== '') {
        $query .= " AND (s.nombre LIKE ? OR g.nombre LIKE ?)";
        $busqueda = '%' . $filtro_busqueda . '%';
        $parametros[] = $busqueda;
        $parametros[] = $busqueda;
    }

    $query .= " ORDER BY g.orden, s.nombre";
    $stmt = $pdo->prepare($query);
    $stmt->execute($parametros);
    $secciones = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Error al cargar la gestión de horarios: " . $e->getMessage());
    $mensaje_error = 'No se pudieron cargar las secciones y sus horarios. Intente nuevamente.';
}
?>

<?php include '../../includes/header.php'; ?>

<div class="content-wrapper" style="padding: 20px;">
    <?php include '../../includes/sidebar_admin.php'; ?>

    <div class="container-fluid">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-3 mb-4">
            <div>
                <h2><i class="fas fa-clock me-2"></i>Gestión de Horarios</h2>
                <p class="text-muted mb-0">Consulta y administra el horario de cada sección.</p>
            </div>
            <a href="../secciones/gestionar.php" class="btn btn-outline-secondary">
                <i class="fas fa-door-open me-2"></i>Gestionar Secciones
            </a>
        </div>

        <?php if ($mensaje_error !== ''): ?>
            <div class="alert alert-danger" role="alert">
                <?php echo htmlspecialchars($mensaje_error, ENT_QUOTES, 'UTF-8'); ?>
            </div>
        <?php endif; ?>

        <div class="card mb-4">
            <div class="card-body">
                <form method="get" class="row g-3 align-items-end">
                    <div class="col-md-5">
                        <label for="año_escolar" class="form-label">Año escolar</label>
                        <select name="año_escolar" id="año_escolar" class="form-select">
                            <option value="0">Todos los años</option>
                            <?php foreach ($años_escolares as $año): ?>
                                <option value="<?php echo (int) $año['id']; ?>"
                                    <?php echo $año_seleccionado === (int) $año['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($año['nombre'], ENT_QUOTES, 'UTF-8'); ?>
                                    <?php echo $año['estado'] === 'activo' ? ' (Activo)' : ''; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-5">
                        <label for="q" class="form-label">Buscar sección o grado</label>
                        <input type="search" name="q" id="q" class="form-control"
                               value="<?php echo htmlspecialchars($filtro_busqueda, ENT_QUOTES, 'UTF-8'); ?>"
                               placeholder="Ej.: 1° A o Primer Grado">
                    </div>
                    <div class="col-md-2 d-grid">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-search me-1"></i>Buscar
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <div class="card">
            <div class="card-header bg-white">
                <h5 class="mb-0">Secciones y horarios</h5>
            </div>
            <div class="card-body">
                <?php if (!$secciones && $mensaje_error === ''): ?>
                    <div class="text-center text-muted py-4">
                        <i class="fas fa-calendar-times fa-2x mb-3"></i>
                        <p class="mb-0">No hay secciones que coincidan con los filtros seleccionados.</p>
                    </div>
                <?php elseif ($secciones): ?>
                    <div class="table-responsive">
                        <table class="table table-hover align-middle">
                            <thead>
                                <tr>
                                    <th>Sección</th>
                                    <th>Grado</th>
                                    <th>Año escolar</th>
                                    <th>Profesor titular</th>
                                    <th>Asignaciones</th>
                                    <th class="text-end">Acción</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($secciones as $seccion): ?>
                                    <tr>
                                        <td>
                                            <strong><?php echo htmlspecialchars($seccion['nombre'], ENT_QUOTES, 'UTF-8'); ?></strong>
                                            <div class="small text-muted"><?php echo htmlspecialchars($seccion['nivel'] ?? '', ENT_QUOTES, 'UTF-8'); ?></div>
                                        </td>
                                        <td><?php echo htmlspecialchars($seccion['grado_nombre'] ?? 'Sin grado', ENT_QUOTES, 'UTF-8'); ?></td>
                                        <td><?php echo htmlspecialchars($seccion['año_escolar'], ENT_QUOTES, 'UTF-8'); ?></td>
                                        <td>
                                            <?php if ($seccion['profesor_nombre']): ?>
                                                <?php echo htmlspecialchars(trim($seccion['profesor_nombre'] . ' ' . $seccion['profesor_apellido']), ENT_QUOTES, 'UTF-8'); ?>
                                            <?php else: ?>
                                                <span class="text-muted">Sin asignar</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ((int) $seccion['total_horarios'] > 0): ?>
                                                <span class="badge bg-success">
                                                    <?php echo (int) $seccion['total_horarios']; ?> bloques
                                                </span>
                                            <?php else: ?>
                                                <span class="badge bg-secondary">Sin horario</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-end">
                                            <a href="../secciones/asignar_profesores.php?id=<?php echo (int) $seccion['id']; ?>"
                                               class="btn btn-sm btn-outline-primary">
                                                <i class="fas fa-calendar-alt me-1"></i>
                                                <?php echo (int) $seccion['total_horarios'] > 0 ? 'Ver / editar' : 'Configurar'; ?>
                                            </a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php include '../../includes/footer.php'; ?>
