<?php

function app_base_path(): string
{
    $configuredPath = getenv('APP_BASE_PATH');
    if ($configuredPath !== false) {
        $configuredPath = str_replace('\\', '/', $configuredPath);
        $configuredPath = trim($configuredPath, '/');
        return $configuredPath === '' ? '' : '/' . $configuredPath;
    }

    $scriptName = $_SERVER['SCRIPT_NAME'] ?? '';
    if (preg_match('~^(.*?)(?=/(?:admin|profesor|estudiante|includes|assets|css|js)(?:/|$))~i', $scriptName, $matches)) {
        return rtrim($matches[1], '/');
    }

    $directory = str_replace('\\', '/', dirname($scriptName));
    return $directory === '/' || $directory === '.' ? '' : rtrim($directory, '/');
}

function app_url(string $path = ''): string
{
    $basePath = app_base_path();
    $path = ltrim($path, '/');

    if ($path === '') {
        return $basePath === '' ? '/' : $basePath . '/';
    }

    return $basePath . '/' . $path;
}
