<?php

require_once dirname(__DIR__) . '/includes/database_helpers.php';
require_once dirname(__DIR__) . '/includes/functions_asistencias.php';

function assertAdminAttendanceTest(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('CREATE TABLE `secciones` (
    `id` INTEGER PRIMARY KEY,
    `año_escolar_id` INTEGER NOT NULL,
    `nombre` TEXT NOT NULL
)');
$pdo->exec('CREATE TABLE `estudiantes` (
    `id` INTEGER PRIMARY KEY,
    `cedula_escolar` TEXT,
    `nombre` TEXT NOT NULL,
    `apellido` TEXT NOT NULL
)');
$pdo->exec('CREATE TABLE `inscripciones` (
    `id` INTEGER PRIMARY KEY,
    `estudiante_id` INTEGER NOT NULL,
    `seccion_id` INTEGER NOT NULL,
    `año_escolar_id` INTEGER NOT NULL,
    `status` TEXT NOT NULL
)');
$pdo->exec('CREATE TABLE `asistencias` (
    `id` INTEGER PRIMARY KEY AUTOINCREMENT,
    `inscripcion_id` INTEGER NOT NULL,
    `fecha` TEXT NOT NULL,
    `estado` TEXT NOT NULL,
    `observaciones` TEXT,
    `registrado_por` INTEGER,
    `created_at` TEXT DEFAULT CURRENT_TIMESTAMP,
    UNIQUE (`inscripcion_id`, `fecha`)
)');

$pdo->exec("INSERT INTO `secciones` VALUES (10, 1, 'A'), (20, 2, 'B')");
$pdo->exec("INSERT INTO `estudiantes` VALUES
    (1, 'E-1', 'Ana', 'Alvarez'),
    (2, 'E-2', 'Bruno', 'Bello'),
    (3, 'E-3', 'Carla', 'Cruz')");
$pdo->exec("INSERT INTO `inscripciones` VALUES
    (11, 1, 10, 1, 'activo'),
    (12, 2, 10, 1, 'activo'),
    (13, 3, 10, 1, 'retirado'),
    (14, 3, 20, 2, 'activo')");

assertAdminAttendanceTest(
    esFechaAsistenciaValida('2026-02-28') && !esFechaAsistenciaValida('2026-02-30'),
    'La fecha debe validarse como una fecha real.'
);

$lista = obtenerListaAsistenciaAdmin($pdo, 1, 10, '2026-11-19');
assertAdminAttendanceTest(
    count($lista) === 2,
    'La lista debe incluir estudiantes activos del año y la sección seleccionados.'
);

$procesados = guardarListaAsistenciaAdmin(
    $pdo,
    1,
    10,
    '2026-11-19',
    [11 => 'presente', 12 => 'justificado'],
    [11 => '', 12 => 'Presentó constancia'],
    null
);
assertAdminAttendanceTest($procesados === 2, 'Deben guardarse las asistencias de la lista activa.');

$lista = obtenerListaAsistenciaAdmin($pdo, 1, 10, '2026-11-19');
assertAdminAttendanceTest(
    $lista[0]['estado'] === 'presente' &&
    $lista[1]['estado'] === 'justificado' &&
    $lista[1]['observaciones'] === 'Presentó constancia',
    'La lista debe devolver el estado y las observaciones guardadas.'
);

guardarListaAsistenciaAdmin(
    $pdo,
    1,
    10,
    '2026-11-19',
    [11 => 'ausente', 12 => ''],
    [11 => 'Aviso a representante', 12 => ''],
    null
);
assertAdminAttendanceTest(
    (int) $pdo->query('SELECT COUNT(*) FROM `asistencias`')->fetchColumn() === 1,
    'Una selección vacía debe eliminar el registro de esa fecha.'
);
assertAdminAttendanceTest(
    $pdo->query('SELECT `estado` FROM `asistencias` WHERE `inscripcion_id` = 11')->fetchColumn() === 'ausente',
    'Volver a guardar debe actualizar el registro existente, no duplicarlo.'
);

$rechazado = false;
try {
    guardarListaAsistenciaAdmin(
        $pdo,
        1,
        10,
        '2026-11-19',
        [11 => 'presente', 12 => 'no-valido'],
        [11 => '', 12 => ''],
        null
    );
} catch (InvalidArgumentException $exception) {
    $rechazado = true;
}
assertAdminAttendanceTest($rechazado, 'El sistema debe rechazar estados fuera del catálogo.');
assertAdminAttendanceTest(
    $pdo->query('SELECT `estado` FROM `asistencias` WHERE `inscripcion_id` = 11')->fetchColumn() === 'ausente',
    'Un estado inválido debe cancelar toda la operación.'
);

$seccionRechazada = false;
try {
    obtenerListaAsistenciaAdmin($pdo, 1, 20, '2026-11-19');
} catch (InvalidArgumentException $exception) {
    $seccionRechazada = true;
}
assertAdminAttendanceTest($seccionRechazada, 'La sección debe corresponder al año elegido.');

echo "admin attendance tests passed\n";
