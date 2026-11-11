<?php
require_once __DIR__ . '/../includes/paths.php';

session_start();

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
session_regenerate_id(true);

$_SESSION['mensaje_login'] = 'Has cerrado sesión correctamente.';
$_SESSION['tipo_mensaje'] = 'success';

header('Location: ' . app_url('login.php'));
exit;
?>