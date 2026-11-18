<?php

$localConfigPath = __DIR__ . '/config.local.php';
$localConfig = is_file($localConfigPath) ? require $localConfigPath : [];
if (!is_array($localConfig)) {
    throw new RuntimeException('includes/config.local.php debe devolver un arreglo.');
}

$configuredDriver = getenv('APP_DB_DRIVER');
$databaseDriver = $configuredDriver !== false && $configuredDriver !== ''
    ? strtolower($configuredDriver)
    : strtolower((string) ($localConfig['db_driver'] ?? 'mysql'));

if ($databaseDriver === 'mysql') {
    require_once __DIR__ . '/database_mysql.php';
} elseif ($databaseDriver === 'sqlite') {
    if (!defined('DB_DRIVER')) {
        define('DB_DRIVER', 'sqlite');
    }

    require_once __DIR__ . '/offline_sync.php';
    require_once __DIR__ . '/sqlite_bootstrap.php';
    $databasePath = offlineSyncDatabasePath();
    if ($databasePath !== ':memory:' && !is_file($databasePath)) {
        throw new RuntimeException('Primero crea la copia local con exportar_sqlite.php.');
    }

    $pdo = offlineSyncConnect();
    sqliteRegisterFunctions($pdo);
    if ($databasePath !== ':memory:') {
        offlineSyncInstallTriggers($pdo);
    }
} else {
    throw new RuntimeException('APP_DB_DRIVER debe ser mysql o sqlite.');
}

require_once __DIR__ . '/database_helpers.php';
