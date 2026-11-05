<?php
require_once '../../includes/auth.php';
require_once '../../includes/database.php';
checkAuth();
checkRole(['estudiante']);

$usuario = $_SESSION['username'] ?? 'Estudiante';
?>

<?php include '../../includes/header.php'; ?>

<div class="row">
    <?php include '../../includes/sidebar_estudiante.php'; ?>

    <div class="col-md-9">
        <div class="p-4">
            <h2>Mi Perfil</h2>
            <div class="card">
                <div class="card-body">
                    <p><strong>Usuario:</strong> <?php echo htmlspecialchars($usuario); ?></p>
                    <p class="mb-0">La información de perfil del estudiante se completará en la próxima etapa del módulo.</p>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include '../../includes/footer.php'; ?>
