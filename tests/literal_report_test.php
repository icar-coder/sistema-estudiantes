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
$pdo->exec('CREATE TABLE `historial_academico` (
    `id` INTEGER PRIMARY KEY,
    `estudiante_id` INTEGER,
    `año_escolar_id` INTEGER,
    `literal_final` TEXT
)');
$pdo->exec('CREATE TABLE `areas_aprendizaje` (
    `id` INTEGER PRIMARY KEY,
    `nombre` TEXT
)');
$pdo->exec('CREATE TABLE `areas_indicadores` (
    `id` INTEGER PRIMARY KEY,
    `area_id` INTEGER,
    `nombre` TEXT,
    `descripcion` TEXT,
    `orden` INTEGER
)');
$pdo->exec('CREATE TABLE `historial_indicadores` (
    `id` INTEGER PRIMARY KEY,
    `historial_id` INTEGER,
    `indicador_id` INTEGER,
    `momento` INTEGER,
    `valoracion` TEXT
)');
$pdo->exec('CREATE TABLE `plan_anual_indicadores` (
    `id` INTEGER PRIMARY KEY,
    `año_escolar_id` INTEGER,
    `indicador_id` INTEGER
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
$pdo->exec("INSERT INTO `historial_academico` VALUES
    (21, 1, 1, 'B'),
    (22, 3, 1, 'A'),
    (23, 4, 1, 'A')");
$pdo->exec("INSERT INTO `areas_aprendizaje` VALUES
    (1, 'Lengua'),
    (2, 'Matematica')");
$pdo->exec("INSERT INTO `areas_indicadores` VALUES
    (31, 1, 'Lectura', 'Lee diversos tipos de textos', 1),
    (32, 2, 'Calculo', 'Opera con numeros naturales', 1)");
$pdo->exec("INSERT INTO `plan_anual_indicadores` VALUES
    (51, 1, 31),
    (52, 1, 32),
    (53, 2, 32)");
$pdo->exec("INSERT INTO `historial_indicadores` VALUES
    (41, 21, 31, 1, 'LE'),
    (42, 21, 32, 2, 'L'),
    (43, 22, 31, 1, 'PA'),
    (44, 23, 32, 1, 'LE')");

$reporte = obtenerReporteLiterales($pdo, 1, null, null, '');
assertLiteralReportTest(
    count($reporte['estudiantes']) === 4,
    'El reporte debe incluir inscripciones y registros historicos sin inscripción del año seleccionado.'
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

$conIndicadores = obtenerReporteLiterales($pdo, 1, null, null, 'Ana', true, 1);
assertLiteralReportTest(
    count($conIndicadores['indicadores'][1] ?? []) === 1 &&
    $conIndicadores['indicadores'][1][0]['valoracion'] === 'LE',
    'El reporte debe filtrar los indicadores por inscripción y momento.'
);
$historicoIndicadores = obtenerReporteLiterales($pdo, 1, null, null, 'Dora', true, 1);
assertLiteralReportTest(
    count($historicoIndicadores['estudiantes']) === 1 &&
    $historicoIndicadores['estudiantes'][0]['inscripcion_id'] === null &&
    $historicoIndicadores['estudiantes'][0]['literal_final'] === 'A' &&
    $historicoIndicadores['indicadores'][4][0]['valoracion'] === 'LE',
    'El reporte debe incluir estudiantes históricos sin inscripción y sus indicadores.'
);

$estadisticasLiteralesFinales = obtenerEstadisticasLiteralesFinales($pdo, 1);
$conteosLiteralesFinales = array_column(
    $estadisticasLiteralesFinales['literales_finales'],
    'cantidad',
    'literal'
);
assertLiteralReportTest(
    (int) $conteosLiteralesFinales['A'] === 2 &&
    (int) $conteosLiteralesFinales['B'] === 1 &&
    count($estadisticasLiteralesFinales['top_estudiantes']) === 3,
    'Las estadísticas finales deben incorporar historiales sin inscripción y evitar duplicar estudiantes inscritos.'
);
assertLiteralReportTest(
    $estadisticasLiteralesFinales['top_estudiantes'][1]['seccion'] === 'Historial académico',
    'Los estudiantes históricos destacados deben identificarse sin asignarles una sección real.'
);
$estadisticasSeccion = obtenerEstadisticasLiteralesFinales($pdo, 1, 10);
assertLiteralReportTest(
    count($estadisticasSeccion['literales_finales']) === 1 &&
    $estadisticasSeccion['literales_finales'][0]['literal'] === 'B' &&
    count($estadisticasSeccion['top_estudiantes']) === 1,
    'Las estadísticas de literales finales deben respetar el filtro por sección.'
);

$resumenIndicadores = obtenerResumenIndicadores($pdo, 1);
$lengua = array_values(array_filter(
    $resumenIndicadores,
    static function (array $fila): bool {
        return $fila['area'] === 'Lengua';
    }
))[0];
$matematica = array_values(array_filter(
    $resumenIndicadores,
    static function (array $fila): bool {
        return $fila['area'] === 'Matematica';
    }
))[0];
assertLiteralReportTest(
    (int) $lengua['le'] === 1 &&
    (int) $lengua['pa'] === 1 &&
    (int) $lengua['total'] === 2 &&
    (int) $matematica['le'] === 1 &&
    (int) $matematica['l'] === 1,
    'El resumen debe contar valoraciones por indicador e incluir el historial del año.'
);

$resumenFiltrado = obtenerResumenIndicadores($pdo, 1, 10, 1);
assertLiteralReportTest(
    count($resumenFiltrado) === 1 &&
    (int) $resumenFiltrado[0]['le'] === 1 &&
    (int) $resumenFiltrado[0]['total'] === 1,
    'El resumen de indicadores debe respetar sección y momento.'
);

echo "literal report tests passed\n";
