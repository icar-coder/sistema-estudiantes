<?php

function generarUuidV4(): string
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

function obtenerGradoSeccion(PDO $pdo, int $gradoId): array
{
    $stmt = $pdo->prepare("
        SELECT id, numero, nombre, nivel
        FROM grados
        WHERE id = ? AND estado = 'activo'
        LIMIT 1
    ");
    $stmt->execute([$gradoId]);
    $grado = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$grado) {
        throw new RuntimeException('El grado seleccionado no existe o no está activo.');
    }

    return $grado;
}

function obtenerPrefijosSeccion(array $grado): array
{
    $prefijos = [$grado['nombre']];
    $abreviaturas = [
        1 => '1er Grado',
        2 => '2do Grado',
        3 => '3er Grado',
        4 => '4to Grado',
        5 => '5to Grado',
        6 => '6to Grado',
    ];
    $numero = (int) $grado['numero'];

    if (isset($abreviaturas[$numero])) {
        $prefijos[] = $abreviaturas[$numero];
    }

    return array_values(array_unique($prefijos));
}

function crearSiguienteSeccion(
    PDO $pdo,
    int $añoEscolarId,
    int $gradoId,
    int $capacidad = 25,
    ?int $profesorId = null
): array {
    $grado = obtenerGradoSeccion($pdo, $gradoId);
    $nombreLegacyConditions = [];
    $params = [$añoEscolarId, $gradoId];

    foreach (obtenerPrefijosSeccion($grado) as $prefijo) {
        $nombreLegacyConditions[] = 'nombre LIKE ?';
        $params[] = $prefijo . ' %';
    }

    $stmt = $pdo->prepare("
        SELECT nombre
        FROM secciones
        WHERE `año_escolar_id` = ?
          AND (
              grado_id = ?
              OR (grado_id IS NULL AND (" . implode(' OR ', $nombreLegacyConditions) . "))
          )
    ");
    $stmt->execute($params);

    $mayorSufijo = 0;
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $nombreExistente) {
        if (!preg_match('/\s+([A-Z]+)$/', $nombreExistente, $coincidencias)) {
            continue;
        }

        $valorSufijo = 0;
        foreach (str_split($coincidencias[1]) as $letra) {
            $valorSufijo = ($valorSufijo * 26) + ord($letra) - 64;
        }
        $mayorSufijo = max($mayorSufijo, $valorSufijo);
    }

    $sufijo = '';
    $siguienteSufijo = $mayorSufijo + 1;
    while ($siguienteSufijo > 0) {
        $siguienteSufijo--;
        $sufijo = chr(65 + ($siguienteSufijo % 26)) . $sufijo;
        $siguienteSufijo = intdiv($siguienteSufijo, 26);
    }

    $nombre = $grado['nombre'] . ' ' . $sufijo;
    $seccionId = insertarSeccion($pdo, [
        'nombre' => $nombre,
        'año_escolar_id' => $añoEscolarId,
        'grado_id' => $gradoId,
        'capacidad' => $capacidad,
        'nivel' => $grado['nivel'],
        'tipo_grado' => 'grado',
        'profesor_id' => $profesorId,
    ]);

    return [
        'id' => $seccionId,
        'nombre' => $nombre,
        'capacidad' => $capacidad,
    ];
}

function obtenerSeccionesPorGrado(PDO $pdo, int $añoEscolarId, int $gradoId, bool $soloDisponibles = false): array
{
    $grado = obtenerGradoSeccion($pdo, $gradoId);
    $prefijos = obtenerPrefijosSeccion($grado);
    $nombreLegacyConditions = [];
    $params = [$añoEscolarId, $grado['nivel'], $gradoId];

    foreach ($prefijos as $prefijo) {
        $nombreLegacyConditions[] = 's.nombre LIKE ?';
        $params[] = $prefijo . ' %';
    }

    $sql = "
        SELECT s.id, s.nombre, s.capacidad, s.nivel,
               COUNT(i.id) AS estudiantes_inscritos,
               (s.capacidad - COUNT(i.id)) AS cupos_disponibles,
               p.nombre AS profesor_nombre, p.apellido AS profesor_apellido
        FROM secciones s
        LEFT JOIN inscripciones i ON s.id = i.seccion_id AND i.status = 'activo'
        LEFT JOIN profesores p ON s.profesor_id = p.id
        WHERE s.`año_escolar_id` = ?
          AND s.nivel = ?
          AND (
              s.grado_id = ?
              OR (
                  s.grado_id IS NULL
                  AND (" . implode(' OR ', $nombreLegacyConditions) . ")
              )
          )
        GROUP BY s.id
    ";

    if ($soloDisponibles) {
        $sql .= " HAVING s.capacidad IS NULL OR s.capacidad > COUNT(i.id)";
    }

    $sql .= ' ORDER BY s.nombre';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function insertarSeccion(PDO $pdo, array $seccion): int
{
    $nombre = trim((string) ($seccion['nombre'] ?? ''));
    $añoEscolarId = filter_var($seccion['año_escolar_id'] ?? null, FILTER_VALIDATE_INT);
    $gradoId = isset($seccion['grado_id']) && $seccion['grado_id'] !== ''
        ? filter_var($seccion['grado_id'], FILTER_VALIDATE_INT)
        : null;
    $capacidad = isset($seccion['capacidad']) && $seccion['capacidad'] !== ''
        ? filter_var($seccion['capacidad'], FILTER_VALIDATE_INT)
        : null;
    $profesorId = isset($seccion['profesor_id']) && $seccion['profesor_id'] !== ''
        ? filter_var($seccion['profesor_id'], FILTER_VALIDATE_INT)
        : null;
    $nivel = $seccion['nivel'] ?? '';
    $tipoGrado = $seccion['tipo_grado'] ?? '';

    if ($nombre === '' || mb_strlen($nombre, 'UTF-8') > 50) {
        throw new InvalidArgumentException('El nombre de la sección debe tener entre 1 y 50 caracteres.');
    }
    if ($añoEscolarId === false || $añoEscolarId < 1) {
        throw new InvalidArgumentException('El año escolar de la sección no es válido.');
    }
    if ($gradoId === false || ($gradoId !== null && $gradoId < 1) || ($tipoGrado === 'grado' && $gradoId === null)) {
        throw new InvalidArgumentException('El grado de la sección no es válido.');
    }
    if ($capacidad === false || ($capacidad !== null && $capacidad < 1)) {
        throw new InvalidArgumentException('La capacidad de la sección no es válida.');
    }
    if ($profesorId === false || ($profesorId !== null && $profesorId < 1)) {
        throw new InvalidArgumentException('El profesor de la sección no es válido.');
    }
    if (!in_array($nivel, ['primaria', 'secundaria'], true)) {
        throw new InvalidArgumentException('El nivel de la sección no es válido.');
    }
    if (!in_array($tipoGrado, ['grado', 'año'], true)) {
        throw new InvalidArgumentException('El tipo de grado de la sección no es válido.');
    }

    $stmt = $pdo->prepare("
        INSERT INTO secciones
            (uuid, nombre, `año_escolar_id`, grado_id, capacidad, nivel, tipo_grado, profesor_id, sincronizado)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1)
    ");
    $stmt->execute([
        generarUuidV4(),
        $nombre,
        $añoEscolarId,
        $gradoId,
        $capacidad,
        $nivel,
        $tipoGrado,
        $profesorId,
    ]);

    return (int) $pdo->lastInsertId();
}
