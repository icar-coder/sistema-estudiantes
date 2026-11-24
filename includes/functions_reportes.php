<?php

require_once dirname(__DIR__) . '/includes/database_helpers.php';

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
