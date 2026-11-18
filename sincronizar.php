<?php

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/includes/offline_sync.php';

try {
    $config = offlineSyncGetConfig();
    $pdo = offlineSyncConnect();
    $summary = offlineSyncRun($pdo, $config['endpoint'], $config['token']);
    echo json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;

    if ($summary['pending'] > 0 || $summary['blocked'] > 0) {
        exit(2);
    }
} catch (Throwable $exception) {
    fwrite(STDERR, 'Error de sincronización: ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}
