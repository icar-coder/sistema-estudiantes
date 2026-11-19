<?php
require_once '../../includes/auth.php';
require_once '../../includes/database.php';
checkAuth();
checkRole(['admin']);
requireOnlineFeature($pdo, 'La administración de cuentas de usuario');

try {
    $stmt = $pdo->prepare('UPDATE usuarios SET bloqueado_hasta = NULL, intentos_fallidos = 0 WHERE bloqueado_hasta IS NOT NULL OR intentos_fallidos > 0');
    $stmt->execute();
    $_SESSION['mensaje_backup'] = 'Los bloqueos temporales fueron liberados correctamente.';
    $_SESSION['tipo_mensaje_backup'] = 'success';
} catch (PDOException $e) {
    error_log('Error al liberar bloqueos: ' . $e->getMessage());
    $_SESSION['mensaje_backup'] = 'No se pudieron liberar los bloqueos.';
    $_SESSION['tipo_mensaje_backup'] = 'danger';
}

header('Location: index.php');
exit;
