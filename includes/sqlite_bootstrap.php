<?php

function sqliteBootstrapQuoteIdentifier(string $identifier): string
{
    return '"' . str_replace('"', '""', $identifier) . '"';
}

function sqliteBootstrapQuoteMysqlIdentifier(string $identifier): string
{
    return '`' . str_replace('`', '``', $identifier) . '`';
}

function sqliteRegisterFunctions(PDO $pdo): void
{
    $pdo->sqliteCreateFunction('NOW', function () {
        return date('Y-m-d H:i:s');
    }, 0);
    $pdo->sqliteCreateFunction('CURDATE', function () {
        return date('Y-m-d');
    }, 0);
    $pdo->sqliteCreateFunction('UUID', function () {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);
        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20, 12)
        );
    }, 0);
    $pdo->sqliteCreateFunction('CONCAT', function (...$values) {
        if (in_array(null, $values, true)) {
            return null;
        }
        return implode('', array_map('strval', $values));
    }, -1);
    $pdo->sqliteCreateFunction('YEAR', function ($value) {
        $timestamp = is_string($value) ? strtotime($value) : false;
        return $timestamp === false ? null : (int) date('Y', $timestamp);
    }, 1);
    $pdo->sqliteCreateFunction('MONTH', function ($value) {
        $timestamp = is_string($value) ? strtotime($value) : false;
        return $timestamp === false ? null : (int) date('n', $timestamp);
    }, 1);
    $pdo->sqliteCreateFunction('DAY', function ($value) {
        $timestamp = is_string($value) ? strtotime($value) : false;
        return $timestamp === false ? null : (int) date('j', $timestamp);
    }, 1);
    $pdo->sqliteCreateFunction('DATE_FORMAT', function ($value, $format) {
        $timestamp = is_string($value) ? strtotime($value) : false;
        if ($timestamp === false || !is_string($format)) {
            return null;
        }

        return date(strtr($format, [
            '%Y' => 'Y',
            '%y' => 'y',
            '%m' => 'm',
            '%c' => 'n',
            '%d' => 'd',
            '%e' => 'j',
            '%H' => 'H',
            '%h' => 'h',
            '%i' => 'i',
            '%s' => 's',
        ]), $timestamp);
    }, 2);
}

function sqliteBootstrapType(string $mysqlType): string
{
    $type = strtolower($mysqlType);
    if (preg_match('/\\b(int|integer|tinyint|smallint|mediumint|bigint|bit)\\b/', $type)) {
        return 'INTEGER';
    }
    if (preg_match('/\\b(float|double|real)\\b/', $type)) {
        return 'REAL';
    }
    if (preg_match('/\\b(decimal|numeric)\\b/', $type)) {
        return 'NUMERIC';
    }
    if (preg_match('/\\b(blob|binary|varbinary)\\b/', $type)) {
        return 'BLOB';
    }

    return 'TEXT';
}

function sqliteBootstrapDefault(PDO $target, array $column): ?string
{
    if ($column['Default'] === null) {
        return null;
    }

    $default = (string) $column['Default'];
    if (preg_match('/^CURRENT_TIMESTAMP(?:\\(\\d*\\))?$/i', $default)) {
        return 'CURRENT_TIMESTAMP';
    }
    if (is_numeric($default)) {
        return $default;
    }

    return $target->quote($default);
}

function sqliteBootstrapForeignKeys(PDO $source): array
{
    $statement = $source->query(
        'SELECT TABLE_NAME, COLUMN_NAME, CONSTRAINT_NAME, REFERENCED_TABLE_NAME,
                REFERENCED_COLUMN_NAME, ORDINAL_POSITION
         FROM information_schema.KEY_COLUMN_USAGE
         WHERE TABLE_SCHEMA = DATABASE() AND REFERENCED_TABLE_NAME IS NOT NULL
         ORDER BY TABLE_NAME, CONSTRAINT_NAME, ORDINAL_POSITION'
    );
    $foreignKeys = [];
    foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $key = $row['TABLE_NAME'] . ':' . $row['CONSTRAINT_NAME'];
        if (!isset($foreignKeys[$key])) {
            $foreignKeys[$key] = [
                'table' => $row['TABLE_NAME'],
                'name' => $row['CONSTRAINT_NAME'],
                'columns' => [],
                'referenced_table' => $row['REFERENCED_TABLE_NAME'],
                'referenced_columns' => [],
            ];
        }
        $foreignKeys[$key]['columns'][] = $row['COLUMN_NAME'];
        $foreignKeys[$key]['referenced_columns'][] = $row['REFERENCED_COLUMN_NAME'];
    }

    $rulesStatement = $source->query(
        'SELECT TABLE_NAME, CONSTRAINT_NAME, UPDATE_RULE, DELETE_RULE
         FROM information_schema.REFERENTIAL_CONSTRAINTS
         WHERE CONSTRAINT_SCHEMA = DATABASE()'
    );
    $rules = [];
    foreach ($rulesStatement->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $rules[$row['TABLE_NAME'] . ':' . $row['CONSTRAINT_NAME']] = [
            'update' => $row['UPDATE_RULE'],
            'delete' => $row['DELETE_RULE'],
        ];
    }

    foreach ($foreignKeys as $key => &$foreignKey) {
        $foreignKey['rules'] = $rules[$key] ?? ['update' => 'RESTRICT', 'delete' => 'RESTRICT'];
    }
    unset($foreignKey);

    return $foreignKeys;
}

function sqliteBootstrapCreateTable(
    PDO $source,
    PDO $target,
    string $table,
    array $columns,
    array $indexes,
    array $foreignKeys
): void {
    $primaryColumns = [];
    foreach ($indexes as $index) {
        if ($index['Key_name'] === 'PRIMARY') {
            $primaryColumns[(int) $index['Seq_in_index']] = $index['Column_name'];
        }
    }
    ksort($primaryColumns);
    $primaryColumns = array_values($primaryColumns);

    $autoIncrementColumns = [];
    $definitions = [];
    foreach ($columns as $column) {
        if (stripos($column['Extra'], 'GENERATED') !== false) {
            throw new RuntimeException(
                'No se puede exportar la columna generada ' . $table . '.' . $column['Field'] . '.'
            );
        }

        $name = $column['Field'];
        $extra = strtolower($column['Extra']);
        $isAutoIncrement = strpos($extra, 'auto_increment') !== false;
        if ($isAutoIncrement) {
            $autoIncrementColumns[] = $name;
        }

        $definition = sqliteBootstrapQuoteIdentifier($name) . ' ' . sqliteBootstrapType($column['Type']);
        if ($column['Null'] === 'NO') {
            $definition .= ' NOT NULL';
        }

        $collation = $column['Collation'] ?? null;
        if (is_string($collation) && preg_match('/_ci$/i', $collation)) {
            $definition .= ' COLLATE NOCASE';
        }

        $default = sqliteBootstrapDefault($target, $column);
        if ($default === null && $name === 'uuid' && !$isAutoIncrement) {
            $default = '(UUID())';
        }
        if ($default !== null && !$isAutoIncrement) {
            $definition .= ' DEFAULT ' . $default;
        }

        if ($isAutoIncrement && count($primaryColumns) === 1 && $primaryColumns[0] === $name) {
            if (sqliteBootstrapType($column['Type']) !== 'INTEGER') {
                throw new RuntimeException('SQLite requiere una clave autoincremental de tipo entero.');
            }
            $definition .= ' PRIMARY KEY AUTOINCREMENT';
        }

        $definitions[] = $definition;
    }

    if (count($autoIncrementColumns) > 1 ||
        ($autoIncrementColumns !== [] &&
            (count($primaryColumns) !== 1 || $primaryColumns[0] !== $autoIncrementColumns[0]))) {
        throw new RuntimeException('La tabla ' . $table . ' tiene una clave AUTO_INCREMENT no compatible.');
    }

    if ($primaryColumns !== [] && $autoIncrementColumns === []) {
        $quotedPrimaryColumns = array_map('sqliteBootstrapQuoteIdentifier', $primaryColumns);
        $definitions[] = 'PRIMARY KEY (' . implode(', ', $quotedPrimaryColumns) . ')';
    }

    foreach ($foreignKeys as $foreignKey) {
        $columnsSql = array_map('sqliteBootstrapQuoteIdentifier', $foreignKey['columns']);
        $referencedColumnsSql = array_map(
            'sqliteBootstrapQuoteIdentifier',
            $foreignKey['referenced_columns']
        );
        $definition = 'FOREIGN KEY (' . implode(', ', $columnsSql) . ') REFERENCES ' .
            sqliteBootstrapQuoteIdentifier($foreignKey['referenced_table']) .
            ' (' . implode(', ', $referencedColumnsSql) . ')';

        foreach (['delete' => 'ON DELETE', 'update' => 'ON UPDATE'] as $ruleKey => $sqlKey) {
            $rule = strtoupper((string) ($foreignKey['rules'][$ruleKey] ?? 'RESTRICT'));
            if (!in_array($rule, ['CASCADE', 'RESTRICT', 'SET NULL', 'NO ACTION', 'SET DEFAULT'], true)) {
                throw new RuntimeException('Regla de clave foránea MySQL no compatible.');
            }
            $definition .= ' ' . $sqlKey . ' ' . $rule;
        }

        $definitions[] = $definition;
    }

    $sql = 'CREATE TABLE ' . sqliteBootstrapQuoteIdentifier($table) .
        ' (' . implode(', ', $definitions) . ')';
    $target->exec($sql);
}

function sqliteBootstrapCreateIndexes(PDO $target, string $table, array $indexes): void
{
    $grouped = [];
    foreach ($indexes as $index) {
        if ($index['Key_name'] === 'PRIMARY') {
            continue;
        }
        if ($index['Column_name'] === null ||
            strtoupper((string) $index['Index_type']) !== 'BTREE' ||
            $index['Sub_part'] !== null) {
            throw new RuntimeException('La tabla ' . $table . ' tiene un índice no compatible con SQLite.');
        }

        $name = $index['Key_name'];
        if (!isset($grouped[$name])) {
            $grouped[$name] = [
                'unique' => (int) $index['Non_unique'] === 0,
                'columns' => [],
            ];
        }
        $grouped[$name]['columns'][(int) $index['Seq_in_index']] = $index['Column_name'];
        if ($index['Collation'] === 'D') {
            $grouped[$name]['descending'][(int) $index['Seq_in_index']] = true;
        }
    }

    foreach ($grouped as $name => $index) {
        ksort($index['columns']);
        $columns = [];
        foreach ($index['columns'] as $position => $column) {
            $columns[] = sqliteBootstrapQuoteIdentifier($column) .
                (isset($index['descending'][$position]) ? ' DESC' : '');
        }
        $indexName = 'idx_' . substr(hash('sha256', $table . ':' . $name), 0, 24);
        $unique = $index['unique'] ? 'UNIQUE ' : '';
        $target->exec(
            'CREATE ' . $unique . 'INDEX ' . sqliteBootstrapQuoteIdentifier($indexName) .
            ' ON ' . sqliteBootstrapQuoteIdentifier($table) .
            ' (' . implode(', ', $columns) . ')'
        );
    }
}

function sqliteBootstrapCreateTriggers(PDO $target, string $table, array $columns, array $indexes): void
{
    $primaryColumns = [];
    foreach ($indexes as $index) {
        if ($index['Key_name'] === 'PRIMARY') {
            $primaryColumns[(int) $index['Seq_in_index']] = $index['Column_name'];
        }
    }
    ksort($primaryColumns);
    $primaryColumns = array_values($primaryColumns);

    foreach ($columns as $column) {
        if (stripos((string) $column['Extra'], 'on update current_timestamp') === false) {
            continue;
        }
        if ($primaryColumns === []) {
            throw new RuntimeException(
                'No se puede migrar el auto-actualizado de ' . $table . ' sin clave primaria.'
            );
        }

        $conditions = [];
        foreach ($primaryColumns as $primaryColumn) {
            $quotedPrimary = sqliteBootstrapQuoteIdentifier($primaryColumn);
            $conditions[] = $quotedPrimary . ' IS NEW.' . $quotedPrimary;
        }
        $quotedColumn = sqliteBootstrapQuoteIdentifier($column['Field']);
        $triggerName = 'trg_' . substr(hash('sha256', $table . ':' . $column['Field']), 0, 24);
        $target->exec(
            'CREATE TRIGGER ' . sqliteBootstrapQuoteIdentifier($triggerName) .
            ' AFTER UPDATE ON ' . sqliteBootstrapQuoteIdentifier($table) .
            ' FOR EACH ROW WHEN NEW.' . $quotedColumn . ' IS OLD.' . $quotedColumn .
            ' BEGIN UPDATE ' . sqliteBootstrapQuoteIdentifier($table) .
            ' SET ' . $quotedColumn . " = strftime('%Y-%m-%d %H:%M:%f', 'now')" .
            ' WHERE ' . implode(' AND ', $conditions) . '; END'
        );
    }
}

function sqliteBootstrapExpectedUuidTriggers(): array
{
    return [
        'trg_sync_uuid_areas_aprendizaje_bi' => 'areas_aprendizaje',
        'trg_sync_uuid_anios_escolares_bi' => 'años_escolares',
        'trg_sync_uuid_grados_bi' => 'grados',
        'trg_sync_uuid_profesores_bi' => 'profesores',
        'trg_sync_uuid_representantes_bi' => 'representantes',
        'trg_sync_uuid_areas_indicadores_bi' => 'areas_indicadores',
        'trg_sync_uuid_secciones_bi' => 'secciones',
        'trg_sync_uuid_lapsos_bi' => 'lapsos',
        'trg_sync_uuid_estudiantes_bi' => 'estudiantes',
        'trg_sync_uuid_estudiantes_representantes_bi' => 'estudiantes_representantes',
        'trg_sync_uuid_inscripciones_bi' => 'inscripciones',
        'trg_sync_uuid_horarios_asignaciones_bi' => 'horarios_asignaciones',
        'trg_sync_uuid_indicadores_seccion_bi' => 'indicadores_seccion',
        'trg_sync_uuid_historial_academico_bi' => 'historial_academico',
        'trg_sync_uuid_historial_indicadores_bi' => 'historial_indicadores',
        'trg_sync_uuid_asistencias_bi' => 'asistencias',
        'trg_sync_uuid_literales_finales_bi' => 'literales_finales',
        'trg_sync_uuid_literales_lapso_bi' => 'literales_lapso',
        'trg_sync_uuid_proyectos_lapso_bi' => 'proyectos_lapso',
        'trg_sync_uuid_usuarios_bi' => 'usuarios',
    ];
}

function sqliteBootstrapNormalizeTriggerStatement(string $statement): string
{
    $statement = strtoupper(str_replace('`', '', $statement));
    $statement = preg_replace('/\s+/', '', $statement);
    return rtrim((string) $statement, ';');
}

function sqliteBootstrapExpectedPullTriggers(PDO $source): array
{
    if (!function_exists('syncPullTriggerStatements')) {
        throw new RuntimeException('No se pudo validar la configuración de los disparadores pull.');
    }

    $expected = [];
    foreach (syncPullTriggerStatements($source) as $createStatement) {
        if (!preg_match(
            '/^CREATE TRIGGER `([^`]+)` AFTER (INSERT|UPDATE|DELETE) ON `([^`]+)` FOR EACH ROW (.+)$/s',
            $createStatement,
            $matches
        )) {
            throw new RuntimeException('No se pudo validar un disparador pull generado.');
        }

        $expected[$matches[1]] = [
            'table' => str_replace('``', '`', $matches[3]),
            'timing' => 'AFTER',
            'event' => $matches[2],
            'statement' => sqliteBootstrapNormalizeTriggerStatement($matches[4]),
        ];
    }

    return $expected;
}

function sqliteBootstrapValidateTriggers(array $triggers, ?PDO $source = null): void
{
    if ($triggers === []) {
        return;
    }

    $expectedUuidTriggers = sqliteBootstrapExpectedUuidTriggers();
    $expectedPullTriggers = $source instanceof PDO
        ? sqliteBootstrapExpectedPullTriggers($source)
        : [];
    $uuidTriggerCount = count($expectedUuidTriggers);
    $hasPullTriggers = count($triggers) === $uuidTriggerCount + count($expectedPullTriggers);
    if (count($triggers) !== $uuidTriggerCount && !$hasPullTriggers) {
        throw new RuntimeException(
            'El esquema tiene triggers MySQL inesperados o una migración UUID incompleta.'
        );
    }

    $expectedStatement = "SETNEW.UUID=IF(NEW.UUIDISNULLORNEW.UUID='',UUID(),NEW.UUID)";
    $found = [];
    foreach ($triggers as $trigger) {
        $name = (string) ($trigger['Trigger'] ?? '');
        $table = (string) ($trigger['Table'] ?? '');
        $timing = strtoupper((string) ($trigger['Timing'] ?? ''));
        $event = strtoupper((string) ($trigger['Event'] ?? ''));
        $statement = sqliteBootstrapNormalizeTriggerStatement(
            (string) ($trigger['Statement'] ?? '')
        );

        if (isset($expectedUuidTriggers[$name])) {
            $isExpectedUuidTrigger = $expectedUuidTriggers[$name] === $table &&
                $timing === 'BEFORE' && $event === 'INSERT' &&
                $statement === $expectedStatement;
            $isExpectedPullTrigger = false;
        } elseif ($hasPullTriggers && isset($expectedPullTriggers[$name])) {
            $expectedPullTrigger = $expectedPullTriggers[$name];
            $isExpectedUuidTrigger = false;
            $isExpectedPullTrigger = $expectedPullTrigger['table'] === $table &&
                $expectedPullTrigger['timing'] === $timing &&
                $expectedPullTrigger['event'] === $event &&
                $expectedPullTrigger['statement'] === $statement;
        } else {
            $isExpectedUuidTrigger = false;
            $isExpectedPullTrigger = false;
        }

        if ((!$isExpectedUuidTrigger && !$isExpectedPullTrigger) || isset($found[$name])) {
            throw new RuntimeException(
                'El esquema tiene triggers MySQL inesperados o una migración UUID incompleta.'
            );
        }
        $found[$name] = true;
    }

    $expectedTriggerCount = $uuidTriggerCount +
        ($hasPullTriggers ? count($expectedPullTriggers) : 0);
    if (count($found) !== $expectedTriggerCount) {
        throw new RuntimeException(
            'El esquema tiene triggers MySQL inesperados o una migración UUID incompleta.'
        );
    }
}

function sqliteBootstrapExport(PDO $source, PDO $target): array
{
    if ($source->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql' ||
        $target->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'sqlite') {
        throw new RuntimeException('La exportación requiere MySQL como origen y SQLite como destino.');
    }

    $ownsSourceTransaction = !$source->inTransaction();
    if ($ownsSourceTransaction) {
        $source->beginTransaction();
    }

    try {
    $triggersStatement = $source->query('SHOW TRIGGERS');
    sqliteBootstrapValidateTriggers($triggersStatement->fetchAll(PDO::FETCH_ASSOC), $source);

    $tablesStatement = $source->query('SHOW FULL TABLES');
    $tables = [];
    $views = [];
    foreach ($tablesStatement->fetchAll(PDO::FETCH_NUM) as $row) {
        if (strtoupper((string) ($row[1] ?? '')) === 'BASE TABLE') {
            $tables[] = (string) $row[0];
        } else {
            $views[] = (string) $row[0];
        }
    }

    if ($tables === []) {
        throw new RuntimeException('La base MySQL no contiene tablas para exportar.');
    }
    if ($views !== []) {
        throw new RuntimeException('El esquema contiene vistas; deben migrarse explícitamente antes de exportar.');
    }

    $foreignKeys = sqliteBootstrapForeignKeys($source);
    $schema = [];
    foreach ($tables as $table) {
        $quotedTable = sqliteBootstrapQuoteMysqlIdentifier($table);
        $columnsStatement = $source->query('SHOW FULL COLUMNS FROM ' . $quotedTable);
        $indexesStatement = $source->query('SHOW INDEX FROM ' . $quotedTable);
        $tableForeignKeys = [];
        foreach ($foreignKeys as $foreignKey) {
            if ($foreignKey['table'] === $table) {
                $tableForeignKeys[] = $foreignKey;
            }
        }
        $schema[$table] = [
            'columns' => $columnsStatement->fetchAll(PDO::FETCH_ASSOC),
            'indexes' => $indexesStatement->fetchAll(PDO::FETCH_ASSOC),
            'foreign_keys' => $tableForeignKeys,
        ];
    }

    $target->exec('PRAGMA foreign_keys = OFF');
    $target->beginTransaction();
    $counts = [];
    try {
        foreach ($schema as $table => $definition) {
            sqliteBootstrapCreateTable(
                $source,
                $target,
                $table,
                $definition['columns'],
                $definition['indexes'],
                $definition['foreign_keys']
            );
        }
        foreach ($schema as $table => $definition) {
            sqliteBootstrapCreateIndexes($target, $table, $definition['indexes']);
            sqliteBootstrapCreateTriggers(
                $target,
                $table,
                $definition['columns'],
                $definition['indexes']
            );

            $columns = array_column($definition['columns'], 'Field');
            $quotedColumns = array_map('sqliteBootstrapQuoteIdentifier', $columns);
            $placeholders = array_fill(0, count($columns), '?');
            $insert = $target->prepare(
                'INSERT INTO ' . sqliteBootstrapQuoteIdentifier($table) .
                ' (' . implode(', ', $quotedColumns) . ') VALUES (' .
                implode(', ', $placeholders) . ')'
            );

            $sourceRows = $source->query(
                'SELECT * FROM ' . sqliteBootstrapQuoteMysqlIdentifier($table)
            );
            $count = 0;
            while ($row = $sourceRows->fetch(PDO::FETCH_NUM)) {
                foreach ($row as $position => $value) {
                    if (is_resource($value)) {
                        $insert->bindValue($position + 1, stream_get_contents($value), PDO::PARAM_LOB);
                    } elseif ($value === null) {
                        $insert->bindValue($position + 1, null, PDO::PARAM_NULL);
                    } else {
                        $insert->bindValue($position + 1, $value);
                    }
                }
                $insert->execute();
                $count++;
            }
            $counts[$table] = $count;
        }

        $target->commit();
    } catch (Throwable $exception) {
        if ($target->inTransaction()) {
            $target->rollBack();
        }
        throw $exception;
    }

    $target->exec('PRAGMA foreign_keys = ON');
    $violations = $target->query('PRAGMA foreign_key_check')->fetchAll(PDO::FETCH_ASSOC);
    if ($violations !== []) {
        throw new RuntimeException('La exportación detectó referencias inválidas en los datos existentes.');
    }

    if ($ownsSourceTransaction) {
        $source->commit();
    }

    return $counts;
    } catch (Throwable $exception) {
        if ($ownsSourceTransaction && $source->inTransaction()) {
            $source->rollBack();
        }
        throw $exception;
    }
}
