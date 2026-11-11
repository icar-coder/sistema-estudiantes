<?php
// includes/config.php

require_once __DIR__ . '/paths.php';

$base_host = $_SERVER['HTTP_HOST'] ?? 'localhost';
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$base_path = app_base_path();

// Configuración de la aplicación
define('APP_ENV', getenv('APP_ENV') ?: 'production');
define('BASE_URL', $scheme . '://' . $base_host . $base_path);
define('SITE_NAME', 'Sistema de Gestión Estudiantil');

// Configuración de la base de datos
require_once __DIR__ . '/database_config.php';
$dbConfig = getDatabaseConfig();
define('DB_HOST', $dbConfig['host']);
define('DB_NAME', $dbConfig['name']);
define('DB_USER', $dbConfig['user']);
define('DB_PASS', $dbConfig['pass']);
define('DB_CHARSET', $dbConfig['charset']);

// Configuración de sesión
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$log_dir = __DIR__ . '/../logs';
if (!is_dir($log_dir) && !@mkdir($log_dir, 0775, true) && !is_dir($log_dir)) {
    error_log('No se pudo crear el directorio de logs: ' . $log_dir);
}

$log_file = $log_dir . '/php_errors.log';
ini_set('log_errors', '1');
ini_set('error_log', $log_file);

if (APP_ENV === 'production') {
    error_reporting(E_ALL & ~E_NOTICE & ~E_WARNING & ~E_DEPRECATED & ~E_USER_NOTICE & ~E_USER_WARNING & ~E_USER_DEPRECATED);
    ini_set('display_errors', '0');
    ini_set('display_startup_errors', '0');
} else {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
    ini_set('display_startup_errors', '1');
}

function app_exception_handler(Throwable $e): void {
    $message = '[' . date('c') . '] Excepción: ' . $e->getMessage() . ' en ' . $e->getFile() . ':' . $e->getLine();
    error_log($message);

    if (session_status() === PHP_SESSION_ACTIVE) {
        $_SESSION['error_sistema'] = 'Ocurrió un error al procesar la solicitud. El equipo técnico fue notificado.';
    }

    $redirect = $_SERVER['HTTP_REFERER'] ?? BASE_URL . '/index.php';
    header('Location: ' . $redirect);
    exit;
}

function app_error_handler(int $severity, string $message, string $file, int $line): bool {
    $is_fatal = in_array($severity, [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true);
    $log_message = '[' . date('c') . '] Error PHP (' . $severity . '): ' . $message . ' en ' . $file . ':' . $line;
    error_log($log_message);

    if ($is_fatal) {
        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION['error_sistema'] = 'Ocurrió un error al procesar la solicitud. El equipo técnico fue notificado.';
        }

        $redirect = $_SERVER['HTTP_REFERER'] ?? BASE_URL . '/index.php';
        header('Location: ' . $redirect);
        exit;
    }

    return true;
}

function app_shutdown_handler(): void {
    $error = error_get_last();
    if ($error !== null && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)) {
        $message = '[' . date('c') . '] Error fatal: ' . $error['message'] . ' en ' . $error['file'] . ':' . $error['line'];
        error_log($message);

        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION['error_sistema'] = 'Ocurrió un error al procesar la solicitud. El equipo técnico fue notificado.';
        }

        $redirect = $_SERVER['HTTP_REFERER'] ?? BASE_URL . '/index.php';
        header('Location: ' . $redirect);
        exit;
    }
}

set_exception_handler('app_exception_handler');
set_error_handler('app_error_handler');
register_shutdown_function('app_shutdown_handler');

// Incluir database.php que ya existe
require_once __DIR__ . '/database.php';

// Variables globales
$base_url = BASE_URL;
$site_name = SITE_NAME;

// Función para sanitizar datos
function sanitize($data) {
    if (is_array($data)) {
        return array_map('sanitize', $data);
    }
    return htmlspecialchars(trim($data), ENT_QUOTES, 'UTF-8');
}
?>