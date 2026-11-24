<?php
require __DIR__ . '/redirigir_plan_anual.php';
// admin/areas_aprendizaje/eliminar_indicador.php
require_once '../../includes/auth.php';
require_once '../../includes/database.php';
checkAuth();
checkRole(['admin']);

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Método no permitido']);
    exit;
}

$indicador_id = $_POST['id'] ?? null;

if (!$indicador_id) {
    echo json_encode(['success' => false, 'message' => 'No se especificó el indicador']);
    exit;
}

try {
    // Verificar que el indicador existe
    $stmt = $pdo->prepare("SELECT * FROM areas_indicadores WHERE id = ?");
    $stmt->execute([$indicador_id]);
    $indicador = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$indicador) {
        echo json_encode(['success' => false, 'message' => 'Indicador no encontrado']);
        exit;
    }
    
    // Eliminar el indicador (cambiar estado a inactivo en lugar de eliminar físicamente)
    $stmt = $pdo->prepare("UPDATE areas_indicadores SET estado = 'inactivo' WHERE id = ?");
    $stmt->execute([$indicador_id]);
    
    echo json_encode([
        'success' => true, 
        'message' => 'Indicador eliminado correctamente'
    ]);
    
} catch (PDOException $e) {
    error_log("Error al eliminar indicador: " . $e->getMessage());
    echo json_encode([
        'success' => false, 
        'message' => 'Error al eliminar el indicador: ' . $e->getMessage()
    ]);
}
?>