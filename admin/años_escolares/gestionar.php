<?php
require_once '../../includes/auth.php';
require_once '../../includes/database.php';
checkAuth();
checkRole(['admin']);

$mensaje = '';
$tipo_mensaje = '';

// Procesar activación/desactivación
if (isset($_GET['cambiar_estado'])) {
    $id = $_GET['cambiar_estado'];
    $nuevo_estado = $_GET['estado'];
    
    try {
        // Si se activa un año, desactivar los demás
        if ($nuevo_estado == 'activo') {
            $stmt = $pdo->prepare("UPDATE años_escolares SET estado = 'inactivo' WHERE estado = 'activo'");
            $stmt->execute();
        }
        
        $stmt = $pdo->prepare("UPDATE años_escolares SET estado = ? WHERE id = ?");
        $stmt->execute([$nuevo_estado, $id]);
        
        $mensaje = "Estado del año escolar actualizado correctamente";
        $tipo_mensaje = "success";
    } catch (PDOException $e) {
        $mensaje = "Error al actualizar año escolar: " . $e->getMessage();
        $tipo_mensaje = "danger";
    }
}

// Procesar eliminación
if (isset($_GET['eliminar'])) {
    $id = $_GET['eliminar'];
    
    try {
        // Verificar si el año escolar tiene secciones asociadas
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM secciones WHERE año_escolar_id = ?");
        $stmt->execute([$id]);
        $tiene_secciones = $stmt->fetchColumn();
        
        if ($tiene_secciones > 0) {
            $mensaje = "No se puede eliminar el año escolar porque tiene secciones asociadas";
            $tipo_mensaje = "warning";
        } else {
            $stmt = $pdo->prepare("DELETE FROM años_escolares WHERE id = ?");
            $stmt->execute([$id]);
            $mensaje = "Año escolar eliminado correctamente";
            $tipo_mensaje = "success";
        }
    } catch (PDOException $e) {
        $mensaje = "Error al eliminar año escolar: " . $e->getMessage();
        $tipo_mensaje = "danger";
    }
}

// Obtener lista de años escolares
try {
    $stmt = $pdo->query("
        SELECT ae.*,
               COUNT(s.id) as total_secciones,
               COUNT(i.id) as total_inscripciones
        FROM años_escolares ae
        LEFT JOIN secciones s ON ae.id = s.año_escolar_id
        LEFT JOIN inscripciones i ON ae.id = i.año_escolar_id
        GROUP BY ae.id
        ORDER BY ae.fecha_inicio DESC
    ");
    $años_escolares = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $años_escolares = [];
    $mensaje = "Error al cargar años escolares: " . $e->getMessage();
    $tipo_mensaje = "danger";
}
?>

<?php include '../../includes/header.php'; ?>

   <?php include '../../includes/sidebar_admin.php'; ?>

<div class="content-wrapper" style="padding: 20px;">

  

            <div class="d-flex justify-content-between align-items-center mb-4">
                <h2>Gestión de Años Escolares</h2>
                <a href="agregar.php" class="btn btn-primary">
                    <i class="fas fa-plus me-2"></i>Nuevo Año Escolar
                </a>
            </div>

            <?php if ($mensaje): ?>
                <div class="alert alert-<?php echo $tipo_mensaje; ?> alert-dismissible fade show" role="alert">
                    <?php echo $mensaje; ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <div class="card">
                <div class="card-header">
                    <h5 class="card-title mb-0">Lista de Años Escolares</h5>
                </div>
                <div class="card-body">
                    <?php if ($años_escolares): ?>
                        <div class="table-responsive">
                            <table class="table table-striped table-hover">
                                <thead>
                                    <tr>
                                        <th>Nombre</th>
                                        <th>Fecha Inicio</th>
                                        <th>Fecha Fin</th>
                                        <th>Secciones</th>
                                        <th>Inscripciones</th>
                                        <th>Estado</th>
                                        <th>Acciones</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($años_escolares as $año): ?>
                                        <tr>
                                            <td>
                                                <strong><?php echo htmlspecialchars($año['nombre']); ?></strong>
                                            </td>
                                            <td>
                                                <?php echo $año['fecha_inicio'] ? date('d/m/Y', strtotime($año['fecha_inicio'])) : '<span class="text-muted">No definida</span>'; ?>
                                            </td>
                                            <td>
                                                <?php echo $año['fecha_fin'] ? date('d/m/Y', strtotime($año['fecha_fin'])) : '<span class="text-muted">No definida</span>'; ?>
                                            </td>
                                            <td>
                                                <span class="badge bg-info"><?php echo $año['total_secciones']; ?></span>
                                            </td>
                                            <td>
                                                <span class="badge bg-primary"><?php echo $año['total_inscripciones']; ?></span>
                                            </td>
                                            <td>
                                                <?php 
                                                $badge_class = [
                                                    'activo' => 'bg-success',
                                                    'inactivo' => 'bg-secondary',
                                                    'planificado' => 'bg-warning'
                                                ];
                                                $estado_text = [
                                                    'activo' => 'Activo',
                                                    'inactivo' => 'Inactivo',
                                                    'planificado' => 'Planificado'
                                                ];
                                                ?>
                                                <span class="badge <?php echo $badge_class[$año['estado']] ?? 'bg-secondary'; ?>">
                                                    <?php echo $estado_text[$año['estado']] ?? 'Desconocido'; ?>
                                                </span>
                                            </td>
                                            <td>
                                                <div class="btn-group btn-group-sm">
                                                    <a href="configurar_plan.php?año_escolar_id=<?php echo $año['id']; ?>"
                                                       class="btn btn-outline-info"
                                                       title="Configurar áreas e indicadores del año">
                                                        <i class="fas fa-sitemap"></i>
                                                    </a>
                                                    <a href="editar.php?id=<?php echo $año['id']; ?>" 
                                                       class="btn btn-outline-primary" 
                                                       title="Editar">
                                                        <i class="fas fa-edit"></i>
                                                    </a>
                                                    
                                                    <?php if ($año['estado'] != 'activo'): ?>
                                                        <a href="gestionar.php?cambiar_estado=<?php echo $año['id']; ?>&estado=activo" 
                                                           class="btn btn-outline-success" 
                                                           title="Activar Año"
                                                           onclick="return confirm('¿Estás seguro de activar el año escolar <?php echo htmlspecialchars($año['nombre']); ?>?')">
                                                            <i class="fas fa-play"></i>
                                                        </a>
                                                    <?php endif; ?>
                                                    
                                                    <?php if ($año['estado'] == 'activo'): ?>
                                                        <a href="gestionar.php?cambiar_estado=<?php echo $año['id']; ?>&estado=inactivo" 
                                                           class="btn btn-outline-warning" 
                                                           title="Desactivar Año"
                                                           onclick="return confirm('¿Estás seguro de desactivar el año escolar <?php echo htmlspecialchars($año['nombre']); ?>?')">
                                                            <i class="fas fa-pause"></i>
                                                        </a>
                                                    <?php endif; ?>
                                                    
                                                    <?php if ($año['estado'] == 'planificado'): ?>
                                                        <button type="button" 
                                                                class="btn btn-outline-danger" 
                                                                title="Eliminar"
                                                                onclick="confirmarEliminacion(<?php echo $año['id']; ?>, '<?php echo htmlspecialchars($año['nombre']); ?>')">
                                                            <i class="fas fa-trash"></i>
                                                        </button>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        
                        <!-- Información importante -->
                        <div class="alert alert-info mt-3">
                            <h6><i class="fas fa-info-circle me-2"></i>Información Importante</h6>
                            <ul class="mb-0">
                                <li>Solo puede haber <strong>un año escolar activo</strong> a la vez</li>
                                <li>Al activar un año escolar, los demás se desactivan automáticamente</li>
                                <li>Solo se pueden eliminar años escolares en estado <strong>"Planificado"</strong></li>
                                <li>El año escolar activo es el que se usa para nuevas inscripciones</li>
                            </ul>
                        </div>
                    <?php else: ?>
                        <div class="text-center py-4">
                            <i class="fas fa-calendar-alt fa-3x text-muted mb-3"></i>
                            <h5>No hay años escolares registrados</h5>
                            <p class="text-muted">Comienza agregando el primer año escolar al sistema.</p>
                            <a href="agregar.php" class="btn btn-primary">
                                <i class="fas fa-plus me-2"></i>Agregar Primer Año Escolar
                            </a>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
function confirmarEliminacion(id, nombre) {
    if (confirm(`¿Estás seguro de que deseas eliminar el año escolar "${nombre}"?\n\nEsta acción no se puede deshacer.`)) {
        window.location.href = `gestionar.php?eliminar=${id}`;
    }
}
</script>

<?php include '../../includes/footer.php'; ?>
