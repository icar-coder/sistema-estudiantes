<?php
require_once '../../includes/auth.php';
require_once '../../includes/database.php';
require_once '../../includes/database_backup.php';
checkAuth();
checkRole(['admin']);

try {
    $backupFile = tempnam(sys_get_temp_dir(), 'sistema_backup_');
    if ($backupFile === false) {
        throw new RuntimeException('No se pudo crear un archivo temporal para el respaldo.');
    }

    writeBackupContents($pdo, $backupFile);
    if (filesize($backupFile) === 0) {
        throw new RuntimeException('El archivo de respaldo quedó vacío.');
    }

    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    header('Content-Type: application/sql; charset=utf-8');
    header('Content-Disposition: attachment; filename="sistema_estudiantes_' . date('Ymd_His') . '.sql"');
    header('Content-Length: ' . filesize($backupFile));
    readfile($backupFile);
    unlink($backupFile);
    exit;
} catch (Throwable $e) {
    if (isset($backupFile) && is_string($backupFile) && is_file($backupFile)) {
        unlink($backupFile);
    }
    error_log('Error al crear respaldo de base de datos: ' . $e->getMessage());
    $_SESSION['mensaje_backup'] = 'No se pudo crear el respaldo. Revisa los registros del servidor.';
    $_SESSION['tipo_mensaje_backup'] = 'danger';
}

header('Location: index.php');
exit;
