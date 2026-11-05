<?php
require_once 'database.php';

header('Content-Type: application/json');
error_reporting(0);

try {
    if (!isset($_GET['seccion_id']) || !isset($_GET['año_escolar_id'])) {
        throw new Exception('Parámetros requeridos faltantes');
    }

    $seccion_id = (int) $_GET['seccion_id'];
    $año_escolar_id = (int) $_GET['año_escolar_id'];

    $stmt = $pdo->prepare("SELECT nivel FROM secciones WHERE id = ?");
    $stmt->execute([$seccion_id]);
    $seccion = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$seccion) {
        throw new Exception('Sección no encontrada');
    }

    $nivel_seccion = $seccion['nivel'];
    $nivel_materia = ($nivel_seccion === 'secundaria') ? 'media' : 'primaria';

    $stmt = $pdo->prepare("
        SELECT aa.id, aa.nombre, aa.codigo
        FROM areas_aprendizaje aa
        WHERE aa.nivel = ? AND aa.estado = 'activa'
        ORDER BY aa.nombre
    ");
    $stmt->execute([$nivel_materia]);
    echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
} catch (Exception $e) {
    echo json_encode([]);
}
