<?php

function obtenerListaAsistenciaAdmin(PDO $pdo, int $añoEscolarId, int $seccionId, string $fecha): array
{
    $seccion = $pdo->prepare(
        'SELECT 1 FROM `secciones` WHERE `id` = ? AND `año_escolar_id` = ? LIMIT 1'
    );
    $seccion->execute([$seccionId, $añoEscolarId]);
    if ($seccion->fetchColumn() === false) {
        throw new InvalidArgumentException('La sección no pertenece al año escolar seleccionado.');
    }

    $statement = $pdo->prepare(
        'SELECT i.`id` AS `inscripcion_id`, e.`cedula_escolar`,
                e.`nombre`, e.`apellido`, a.`estado`, a.`observaciones`
         FROM `inscripciones` i
         JOIN `estudiantes` e ON e.`id` = i.`estudiante_id`
         LEFT JOIN `asistencias` a
            ON a.`inscripcion_id` = i.`id` AND a.`fecha` = ?
         WHERE i.`año_escolar_id` = ?
           AND i.`seccion_id` = ?
           AND i.`status` = ?
         ORDER BY e.`apellido`, e.`nombre`, i.`id`'
    );
    $statement->execute([$fecha, $añoEscolarId, $seccionId, 'activo']);

    return $statement->fetchAll(PDO::FETCH_ASSOC);
}

function guardarListaAsistenciaAdmin(
    PDO $pdo,
    int $añoEscolarId,
    int $seccionId,
    string $fecha,
    array $asistencias,
    array $observaciones,
    ?int $registradoPor
): int {
    $estudiantes = obtenerListaAsistenciaAdmin($pdo, $añoEscolarId, $seccionId, $fecha);
    $guardar = $pdo->prepare(
        'INSERT INTO `asistencias`
            (`inscripcion_id`, `fecha`, `estado`, `observaciones`, `registrado_por`)
         VALUES (?, ?, ?, ?, ?)' .
            databaseUpsertClause(
                $pdo,
                ['inscripcion_id', 'fecha'],
                ['estado', 'observaciones', 'registrado_por'],
                ['created_at']
            )
    );
    $eliminar = $pdo->prepare(
        'DELETE FROM `asistencias` WHERE `inscripcion_id` = ? AND `fecha` = ?'
    );

    $procesados = 0;
    $pdo->beginTransaction();
    try {
        foreach ($estudiantes as $estudiante) {
            $inscripcionId = (int) $estudiante['inscripcion_id'];
            if (!array_key_exists($inscripcionId, $asistencias)) {
                continue;
            }

            $estado = $asistencias[$inscripcionId];
            if (!is_string($estado)) {
                throw new InvalidArgumentException('El estado de asistencia no es válido.');
            }

            if ($estado === '') {
                $eliminar->execute([$inscripcionId, $fecha]);
                $procesados++;
                continue;
            }
            if (!in_array($estado, ['presente', 'ausente', 'justificado'], true)) {
                throw new InvalidArgumentException('El estado de asistencia seleccionado no es válido.');
            }

            $observacion = $observaciones[$inscripcionId] ?? '';
            if (!is_string($observacion)) {
                throw new InvalidArgumentException('La observación de asistencia no es válida.');
            }

            $guardar->execute([
                $inscripcionId,
                $fecha,
                $estado,
                trim($observacion),
                $registradoPor,
            ]);
            $procesados++;
        }

        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }

    return $procesados;
}

function esFechaAsistenciaValida(string $fecha): bool
{
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha)) {
        return false;
    }

    $fechaParseada = DateTimeImmutable::createFromFormat('!Y-m-d', $fecha);
    return $fechaParseada !== false && $fechaParseada->format('Y-m-d') === $fecha;
}
