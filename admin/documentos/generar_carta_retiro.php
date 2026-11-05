<?php
// admin/documentos/generar_carta_retiro.php
require_once '../../includes/auth.php';
require_once '../../includes/database.php';
checkAuth();
checkRole(['admin']);

$estudiante_id = intval($_GET['estudiante_id'] ?? 0);
$causa = $_GET['causa'] ?? 'CAMBIO DE INSTITUCION';
$observaciones = $_GET['observaciones'] ?? '';

if (!$estudiante_id) {
    die("ID de estudiante no especificado");
}

// Buscar la inscripción activa más reciente
try {
    $stmt = $pdo->prepare("
        SELECT id FROM inscripciones 
        WHERE estudiante_id = ? AND status = 'activo'
        ORDER BY fecha_inscripcion DESC 
        LIMIT 1
    ");
    $stmt->execute([$estudiante_id]);
    $inscripcion = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$inscripcion) {
        die("El estudiante no tiene una inscripción activa");
    }
    
    // Redirigir al generador real
    $url = "../inscripciones/ver_carta_retiro.php?id=" . $inscripcion['id']
         . "&causa=" . urlencode($causa)
         . "&observaciones=" . urlencode($observaciones);
    
    header('Location: ' . $url);
    exit;
    
} catch (PDOException $e) {
    error_log("Error en generar_carta_retiro: " . $e->getMessage());
    die("Error al generar la carta de retiro");
}