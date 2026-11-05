<?php
require_once '../../includes/auth.php';
require_once '../../includes/database.php';
checkAuth();
checkRole(['estudiante']);

$estudiante_id = $_SESSION['relacion_id'];
$mensaje = '';
$tipo_mensaje = '';

// Obtener información del estudiante y su inscripción activa
try {
    $stmt = $pdo->prepare("
        SELECT e.*, i.id as inscripcion_id, i.seccion_id, s.nombre as seccion_nombre, 
               ae.nombre as año_escolar, ae.id as año_escolar_id
        FROM estudiantes e
        JOIN inscripciones i ON e.id = i.estudiante_id
        JOIN secciones s ON i.seccion_id = s.id
        JOIN años_escolares ae ON i.año_escolar_id = ae.id
        WHERE e.id = ? AND i.status = 'activo' AND ae.estado = 'activo'
        LIMIT 1
    ");
    $stmt->execute([$estudiante_id]);
    $estudiante = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$estudiante) {
        $mensaje = "No tienes una inscripción activa en el año escolar actual";
        $tipo_mensaje = "warning";
    }
} catch (PDOException $e) {
    $estudiante = null;
    $mensaje = "Error al cargar información del estudiante: " . $e->getMessage();
    $tipo_mensaje = "danger";
}

// Obtener notas del estudiante
$notas = [];
if ($estudiante) {
    try {
        $stmt = $pdo->prepare("
            SELECT n.valor_decimal, n.valor_letra, n.observaciones, n.fecha_registro,
                   m.nombre as materia_nombre, m.codigo as materia_codigo,
                   l.nombre as lapso_nombre, l.numero as lapso_numero
            FROM notas n
            JOIN materias m ON n.materia_id = m.id
            JOIN lapsos l ON n.lapso_id = l.id
            WHERE n.inscripcion_id = ?
            ORDER BY l.numero, m.nombre
        ");
        $stmt->execute([$estudiante['inscripcion_id']]);
        $notas = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $notas = [];
    }
}

// Organizar notas por lapso
$notas_por_lapso = [];
foreach ($notas as $nota) {
    $lapso_key = $nota['lapso_nombre'];
    if (!isset($notas_por_lapso[$lapso_key])) {
        $notas_por_lapso[$lapso_key] = [];
    }
    $notas_por_lapso[$lapso_key][] = $nota;
}

// Calcular promedios por lapso
$promedios_lapso = [];
foreach ($notas_por_lapso as $lapso => $notas_lapso) {
    $suma = 0;
    $contador = 0;
    foreach ($notas_lapso as $nota) {
        $suma += $nota['valor_decimal'];
        $contador++;
    }
    $promedios_lapso[$lapso] = $contador > 0 ? number_format($suma / $contador, 2) : 0;
}
?>

<?php include '../../includes/header.php'; ?>

<div class="row">
    <div class="col-md-3 sidebar">
        <div class="p-3">
            <h5 class="text-white mb-4">Menú Estudiante</h5>
            <nav class="nav flex-column">
                <a class="nav-link" href="../dashboard.php">
                    <i class="fas fa-tachometer-alt me-2"></i>Dashboard
                </a>
                <a class="nav-link active" href="ver.php">
                    <i class="fas fa-chart-line me-2"></i>Mis Notas
                </a>
                <!-- El módulo de horario del profesor aún no está implementado -->
                <a class="nav-link" href="../perfil/ver.php">
                    <i class="fas fa-user me-2"></i>Mi Perfil
                </a>
            </nav>
        </div>
    </div>

    <div class="col-md-9">
        <div class="p-4">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h2>Mis Calificaciones</h2>
            </div>

            <?php if ($mensaje): ?>
                <div class="alert alert-<?php echo $tipo_mensaje; ?> alert-dismissible fade show" role="alert">
                    <?php echo $mensaje; ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <?php if ($estudiante): ?>
                <!-- Información del estudiante -->
                <div class="card mb-4">
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-8">
                                <h4><?php echo htmlspecialchars($estudiante['nombre'] . ' ' . $estudiante['apellido']); ?></h4>
                                <p class="mb-1"><strong>Sección:</strong> <?php echo htmlspecialchars($estudiante['seccion_nombre']); ?></p>
                                <p class="mb-1"><strong>Año Escolar:</strong> <?php echo htmlspecialchars($estudiante['año_escolar']); ?></p>
                                <p class="mb-0"><strong>Cédula Escolar:</strong> <?php echo htmlspecialchars($estudiante['cedula_escolar'] ?? 'No especificada'); ?></p>
                            </div>
                            <div class="col-md-4 text-end">
                                <span class="badge bg-success fs-6">Estudiante Activo</span>
                            </div>
                        </div>
                    </div>
                </div>

                <?php if ($notas_por_lapso): ?>
                    <?php foreach ($notas_por_lapso as $lapso => $notas_lapso): ?>
                        <div class="card mb-4">
                            <div class="card-header d-flex justify-content-between align-items-center">
                                <h5 class="card-title mb-0"><?php echo htmlspecialchars($lapso); ?></h5>
                                <span class="badge bg-primary fs-6">
                                    Promedio: <?php echo $promedios_lapso[$lapso]; ?>
                                </span>
                            </div>
                            <div class="card-body">
                                <div class="table-responsive">
                                    <table class="table table-striped table-hover">
                                        <thead>
                                            <tr>
                                                <th>Materia</th>
                                                <th>Nota Numérica</th>
                                                <th>Nota en Letras</th>
                                                <th>Observaciones</th>
                                                <th>Fecha Registro</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($notas_lapso as $nota): 
                                                $clase_nota = $nota['valor_decimal'] >= 10 ? 'table-success' : 'table-danger';
                                            ?>
                                                <tr class="<?php echo $clase_nota; ?>">
                                                    <td>
                                                        <strong><?php echo htmlspecialchars($nota['materia_nombre']); ?></strong>
                                                        <br>
                                                        <small class="text-muted"><?php echo htmlspecialchars($nota['materia_codigo']); ?></small>
                                                    </td>
                                                    <td>
                                                        <span class="fw-bold fs-5"><?php echo $nota['valor_decimal']; ?></span>
                                                    </td>
                                                    <td>
                                                        <span class="fst-italic"><?php echo htmlspecialchars($nota['valor_letra']); ?></span>
                                                    </td>
                                                    <td>
                                                        <?php echo $nota['observaciones'] ? htmlspecialchars($nota['observaciones']) : '<span class="text-muted">Sin observaciones</span>'; ?>
                                                    </td>
                                                    <td>
                                                        <?php echo date('d/m/Y', strtotime($nota['fecha_registro'])); ?>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="alert alert-info">
                        <i class="fas fa-info-circle me-2"></i>
                        No hay notas registradas para el año escolar actual.
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php include '../../includes/footer.php'; ?>