<?php
require_once 'database.php';

header('Content-Type: application/json');

if (isset($_GET['año_escolar_id'])) {
    $año_escolar_id = $_GET['año_escolar_id'];
    
    try {
        $stmt = $pdo->prepare("
            SELECT s.id, s.nombre, s.capacidad, 
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
    } catch (PDOException $e) {
        echo json_encode([]);
    }
} else {
    echo json_encode([]);
}
?>