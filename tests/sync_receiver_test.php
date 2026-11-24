<?php

require_once dirname(__DIR__) . '/includes/sync_receiver.php';

function assertSyncReceiverTest(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pullTriggers = syncPullTriggerStatements($pdo);
assertSyncReceiverTest(
    count($pullTriggers) === count(syncReceiverOrder()) * 3,
    'La instalación pull debe generar disparadores de altas, cambios y borrados.'
);
assertSyncReceiverTest(
    strpos(implode("\n", $pullTriggers), "'uuid_representante_id'") !== false &&
        strpos(implode("\n", $pullTriggers), "'delete'") !== false,
    'Los eventos pull deben incluir UUID de claves foráneas y tombstones.'
);
$pdo->exec(
    'CREATE TABLE representantes (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        uuid TEXT NOT NULL UNIQUE,
        cedula TEXT NOT NULL,
        nombre TEXT NOT NULL,
        apellido TEXT NOT NULL,
        parentesco TEXT NOT NULL,
        sincronizado INTEGER NOT NULL DEFAULT 0
    )'
);
$pdo->exec(
    'CREATE TABLE estudiantes (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        uuid TEXT NOT NULL UNIQUE,
        nombre TEXT NOT NULL,
        apellido TEXT NOT NULL,
        representante_id INTEGER NOT NULL,
        sincronizado INTEGER NOT NULL DEFAULT 0,
        FOREIGN KEY (representante_id) REFERENCES representantes(id) ON DELETE RESTRICT
    )'
);
$pdo->exec(
    'CREATE TABLE sync_map (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        uuid TEXT NOT NULL,
        tabla TEXT NOT NULL,
        id_remoto INTEGER NOT NULL,
        last_received_at TEXT DEFAULT NULL,
        UNIQUE (uuid, tabla)
    )'
);

$records = [
    'estudiantes' => [[
        'uuid' => '22222222-2222-4222-8222-222222222222',
        'nombre' => 'Estudiante',
        'apellido' => 'Prueba',
        'uuid_representante_id' => '11111111-1111-4111-8111-111111111111',
    ]],
    'representantes' => [[
        'uuid' => '11111111-1111-4111-8111-111111111111',
        'cedula' => 'TEST-1',
        'nombre' => 'Representante',
        'apellido' => 'Prueba',
        'parentesco' => 'madre',
    ]],
];

$firstResults = syncProcessRecords($pdo, $records, '2025-01-01 10:00:00.000000');
assertSyncReceiverTest(count($firstResults) === 2, 'No se procesaron ambos registros.');
assertSyncReceiverTest(
    $firstResults[0]['tabla'] === 'representantes',
    'El padre debe procesarse antes que el hijo.'
);

$studentId = (int) $pdo->query('SELECT `id` FROM `estudiantes`')->fetchColumn();
$representativeId = (int) $pdo->query('SELECT `id` FROM `representantes`')->fetchColumn();
assertSyncReceiverTest(
    (int) $pdo->query('SELECT `representante_id` FROM `estudiantes`')->fetchColumn() === $representativeId,
    'La clave foránea no se resolvió por UUID.'
);

$records['representantes'][0]['nombre'] = 'Representante actualizado';
$records['estudiantes'][0]['nombre'] = 'Estudiante actualizado';
$secondResults = syncProcessRecords($pdo, $records, '2025-01-02 10:00:00.000000');
assertSyncReceiverTest(
    $secondResults[0]['operacion'] === 'updated' &&
    $secondResults[0]['id_remoto'] === $representativeId,
    'Un reenvío debe actualizar el mismo registro remoto.'
);
assertSyncReceiverTest(
    (int) $pdo->query('SELECT COUNT(*) FROM `representantes`')->fetchColumn() === 1,
    'Un reenvío duplicó el registro.'
);
assertSyncReceiverTest(
    (int) $pdo->query('SELECT COUNT(*) FROM `sync_map`')->fetchColumn() === 2,
    'No se guardó el mapa UUID a ID remoto.'
);
assertSyncReceiverTest(
    (int) $pdo->query('SELECT `id` FROM `estudiantes`')->fetchColumn() === $studentId,
    'La actualización cambió el ID remoto.'
);

$records['estudiantes'][0]['nombre'] = 'Cambio retrasado';
$staleResults = syncProcessRecords($pdo, $records, '2025-01-01 12:00:00.000000');
assertSyncReceiverTest(
    $staleResults[1]['operacion'] === 'ignored_stale',
    'Una solicitud más antigua debe ignorarse.'
);
assertSyncReceiverTest(
    $pdo->query('SELECT `nombre` FROM `estudiantes`')->fetchColumn() === 'Estudiante actualizado',
    'Una solicitud antigua sobrescribió un cambio más reciente.'
);

try {
    syncProcessRecords($pdo, ['estudiantes' => [[
        'uuid' => '33333333-3333-4333-8333-333333333333',
        'nombre' => 'No',
        'apellido' => 'ID',
        'representante_id' => 1,
    ]]], '2025-01-03 10:00:00.000000');
    throw new RuntimeException('Se aceptó una clave foránea con ID local.');
} catch (SyncPayloadException $exception) {
}

try {
    syncProcessRecords($pdo, [
        'representantes' => [[
            'uuid' => '44444444-4444-4444-8444-444444444444',
            'cedula' => 'TEST-2',
            'nombre' => 'Rollback',
            'apellido' => 'Prueba',
            'parentesco' => 'madre',
        ]],
        'estudiantes' => [[
            'uuid' => '55555555-5555-4555-8555-555555555555',
            'nombre' => 'Falla',
            'apellido' => 'FK',
            'uuid_representante_id' => '99999999-9999-4999-8999-999999999999',
        ]],
    ], '2025-01-03 10:00:00.000000');
    throw new RuntimeException('Se aceptó una referencia UUID inexistente.');
} catch (SyncPayloadException $exception) {
}

assertSyncReceiverTest(
    (int) $pdo->query('SELECT COUNT(*) FROM `representantes`')->fetchColumn() === 1,
    'Un error no revirtió la transacción completa.'
);

$deleteResults = syncProcessRecords($pdo, [], '2025-01-04 10:00:00.000000', [
    'representantes' => ['11111111-1111-4111-8111-111111111111'],
    'estudiantes' => ['22222222-2222-4222-8222-222222222222'],
]);
assertSyncReceiverTest(
    $deleteResults[0]['tabla'] === 'estudiantes' &&
        $deleteResults[0]['operacion'] === 'deleted' &&
        $deleteResults[1]['tabla'] === 'representantes' &&
        $deleteResults[1]['operacion'] === 'deleted',
    'Los borrados deben procesarse de hijos a padres.'
);
assertSyncReceiverTest(
    (int) $pdo->query('SELECT COUNT(*) FROM `representantes`')->fetchColumn() === 0 &&
        (int) $pdo->query('SELECT COUNT(*) FROM `estudiantes`')->fetchColumn() === 0,
    'No se borraron los registros remotos.'
);
$repeatDeleteResults = syncProcessRecords($pdo, [], '2025-01-05 10:00:00.000000', [
    'estudiantes' => ['22222222-2222-4222-8222-222222222222'],
    'representantes' => ['11111111-1111-4111-8111-111111111111'],
]);
assertSyncReceiverTest(
    $repeatDeleteResults[0]['operacion'] === 'already_deleted' &&
        $repeatDeleteResults[1]['operacion'] === 'already_deleted',
    'Reintentar un borrado confirmado debe ser idempotente.'
);
$staleDeleteResults = syncProcessRecords($pdo, [], '2025-01-03 10:00:00.000000', [
    'estudiantes' => ['22222222-2222-4222-8222-222222222222'],
]);
assertSyncReceiverTest(
    $staleDeleteResults[0]['operacion'] === 'ignored_stale',
    'Un borrado atrasado no debe invalidar una recepción posterior.'
);

$annualPdo = new PDO('sqlite::memory:');
$annualPdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
foreach (syncReceiverOrder() as $table) {
    $columns = [
        '`id` INTEGER PRIMARY KEY AUTOINCREMENT',
        '`uuid` TEXT NOT NULL UNIQUE',
        '`sincronizado` INTEGER NOT NULL DEFAULT 0',
    ];
    foreach (syncReceiverSchema()[$table]['fields'] as $column) {
        $columns[] = syncQuoteIdentifier($column) . ' ' .
            (isset(syncReceiverSchema()[$table]['foreign_keys'][$column]) ? 'INTEGER' : 'TEXT');
    }
    $annualPdo->exec(
        'CREATE TABLE ' . syncQuoteIdentifier($table) . ' (' . implode(', ', $columns) . ')'
    );
}
$annualPdo->exec(
    'CREATE TABLE `sync_map` (
        `id` INTEGER PRIMARY KEY AUTOINCREMENT,
        `uuid` TEXT NOT NULL,
        `tabla` TEXT NOT NULL,
        `id_remoto` INTEGER NOT NULL,
        `last_received_at` TEXT DEFAULT NULL,
        UNIQUE (`uuid`, `tabla`)
    )'
);
$annualRecords = [
    'plan_anual_areas' => [[
        'uuid' => '66666666-6666-4666-8666-666666666666',
        'uuid_año_escolar_id' => '11111111-1111-4111-8111-111111111111',
        'uuid_area_id' => '22222222-2222-4222-8222-222222222222',
    ]],
    'plan_anual_indicadores' => [[
        'uuid' => '77777777-7777-4777-8777-777777777777',
        'uuid_año_escolar_id' => '11111111-1111-4111-8111-111111111111',
        'uuid_indicador_id' => '33333333-3333-4333-8333-333333333333',
    ]],
    'areas_indicadores' => [[
        'uuid' => '33333333-3333-4333-8333-333333333333',
        'nombre' => 'Indicador anual',
        'uuid_area_id' => '22222222-2222-4222-8222-222222222222',
    ]],
    'años_escolares' => [[
        'uuid' => '11111111-1111-4111-8111-111111111111',
        'nombre' => '2025-2026',
    ]],
    'areas_aprendizaje' => [[
        'uuid' => '22222222-2222-4222-8222-222222222222',
        'nombre' => 'Matemática',
    ]],
];
$annualResults = syncProcessRecords($annualPdo, $annualRecords, '2025-02-01 10:00:00.000000');
assertSyncReceiverTest(
    count($annualResults) === 5 &&
        (int) $annualPdo->query(
            'SELECT COUNT(*) FROM `plan_anual_areas` WHERE `año_escolar_id` = (
                SELECT `id` FROM `años_escolares` WHERE `nombre` = "2025-2026"
            )'
        )->fetchColumn() === 1 &&
        (int) $annualPdo->query(
            'SELECT COUNT(*) FROM `plan_anual_indicadores` WHERE `indicador_id` = (
                SELECT `id` FROM `areas_indicadores` WHERE `nombre` = "Indicador anual"
            )'
        )->fetchColumn() === 1,
    'Las asociaciones del plan anual deben sincronizarse resolviendo sus UUID de claves foráneas.'
);

echo "sync receiver tests passed\n";
