<?php

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/includes/sync_receiver.php';
require_once __DIR__ . '/includes/database_mysql.php';

try {
    syncInstallPullSchema($pdo);
    echo 'Pull instalado: sync_changes y disparadores de cambios listos.' . PHP_EOL;
} catch (Throwable $exception) {
    fwrite(STDERR, 'No se pudo instalar el pull: ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}
