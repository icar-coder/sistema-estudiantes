<?php

require_once dirname(__DIR__) . '/includes/offline_sync.php';

function assertOfflineSyncTest(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function createOfflineSyncFixtureTable(PDO $pdo, string $table): void
{
    $schema = syncReceiverSchema()[$table];
    $columns = [
        '`id` INTEGER PRIMARY KEY AUTOINCREMENT',
        '`uuid` TEXT NOT NULL UNIQUE',
        '`sincronizado` INTEGER NOT NULL DEFAULT 0',
    ];
    foreach ($schema['fields'] as $column) {
        $type = isset($schema['foreign_keys'][$column]) ? 'INTEGER' : 'TEXT';
        $columns[] = syncQuoteIdentifier($column) . ' ' . $type;
    }

    $pdo->exec(
        'CREATE TABLE ' . syncQuoteIdentifier($table) . ' (' . implode(', ', $columns) . ')'
    );
}

$legacyPdo = new PDO('sqlite::memory:');
$legacyPdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$legacyPdo->exec(
    "CREATE TABLE `offline_sync_outbox` (
        `id` INTEGER PRIMARY KEY AUTOINCREMENT,
        `tabla` TEXT NOT NULL,
        `uuid` TEXT NOT NULL,
        `payload_json` TEXT NOT NULL,
        `estado` TEXT NOT NULL DEFAULT 'pending',
        `attempts` INTEGER NOT NULL DEFAULT 0,
        `last_error` TEXT DEFAULT NULL,
        `created_at` TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `updated_at` TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE (`tabla`, `uuid`)
    )"
);
$legacyPdo->exec(
    'INSERT INTO `offline_sync_outbox` (`tabla`, `uuid`, `payload_json`)
     VALUES ("representantes", "77777777-7777-4777-8777-777777777777", "{}")'
);
offlineSyncInitialize($legacyPdo);
assertOfflineSyncTest(
    $legacyPdo->query(
        'SELECT `operacion` FROM `offline_sync_outbox`
         WHERE `uuid` = "77777777-7777-4777-8777-777777777777"'
    )->fetchColumn() === 'upsert',
    'La cola existente debe migrarse conservando sus registros pendientes como altas/cambios.'
);

$triggerPdo = new PDO('sqlite::memory:');
$triggerPdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
offlineSyncInitialize($triggerPdo);
createOfflineSyncFixtureTable($triggerPdo, 'representantes');
createOfflineSyncFixtureTable($triggerPdo, 'estudiantes');
offlineSyncInstallTriggers($triggerPdo, ['representantes', 'estudiantes']);

$fixtureRows = [
    'representantes' => [
        'uuid' => '33333333-3333-4333-8333-333333333333',
        'cedula' => 'FIXTURE-1',
        'nombre' => 'Madre',
        'apellido' => 'Prueba',
        'parentesco' => 'madre',
    ],
];
foreach ($fixtureRows as $table => $row) {
    foreach (syncReceiverSchema()[$table]['fields'] as $column) {
        $row += [$column => null];
    }
    $row['sincronizado'] = 0;
    $columns = array_keys($row);
    $insert = $triggerPdo->prepare(
        'INSERT INTO ' . syncQuoteIdentifier($table) . ' (' .
        implode(', ', array_map('syncQuoteIdentifier', $columns)) . ') VALUES (' .
        implode(', ', array_fill(0, count($columns), '?')) . ')'
    );
    $insert->execute(array_values($row));
}
$representativeId = (int) $triggerPdo->lastInsertId();

$studentRow = [
    'uuid' => '44444444-4444-4444-8444-444444444444',
    'sincronizado' => 0,
    'representante_id' => $representativeId,
    'nombre' => 'Estudiante',
    'apellido' => 'Prueba',
];
foreach (syncReceiverSchema()['estudiantes']['fields'] as $column) {
    $studentRow += [$column => null];
}
$studentColumns = array_keys($studentRow);
$insertStudent = $triggerPdo->prepare(
    'INSERT INTO `estudiantes` (' .
    implode(', ', array_map('syncQuoteIdentifier', $studentColumns)) . ') VALUES (' .
    implode(', ', array_fill(0, count($studentColumns), '?')) . ')'
);
$insertStudent->execute(array_values($studentRow));

$studentOutbox = $triggerPdo->query(
    'SELECT `payload_json` FROM `offline_sync_outbox` WHERE `tabla` = "estudiantes"'
)->fetchColumn();
$studentPayload = json_decode($studentOutbox, true);
assertOfflineSyncTest(
    ($studentPayload['uuid_representante_id'] ?? null) === $fixtureRows['representantes']['uuid'] &&
        !array_key_exists('representante_id', $studentPayload),
    'El disparador debe traducir las claves foráneas locales a UUID.'
);

$triggerBatch = offlineSyncNextBatch($triggerPdo);
$triggerResults = [];
foreach ($triggerBatch['entries'] as $index => $entry) {
    $triggerResults[] = [
        'tabla' => $entry['tabla'],
        'uuid' => $entry['uuid'],
        'id_remoto' => $index + 1,
        'operacion' => 'inserted',
    ];
}
offlineSyncStoreResults($triggerPdo, $triggerBatch['entries'], $triggerResults);
assertOfflineSyncTest(
    (int) $triggerPdo->query(
        'SELECT `sincronizado` FROM `estudiantes` WHERE `uuid` = "44444444-4444-4444-8444-444444444444"'
    )->fetchColumn() === 1,
    'La sincronización exitosa debe marcar el registro local como sincronizado.'
);
$triggerPdo->exec(
    'UPDATE `estudiantes` SET `nombre` = "Estudiante editado" ' .
    'WHERE `uuid` = "44444444-4444-4444-8444-444444444444"'
);
assertOfflineSyncTest(
    offlineSyncPendingCount($triggerPdo) === 1,
    'La edición posterior debe volver a poner el registro en la cola.'
);
assertOfflineSyncTest(
    (int) $triggerPdo->query(
        'SELECT `sincronizado` FROM `estudiantes`
         WHERE `uuid` = "44444444-4444-4444-8444-444444444444"'
    )->fetchColumn() === 0,
    'Una edición local debe marcar el registro como pendiente de sincronización.'
);
$triggerPdo->exec(
    'DELETE FROM `estudiantes` WHERE `uuid` = "44444444-4444-4444-8444-444444444444"'
);
$deletePayload = $triggerPdo->query(
    'SELECT `payload_json` FROM `offline_sync_outbox`
     WHERE `tabla` = "estudiantes" AND `operacion` = "delete"'
)->fetchColumn();
assertOfflineSyncTest(
    $deletePayload !== false &&
        json_decode($deletePayload, true) === [
            'uuid' => '44444444-4444-4444-8444-444444444444',
        ],
    'El borrado de un registro sincronizado debe generar una tombstone UUID.'
);
$deleteBatch = offlineSyncNextBatch($triggerPdo);
assertOfflineSyncTest(
    ($deleteBatch['deletions']['estudiantes'] ?? []) === [
        '44444444-4444-4444-8444-444444444444',
    ],
    'La cola debe enviar los borrados separados de las altas y cambios.'
);
$deleteStored = offlineSyncStoreResults($triggerPdo, $deleteBatch['entries'], [[
    'tabla' => 'estudiantes',
    'uuid' => '44444444-4444-4444-8444-444444444444',
    'id_remoto' => 2,
    'operacion' => 'deleted',
]]);
assertOfflineSyncTest(
    $deleteStored['synced'] === 1 &&
        (int) $triggerPdo->query('SELECT COUNT(*) FROM `sync_map` WHERE `tabla` = "estudiantes"')
            ->fetchColumn() === 0,
    'Un borrado confirmado debe quitar el mapa remoto local.'
);

$unsyncedRepresentative = [
    'uuid' => '55555555-5555-4555-8555-555555555555',
    'cedula' => 'UNSYNCED',
    'nombre' => 'Temporal',
    'apellido' => 'Sincronizar',
    'parentesco' => 'madre',
];
foreach (syncReceiverSchema()['representantes']['fields'] as $column) {
    $unsyncedRepresentative += [$column => null];
}
$unsyncedColumns = ['uuid', 'sincronizado'];
foreach (syncReceiverSchema()['representantes']['fields'] as $column) {
    if (!in_array($column, $unsyncedColumns, true)) {
        $unsyncedColumns[] = $column;
    }
}
$insertUnsynced = $triggerPdo->prepare(
    'INSERT INTO `representantes` (' .
    implode(', ', array_map('syncQuoteIdentifier', $unsyncedColumns)) . ') VALUES (' .
    implode(', ', array_fill(0, count($unsyncedColumns), '?')) . ')'
);
$insertUnsynced->execute(array_map(
    function ($column) use ($unsyncedRepresentative) {
        return $column === 'sincronizado' ? 0 : $unsyncedRepresentative[$column];
    },
    $unsyncedColumns
));
$triggerPdo->exec(
    'DELETE FROM `representantes` WHERE `uuid` = "55555555-5555-4555-8555-555555555555"'
);
assertOfflineSyncTest(
    (int) $triggerPdo->query(
        'SELECT COUNT(*) FROM `offline_sync_outbox`
         WHERE `tabla` = "representantes" AND `uuid` = "55555555-5555-4555-8555-555555555555"'
    )->fetchColumn() === 0,
    'Borrar un registro que nunca llegó al servidor debe cancelar el envío pendiente.'
);

$completePdo = new PDO('sqlite::memory:');
$completePdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
offlineSyncInitialize($completePdo);
foreach (syncReceiverOrder() as $table) {
    createOfflineSyncFixtureTable($completePdo, $table);
}
$completePdo->exec(
    'CREATE TABLE `usuarios` (`id` INTEGER PRIMARY KEY, `uuid` TEXT NOT NULL UNIQUE)'
);
$seedRow = [
    'uuid' => '66666666-6666-4666-8666-666666666666',
    'sincronizado' => 1,
];
foreach (syncReceiverSchema()['representantes']['fields'] as $column) {
    $seedRow += [$column => null];
}
$seedRow['nombre'] = 'Histórico';
$seedRow['apellido'] = 'Exportado';
$seedRow['parentesco'] = 'madre';
$seedColumns = array_keys($seedRow);
$insertSeed = $completePdo->prepare(
    'INSERT INTO `representantes` (' .
    implode(', ', array_map('syncQuoteIdentifier', $seedColumns)) . ') VALUES (' .
    implode(', ', array_fill(0, count($seedColumns), '?')) . ')'
);
$insertSeed->execute(array_values($seedRow));
$seedId = (int) $completePdo->lastInsertId();
offlineSyncSeedSyncMap($completePdo);
assertOfflineSyncTest(
    (int) $completePdo->query(
        'SELECT `id_remoto` FROM `sync_map`
         WHERE `uuid` = "66666666-6666-4666-8666-666666666666" AND `tabla` = "representantes"'
    )->fetchColumn() === $seedId,
    'La exportación debe mapear los registros históricos para admitir sus borrados.'
);
offlineSyncInstallTriggers($completePdo);
assertOfflineSyncTest(
    (int) $completePdo->query(
        "SELECT COUNT(*) FROM sqlite_master WHERE type = 'trigger' AND name LIKE 'trg_outbox_%'"
    )->fetchColumn() === count(syncReceiverOrder()) * 3,
    'Deben instalarse los disparadores de altas, cambios y borrados de todas las tablas.'
);
$completePdo->exec(
    'UPDATE `sync_map` SET `id_remoto` = 0
     WHERE `uuid` = "66666666-6666-4666-8666-666666666666" AND `tabla` = "representantes"'
);
$completePdo->exec(
    'DELETE FROM `representantes` WHERE `uuid` = "66666666-6666-4666-8666-666666666666"'
);
$zeroIdBatch = offlineSyncNextBatch($completePdo);
$zeroIdStored = offlineSyncStoreResults($completePdo, $zeroIdBatch['entries'], [[
    'tabla' => 'representantes',
    'uuid' => '66666666-6666-4666-8666-666666666666',
    'id_remoto' => 0,
    'operacion' => 'deleted',
]]);
assertOfflineSyncTest(
    $zeroIdStored['synced'] === 1,
    'Los registros históricos con ID remoto cero deben poder borrar y cerrar su tombstone.'
);

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
offlineSyncInitialize($pdo);
$pdo->beginTransaction();
offlineSyncEnqueue($pdo, 'representantes', [
    'uuid' => '99999999-9999-4999-8999-999999999999',
    'cedula' => 'TEST-TX',
    'nombre' => 'Transacción',
    'apellido' => 'Externa',
    'parentesco' => 'madre',
]);
assertOfflineSyncTest(
    $pdo->inTransaction(),
    'Encolar debe conservar la transacción externa del formulario.'
);
$pdo->rollBack();
assertOfflineSyncTest(
    (int) $pdo->query('SELECT COUNT(*) FROM `offline_sync_outbox`')->fetchColumn() === 0,
    'El rollback externo debe revertir la cola offline.'
);

$representative = [
    'uuid' => '11111111-1111-4111-8111-111111111111',
    'cedula' => 'TEST-1',
    'nombre' => 'Representante',
    'apellido' => 'Prueba',
    'parentesco' => 'madre',
];
$student = [
    'uuid' => '22222222-2222-4222-8222-222222222222',
    'nombre' => 'Estudiante',
    'apellido' => 'Prueba',
    'uuid_representante_id' => $representative['uuid'],
];

offlineSyncEnqueue($pdo, 'estudiantes', $student);
offlineSyncEnqueue($pdo, 'representantes', $representative);
$batch = offlineSyncNextBatch($pdo);
assertOfflineSyncTest(
    array_keys($batch['records']) === ['representantes', 'estudiantes'],
    'El cliente debe ordenar los padres antes que los hijos.'
);
assertOfflineSyncTest(count($batch['entries']) === 2, 'No se seleccionaron ambos registros.');

$student['nombre'] = 'Estudiante actualizado';
offlineSyncEnqueue($pdo, 'estudiantes', $student);
assertOfflineSyncTest(
    (int) $pdo->query('SELECT COUNT(*) FROM `offline_sync_outbox`')->fetchColumn() === 2,
    'Reencolar el mismo UUID no debe duplicar el registro.'
);
assertOfflineSyncTest(
    offlineSyncPendingCount($pdo) === 2,
    'Una actualización reencolada debe quedar pendiente.'
);

$batch = offlineSyncNextBatch($pdo);
$results = [
    [
        'tabla' => 'representantes',
        'uuid' => $representative['uuid'],
        'id_remoto' => 51,
        'operacion' => 'inserted',
    ],
    [
        'tabla' => 'estudiantes',
        'uuid' => $student['uuid'],
        'id_remoto' => 81,
        'operacion' => 'updated',
    ],
];
$stored = offlineSyncStoreResults($pdo, $batch['entries'], $results);
assertOfflineSyncTest($stored['synced'] === 2, 'No se marcaron ambos registros como sincronizados.');
assertOfflineSyncTest(offlineSyncPendingCount($pdo) === 0, 'Quedaron registros pendientes.');
assertOfflineSyncTest(
    (int) $pdo->query('SELECT COUNT(*) FROM `sync_map`')->fetchColumn() === 2,
    'No se guardaron los ID remotos.'
);

offlineSyncEnqueue($pdo, 'estudiantes', $student);
$batch = offlineSyncNextBatch($pdo);
$stored = offlineSyncStoreResults($pdo, $batch['entries'], [[
    'tabla' => 'estudiantes',
    'uuid' => $student['uuid'],
    'id_remoto' => 81,
    'operacion' => 'ignored_stale',
]]);
assertOfflineSyncTest($stored['conflicts'] === 1, 'Un cambio antiguo debe quedar como conflicto.');
assertOfflineSyncTest(
    (int) $pdo->query(
        'SELECT COUNT(*) FROM `offline_sync_outbox` WHERE `estado` = "conflict"'
    )->fetchColumn() === 1,
    'El conflicto no quedó visible en la cola.'
);

echo "offline sync tests passed\n";
