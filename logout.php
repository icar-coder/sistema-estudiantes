<?php
session_start();

$motivo = $_GET['expired'] ?? null;

if ($motivo === '1') {
    error_log('Logout por expiración de sesión: user_id=' . ($_SESSION['user_id'] ?? 'anon'));
}

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

if ($motivo === '1') {
    $_SESSION['mensaje_login'] = 'Tu sesión expiró por inactividad. Inicia sesión nuevamente.';
    $_SESSION['tipo_mensaje'] = 'warning';
} else {
    $_SESSION['mensaje_login'] = 'Has cerrado sesión correctamente.';
    $_SESSION['tipo_mensaje'] = 'success';
}

header('Location: /SistemaEstudiantes/login.php');
exit;
?>