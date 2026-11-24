<?php

require_once __DIR__ . '/database_helpers.php';

function crearTablasPlanAnual(PDO $pdo): void
{
    $tieneAreas = databaseTableExists($pdo, 'plan_anual_areas');
    $tieneIndicadores = databaseTableExists($pdo, 'plan_anual_indicadores');
    $tieneMetadata = databaseTableExists($pdo, 'plan_anual_meta');
    if ($tieneAreas && $tieneIndicadores && $tieneMetadata) {
        return;
    }

    if (isSqliteDatabase($pdo)) {
        if (!$tieneAreas) {
            $pdo->exec(
                'CREATE TABLE IF NOT EXISTS plan_anual_areas (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                uuid TEXT NOT NULL UNIQUE,
                sincronizado INTEGER NOT NULL DEFAULT 0,
                año_escolar_id INTEGER NOT NULL,
                area_id INTEGER NOT NULL,
                UNIQUE (año_escolar_id, area_id)
            )'
            );
        }
        if (!$tieneIndicadores) {
            $pdo->exec(
                'CREATE TABLE IF NOT EXISTS plan_anual_indicadores (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                uuid TEXT NOT NULL UNIQUE,
                sincronizado INTEGER NOT NULL DEFAULT 0,
                año_escolar_id INTEGER NOT NULL,
                indicador_id INTEGER NOT NULL,
                UNIQUE (año_escolar_id, indicador_id)
            )'
            );
        }
        if (!$tieneMetadata) {
            $pdo->exec(
                'CREATE TABLE IF NOT EXISTS plan_anual_meta (
                    clave TEXT PRIMARY KEY,
                    valor TEXT NOT NULL
                )'
            );
        }
        return;
    }

    if (!$tieneAreas) {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS `plan_anual_areas` (
            `id` INT(11) NOT NULL AUTO_INCREMENT,
            `uuid` CHAR(36) NOT NULL,
            `sincronizado` TINYINT(4) NOT NULL DEFAULT 0,
            `año_escolar_id` INT(11) NOT NULL,
            `area_id` INT(11) NOT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_plan_anual_area_uuid` (`uuid`),
            UNIQUE KEY `uq_plan_anual_area` (`año_escolar_id`, `area_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );
    }
    if (!$tieneIndicadores) {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS `plan_anual_indicadores` (
            `id` INT(11) NOT NULL AUTO_INCREMENT,
            `uuid` CHAR(36) NOT NULL,
            `sincronizado` TINYINT(4) NOT NULL DEFAULT 0,
            `año_escolar_id` INT(11) NOT NULL,
            `indicador_id` INT(11) NOT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_plan_anual_indicador_uuid` (`uuid`),
            UNIQUE KEY `uq_plan_anual_indicador` (`año_escolar_id`, `indicador_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );
    }
    if (!$tieneMetadata) {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS `plan_anual_meta` (
                `clave` VARCHAR(100) NOT NULL,
                `valor` TEXT NOT NULL,
                PRIMARY KEY (`clave`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );
    }
}

function planAnualAgregarArea(PDO $pdo, int $añoId, int $areaId): void
{
    $uuid = planAnualGenerarUuid();
    $statement = $pdo->prepare(
        'INSERT INTO plan_anual_areas
         (uuid, sincronizado, año_escolar_id, area_id) VALUES (?, 0, ?, ?)'
    );
    $statement->execute([$uuid, $añoId, $areaId]);
    planAnualRegistrarMapaRemoto($pdo, $uuid, 'plan_anual_areas');
}

function planAnualAgregarIndicador(PDO $pdo, int $añoId, int $indicadorId): void
{
    $uuid = planAnualGenerarUuid();
    $statement = $pdo->prepare(
        'INSERT INTO plan_anual_indicadores
         (uuid, sincronizado, año_escolar_id, indicador_id) VALUES (?, 0, ?, ?)'
    );
    $statement->execute([$uuid, $añoId, $indicadorId]);
    planAnualRegistrarMapaRemoto($pdo, $uuid, 'plan_anual_indicadores');
}

function planAnualRegistrarMapaRemoto(PDO $pdo, string $uuid, string $tabla): void
{
    if (isSqliteDatabase($pdo) || !databaseTableExists($pdo, 'sync_map')) {
        return;
    }

    $statement = $pdo->prepare(
        'INSERT IGNORE INTO sync_map (uuid, tabla, id_remoto)
         SELECT uuid, ?, id FROM `' . $tabla . '` WHERE uuid = ?'
    );
    $statement->execute([$tabla, $uuid]);
}

function planAnualGuardarAsociaciones(PDO $pdo, int $añoId, array $areas, array $indicadores): void
{
    $asociaciones = [
        [
            'table' => 'plan_anual_areas',
            'column' => 'area_id',
            'selected' => $areas,
            'insert' => 'area',
        ],
        [
            'table' => 'plan_anual_indicadores',
            'column' => 'indicador_id',
            'selected' => $indicadores,
            'insert' => 'indicador',
        ],
    ];

    foreach ($asociaciones as $association) {
        $selected = array_fill_keys(array_map('intval', $association['selected']), true);
        $statement = $pdo->prepare(
            'SELECT `' . $association['column'] . '` FROM `' . $association['table'] . '`
             WHERE `año_escolar_id` = ?'
        );
        $statement->execute([$añoId]);
        $existing = array_map('intval', $statement->fetchAll(PDO::FETCH_COLUMN));
        foreach ($existing as $id) {
            if (!isset($selected[$id])) {
                $statement = $pdo->prepare(
                    'DELETE FROM `' . $association['table'] . '`
                     WHERE `año_escolar_id` = ? AND `' . $association['column'] . '` = ?'
                );
                $statement->execute([$añoId, $id]);
            }
        }
        $existingSet = array_fill_keys($existing, true);
        foreach (array_keys($selected) as $id) {
            if (isset($existingSet[$id])) {
                continue;
            }
            if ($association['insert'] === 'area') {
                planAnualAgregarArea($pdo, $añoId, (int) $id);
            } else {
                planAnualAgregarIndicador($pdo, $añoId, (int) $id);
            }
        }
    }
}

function planAnualGenerarUuid(): string
{
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
}

function planAnualInsertarArea(PDO $pdo, array $area, int $añoId): int
{
    $values = [
        'nombre' => $area['nombre'],
        'codigo' => $area['codigo'] ?? null,
        'descripcion' => $area['descripcion'] ?? null,
        'nivel' => $area['nivel'] ?? 'primaria',
        'estado' => $area['estado'] ?? 'activa',
    ];

    return planAnualInsertarCatalogo($pdo, 'areas_aprendizaje', $values, $añoId);
}

function planAnualInsertarIndicador(PDO $pdo, array $indicador, int $areaId, int $añoId): int
{
    $values = [
        'area_id' => $areaId,
        'nombre' => $indicador['nombre'],
        'descripcion' => $indicador['descripcion'] ?? null,
        'escala' => $indicador['escala'] ?? 'L',
        'orden' => $indicador['orden'] ?? 0,
        'estado' => $indicador['estado'] ?? 'activo',
    ];

    return planAnualInsertarCatalogo($pdo, 'areas_indicadores', $values, $añoId);
}

function planAnualInsertarCatalogo(PDO $pdo, string $tabla, array $values, int $añoId): int
{
    if (!in_array($tabla, ['areas_aprendizaje', 'areas_indicadores'], true)) {
        throw new InvalidArgumentException('Tabla de catálogo anual no válida.');
    }
    if (databaseColumnExists($pdo, $tabla, 'uuid')) {
        $values['uuid'] = planAnualGenerarUuid();
    }
    if (databaseColumnExists($pdo, $tabla, 'sincronizado')) {
        $values['sincronizado'] = 0;
    }

    if ($tabla === 'areas_aprendizaje' && isset($values['codigo']) &&
        planAnualCodigoTieneIndiceUnico($pdo) &&
        planAnualExisteCodigo($pdo, (string) $values['codigo'])) {
        $values['codigo'] = planAnualCodigoUnico($pdo, (string) $values['codigo'], $añoId);
    }

    $columns = array_keys($values);
    $quotedColumns = array_map(
        function ($column) {
            return '`' . $column . '`';
        },
        $columns
    );
    $placeholders = implode(', ', array_fill(0, count($columns), '?'));
    $statement = $pdo->prepare(
        'INSERT INTO `' . $tabla . '` (' . implode(', ', $quotedColumns) . ')
         VALUES (' . $placeholders . ')'
    );
    $statement->execute(array_values($values));

    return (int) $pdo->lastInsertId();
}

function planAnualCodigoTieneIndiceUnico(PDO $pdo): bool
{
    if (isSqliteDatabase($pdo)) {
        $indexes = $pdo->query('PRAGMA index_list(`areas_aprendizaje`)')->fetchAll(PDO::FETCH_ASSOC);
        foreach ($indexes as $index) {
            if ((int) $index['unique'] !== 1) {
                continue;
            }
            $quotedName = '`' . str_replace('`', '``', $index['name']) . '`';
            $columns = $pdo->query('PRAGMA index_info(' . $quotedName . ')')
                ->fetchAll(PDO::FETCH_ASSOC);
            if (array_column($columns, 'name') === ['codigo']) {
                return true;
            }
        }
        return false;
    }

    $indexes = $pdo->query('SHOW INDEX FROM `areas_aprendizaje`')
        ->fetchAll(PDO::FETCH_ASSOC);
    $uniqueColumns = [];
    foreach ($indexes as $index) {
        if ((int) $index['Non_unique'] === 0) {
            $uniqueColumns[$index['Key_name']][] = $index['Column_name'];
        }
    }
    foreach ($uniqueColumns as $columns) {
        if ($columns === ['codigo']) {
            return true;
        }
    }

    return false;
}

function planAnualExisteCodigo(PDO $pdo, string $codigo): bool
{
    $statement = $pdo->prepare(
        'SELECT 1 FROM areas_aprendizaje WHERE codigo = ? LIMIT 1'
    );
    $statement->execute([$codigo]);
    return $statement->fetchColumn() !== false;
}

function planAnualCodigoUnico(PDO $pdo, string $codigo, int $añoId): string
{
    $sufijo = '-A' . $añoId;
    $candidato = substr($codigo, 0, max(1, 20 - strlen($sufijo))) . $sufijo;
    $contador = 1;
    while (planAnualExisteCodigo($pdo, $candidato)) {
        $sufijo = '-A' . $añoId . '-' . $contador++;
        $candidato = substr($codigo, 0, max(1, 20 - strlen($sufijo))) . $sufijo;
    }

    return $candidato;
}

function planAnualObtenerAño(PDO $pdo, int $añoId): ?array
{
    $statement = $pdo->prepare('SELECT id, nombre, estado FROM años_escolares WHERE id = ?');
    $statement->execute([$añoId]);
    $año = $statement->fetch(PDO::FETCH_ASSOC);

    return $año ?: null;
}

function planAnualEsEditable(PDO $pdo, int $añoId): bool
{
    $año = planAnualObtenerAño($pdo, $añoId);
    return $año !== null && $año['estado'] === 'activo';
}

function planAnualMigrarCatalogosExistentes(PDO $pdo): void
{
    crearTablasPlanAnual($pdo);
    $statement = $pdo->prepare(
        'SELECT valor FROM plan_anual_meta WHERE clave = ? LIMIT 1'
    );
    $statement->execute(['catalogos_legacy_v1']);
    if ($statement->fetchColumn() !== false) {
        return;
    }

    $pdo->beginTransaction();
    try {
        if (databaseTableExists($pdo, 'años_escolares_areas')) {
            $legacyAreas = $pdo->query(
                'SELECT año_escolar_id, area_id FROM años_escolares_areas'
            )->fetchAll(PDO::FETCH_ASSOC);
            foreach ($legacyAreas as $legacyArea) {
                $statement = $pdo->prepare(
                    'SELECT 1 FROM plan_anual_areas
                     WHERE año_escolar_id = ? AND area_id = ? LIMIT 1'
                );
                $statement->execute([
                    (int) $legacyArea['año_escolar_id'],
                    (int) $legacyArea['area_id'],
                ]);
                if ($statement->fetchColumn() === false) {
                    planAnualAgregarArea(
                        $pdo,
                        (int) $legacyArea['año_escolar_id'],
                        (int) $legacyArea['area_id']
                    );
                }
            }
        }
        if (databaseTableExists($pdo, 'años_escolares_indicadores')) {
            $legacyIndicators = $pdo->query(
                'SELECT año_escolar_id, indicador_id FROM años_escolares_indicadores'
            )->fetchAll(PDO::FETCH_ASSOC);
            foreach ($legacyIndicators as $legacyIndicator) {
                $statement = $pdo->prepare(
                    'SELECT 1 FROM plan_anual_indicadores
                     WHERE año_escolar_id = ? AND indicador_id = ? LIMIT 1'
                );
                $statement->execute([
                    (int) $legacyIndicator['año_escolar_id'],
                    (int) $legacyIndicator['indicador_id'],
                ]);
                if ($statement->fetchColumn() === false) {
                    planAnualAgregarIndicador(
                        $pdo,
                        (int) $legacyIndicator['año_escolar_id'],
                        (int) $legacyIndicator['indicador_id']
                    );
                }
            }
        }

        $años = $pdo->query(
            "SELECT id FROM años_escolares
             WHERE estado IN ('activo', 'inactivo')
             ORDER BY id"
        )->fetchAll(PDO::FETCH_COLUMN);
        $areas = $pdo->query(
            "SELECT id FROM areas_aprendizaje
             WHERE estado = 'activa'
             ORDER BY id"
        )->fetchAll(PDO::FETCH_COLUMN);

        foreach ($años as $añoId) {
            $añoId = (int) $añoId;
            $statement = $pdo->prepare(
                'SELECT area_id FROM plan_anual_areas WHERE año_escolar_id = ?'
            );
            $statement->execute([$añoId]);
            $areaIds = array_map('intval', $statement->fetchAll(PDO::FETCH_COLUMN));
            if ($areaIds === []) {
                foreach ($areas as $areaId) {
                    planAnualAgregarArea($pdo, $añoId, (int) $areaId);
                    $areaIds[] = (int) $areaId;
                }
            }

            foreach ($areaIds as $areaId) {
                $statement = $pdo->prepare(
                    'SELECT 1 FROM plan_anual_indicadores pei
                     JOIN areas_indicadores ai ON ai.id = pei.indicador_id
                     WHERE pei.año_escolar_id = ? AND ai.area_id = ? LIMIT 1'
                );
                $statement->execute([$añoId, $areaId]);
                if ($statement->fetchColumn() !== false) {
                    continue;
                }
                $statement = $pdo->prepare(
                    "SELECT id FROM areas_indicadores
                     WHERE area_id = ? AND estado = 'activo'
                     ORDER BY orden, id"
                );
                $statement->execute([$areaId]);
                foreach ($statement->fetchAll(PDO::FETCH_COLUMN) as $indicadorId) {
                    planAnualAgregarIndicador($pdo, $añoId, (int) $indicadorId);
                }
            }
        }

        $upsert = databaseUpsertClause($pdo, ['clave'], ['valor']);
        $statement = $pdo->prepare(
            'INSERT INTO plan_anual_meta (clave, valor) VALUES (?, ?) ' . $upsert
        );
        $statement->execute(['catalogos_legacy_v1', 'done']);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function planAnualObtenerAreas(PDO $pdo, int $añoId, ?string $nivel = null): array
{
    crearTablasPlanAnual($pdo);
    $sql = 'SELECT aa.id, aa.codigo, aa.nombre, aa.descripcion, aa.nivel, aa.estado
            FROM plan_anual_areas pea
            JOIN areas_aprendizaje aa ON aa.id = pea.area_id
            WHERE pea.año_escolar_id = ?';
    $parameters = [$añoId];
    if ($nivel !== null) {
        $sql .= ' AND aa.nivel = ?';
        $parameters[] = $nivel;
    }
    $sql .= ' ORDER BY aa.nombre, aa.id';

    $statement = $pdo->prepare($sql);
    $statement->execute($parameters);
    return $statement->fetchAll(PDO::FETCH_ASSOC);
}

function planAnualObtenerIndicadores(PDO $pdo, int $añoId, int $areaId): array
{
    crearTablasPlanAnual($pdo);
    $statement = $pdo->prepare(
        'SELECT ai.id, ai.area_id, ai.nombre, ai.descripcion, ai.escala, ai.orden, ai.estado
         FROM plan_anual_indicadores pei
         JOIN areas_indicadores ai ON ai.id = pei.indicador_id
         JOIN plan_anual_areas pea
           ON pea.año_escolar_id = pei.año_escolar_id AND pea.area_id = ai.area_id
         WHERE pei.año_escolar_id = ? AND ai.area_id = ?
         ORDER BY ai.orden, ai.id'
    );
    $statement->execute([$añoId, $areaId]);
    return $statement->fetchAll(PDO::FETCH_ASSOC);
}

function planAnualTieneHistorial(PDO $pdo, int $añoId): bool
{
    if (!databaseTableExists($pdo, 'historial_academico') ||
        !databaseColumnExists($pdo, 'historial_academico', 'año_escolar_id')) {
        return false;
    }

    $statement = $pdo->prepare(
        'SELECT 1 FROM historial_academico WHERE año_escolar_id = ? LIMIT 1'
    );
    $statement->execute([$añoId]);
    return $statement->fetchColumn() !== false;
}

function planAnualReemplazarArea(PDO $pdo, int $añoId, int $areaAnterior, int $areaNueva): void
{
    $pdo->prepare(
        'DELETE FROM plan_anual_areas
         WHERE año_escolar_id = ? AND area_id = ?'
    )->execute([$añoId, $areaAnterior]);
    planAnualAgregarArea($pdo, $añoId, $areaNueva);

    if (databaseTableExists($pdo, 'horarios_asignaciones') &&
        databaseColumnExists($pdo, 'horarios_asignaciones', 'año_escolar_id') &&
        databaseColumnExists($pdo, 'horarios_asignaciones', 'area_id')) {
        $pdo->prepare(
            'UPDATE horarios_asignaciones
             SET area_id = ? WHERE año_escolar_id = ? AND area_id = ?'
        )->execute([$areaNueva, $añoId, $areaAnterior]);
    }

    if (databaseTableExists($pdo, 'indicadores_seccion') &&
        databaseColumnExists($pdo, 'indicadores_seccion', 'seccion_id') &&
        databaseColumnExists($pdo, 'indicadores_seccion', 'area_id')) {
        $pdo->prepare(
            'UPDATE indicadores_seccion
             SET area_id = ?
             WHERE area_id = ? AND seccion_id IN (
                 SELECT id FROM secciones WHERE año_escolar_id = ?
             )'
        )->execute([$areaNueva, $areaAnterior, $añoId]);
    }
}

function planAnualReemplazarIndicador(
    PDO $pdo,
    int $añoId,
    int $indicadorAnterior,
    int $indicadorNuevo
): void {
    $pdo->prepare(
        'DELETE FROM plan_anual_indicadores
         WHERE año_escolar_id = ? AND indicador_id = ?'
    )->execute([$añoId, $indicadorAnterior]);
    planAnualAgregarIndicador($pdo, $añoId, $indicadorNuevo);

    if (databaseTableExists($pdo, 'historial_indicadores') &&
        databaseTableExists($pdo, 'historial_academico') &&
        databaseColumnExists($pdo, 'historial_academico', 'año_escolar_id')) {
        $statement = $pdo->prepare(
            'UPDATE historial_indicadores
             SET indicador_id = ?
             WHERE indicador_id = ? AND historial_id IN (
                 SELECT id FROM historial_academico WHERE año_escolar_id = ?
             )'
        );
        $statement->execute([$indicadorNuevo, $indicadorAnterior, $añoId]);
    }
}

function planAnualCopiarPlan(PDO $pdo, int $añoOrigen, int $añoDestino): void
{
    if ($añoOrigen < 1 || $añoDestino < 1 || $añoOrigen === $añoDestino) {
        throw new InvalidArgumentException('Seleccione dos años escolares diferentes.');
    }
    crearTablasPlanAnual($pdo);
    if (!planAnualObtenerAño($pdo, $añoOrigen) ||
        !planAnualObtenerAño($pdo, $añoDestino)) {
        throw new RuntimeException('Uno de los años seleccionados no existe.');
    }
    if (planAnualTieneHistorial($pdo, $añoDestino)) {
        throw new RuntimeException(
            'No se puede reemplazar el plan de un año que ya tiene historial académico.'
        );
    }

    $pdo->beginTransaction();
    try {
        $pdo->prepare(
            'DELETE FROM plan_anual_areas WHERE año_escolar_id = ?'
        )->execute([$añoDestino]);
        $pdo->prepare(
            'DELETE FROM plan_anual_indicadores WHERE año_escolar_id = ?'
        )->execute([$añoDestino]);

        $statement = $pdo->prepare(
            'SELECT aa.*
             FROM plan_anual_areas pea
             JOIN areas_aprendizaje aa ON aa.id = pea.area_id
             WHERE pea.año_escolar_id = ?
             ORDER BY aa.id'
        );
        $statement->execute([$añoOrigen]);
        $areaIds = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $area) {
            $areaId = planAnualInsertarArea($pdo, $area, $añoDestino);
            $areaIds[(int) $area['id']] = $areaId;
            planAnualAgregarArea($pdo, $añoDestino, $areaId);
        }

        $statement = $pdo->prepare(
            'SELECT ai.*
             FROM plan_anual_indicadores pei
             JOIN areas_indicadores ai ON ai.id = pei.indicador_id
             WHERE pei.año_escolar_id = ?
             ORDER BY ai.id'
        );
        $statement->execute([$añoOrigen]);
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $indicador) {
            $areaIdOriginal = (int) $indicador['area_id'];
            if (!isset($areaIds[$areaIdOriginal])) {
                continue;
            }
            $indicadorId = planAnualInsertarIndicador(
                $pdo,
                $indicador,
                $areaIds[$areaIdOriginal],
                $añoDestino
            );
            planAnualAgregarIndicador($pdo, $añoDestino, $indicadorId);
        }

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function planAnualAislarCatalogo(PDO $pdo, int $añoId): array
{
    if (!planAnualEsEditable($pdo, $añoId)) {
        throw new RuntimeException('Solo se puede modificar el plan del año escolar activo.');
    }
    crearTablasPlanAnual($pdo);

    $areasClonadas = [];
    $indicadoresClonados = [];
    $pdo->beginTransaction();
    try {
        $statement = $pdo->prepare(
            'SELECT aa.*
             FROM plan_anual_areas pea
             JOIN areas_aprendizaje aa ON aa.id = pea.area_id
             WHERE pea.año_escolar_id = ?
             ORDER BY aa.id'
        );
        $statement->execute([$añoId]);
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $area) {
            $areaIdAnterior = (int) $area['id'];
            $statementCompartido = $pdo->prepare(
                'SELECT 1 FROM plan_anual_areas
                 WHERE area_id = ? AND año_escolar_id <> ? LIMIT 1'
            );
            $statementCompartido->execute([$areaIdAnterior, $añoId]);
            if ($statementCompartido->fetchColumn() === false) {
                continue;
            }

            $areaIdNuevo = planAnualInsertarArea($pdo, $area, $añoId);
            $areasClonadas[$areaIdAnterior] = $areaIdNuevo;
            planAnualReemplazarArea($pdo, $añoId, $areaIdAnterior, $areaIdNuevo);

            $statementIndicadores = $pdo->prepare(
                'SELECT ai.*
                 FROM plan_anual_indicadores pei
                 JOIN areas_indicadores ai ON ai.id = pei.indicador_id
                 WHERE pei.año_escolar_id = ? AND ai.area_id = ?
                 ORDER BY ai.id'
            );
            $statementIndicadores->execute([$añoId, $areaIdAnterior]);
            foreach ($statementIndicadores->fetchAll(PDO::FETCH_ASSOC) as $indicador) {
                $indicadorIdAnterior = (int) $indicador['id'];
                $indicadorIdNuevo = planAnualInsertarIndicador(
                    $pdo,
                    $indicador,
                    $areaIdNuevo,
                    $añoId
                );
                $indicadoresClonados[$indicadorIdAnterior] = $indicadorIdNuevo;
                planAnualReemplazarIndicador(
                    $pdo,
                    $añoId,
                    $indicadorIdAnterior,
                    $indicadorIdNuevo
                );
            }
        }

        $statement = $pdo->prepare(
            'SELECT ai.*
             FROM plan_anual_indicadores pei
             JOIN areas_indicadores ai ON ai.id = pei.indicador_id
             WHERE pei.año_escolar_id = ?
             ORDER BY ai.id'
        );
        $statement->execute([$añoId]);
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $indicador) {
            $indicadorIdAnterior = (int) $indicador['id'];
            $statementCompartido = $pdo->prepare(
                'SELECT 1 FROM plan_anual_indicadores
                 WHERE indicador_id = ? AND año_escolar_id <> ? LIMIT 1'
            );
            $statementCompartido->execute([$indicadorIdAnterior, $añoId]);
            if ($statementCompartido->fetchColumn() === false) {
                continue;
            }

            $indicadorIdNuevo = planAnualInsertarIndicador(
                $pdo,
                $indicador,
                $areasClonadas[(int) $indicador['area_id']] ?? (int) $indicador['area_id'],
                $añoId
            );
            $indicadoresClonados[$indicadorIdAnterior] = $indicadorIdNuevo;
            planAnualReemplazarIndicador(
                $pdo,
                $añoId,
                $indicadorIdAnterior,
                $indicadorIdNuevo
            );
        }

        $pdo->commit();
        return [
            'areas' => $areasClonadas,
            'indicadores' => $indicadoresClonados,
        ];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function planAnualAreaAsignada(PDO $pdo, int $añoId, int $areaId): bool
{
    $statement = $pdo->prepare(
        'SELECT 1 FROM plan_anual_areas
         WHERE año_escolar_id = ? AND area_id = ? LIMIT 1'
    );
    $statement->execute([$añoId, $areaId]);
    return $statement->fetchColumn() !== false;
}

function planAnualIndicadorAsignado(PDO $pdo, int $añoId, int $indicadorId): bool
{
    $statement = $pdo->prepare(
        'SELECT 1 FROM plan_anual_indicadores
         WHERE año_escolar_id = ? AND indicador_id = ? LIMIT 1'
    );
    $statement->execute([$añoId, $indicadorId]);
    return $statement->fetchColumn() !== false;
}
