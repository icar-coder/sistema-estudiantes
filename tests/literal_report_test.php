<?php

require_once dirname(__DIR__) . '/includes/functions_reportes.php';

function assertLiteralReportTest(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$pdo->exec('CREATE TABLE `inscripciones` (
    `id` INTEGER PRIMARY KEY,
    `estudiante_id` INTEGER,
    `seccion_id` INTEGER,
    `año_escolar_id` INTEGER,
    `status` TEXT
)');
$pdo->exec('CREATE TABLE `estudiantes` (
    `id` INTEGER PRIMARY KEY,
    `cedula_escolar` TEXT,
    `nombre` TEXT,
    `apellido` TEXT
)');
$pdo->exec('CREATE TABLE `secciones` (`id` INTEGER PRIMARY KEY, `nombre` TEXT)');
$pdo->exec('CREATE TABLE `lapsos` (
    `id` INTEGER PRIMARY KEY,
    `año_escolar_id` INTEGER,
    `nombre` TEXT,
    `numero` INTEGER
)');
$pdo->exec('CREATE TABLE `literales_lapso` (
    `id` INTEGER PRIMARY KEY,
    `inscripcion_id` INTEGER,
    `lapso_id` INTEGER,
    `literal` TEXT,
    `observaciones` TEXT,
    `fecha_evaluacion` TEXT
)');
$pdo->exec('CREATE TABLE `literales_finales` (
    `id` INTEGER PRIMARY KEY,
    `inscripcion_id` INTEGER,
    `año_escolar_id` INTEGER,
    `literal_final` TEXT
)');

$pdo->exec("INSERT INTO `secciones` VALUES (10, 'A'), (20, 'B'), (30, 'C')");
$pdo->exec("INSERT INTO `lapsos` VALUES
    (101, 1, 'Primer lapso', 1),
    (102, 1, 'Segundo lapso', 2),
    (201, 2, 'Primer lapso', 1)");
$pdo->exec("INSERT INTO `estudiantes` VALUES
    (1, 'E-1', 'Ana', 'Alvarez'),
    (2, 'E-2', 'Bruno', 'Bello'),
    (3, 'E-3', 'Carla', 'Cruz'),
    (4, 'E-4', 'Dora', 'Diaz')");
$pdo->exec("INSERT INTO `inscripciones` VALUES
    (11, 1, 10, 1, 'activo'),
    (12, 2, 10, 1, 'retirado'),
    (13, 3, 20, 1, 'graduado'),
    (14, 4, 30, 2, 'activo')");
$pdo->exec("INSERT INTO `literales_lapso` VALUES
    (1, 11, 101, 'A', 'Buen avance', '2026-10-01'),
    (2, 13, 102, 'C', '', '2026-11-01'),
    (3, 14, 201, 'E', '', '2026-11-02')");
$pdo->exec("INSERT INTO `literales_finales` VALUES
    (1, 11, 1, 'B'),
    (2, 13, 1, 'A'),
    (3, 14, 2, 'E')");

$reporte = obtenerReporteLiterales($pdo, 1, null, null, '');
assertLiteralReportTest(
    count($reporte['estudiantes']) === 3,
    'El reporte debe incluir las inscripciones del año seleccionado, incluso si ya no están activas.'
);
assertLiteralReportTest(
    count($reporte['lapsos']) === 2,
    'El reporte debe incluir los lapsos del año seleccionado.'
);
assertLiteralReportTest(
    $reporte['evaluaciones'][11][101]['literal'] === 'A' &&
    $reporte['estudiantes'][0]['literal_final'] === 'B',
    'El reporte debe devolver los literales del lapso y el literal final.'
);
assertLiteralReportTest(
    !isset($reporte['evaluaciones'][12]),
    'El reporte debe conservar estudiantes sin evaluación registrada.'
);

$filtrado = obtenerReporteLiterales($pdo, 1, 101, 10, 'Bruno');
assertLiteralReportTest(
    count($filtrado['estudiantes']) === 1 &&
    $filtrado['estudiantes'][0]['nombre'] === 'Bruno' &&
    $filtrado['estudiantes'][0]['inscripcion_id'] == 12,
    'El reporte debe combinar filtros de lapso, sección y estudiante.'
);
assertLiteralReportTest(
    $filtrado['evaluaciones'] === [],
    'El reporte debe permitir mostrar un estudiante sin literal en el lapso filtrado.'
);

echo "literal report tests passed\n";
