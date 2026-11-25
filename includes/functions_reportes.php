<?php

require_once dirname(__DIR__) . '/includes/database_helpers.php';

function obtenerEstadisticasLiteralesFinales(
    PDO $pdo,
    int $añoEscolarId,
    ?int $seccionId = null
): array {
    $condicionesInscripciones = ['i.`año_escolar_id` = ?'];
    $parametros = [$añoEscolarId];
    if ($seccionId !== null) {
        $condicionesInscripciones[] = 'i.`seccion_id` = ?';
        $parametros[] = $seccionId;
    }

    $condicionesHistoricas = [
        'ha.`año_escolar_id` = ?',
        'NOT EXISTS (SELECT 1 FROM `inscripciones` i
                     WHERE i.`estudiante_id` = ha.`estudiante_id`
                       AND i.`año_escolar_id` = ha.`año_escolar_id`)',
    ];
    $parametros[] = $añoEscolarId;
    if ($seccionId !== null) {
        $condicionesHistoricas[] = '1 = 0';
    }

    $statement = $pdo->prepare(
        'SELECT e.`nombre`, e.`apellido`,
                COALESCE(s.`nombre`, \'Historial académico\') AS `seccion`,
                finales.`literal_final` AS `literal`
         FROM (
             SELECT i.`estudiante_id`, i.`seccion_id`, lf.`literal_final`
             FROM `literales_finales` lf
             JOIN `inscripciones` i ON i.`id` = lf.`inscripcion_id`
             WHERE ' . implode(' AND ', $condicionesInscripciones) . '
             UNION ALL
             SELECT ha.`estudiante_id`, NULL AS `seccion_id`, ha.`literal_final`
             FROM `historial_academico` ha
             WHERE ' . implode(' AND ', $condicionesHistoricas) . '
         ) finales
         JOIN `estudiantes` e ON e.`id` = finales.`estudiante_id`
         LEFT JOIN `secciones` s ON s.`id` = finales.`seccion_id`
         WHERE finales.`literal_final` IN (\'A\', \'B\', \'C\', \'D\', \'E\')
         ORDER BY e.`apellido`, e.`nombre`'
    );
    $statement->execute($parametros);

    $conteos = [];
    $destacados = [];
    foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $registro) {
        $literal = (string) $registro['literal'];
        $conteos[$literal] = ($conteos[$literal] ?? 0) + 1;
        if (in_array($literal, ['A', 'B'], true)) {
            $destacados[] = $registro;
        }
    }
    ksort($conteos, SORT_STRING);
    usort($destacados, static function (array $a, array $b): int {
        return strcmp((string) $a['literal'], (string) $b['literal']) ?:
            strcmp((string) $a['apellido'], (string) $b['apellido']) ?:
            strcmp((string) $a['nombre'], (string) $b['nombre']);
    });

    $literalesFinales = [];
    foreach ($conteos as $literal => $cantidad) {
        $literalesFinales[] = ['literal' => $literal, 'cantidad' => $cantidad];
    }
    return [
        'literales_finales' => $literalesFinales,
        'top_estudiantes' => array_slice($destacados, 0, 15),
    ];
}

function obtenerResumenIndicadores(
    PDO $pdo,
    int $añoEscolarId,
    ?int $seccionId = null,
    ?int $momentoId = null
): array {
    $tieneMomento = databaseColumnExists($pdo, 'historial_indicadores', 'momento');
    if ($momentoId !== null && !$tieneMomento) {
        throw new InvalidArgumentException('El esquema no permite filtrar indicadores por momento.');
    }

    $condiciones = [
        'ha.`año_escolar_id` = ?',
        'hi.`valoracion` IN (\'LE\', \'L\', \'PA\', \'IP\', \'NL\')',
    ];
    $parametros = [$añoEscolarId];
    if ($seccionId !== null) {
        $condiciones[] =
            'EXISTS (SELECT 1 FROM `inscripciones` i
                     WHERE i.`estudiante_id` = ha.`estudiante_id`
                       AND i.`año_escolar_id` = ha.`año_escolar_id`
                       AND i.`seccion_id` = ?)';
        $parametros[] = $seccionId;
    }
    if ($momentoId !== null) {
        $condiciones[] = 'hi.`momento` = ?';
        $parametros[] = $momentoId;
    }

    $statement = $pdo->prepare(
        'SELECT aa.`nombre` AS `area`,
                COALESCE(NULLIF(ai.`descripcion`, \'\'), ai.`nombre`) AS `indicador`,
                COUNT(CASE WHEN hi.`valoracion` = \'LE\' THEN 1 END) AS `le`,
                COUNT(CASE WHEN hi.`valoracion` = \'L\' THEN 1 END) AS `l`,
                COUNT(CASE WHEN hi.`valoracion` = \'PA\' THEN 1 END) AS `pa`,
                COUNT(CASE WHEN hi.`valoracion` = \'IP\' THEN 1 END) AS `ip`,
                COUNT(CASE WHEN hi.`valoracion` = \'NL\' THEN 1 END) AS `nl`,
                COUNT(*) AS `total`
         FROM `historial_academico` ha
         JOIN `historial_indicadores` hi ON hi.`historial_id` = ha.`id`
         JOIN `plan_anual_indicadores` pai
           ON pai.`año_escolar_id` = ha.`año_escolar_id`
          AND pai.`indicador_id` = hi.`indicador_id`
         JOIN `areas_indicadores` ai ON ai.`id` = hi.`indicador_id`
         JOIN `areas_aprendizaje` aa ON aa.`id` = ai.`area_id`
         WHERE ' . implode(' AND ', $condiciones) . '
         GROUP BY aa.`id`, aa.`nombre`, ai.`id`, ai.`nombre`, ai.`descripcion`
         ORDER BY aa.`nombre`, ai.`orden`, ai.`id`'
    );
    $statement->execute($parametros);
    return $statement->fetchAll(PDO::FETCH_ASSOC);
}

function obtenerReporteLiterales(
    PDO $pdo,
    int $añoEscolarId,
    ?int $lapsoId,
    ?int $seccionId,
    string $busqueda,
    bool $mostrarIndicadores = false,
    ?int $momentoId = null
): array {
    $condiciones = ['i.`año_escolar_id` = ?'];
    $parametros = [$añoEscolarId];

    if ($seccionId !== null) {
        $condiciones[] = 'i.`seccion_id` = ?';
        $parametros[] = $seccionId;
    }

    if ($busqueda !== '') {
        $condiciones[] = '(e.`nombre` LIKE ? OR e.`apellido` LIKE ? OR e.`cedula_escolar` LIKE ?)';
        $termino = '%' . $busqueda . '%';
        array_push($parametros, $termino, $termino, $termino);
    }

    $where = implode(' AND ', $condiciones);
    $statement = $pdo->prepare(
        'SELECT i.`id` AS `inscripcion_id`, i.`status`, e.`id` AS `estudiante_id`, e.`cedula_escolar`,
                e.`nombre`, e.`apellido`, s.`nombre` AS `seccion`,
                (
                    SELECT lf.`literal_final`
                    FROM `literales_finales` lf
                    WHERE lf.`inscripcion_id` = i.`id`
                      AND lf.`año_escolar_id` = i.`año_escolar_id`
                    ORDER BY lf.`id` DESC
                    LIMIT 1
                ) AS `literal_final`
         FROM `inscripciones` i
         JOIN `estudiantes` e ON e.`id` = i.`estudiante_id`
         LEFT JOIN `secciones` s ON s.`id` = i.`seccion_id`
         WHERE ' . $where . '
         ORDER BY s.`nombre`, e.`apellido`, e.`nombre`, i.`id`'
    );
    $statement->execute($parametros);
    $estudiantes = $statement->fetchAll(PDO::FETCH_ASSOC);

    if (databaseTableExists($pdo, 'historial_academico')) {
        $condicionesHistoricos = ['ha.`año_escolar_id` = ?'];
        $parametrosHistoricos = [$añoEscolarId];
        $condicionesHistoricos[] =
            'NOT EXISTS (SELECT 1 FROM `inscripciones` hi
                         WHERE hi.`estudiante_id` = ha.`estudiante_id`
                           AND hi.`año_escolar_id` = ha.`año_escolar_id`)';

        if ($seccionId !== null) {
            $condicionesHistoricos[] = '1 = 0';
        }
        if ($busqueda !== '') {
            $condicionesHistoricos[] = '(e.`nombre` LIKE ? OR e.`apellido` LIKE ? OR e.`cedula_escolar` LIKE ?)';
            $termino = '%' . $busqueda . '%';
            array_push($parametrosHistoricos, $termino, $termino, $termino);
        }

        $statement = $pdo->prepare(
            'SELECT NULL AS `inscripcion_id`, \'graduado\' AS `status`,
                    e.`id` AS `estudiante_id`, e.`cedula_escolar`,
                    e.`nombre`, e.`apellido`, \'Historial académico\' AS `seccion`,
                    ha.`literal_final`
             FROM `historial_academico` ha
             JOIN `estudiantes` e ON e.`id` = ha.`estudiante_id`
             WHERE ' . implode(' AND ', $condicionesHistoricos) . '
             ORDER BY e.`apellido`, e.`nombre`, ha.`id`'
        );
        $statement->execute($parametrosHistoricos);
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $historico) {
            $estudiantes[] = $historico;
        }

        usort($estudiantes, static function (array $a, array $b): int {
            return strcmp(
                (string) ($a['seccion'] ?? ''),
                (string) ($b['seccion'] ?? '')
            ) ?: strcmp(
                (string) ($a['apellido'] ?? ''),
                (string) ($b['apellido'] ?? '')
            );
        });
    }

    $statement = $pdo->prepare(
        'SELECT `id`, `nombre`, `numero`
         FROM `lapsos`
         WHERE `año_escolar_id` = ?' .
            ($lapsoId !== null ? ' AND `id` = ?' : '') .
        ' ORDER BY `numero`, `id`'
    );
    $parametrosLapsos = [$añoEscolarId];
    if ($lapsoId !== null) {
        $parametrosLapsos[] = $lapsoId;
    }
    $statement->execute($parametrosLapsos);
    $lapsos = $statement->fetchAll(PDO::FETCH_ASSOC);

    $condicionesEvaluaciones = $condiciones;
    $parametrosEvaluaciones = $parametros;
    $condicionesEvaluaciones[] = 'l.`año_escolar_id` = ?';
    $parametrosEvaluaciones[] = $añoEscolarId;
    if ($lapsoId !== null) {
        $condicionesEvaluaciones[] = 'll.`lapso_id` = ?';
        $parametrosEvaluaciones[] = $lapsoId;
    }

    $statement = $pdo->prepare(
        'SELECT ll.`inscripcion_id`, ll.`lapso_id`, ll.`literal`,
                ll.`observaciones`, ll.`fecha_evaluacion`
         FROM `literales_lapso` ll
         JOIN `lapsos` l ON l.`id` = ll.`lapso_id`
         JOIN `inscripciones` i ON i.`id` = ll.`inscripcion_id`
         JOIN `estudiantes` e ON e.`id` = i.`estudiante_id`
         WHERE ' . implode(' AND ', $condicionesEvaluaciones) . '
         ORDER BY ll.`inscripcion_id`, l.`numero`, ll.`id`'
    );
    $statement->execute($parametrosEvaluaciones);

    $evaluaciones = [];
    foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $evaluacion) {
        $evaluaciones[(int) $evaluacion['inscripcion_id']][(int) $evaluacion['lapso_id']] = $evaluacion;
    }

    $indicadores = [];
    if ($mostrarIndicadores &&
        databaseTableExists($pdo, 'historial_academico') &&
        databaseTableExists($pdo, 'historial_indicadores') &&
        databaseTableExists($pdo, 'areas_indicadores') &&
        databaseTableExists($pdo, 'areas_aprendizaje')) {
        $condicionesIndicadores = $condiciones;
        $parametrosIndicadores = $parametros;
        $tieneMomento = databaseColumnExists($pdo, 'historial_indicadores', 'momento');
        if ($tieneMomento && $momentoId !== null) {
            $condicionesIndicadores[] = 'hi.`momento` = ?';
            $parametrosIndicadores[] = $momentoId;
        }

        $momentoSeleccion = $tieneMomento ? 'hi.`momento`' : '1';
        $statement = $pdo->prepare(
            'SELECT i.`id` AS `inscripcion_id`, i.`estudiante_id`,
                    aa.`nombre` AS `area_nombre`,
                    ai.`nombre` AS `indicador_nombre`, ai.`descripcion` AS `indicador_descripcion`,
                    hi.`valoracion`, ' . $momentoSeleccion . ' AS `momento`
             FROM `inscripciones` i
             JOIN `estudiantes` e ON e.`id` = i.`estudiante_id`
             JOIN `historial_academico` ha
               ON ha.`estudiante_id` = i.`estudiante_id`
              AND ha.`año_escolar_id` = i.`año_escolar_id`
             JOIN `historial_indicadores` hi ON hi.`historial_id` = ha.`id`
             JOIN `areas_indicadores` ai ON ai.`id` = hi.`indicador_id`
             JOIN `areas_aprendizaje` aa ON aa.`id` = ai.`area_id`
             LEFT JOIN `secciones` s ON s.`id` = i.`seccion_id`
             WHERE ' . implode(' AND ', $condicionesIndicadores) . '
             ORDER BY i.`id`, aa.`nombre`, ai.`orden`, ai.`id`'
        );
        $statement->execute($parametrosIndicadores);
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $indicador) {
            $indicadores[(int) $indicador['estudiante_id']][] = $indicador;
        }

        $condicionesHistoricos = ['ha.`año_escolar_id` = ?'];
        $parametrosHistoricos = [$añoEscolarId];
        $condicionesHistoricos[] =
            'NOT EXISTS (SELECT 1 FROM `inscripciones` i2
                         WHERE i2.`estudiante_id` = ha.`estudiante_id`
                           AND i2.`año_escolar_id` = ha.`año_escolar_id`)';
        if ($seccionId !== null) {
            $condicionesHistoricos[] = '1 = 0';
        }
        if ($busqueda !== '') {
            $condicionesHistoricos[] = '(e.`nombre` LIKE ? OR e.`apellido` LIKE ? OR e.`cedula_escolar` LIKE ?)';
            $termino = '%' . $busqueda . '%';
            array_push($parametrosHistoricos, $termino, $termino, $termino);
        }
        if ($tieneMomento && $momentoId !== null) {
            $condicionesHistoricos[] = 'hi.`momento` = ?';
            $parametrosHistoricos[] = $momentoId;
        }

        $statement = $pdo->prepare(
            'SELECT e.`id` AS `estudiante_id`, aa.`nombre` AS `area_nombre`,
                    ai.`nombre` AS `indicador_nombre`, ai.`descripcion` AS `indicador_descripcion`,
                    hi.`valoracion`, ' . $momentoSeleccion . ' AS `momento`
             FROM `historial_academico` ha
             JOIN `estudiantes` e ON e.`id` = ha.`estudiante_id`
             JOIN `historial_indicadores` hi ON hi.`historial_id` = ha.`id`
             JOIN `areas_indicadores` ai ON ai.`id` = hi.`indicador_id`
             JOIN `areas_aprendizaje` aa ON aa.`id` = ai.`area_id`
             WHERE ' . implode(' AND ', $condicionesHistoricos) . '
             ORDER BY e.`id`, aa.`nombre`, ai.`orden`, ai.`id`'
        );
        $statement->execute($parametrosHistoricos);
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $indicador) {
            $indicadores[(int) $indicador['estudiante_id']][] = $indicador;
        }
    }

    return [
        'lapsos' => $lapsos,
        'estudiantes' => $estudiantes,
        'evaluaciones' => $evaluaciones,
        'indicadores' => $indicadores,
    ];
}
