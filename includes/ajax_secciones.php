<?php
// Archivo: includes/ajax_secciones.php
require_once 'database.php';
require_once __DIR__ . '/functions_secciones.php';
header('Content-Type: application/json');

$añoEscolarId = filter_var($_GET['año_escolar_id'] ?? null, FILTER_VALIDATE_INT);
$gradoId = filter_var($_GET['grado_id'] ?? null, FILTER_VALIDATE_INT);

if ($añoEscolarId === false || $añoEscolarId < 1 || $gradoId === false || $gradoId < 1) {
    echo json_encode([
        'success' => false,
        'error' => 'Se requiere un año escolar y un grado válidos.'
    ]);
    exit;
}

try {
    $secciones = obtenerSeccionesPorGrado($pdo, $añoEscolarId, $gradoId);
    echo json_encode([
        'success' => true,
        'secciones' => $secciones
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('Error al consultar secciones por grado: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'No se pudieron cargar las secciones.'
    ], JSON_UNESCAPED_UNICODE);
}
?>