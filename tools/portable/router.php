<?php

$requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
if (!is_string($requestPath)) {
    http_response_code(400);
    exit;
}

$decodedPath = rawurldecode($requestPath);
if (strpos($decodedPath, "\0") !== false || strpos($decodedPath, '\\') !== false) {
    http_response_code(404);
    exit;
}

$segments = explode('/', trim($decodedPath, '/'));
foreach ($segments as $segment) {
    if ($segment === '.' || $segment === '..' || ($segment !== '' && $segment[0] === '.')) {
        http_response_code(404);
        exit;
    }
}

$blockedDirectories = ['database', 'includes', 'logs', 'tmp', 'vendor'];
if (isset($segments[0]) && in_array(strtolower($segments[0]), $blockedDirectories, true)) {
    http_response_code(404);
    exit;
}

if (preg_match('/\.(?:sqlite(?:-(?:wal|shm))?|db|sql|log|ini|env|bak|backup)$/i', $decodedPath)) {
    http_response_code(404);
    exit;
}

return false;
