<?php

function validarSeccionAsistenciaAdmin(PDO $pdo, int $añoEscolarId, int $seccionId): void
{
    $seccion = $pdo->prepare(
        'SELECT 1 FROM `secciones` WHERE `id` = ? AND `año_escolar_id` = ? LIMIT 1'
    );
    $seccion->execute([$seccionId, $añoEscolarId]);
    if ($seccion->fetchColumn() === false) {
        throw new InvalidArgumentException('La sección no pertenece al año escolar seleccionado.');
    }
}

function obtenerListaAsistenciaAdmin(PDO $pdo, int $añoEscolarId, int $seccionId, string $fecha): array
{
    validarSeccionAsistenciaAdmin($pdo, $añoEscolarId, $seccionId);

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
    $cambios = [];
    foreach ($estudiantes as $estudiante) {
        $inscripcionId = (int) $estudiante['inscripcion_id'];
        if (!array_key_exists($inscripcionId, $asistencias)) {
            continue;
        }

        $cambios[] = [
            'inscripcion_id' => $inscripcionId,
            'fecha' => $fecha,
            'estado' => $asistencias[$inscripcionId],
            'observaciones' => $observaciones[$inscripcionId] ?? '',
        ];
    }

    return guardarCambiosAsistenciaAdmin($pdo, $cambios, $registradoPor);
}

function guardarCambiosAsistenciaAdmin(PDO $pdo, array $cambios, ?int $registradoPor): int
{
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
        foreach ($cambios as $cambio) {
            $inscripcionId = filter_var(
                $cambio['inscripcion_id'] ?? null,
                FILTER_VALIDATE_INT,
                ['options' => ['min_range' => 1]]
            );
            $fecha = $cambio['fecha'] ?? null;
            $estado = $cambio['estado'] ?? null;
            $observacion = $cambio['observaciones'] ?? '';
            if ($inscripcionId === false || !is_string($fecha) ||
                !esFechaAsistenciaValida($fecha) || !is_string($estado) ||
                !is_string($observacion)) {
                throw new InvalidArgumentException('Los datos de asistencia enviados no son válidos.');
            }

            if ($estado === '') {
                $eliminar->execute([$inscripcionId, $fecha]);
                $procesados++;
                continue;
            }
            if (!in_array($estado, ['presente', 'ausente', 'justificado'], true)) {
                throw new InvalidArgumentException('El estado de asistencia seleccionado no es válido.');
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
