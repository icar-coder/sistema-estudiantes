<?php
// Archivo: includes/ajax_buscar_representante.php
require_once 'auth.php';
require_once 'database.php';
checkAuth();
checkRole(['admin']);

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Método no permitido']);
    exit;
}

$cedula = trim($_POST['cedula'] ?? '');

if (empty($cedula)) {
    echo json_encode(['success' => false, 'message' => 'Cédula requerida']);
    exit;
}

if (!preg_match('/^[0-9]{6,20}$/', $cedula)) {
    echo json_encode(['success' => false, 'message' => 'Formato de cédula inválido']);
    exit;
}

try {
    $stmt = $pdo->prepare("SELECT * FROM representantes WHERE cedula = ?");
    $stmt->execute([$cedula]);
    $representante = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($representante) {
        echo json_encode([
            'success' => true,
            'encontrado' => true,
            'representante' => [
                'id' => $representante['id'],
                'cedula' => $representante['cedula'],
                'nombre' => $representante['nombre'],
                'apellido' => $representante['apellido'],
                'parentesco' => $representante['parentesco'],
                'telefono' => $representante['telefono'],
                'email' => $representante['email']
            ]
        ]);
    } else {
        echo json_encode([
            'success' => true,
            'encontrado' => false,
            'message' => 'Cédula no encontrada'
        ]);
    }
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Error en la base de datos: ' . $e->getMessage()]);
}