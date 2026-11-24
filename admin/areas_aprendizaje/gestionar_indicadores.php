<?php
require __DIR__ . '/redirigir_plan_anual.php';
// admin/areas_aprendizaje/gestionar_indicadores.php
require_once '../../includes/auth.php';
require_once '../../includes/database.php';
checkAuth();
checkRole(['admin']);

// Obtener todas las áreas con sus indicadores
try {
    $stmt = $pdo->prepare("
        SELECT aa.*, 
               COUNT(ai.id) as total_indicadores
        FROM areas_aprendizaje aa
        LEFT JOIN areas_indicadores ai ON aa.id = ai.area_id AND ai.estado = 'activo'
        WHERE aa.estado = 'activa'
        GROUP BY aa.id
        ORDER BY aa.nivel, aa.nombre
    ");
    $stmt->execute();
    $areas = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Obtener indicadores para cada área
    foreach ($areas as &$area) {
        $stmt = $pdo->prepare("
            SELECT * FROM areas_indicadores 
            WHERE area_id = ? AND estado = 'activo'
            ORDER BY orden, nombre
        ");
        $stmt->execute([$area['id']]);
        $area['indicadores'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    unset($area); // Romper la referencia
    
} catch (PDOException $e) {
    $areas = [];
    error_log("Error al cargar áreas: " . $e->getMessage());
}

// Cargar indicadores predefinidos por área
$indicadores_predefinidos = [
    'Lengua y Literatura' => [
        ['nombre' => 'Destreza cognitiva en la comunicación', 'orden' => 1],
        ['nombre' => 'Identifica, organiza la lectura', 'orden' => 2],
        ['nombre' => 'Expresa con claridad sus necesidades e intereses', 'orden' => 3],
        ['nombre' => 'Expresa sentimientos e ideas en forma diferentes', 'orden' => 4],
        ['nombre' => 'Forma palabras en grupos', 'orden' => 5]
    ],
    'Matemática' => [
        ['nombre' => 'Comprende diferentes procesos matemáticos', 'orden' => 1],
        ['nombre' => 'Lectura de Números Naturales', 'orden' => 2],
        ['nombre' => 'Realiza operaciones suma y resta', 'orden' => 3],
        ['nombre' => 'Reconoce Cantidades', 'orden' => 4],
        ['nombre' => 'Relaciona con objetos más que-menos que tanto como', 'orden' => 5],
        ['nombre' => 'Ordena números en recta', 'orden' => 6]
    ],
    'Ciencias Naturales' => [
        ['nombre' => 'Observa y describe fenómenos naturales', 'orden' => 1],
        ['nombre' => 'Identifica seres vivos y su entorno', 'orden' => 2],
        ['nombre' => 'Realiza experimentos sencillos', 'orden' => 3],
        ['nombre' => 'Cuida y valora el medio ambiente', 'orden' => 4]
    ],
    'Ciencias Sociales' => [
        ['nombre' => 'Conocimiento y valoración de los héroes patrios nacionales', 'orden' => 1],
        ['nombre' => 'Conmemoración y valoración de efemérides nacionales', 'orden' => 2],
        ['nombre' => 'Pueblos originarios', 'orden' => 3],
        ['nombre' => 'Valoración y aplicación de las normas de convivencias', 'orden' => 4]
    ],
    'Educación Física' => [
        ['nombre' => 'Manifiesta placer en las actividades en los que participa', 'orden' => 1],
        ['nombre' => 'Expresa respeto y tolerancia hacia los demás', 'orden' => 2],
        ['nombre' => 'Demuestra constancia y perseverancia en las actividades realizadas', 'orden' => 3],
        ['nombre' => 'Manifiesta confianza en sí misma', 'orden' => 4]
    ],
    'Inglés' => [
        ['nombre' => 'Demuestra Interés por la materia', 'orden' => 1],
        ['nombre' => 'Realiza practicas con entusiasmo', 'orden' => 2],
        ['nombre' => 'Participa con entusiasmo en los talleres de la materia', 'orden' => 3]
    ],
    'Arte y Patrimonio' => [
        ['nombre' => 'Expresa creatividad en sus trabajos', 'orden' => 1],
        ['nombre' => 'Muestra interés por las manifestaciones artísticas', 'orden' => 2],
        ['nombre' => 'Valora el patrimonio cultural', 'orden' => 3]
    ],
    'Formación Ciudadana' => [
        ['nombre' => 'Respeta las normas de convivencia', 'orden' => 1],
        ['nombre' => 'Muestra valores de solidaridad y respeto', 'orden' => 2],
        ['nombre' => 'Participa activamente en actividades comunitarias', 'orden' => 3]
    ]
];
?>

<?php include '../../includes/header.php'; ?>

<div class="content-wrapper" style="padding: 20px;">
    <?php include '../../includes/sidebar_admin.php'; ?>
    
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2>
            <i class="fas fa-list-check me-2"></i>Gestión de Indicadores por Área
        </h2>
        <div>
            <a href="gestionar.php" class="btn btn-outline-secondary me-2">
                <i class="fas fa-shapes me-2"></i>Gestionar Áreas
            </a>
            <a href="agregar.php" class="btn btn-primary">
                <i class="fas fa-plus me-2"></i>Nueva Área
            </a>
        </div>
    </div>

    <!-- Mensajes -->
    <?php if (isset($_SESSION['mensaje'])): ?>
        <div class="alert alert-<?php echo $_SESSION['tipo_mensaje']; ?> alert-dismissible fade show" role="alert">
            <?php echo $_SESSION['mensaje']; ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php unset($_SESSION['mensaje'], $_SESSION['tipo_mensaje']); ?>
    <?php endif; ?>

    <!-- Áreas con sus indicadores -->
    <div class="row">
        <?php foreach ($areas as $area): ?>
        <div class="col-md-6 mb-4">
            <div class="card h-100">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="card-title mb-0">
                        <span class="badge bg-primary me-2"><?php echo htmlspecialchars($area['codigo']); ?></span>
                        <?php echo htmlspecialchars($area['nombre']); ?>
                    </h5>
                    <div>
                        <span class="badge bg-secondary"><?php echo $area['total_indicadores']; ?> indicadores</span>
                    </div>
                </div>
                <div class="card-body">
                    <?php if (!empty($area['descripcion'])): ?>
                        <p class="card-text small text-muted"><?php echo htmlspecialchars($area['descripcion']); ?></p>
                    <?php endif; ?>
                    
                    <!-- Lista de indicadores -->
                    <div class="mb-3">
                        <h6 class="text-primary mb-2">
                            <i class="fas fa-bullseye me-1"></i>Indicadores de Logro:
                        </h6>
                        <?php if (!empty($area['indicadores'])): ?>
                            <ul class="list-group list-group-flush small">
                                <?php foreach ($area['indicadores'] as $indicador): ?>
                                    <li class="list-group-item d-flex justify-content-between align-items-center py-2 px-2">
                                        <div class="flex-grow-1">
                                            <span class="fw-medium"><?php echo htmlspecialchars($indicador['nombre']); ?></span>
                                            <?php if (!empty($indicador['descripcion'])): ?>
                                                <br><small class="text-muted"><?php echo htmlspecialchars($indicador['descripcion']); ?></small>
                                            <?php endif; ?>
                                        </div>
                                        <div class="btn-group btn-group-sm">
                                            <a href="editar_indicador.php?id=<?php echo $indicador['id']; ?>" 
                                               class="btn btn-outline-primary" title="Editar">
                                                <i class="fas fa-edit"></i>
                                            </a>
                                            <button class="btn btn-outline-danger" 
                                                    onclick="eliminarIndicador(<?php echo $indicador['id']; ?>, '<?php echo addslashes($indicador['nombre']); ?>')"
                                                    title="Eliminar">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        </div>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        <?php else: ?>
                            <div class="alert alert-warning py-2 mb-2">
                                <small><i class="fas fa-exclamation-triangle me-1"></i>No hay indicadores definidos</small>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="card-footer">
                    <div class="d-flex justify-content-between">
                        <a href="agregar_indicador.php?area_id=<?php echo $area['id']; ?>" 
                           class="btn btn-sm btn-success">
                            <i class="fas fa-plus me-1"></i>Agregar Indicador
                        </a>
                        
                        <!-- Cargar indicadores predefinidos -->
                        <?php if (isset($indicadores_predefinidos[$area['nombre']])): ?>
                        <form method="POST" action="procesar_indicadores_predefinidos.php" class="d-inline">
                            <input type="hidden" name="area_id" value="<?php echo $area['id']; ?>">
                            <button type="submit" class="btn btn-sm btn-info" 
                                    onclick="return confirm('¿Cargar indicadores predefinidos para <?php echo htmlspecialchars($area['nombre']); ?>? Los indicadores existentes se mantendrán.')">
                                <i class="fas fa-bolt me-1"></i>Cargar Predefinidos
                            </button>
                        </form>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- Si no hay áreas -->
    <?php if (empty($areas)): ?>
    <div class="text-center py-5">
        <i class="fas fa-inbox fa-3x text-muted mb-3"></i>
        <h4 class="text-muted">No hay áreas de aprendizaje activas</h4>
        <p class="text-muted">Comienza creando algunas áreas de aprendizaje.</p>
        <a href="agregar.php" class="btn btn-primary">
            <i class="fas fa-plus me-2"></i>Crear Primera Área
        </a>
    </div>
    <?php endif; ?>
</div>

<script>
function eliminarIndicador(indicadorId, indicadorNombre) {
    if (confirm(`¿Está seguro de eliminar el indicador "${indicadorNombre}"?`)) {
        fetch('eliminar_indicador.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: 'id=' + indicadorId
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                location.reload();
            } else {
                alert('Error al eliminar el indicador: ' + data.message);
            }
        })
        .catch(error => {
            alert('Error al eliminar el indicador');
            console.error('Error:', error);
        });
    }
}
</script>

<?php include '../../includes/footer.php'; ?>
