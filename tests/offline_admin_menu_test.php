<?php

require_once dirname(__DIR__) . '/includes/paths.php';
require_once dirname(__DIR__) . '/includes/database_helpers.php';

function assertOfflineAdminMenuTest(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$pdo = new PDO('sqlite::memory:');
$_SERVER['PHP_SELF'] = '/admin/dashboard.php';
$_SERVER['SCRIPT_NAME'] = '/admin/dashboard.php';
$_SESSION['username'] = 'Admin';

ob_start();
require dirname(__DIR__) . '/includes/sidebar_admin.php';
$menu = ob_get_clean();

foreach ([
    '/admin/reportes/notas.php',
    '/admin/reportes/asistencias.php',
    '/admin/reportes/promedios.php',
    '/admin/usuarios/gestionar.php',
    '/admin/respaldos/index.php',
    '/admin/horarios/gestionar.php',
] as $unavailableRoute) {
    assertOfflineAdminMenuTest(
        strpos($menu, 'href="' . $unavailableRoute . '"') === false,
        'El menú offline no debe mostrar ' . $unavailableRoute . '.'
    );
}

foreach ([
    '/admin/años_escolares/gestionar.php',
    '/admin/secciones/gestionar.php',
    '/admin/estudiantes/gestionar.php',
    '/admin/profesores/gestionar.php',
    '/admin/asistencias/gestionar.php',
    '/admin/profesores/asignaciones.php',
    '/admin/reportes/literales.php',
    '/admin/sincronizacion.php',
] as $availableRoute) {
    assertOfflineAdminMenuTest(
        strpos($menu, 'href="' . $availableRoute . '"') !== false,
        'El menú offline debe conservar ' . $availableRoute . '.'
    );
}

echo "offline admin menu tests passed\n";
