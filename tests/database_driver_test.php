<?php

putenv('APP_DB_DRIVER=sqlite');
putenv('OFFLINE_DB_PATH=:memory:');
require_once dirname(__DIR__) . '/includes/database.php';

function assertDatabaseDriverTest(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

assertDatabaseDriverTest(
    $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite',
    'No se abrió SQLite en modo offline.'
);
assertDatabaseDriverTest(
    $pdo->query("SELECT CONCAT('Sistema', ' ', 'Offline')")->fetchColumn() === 'Sistema Offline',
    'CONCAT no es compatible en SQLite.'
);
assertDatabaseDriverTest(
    (int) $pdo->query("SELECT YEAR('2025-03-04')")->fetchColumn() === 2025,
    'YEAR no es compatible en SQLite.'
);
assertDatabaseDriverTest(
    $pdo->query("SELECT DATE_FORMAT('2025-03-04', '%Y-%m')")->fetchColumn() === '2025-03',
    'DATE_FORMAT no es compatible en SQLite.'
);

$pdo->exec(
    'CREATE TABLE database_driver_fixture (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        uuid TEXT NOT NULL UNIQUE,
        nombre TEXT NOT NULL,
        updated_at TEXT DEFAULT CURRENT_TIMESTAMP
    )'
);
assertDatabaseDriverTest(
    databaseTableExists($pdo, 'database_driver_fixture'),
    'No se detectó una tabla SQLite.'
);
assertDatabaseDriverTest(
    databaseColumnExists($pdo, 'database_driver_fixture', 'nombre'),
    'No se detectó una columna SQLite.'
);
assertDatabaseDriverTest(
    !databaseColumnExists($pdo, 'database_driver_fixture', 'missing'),
    'Se reportó una columna inexistente.'
);

$upsertClause = databaseUpsertClause(
    $pdo,
    ['uuid'],
    ['nombre'],
    ['updated_at']
);
$statement = $pdo->prepare(
    'INSERT INTO database_driver_fixture (uuid, nombre) VALUES (?, ?) ' . $upsertClause
);
$statement->execute(['fixture-uuid', 'Primero']);
$statement->execute(['fixture-uuid', 'Actualizado']);
assertDatabaseDriverTest(
    $pdo->query('SELECT nombre FROM database_driver_fixture')->fetchColumn() === 'Actualizado',
    'El upsert SQLite no actualizó el registro existente.'
);

echo "database driver tests passed\n";
