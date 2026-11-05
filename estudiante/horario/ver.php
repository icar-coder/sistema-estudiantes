<?php
require_once '../../includes/auth.php';
require_once '../../includes/database.php';
checkAuth();
checkRole(['estudiante']);
?>

<?php include '../../includes/header.php'; ?>

<div class="row">
    <?php include '../../includes/sidebar_estudiante.php'; ?>

    <div class="col-md-9">
        <div class="p-4">
            <h2>Horario</h2>
            <div class="alert alert-info">
                El horario del estudiante se mostrará aquí cuando se complete la vista académica del módulo.
            </div>
        </div>
    </div>
</div>

<?php include '../../includes/footer.php'; ?>
