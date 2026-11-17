<?php
require_once '../../includes/auth.php';
require_once '../../includes/database.php';
checkAuth();
checkRole(['admin']);

$mensaje = '';
$tipo_mensaje = '';

// Primero, asegurarnos de que existan los grados base
try {
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM grados");
    $total_grados = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
    
    if ($total_grados == 0) {
        // Insertar grados base automáticamente
        $grados_base = [
            [1, 'Primer Grado', 'primaria', 1],
            [2, 'Segundo Grado', 'primaria', 2],
            [3, 'Tercer Grado', 'primaria', 3],
            [4, 'Cuarto Grado', 'primaria', 4],
            [5, 'Quinto Grado', 'primaria', 5],
            [6, 'Sexto Grado', 'primaria', 6]
        ];
        
        foreach ($grados_base as $grado) {
            $stmt = $pdo->prepare("INSERT INTO grados (numero, nombre, nivel, orden) VALUES (?, ?, ?, ?)");
            $stmt->execute($grado);
        }
    }
} catch (PDOException $e) {
    error_log("Error al verificar grados: " . $e->getMessage());
}

// Procesar eliminación de sección
if (isset($_GET['eliminar'])) {
    $id = $_GET['eliminar'];
    
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM inscripciones WHERE seccion_id = ?");
        $stmt->execute([$id]);
        $tiene_estudiantes = $stmt->fetchColumn();
        
        if ($tiene_estudiantes > 0) {
            $mensaje = "No se puede eliminar la sección porque tiene estudiantes inscritos";
            $tipo_mensaje = "warning";
        } else {
            $stmt = $pdo->prepare("DELETE FROM secciones WHERE id = ?");
            $stmt->execute([$id]);
            $mensaje = "Sección eliminada correctamente";
            $tipo_mensaje = "success";
        }
    } catch (PDOException $e) {
        $mensaje = "Error al eliminar sección: " . $e->getMessage();
        $tipo_mensaje = "danger";
    }
}

// Obtener años escolares
try {
    $stmt = $pdo->query("SELECT id, nombre FROM años_escolares ORDER BY nombre DESC");
    $años_escolares = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $años_escolares = [];
}

// Obtener grados con sus secciones
try {
    $stmt = $pdo->prepare("
        SELECT g.*, 
               COUNT(s.id) as total_secciones,
               GROUP_CONCAT(CONCAT(s.nombre, '|', s.id, '|', ae.nombre) SEPARATOR ';;') as secciones_info
        FROM grados g
        LEFT JOIN secciones s ON g.id = s.grado_id
        LEFT JOIN años_escolares ae ON s.año_escolar_id = ae.id
        WHERE g.estado = 'activo'
        GROUP BY g.id
        ORDER BY g.orden
    ");
    $stmt->execute();
    $grados = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $grados = [];
    error_log("Error al cargar grados: " . $e->getMessage());
}

// Obtener todas las secciones para el listado completo
try {
    $stmt = $pdo->query("
        SELECT s.*, 
               ae.nombre as año_escolar,
               g.nombre as grado_nombre,
               COUNT(i.id) as total_estudiantes
        FROM secciones s 
        LEFT JOIN años_escolares ae ON s.año_escolar_id = ae.id 
        LEFT JOIN grados g ON s.grado_id = g.id
        LEFT JOIN inscripciones i ON s.id = i.seccion_id AND i.status = 'activo'
        GROUP BY s.id 
        ORDER BY ae.nombre DESC, g.orden, s.nombre
    ");
    $todas_secciones = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $todas_secciones = [];
}
?>

<?php include '../../includes/header.php'; ?>

<div class="content-wrapper" style="padding: 20px;">
    <?php include '../../includes/sidebar_admin.php'; ?>
    
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2>
            <i class="fas fa-door-open me-2"></i>Gestión de Secciones por Grado
        </h2>
        <div>
            <a href="agregar.php" class="btn btn-primary">
                <i class="fas fa-plus me-2"></i>Nueva Sección
            </a>
        </div>
    </div>

    <?php if ($mensaje): ?>
        <div class="alert alert-<?php echo $tipo_mensaje; ?> alert-dismissible fade show" role="alert">
            <?php echo $mensaje; ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- Cards de Grados -->
    <div class="row mb-5">
        <?php foreach ($grados as $grado): 
            $secciones_grado = [];
            if (!empty($grado['secciones_info'])) {
                $secciones_data = explode(';;', $grado['secciones_info']);
                foreach ($secciones_data as $seccion_data) {
                    if (!empty(trim($seccion_data))) {
                        list($nombre, $id, $año_escolar) = explode('|', $seccion_data);
                        $secciones_grado[] = [
                            'id' => $id,
                            'nombre' => $nombre,
                            'año_escolar' => $año_escolar
                        ];
                    }
                }
            }
        ?>
        <div class="col-md-6 col-lg-4 mb-4">
            <div class="card h-100 border-<?php echo $this->getColorByGrado($grado['numero']); ?>">
                <div class="card-header bg-<?php echo $this->getColorByGrado($grado['numero']); ?> text-white d-flex justify-content-between align-items-center">
                    <h5 class="card-title mb-0">
                        <i class="fas fa-graduation-cap me-2"></i><?php echo htmlspecialchars($grado['nombre']); ?>
                    </h5>
                    <span class="badge bg-light text-dark"><?php echo $grado['total_secciones']; ?> secc.</span>
                </div>
                <div class="card-body">
                    <!-- Lista de secciones existentes -->
                    <?php if (!empty($secciones_grado)): ?>
                        <h6 class="text-muted mb-2">Secciones Activas:</h6>
                        <div class="mb-3">
                            <?php foreach ($secciones_grado as $seccion): ?>
                                <div class="d-flex justify-content-between align-items-center mb-2 p-2 bg-light rounded">
                                    <div>
                                        <strong><?php echo htmlspecialchars($seccion['nombre']); ?></strong>
                                        <br>
                                        <small class="text-muted"><?php echo htmlspecialchars($seccion['año_escolar']); ?></small>
                                    </div>
                                    <div class="btn-group btn-group-sm">
                                        <a href="editar.php?id=<?php echo $seccion['id']; ?>" 
                                           class="btn btn-outline-primary btn-sm" title="Editar">
                                            <i class="fas fa-edit"></i>
                                        </a>
                                        <a href="../inscripciones/gestionar.php?seccion_id=<?php echo $seccion['id']; ?>" 
                                           class="btn btn-outline-success btn-sm" title="Estudiantes">
                                            <i class="fas fa-users"></i>
                                        </a>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="text-center py-3">
                            <i class="fas fa-door-open fa-2x text-muted mb-2"></i>
                            <p class="text-muted mb-0">No hay secciones creadas</p>
                        </div>
                    <?php endif; ?>
                </div>
                <div class="card-footer bg-transparent">
                    <div class="d-grid gap-2">
                        <a href="agregar.php?grado_id=<?php echo $grado['id']; ?>" 
                           class="btn btn-<?php echo $this->getColorByGrado($grado['numero']); ?> btn-sm">
                            <i class="fas fa-plus me-1"></i>Crear Sección
                        </a>
                    </div>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- Lista Completa de Todas las Secciones -->
    <div class="card">
        <div class="card-header">
            <h5 class="card-title mb-0">
                <i class="fas fa-list me-2"></i>Todas las Secciones
            </h5>
        </div>
        <div class="card-body">
            <?php if ($todas_secciones): ?>
                <div class="table-responsive">
                    <table class="table table-striped table-hover">
                        <thead>
                            <tr>
                                <th>Nombre</th>
                                <th>Grado</th>
                                <th>Año Escolar</th>
                                <th>Estudiantes</th>
                                <th>Capacidad</th>
                                <th>Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($todas_secciones as $seccion): 
                                $ocupacion = $seccion['capacidad'] > 0 ? ($seccion['total_estudiantes'] / $seccion['capacidad']) * 100 : 0;
                            ?>
                                <tr>
                                    <td>
                                        <strong><?php echo htmlspecialchars($seccion['nombre']); ?></strong>
                                    </td>
                                    <td>
                                        <span class="badge bg-primary"><?php echo htmlspecialchars($seccion['grado_nombre']); ?></span>
                                    </td>
                                    <td>
                                        <span class="badge bg-info"><?php echo htmlspecialchars($seccion['año_escolar']); ?></span>
                                    </td>
                                    <td>
                                        <span class="badge bg-<?php echo $seccion['total_estudiantes'] > 0 ? 'success' : 'secondary'; ?>">
                                            <?php echo $seccion['total_estudiantes']; ?> estudiantes
                                        </span>
                                    </td>
                                    <td>
                                        <?php if ($seccion['capacidad']): ?>
                                            <div class="progress" style="height: 20px; width: 100px;">
                                                <div class="progress-bar <?php echo $ocupacion >= 90 ? 'bg-danger' : ($ocupacion >= 70 ? 'bg-warning' : 'bg-success'); ?>" 
                                                     style="width: <?php echo $ocupacion; ?>%"
                                                     title="<?php echo round($ocupacion); ?>%">
                                                    <?php echo $seccion['total_estudiantes']; ?>/<?php echo $seccion['capacidad']; ?>
                                                </div>
                                            </div>
                                        <?php else: ?>
                                            <span class="text-muted">Sin límite</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="btn-group btn-group-sm">
                                            <a href="editar.php?id=<?php echo $seccion['id']; ?>" 
                                               class="btn btn-outline-primary" title="Editar">
                                                <i class="fas fa-edit"></i>
                                            </a>
                                            <a href="../inscripciones/gestionar.php?seccion_id=<?php echo $seccion['id']; ?>" 
                                               class="btn btn-outline-success" title="Estudiantes">
                                                <i class="fas fa-users"></i>
                                            </a>
                                            <a href="asignar_profesores.php?id=<?php echo $seccion['id']; ?>" 
                                               class="btn btn-outline-info" title="Horario">
                                                <i class="fas fa-clock"></i>
                                            </a>
                                            <button type="button" 
                                                    class="btn btn-outline-danger" 
                                                    onclick="confirmarEliminacion(<?php echo $seccion['id']; ?>, '<?php echo htmlspecialchars($seccion['nombre']); ?>')"
                                                    title="Eliminar">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div class="text-center py-4">
                    <i class="fas fa-door-open fa-3x text-muted mb-3"></i>
                    <h5 class="text-muted">No hay secciones registradas</h5>
                    <p class="text-muted">Comienza creando secciones desde las cards de grados.</p>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
function confirmarEliminacion(id, nombre) {
    if (confirm(`¿Estás seguro de eliminar la sección "${nombre}"?\n\nEsta acción no se puede deshacer.`)) {
        window.location.href = `gestionar.php?eliminar=${id}`;
    }
}

// Función auxiliar para colores por grado (debería estar en PHP, pero por simplicidad)
document.addEventListener('DOMContentLoaded', function() {
    const cards = document.querySelectorAll('.card');
    cards.forEach(card => {
        const header = card.querySelector('.card-header');
        if (header) {
            const colorClass = Array.from(header.classList).find(cls => cls.startsWith('bg-'));
            if (colorClass) {
                const color = colorClass.replace('bg-', '');
                const footerBtn = card.querySelector('.card-footer .btn');
                if (footerBtn) {
                    footerBtn.className = footerBtn.className.replace(/btn-\w+/, 'btn-' + color);
                }
            }
        }
    });
});
</script>

<style>
.card {
    transition: transform 0.2s ease-in-out;
}
.card:hover {
    transform: translateY(-2px);
}
.progress {
    min-width: 80px;
}
</style>

<?php 
// Función auxiliar para colores por grado
function getColorByGrado($numero) {
    $colores = [
        1 => 'primary',
        2 => 'success', 
        3 => 'info',
        4 => 'warning',
        5 => 'danger',
        6 => 'secondary'
    ];
    return $colores[$numero] ?? 'dark';
}
?>

<?php include '../../includes/footer.php'; ?>
