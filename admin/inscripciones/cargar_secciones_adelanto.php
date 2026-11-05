<?php
require_once '../../includes/auth.php';
require_once '../../includes/database.php';
checkAuth();
checkRole(['admin']);

header('Content-Type: application/json');

try {
    $grado_id = $_GET['grado_id'] ?? null;
    $año_escolar_id = $_GET['año_escolar_id'] ?? null;

    if (!$grado_id || !$año_escolar_id) {
        throw new Exception("Datos incompletos");
    }

    // Obtener secciones disponibles para el grado
    $stmt = $pdo->prepare("
        SELECT s.id, s.nombre, s.capacidad,
               (SELECT COUNT(*) FROM inscripciones i WHERE i.seccion_id = s.id AND i.status = 'activo') as inscritos,
               (s.capacidad - (SELECT COUNT(*) FROM inscripciones i WHERE i.seccion_id = s.id AND i.status = 'activo')) as cupos_disponibles
        FROM secciones s 
        WHERE s.grado_id = ? AND s.año_escolar_id = ?
        AND (s.capacidad IS NULL OR s.capacidad > (SELECT COUNT(*) FROM inscripciones i WHERE i.seccion_id = s.id AND i.status = 'activo'))
        ORDER BY s.nombre
    ");
    $stmt->execute([$grado_id, $año_escolar_id]);
    $secciones = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'secciones' => $secciones
    ]);

} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?>