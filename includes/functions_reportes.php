<?php

function obtenerReporteLiterales(
    PDO $pdo,
    int $añoEscolarId,
    ?int $lapsoId,
    ?int $seccionId,
    string $busqueda
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
        'SELECT i.`id` AS `inscripcion_id`, i.`status`, e.`cedula_escolar`,
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

    return [
        'lapsos' => $lapsos,
        'estudiantes' => $estudiantes,
        'evaluaciones' => $evaluaciones,
    ];
}
