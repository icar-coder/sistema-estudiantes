<?php

require_once __DIR__ . '/sync_receiver.php';

function offlineSyncDatabasePath(): string
{
    $configuredPath = getenv('OFFLINE_DB_PATH');
    if ($configuredPath !== false && $configuredPath !== '') {
        return $configuredPath;
    }

    $localConfigPath = __DIR__ . '/config.local.php';
    $localConfig = is_file($localConfigPath) ? require $localConfigPath : [];
    if (!is_array($localConfig)) {
        throw new RuntimeException('includes/config.local.php debe devolver un arreglo.');
    }

    $path = $localConfig['offline_db_path'] ?? null;
    return is_string($path) && $path !== ''
        ? $path
        : dirname(__DIR__) . '/database/datos_locales.sqlite';
}

function offlineSyncConnect(): PDO
{
    if (!extension_loaded('pdo_sqlite')) {
        throw new RuntimeException('La extensión PDO SQLite no está habilitada.');
    }

    $databasePath = offlineSyncDatabasePath();
    $directory = dirname($databasePath);
    if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
        throw new RuntimeException('No se pudo crear el directorio de la base de datos offline.');
    }

    $pdo = new PDO('sqlite:' . $databasePath, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    $pdo->exec('PRAGMA foreign_keys = ON');
    $pdo->exec('PRAGMA busy_timeout = 5000');
    offlineSyncInitialize($pdo);

    return $pdo;
}

function offlineSyncIsEnabled(?array $localConfig = null): bool
{
    if ($localConfig === null) {
        $localConfigPath = __DIR__ . '/config.local.php';
        $localConfig = is_file($localConfigPath) ? require $localConfigPath : [];
        if (!is_array($localConfig)) {
            throw new RuntimeException('includes/config.local.php debe devolver un arreglo.');
        }
    }

    $enabled = $localConfig['offline_sync_enabled'] ?? true;
    if (!is_bool($enabled)) {
        throw new RuntimeException('offline_sync_enabled debe ser un valor booleano.');
    }

    return $enabled;
}

function offlineSyncInitialize(PDO $pdo): void
{
    if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'sqlite') {
        throw new RuntimeException('La cola offline requiere una conexión PDO SQLite.');
    }

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS offline_sync_outbox (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            tabla TEXT NOT NULL,
            uuid TEXT NOT NULL,
            payload_json TEXT NOT NULL,
            operacion TEXT NOT NULL DEFAULT 'upsert'
                CHECK (operacion IN ('upsert', 'delete')),
            estado TEXT NOT NULL DEFAULT 'pending'
                CHECK (estado IN ('pending', 'synced', 'conflict', 'blocked')),
            attempts INTEGER NOT NULL DEFAULT 0,
            last_error TEXT DEFAULT NULL,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE (tabla, uuid)
        )"
    );
    $pdo->exec(
        'CREATE INDEX IF NOT EXISTS idx_offline_sync_pending
         ON offline_sync_outbox (estado, tabla, id)'
    );
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS sync_map (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            uuid TEXT NOT NULL,
            tabla TEXT NOT NULL,
            id_remoto INTEGER NOT NULL,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE (uuid, tabla)
        )'
    );
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS offline_sync_metadata (
            `key` TEXT PRIMARY KEY,
            `value` TEXT NOT NULL
        )'
    );
    $outboxColumns = array_column(
        $pdo->query('PRAGMA table_info(`offline_sync_outbox`)')->fetchAll(PDO::FETCH_ASSOC),
        'name'
    );
    if (!in_array('operacion', $outboxColumns, true)) {
        $pdo->exec(
            "ALTER TABLE `offline_sync_outbox`
             ADD COLUMN `operacion` TEXT NOT NULL DEFAULT 'upsert'"
        );
    }
}

function offlineSyncPrepareLocalOnlyDatabase(PDO $pdo): void
{
    if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'sqlite') {
        throw new RuntimeException('La preparación local requiere SQLite.');
    }

    $schema = syncReceiverSchema();
    foreach (syncReceiverOrder() as $table) {
        $quotedTable = syncQuoteIdentifier($table);
        $tableExists = $pdo->prepare(
            "SELECT 1 FROM `sqlite_master` WHERE `type` = 'table' AND `name` = ? LIMIT 1"
        );
        $tableExists->execute([$table]);
        if ($tableExists->fetchColumn() === false) {
            throw new RuntimeException('Falta la tabla SQLite requerida: ' . $table . '.');
        }

        $columns = array_column(
            $pdo->query('PRAGMA table_info(' . $quotedTable . ')')->fetchAll(PDO::FETCH_ASSOC),
            'name'
        );
        $missingFields = array_values(array_diff(
            array_merge(['id'], $schema[$table]['fields']),
            $columns
        ));
        if ($missingFields !== []) {
            throw new RuntimeException(
                'Faltan columnas requeridas en la tabla SQLite ' . $table . ': ' .
                implode(', ', $missingFields) . '.'
            );
        }

        if (!in_array('uuid', $columns, true)) {
            $pdo->exec('ALTER TABLE ' . $quotedTable . ' ADD COLUMN `uuid` TEXT');
        }
        if (!in_array('sincronizado', $columns, true)) {
            $pdo->exec(
                'ALTER TABLE ' . $quotedTable .
                ' ADD COLUMN `sincronizado` INTEGER NOT NULL DEFAULT 0'
            );
        }

        $pdo->exec(
            'UPDATE ' . $quotedTable . ' SET `uuid` = UUID() ' .
            'WHERE `uuid` IS NULL OR `uuid` = ""'
        );
        $duplicateUuid = $pdo->query(
            'SELECT 1 FROM ' . $quotedTable .
            ' GROUP BY `uuid` HAVING COUNT(*) > 1 LIMIT 1'
        )->fetchColumn();
        if ($duplicateUuid !== false) {
            throw new RuntimeException('Hay UUID duplicados en la tabla SQLite ' . $table . '.');
        }

        $indexName = 'idx_local_uuid_' . substr(hash('sha256', $table), 0, 20);
        $pdo->exec(
            'CREATE UNIQUE INDEX IF NOT EXISTS ' . syncQuoteIdentifier($indexName) .
            ' ON ' . $quotedTable . ' (`uuid`)'
        );
    }

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS `offline_sync_metadata` (
            `key` TEXT PRIMARY KEY,
            `value` TEXT NOT NULL
        )'
    );
    $statement = $pdo->prepare(
        'INSERT OR REPLACE INTO `offline_sync_metadata` (`key`, `value`)
         VALUES ("database_origin", ?)'
    );
    $statement->execute(['local_only']);
}

function offlineSyncSeedSyncMap(PDO $pdo): void
{
    $schema = syncReceiverSchema();
    foreach (syncReceiverOrder() as $table) {
        $quotedTable = syncQuoteIdentifier($table);
        $columns = array_column(
            $pdo->query('PRAGMA table_info(' . $quotedTable . ')')->fetchAll(PDO::FETCH_ASSOC),
            'name'
        );
        if (!in_array('id', $columns, true) || !in_array('uuid', $columns, true) ||
            !isset($schema[$table])) {
            throw new RuntimeException('No se pudo inicializar el mapa offline para ' . $table . '.');
        }

        $statement = $pdo->prepare(
            'INSERT OR IGNORE INTO `sync_map` (`uuid`, `tabla`, `id_remoto`)
             SELECT `uuid`, ?, `id` FROM ' . $quotedTable . ' WHERE `uuid` IS NOT NULL'
        );
        $statement->execute([$table]);
    }
}

function offlineSyncInstallTriggers(PDO $pdo, ?array $tables = null): void
{
    if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'sqlite') {
        throw new RuntimeException('Los disparadores de la cola requieren SQLite.');
    }

    $installAllTables = $tables === null;
    $schema = syncReceiverSchema();
    if ($installAllTables) {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS `offline_sync_metadata` (
                `key` TEXT PRIMARY KEY,
                `value` TEXT NOT NULL
            )'
        );
        $version = $pdo->query(
            'SELECT `value` FROM `offline_sync_metadata` WHERE `key` = "trigger_version"'
        )->fetchColumn();
        if ($version === '3') {
            return;
        }
        offlineSyncSeedSyncMap($pdo);
    }

    $jsonTest = $pdo->query("SELECT json_object('test', 'ok')")->fetchColumn();
    if ($jsonTest !== '{"test":"ok"}') {
        throw new RuntimeException('La extensión JSON de SQLite no está disponible.');
    }

    $tables = $tables ?? array_keys($schema);
    foreach ($tables as $table) {
        if (!isset($schema[$table])) {
            throw new InvalidArgumentException('Tabla no permitida para sincronización offline.');
        }

        $tableExists = $pdo->prepare(
            "SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = ? LIMIT 1"
        );
        $tableExists->execute([$table]);
        if ($tableExists->fetchColumn() === false) {
            throw new RuntimeException('Falta la tabla SQLite requerida para sincronizar: ' . $table);
        }

        $tableSchema = $schema[$table];
        $columnStatement = $pdo->query('PRAGMA table_info(' . syncQuoteIdentifier($table) . ')');
        $existingColumns = [];
        foreach ($columnStatement->fetchAll(PDO::FETCH_ASSOC) as $column) {
            $existingColumns[$column['name']] = true;
        }

        $requiredColumns = array_merge(['id', 'uuid', 'sincronizado'], $tableSchema['fields']);
        foreach ($tableSchema['foreign_keys'] as $column => $foreignKey) {
            $foreignTableStatement = $pdo->prepare(
                "SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = ? LIMIT 1"
            );
            $foreignTableStatement->execute([$foreignKey['table']]);
            if ($foreignTableStatement->fetchColumn() === false) {
                throw new RuntimeException('Falta la tabla de referencia ' . $foreignKey['table'] . '.');
            }

            $foreignColumnsStatement = $pdo->query(
                'PRAGMA table_info(' . syncQuoteIdentifier($foreignKey['table']) . ')'
            );
            $foreignColumns = array_column($foreignColumnsStatement->fetchAll(PDO::FETCH_ASSOC), 'name');
            if (!in_array('id', $foreignColumns, true) || !in_array('uuid', $foreignColumns, true)) {
                throw new RuntimeException(
                    'La tabla de referencia ' . $foreignKey['table'] . ' debe incluir id y uuid.'
                );
            }
        }
        foreach ($requiredColumns as $column) {
            if (!isset($existingColumns[$column])) {
                throw new RuntimeException('Falta la columna SQLite ' . $table . '.' . $column . '.');
            }
        }

        $jsonArguments = ["'uuid'", 'NEW.' . syncQuoteIdentifier('uuid')];
        foreach ($tableSchema['fields'] as $column) {
            if (isset($tableSchema['foreign_keys'][$column])) {
                $foreignKey = $tableSchema['foreign_keys'][$column];
                $jsonKey = 'uuid_' . $column;
                $foreignTable = syncQuoteIdentifier($foreignKey['table']);
                $foreignId = syncQuoteIdentifier('id');
                $foreignUuid = syncQuoteIdentifier('uuid');
                $localColumn = syncQuoteIdentifier($column);
                $expression = '(SELECT ' . $foreignUuid . ' FROM ' . $foreignTable .
                    ' WHERE ' . $foreignId . ' = NEW.' . $localColumn . ' LIMIT 1)';
            } else {
                $jsonKey = $column;
                $expression = 'NEW.' . syncQuoteIdentifier($column);
            }

            $jsonArguments[] = "'" . str_replace("'", "''", $jsonKey) . "'";
            $jsonArguments[] = $expression;
        }
        $payloadExpression = 'json_object(' . implode(', ', $jsonArguments) . ')';
        $quotedTable = syncQuoteIdentifier($table);
        $quotedOutbox = syncQuoteIdentifier('offline_sync_outbox');
        $triggerBase = 'trg_outbox_' . substr(hash('sha256', $table), 0, 20);
        $triggerBody = 'INSERT INTO ' . $quotedOutbox .
            ' (`tabla`, `uuid`, `payload_json`, `operacion`, `estado`, `attempts`, `last_error`) VALUES (' .
            $pdo->quote($table) . ', NEW.`uuid`, ' .
            $payloadExpression . ', ' . $pdo->quote('upsert') . ', ' .
            $pdo->quote('pending') . ', 0, NULL) ' .
            'ON CONFLICT (`tabla`, `uuid`) DO UPDATE SET ' .
            '`payload_json` = excluded.`payload_json`, `estado` = ' . $pdo->quote('pending') . ', ' .
            '`operacion` = ' . $pdo->quote('upsert') . ', `attempts` = 0, ' .
            '`last_error` = NULL, `updated_at` = CURRENT_TIMESTAMP;';

        foreach (['_insert', '_update', '_delete'] as $suffix) {
            $pdo->exec('DROP TRIGGER IF EXISTS ' . syncQuoteIdentifier($triggerBase . $suffix));
        }
        $pdo->exec(
            'CREATE TRIGGER ' . syncQuoteIdentifier($triggerBase . '_insert') .
            ' AFTER INSERT ON ' . $quotedTable .
            ' WHEN NOT EXISTS (SELECT 1 FROM `offline_sync_metadata` ' .
            'WHERE `key` = "pull_in_progress" AND `value` = "1") ' .
            ' BEGIN ' . $triggerBody .
            ' UPDATE ' . $quotedTable . ' SET `sincronizado` = 0 WHERE `id` = NEW.`id`; END'
        );
        $updateColumns = array_values(array_unique(array_merge(['uuid'], $tableSchema['fields'])));
        $updateColumnSql = implode(', ', array_map('syncQuoteIdentifier', $updateColumns));
        $pdo->exec(
            'CREATE TRIGGER ' . syncQuoteIdentifier($triggerBase . '_update') .
            ' AFTER UPDATE OF ' . $updateColumnSql . ' ON ' . $quotedTable .
            ' WHEN NOT (COALESCE(NEW.`sincronizado`, 0) = 1 AND COALESCE(OLD.`sincronizado`, 0) <> 1)' .
            ' AND NOT EXISTS (SELECT 1 FROM `offline_sync_metadata` ' .
            'WHERE `key` = "pull_in_progress" AND `value` = "1")' .
            ' BEGIN ' . $triggerBody .
            ' UPDATE ' . $quotedTable . ' SET `sincronizado` = 0 WHERE `id` = NEW.`id`; END'
        );
        $tableLiteral = $pdo->quote($table);
        $deleteBody = 'DELETE FROM ' . $quotedOutbox .
            ' WHERE `tabla` = ' . $tableLiteral . ' AND `uuid` = OLD.`uuid` AND `operacion` = ' .
            $pdo->quote('upsert') . ' AND NOT EXISTS (' .
            'SELECT 1 FROM `sync_map` WHERE `uuid` = OLD.`uuid` AND `tabla` = ' . $tableLiteral . '); ' .
            'INSERT INTO ' . $quotedOutbox .
            ' (`tabla`, `uuid`, `payload_json`, `operacion`, `estado`, `attempts`, `last_error`) ' .
            'SELECT ' . $tableLiteral . ', OLD.`uuid`, json_object(' .
            $pdo->quote('uuid') . ', OLD.`uuid`), ' . $pdo->quote('delete') . ', ' .
            $pdo->quote('pending') . ', 0, NULL WHERE EXISTS (' .
            'SELECT 1 FROM `sync_map` WHERE `uuid` = OLD.`uuid` AND `tabla` = ' . $tableLiteral . ') ' .
            'ON CONFLICT (`tabla`, `uuid`) DO UPDATE SET ' .
            '`payload_json` = excluded.`payload_json`, `operacion` = ' . $pdo->quote('delete') . ', ' .
            '`estado` = ' . $pdo->quote('pending') . ', `attempts` = 0, `last_error` = NULL, ' .
            '`updated_at` = CURRENT_TIMESTAMP;';
        $pdo->exec(
            'CREATE TRIGGER ' . syncQuoteIdentifier($triggerBase . '_delete') .
            ' AFTER DELETE ON ' . $quotedTable .
            ' WHEN NOT EXISTS (SELECT 1 FROM `offline_sync_metadata` ' .
            'WHERE `key` = "pull_in_progress" AND `value` = "1") ' .
            ' BEGIN ' . $deleteBody . ' END'
        );
    }

    if ($installAllTables) {
        $statement = $pdo->prepare(
            'INSERT OR REPLACE INTO `offline_sync_metadata` (`key`, `value`)
             VALUES ("trigger_version", ?)'
        );
        $statement->execute(['2']);
    }
}

function offlineSyncRemoveTriggers(PDO $pdo): void
{
    if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'sqlite') {
        throw new RuntimeException('Los disparadores de la cola requieren SQLite.');
    }

    $triggers = $pdo->query(
        "SELECT `name` FROM `sqlite_master`
         WHERE `type` = 'trigger' AND substr(`name`, 1, 11) = 'trg_outbox_'"
    )->fetchAll(PDO::FETCH_COLUMN);
    foreach ($triggers as $trigger) {
        $pdo->exec('DROP TRIGGER IF EXISTS ' . syncQuoteIdentifier((string) $trigger));
    }

    $metadataExists = $pdo->query(
        "SELECT 1 FROM `sqlite_master`
         WHERE `type` = 'table' AND `name` = 'offline_sync_metadata' LIMIT 1"
    )->fetchColumn();
    if ($metadataExists !== false) {
        $statement = $pdo->prepare(
            'DELETE FROM `offline_sync_metadata` WHERE `key` = "trigger_version"'
        );
        $statement->execute();
    }
}

function offlineSyncGetConfig(): array
{
    $localConfigPath = __DIR__ . '/config.local.php';
    $localConfig = is_file($localConfigPath) ? require $localConfigPath : [];
    if (!is_array($localConfig)) {
        throw new RuntimeException('includes/config.local.php debe devolver un arreglo.');
    }

    $syncConfig = isset($localConfig['sync']) && is_array($localConfig['sync'])
        ? $localConfig['sync']
        : [];
    $endpoint = getenv('SYNC_ENDPOINT');
    if ($endpoint === false || $endpoint === '') {
        $endpoint = $syncConfig['endpoint'] ?? null;
    }

    $token = getenv('SYNC_TOKEN');
    if ($token === false || $token === '') {
        $token = $syncConfig['token'] ?? null;
    }

    if (!is_string($endpoint) || trim($endpoint) === '') {
        throw new RuntimeException('Configura SYNC_ENDPOINT o sync.endpoint en includes/config.local.php.');
    }
    if (!is_string($token) || strlen($token) < 32) {
        throw new RuntimeException('Configura un token SYNC_TOKEN de al menos 32 caracteres.');
    }

    $parts = parse_url($endpoint);
    if ($parts === false || empty($parts['host']) ||
        isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])) {
        throw new RuntimeException('SYNC_ENDPOINT debe ser una URL HTTP(S) válida sin credenciales ni fragmento.');
    }

    $scheme = strtolower($parts['scheme'] ?? '');
    $host = strtolower($parts['host']);
    $isLoopback = in_array($host, ['localhost', '127.0.0.1', '::1'], true);
    if ($scheme !== 'https' && !($scheme === 'http' && $isLoopback)) {
        throw new RuntimeException('SYNC_ENDPOINT debe usar HTTPS, excepto para pruebas en localhost.');
    }

    return ['endpoint' => $endpoint, 'token' => $token];
}

function offlineSyncEnqueue(PDO $pdo, string $table, array $record): void
{
    $validated = syncValidateRecords([$table => [$record]]);
    if (!isset($validated[$table][0])) {
        throw new SyncPayloadException('No se pudo validar el registro para la cola offline.');
    }

    $uuid = $validated[$table][0]['uuid'];
    $record['uuid'] = $uuid;
    $payload = json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($payload === false) {
        throw new RuntimeException('No se pudo codificar el registro offline como JSON.');
    }

    $ownsTransaction = !$pdo->inTransaction();
    if ($ownsTransaction) {
        $pdo->beginTransaction();
    }

    try {
        $statement = $pdo->prepare(
            'SELECT `id` FROM `offline_sync_outbox` WHERE `tabla` = ? AND `uuid` = ? LIMIT 1'
        );
        $statement->execute([$table, $uuid]);
        $existingId = $statement->fetchColumn();

        if ($existingId === false) {
            $statement = $pdo->prepare(
                'INSERT INTO `offline_sync_outbox`
                    (`tabla`, `uuid`, `payload_json`, `operacion`, `estado`, `attempts`, `last_error`)
                 VALUES (?, ?, ?, "upsert", "pending", 0, NULL)'
            );
            $statement->execute([$table, $uuid, $payload]);
        } else {
            $statement = $pdo->prepare(
                'UPDATE `offline_sync_outbox`
                 SET `payload_json` = ?, `estado` = "pending", `attempts` = 0,
                     `operacion` = "upsert", `last_error` = NULL, `updated_at` = CURRENT_TIMESTAMP
                 WHERE `id` = ?'
            );
            $statement->execute([$payload, (int) $existingId]);
        }

        if ($ownsTransaction) {
            $pdo->commit();
        }
    } catch (Throwable $exception) {
        if ($ownsTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }
}

function offlineSyncPendingCount(PDO $pdo): int
{
    return (int) $pdo->query(
        'SELECT COUNT(*) FROM `offline_sync_outbox` WHERE `estado` = "pending"'
    )->fetchColumn();
}

function offlineSyncRetryConflict(PDO $pdo, int $entryId): bool
{
    if ($entryId < 1) {
        return false;
    }

    $statement = $pdo->prepare(
        'UPDATE `offline_sync_outbox`
         SET `estado` = "pending", `last_error` = NULL, `updated_at` = CURRENT_TIMESTAMP
         WHERE `id` = ? AND `estado` = "conflict"'
    );
    $statement->execute([$entryId]);

    return $statement->rowCount() === 1;
}

function offlineSyncNextBatch(PDO $pdo): array
{
    $records = [];
    $deletions = [];
    $entries = [];
    $maxRequestBytes = 1900000;
    $processingOrder = [];
    foreach (syncReceiverOrder() as $table) {
        $processingOrder[] = [$table, 'upsert'];
    }
    foreach (array_reverse(syncReceiverOrder()) as $table) {
        $processingOrder[] = [$table, 'delete'];
    }

    foreach ($processingOrder as [$table, $operation]) {
        $statement = $pdo->prepare(
            'SELECT `id`, `uuid`, `payload_json`, `operacion` FROM `offline_sync_outbox`
             WHERE `tabla` = ? AND `operacion` = ? AND `estado` = "pending"
             ORDER BY `id` LIMIT 50'
        );
        $statement->execute([$table, $operation]);
        foreach ($statement->fetchAll() as $entry) {
            $record = json_decode($entry['payload_json'], true);
            if (!is_array($record) || json_last_error() !== JSON_ERROR_NONE ||
                ($operation === 'delete' && ($record['uuid'] ?? null) !== $entry['uuid'])) {
                $update = $pdo->prepare(
                    'UPDATE `offline_sync_outbox`
                     SET `estado` = "blocked", `last_error` = ?, `updated_at` = CURRENT_TIMESTAMP
                     WHERE `id` = ?'
                );
                $update->execute(['El registro pendiente contiene JSON inválido.', $entry['id']]);
                continue;
            }

            $candidateRecords = $records;
            $candidateDeletions = $deletions;
            if ($operation === 'upsert') {
                if (!isset($candidateRecords[$table])) {
                    $candidateRecords[$table] = [];
                }
                $candidateRecords[$table][] = $record;
            } else {
                if (!isset($candidateDeletions[$table])) {
                    $candidateDeletions[$table] = [];
                }
                $candidateDeletions[$table][] = $entry['uuid'];
            }
            $candidateRequest = ['records' => $candidateRecords];
            if ($candidateDeletions !== []) {
                $candidateRequest['deletions'] = $candidateDeletions;
            }
            $candidatePayload = json_encode($candidateRequest, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($candidatePayload === false) {
                throw new RuntimeException('No se pudo codificar el lote offline.');
            }

            if (strlen($candidatePayload) > $maxRequestBytes) {
                if ($entries === []) {
                    $update = $pdo->prepare(
                        'UPDATE `offline_sync_outbox`
                         SET `estado` = "blocked", `last_error` = ?, `updated_at` = CURRENT_TIMESTAMP
                         WHERE `id` = ?'
                    );
                    $update->execute([
                        'El registro supera el límite seguro de tamaño de la solicitud.',
                        $entry['id'],
                    ]);
                    continue;
                }
                return ['records' => $records, 'entries' => $entries];
            }

            $records = $candidateRecords;
            $deletions = $candidateDeletions;
            $entries[] = [
                'id' => (int) $entry['id'],
                'tabla' => $table,
                'uuid' => $entry['uuid'],
                'payload_json' => $entry['payload_json'],
                'operacion' => $operation,
            ];
        }
    }

    return ['records' => $records, 'deletions' => $deletions, 'entries' => $entries];
}

function offlineSyncRequest(
    string $endpoint,
    string $token,
    array $records,
    array $deletions = []
): array
{
    if (!extension_loaded('curl')) {
        throw new RuntimeException('La extensión cURL no está habilitada.');
    }

    $request = ['records' => $records];
    if ($deletions !== []) {
        $request['deletions'] = $deletions;
    }
    $payload = json_encode($request, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($payload === false) {
        throw new RuntimeException('No se pudo codificar la solicitud de sincronización.');
    }

    $curl = curl_init($endpoint);
    if ($curl === false) {
        throw new RuntimeException('No se pudo iniciar cURL para sincronizar.');
    }

    try {
        $configured = curl_setopt_array($curl, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $token,
                'Content-Type: application/json',
                'Accept: application/json',
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        if (!$configured) {
            throw new RuntimeException('No se pudo configurar la solicitud cURL.');
        }

        $body = curl_exec($curl);
        if ($body === false) {
            throw new RuntimeException('No se pudo conectar al servidor de sincronización: ' . curl_error($curl));
        }

        $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
    } finally {
        curl_close($curl);
    }

    $response = json_decode($body, true);
    if ($status !== 200 || !is_array($response) || !isset($response['results']) ||
        !is_array($response['results'])) {
        $serverError = is_array($response) && isset($response['error']) && is_string($response['error'])
            ? $response['error']
            : 'respuesta_inválida';
        throw new OfflineSyncHttpException($status, $serverError);
    }

    return $response['results'];
}

function offlineSyncPullRequest(string $endpoint, string $token, string $cursor): array
{
    if (!extension_loaded('curl')) {
        throw new RuntimeException('La extensión cURL no está habilitada.');
    }

    $payload = json_encode(
        ['action' => 'pull', 'cursor' => $cursor],
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
    if ($payload === false) {
        throw new RuntimeException('No se pudo codificar la solicitud pull.');
    }

    $curl = curl_init($endpoint);
    if ($curl === false) {
        throw new RuntimeException('No se pudo iniciar cURL para descargar cambios.');
    }

    try {
        $configured = curl_setopt_array($curl, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $token,
                'Content-Type: application/json',
                'Accept: application/json',
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        if (!$configured) {
            throw new RuntimeException('No se pudo configurar la solicitud pull.');
        }

        $body = curl_exec($curl);
        if ($body === false) {
            throw new RuntimeException('No se pudieron descargar cambios: ' . curl_error($curl));
        }
        $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
    } finally {
        curl_close($curl);
    }

    $response = json_decode($body, true);
    if ($status !== 200 || !is_array($response) ||
        !isset($response['changes'], $response['next_cursor'], $response['has_more']) ||
        !is_array($response['changes']) ||
        !is_string($response['next_cursor']) ||
        !preg_match('/^\d{1,20}$/', $response['next_cursor']) ||
        !is_bool($response['has_more'])) {
        $serverError = is_array($response) && isset($response['error']) && is_string($response['error'])
            ? $response['error']
            : 'respuesta_pull_inválida';
        throw new OfflineSyncHttpException($status, $serverError);
    }

    return $response;
}

class OfflineSyncHttpException extends RuntimeException
{
    private $statusCode;

    public function __construct(int $statusCode, string $message)
    {
        parent::__construct($message);
        $this->statusCode = $statusCode;
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }
}

class OfflineSyncBusyException extends RuntimeException
{
}

function offlineSyncPullCursor(PDO $pdo): string
{
    $statement = $pdo->prepare(
        'SELECT `value` FROM `offline_sync_metadata` WHERE `key` = "pull_cursor"'
    );
    $statement->execute();
    $cursor = $statement->fetchColumn();

    if ($cursor === false) {
        return '0';
    }
    if (!is_string($cursor) || !preg_match('/^\d{1,20}$/', $cursor)) {
        throw new RuntimeException('El cursor pull local está corrupto.');
    }

    return $cursor;
}

function offlineSyncCompareCursors(string $left, string $right): int
{
    $left = ltrim($left, '0');
    $right = ltrim($right, '0');
    $left = $left === '' ? '0' : $left;
    $right = $right === '' ? '0' : $right;
    if (strlen($left) !== strlen($right)) {
        return strlen($left) <=> strlen($right);
    }

    return strcmp($left, $right) <=> 0;
}

function offlineSyncApplyPullChanges(PDO $pdo, array $response): array
{
    if (!isset($response['changes'], $response['next_cursor'], $response['has_more']) ||
        !is_array($response['changes']) || !is_string($response['next_cursor']) ||
        !preg_match('/^\d{1,20}$/', $response['next_cursor']) ||
        !is_bool($response['has_more'])) {
        throw new RuntimeException('La respuesta pull no tiene el formato esperado.');
    }

    $changes = [];
    $schema = syncReceiverSchema();
    $order = array_flip(syncReceiverOrder());
    foreach ($response['changes'] as $change) {
        if (!is_array($change) || !isset(
            $change['cursor'],
            $change['tabla'],
            $change['uuid'],
            $change['operacion'],
            $change['id_remoto'],
            $change['payload']
        ) || !is_string($change['cursor']) || !preg_match('/^\d{1,20}$/', $change['cursor']) ||
            !is_string($change['tabla']) || !isset($schema[$change['tabla']]) ||
            !is_string($change['uuid']) || !is_numeric($change['id_remoto']) ||
            !is_array($change['payload']) ||
            !in_array($change['operacion'], ['upsert', 'delete'], true)) {
            throw new RuntimeException('La respuesta pull contiene un cambio inválido.');
        }
        $change['uuid'] = strtolower($change['uuid']);
        if (!preg_match(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/',
            $change['uuid']
        ) || strtolower((string) ($change['payload']['uuid'] ?? '')) !== $change['uuid']) {
            throw new RuntimeException('La respuesta pull contiene un UUID inválido.');
        }

        $key = $change['tabla'] . ':' . $change['uuid'];
        if (!isset($changes[$key]) ||
            offlineSyncCompareCursors($change['cursor'], $changes[$key]['cursor']) > 0) {
            $changes[$key] = $change;
        }
    }

    $changes = array_values($changes);
    usort($changes, function (array $left, array $right) use ($order): int {
        $leftDelete = $left['operacion'] === 'delete';
        $rightDelete = $right['operacion'] === 'delete';
        if ($leftDelete !== $rightDelete) {
            return $leftDelete ? 1 : -1;
        }

        $leftOrder = $order[$left['tabla']] ?? PHP_INT_MAX;
        $rightOrder = $order[$right['tabla']] ?? PHP_INT_MAX;
        if ($leftDelete) {
            $leftOrder = -$leftOrder;
            $rightOrder = -$rightOrder;
        }
        if ($leftOrder !== $rightOrder) {
            return $leftOrder <=> $rightOrder;
        }

        return offlineSyncCompareCursors($left['cursor'], $right['cursor']);
    });

    $currentCursor = offlineSyncPullCursor($pdo);
    if (offlineSyncCompareCursors($response['next_cursor'], $currentCursor) < 0) {
        throw new RuntimeException('El servidor devolvió un cursor pull anterior al local.');
    }

    $applied = 0;
    $pdo->beginTransaction();
    try {
        $pdo->exec(
            'INSERT OR REPLACE INTO `offline_sync_metadata` (`key`, `value`)
             VALUES ("pull_in_progress", "1")'
        );

        foreach ($changes as $change) {
            $table = $change['tabla'];
            $uuid = $change['uuid'];
            $outbox = $pdo->prepare(
                'SELECT `estado` FROM `offline_sync_outbox`
                 WHERE `tabla` = ? AND `uuid` = ? AND `estado` = "pending" LIMIT 1'
            );
            $outbox->execute([$table, $uuid]);
            if ($outbox->fetchColumn() !== false) {
                throw new OfflineSyncBusyException(
                    'El registro local cambió durante la descarga; se reintentará en la próxima sincronización.'
                );
            }

            if ($change['operacion'] === 'delete') {
                $delete = $pdo->prepare(
                    'DELETE FROM ' . syncQuoteIdentifier($table) . ' WHERE `uuid` = ?'
                );
                $delete->execute([$uuid]);
                $removeMap = $pdo->prepare(
                    'DELETE FROM `sync_map` WHERE `uuid` = ? AND `tabla` = ?'
                );
                $removeMap->execute([$uuid, $table]);
                $resolveConflict = $pdo->prepare(
                    'UPDATE `offline_sync_outbox`
                     SET `estado` = "synced", `last_error` = NULL, `updated_at` = CURRENT_TIMESTAMP
                     WHERE `tabla` = ? AND `uuid` = ? AND `estado` = "conflict"'
                );
                $resolveConflict->execute([$table, $uuid]);
                $applied++;
                continue;
            }

            $validated = syncValidateRecords([$table => [$change['payload']]]);
            if (!isset($validated[$table][0])) {
                throw new RuntimeException('No se pudo validar un registro recibido por pull.');
            }
            $record = $validated[$table][0];
            if ($record['uuid'] !== $uuid) {
                throw new RuntimeException('El UUID del registro recibido no coincide con su evento.');
            }

            $data = [];
            foreach ($schema[$table]['fields'] as $column) {
                if (!isset($schema[$table]['foreign_keys'][$column])) {
                    if (!array_key_exists($column, $record['data'])) {
                        throw new RuntimeException('Falta el campo ' . $table . '.' . $column . ' en el pull.');
                    }
                    $data[$column] = $record['data'][$column];
                    continue;
                }

                $foreignKey = $schema[$table]['foreign_keys'][$column];
                $uuidKey = 'uuid_' . $column;
                if (!array_key_exists($uuidKey, $record['data'])) {
                    throw new RuntimeException('Falta la referencia UUID ' . $uuidKey . ' en el pull.');
                }
                $foreignUuid = $record['data'][$uuidKey];
                if ($foreignUuid === null) {
                    if (!$foreignKey['nullable']) {
                        throw new RuntimeException('El pull contiene una referencia obligatoria vacía.');
                    }
                    $data[$column] = null;
                    continue;
                }

                $foreignStatement = $pdo->prepare(
                    'SELECT `id` FROM ' . syncQuoteIdentifier($foreignKey['table']) .
                    ' WHERE `uuid` = ? LIMIT 1'
                );
                $foreignStatement->execute([$foreignUuid]);
                $localId = $foreignStatement->fetchColumn();
                if ($localId === false) {
                    throw new RuntimeException(
                        'No se encontró la entidad local ' . $foreignKey['table'] .
                        ' referenciada por el pull.'
                    );
                }
                $data[$column] = (int) $localId;
            }

            $localStatement = $pdo->prepare(
                'SELECT `id` FROM ' . syncQuoteIdentifier($table) . ' WHERE `uuid` = ? LIMIT 1'
            );
            $localStatement->execute([$uuid]);
            $localId = $localStatement->fetchColumn();
            if ($localId === false) {
                $columns = array_merge(['uuid'], array_keys($data), ['sincronizado']);
                $insert = $pdo->prepare(
                    'INSERT INTO ' . syncQuoteIdentifier($table) . ' (' .
                    implode(', ', array_map('syncQuoteIdentifier', $columns)) . ') VALUES (' .
                    implode(', ', array_fill(0, count($columns), '?')) . ')'
                );
                $insert->execute(array_merge([$uuid], array_values($data), [1]));
            } else {
                $assignments = [];
                foreach (array_keys($data) as $column) {
                    $assignments[] = syncQuoteIdentifier($column) . ' = ?';
                }
                $assignments[] = '`sincronizado` = 1';
                $update = $pdo->prepare(
                    'UPDATE ' . syncQuoteIdentifier($table) . ' SET ' .
                    implode(', ', $assignments) . ' WHERE `id` = ?'
                );
                $update->execute(array_merge(array_values($data), [(int) $localId]));
            }

            $map = $pdo->prepare(
                'INSERT INTO `sync_map` (`uuid`, `tabla`, `id_remoto`) VALUES (?, ?, ?)
                 ON CONFLICT (`uuid`, `tabla`) DO UPDATE SET `id_remoto` = excluded.`id_remoto`'
            );
            $map->execute([$uuid, $table, (int) $change['id_remoto']]);
            $resolveConflict = $pdo->prepare(
                'UPDATE `offline_sync_outbox`
                 SET `estado` = "synced", `last_error` = NULL, `updated_at` = CURRENT_TIMESTAMP
                 WHERE `tabla` = ? AND `uuid` = ? AND `estado` = "conflict"'
            );
            $resolveConflict->execute([$table, $uuid]);
            $applied++;
        }

        $saveCursor = $pdo->prepare(
            'INSERT OR REPLACE INTO `offline_sync_metadata` (`key`, `value`)
             VALUES ("pull_cursor", ?)'
        );
        $saveCursor->execute([$response['next_cursor']]);
        $pdo->prepare(
            'DELETE FROM `offline_sync_metadata` WHERE `key` = "pull_in_progress"'
        )->execute();
        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }

    return [
        'applied' => $applied,
        'next_cursor' => $response['next_cursor'],
        'has_more' => $response['has_more'],
    ];
}

function offlineSyncStoreResults(PDO $pdo, array $entries, array $results): array
{
    $expected = [];
    foreach ($entries as $entry) {
        $expected[$entry['tabla'] . ':' . strtolower($entry['uuid'])] = $entry;
    }

    if (count($results) !== count($expected)) {
        throw new RuntimeException('La respuesta del servidor no coincide con los registros enviados.');
    }

    $processed = ['synced' => 0, 'conflicts' => 0];
    $pdo->beginTransaction();
    try {
        foreach ($results as $result) {
            if (!is_array($result) || !isset($result['tabla'], $result['uuid'], $result['id_remoto']) ||
                !is_string($result['tabla']) || !is_string($result['uuid']) ||
                !is_numeric($result['id_remoto']) || (int) $result['id_remoto'] < 0) {
                throw new RuntimeException('El servidor devolvió un resultado de sincronización inválido.');
            }

            $key = $result['tabla'] . ':' . strtolower($result['uuid']);
            if (!isset($expected[$key])) {
                throw new RuntimeException('El servidor devolvió un registro que no se envió.');
            }
            $entry = $expected[$key];
            unset($expected[$key]);

            $operation = $result['operacion'] ?? null;
            $allowedOperations = $entry['operacion'] === 'delete'
                ? ['deleted', 'already_deleted', 'ignored_stale']
                : ['inserted', 'updated', 'ignored_stale'];
            if (!in_array($operation, $allowedOperations, true)) {
                throw new RuntimeException('El servidor devolvió una operación desconocida.');
            }

            $mapStatement = $pdo->prepare(
                'SELECT `id` FROM `sync_map` WHERE `uuid` = ? AND `tabla` = ? LIMIT 1'
            );
            $mapStatement->execute([$entry['uuid'], $entry['tabla']]);
            $mapId = $mapStatement->fetchColumn();
            if ($mapId === false) {
                $mapStatement = $pdo->prepare(
                    'INSERT INTO `sync_map` (`uuid`, `tabla`, `id_remoto`) VALUES (?, ?, ?)'
                );
                $mapStatement->execute([$entry['uuid'], $entry['tabla'], (int) $result['id_remoto']]);
            } else {
                $mapStatement = $pdo->prepare(
                    'UPDATE `sync_map` SET `id_remoto` = ? WHERE `id` = ?'
                );
                $mapStatement->execute([(int) $result['id_remoto'], (int) $mapId]);
            }

            $isConflict = $operation === 'ignored_stale';
            $update = $pdo->prepare(
                'UPDATE `offline_sync_outbox`
                 SET `estado` = ?, `last_error` = ?, `updated_at` = CURRENT_TIMESTAMP
                 WHERE `id` = ? AND `estado` = "pending" AND `payload_json` = ?'
            );
            $update->execute([
                $isConflict ? 'conflict' : 'synced',
                $isConflict ? 'El servidor conservó una recepción posterior; revisar el conflicto.' : null,
                $entry['id'],
                $entry['payload_json'],
            ]);

            if ($update->rowCount() > 0) {
                $processed[$isConflict ? 'conflicts' : 'synced']++;
                if (!$isConflict && $entry['operacion'] === 'delete') {
                    $deleteMap = $pdo->prepare(
                        'DELETE FROM `sync_map` WHERE `uuid` = ? AND `tabla` = ?'
                    );
                    $deleteMap->execute([$entry['uuid'], $entry['tabla']]);
                } elseif (!$isConflict) {
                    $tableExists = $pdo->prepare(
                        "SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = ? LIMIT 1"
                    );
                    $tableExists->execute([$entry['tabla']]);
                    if ($tableExists->fetchColumn() !== false) {
                        $quotedTable = syncQuoteIdentifier($entry['tabla']);
                        $syncFlag = $pdo->prepare(
                            "UPDATE {$quotedTable} SET `sincronizado` = 1 WHERE `uuid` = ?"
                        );
                        $syncFlag->execute([$entry['uuid']]);
                    }
                }
            }
        }

        if ($expected !== []) {
            throw new RuntimeException('Faltan resultados para uno o más registros enviados.');
        }

        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }

    return $processed;
}

function offlineSyncRecordFailure(PDO $pdo, array $entries, string $error, bool $blocked): void
{
    $pdo->beginTransaction();
    try {
        $statement = $pdo->prepare(
            'UPDATE `offline_sync_outbox`
             SET `estado` = ?, `attempts` = `attempts` + 1, `last_error` = ?,
                 `updated_at` = CURRENT_TIMESTAMP
             WHERE `id` = ? AND `estado` = "pending" AND `payload_json` = ?'
        );

        foreach ($entries as $entry) {
            $statement->execute([
                $blocked ? 'blocked' : 'pending',
                $error,
                $entry['id'],
                $entry['payload_json'],
            ]);
        }

        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }
}

function offlineSyncRunUnlocked(PDO $pdo, string $endpoint, string $token, int $maxBatches): array
{
    if ($maxBatches < 1) {
        throw new InvalidArgumentException('maxBatches debe ser mayor que cero.');
    }

    $summary = ['requests' => 0, 'synced' => 0, 'conflicts' => 0, 'pulled' => 0];
    for ($batchNumber = 0; $batchNumber < $maxBatches; $batchNumber++) {
        $batch = offlineSyncNextBatch($pdo);
        if ($batch['entries'] === []) {
            break;
        }

        try {
            $results = offlineSyncRequest(
                $endpoint,
                $token,
                $batch['records'],
                $batch['deletions']
            );
            $stored = offlineSyncStoreResults($pdo, $batch['entries'], $results);
            $summary['requests']++;
            $summary['synced'] += $stored['synced'];
            $summary['conflicts'] += $stored['conflicts'];
        } catch (OfflineSyncHttpException $exception) {
            $blocked = in_array($exception->getStatusCode(), [409, 422], true);
            offlineSyncRecordFailure($pdo, $batch['entries'], $exception->getMessage(), $blocked);
            throw $exception;
        } catch (Throwable $exception) {
            offlineSyncRecordFailure($pdo, $batch['entries'], $exception->getMessage(), false);
            throw $exception;
        }
    }

    $pullPending = false;
    if (offlineSyncPendingCount($pdo) === 0) {
        for ($pullBatch = 0; $pullBatch < $maxBatches; $pullBatch++) {
            $cursor = offlineSyncPullCursor($pdo);
            $response = offlineSyncPullRequest($endpoint, $token, $cursor);
            $pulled = offlineSyncApplyPullChanges($pdo, $response);
            $summary['pulled'] += $pulled['applied'];
            $pullPending = $pulled['has_more'];
            if (!$pulled['has_more']) {
                break;
            }
        }
    }
    $summary['pull_pending'] = $pullPending;

    return $summary + [
        'pending' => offlineSyncPendingCount($pdo),
        'blocked' => (int) $pdo->query(
            'SELECT COUNT(*) FROM `offline_sync_outbox`
             WHERE `estado` IN ("blocked", "conflict")'
        )->fetchColumn(),
    ];
}

function offlineSyncRun(PDO $pdo, string $endpoint, string $token, int $maxBatches = 20): array
{
    $databasePath = offlineSyncDatabasePath();
    $resolvedPath = realpath($databasePath);
    $lockKey = $resolvedPath === false ? $databasePath : $resolvedPath;
    $lockPath = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) .
        DIRECTORY_SEPARATOR . 'sistema-estudiantes-sync-' . hash('sha256', $lockKey) . '.lock';
    $lockHandle = fopen($lockPath, 'c');
    if ($lockHandle === false) {
        throw new RuntimeException('No se pudo crear el bloqueo de sincronización local.');
    }

    if (!flock($lockHandle, LOCK_EX | LOCK_NB)) {
        fclose($lockHandle);
        throw new OfflineSyncBusyException('Ya hay una sincronización en curso.');
    }

    try {
        return offlineSyncRunUnlocked($pdo, $endpoint, $token, $maxBatches);
    } finally {
        flock($lockHandle, LOCK_UN);
        fclose($lockHandle);
    }
}
