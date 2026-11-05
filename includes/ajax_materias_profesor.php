<?php
require_once 'database.php';

header('Content-Type: application/json');

try {
    if (!isset($_GET['seccion_id']) || !isset($_GET['profesor_id'])) {
        throw new Exception('Parámetros incompletos');
    }

    $seccion_id = (int) $_GET['seccion_id'];
    $profesor_id = (int) $_GET['profesor_id'];

    $stmt = $pdo->prepare("
        SELECT DISTINCT aa.id AS materia_id, aa.nombre AS materia_nombre, aa.codigo AS materia_codigo
        FROM horarios_asignaciones ha
        JOIN areas_aprendizaje aa ON ha.area_id = aa.id
        WHERE ha.profesor_id = ? AND ha.seccion_id = ?
        ORDER BY aa.nombre
    ");
    $stmt->execute([$profesor_id, $seccion_id]);
    echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
} catch (Exception $e) {
    echo json_encode([]);
}
