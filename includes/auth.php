<?php
require_once __DIR__ . '/paths.php';

// ============================================================
// CONFIGURACIÓN DE SESIÓN
// ============================================================
define('SESSION_TIMEOUT', 1800);   // 30 minutos de inactividad
define('SESSION_WARNING', 300);    // 5 minutos antes de expirar

function obtenerRutaBaseApp() {
    return app_base_path();
}

function iniciarSesionSegura() {
    if (session_status() !== PHP_SESSION_NONE) {
        return;
    }

    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'domain' => '',
        'secure' => false,
        'httponly' => true,
        'samesite' => 'Lax'
    ]);

    ini_set('session.gc_maxlifetime', (string) SESSION_TIMEOUT);
    ini_set('session.cookie_lifetime', '0');
    ini_set('session.use_strict_mode', '1');
    ini_set('session.cookie_httponly', '1');
    ini_set('session.cookie_samesite', 'Lax');

    session_start();
}

function redirigirAlLogin($mensaje = 'Debes iniciar sesión para continuar.', $tipo = 'warning') {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    $_SESSION['mensaje_login'] = $mensaje;
    $_SESSION['tipo_mensaje'] = $tipo;

    header('Location: ' . app_url('login.php'));
    exit();
}

function checkAuth() {
    iniciarSesionSegura();

    if (!isset($_SESSION['user_id'])) {
        redirigirAlLogin();
    }

    $ahora = time();
    $ultima_actividad = isset($_SESSION['ultima_actividad']) ? (int) $_SESSION['ultima_actividad'] : $ahora;
    $inactividad = $ahora - $ultima_actividad;

    if ($inactividad > SESSION_TIMEOUT) {
        $_SESSION = [];

        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(
                session_name(),
                '',
                time() - 42000,
                $params['path'],
                $params['domain'],
                $params['secure'],
                $params['httponly']
            );
        }

        session_destroy();
        session_start();

        redirigirAlLogin('Tu sesión expiró por inactividad. Por favor, inicia sesión nuevamente.', 'warning');
    }

    $_SESSION['ultima_actividad'] = $ahora;

    if (!isset($_SESSION['creada_en'])) {
        $_SESSION['creada_en'] = $ahora;
    }

    if ($ahora - $_SESSION['creada_en'] > 900) {
        session_regenerate_id(true);
        $_SESSION['creada_en'] = $ahora;
    }
}

function checkRole($allowed_roles) {
    iniciarSesionSegura();

    if (defined('DB_DRIVER') && DB_DRIVER === 'sqlite' && ($_SESSION['rol'] ?? null) !== 'admin') {
        header('Location: ' . app_url('acceso-denegado.php'));
        exit();
    }

    if (!isset($_SESSION['rol']) || !in_array($_SESSION['rol'], $allowed_roles, true)) {
        header('Location: ' . app_url('acceso-denegado.php'));
        exit();
    }
}

function hasRole($role) {
    iniciarSesionSegura();
    return isset($_SESSION['rol']) && $_SESSION['rol'] === $role;
}

// Roles del sistema
define('ROL_ADMIN', 'admin');
define('ROL_PROFESOR', 'profesor');
define('ROL_ESTUDIANTE', 'estudiante');
?>