<?php
require_once '../../includes/auth.php';
require_once '../../includes/database.php';
checkAuth();
checkRole(['estudiante']);

$estudiante_id = $_SESSION['relacion_id'] ?? null;
$mensaje = '';

if (!$estudiante_id) {
    $mensaje = 'No se pudo identificar al estudiante actual.';
}
?>

<?php include '../../includes/header.php'; ?>

<div class="row">
    <?php include '../../includes/sidebar_estudiante.php'; ?>

    <div class="col-md-9">
        <div class="p-4">
            <h2>Mis Notas</h2>

            <?php if ($mensaje): ?>
                <div class="alert alert-warning"><?php echo htmlspecialchars($mensaje); ?></div>
            <?php else: ?>
                <div class="alert alert-info">
                    Esta vista de notas está disponible para el estudiante autenticado y se completará con el módulo académico correspondiente.
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php include '../../includes/footer.php'; ?>
