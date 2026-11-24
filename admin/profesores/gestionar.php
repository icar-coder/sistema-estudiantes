<?php
require_once '../../includes/auth.php';
require_once '../../includes/database.php';
checkAuth();
checkRole(['admin']);

$mensaje = '';
$tipo_mensaje = '';

// Procesar eliminación
if (isset($_GET['eliminar'])) {
    $id = $_GET['eliminar'];
    
    try {
        // Verificar si el profesor tiene secciones asignadas como titular
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM secciones WHERE profesor_id = ?");
        $stmt->execute([$id]);
        $tiene_secciones = $stmt->fetchColumn();
        
        if ($tiene_secciones > 0) {
            $mensaje = "No se puede eliminar el profesor porque es titular de una o más secciones";
            $tipo_mensaje = "warning";
        } else {
            $stmt = $pdo->prepare("DELETE FROM profesores WHERE id = ?");
            $stmt->execute([$id]);
            $mensaje = "Profesor eliminado correctamente";
            $tipo_mensaje = "success";
        }
    } catch (PDOException $e) {
        $mensaje = "Error al eliminar profesor: " . $e->getMessage();
        $tipo_mensaje = "danger";
    }
}

// Procesar cambio de estado
if (isset($_GET['cambiar_estado'])) {
    $id = $_GET['cambiar_estado'];
    $nuevo_estado = $_GET['estado'];
    
    try {
        $stmt = $pdo->prepare("UPDATE profesores SET estado = ? WHERE id = ?");
        $stmt->execute([$nuevo_estado, $id]);
        
        $mensaje = "Estado del profesor actualizado correctamente";
        $tipo_mensaje = "success";
    } catch (PDOException $e) {
        $mensaje = "Error al actualizar profesor: " . $e->getMessage();
        $tipo_mensaje = "danger";
    }
}

// Obtener lista de profesores con información de secciones
try {
    $seccionesAsignadasSql = isSqliteDatabase($pdo)
        ? "GROUP_CONCAT(DISTINCT CONCAT(s.nombre, ' (', ae.nombre, ')'))"
        : "GROUP_CONCAT(DISTINCT CONCAT(s.nombre, ' (', ae.nombre, ')') SEPARATOR ', ')";
    $stmt = $pdo->query("
        SELECT p.*, 
               COUNT(DISTINCT s.id) as total_secciones,
               {$seccionesAsignadasSql} as secciones_asignadas
        FROM profesores p 
        LEFT JOIN secciones s ON p.id = s.profesor_id 
        LEFT JOIN años_escolares ae ON s.año_escolar_id = ae.id
        GROUP BY p.id 
        ORDER BY p.estado DESC, p.apellido, p.nombre
    ");
    $profesores = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $profesores = [];
    $mensaje = "Error al cargar profesores: " . $e->getMessage();
    $tipo_mensaje = "danger";
}
?>

<?php include '../../includes/header.php'; ?>

<div class="content-wrapper" style="padding: 20px;">
    <?php include '../../includes/sidebar_admin.php'; ?>
    
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2>Gestión de Profesores - PRIMARIA</h2>
        <a href="agregar.php" class="btn btn-primary">
            <i class="fas fa-user-plus me-2"></i>Nuevo Profesor
        </a>
    </div>

    <?php if ($mensaje): ?>
        <div class="alert alert-<?php echo $tipo_mensaje; ?> alert-dismissible fade show" role="alert">
            <?php echo $mensaje; ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- Filtros rápidos -->
    <div class="card mb-4">
        <div class="card-header bg-light">
            <h6 class="card-title mb-0">
                <i class="fas fa-filter me-2"></i>Filtros Rápidos
            </h6>
        </div>
        <div class="card-body py-2">
            <div class="btn-group">
                <button type="button" class="btn btn-outline-primary btn-sm active" onclick="filtrarProfesores('todos')">
                    Todos (<?php echo count($profesores); ?>)
                </button>
                <button type="button" class="btn btn-outline-success btn-sm" onclick="filtrarProfesores('activo')">
                    Activos (<?php echo count(array_filter($profesores, function($p) { return $p['estado'] == 'activo'; })); ?>)
                </button>
                <button type="button" class="btn btn-outline-warning btn-sm" onclick="filtrarProfesores('licencia')">
                    En Licencia (<?php echo count(array_filter($profesores, function($p) { return $p['estado'] == 'licencia'; })); ?>)
                </button>
                <button type="button" class="btn btn-outline-secondary btn-sm" onclick="filtrarProfesores('inactivo')">
                    Inactivos (<?php echo count(array_filter($profesores, function($p) { return $p['estado'] == 'inactivo'; })); ?>)
                </button>
                <button type="button" class="btn btn-outline-info btn-sm" onclick="filtrarProfesores('con_secciones')">
                    Con Secciones (<?php echo count(array_filter($profesores, function($p) { return $p['total_secciones'] > 0; })); ?>)
                </button>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-header">
            <h5 class="card-title mb-0">Lista de Profesores</h5>
        </div>
        <div class="card-body">
            <?php if ($profesores): ?>
                <div class="table-responsive">
                    <table class="table table-striped table-hover" id="tabla-profesores">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Cédula</th>
                                <th>Nombre Completo</th>
                                <th>Especialidad</th>
                                <th>Email</th>
                                <th>Teléfono</th>
                                <th>Secciones</th>
                                <th>Estado</th>
                                <th>Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($profesores as $profesor): ?>
                                <tr class="fila-profesor" data-estado="<?php echo $profesor['estado']; ?>" data-secciones="<?php echo $profesor['total_secciones'] > 0 ? 'con_secciones' : 'sin_secciones'; ?>">
                                    <td><?php echo $profesor['id']; ?></td>
                                    <td>
                                        <?php if ($profesor['cedula']): ?>
                                            <span class="badge bg-secondary"><?php echo htmlspecialchars($profesor['cedula']); ?></span>
                                        <?php else: ?>
                                            <span class="text-muted">Sin cédula</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <strong><?php echo htmlspecialchars($profesor['nombre'] . ' ' . $profesor['apellido']); ?></strong>
                                        <?php if ($profesor['total_secciones'] > 0): ?>
                                            <br>
                                            <small class="text-success">
                                                <i class="fas fa-chalkboard-teacher me-1"></i>
                                                Titular de <?php echo $profesor['total_secciones']; ?> sección(es)
                                            </small>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($profesor['especialidad']): ?>
                                            <span class="badge bg-info"><?php echo htmlspecialchars($profesor['especialidad']); ?></span>
                                        <?php else: ?>
                                            <span class="text-muted">No especificada</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($profesor['email']): ?>
                                            <a href="mailto:<?php echo htmlspecialchars($profesor['email']); ?>" class="text-decoration-none">
                                                <?php echo htmlspecialchars($profesor['email']); ?>
                                            </a>
                                        <?php else: ?>
                                            <span class="text-muted">No tiene</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($profesor['telefono']): ?>
                                            <a href="tel:<?php echo htmlspecialchars($profesor['telefono']); ?>" class="text-decoration-none">
                                                <?php echo htmlspecialchars($profesor['telefono']); ?>
                                            </a>
                                        <?php else: ?>
                                            <span class="text-muted">No tiene</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="badge bg-<?php echo $profesor['total_secciones'] > 0 ? 'success' : 'secondary'; ?>">
                                            <?php echo $profesor['total_secciones']; ?>
                                        </span>
                                        <?php if ($profesor['secciones_asignadas']): ?>
                                            <br>
                                            <small class="text-muted" title="<?php echo htmlspecialchars($profesor['secciones_asignadas']); ?>">
                                                <?php 
                                                $secciones = explode(', ', $profesor['secciones_asignadas']);
                                                echo count($secciones) > 2 
                                                    ? htmlspecialchars($secciones[0] . ', ' . $secciones[1] . '...') 
                                                    : htmlspecialchars($profesor['secciones_asignadas']);
                                                ?>
                                            </small>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php 
                                        $badge_class = [
                                            'activo' => 'bg-success',
                                            'inactivo' => 'bg-secondary',
                                            'licencia' => 'bg-warning'
                                        ];
                                        $estado_text = [
                                            'activo' => 'Activo',
                                            'inactivo' => 'Inactivo',
                                            'licencia' => 'En Licencia'
                                        ];
                                        ?>
                                        <span class="badge <?php echo $badge_class[$profesor['estado']] ?? 'bg-secondary'; ?>">
                                            <?php echo $estado_text[$profesor['estado']] ?? 'Desconocido'; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div class="btn-group btn-group-sm">
                                            <!-- Botón Editar -->
                                            <a href="editar.php?id=<?php echo $profesor['id']; ?>" 
                                               class="btn btn-outline-primary" 
                                               title="Editar">
                                                <i class="fas fa-edit"></i>
                                            </a>
                                            
                                            <!-- Botón Asignar Secciones -->
                                            <a href="asignaciones.php?profesor_id=<?php echo $profesor['id']; ?>"
                                               class="btn btn-outline-success" 
                                               title="Asignar grados y secciones">
                                                <i class="fas fa-chalkboard-teacher"></i>
                                            </a>
                                            
                                            <!-- Gestión de Estados -->
                                            <?php if ($profesor['estado'] == 'activo'): ?>
                                                <a href="gestionar.php?cambiar_estado=<?php echo $profesor['id']; ?>&estado=licencia" 
                                                   class="btn btn-outline-warning" 
                                                   title="Marcar como Licencia"
                                                   onclick="return confirm('¿Cambiar estado a Licencia? El profesor mantendrá sus secciones pero no podrá recibir nuevas.')">
                                                    <i class="fas fa-umbrella-beach"></i>
                                                </a>
                                                <a href="gestionar.php?cambiar_estado=<?php echo $profesor['id']; ?>&estado=inactivo" 
                                                   class="btn btn-outline-secondary" 
                                                   title="Desactivar"
                                                   onclick="return confirm('¿Desactivar profesor? Se removerá de todas sus secciones.')">
                                                    <i class="fas fa-pause"></i>
                                                </a>
                                            <?php elseif ($profesor['estado'] == 'licencia'): ?>
                                                <a href="gestionar.php?cambiar_estado=<?php echo $profesor['id']; ?>&estado=activo" 
                                                   class="btn btn-outline-success" 
                                                   title="Reactivar de Licencia"
                                                   onclick="return confirm('¿Reactivar profesor de licencia?')">
                                                    <i class="fas fa-play"></i>
                                                </a>
                                                <a href="gestionar.php?cambiar_estado=<?php echo $profesor['id']; ?>&estado=inactivo" 
                                                   class="btn btn-outline-secondary" 
                                                   title="Desactivar"
                                                   onclick="return confirm('¿Desactivar profesor? Se removerá de todas sus secciones.')">
                                                    <i class="fas fa-pause"></i>
                                                </a>
                                            <?php else: ?>
                                                <a href="gestionar.php?cambiar_estado=<?php echo $profesor['id']; ?>&estado=activo" 
                                                   class="btn btn-outline-success" 
                                                   title="Activar"
                                                   onclick="return confirm('¿Activar profesor? Podrá recibir asignaciones.')">
                                                    <i class="fas fa-play"></i>
                                                </a>
                                            <?php endif; ?>
                                            
                                            <!-- Botón Eliminar -->
                                            <button type="button" 
                                                    class="btn btn-outline-danger" 
                                                    title="Eliminar"
                                                    onclick="confirmarEliminacion(<?php echo $profesor['id']; ?>, '<?php echo htmlspecialchars($profesor['nombre'] . ' ' . $profesor['apellido']); ?>', <?php echo $profesor['total_secciones']; ?>)"
                                                    <?php echo $profesor['total_secciones'] > 0 ? 'disabled' : ''; ?>>
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                
                <!-- Información del sistema -->
                <div class="alert alert-info mt-4">
                    <h6><i class="fas fa-info-circle me-2"></i>Información del Sistema - PRIMARIA</h6>
                    <ul class="mb-0">
                        <li><strong>Profesor Titular:</strong> Cada profesor puede ser titular de una o más secciones</li>
                        <li><strong>Asignación Automática:</strong> El profesor titular recibe TODAS las áreas de aprendizaje de su sección</li>
                        <li><strong>Restricción de Eliminación:</strong> No se pueden eliminar profesores con secciones asignadas</li>
                        <li><strong>Estados:</strong> 
                            <strong>Activo</strong> → Puede recibir asignaciones |
                            <strong>Licencia</strong> → Temporalmente no disponible (mantiene secciones) |
                            <strong>Inactivo</strong> → No disponible (pierde secciones)
                        </li>
                    </ul>
                </div>
                
            <?php else: ?>
                <div class="text-center py-4">
                    <i class="fas fa-chalkboard-teacher fa-3x text-muted mb-3"></i>
                    <h5>No hay profesores registrados</h5>
                    <p class="text-muted">Comienza agregando el primer profesor al sistema.</p>
                    <a href="agregar.php" class="btn btn-primary">
                        <i class="fas fa-user-plus me-2"></i>Agregar Primer Profesor
                    </a>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
function confirmarEliminacion(id, nombre, tieneSecciones) {
    if (tieneSecciones > 0) {
        alert('No se puede eliminar este profesor porque tiene secciones asignadas como titular.');
        return;
    }
    
    if (confirm(`¿Estás seguro de que deseas eliminar al profesor "${nombre}"?\n\nEsta acción no se puede deshacer.`)) {
        window.location.href = `gestionar.php?eliminar=${id}`;
    }
}

function filtrarProfesores(filtro) {
    const filas = document.querySelectorAll('.fila-profesor');
    let contador = 0;
    
    filas.forEach(fila => {
        let mostrar = false;
        
        switch(filtro) {
            case 'todos':
                mostrar = true;
                break;
            case 'activo':
                mostrar = fila.getAttribute('data-estado') === 'activo';
                break;
            case 'licencia':
                mostrar = fila.getAttribute('data-estado') === 'licencia';
                break;
            case 'inactivo':
                mostrar = fila.getAttribute('data-estado') === 'inactivo';
                break;
            case 'con_secciones':
                mostrar = fila.getAttribute('data-secciones') === 'con_secciones';
                break;
        }
        
        if (mostrar) {
            fila.style.display = '';
            contador++;
        } else {
            fila.style.display = 'none';
        }
    });
    
    // Actualizar botones activos
    document.querySelectorAll('.btn-group .btn').forEach(btn => {
        btn.classList.remove('active');
    });
    event.target.classList.add('active');
    
    // Mostrar contador si no es "todos"
    if (filtro !== 'todos') {
        console.log(`Mostrando ${contador} profesores`);
    }
}

// Inicializar filtros
document.addEventListener('DOMContentLoaded', function() {
    // Activar el primer botón de filtro
    document.querySelector('.btn-group .btn').classList.add('active');
});
</script>

<?php include '../../includes/footer.php'; ?>
