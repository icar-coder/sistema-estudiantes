
<!-- Sidebar -->
<?php
// includes/sidebar_admin.php
$current_page = basename($_SERVER['PHP_SELF']);
$current_path = str_replace('\\', '/', $_SERVER['PHP_SELF']);
$base = app_base_path();
$offlineMode = isset($pdo) && $pdo instanceof PDO && isSqliteDatabase($pdo);
?>

<!-- Botón hamburguesa (solo visible en móvil) -->
<button class="mobile-menu-toggle" id="mobileMenuToggle" aria-label="Abrir menú">
    <i class="fas fa-bars"></i>
</button>

<!-- Overlay -->
<div class="sidebar-overlay" id="sidebarOverlay"></div>

<!-- Sidebar -->
<div class="sidebar" id="sidebar">
    <div class="sidebar-header">
        <div class="sidebar-brand">
            <i class="fas fa-graduation-cap"></i>
            <span class="brand-text">Sistema Escolar</span>
        </div>
        <button class="sidebar-toggle" id="sidebarToggle">
            <i class="fas fa-bars"></i>
        </button>
    </div>

    <nav class="sidebar-nav">
        <!-- Dashboard -->
        <div class="nav-item <?php echo ($current_page == 'dashboard.php') ? 'active' : ''; ?>">
            <a href="<?php echo $base; ?>/admin/dashboard.php" class="nav-link">
                <i class="fas fa-tachometer-alt"></i>
                <span class="nav-text">Dashboard</span>
            </a>
        </div>

        <!-- Gestión Académica -->
        <div class="nav-section">
            <div class="nav-section-header" onclick="toggleSection(this)">
                <i class="fas fa-book"></i>
                <span>Gestión Académica</span>
                <i class="fas fa-chevron-down arrow"></i>
            </div>
            <div class="nav-section-content">
                <div class="nav-item">
                    <a href="<?php echo $base; ?>/admin/años_escolares/gestionar.php" class="nav-link">
                        <i class="fas fa-calendar-alt"></i>
                        <span>Años Escolares</span>
                    </a>
                </div>
                <div class="nav-item">
                    <a href="<?php echo $base; ?>/admin/secciones/gestionar.php" class="nav-link">
                        <i class="fas fa-door-open"></i>
                        <span>Secciones</span>
                    </a>
                </div>
                <div class="nav-item <?php echo (strpos($current_path, '/admin/secciones/') === false && strpos($current_path, '/admin/horarios/') !== false) ? 'active' : ''; ?>">
                    <a href="<?php echo $base; ?>/admin/horarios/gestionar.php" class="nav-link">
                        <i class="fas fa-clock"></i>
                        <span>Horarios</span>
                    </a>
                </div>
                <div class="nav-item">
                    <a href="<?php echo $base; ?>/admin/areas_aprendizaje/gestionar.php" class="nav-link">
                        <i class="fas fa-puzzle-piece"></i>
                        <span>Áreas de Aprendizaje</span>
                    </a>
                </div>
            </div>
        </div>

        <!-- Estudiantes -->
        <div class="nav-section">
            <div class="nav-section-header" onclick="toggleSection(this)">
                <i class="fas fa-users"></i>
                <span>Estudiantes</span>
                <i class="fas fa-chevron-down arrow"></i>
            </div>
            <div class="nav-section-content">
                <div class="nav-item">
                    <a href="<?php echo $base; ?>/admin/estudiantes/gestionar.php" class="nav-link">
                        <i class="fas fa-user-graduate"></i>
                        <span>Gestionar Estudiantes</span>
                    </a>
                </div>
                <div class="nav-item">
                    <a href="<?php echo $base; ?>/admin/inscripciones/gestionar.php" class="nav-link">
                        <i class="fas fa-clipboard-list"></i>
                        <span>Inscripciones</span>
                    </a>
                </div>
                <div class="nav-item">
                    <a href="<?php echo $base; ?>/admin/asistencias/gestionar.php" class="nav-link">
                        <i class="fas fa-clipboard-check"></i>
                        <span>Registrar asistencias</span>
                    </a>
                </div>
                <div class="nav-item">
                    <a href="<?php echo $base; ?>/admin/inscripciones/gestionar_adelantos.php" class="nav-link">
                        <i class="fas fa-forward"></i>
                        <span>Adelantos</span>
                    </a>
                </div>
            </div>
        </div>

        <!-- Evaluación -->
        <div class="nav-section">
            <div class="nav-section-header" onclick="toggleSection(this)">
                <i class="fas fa-chart-bar"></i>
                <span>Evaluación</span>
                <i class="fas fa-chevron-down arrow"></i>
            </div>
            <div class="nav-section-content">
                <div class="nav-item">
                    <a href="<?php echo $base; ?>/admin/evaluacion_literales/index.php" class="nav-link">
                        <i class="fas fa-edit"></i>
                        <span>Evaluación por Literales</span>
                    </a>
                </div>
                <div class="nav-item">
                    <a href="<?php echo $base; ?>/admin/estudiantes/gestionar.php" class="nav-link">
                        <i class="fas fa-file-pdf"></i>
                        <span>Boletas por estudiante</span>
                    </a>
                </div>
            </div>
        </div>

        <!-- Personal -->
        <div class="nav-section">
            <div class="nav-section-header" onclick="toggleSection(this)">
                <i class="fas fa-chalkboard-teacher"></i>
                <span>Personal</span>
                <i class="fas fa-chevron-down arrow"></i>
            </div>
            <div class="nav-section-content">
                <div class="nav-item">
                    <a href="<?php echo $base; ?>/admin/profesores/gestionar.php" class="nav-link">
                        <i class="fas fa-user-tie"></i>
                        <span>Profesores</span>
                    </a>
                </div>
                <?php if (!$offlineMode): ?>
                <div class="nav-item">
                    <a href="<?php echo $base; ?>/admin/usuarios/gestionar.php" class="nav-link">
                        <i class="fas fa-user-cog"></i>
                        <span>Usuarios</span>
                    </a>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Reportes -->
        <div class="nav-section">
            <div class="nav-section-header" onclick="toggleSection(this)">
                <i class="fas fa-chart-line"></i>
                <span>Reportes</span>
                <i class="fas fa-chevron-down arrow"></i>
            </div>
            <div class="nav-section-content">
                <div class="nav-item">
                    <a href="<?php echo $base; ?>/admin/reportes/literales.php" class="nav-link">
                        <i class="fas fa-list-alt"></i>
                        <span>Estudiantes y literales</span>
                    </a>
                </div>
                <?php if (!$offlineMode): ?>
                <div class="nav-item">
                    <a href="<?php echo $base; ?>/admin/reportes/notas.php" class="nav-link">
                        <i class="fas fa-chart-bar"></i>
                        <span>Estadísticas por literales</span>
                    </a>
                </div>
                <div class="nav-item">
                    <a href="<?php echo $base; ?>/admin/reportes/asistencias.php" class="nav-link">
                        <i class="fas fa-user-check"></i>
                        <span>Asistencias</span>
                    </a>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($offlineMode): ?>
        <div class="nav-item">
            <a href="<?php echo $base; ?>/admin/sincronizacion.php" class="nav-link">
                <i class="fas fa-sync-alt"></i>
                <span>Estado de sincronización</span>
            </a>
        </div>
        <?php endif; ?>

        <?php if (!$offlineMode): ?>
        <div class="nav-item">
            <a href="<?php echo $base; ?>/admin/respaldos/index.php" class="nav-link">
                <i class="fas fa-database"></i>
                <span>Respaldos</span>
            </a>
        </div>
        <?php endif; ?>

        <div class="nav-item">
            <a href="<?php echo $base; ?>/admin/politicas/claves_criptograficas.php" class="nav-link">
                <i class="fas fa-shield-alt"></i>
                <span>Políticas de Seguridad</span>
            </a>
        </div>
    </nav>

    <div class="sidebar-footer">
        <div class="user-info">
            <div class="user-avatar">
                <i class="fas fa-user-shield"></i>
            </div>
            <div class="user-details">
                <div class="user-name"><?php echo $_SESSION['username'] ?? 'Admin'; ?></div>
                <div class="user-role">Administrador</div>
            </div>
        </div>
        <a href="<?php echo $base; ?>/admin/logout.php" class="logout-btn">
            <i class="fas fa-sign-out-alt"></i>
            <span>Cerrar Sesión</span>
        </a>
    </div>
</div>

<style>
/* Sidebar Styles */
.sidebar {
    position: fixed;
    top: 0;
    left: 0;
    height: 100vh;
    width: 280px;
    background: linear-gradient(135deg, #2c3e50 0%, #34495e 100%);
    color: white;
    z-index: 1000;
    transition: all 0.3s ease;
    display: flex;
    flex-direction: column;
    box-shadow: 2px 0 10px rgba(0,0,0,0.1);
    transform: translateX(0);
}

.sidebar.collapsed {
    width: 70px;
}

.sidebar.collapsed .brand-text,
.sidebar.collapsed .nav-text,
.sidebar.collapsed .nav-section-header span,
.sidebar.collapsed .user-details,
.sidebar.collapsed .logout-btn span {
    display: none;
}

.sidebar.collapsed .sidebar-nav {
    padding: 1rem 0;
}

.sidebar.collapsed .nav-link {
    justify-content: center;
    padding: 0.75rem;
}

.sidebar.collapsed .nav-section-header {
    justify-content: center;
    padding: 0.75rem;
}

.sidebar.collapsed .nav-section-header .arrow {
    display: none;
}

.sidebar.collapsed .nav-section-content {
    display: none;
}

.sidebar.collapsed .user-info {
    justify-content: center;
}

.sidebar.collapsed .sidebar-footer {
    padding: 1rem 0.5rem;
}

.sidebar.collapsed .logout-btn {
    justify-content: center;
    padding: 0.75rem;
}

.sidebar-header {
    padding: 1.5rem 1rem;
    border-bottom: 1px solid rgba(255,255,255,0.1);
    display: flex;
    justify-content: space-between;
    align-items: center;
    min-height: 70px;
}

.sidebar.collapsed .sidebar-header {
    padding: 1.5rem 0.5rem;
    justify-content: center;
}

.sidebar-brand {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    font-size: 1.25rem;
    font-weight: 600;
    white-space: nowrap;
}

.sidebar-brand i {
    color: #3498db;
    font-size: 1.5rem;
    min-width: 24px;
}

.sidebar-toggle {
    background: none;
    border: none;
    color: white;
    font-size: 1.25rem;
    cursor: pointer;
    padding: 0.5rem;
    border-radius: 4px;
    transition: background-color 0.2s;
}

.sidebar-toggle:hover {
    background-color: rgba(255,255,255,0.1);
}

.sidebar-nav {
    flex: 1;
    overflow-y: auto;
    padding: 1rem 0;
    transition: all 0.3s ease;
}

/* Navigation Sections */
.nav-section {
    margin-bottom: 0.5rem;
}

.nav-section-header {
    padding: 0.75rem 1rem;
    font-weight: 600;
    font-size: 0.875rem;
    color: #bdc3c7;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    display: flex;
    align-items: center;
    gap: 0.5rem;
    cursor: pointer;
    transition: all 0.2s ease;
    user-select: none;
}

.nav-section-header:hover {
    background-color: rgba(255,255,255,0.05);
}

.nav-section-header .arrow {
    margin-left: auto;
    font-size: 0.8rem;
    transition: transform 0.3s ease;
}

.nav-section-header.collapsed .arrow {
    transform: rotate(-90deg);
}

.nav-section-content {
    padding: 0.25rem 0;
    overflow: hidden;
    transition: all 0.3s ease;
}

.nav-section-content.collapsed {
    max-height: 0 !important;
    opacity: 0;
}

/* Navigation Items */
.nav-item {
    margin: 0.125rem 0.5rem;
}

.nav-link {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    padding: 0.75rem 1rem;
    color: #ecf0f1;
    text-decoration: none;
    border-radius: 6px;
    transition: all 0.2s ease;
    font-size: 0.9rem;
    white-space: nowrap;
}

.nav-link:hover {
    background-color: rgba(52, 152, 219, 0.2);
    color: #3498db;
    transform: translateX(4px);
}

.nav-item.active .nav-link {
    background: linear-gradient(135deg, #3498db 0%, #2980b9 100%);
    color: white;
    box-shadow: 0 2px 8px rgba(52, 152, 219, 0.3);
}

.nav-link i {
    width: 20px;
    text-align: center;
    font-size: 1rem;
    flex-shrink: 0;
}

/* Sidebar Footer */
.sidebar-footer {
    border-top: 1px solid rgba(255,255,255,0.1);
    padding: 1rem;
    transition: all 0.3s ease;
}

.user-info {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    margin-bottom: 1rem;
}

.user-avatar {
    width: 40px;
    height: 40px;
    background: linear-gradient(135deg, #3498db 0%, #2980b9 100%);
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1rem;
    flex-shrink: 0;
}

.user-details {
    flex: 1;
    overflow: hidden;
}

.user-name {
    font-weight: 600;
    font-size: 0.9rem;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.user-role {
    font-size: 0.8rem;
    color: #bdc3c7;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.logout-btn {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    padding: 0.75rem 1rem;
    color: #e74c3c;
    text-decoration: none;
    border-radius: 6px;
    transition: all 0.2s ease;
    font-size: 0.9rem;
    background-color: rgba(231, 76, 60, 0.1);
    white-space: nowrap;
}

.logout-btn:hover {
    background-color: rgba(231, 76, 60, 0.2);
    color: #c0392b;
}

/* Responsive */
@media (max-width: 768px) {
    .sidebar {
        transform: translateX(-100%);
    }
    
    .sidebar.mobile-open {
        transform: translateX(0);
    }
    
    .sidebar-overlay.mobile-open {
        display: block;
    }
}

/* Scrollbar personalizado */
.sidebar-nav::-webkit-scrollbar {
    width: 4px;
}

.sidebar-nav::-webkit-scrollbar-track {
    background: rgba(255,255,255,0.1);
}

.sidebar-nav::-webkit-scrollbar-thumb {
    background: rgba(255,255,255,0.3);
    border-radius: 2px;
}

.sidebar-nav::-webkit-scrollbar-thumb:hover {
    background: rgba(255,255,255,0.5);
}

/* Main content adjustment */
.content-wrapper {
    margin-left: 280px;
    transition: margin-left 0.3s ease;
    padding: 20px;
    min-height: calc(100vh - 60px);
}

.sidebar.collapsed ~ .content-wrapper,
body.sidebar-collapsed .content-wrapper {
    margin-left: 70px;
}

/* ============================================================
   RESPONSIVE
   ============================================================ */

@media (max-width: 1024px) {
    .content-wrapper {
        margin-left: 70px !important;
    }

    .sidebar {
        width: 70px;
    }

    .sidebar .brand-text,
    .sidebar .nav-text,
    .sidebar .nav-section-header span,
    .sidebar .user-details,
    .sidebar .logout-btn span {
        display: none;
    }
}

@media (max-width: 768px) {
    .content-wrapper {
        margin-left: 0 !important;
        padding: 15px;
        padding-top: 70px;
    }

    .sidebar {
        transform: translateX(-100%);
        width: 280px !important;
    }

    .sidebar.mobile-open {
        transform: translateX(0);
    }

    .sidebar-overlay.mobile-open {
        display: block;
        opacity: 1;
    }

    .sidebar.mobile-open .brand-text,
    .sidebar.mobile-open .nav-text,
    .sidebar.mobile-open .nav-section-header span,
    .sidebar.mobile-open .user-details,
    .sidebar.mobile-open .logout-btn span {
        display: inline !important;
    }
}

.mobile-menu-toggle {
    display: none;
    position: fixed;
    top: 15px;
    left: 15px;
    z-index: 1100;
    background: linear-gradient(135deg, #2c3e50 0%, #3498db 100%);
    color: white;
    border: none;
    border-radius: 8px;
    width: 45px;
    height: 45px;
    font-size: 1.2rem;
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.2);
    cursor: pointer;
    transition: transform 0.2s;
}

.mobile-menu-toggle:hover {
    transform: scale(1.05);
}

@media (max-width: 768px) {
    .mobile-menu-toggle {
        display: flex;
        align-items: center;
        justify-content: center;
    }
}

.sidebar-overlay {
    display: none;
    position: fixed;
    inset: 0;
    background: rgba(0, 0, 0, 0.5);
    z-index: 999;
    opacity: 0;
    transition: opacity 0.3s;
}

.sidebar-overlay.mobile-open {
    display: block;
    opacity: 1;
}
</style>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const sidebar = document.getElementById('sidebar');
    const sidebarToggle = document.getElementById('sidebarToggle');
    const sidebarOverlay = document.getElementById('sidebarOverlay');
    const mobileMenuToggle = document.getElementById('mobileMenuToggle');

    const isMobile = () => window.innerWidth <= 768;

    if (sidebarToggle) {
        sidebarToggle.addEventListener('click', function() {
            if (isMobile()) {
                sidebar.classList.toggle('mobile-open');
                if (sidebarOverlay) sidebarOverlay.classList.toggle('mobile-open');
            } else {
                sidebar.classList.toggle('collapsed');
                document.body.classList.toggle('sidebar-collapsed');
                localStorage.setItem('sidebarCollapsed', sidebar.classList.contains('collapsed'));
            }
        });
    }

    if (mobileMenuToggle) {
        mobileMenuToggle.addEventListener('click', function() {
            sidebar.classList.toggle('mobile-open');
            if (sidebarOverlay) sidebarOverlay.classList.toggle('mobile-open');
        });
    }

    if (sidebarOverlay) {
        sidebarOverlay.addEventListener('click', function() {
            sidebar.classList.remove('mobile-open');
            sidebarOverlay.classList.remove('mobile-open');
        });
    }

    document.querySelectorAll('.sidebar .nav-link').forEach(link => {
        link.addEventListener('click', function() {
            if (isMobile()) {
                sidebar.classList.remove('mobile-open');
                if (sidebarOverlay) sidebarOverlay.classList.remove('mobile-open');
            }
        });
    });

    const savedState = localStorage.getItem('sidebarCollapsed');
    if (savedState === 'true' && !isMobile()) {
        sidebar.classList.add('collapsed');
        document.body.classList.add('sidebar-collapsed');
    }

    window.addEventListener('resize', function() {
        if (!isMobile()) {
            sidebar.classList.remove('mobile-open');
            if (sidebarOverlay) sidebarOverlay.classList.remove('mobile-open');
        }
    });

    const firstSection = document.querySelector('.nav-section-header');
    if (firstSection) {
        const content = firstSection.nextElementSibling;
        if (content) {
            content.style.maxHeight = content.scrollHeight + 'px';
        }
    }
});

function toggleSection(header) {
    const content = header.nextElementSibling;
    if (!content) return;

    header.classList.toggle('collapsed');

    if (header.classList.contains('collapsed')) {
        content.style.maxHeight = '0';
        content.classList.add('collapsed');
    } else {
        content.style.maxHeight = content.scrollHeight + 'px';
        content.classList.remove('collapsed');
    }
}
</script>