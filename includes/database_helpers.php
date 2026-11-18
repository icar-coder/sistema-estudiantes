<?php

function isSqliteDatabase(PDO $pdo): bool
{
    return $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite';
}

function databaseTableExists(PDO $pdo, string $table): bool
{
    if (isSqliteDatabase($pdo)) {
        $statement = $pdo->prepare(
            "SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = ? LIMIT 1"
        );
    } else {
        $statement = $pdo->prepare(
            'SELECT 1 FROM information_schema.tables
             WHERE table_schema = DATABASE() AND table_name = ? LIMIT 1'
        );
    }

    $statement->execute([$table]);
    return $statement->fetchColumn() !== false;
}

function databaseColumnExists(PDO $pdo, string $table, string $column): bool
{
    if (isSqliteDatabase($pdo)) {
        $quotedTable = '"' . str_replace('"', '""', $table) . '"';
        $statement = $pdo->query('PRAGMA table_info(' . $quotedTable . ')');
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if ($row['name'] === $column) {
                return true;
            }
        }
        return false;
    }

    $statement = $pdo->prepare(
        'SELECT 1 FROM information_schema.columns
         WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ? LIMIT 1'
    );
    $statement->execute([$table, $column]);
    return $statement->fetchColumn() !== false;
}

function databaseUpsertClause(
    PDO $pdo,
    array $conflictColumns,
    array $updateColumns,
    array $currentTimestampColumns = []
): string {
    foreach (array_merge($conflictColumns, $updateColumns, $currentTimestampColumns) as $column) {
        if (!is_string($column) ||
            !preg_match('/^[A-Za-z0-9_áéíóúÁÉÍÓÚñÑ]+$/u', $column)) {
            throw new InvalidArgumentException('Nombre de columna no válido para upsert.');
        }
    }

    if (isSqliteDatabase($pdo)) {
        $quote = function ($column) {
            return '"' . str_replace('"', '""', $column) . '"';
        };
        $assignments = [];
        foreach ($updateColumns as $column) {
            $quotedColumn = $quote($column);
            $assignments[] = $quotedColumn . ' = excluded.' . $quotedColumn;
        }
        foreach ($currentTimestampColumns as $column) {
            $assignments[] = $quote($column) . ' = CURRENT_TIMESTAMP';
        }

        return 'ON CONFLICT (' .
            implode(', ', array_map($quote, $conflictColumns)) .
            ') DO UPDATE SET ' . implode(', ', $assignments);
    }

    $assignments = [];
    foreach ($updateColumns as $column) {
        $quotedColumn = '`' . $column . '`';
        $assignments[] = $quotedColumn . ' = VALUES(' . $quotedColumn . ')';
    }
    foreach ($currentTimestampColumns as $column) {
        $assignments[] = '`' . $column . '` = CURRENT_TIMESTAMP';
    }

    return 'ON DUPLICATE KEY UPDATE ' . implode(', ', $assignments);
}

function requireOnlineFeature(PDO $pdo, string $feature): void
{
    if (!isSqliteDatabase($pdo)) {
        return;
    }

    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    $_SESSION['offline_feature_notice'] = $feature . ' no está habilitado en el modo offline.';
    header('Location: ' . app_url('admin/dashboard.php'));
    exit;
}
