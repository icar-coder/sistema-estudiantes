<?php

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

if (!in_array('--confirmar-datos-privados', $argv, true)) {
    fwrite(
        STDERR,
        "Este comando copia datos personales de MySQL a una base SQLite local.\n" .
        "Hazlo solo en un equipo/USB protegido y ejecuta: php exportar_sqlite.php --confirmar-datos-privados\n"
    );
    exit(2);
}
$portableWithoutSync = in_array('--portable-sin-sincronizacion', $argv, true);

require_once __DIR__ . '/includes/database_mysql.php';
require_once __DIR__ . '/includes/sqlite_bootstrap.php';
require_once __DIR__ . '/includes/offline_sync.php';

$targetPath = getenv('OFFLINE_DB_PATH');
if ($targetPath === false || $targetPath === '') {
    $targetPath = __DIR__ . '/database/datos_locales.sqlite';
}

$targetDirectory = dirname($targetPath);
if (!is_dir($targetDirectory) &&
    !mkdir($targetDirectory, 0775, true) &&
    !is_dir($targetDirectory)) {
    fwrite(STDERR, "No se pudo crear el directorio SQLite local.\n");
    exit(1);
}
if (file_exists($targetPath)) {
    fwrite(STDERR, "La base SQLite ya existe; no se sobrescribió.\n");
    exit(1);
}

$target = null;
try {
    $target = new PDO('sqlite:' . $targetPath, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    sqliteRegisterFunctions($target);
    $target->exec('PRAGMA busy_timeout = 5000');
    $counts = sqliteBootstrapExport($pdo, $target);
    offlineSyncInitialize($target);
    if ($portableWithoutSync) {
        offlineSyncPrepareLocalOnlyDatabase($target);
    } else {
        offlineSyncInstallTriggers($target);
        $target->exec(
            'CREATE TABLE IF NOT EXISTS `offline_sync_metadata` (
                `key` TEXT PRIMARY KEY,
                `value` TEXT NOT NULL
            )'
        );
        $origin = $target->prepare(
            'INSERT OR REPLACE INTO `offline_sync_metadata` (`key`, `value`)
             VALUES ("database_origin", ?)'
        );
        $origin->execute(['mysql_export']);
    }

    $total = array_sum($counts);
    echo json_encode([
        'database' => basename($targetPath),
        'tables' => count($counts),
        'records' => $total,
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;
} catch (Throwable $exception) {
    if ($target instanceof PDO && $target->inTransaction()) {
        $target->rollBack();
    }
    $target = null;
    gc_collect_cycles();
    if (is_file($targetPath)) {
        $removed = @unlink($targetPath);
        if (!$removed && is_file($targetPath)) {
            fwrite(STDERR, 'No se pudo eliminar la base SQLite parcial; bórrala manualmente antes de reintentar.' . PHP_EOL);
        }
    }
    fwrite(STDERR, 'Error al exportar SQLite: ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}
