<?php

require_once dirname(__DIR__) . '/includes/functions_plan_anual.php';

function assertAnnualPlanTest(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('CREATE TABLE `años_escolares` (
    `id` INTEGER PRIMARY KEY,
    `nombre` TEXT NOT NULL,
    `estado` TEXT NOT NULL
)');
$pdo->exec('CREATE TABLE `areas_aprendizaje` (
    `id` INTEGER PRIMARY KEY AUTOINCREMENT,
    `nombre` TEXT NOT NULL,
    `codigo` TEXT,
    `descripcion` TEXT,
    `nivel` TEXT,
    `estado` TEXT,
    `uuid` TEXT NOT NULL UNIQUE,
    `sincronizado` INTEGER NOT NULL DEFAULT 0
)');
$pdo->exec('CREATE UNIQUE INDEX `uq_area_codigo` ON `areas_aprendizaje` (`codigo`)');
$pdo->exec('CREATE TABLE `areas_indicadores` (
    `id` INTEGER PRIMARY KEY AUTOINCREMENT,
    `area_id` INTEGER NOT NULL,
    `nombre` TEXT NOT NULL,
    `descripcion` TEXT,
    `escala` TEXT,
    `orden` INTEGER,
    `estado` TEXT,
    `uuid` TEXT NOT NULL UNIQUE,
    `sincronizado` INTEGER NOT NULL DEFAULT 0
)');
$pdo->exec('CREATE TABLE `historial_academico` (
    `id` INTEGER PRIMARY KEY,
    `estudiante_id` INTEGER,
    `año_escolar_id` INTEGER
)');
$pdo->exec('CREATE TABLE `historial_indicadores` (
    `id` INTEGER PRIMARY KEY,
    `historial_id` INTEGER NOT NULL,
    `indicador_id` INTEGER NOT NULL,
    `valoracion` TEXT
)');
$pdo->exec("INSERT INTO `años_escolares` VALUES
    (1, '2024-2025', 'inactivo'),
    (2, '2025-2026', 'activo'),
    (3, '2026-2027', 'activo')");
$pdo->exec("INSERT INTO `areas_aprendizaje`
    (`id`, `nombre`, `codigo`, `descripcion`, `nivel`, `estado`, `uuid`)
    VALUES (10, 'Matemática', 'MAT', 'Área matemática', 'primaria', 'activa', 'area-uuid-10')");
$pdo->exec("INSERT INTO `areas_indicadores`
    (`id`, `area_id`, `nombre`, `descripcion`, `escala`, `orden`, `estado`, `uuid`)
    VALUES (30, 10, 'Suma', 'Resuelve sumas', 'L', 1, 'activo', 'indicator-uuid-30')");
$pdo->exec('CREATE TABLE `años_escolares_areas` (
    `año_escolar_id` INTEGER NOT NULL,
    `area_id` INTEGER NOT NULL,
    PRIMARY KEY (`año_escolar_id`, `area_id`)
)');
$pdo->exec('CREATE TABLE `años_escolares_indicadores` (
    `año_escolar_id` INTEGER NOT NULL,
    `indicador_id` INTEGER NOT NULL,
    PRIMARY KEY (`año_escolar_id`, `indicador_id`)
)');
$pdo->exec("INSERT INTO `años_escolares_areas` VALUES (1, 10), (2, 10)");
$pdo->exec("INSERT INTO `años_escolares_indicadores` VALUES (1, 30), (2, 30)");
$pdo->exec("INSERT INTO `historial_academico` VALUES (101, 1, 1), (102, 2, 2)");
$pdo->exec("INSERT INTO `historial_indicadores` VALUES
    (201, 101, 30, 'L'),
    (202, 102, 30, 'EP')");

planAnualMigrarCatalogosExistentes($pdo);
$areaAssociationUuid = (string) $pdo->query(
    'SELECT `uuid` FROM `plan_anual_areas` WHERE `año_escolar_id` = 1'
)->fetchColumn();
planAnualGuardarAsociaciones($pdo, 1, [10], [30]);
assertAnnualPlanTest(
    (string) $pdo->query(
        'SELECT `uuid` FROM `plan_anual_areas` WHERE `año_escolar_id` = 1'
    )->fetchColumn() === $areaAssociationUuid,
    'Guardar el mismo plan debe conservar el UUID de las asociaciones sincronizadas.'
);
$clones = planAnualAislarCatalogo($pdo, 2);
$areaNueva = (int) $clones['areas'][10];
$indicadorNuevo = (int) $clones['indicadores'][30];
assertAnnualPlanTest($areaNueva !== 10, 'El área compartida debe clonarse para el año activo.');
assertAnnualPlanTest($indicadorNuevo !== 30, 'El indicador compartido debe clonarse para el año activo.');
assertAnnualPlanTest(
    (int) $pdo->query('SELECT `area_id` FROM `plan_anual_areas` WHERE `año_escolar_id` = 1')->fetchColumn() === 10,
    'Aislar el plan activo no debe cambiar la asociación del año histórico.'
);
assertAnnualPlanTest(
    (int) $pdo->query('SELECT `indicador_id` FROM `plan_anual_indicadores` WHERE `año_escolar_id` = 1')->fetchColumn() === 30,
    'El plan histórico debe conservar el indicador original.'
);
assertAnnualPlanTest(
    (int) $pdo->query('SELECT `indicador_id` FROM `historial_indicadores` WHERE `historial_id` = 101')->fetchColumn() === 30,
    'Las evaluaciones del año histórico deben conservar su indicador original.'
);
assertAnnualPlanTest(
    (int) $pdo->query('SELECT `indicador_id` FROM `historial_indicadores` WHERE `historial_id` = 102')->fetchColumn() === $indicadorNuevo,
    'Las evaluaciones del año activo deben seguir el indicador clonado.'
);
assertAnnualPlanTest(
    $pdo->query('SELECT `codigo` FROM `areas_aprendizaje` WHERE `id` = ' . $areaNueva)->fetchColumn() === 'MAT-A2',
    'Una columna de código única debe generar un código independiente al clonar.'
);

$pdo->prepare('UPDATE `areas_aprendizaje` SET `nombre` = ? WHERE `id` = ?')
    ->execute(['Matemática actualizada', $areaNueva]);
assertAnnualPlanTest(
    $pdo->query('SELECT `nombre` FROM `areas_aprendizaje` WHERE `id` = 10')->fetchColumn() === 'Matemática',
    'Modificar el catálogo del año activo no debe alterar el histórico.'
);

planAnualCopiarPlan($pdo, 1, 3);
$areaCopiada = (int) $pdo->query(
    'SELECT `area_id` FROM `plan_anual_areas` WHERE `año_escolar_id` = 3'
)->fetchColumn();
$indicadorCopiado = (int) $pdo->query(
    'SELECT `indicador_id` FROM `plan_anual_indicadores` WHERE `año_escolar_id` = 3'
)->fetchColumn();
assertAnnualPlanTest($areaCopiada !== 10, 'Copiar un plan debe crear un área independiente.');
assertAnnualPlanTest($indicadorCopiado !== 30, 'Copiar un plan debe crear indicadores independientes.');
assertAnnualPlanTest(
    (int) $pdo->query('SELECT `area_id` FROM `areas_indicadores` WHERE `id` = ' . $indicadorCopiado)->fetchColumn() === $areaCopiada,
    'El indicador copiado debe quedar vinculado a su nueva área.'
);

$pdo->exec("INSERT INTO `historial_academico` VALUES (103, 3, 3)");
$copiaBloqueada = false;
try {
    planAnualCopiarPlan($pdo, 1, 3);
} catch (RuntimeException $e) {
    $copiaBloqueada = true;
}
assertAnnualPlanTest($copiaBloqueada, 'No se debe reemplazar un plan que ya tiene historial académico.');

$edicionHistoricaBloqueada = false;
try {
    planAnualAislarCatalogo($pdo, 1);
} catch (RuntimeException $e) {
    $edicionHistoricaBloqueada = true;
}
assertAnnualPlanTest($edicionHistoricaBloqueada, 'Los años inactivos deben permanecer en modo consulta.');

$legacy = new PDO('sqlite::memory:');
$legacy->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$legacy->exec('CREATE TABLE `años_escolares` (
    `id` INTEGER PRIMARY KEY,
    `nombre` TEXT NOT NULL,
    `estado` TEXT NOT NULL
)');
$legacy->exec('CREATE TABLE `areas_aprendizaje` (
    `id` INTEGER PRIMARY KEY,
    `estado` TEXT NOT NULL
)');
$legacy->exec('CREATE TABLE `areas_indicadores` (
    `id` INTEGER PRIMARY KEY,
    `area_id` INTEGER NOT NULL,
    `estado` TEXT NOT NULL,
    `orden` INTEGER
)');
$legacy->exec("INSERT INTO `años_escolares` VALUES
    (1, '2024-2025', 'inactivo'),
    (2, '2025-2026', 'activo'),
    (3, '2026-2027', 'planificado')");
$legacy->exec("INSERT INTO `areas_aprendizaje` VALUES (10, 'activa'), (11, 'inactiva')");
$legacy->exec("INSERT INTO `areas_indicadores` VALUES
    (30, 10, 'activo', 1),
    (31, 10, 'inactivo', 2)");
planAnualMigrarCatalogosExistentes($legacy);
assertAnnualPlanTest(
    (int) $legacy->query('SELECT COUNT(*) FROM `plan_anual_areas` WHERE `año_escolar_id` = 1')->fetchColumn() === 1,
    'La migración debe asignar áreas activas a los planes históricos sin configuración.'
);
assertAnnualPlanTest(
    (int) $legacy->query('SELECT COUNT(*) FROM `plan_anual_indicadores` WHERE `año_escolar_id` = 1')->fetchColumn() === 1,
    'La migración debe preservar el catálogo activo que se usaba como alternativa histórica.'
);
assertAnnualPlanTest(
    (int) $legacy->query('SELECT COUNT(*) FROM `plan_anual_areas` WHERE `año_escolar_id` = 3')->fetchColumn() === 0,
    'Los años planificados nuevos no deben recibir automáticamente el catálogo anterior.'
);
$legacy->exec('DELETE FROM `plan_anual_areas` WHERE `año_escolar_id` = 1');
planAnualMigrarCatalogosExistentes($legacy);
assertAnnualPlanTest(
    (int) $legacy->query('SELECT COUNT(*) FROM `plan_anual_areas` WHERE `año_escolar_id` = 1')->fetchColumn() === 0,
    'La migración de catálogos heredados debe ejecutarse una sola vez.'
);

echo "annual plan tests passed\n";
