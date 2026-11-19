<?php

require_once dirname(__DIR__) . '/includes/sqlite_bootstrap.php';

function assertSqliteBootstrapTest(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
sqliteRegisterFunctions($pdo);

$columns = [
    [
        'Field' => 'id',
        'Type' => 'int(11)',
        'Null' => 'NO',
        'Key' => 'PRI',
        'Default' => null,
        'Extra' => 'auto_increment',
        'Collation' => null,
    ],
    [
        'Field' => 'uuid',
        'Type' => 'char(36)',
        'Null' => 'NO',
        'Key' => 'UNI',
        'Default' => null,
        'Extra' => '',
        'Collation' => 'utf8mb4_general_ci',
    ],
    [
        'Field' => 'estado',
        'Type' => "enum('activo','inactivo')",
        'Null' => 'NO',
        'Key' => '',
        'Default' => 'activo',
        'Extra' => '',
        'Collation' => 'utf8mb4_general_ci',
    ],
    [
        'Field' => 'updated_at',
        'Type' => 'timestamp',
        'Null' => 'NO',
        'Key' => '',
        'Default' => 'CURRENT_TIMESTAMP',
        'Extra' => 'on update CURRENT_TIMESTAMP',
        'Collation' => null,
    ],
];
$indexes = [
    [
        'Key_name' => 'PRIMARY',
        'Seq_in_index' => 1,
        'Column_name' => 'id',
        'Non_unique' => 0,
        'Index_type' => 'BTREE',
        'Sub_part' => null,
        'Collation' => 'A',
    ],
    [
        'Key_name' => 'uq_uuid',
        'Seq_in_index' => 1,
        'Column_name' => 'uuid',
        'Non_unique' => 0,
        'Index_type' => 'BTREE',
        'Sub_part' => null,
        'Collation' => 'A',
    ],
];

assertSqliteBootstrapTest(sqliteBootstrapType('bigint(20) unsigned') === 'INTEGER', 'No se mapeó bigint.');
assertSqliteBootstrapTest(sqliteBootstrapType('decimal(10,2)') === 'NUMERIC', 'No se mapeó decimal.');
assertSqliteBootstrapTest(
    sqliteBootstrapDefault($pdo, ['Default' => 'CURRENT_TIMESTAMP()']) === 'CURRENT_TIMESTAMP',
    'No se normalizó el default timestamp.'
);

$expectedTriggers = [];
foreach (sqliteBootstrapExpectedUuidTriggers() as $name => $table) {
    $expectedTriggers[] = [
        'Trigger' => $name,
        'Table' => $table,
        'Timing' => 'BEFORE',
        'Event' => 'INSERT',
        'Statement' => "SET NEW.`uuid` = IF(NEW.`uuid` IS NULL OR NEW.`uuid` = '', UUID(), NEW.`uuid`)",
    ];
}
sqliteBootstrapValidateTriggers([]);
sqliteBootstrapValidateTriggers($expectedTriggers);

$unexpectedTriggerRejected = false;
try {
    sqliteBootstrapValidateTriggers(array_slice($expectedTriggers, 0, -1));
} catch (RuntimeException $exception) {
    $unexpectedTriggerRejected = true;
}
assertSqliteBootstrapTest(
    $unexpectedTriggerRejected,
    'No se rechazó una migración UUID incompleta.'
);

$unexpectedTriggerRejected = false;
$unexpectedTrigger = $expectedTriggers;
$unexpectedTrigger[0]['Statement'] = 'DELETE FROM estudiantes';
try {
    sqliteBootstrapValidateTriggers($unexpectedTrigger);
} catch (RuntimeException $exception) {
    $unexpectedTriggerRejected = true;
}
assertSqliteBootstrapTest(
    $unexpectedTriggerRejected,
    'No se rechazó un trigger UUID con lógica inesperada.'
);

sqliteBootstrapCreateTable($pdo, $pdo, 'muestra', $columns, $indexes, []);
sqliteBootstrapCreateIndexes($pdo, 'muestra', $indexes);
sqliteBootstrapCreateTriggers($pdo, 'muestra', $columns, $indexes);
$pdo->exec('INSERT INTO "muestra" ("uuid") VALUES ("uuid-a")');
$id = (int) $pdo->lastInsertId();
$pdo->exec('UPDATE "muestra" SET "estado" = "inactivo" WHERE "id" = ' . $id);
$pdo->exec('INSERT INTO "muestra" ("estado") VALUES ("activo")');
$generatedUuid = $pdo->query(
    'SELECT "uuid" FROM "muestra" WHERE "id" > ' . $id
)->fetchColumn();
$updatedAt = $pdo->query(
    'SELECT "updated_at" FROM "muestra" WHERE "id" = ' . $id
)->fetchColumn();
assertSqliteBootstrapTest(is_string($updatedAt) && $updatedAt !== '', 'No se aplicó el timestamp por defecto.');
assertSqliteBootstrapTest(
    is_string($generatedUuid) && preg_match('/^[0-9a-f-]{36}$/', $generatedUuid),
    'No se generó UUID al insertar una fila local.'
);

try {
    $pdo->exec('INSERT INTO "muestra" ("uuid") VALUES ("UUID-A")');
    throw new RuntimeException('El índice case-insensitive permitió un UUID duplicado.');
} catch (PDOException $exception) {
}

echo "sqlite bootstrap tests passed\n";
