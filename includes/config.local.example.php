<?php

return [
    'db_driver' => 'mysql',
    'host' => 'localhost',
    'name' => 'nombre_de_base_de_datos',
    'user' => 'usuario_de_base_de_datos',
    'pass' => 'contraseña_de_base_de_datos',
    'charset' => 'utf8mb4',
    'app_url' => 'https://sistemaestudiante.alwaysdata.net',
    'mail' => [
        'host' => 'smtp.gmail.com',
        'port' => 587,
        'username' => 'tu-cuenta@gmail.com',
        'password' => 'contraseña-de-aplicación-de-google',
        'encryption' => 'tls',
        'from_address' => 'tu-cuenta@gmail.com',
        'from_name' => 'Sistema de Gestión Estudiantil',
    ],
    'sync' => [
        'endpoint' => 'https://sistemaestudiante.alwaysdata.net/api_sync.php',
        'token' => 'reemplazar-por-un-token-aleatorio-de-al-menos-32-caracteres',
    ],
    'gemini' => [
        'api_key' => '',
        'models' => [
            'gemini-3.5-flash-lite',
            'gemini-3.8-flash',
            'gemini-3.1-flash-lite',
        ],
    ],
    'openrouter' => [
        'api_key' => '',
    ],
    'offline_db_path' => dirname(__DIR__) . '/database/datos_locales.sqlite',
];
