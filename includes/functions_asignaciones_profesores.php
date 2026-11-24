<?php

function guardarAsignacionProfesorAGrado(
    PDO $pdo,
    int $añoEscolarId,
    int $gradoId,
    int $profesorId
): array {
    $añoValido = $pdo->prepare(
        "SELECT 1 FROM `años_escolares` WHERE `id` = ? AND `estado` = 'activo'"
    );
    $añoValido->execute([$añoEscolarId]);
    if ($añoValido->fetchColumn() === false) {
        throw new InvalidArgumentException('El año escolar no existe o no está activo.');
    }

    $gradoValido = $pdo->prepare(
        "SELECT 1 FROM `grados` WHERE `id` = ? AND `estado` = 'activo'"
    );
    $gradoValido->execute([$gradoId]);
    if ($gradoValido->fetchColumn() === false) {
        throw new InvalidArgumentException('El grado seleccionado no existe o no está activo.');
    }

    $profesorValido = $pdo->prepare(
        "SELECT 1 FROM `profesores` WHERE `id` = ? AND `estado` = 'activo'"
    );
    $profesorValido->execute([$profesorId]);
    if ($profesorValido->fetchColumn() === false) {
        throw new InvalidArgumentException('Solo se puede asignar un profesor activo.');
    }

    $totalQuery = $pdo->prepare(
        'SELECT COUNT(*) FROM `secciones`
         WHERE `año_escolar_id` = ? AND `grado_id` = ?'
    );
    $totalQuery->execute([$añoEscolarId, $gradoId]);
    $totalSecciones = (int) $totalQuery->fetchColumn();
    if ($totalSecciones === 0) {
        throw new InvalidArgumentException('No hay secciones creadas para ese grado en el año seleccionado.');
    }

    $cambiosQuery = $pdo->prepare(
        'SELECT COUNT(*) FROM `secciones`
         WHERE `año_escolar_id` = ? AND `grado_id` = ?
           AND (`profesor_id` IS NULL OR `profesor_id` <> ?)'
    );
    $cambiosQuery->execute([$añoEscolarId, $gradoId, $profesorId]);
    $seccionesActualizadas = (int) $cambiosQuery->fetchColumn();

    $actualizar = $pdo->prepare(
        'UPDATE `secciones` SET `profesor_id` = ?
         WHERE `año_escolar_id` = ? AND `grado_id` = ?'
    );
    $actualizar->execute([$profesorId, $añoEscolarId, $gradoId]);

    return [
        'secciones' => $totalSecciones,
        'actualizadas' => $seccionesActualizadas,
    ];
}

function guardarAsignacionProfesorSeccion(
    PDO $pdo,
    int $añoEscolarId,
    int $gradoId,
    int $seccionId,
    ?int $profesorId
): void {
    $añoValido = $pdo->prepare(
        "SELECT 1 FROM `años_escolares` WHERE `id` = ? AND `estado` = 'activo'"
    );
    $añoValido->execute([$añoEscolarId]);
    if ($añoValido->fetchColumn() === false) {
        throw new InvalidArgumentException('El año escolar no existe o no está activo.');
    }

    $seccionValida = $pdo->prepare(
        'SELECT `profesor_id` FROM `secciones`
         WHERE `id` = ? AND `año_escolar_id` = ? AND `grado_id` = ?'
    );
    $seccionValida->execute([$seccionId, $añoEscolarId, $gradoId]);
    $profesorActual = $seccionValida->fetchColumn();
    if ($profesorActual === false) {
        throw new InvalidArgumentException('La sección no pertenece al grado y año seleccionados.');
    }

    if ($profesorId !== null) {
        $profesorValido = $pdo->prepare(
            "SELECT 1 FROM `profesores` WHERE `id` = ? AND `estado` = 'activo'"
        );
        $profesorValido->execute([$profesorId]);
        if ($profesorValido->fetchColumn() === false &&
            (string) $profesorActual !== (string) $profesorId) {
            throw new InvalidArgumentException('Solo se puede asignar un profesor activo.');
        }
    }

    $actualizar = $pdo->prepare(
        'UPDATE `secciones` SET `profesor_id` = ? WHERE `id` = ?'
    );
    $actualizar->execute([$profesorId, $seccionId]);
}
