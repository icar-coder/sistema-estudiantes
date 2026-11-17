<?php

function quoteDatabaseIdentifier(string $identifier): string
{
    return '`' . str_replace('`', '``', $identifier) . '`';
}

function writeBackupContents(PDO $pdo, string $path): void
{
    $handle = fopen($path, 'wb');
    if ($handle === false) {
        throw new RuntimeException('No se pudo crear el archivo de respaldo.');
    }

    $write = static function (string $contents) use ($handle): void {
        $length = strlen($contents);
        $offset = 0;
        while ($offset < $length) {
            $written = fwrite($handle, substr($contents, $offset));
            if ($written === false || $written === 0) {
                throw new RuntimeException('No se pudo escribir el archivo de respaldo.');
            }
            $offset += $written;
        }
    };

    try {
        $write("-- Respaldo del Sistema de Gestión Estudiantil\n");
        $write("SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n\n");

        $tables = $pdo->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'");
        while (($tableRow = $tables->fetch(PDO::FETCH_NUM)) !== false) {
            $tableName = (string) $tableRow[0];
            $quotedTable = quoteDatabaseIdentifier($tableName);
            $create = $pdo->query('SHOW CREATE TABLE ' . $quotedTable)->fetch(PDO::FETCH_NUM);
            if (!isset($create[1])) {
                throw new RuntimeException('No se pudo leer la estructura de una tabla.');
            }

            $write('DROP TABLE IF EXISTS ' . $quotedTable . ";\n");
            $write($create[1] . ";\n\n");

            $binaryColumns = [];
            $columns = $pdo->query('SHOW FULL COLUMNS FROM ' . $quotedTable);
            while (($column = $columns->fetch(PDO::FETCH_ASSOC)) !== false) {
                if (preg_match('/^(tinyblob|blob|mediumblob|longblob|binary|varbinary)\b/i', (string) $column['Type'])) {
                    $binaryColumns[(string) $column['Field']] = true;
                }
            }

            $rows = $pdo->query('SELECT * FROM ' . $quotedTable);
            while (($row = $rows->fetch(PDO::FETCH_ASSOC)) !== false) {
                $columnNames = [];
                $values = [];
                foreach ($row as $columnName => $value) {
                    $columnNames[] = quoteDatabaseIdentifier((string) $columnName);
                    if ($value === null) {
                        $values[] = 'NULL';
                    } elseif (isset($binaryColumns[$columnName])) {
                        $values[] = '0x' . bin2hex((string) $value);
                    } else {
                        $quotedValue = $pdo->quote((string) $value);
                        if ($quotedValue === false) {
                            throw new RuntimeException('No se pudo serializar un valor del respaldo.');
                        }
                        $values[] = $quotedValue;
                    }
                }

                $write(
                    'INSERT INTO ' . $quotedTable . ' (' . implode(', ', $columnNames) . ') VALUES ('
                    . implode(', ', $values) . ");\n"
                );
            }

            $write("\n");
        }

        $write("SET FOREIGN_KEY_CHECKS=1;\n");
    } finally {
        fclose($handle);
    }
}

function parseBackupStatements(string $sql): iterable
{
    $statement = '';
    $quote = null;
    $escaped = false;
    $length = strlen($sql);

    for ($index = 0; $index < $length; $index++) {
        $character = $sql[$index];
        $nextCharacter = $index + 1 < $length ? $sql[$index + 1] : '';

        if ($quote !== null) {
            $statement .= $character;
            if ($escaped) {
                $escaped = false;
            } elseif ($character === '\\') {
                $escaped = true;
            } elseif ($character === $quote) {
                if ($nextCharacter === $quote) {
                    $statement .= $nextCharacter;
                    $index++;
                } else {
                    $quote = null;
                }
            }
            continue;
        }

        if ($character === "'" || $character === '"' || $character === '`') {
            $quote = $character;
            $statement .= $character;
            continue;
        }

        if ($character === '#' || ($character === '-' && $nextCharacter === '-' && (
            $index + 2 >= $length || ctype_space($sql[$index + 2])
        ))) {
            while ($index < $length && $sql[$index] !== "\n") {
                $index++;
            }
            $statement .= "\n";
            continue;
        }

        if ($character === '/' && $nextCharacter === '*') {
            $end = strpos($sql, '*/', $index + 2);
            if ($end === false) {
                throw new RuntimeException('El archivo SQL contiene un comentario incompleto.');
            }
            $statement .= ' ';
            $index = $end + 1;
            continue;
        }

        if ($character === ';') {
            $trimmed = trim($statement);
            if ($trimmed !== '') {
                yield $trimmed;
            }
            $statement = '';
            continue;
        }

        $statement .= $character;
    }

    if ($quote !== null) {
        throw new RuntimeException('El archivo SQL contiene una cadena incompleta.');
    }

    $trimmed = trim($statement);
    if ($trimmed !== '') {
        yield $trimmed;
    }
}

function restoreBackupContents(PDO $pdo, string $path): void
{
    $sql = file_get_contents($path);
    if ($sql === false || trim($sql) === '') {
        throw new RuntimeException('El archivo SQL está vacío o no se pudo leer.');
    }
    $sql = preg_replace('/^\xEF\xBB\xBF/', '', $sql);

    $hasStatements = false;
    foreach (parseBackupStatements($sql) as $statement) {
        if (preg_match('/^(CREATE|DROP)\s+(DATABASE|SCHEMA)\b|^USE\b|^SET\b|^LOCK\s+TABLES\b|^UNLOCK\s+TABLES\b/i', $statement)) {
            continue;
        }

        if (!preg_match('/^(DROP\s+TABLE|CREATE\s+TABLE|ALTER\s+TABLE|INSERT\s+INTO|REPLACE\s+INTO|TRUNCATE\s+TABLE)\b/i', $statement)) {
            throw new RuntimeException('El respaldo contiene una instrucción SQL no permitida.');
        }

        $hasStatements = true;
    }

    if (!$hasStatements) {
        throw new RuntimeException('El archivo no contiene instrucciones de respaldo compatibles.');
    }

    $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
    try {
        foreach (parseBackupStatements($sql) as $statement) {
            if (preg_match('/^(CREATE|DROP)\s+(DATABASE|SCHEMA)\b|^USE\b|^SET\b|^LOCK\s+TABLES\b|^UNLOCK\s+TABLES\b/i', $statement)) {
                continue;
            }
            $pdo->exec($statement);
        }
    } finally {
        $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
    }
}
