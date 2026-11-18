<?php

ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
error_reporting(E_ALL);

require_once __DIR__ . '/includes/sync_receiver.php';

$requestReceivedAt = (new DateTimeImmutable('now', new DateTimeZone('UTC')))
    ->format('Y-m-d H:i:s.u');

function syncRespond(int $status, array $body): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    syncRespond(405, ['error' => 'method_not_allowed']);
}

$expectedToken = getenv('SYNC_TOKEN');
if ($expectedToken === false || $expectedToken === '') {
    $localConfigPath = __DIR__ . '/includes/config.local.php';
    $localConfig = is_file($localConfigPath) ? require $localConfigPath : [];
    $expectedToken = is_array($localConfig) ? ($localConfig['sync']['token'] ?? false) : false;
}

if (!is_string($expectedToken) || strlen($expectedToken) < 32) {
    error_log('SYNC_TOKEN debe configurarse con al menos 32 caracteres.');
    syncRespond(500, ['error' => 'sync_not_configured']);
}

$authorization = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
if (!preg_match('/^Bearer\s+(.+)$/i', $authorization, $matches) ||
    !hash_equals($expectedToken, trim($matches[1]))) {
    syncRespond(401, ['error' => 'unauthorized']);
}

$contentType = $_SERVER['CONTENT_TYPE'] ?? '';
if (stripos($contentType, 'application/json') !== 0) {
    syncRespond(415, ['error' => 'content_type_must_be_json']);
}

$maxRequestSize = 2097152;
$contentLength = $_SERVER['CONTENT_LENGTH'] ?? null;
if ($contentLength !== null && ctype_digit((string) $contentLength) &&
    (int) $contentLength > $maxRequestSize) {
    syncRespond(413, ['error' => 'request_too_large']);
}

$rawBody = file_get_contents('php://input', false, null, 0, $maxRequestSize + 1);
if ($rawBody === false || strlen($rawBody) > $maxRequestSize) {
    syncRespond(413, ['error' => 'request_too_large']);
}

$payload = json_decode($rawBody, true);
if (json_last_error() !== JSON_ERROR_NONE || !is_array($payload) ||
    !array_key_exists('records', $payload) || count($payload) < 1 || count($payload) > 2 ||
    array_diff(array_keys($payload), ['records', 'deletions']) !== [] ||
    !is_array($payload['records']) ||
    (array_key_exists('deletions', $payload) && !is_array($payload['deletions']))) {
    syncRespond(400, ['error' => 'invalid_json_payload']);
}

try {
    require_once __DIR__ . '/includes/database_mysql.php';
    $lockName = 'sistema_est_sync_' . substr(hash('sha256', DB_NAME), 0, 40);
    if (!syncAcquireReceiverLock($pdo, $lockName)) {
        syncRespond(503, ['error' => 'sync_temporarily_unavailable']);
    }

    try {
        $results = syncProcessRecords(
            $pdo,
            $payload['records'],
            $requestReceivedAt,
            $payload['deletions'] ?? []
        );
    } finally {
        syncReleaseReceiverLock($pdo, $lockName);
    }

    syncRespond(200, [
        'received_at' => str_replace(' ', 'T', $requestReceivedAt) . 'Z',
        'results' => $results,
    ]);
} catch (SyncPayloadException $exception) {
    syncRespond(422, ['error' => 'invalid_sync_record', 'message' => $exception->getMessage()]);
} catch (SyncLockException $exception) {
    error_log('No se pudo adquirir el bloqueo en api_sync.php: ' . $exception->getMessage());
    syncRespond(503, ['error' => 'sync_temporarily_unavailable']);
} catch (PDOException $exception) {
    error_log('Error de base de datos en api_sync.php: ' . $exception->getMessage());
    if (isset($exception->errorInfo[0]) && $exception->errorInfo[0] === '23000') {
        syncRespond(409, ['error' => 'unique_or_foreign_key_conflict']);
    }
    syncRespond(500, ['error' => 'sync_database_error']);
}
