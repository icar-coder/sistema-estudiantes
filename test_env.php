<?php
header('Content-Type: text/plain; charset=utf-8');

echo "getenv('SYNC_TOKEN') = ";
var_dump(getenv('SYNC_TOKEN'));

echo "\n\$_ENV['SYNC_TOKEN'] = ";
var_dump($_ENV['SYNC_TOKEN'] ?? null);

echo "\n\$_SERVER['SYNC_TOKEN'] = ";
var_dump($_SERVER['SYNC_TOKEN'] ?? null);

echo "\n\nvariables_order = " . ini_get('variables_order') . "\n";