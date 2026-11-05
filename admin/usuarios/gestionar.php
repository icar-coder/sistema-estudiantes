<?php
require_once '../../includes/auth.php';
require_once '../../includes/database.php';
checkAuth();
checkRole(['admin']);

if (isset($_SESSION['usuario_mensaje'])) {
    $mensaje = $_SESSION['usuario_mensaje'];
    $tipo_mensaje = $_SESSION['usuario_tipo_mensaje'] ?? 'info';
    unset($_SESSION['usuario_mensaje'], $_SESSION['usuario_tipo_mensaje']);
} else {
    $mensaje = '';
    $tipo_mensaje = '';
}

// Obtener lista de usuarios
try {
    $stmt = $pdo->query("
        SELECT u.*, 
               p.nombre as prof_nombre, p.apellido as prof_apellido,
               e.nombre as est_nombre, e.apellido as est_apellido
        FROM usuarios u
        LEFT JOIN profesores p ON u.relacion_id = p.id AND u.rol = 'profesor'
        LEFT JOIN estudiantes e ON u.relacion_id = e.id AND u.rol = 'estudiante'
        ORDER BY u.username
    ");
    $usuarios = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $usuarios = [];
    error_log("Error al cargar usuarios: " . $e->getMessage());
}

// Procesar cambio de estado
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cambiar_estado'])) {
    $usuario_id = $_POST['usuario_id'];
    $nuevo_estado = $_POST['nuevo_estado'];
    
    try {
        $stmt = $pdo->prepare("UPDATE usuarios SET estado = ? WHERE id = ?");
        $stmt->execute([$nuevo_estado, $usuario_id]);
        
        $mensaje = "Estado actualizado correctamente";
        $tipo_mensaje = "success";
        
        // Recargar la pÃ¡gina para ver los cambios
        header("Location: gestionar.php");
        exit();
        
    } catch (PDOException $e) {
        $mensaje = "Error al actualizar estado: " . $e->getMessage();
        $tipo_mensaje = "danger";
    }
}
?>

<?php include '../../includes/header.php'; ?>


<div class="content-wrapper" style="padding: 20px;">
    <?php include '../../includes/sidebar_admin.php'; ?>


            <div class="d-flex justify-content-between align-items-center mb-4">
                <h2>GestiÃ³n de Usuarios</h2>
                <a href="crear.php" class="btn btn-primary">
                    <i class="fas fa-user-plus me-2"></i>Nuevo Usuario
                </a>
            </div>

            <?php if (isset($mensaje)): ?>
                <div class="alert alert-<?php echo $tipo_mensaje; ?> alert-dismissible fade show" role="alert">
                    <?php echo $mensaje; ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <div class="card">
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-striped table-hover">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Usuario</th>
                                    <th>Email</th>
                                    <th>Rol</th>
                                    <th>Persona Relacionada</th>
                                    <th>Estado</th>
                                    <th>Ãšltimo Login</th>
                                    <th>Acciones</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($usuarios as $usuario): ?>
                                    <tr>
                                        <td><?php echo $usuario['id']; ?></td>
                                        <td>
                                            <strong><?php echo htmlspecialchars($usuario['username']); ?></strong>
                                        </td>
                                        <td><?php echo htmlspecialchars($usuario['email'] ?? '-'); ?></td>
                                        <td>
                                            <span class="badge bg-<?php 
                                                echo $usuario['rol'] == 'admin' ? 'danger' : 
                                                     ($usuario['rol'] == 'profesor' ? 'primary' : 'success'); 
                                            ?>">
                                                <?php echo ucfirst($usuario['rol']); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php if ($usuario['rol'] == 'profesor' && $usuario['prof_nombre']): ?>
                                                <?php echo htmlspecialchars($usuario['prof_nombre'] . ' ' . $usuario['prof_apellido']); ?>
                                            <?php elseif ($usuario['rol'] == 'estudiante' && $usuario['est_nombre']): ?>
                                                <?php echo htmlspecialchars($usuario['est_nombre'] . ' ' . $usuario['est_apellido']); ?>
                                            <?php else: ?>
                                                <span class="text-muted">-</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <form method="POST" class="d-inline">
                                                <input type="hidden" name="usuario_id" value="<?php echo $usuario['id']; ?>">
                                                <input type="hidden" name="nuevo_estado" value="<?php echo $usuario['estado'] == 'activo' ? 'inactivo' : 'activo'; ?>">
                                                <button type="submit" name="cambiar_estado" 
                                                        class="btn btn-sm btn-<?php echo $usuario['estado'] == 'activo' ? 'success' : 'secondary'; ?>">
                                                    <?php echo ucfirst($usuario['estado']); ?>
                                                </button>
                                            </form>
                                        </td>
                                        <td>
                                            <?php if ($usuario['ultimo_login']): ?>
                                                <?php echo date('d/m/Y H:i', strtotime($usuario['ultimo_login'])); ?>
                                            <?php else: ?>
                                                <span class="text-muted">Nunca</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div class="btn-group btn-group-sm">
                                                <a href="editar.php?id=<?php echo (int)$usuario['id']; ?>" class="btn btn-outline-primary" title="Editar">
                                                    <i class="fas fa-edit"></i>
                                                </a>
                                                <button type="button" class="btn btn-outline-danger" title="Eliminar"
                                                        onclick="confirmarEliminarUsuario(<?php echo (int)$usuario['id']; ?>, '<?php echo htmlspecialchars($usuario['username'], ENT_QUOTES); ?>')">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
function confirmarEliminarUsuario(id, username) {
    if (confirm('Â¿EstÃ¡s seguro de que deseas desactivar al usuario "' + username + '"?\n\nEsta acciÃ³n marcarÃ¡ el usuario como inactivo y no podrÃ¡ iniciar sesiÃ³n.')) {
        window.location.href = 'eliminar.php?id=' + id;
    }
}
</script>

<?php include '../../includes/footer.php'; ?>
