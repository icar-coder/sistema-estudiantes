<?php
$current_page = basename($_SERVER['PHP_SELF']);
$base = app_base_path();
?>

<div class="col-md-3 sidebar">
    <div class="p-3">
        <h5 class="text-white mb-4">Menú Estudiante</h5>
        <nav class="nav flex-column">
            <a class="nav-link <?php echo ($current_page === 'dashboard.php') ? 'active' : ''; ?>" href="<?php echo $base; ?>/estudiante/dashboard.php">
                <i class="fas fa-tachometer-alt me-2"></i>Dashboard
            </a>
            <a class="nav-link <?php echo ($current_page === 'ver.php' && strpos($_SERVER['PHP_SELF'], '/estudiante/notas/') !== false) ? 'active' : ''; ?>" href="<?php echo $base; ?>/estudiante/notas/ver.php">
                <i class="fas fa-chart-line me-2"></i>Mis Notas
            </a>
            <a class="nav-link <?php echo (strpos($_SERVER['PHP_SELF'], '/estudiante/horario/') !== false) ? 'active' : ''; ?>" href="<?php echo $base; ?>/estudiante/horario/ver.php">
                <i class="fas fa-calendar-alt me-2"></i>Horario
            </a>
            <a class="nav-link <?php echo (strpos($_SERVER['PHP_SELF'], '/estudiante/perfil/') !== false) ? 'active' : ''; ?>" href="<?php echo $base; ?>/estudiante/perfil/ver.php">
                <i class="fas fa-user me-2"></i>Mi Perfil
            </a>
        </nav>
    </div>
</div>
