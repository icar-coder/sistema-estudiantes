<?php

function getDatabaseConfig(): array
{
    static $config = null;

    if ($config !== null) {
        return $config;
    }

    $localConfigPath = __DIR__ . '/config.local.php';
    $localConfig = is_file($localConfigPath) ? require $localConfigPath : [];

    if (!is_array($localConfig)) {
        throw new RuntimeException('La configuración local de la base de datos debe devolver un arreglo.');
    }

    $config = [];
    foreach (['host', 'name', 'user', 'pass', 'charset'] as $key) {
        $environmentValue = getenv('DB_' . strtoupper($key));
        if ($environmentValue !== false) {
            $config[$key] = $environmentValue;
        } elseif (array_key_exists($key, $localConfig)) {
            $config[$key] = $localConfig[$key];
        } elseif ($key === 'charset') {
            $config[$key] = 'utf8mb4';
        } else {
            $config[$key] = null;
        }
    }

    foreach (['host', 'name', 'user', 'pass'] as $key) {
        if ($config[$key] === null || ($key !== 'pass' && $config[$key] === '')) {
            error_log('Falta configurar la variable DB_' . strtoupper($key) . ' o includes/config.local.php.');
            http_response_code(500);
            exit('La configuración de la base de datos está incompleta.');
        }
    }

    return $config;
}
