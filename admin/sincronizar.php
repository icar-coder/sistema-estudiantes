<?php

require_once '../includes/auth.php';
require_once '../includes/database.php';
checkAuth();
checkRole(['admin']);

function offlineSyncAdminRespond(int $status, array $response): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    offlineSyncAdminRespond(405, ['error' => 'method_not_allowed']);
}

if (!isSqliteDatabase($pdo)) {
    offlineSyncAdminRespond(409, ['error' => 'offline_mode_required']);
}

$contentType = $_SERVER['CONTENT_TYPE'] ?? '';
if (stripos($contentType, 'application/json') !== 0) {
    offlineSyncAdminRespond(415, ['error' => 'content_type_must_be_json']);
}

$csrfToken = $_SESSION['csrf_offline_sync'] ?? '';
$submittedToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
if (!is_string($csrfToken) || $csrfToken === '' ||
    !is_string($submittedToken) || !hash_equals($csrfToken, $submittedToken)) {
    offlineSyncAdminRespond(403, ['error' => 'csrf_validation_failed']);
}

$requestBody = file_get_contents('php://input', false, null, 0, 1025);
if ($requestBody === false || strlen($requestBody) > 1024 ||
    json_decode($requestBody, true) !== []) {
    offlineSyncAdminRespond(400, ['error' => 'invalid_request']);
}

try {
    $config = offlineSyncGetConfig();
    $summary = offlineSyncRun($pdo, $config['endpoint'], $config['token'], 2);
    offlineSyncAdminRespond(200, ['summary' => $summary]);
} catch (OfflineSyncBusyException $exception) {
    offlineSyncAdminRespond(202, ['busy' => true]);
} catch (Throwable $exception) {
    error_log('Error en sincronización administrativa offline: ' . $exception->getMessage());
    offlineSyncAdminRespond(503, ['error' => 'sync_failed']);
}
