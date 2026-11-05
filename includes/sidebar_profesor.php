<?php
// includes/sidebar_profesor.php
$base_url = "http://localhost/SistemaEstudiantes";
$current_page = basename($_SERVER['PHP_SELF']);
?>

<nav class="main-menu">
    <div class="menu-overlay"></div>
    
    <!-- Sección de Perfil -->
    <div class="profile-section">
        <div class="profile-image">
            <img src="<?php echo $base_url; ?>/assets/images/default-avatar.png" alt="Perfil" 
                 onerror="this.src='data:image/svg+xml;base64,PHN2ZyB3aWR0aD0iNDAiIGhlaWdodD0iNDAiIHZpZXdCb3g9IjAgMCA0MCA0MCIgZmlsbD0ibm9uZSIgeG1sbnM9Imh0dHA6Ly93d3cudzMub3JnLzIwMDAvc3ZnIj4KPGNpcmNsZSBjeD0iMjAiIGN5PSIyMCIgcj0iMjAiIGZpbGw9IiMyNzk0ODQiLz4KPHBhdGggZD0iTTIwIDIyQzIzLjMxMzcgMjIgMjYgMTkuMzEzNyAyNiAxNkMyNiAxMi42ODYzIDIzLjMxMzcgMTAgMjAgMTBDMTYuNjg2MyAxMCAxNCAxMi42ODYzIDE0IDE2QzE0IDE5LjMxMzcgMTYuNjg2MyAyMiAyMCAyMloiIGZpbGw9IndoaXRlIi8+CjxwYXRoIGQ9Ik0yOCAzMEMyOCAyNy43OTA5IDI2LjIwOTEgMjYgMjQgMjZIMTYuMDAwMUMxMy43OTEgMjYgMTIgMjcuNzkwOSAxMiAzMEMxMiAzMi4yMDkxIDEzLjc5MSAzNCAxNi4wMDAxIDM0SDI0QzI2LjIwOTEgMzQgMjggMzIuMjA5MSAyOCAzMFoiIGZpbGw9IndoaXRlIi8+Cjwvc3ZnPgo='">
        </div>
        <div class="profile-info">
            <div class="profile-name"><?php echo $_SESSION['nombre'] ?? 'Profesor'; ?></div>
            <div class="profile-role"><?php echo ucfirst($_SESSION['rol'] ?? 'Profesor'); ?></div>
        </div>
    </div>
    
    <ul>
        <li class="<?php echo $current_page == 'dashboard.php' ? 'active' : ''; ?>">
            <a href="<?php echo $base_url; ?>/profesor/dashboard.php">
                <i class="nav-icon fa fa-home"></i>
                <span class="nav-text">Dashboard</span>
            </a>
        </li>
        
        <!-- EVALUACIÓN POR LAPSO -->
        <li class="has-subnav <?php echo strpos($_SERVER['PHP_SELF'], 'evaluaciones') !== false ? 'active' : ''; ?>">
            <a href="<?php echo $base_url; ?>/profesor/evaluaciones/gestionar.php">
                <i class="nav-icon fa fa-chart-line"></i>
                <span class="nav-text">Evaluación por Lapso</span>
            </a>
        </li>
        
        <!-- ASISTENCIAS -->
        <li class="has-subnav <?php echo strpos($_SERVER['PHP_SELF'], 'asistencias') !== false ? 'active' : ''; ?>">
            <a href="<?php echo $base_url; ?>/profesor/asistencias/registrar.php">
                <i class="nav-icon fa fa-clipboard-list"></i>
                <span class="nav-text">Asistencias</span>
            </a>
        </li>
        
        <!-- MIS MATERIAS -->
        <li class="has-subnav <?php echo strpos($_SERVER['PHP_SELF'], 'materias') !== false ? 'active' : ''; ?>">
            <a href="<?php echo $base_url; ?>/profesor/materias/mis_materias.php">
                <i class="nav-icon fa fa-book"></i>
                <span class="nav-text">Mis Materias</span>
            </a>
        </li>
        
        <!-- REPORTES DE PROFESOR -->
        <li class="has-subnav <?php echo strpos($_SERVER['PHP_SELF'], 'reportes') !== false ? 'active' : ''; ?>">
            <a href="<?php echo $base_url; ?>/profesor/evaluaciones/gestionar.php">
                <i class="nav-icon fa fa-chart-bar"></i>
                <span class="nav-text">Reportes</span>
            </a>
        </li>
        
        <!-- PERFIL -->
        <li class="has-subnav <?php echo strpos($_SERVER['PHP_SELF'], 'perfil') !== false ? 'active' : ''; ?>">
            <a href="<?php echo $base_url; ?>/profesor/perfil/ver.php">
                <i class="nav-icon fa fa-user"></i>
                <span class="nav-text">Mi Perfil</span>
            </a>
        </li>
    </ul>

    <ul class="logout">
        <li>
            <a href="#" data-bs-toggle="modal" data-bs-target="#logoutModal">
                <i class="nav-icon fa fa-power-off"></i>
                <span class="nav-text">Cerrar Sesión</span>
            </a>
        </li>  
    </ul>
</nav>

<!-- Modal de Confirmación de Logout -->
<div class="modal fade" id="logoutModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="fas fa-sign-out-alt me-2"></i>Cerrar Sesión
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body text-center">
                <i class="fas fa-question-circle fa-3x text-warning mb-3"></i>
                <h5>¿Estás seguro de que deseas cerrar sesión?</h5>
                <p class="text-muted">Serás redirigido a la página de inicio de sesión.</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                    <i class="fas fa-times me-2"></i>Cancelar
                </button>
                <a href="<?php echo $base_url; ?>/logout.php" class="btn btn-danger">
                    <i class="fas fa-sign-out-alt me-2"></i>Sí, Cerrar Sesión
                </a>
            </div>
        </div>
    </div>
</div>

<!-- ESTILOS (igual que el admin pero con colores diferentes) -->
<style>
@import url('https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css');
@import url('https://fonts.googleapis.com/css2?family=Titillium+Web:wght@300;400;600&display=swap');

/* Sidebar base - MÁS GRANDE */
.main-menu {
    background: rgba(33, 33, 33, 0.95);
    backdrop-filter: blur(0px);
    -webkit-backdrop-filter: blur(0px);
    border-right: 1px solid rgba(255, 255, 255, 0.1);
    position: fixed;
    top: 0;
    bottom: 0;
    height: 100%;
    left: 0;
    width: 80px;
    overflow: hidden;
    transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
    transform: translateZ(0);
    z-index: 1000;
    box-shadow: 2px 0 20px rgba(0, 0, 0, 0.3);
}

.main-menu:hover {
    width: 280px;
    background: rgba(10, 10, 10, 0);
    backdrop-filter: blur(15px);
    -webkit-backdrop-filter: blur(15px);
    box-shadow: 2px 0 30px rgba(0, 0, 0, 0.5);
}

/* COLORES DIFERENTES PARA PROFESOR */
.main-menu li:hover > a,
.main-menu li.active > a {
    color: #fff;
    background: linear-gradient(90deg, rgba(39, 148, 132, 0.25) 0%, rgba(32, 120, 107, 0.08) 100%);
    border-right: 4px solid #279484; /* VERDE para profesor */
    margin: 6px 0;
    padding: 18px 0;
}

.main-menu li:hover .nav-icon,
.main-menu li.active .nav-icon {
    color: #279484; /* VERDE para profesor */
    transform: scale(1.15);
}

.has-subnav:hover::after {
    background: #279484; /* VERDE para profesor */
    transform: translateY(-50%) scale(1.3);
}

/* El resto de los estilos son iguales al admin... */
.profile-section {
    padding: 25px 15px;
    border-bottom: 1px solid rgba(255, 255, 255, 0.1);
    margin-bottom: 15px;
    display: flex;
    align-items: center;
    opacity: 0;
    transform: translateX(-20px);
    transition: all 0.3s ease 0.1s;
}

.main-menu:hover .profile-section {
    opacity: 1;
    transform: translateX(0);
}

.profile-image {
    width: 50px;
    height: 50px;
    border-radius: 50%;
    overflow: hidden;
    margin-right: 15px;
    border: 2px solid rgba(39, 148, 132, 0.5); /* VERDE para profesor */
    flex-shrink: 0;
}

.profile-image img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

.profile-info {
    flex: 1;
    min-width: 0;
}

.profile-name {
    color: white;
    font-weight: 600;
    font-size: 14px;
    margin-bottom: 2px;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.profile-role {
    color: rgba(255, 255, 255, 0.7);
    font-size: 12px;
    font-weight: 300;
}

.main-menu > ul {
    margin: 10px 0;
    padding: 0;
}

.main-menu li {
    position: relative;
    display: block;
    width: 280px;
}

.main-menu li > a {
    position: relative;
    display: table;
    border-collapse: collapse;
    border-spacing: 0;
    color: rgba(255, 255, 255, 0.7);
    font-family: 'Titillium Web', sans-serif;
    font-size: 15px;
    font-weight: 400;
    text-decoration: none;
    transition: all 0.3s ease;
    padding: 16px 0;
    margin: 4px 0;
}

.main-menu .nav-icon {
    position: relative;
    display: table-cell;
    width: 80px;
    height: 50px;
    text-align: center;
    vertical-align: middle;
    font-size: 22px;
    transition: all 0.3s ease;
}

.main-menu .nav-text {
    position: relative;
    display: table-cell;
    vertical-align: middle;
    width: 200px;
    font-family: 'Titillium Web', sans-serif;
    font-weight: 400;
    font-size: 15px;
    opacity: 0;
    transform: translateX(-15px);
    transition: all 0.3s ease 0.15s;
}

.main-menu:hover .nav-text {
    opacity: 1;
    transform: translateX(0);
}

.main-menu > ul.logout {
    position: absolute;
    left: 0;
    bottom: 25px;
    width: 100%;
}

/* Indicador de subnavegación */
.has-subnav::after {
    content: '';
    position: absolute;
    right: 20px;
    top: 50%;
    transform: translateY(-50%);
    width: 8px;
    height: 8px;
    background: rgba(255, 255, 255, 0.5);
    border-radius: 50%;
    transition: all 0.3s ease;
    opacity: 0;
}

.main-menu:hover .has-subnav::after {
    opacity: 1;
}

/* Ajustes para el contenido principal */
body {
    margin: 0;
    padding: 0;
    margin-left: 80px !important;
}

.content-wrapper {
    margin-left: 80px !important;
    transition: none !important;
}

/* Efecto de overlay */
.menu-overlay {
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: linear-gradient(135deg, rgba(33, 33, 33, 0.95) 0%, rgba(33, 33, 33, 0.85) 100%);
    opacity: 0;
    transition: opacity 0.3s ease;
    pointer-events: none;
}

.main-menu:hover .menu-overlay {
    opacity: 1;
}

/* Animación de entrada para items */
@keyframes slideIn {
    from {
        opacity: 0;
        transform: translateX(-25px);
    }
    to {
        opacity: 1;
        transform: translateX(0);
    }
}

.main-menu li {
    animation: slideIn 0.4s ease forwards;
}

.main-menu li:nth-child(1) { animation-delay: 0.1s; }
.main-menu li:nth-child(2) { animation-delay: 0.15s; }
.main-menu li:nth-child(3) { animation-delay: 0.2s; }
.main-menu li:nth-child(4) { animation-delay: 0.25s; }
.main-menu li:nth-child(5) { animation-delay: 0.3s; }
.main-menu li:nth-child(6) { animation-delay: 0.35s; }
.main-menu li:nth-child(7) { animation-delay: 0.4s; }

/* Separador visual entre secciones */
.main-menu > ul:not(.logout) {
    border-bottom: 1px solid rgba(255, 255, 255, 0.05);
    padding-bottom: 10px;
    margin-bottom: 10px;
}
</style>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const menu = document.querySelector('.main-menu');
    
    menu.addEventListener('mouseenter', function() {
        this.style.zIndex = '1001';
    });
    
    menu.addEventListener('mouseleave', function() {
        this.style.zIndex = '1000';
    });
    
    // Marcar elemento activo al hacer clic
    const menuItems = menu.querySelectorAll('li > a');
    menuItems.forEach(item => {
        item.addEventListener('click', function() {
            menuItems.forEach(i => i.parentElement.classList.remove('active'));
            this.parentElement.classList.add('active');
        });
    });
    
    // Preload avatar image
    const avatarImg = new Image();
    avatarImg.src = '<?php echo $base_url; ?>/assets/images/default-avatar.png';
});
</script>