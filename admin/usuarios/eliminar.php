<?php
require_once '../../includes/auth.php';
require_once '../../includes/database.php';
checkAuth();
checkRole(['admin']);

if (!isset($_GET['id'])) {
    header('Location: gestionar.php');
    exit();
}

$usuario_id = (int) $_GET['id'];

if ($usuario_id <= 0) {
    $_SESSION['usuario_mensaje'] = 'ID de usuario no válido.';
    $_SESSION['usuario_tipo_mensaje'] = 'danger';
    header('Location: gestionar.php');
    exit();
}

if ((int)($_SESSION['user_id'] ?? 0) === $usuario_id) {
    $_SESSION['usuario_mensaje'] = 'No puedes desactivar tu propio usuario desde esta pantalla.';
    $_SESSION['usuario_tipo_mensaje'] = 'warning';
    header('Location: gestionar.php');
    exit();
}

try {
    $stmt = $pdo->prepare("SELECT id, username, estado FROM usuarios WHERE id = ?");
    $stmt->execute([$usuario_id]);
    $usuario = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$usuario) {
        $_SESSION['usuario_mensaje'] = 'No se encontró el usuario solicitado.';
        $_SESSION['usuario_tipo_mensaje'] = 'danger';
        header('Location: gestionar.php');
        exit();
    }

    if ($usuario['estado'] === 'inactivo') {
        $_SESSION['usuario_mensaje'] = 'El usuario ya está inactivo.';
        $_SESSION['usuario_tipo_mensaje'] = 'warning';
        header('Location: gestionar.php');
        exit();
    }

    $stmt = $pdo->prepare("UPDATE usuarios SET estado = 'inactivo' WHERE id = ?");
    $stmt->execute([$usuario_id]);

    $_SESSION['usuario_mensaje'] = 'Usuario "' . htmlspecialchars($usuario['username'], ENT_QUOTES) . '" desactivado correctamente.';
    $_SESSION['usuario_tipo_mensaje'] = 'success';
} catch (PDOException $e) {
    error_log('Error al desactivar usuario: ' . $e->getMessage());
    $_SESSION['usuario_mensaje'] = 'Ocurrió un error al desactivar el usuario.';
    $_SESSION['usuario_tipo_mensaje'] = 'danger';
}

header('Location: gestionar.php');
exit();
