<?php
require_once '../../includes/auth.php';
require_once '../../includes/database.php';
checkAuth();
checkRole(['admin']);

$backupDir = __DIR__ . '/backups';
if (!is_dir($backupDir) && !@mkdir($backupDir, 0775, true) && !is_dir($backupDir)) {
    $_SESSION['mensaje_backup'] = 'No se pudo crear la carpeta de respaldos.';
    $_SESSION['tipo_mensaje_backup'] = 'danger';
    header('Location: index.php');
    exit;
}

$backupFile = $backupDir . '/sistema_estudiantes_' . date('Ymd_His') . '.sql';

$command = '"C:\\xampp\\mysql\\bin\\mysqldump.exe" --user=root --host=localhost --databases sistema_estudiantes > "' . str_replace('\\', '/', $backupFile) . '" 2>&1';
exec($command, $output, $exitCode);

if ($exitCode === 0 && file_exists($backupFile) && filesize($backupFile) > 0) {
    $_SESSION['mensaje_backup'] = 'El respaldo se creó correctamente. Archivo: ' . basename($backupFile);
    $_SESSION['tipo_mensaje_backup'] = 'success';
} else {
    $_SESSION['mensaje_backup'] = 'No se pudo crear el respaldo. Verifica que MySQL Dump esté disponible en XAMPP.';
    $_SESSION['tipo_mensaje_backup'] = 'danger';
}

header('Location: index.php');
exit;
