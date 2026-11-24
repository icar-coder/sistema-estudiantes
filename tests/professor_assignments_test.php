<?php

require_once dirname(__DIR__) . '/includes/functions_asignaciones_profesores.php';

function assertProfessorAssignmentsTest(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('CREATE TABLE `años_escolares` (`id` INTEGER PRIMARY KEY, `estado` TEXT NOT NULL)');
$pdo->exec('CREATE TABLE `grados` (`id` INTEGER PRIMARY KEY, `estado` TEXT NOT NULL)');
$pdo->exec('CREATE TABLE `profesores` (`id` INTEGER PRIMARY KEY, `estado` TEXT NOT NULL)');
$pdo->exec('CREATE TABLE `secciones` (
    `id` INTEGER PRIMARY KEY,
    `año_escolar_id` INTEGER NOT NULL,
    `grado_id` INTEGER,
    `profesor_id` INTEGER
)');
$pdo->exec("INSERT INTO `años_escolares` VALUES (1, 'activo'), (2, 'inactivo')");
$pdo->exec("INSERT INTO `grados` VALUES (10, 'activo'), (20, 'activo')");
$pdo->exec("INSERT INTO `profesores` VALUES (100, 'activo'), (200, 'activo'), (300, 'inactivo')");
$pdo->exec("INSERT INTO `secciones` VALUES
    (1, 1, 10, NULL),
    (2, 1, 10, 200),
    (3, 1, 20, 300),
    (4, 2, 10, NULL)");

$resultado = guardarAsignacionProfesorAGrado($pdo, 1, 10, 100);
assertProfessorAssignmentsTest(
    $resultado['secciones'] === 2 && $resultado['actualizadas'] === 2,
    'La asignación por grado debe actualizar todas sus secciones del año seleccionado.'
);
assertProfessorAssignmentsTest(
    (int) $pdo->query('SELECT COUNT(*) FROM `secciones` WHERE `profesor_id` = 100')->fetchColumn() === 2 &&
    (int) $pdo->query('SELECT `profesor_id` FROM `secciones` WHERE `id` = 3')->fetchColumn() === 300 &&
    $pdo->query('SELECT `profesor_id` FROM `secciones` WHERE `id` = 4')->fetchColumn() === null,
    'La asignación por grado no debe modificar otros grados ni años escolares.'
);

$resultadoRepetido = guardarAsignacionProfesorAGrado($pdo, 1, 10, 100);
assertProfessorAssignmentsTest(
    $resultadoRepetido['secciones'] === 2 && $resultadoRepetido['actualizadas'] === 0,
    'Repetir la misma asignación debe ser una operación idempotente.'
);

guardarAsignacionProfesorSeccion($pdo, 1, 10, 2, 200);
assertProfessorAssignmentsTest(
    (int) $pdo->query('SELECT `profesor_id` FROM `secciones` WHERE `id` = 2')->fetchColumn() === 200,
    'Una sección debe poder tener una excepción al profesor asignado por grado.'
);
guardarAsignacionProfesorSeccion($pdo, 1, 10, 2, null);
assertProfessorAssignmentsTest(
    $pdo->query('SELECT `profesor_id` FROM `secciones` WHERE `id` = 2')->fetchColumn() === null,
    'Debe ser posible quitar el profesor de una sección.'
);

guardarAsignacionProfesorSeccion($pdo, 1, 20, 3, 300);
assertProfessorAssignmentsTest(
    (int) $pdo->query('SELECT `profesor_id` FROM `secciones` WHERE `id` = 3')->fetchColumn() === 300,
    'Debe ser posible conservar un profesor inactivo ya asignado a una sección.'
);

$nuevoProfesorInactivoRechazado = false;
try {
    guardarAsignacionProfesorSeccion($pdo, 1, 20, 3, 300);
    guardarAsignacionProfesorSeccion($pdo, 1, 10, 1, 300);
} catch (InvalidArgumentException $exception) {
    $nuevoProfesorInactivoRechazado = true;
}
assertProfessorAssignmentsTest(
    $nuevoProfesorInactivoRechazado,
    'No se debe poder asignar un profesor inactivo a otra sección.'
);

$seccionAjenaRechazada = false;
try {
    guardarAsignacionProfesorSeccion($pdo, 1, 10, 3, 100);
} catch (InvalidArgumentException $exception) {
    $seccionAjenaRechazada = true;
}
assertProfessorAssignmentsTest(
    $seccionAjenaRechazada,
    'No se debe permitir asignar desde un grado distinto al de la sección.'
);

$profesorInactivoRechazado = false;
try {
    guardarAsignacionProfesorAGrado($pdo, 1, 10, 300);
} catch (InvalidArgumentException $exception) {
    $profesorInactivoRechazado = true;
}
assertProfessorAssignmentsTest(
    $profesorInactivoRechazado,
    'No se deben asignar profesores inactivos.'
);

echo "professor assignments tests passed\n";
