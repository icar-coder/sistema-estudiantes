<?php
require_once 'database.php';

header('Content-Type: application/json');

// Desactivar errores para evitar output HTML
error_reporting(0);

try {
    if (!isset($_GET['año_escolar_id'])) {
        throw new Exception('Parámetro año_escolar_id requerido');
    }
    
    $año_escolar_id = $_GET['año_escolar_id'];
    
    $stmt = $pdo->prepare("
        SELECT 
            s.id,
            s.nombre,
            s.capacidad,
            s.nivel,
            s.tipo_grado,
            COUNT(i.id) as inscritos
        FROM secciones s
        LEFT JOIN inscripciones i ON s.id = i.seccion_id AND i.status = 'activo'
        WHERE s.año_escolar_id = ?
        GROUP BY s.id
        ORDER BY s.nombre
    ");
    $stmt->execute([$año_escolar_id]);
    $secciones = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode($secciones);
    
} catch (Exception $e) {
    echo json_encode([]);
}
?>