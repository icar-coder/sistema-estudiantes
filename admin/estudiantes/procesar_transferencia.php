<?php
// Archivo: procesar_transferencia.php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/database.php';
checkAuth();
checkRole(['admin']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    session_start();
    
    $estudiante_id = $_POST['estudiante_id'];
    $seccion_id = $_SESSION['seccion_transferencia'] ?? $_POST['seccion_id'];
    $grado_id = $_SESSION['grado_transferencia'] ?? $_POST['grado_id'];
    
    try {
        $pdo->beginTransaction();
        
        // Inscribir estudiante en la sección
        $stmt_inscripcion = $pdo->prepare("
            INSERT INTO inscripciones 
            (estudiante_id, seccion_id, año_escolar_id, fecha_inscripcion, status) 
            VALUES (?, ?, ?, CURDATE(), 'activo')
        ");
        
        $ano_escolar_activo = obtenerAnoEscolarActivo($pdo);
        $stmt_inscripcion->execute([
            $estudiante_id, 
            $seccion_id, 
            $ano_escolar_activo['id']
        ]);
        
        $pdo->commit();
        
        // Limpiar sesión
        unset($_SESSION['seccion_transferencia']);
        unset($_SESSION['grado_transferencia']);
        
        header("Location: gestionar.php?mensaje=Transferencia+completada+exitosamente");
        exit;
        
    } catch (Exception $e) {
        $pdo->rollBack();
        header("Location: cargar_historial.php?estudiante_id=" . $estudiante_id . "&error=" . urlencode($e->getMessage()));
        exit;
    }
}
?>