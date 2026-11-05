<?php
// Archivo: cargar_secciones.php
require_once __DIR__ . '/../includes/database.php';

header('Content-Type: application/json');

// Verificar que los parámetros estén presentes
if (!isset($_GET['grado_id']) || !isset($_GET['ano_escolar_id'])) {
    echo json_encode([
        'success' => false, 
        'message' => 'Parámetros incompletos'
    ]);
    exit;
}

$grado_id = intval($_GET['grado_id']);
$ano_escolar_id = intval($_GET['ano_escolar_id']);

try {
    // Consulta para obtener secciones del grado específico
    $stmt = $pdo->prepare("
        SELECT 
            s.id,
            s.nombre,
            s.capacidad,
            (SELECT COUNT(*) FROM inscripciones i WHERE i.seccion_id = s.id AND i.status = 'activo') as estudiantes_inscritos,
            (s.capacidad - (SELECT COUNT(*) FROM inscripciones i WHERE i.seccion_id = s.id AND i.status = 'activo')) as cupos_disponibles,
            p.nombre as profesor_nombre,
            p.apellido as profesor_apellido
        FROM secciones s 
        LEFT JOIN profesores p ON s.profesor_id = p.id
        WHERE s.año_escolar_id = ? 
        AND s.grado_id = ?
        AND (s.capacidad IS NULL OR s.capacidad > (SELECT COUNT(*) FROM inscripciones i WHERE i.seccion_id = s.id AND i.status = 'activo'))
        ORDER BY s.nombre
    ");
    
    $stmt->execute([$ano_escolar_id, $grado_id]);
    $secciones = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode([
        'success' => true,
        'secciones' => $secciones
    ]);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Error al cargar secciones: ' . $e->getMessage()
    ]);
}
?>