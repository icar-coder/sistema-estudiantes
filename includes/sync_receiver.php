<?php

class SyncPayloadException extends RuntimeException
{
}

class SyncLockException extends RuntimeException
{
}

function syncReceiverSchema(): array
{
    return [
        'areas_aprendizaje' => [
            'fields' => ['nombre', 'codigo', 'descripcion', 'nivel', 'estado'],
            'required' => ['nombre'],
            'foreign_keys' => [],
        ],
        'años_escolares' => [
            'fields' => ['nombre', 'fecha_inicio', 'fecha_fin', 'estado'],
            'required' => ['nombre'],
            'foreign_keys' => [],
        ],
        'grados' => [
            'fields' => ['numero', 'nombre', 'nivel', 'orden', 'estado'],
            'required' => ['numero', 'nombre'],
            'foreign_keys' => [],
        ],
        'profesores' => [
            'fields' => [
                'cedula', 'nombre', 'apellido', 'fecha_nacimiento', 'lugar_nacimiento',
                'direccion', 'fecha_contratacion', 'email', 'telefono', 'especialidad',
                'grado_academico', 'estado',
            ],
            'required' => ['nombre', 'apellido'],
            'foreign_keys' => [],
        ],
        'representantes' => [
            'fields' => [
                'cedula', 'nombre', 'apellido', 'fecha_nacimiento', 'genero', 'parentesco',
                'profesion', 'lugar_trabajo', 'telefono', 'telefono_alternativo', 'email',
                'direccion', 'estado',
            ],
            'required' => ['cedula', 'nombre', 'apellido', 'parentesco'],
            'foreign_keys' => [],
        ],
        'areas_indicadores' => [
            'fields' => ['area_id', 'nombre', 'descripcion', 'escala', 'orden', 'estado'],
            'required' => ['nombre'],
            'foreign_keys' => [
                'area_id' => ['table' => 'areas_aprendizaje', 'nullable' => false],
            ],
        ],
        'secciones' => [
            'fields' => [
                'año_escolar_id', 'grado_id', 'nombre', 'capacidad', 'nivel', 'tipo_grado',
                'profesor_id',
            ],
            'required' => ['nombre'],
            'foreign_keys' => [
                'año_escolar_id' => ['table' => 'años_escolares', 'nullable' => true],
                'grado_id' => ['table' => 'grados', 'nullable' => true],
                'profesor_id' => ['table' => 'profesores', 'nullable' => true],
            ],
        ],
        'lapsos' => [
            'fields' => ['año_escolar_id', 'numero', 'nombre', 'fecha_inicio', 'fecha_fin', 'estado'],
            'required' => ['numero', 'nombre'],
            'foreign_keys' => [
                'año_escolar_id' => ['table' => 'años_escolares', 'nullable' => true],
            ],
        ],
        'estudiantes' => [
            'fields' => [
                'cedula_escolar', 'nombre', 'apellido', 'fecha_nacimiento', 'genero',
                'lugar_nacimiento', 'direccion', 'telefono', 'email', 'escuela_anterior',
                'enfermedades', 'alergias', 'medicamentos', 'observaciones', 'representante_id',
                'tipo_vivienda', 'personas_conviven', 'habilidades_especiales',
                'documentos_presentados',
            ],
            'required' => ['nombre', 'apellido'],
            'foreign_keys' => [
                'representante_id' => ['table' => 'representantes', 'nullable' => false],
            ],
        ],
        'estudiantes_representantes' => [
            'fields' => ['estudiante_id', 'representante_id', 'parentesco', 'es_principal'],
            'required' => ['parentesco'],
            'foreign_keys' => [
                'estudiante_id' => ['table' => 'estudiantes', 'nullable' => false],
                'representante_id' => ['table' => 'representantes', 'nullable' => false],
            ],
        ],
        'inscripciones' => [
            'fields' => ['estudiante_id', 'seccion_id', 'año_escolar_id', 'fecha_inscripcion', 'status'],
            'required' => [],
            'foreign_keys' => [
                'estudiante_id' => ['table' => 'estudiantes', 'nullable' => true],
                'seccion_id' => ['table' => 'secciones', 'nullable' => true],
                'año_escolar_id' => ['table' => 'años_escolares', 'nullable' => true],
            ],
        ],
        'horarios_asignaciones' => [
            'fields' => [
                'seccion_id', 'año_escolar_id', 'area_id', 'profesor_id', 'dia_semana',
                'hora_inicio', 'hora_fin', 'duracion', 'orden',
            ],
            'required' => ['dia_semana', 'hora_inicio', 'hora_fin'],
            'foreign_keys' => [
                'seccion_id' => ['table' => 'secciones', 'nullable' => false],
                'año_escolar_id' => ['table' => 'años_escolares', 'nullable' => false],
                'area_id' => ['table' => 'areas_aprendizaje', 'nullable' => false],
                'profesor_id' => ['table' => 'profesores', 'nullable' => false],
            ],
        ],
        'indicadores_seccion' => [
            'fields' => ['seccion_id', 'area_id', 'nombre', 'descripcion', 'escala', 'orden'],
            'required' => ['nombre', 'escala'],
            'foreign_keys' => [
                'seccion_id' => ['table' => 'secciones', 'nullable' => false],
                'area_id' => ['table' => 'areas_aprendizaje', 'nullable' => false],
            ],
        ],
        'historial_academico' => [
            'fields' => [
                'estudiante_id', 'año_escolar_id', 'grado_id', 'literal_final',
                'tipo_adelantamiento', 'observaciones', 'fecha_registro',
            ],
            'required' => ['literal_final'],
            'foreign_keys' => [
                'estudiante_id' => ['table' => 'estudiantes', 'nullable' => false],
                'año_escolar_id' => ['table' => 'años_escolares', 'nullable' => false],
                'grado_id' => ['table' => 'grados', 'nullable' => false],
            ],
        ],
        'historial_indicadores' => [
            'fields' => ['historial_id', 'indicador_id', 'valoracion', 'fecha_registro'],
            'required' => ['valoracion'],
            'foreign_keys' => [
                'historial_id' => ['table' => 'historial_academico', 'nullable' => false],
                'indicador_id' => ['table' => 'areas_indicadores', 'nullable' => false],
            ],
        ],
        'asistencias' => [
            'fields' => ['inscripcion_id', 'fecha', 'estado', 'observaciones', 'registrado_por'],
            'required' => ['fecha', 'estado'],
            'foreign_keys' => [
                'inscripcion_id' => ['table' => 'inscripciones', 'nullable' => false],
                'registrado_por' => ['table' => 'usuarios', 'nullable' => true],
            ],
        ],
        'literales_finales' => [
            'fields' => [
                'inscripcion_id', 'año_escolar_id', 'literal_final', 'observaciones_finales',
                'evaluado_por', 'fecha_evaluacion',
            ],
            'required' => ['literal_final', 'fecha_evaluacion'],
            'foreign_keys' => [
                'inscripcion_id' => ['table' => 'inscripciones', 'nullable' => false],
                'año_escolar_id' => ['table' => 'años_escolares', 'nullable' => false],
                'evaluado_por' => ['table' => 'usuarios', 'nullable' => true],
            ],
        ],
        'literales_lapso' => [
            'fields' => [
                'inscripcion_id', 'lapso_id', 'literal', 'observaciones', 'evaluado_por',
                'fecha_evaluacion',
            ],
            'required' => ['literal', 'fecha_evaluacion'],
            'foreign_keys' => [
                'inscripcion_id' => ['table' => 'inscripciones', 'nullable' => false],
                'lapso_id' => ['table' => 'lapsos', 'nullable' => false],
                'evaluado_por' => ['table' => 'usuarios', 'nullable' => true],
            ],
        ],
        'proyectos_lapso' => [
            'fields' => [
                'lapso_id', 'seccion_id', 'nombre', 'tipo_proyecto', 'descripcion',
                'objetivo_general', 'descripcion_actividades', 'criterios_evaluacion',
                'fecha_entrega', 'fecha_realizacion', 'estado',
            ],
            'required' => ['nombre', 'fecha_realizacion'],
            'foreign_keys' => [
                'lapso_id' => ['table' => 'lapsos', 'nullable' => false],
                'seccion_id' => ['table' => 'secciones', 'nullable' => true],
            ],
        ],
    ];
}

function syncReceiverOrder(): array
{
    return [
        'areas_aprendizaje',
        'años_escolares',
        'grados',
        'profesores',
        'representantes',
        'areas_indicadores',
        'secciones',
        'lapsos',
        'estudiantes',
        'estudiantes_representantes',
        'inscripciones',
        'horarios_asignaciones',
        'indicadores_seccion',
        'historial_academico',
        'historial_indicadores',
        'asistencias',
        'literales_finales',
        'literales_lapso',
        'proyectos_lapso',
    ];
}

function syncPullTriggerStatements(PDO $pdo): array
{
    $schema = syncReceiverSchema();
    $statements = [];
    foreach (syncReceiverOrder() as $table) {
        $quotedTable = syncQuoteIdentifier($table);
        $tableLiteral = $pdo->quote($table);
        $triggerBase = 'trg_sync_pull_' . substr(hash('sha256', $table), 0, 20);
        $jsonArguments = [$pdo->quote('uuid'), 'NEW.`uuid`'];
        foreach ($schema[$table]['fields'] as $column) {
            if (isset($schema[$table]['foreign_keys'][$column])) {
                $foreignTable = syncQuoteIdentifier($schema[$table]['foreign_keys'][$column]['table']);
                $expression = '(SELECT `uuid` FROM ' . $foreignTable .
                    ' WHERE `id` = NEW.' . syncQuoteIdentifier($column) . ' LIMIT 1)';
                $key = 'uuid_' . $column;
            } else {
                $expression = 'NEW.' . syncQuoteIdentifier($column);
                $key = $column;
            }
            $jsonArguments[] = $pdo->quote($key);
            $jsonArguments[] = $expression;
        }

        $newPayload = 'JSON_OBJECT(' . implode(', ', $jsonArguments) . ')';
        foreach (['ai' => 'INSERT', 'au' => 'UPDATE'] as $suffix => $event) {
            $statements[] = 'CREATE TRIGGER ' .
                syncQuoteIdentifier($triggerBase . '_' . $suffix) .
                ' AFTER ' . $event . ' ON ' . $quotedTable . ' FOR EACH ROW ' .
                'INSERT INTO `sync_changes` ' .
                '(`tabla`, `uuid`, `operacion`, `id_remoto`, `payload_json`) VALUES (' .
                $tableLiteral . ', NEW.`uuid`, ' . $pdo->quote('upsert') . ', ' .
                'NEW.`id`, ' . $newPayload . ')';
        }

        $deletePayload = 'JSON_OBJECT(' . $pdo->quote('uuid') . ', OLD.`uuid`)';
        $statements[] = 'CREATE TRIGGER ' .
            syncQuoteIdentifier($triggerBase . '_ad') .
            ' AFTER DELETE ON ' . $quotedTable . ' FOR EACH ROW ' .
            'INSERT INTO `sync_changes` ' .
            '(`tabla`, `uuid`, `operacion`, `id_remoto`, `payload_json`) VALUES (' .
            $tableLiteral . ', OLD.`uuid`, ' . $pdo->quote('delete') . ', OLD.`id`, ' .
            $deletePayload . ')';
    }

    return $statements;
}

function syncInstallPullSchema(PDO $pdo): void
{
    if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql') {
        throw new RuntimeException('El registro de cambios pull requiere MySQL o MariaDB.');
    }

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS `sync_changes` (
            `cursor` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `tabla` VARCHAR(50) NOT NULL,
            `uuid` CHAR(36) NOT NULL,
            `operacion` ENUM("upsert", "delete") NOT NULL,
            `id_remoto` BIGINT UNSIGNED NOT NULL,
            `payload_json` LONGTEXT NOT NULL,
            `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
            PRIMARY KEY (`cursor`),
            KEY `idx_sync_changes_uuid` (`tabla`, `uuid`, `cursor`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );

    foreach (syncPullTriggerStatements($pdo) as $statement) {
        preg_match('/^CREATE TRIGGER `([^`]+)`/', $statement, $matches);
        if (!isset($matches[1])) {
            throw new RuntimeException('No se pudo determinar el nombre de un disparador pull.');
        }
        $pdo->exec('DROP TRIGGER IF EXISTS ' . syncQuoteIdentifier($matches[1]));
        $pdo->exec($statement);
    }
}

function syncReadPullChanges(PDO $pdo, string $cursor, int $limit = 50): array
{
    if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql') {
        throw new RuntimeException('La descarga de cambios requiere MySQL o MariaDB.');
    }
    if (!preg_match('/^\d{1,20}$/', $cursor) || $limit < 1 || $limit > 100) {
        throw new SyncPayloadException('El cursor de descarga no es válido.');
    }

    $exists = $pdo->query(
        "SELECT 1 FROM information_schema.tables
         WHERE table_schema = DATABASE() AND table_name = 'sync_changes' LIMIT 1"
    )->fetchColumn();
    if ($exists === false) {
        throw new SyncPullNotInstalledException('Debe instalarse el registro de cambios pull.');
    }
    $triggerCount = (int) $pdo->query(
        "SELECT COUNT(*) FROM information_schema.triggers
         WHERE trigger_schema = DATABASE() AND trigger_name LIKE 'trg_sync_pull_%'"
    )->fetchColumn();
    if ($triggerCount !== count(syncReceiverOrder()) * 3) {
        throw new SyncPullNotInstalledException('Faltan disparadores para registrar cambios pull.');
    }

    $statement = $pdo->prepare(
        'SELECT `cursor`, `tabla`, `uuid`, `operacion`, `id_remoto`, `payload_json`
         FROM `sync_changes` WHERE `cursor` > :cursor
         ORDER BY `cursor` LIMIT ' . ($limit + 1)
    );
    $statement->bindValue(':cursor', $cursor, PDO::PARAM_STR);
    $statement->execute();
    $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
    $hasMore = count($rows) > $limit;
    if ($hasMore) {
        array_pop($rows);
    }

    $changes = [];
    foreach ($rows as $row) {
        $payload = json_decode($row['payload_json'], true);
        if (!is_array($payload) || json_last_error() !== JSON_ERROR_NONE ||
            !isset($payload['uuid']) || !is_string($payload['uuid']) ||
            strtolower($payload['uuid']) !== strtolower($row['uuid'])) {
            throw new RuntimeException('El registro de cambios contiene un payload inválido.');
        }

        $changes[] = [
            'cursor' => (string) $row['cursor'],
            'tabla' => $row['tabla'],
            'uuid' => strtolower($row['uuid']),
            'operacion' => $row['operacion'],
            'id_remoto' => (int) $row['id_remoto'],
            'payload' => $payload,
        ];
    }

    return [
        'changes' => $changes,
        'next_cursor' => $changes === []
            ? $cursor
            : $changes[count($changes) - 1]['cursor'],
        'has_more' => $hasMore,
    ];
}

class SyncPullNotInstalledException extends RuntimeException
{
}

function syncIsList(array $value): bool
{
    return $value === [] || array_keys($value) === range(0, count($value) - 1);
}

function syncValidateRecords(array $records): array
{
    $schema = syncReceiverSchema();
    $order = syncReceiverOrder();

    if ($records !== [] && syncIsList($records)) {
        throw new SyncPayloadException('records debe ser un objeto con una lista por tabla.');
    }

    foreach ($records as $table => $rows) {
        if (!is_string($table) || !array_key_exists($table, $schema)) {
            throw new SyncPayloadException('La solicitud contiene una tabla no permitida.');
        }
        if (!is_array($rows) || !syncIsList($rows)) {
            throw new SyncPayloadException('Cada tabla debe contener una lista de registros.');
        }
        if (count($rows) > 50) {
            throw new SyncPayloadException('Cada lote admite como máximo 50 registros por tabla.');
        }
    }

    $validated = [];
    foreach ($order as $table) {
        if (!array_key_exists($table, $records)) {
            continue;
        }

        $tableSchema = $schema[$table];
        $seenUuids = [];
        foreach ($records[$table] as $row) {
            if (!is_array($row) || ($row !== [] && syncIsList($row))) {
                throw new SyncPayloadException('Cada registro debe ser un objeto JSON.');
            }
            if (!isset($row['uuid']) || !is_string($row['uuid']) ||
                !preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $row['uuid'])) {
                throw new SyncPayloadException('Cada registro debe incluir un UUID válido.');
            }

            $uuid = strtolower($row['uuid']);
            if (isset($seenUuids[$uuid])) {
                throw new SyncPayloadException('Un lote no puede repetir UUID dentro de la misma tabla.');
            }
            $seenUuids[$uuid] = true;

            $data = [];
            foreach ($row as $key => $value) {
                if ($key === 'uuid') {
                    continue;
                }

                if (isset($tableSchema['foreign_keys'][$key])) {
                    throw new SyncPayloadException('Las claves foráneas deben enviarse como UUID, no como ID local.');
                }

                if (strncmp($key, 'uuid_', 5) === 0) {
                    $column = substr($key, 5);
                    if (!isset($tableSchema['foreign_keys'][$column])) {
                        throw new SyncPayloadException('La solicitud contiene una referencia no permitida.');
                    }
                    if ($value !== null && (!is_string($value) ||
                        !preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $value))) {
                        throw new SyncPayloadException('Cada referencia debe contener un UUID válido o null.');
                    }
                    $data[$key] = $value === null ? null : strtolower($value);
                    continue;
                }

                if (!in_array($key, $tableSchema['fields'], true)) {
                    throw new SyncPayloadException('La solicitud contiene un campo no permitido.');
                }
                if (!is_scalar($value) && $value !== null) {
                    throw new SyncPayloadException('Los valores de los campos deben ser escalares o null.');
                }
                $data[$key] = $value;
            }

            $validated[$table][] = ['uuid' => $uuid, 'data' => $data];
        }
    }

    return $validated;
}

function syncValidateDeletions(array $deletions): array
{
    $schema = syncReceiverSchema();
    $order = array_reverse(syncReceiverOrder());
    if ($deletions !== [] && syncIsList($deletions)) {
        throw new SyncPayloadException('deletions debe ser un objeto con una lista de UUID por tabla.');
    }

    foreach ($deletions as $table => $uuids) {
        if (!is_string($table) || !isset($schema[$table])) {
            throw new SyncPayloadException('La solicitud contiene una tabla no permitida para borrar.');
        }
        if (!is_array($uuids) || !syncIsList($uuids) || count($uuids) > 50) {
            throw new SyncPayloadException('Cada lista de borrados admite como máximo 50 UUID.');
        }
    }

    $validated = [];
    foreach ($order as $table) {
        if (!array_key_exists($table, $deletions)) {
            continue;
        }

        $seenUuids = [];
        foreach ($deletions[$table] as $uuid) {
            if (!is_string($uuid) ||
                !preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $uuid)) {
                throw new SyncPayloadException('Cada borrado debe incluir un UUID válido.');
            }
            $uuid = strtolower($uuid);
            if (isset($seenUuids[$uuid])) {
                throw new SyncPayloadException('Un lote no puede repetir UUID dentro de la misma tabla.');
            }
            $seenUuids[$uuid] = true;
            $validated[$table][] = $uuid;
        }
    }

    return $validated;
}

function syncQuoteIdentifier(string $identifier): string
{
    return '`' . str_replace('`', '``', $identifier) . '`';
}

function syncAcquireReceiverLock(PDO $pdo, string $lockName): bool
{
    if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql') {
        return true;
    }

    $statement = $pdo->prepare('SELECT GET_LOCK(?, 10)');
    $statement->execute([$lockName]);
    return (int) $statement->fetchColumn() === 1;
}

function syncReleaseReceiverLock(PDO $pdo, string $lockName): void
{
    if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql') {
        return;
    }

    $statement = $pdo->prepare('SELECT RELEASE_LOCK(?)');
    $statement->execute([$lockName]);
    if ((int) $statement->fetchColumn() !== 1) {
        throw new SyncLockException('No se pudo liberar el bloqueo de sincronización.');
    }
}

function syncResolveForeignKeys(PDO $pdo, string $table, array $data, bool $isInsert): array
{
    $tableSchema = syncReceiverSchema()[$table];
    $resolved = [];

    foreach ($tableSchema['foreign_keys'] as $column => $foreignKey) {
        $uuidKey = 'uuid_' . $column;
        if (!array_key_exists($uuidKey, $data)) {
            if ($isInsert && !$foreignKey['nullable']) {
                throw new SyncPayloadException('Falta una referencia UUID obligatoria para el registro.');
            }
            continue;
        }

        $foreignUuid = $data[$uuidKey];
        if ($foreignUuid === null) {
            if (!$foreignKey['nullable']) {
                throw new SyncPayloadException('Una referencia UUID obligatoria no puede ser null.');
            }
            $resolved[$column] = null;
            continue;
        }

        $foreignTable = syncQuoteIdentifier($foreignKey['table']);
        $statement = $pdo->prepare("SELECT `id` FROM {$foreignTable} WHERE `uuid` = :uuid LIMIT 1");
        $statement->execute(['uuid' => $foreignUuid]);
        $remoteId = $statement->fetchColumn();
        if ($remoteId === false) {
            throw new SyncPayloadException('No se encontró en el servidor una entidad referenciada por UUID.');
        }
        $resolved[$column] = (int) $remoteId;
    }

    foreach ($data as $key => $value) {
        if (strncmp($key, 'uuid_', 5) !== 0) {
            $resolved[$key] = $value;
        }
    }

    if ($isInsert) {
        foreach ($tableSchema['required'] as $requiredField) {
            if (!array_key_exists($requiredField, $resolved) || $resolved[$requiredField] === null) {
                throw new SyncPayloadException('Falta un campo obligatorio para crear el registro.');
            }
        }
    }

    return $resolved;
}

function syncPersistMap(PDO $pdo, string $uuid, string $table, int $remoteId, string $receivedAt): void
{
    $statement = $pdo->prepare(
        'SELECT `id` FROM `sync_map` WHERE `uuid` = :uuid AND `tabla` = :tabla LIMIT 1'
    );
    $statement->execute(['uuid' => $uuid, 'tabla' => $table]);
    $mapId = $statement->fetchColumn();

    if ($mapId === false) {
        $statement = $pdo->prepare(
            'INSERT INTO `sync_map` (`uuid`, `tabla`, `id_remoto`, `last_received_at`)
             VALUES (:uuid, :tabla, :id_remoto, :received_at)'
        );
        $statement->bindValue(':uuid', $uuid);
        $statement->bindValue(':tabla', $table);
    } else {
        $statement = $pdo->prepare(
            'UPDATE `sync_map` SET `id_remoto` = :id_remoto, `last_received_at` = :received_at
             WHERE `id` = :id'
        );
        $statement->bindValue(':id', (int) $mapId, PDO::PARAM_INT);
    }

    $statement->bindValue(':id_remoto', $remoteId, PDO::PARAM_INT);
    $statement->bindValue(':received_at', $receivedAt);
    $statement->execute();
}

function syncProcessRecords(
    PDO $pdo,
    array $records,
    string $receivedAt,
    array $deletions = []
): array
{
    $validated = syncValidateRecords($records);
    $validatedDeletions = syncValidateDeletions($deletions);
    foreach ($validated as $table => $rows) {
        $deleteUuids = $validatedDeletions[$table] ?? [];
        foreach ($rows as $row) {
            if (in_array($row['uuid'], $deleteUuids, true)) {
                throw new SyncPayloadException('Un UUID no puede actualizarse y borrarse en el mismo lote.');
            }
        }
    }
    $results = [];

    if (!$pdo->inTransaction()) {
        $pdo->beginTransaction();
    }

    try {
        foreach ($validated as $table => $rows) {
            $quotedTable = syncQuoteIdentifier($table);
            foreach ($rows as $row) {
                $mapStatement = $pdo->prepare(
                    'SELECT `id_remoto`, `last_received_at` FROM `sync_map`
                     WHERE `uuid` = :uuid AND `tabla` = :tabla LIMIT 1'
                );
                $mapStatement->execute(['uuid' => $row['uuid'], 'tabla' => $table]);
                $map = $mapStatement->fetch(PDO::FETCH_ASSOC);
                if ($map && $map['last_received_at'] !== null &&
                    strcmp($map['last_received_at'], $receivedAt) > 0) {
                    $results[] = [
                        'tabla' => $table,
                        'uuid' => $row['uuid'],
                        'id_remoto' => (int) $map['id_remoto'],
                        'operacion' => 'ignored_stale',
                    ];
                    continue;
                }

                $statement = $pdo->prepare(
                    "SELECT `id` FROM {$quotedTable} WHERE `uuid` = :uuid LIMIT 1"
                );
                $statement->execute(['uuid' => $row['uuid']]);
                $remoteId = $statement->fetchColumn();
                $isInsert = $remoteId === false;
                $data = syncResolveForeignKeys($pdo, $table, $row['data'], $isInsert);

                if ($isInsert) {
                    $data = ['uuid' => $row['uuid']] + $data + ['sincronizado' => 1];
                    $columns = array_keys($data);
                    $quotedColumns = array_map('syncQuoteIdentifier', $columns);
                    $placeholders = array_fill(0, count($columns), '?');
                    $sql = sprintf(
                        'INSERT INTO %s (%s) VALUES (%s)',
                        $quotedTable,
                        implode(', ', $quotedColumns),
                        implode(', ', $placeholders)
                    );
                    $statement = $pdo->prepare($sql);
                    $position = 1;
                    foreach ($data as $value) {
                        $statement->bindValue($position++, $value);
                    }
                    $statement->execute();
                    $remoteId = (int) $pdo->lastInsertId();
                    $operation = 'inserted';
                } else {
                    $remoteId = (int) $remoteId;
                    $data['sincronizado'] = 1;
                    $assignments = [];
                    foreach (array_keys($data) as $column) {
                        $assignments[] = syncQuoteIdentifier($column) . ' = ?';
                    }
                    $sql = sprintf(
                        'UPDATE %s SET %s WHERE `uuid` = ?',
                        $quotedTable,
                        implode(', ', $assignments)
                    );
                    $statement = $pdo->prepare($sql);
                    $position = 1;
                    foreach ($data as $value) {
                        $statement->bindValue($position++, $value);
                    }
                    $statement->bindValue($position, $row['uuid']);
                    $statement->execute();
                    $operation = 'updated';
                }

                syncPersistMap($pdo, $row['uuid'], $table, $remoteId, $receivedAt);
                $results[] = [
                    'tabla' => $table,
                    'uuid' => $row['uuid'],
                    'id_remoto' => $remoteId,
                    'operacion' => $operation,
                ];
            }
        }

        foreach ($validatedDeletions as $table => $uuids) {
            $quotedTable = syncQuoteIdentifier($table);
            foreach ($uuids as $uuid) {
                $mapStatement = $pdo->prepare(
                    'SELECT `id_remoto`, `last_received_at` FROM `sync_map`
                     WHERE `uuid` = :uuid AND `tabla` = :tabla LIMIT 1'
                );
                $mapStatement->execute(['uuid' => $uuid, 'tabla' => $table]);
                $map = $mapStatement->fetch(PDO::FETCH_ASSOC);

                if ($map && $map['last_received_at'] !== null &&
                    strcmp($map['last_received_at'], $receivedAt) > 0) {
                    $results[] = [
                        'tabla' => $table,
                        'uuid' => $uuid,
                        'id_remoto' => (int) $map['id_remoto'],
                        'operacion' => 'ignored_stale',
                    ];
                    continue;
                }

                $statement = $pdo->prepare(
                    "SELECT `id` FROM {$quotedTable} WHERE `uuid` = :uuid LIMIT 1"
                );
                $statement->execute(['uuid' => $uuid]);
                $remoteId = $statement->fetchColumn();
                if ($remoteId === false && !$map) {
                    throw new SyncPayloadException('No se encontró el registro remoto que se solicitó borrar.');
                }
                if ($remoteId === false) {
                    $remoteId = (int) $map['id_remoto'];
                    $operation = 'already_deleted';
                } else {
                    $remoteId = (int) $remoteId;
                    $delete = $pdo->prepare("DELETE FROM {$quotedTable} WHERE `uuid` = :uuid");
                    $delete->execute(['uuid' => $uuid]);
                    $operation = $delete->rowCount() > 0 ? 'deleted' : 'already_deleted';
                }

                syncPersistMap($pdo, $uuid, $table, $remoteId, $receivedAt);
                $results[] = [
                    'tabla' => $table,
                    'uuid' => $uuid,
                    'id_remoto' => $remoteId,
                    'operacion' => $operation,
                ];
            }
        }

        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }

    return $results;
}
