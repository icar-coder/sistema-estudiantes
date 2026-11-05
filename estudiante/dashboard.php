<?php
require_once '../includes/auth.php';
require_once '../includes/database.php';
checkAuth();
checkRole(['estudiante']);
?>

<?php include '../includes/header.php'; ?>

<div class="row">
    <?php include '../includes/sidebar_estudiante.php'; ?>

    <div class="col-md-9">
        <div class="p-4">
            <h2>Dashboard Estudiante</h2>
            <p>Bienvenido a tu panel estudiantil. Aquí puedes ver tus notas, horario y perfil.</p>
        </div>
    </div>
</div>

<?php include '../includes/footer.php'; ?>