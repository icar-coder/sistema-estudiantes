<?php
// Archivo: procesar_historial.php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/database.php';
checkAuth();
checkRole(['admin']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $pdo->beginTransaction();
        
        $estudiante_id = $_POST['estudiante_id'];
        $tipo = $_POST['tipo'];
        $grado_anterior = $_POST['grado_anterior'];
        $literal_final_anterior = $_POST['literal_final_anterior'];
        $indicadores = $_POST['indicadores'] ?? [];
        
        // Aquí procesarías el historial académico
        // Guardar los indicadores, literal final, etc.
        
        $pdo->commit();
        
        $_SESSION['mensaje'] = "Historial académico guardado correctamente";
        $_SESSION['tipo_mensaje'] = "success";
        
        header("Location: gestionar.php");
        exit;
        
    } catch (Exception $e) {
        $pdo->rollBack();
        $_SESSION['mensaje'] = "Error al guardar historial: " . $e->getMessage();
        $_SESSION['tipo_mensaje'] = "danger";
        header("Location: cargar_historial.php?estudiante_id=" . $estudiante_id . "&tipo=" . $tipo);
        exit;
    }
}
?>