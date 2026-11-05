<?php
// Archivo: includes/ajax_secciones.php
require_once 'database.php';
header('Content-Type: application/json');

if (isset($_GET['año_escolar_id']) || (isset($_GET['ano_escolar_id']) && isset($_GET['grado']))) {
    $ano_escolar_id = $_GET['año_escolar_id'] ?? $_GET['ano_escolar_id'] ?? null;
    $grado = $_GET['grado'] ?? null;

    if (!$ano_escolar_id) {
        echo json_encode([
            'success' => false,
            'error' => 'Falta el año escolar'
        ]);
        exit;
    }

    try {
        $sql = "
            SELECT s.id, s.nombre, s.capacidad, s.nivel,
                   COUNT(i.id) as estudiantes_inscritos,
                   (s.capacidad - COUNT(i.id)) as cupos_disponibles,
                   p.nombre as profesor_nombre, p.apellido as profesor_apellido
            FROM secciones s 
            LEFT JOIN inscripciones i ON s.id = i.seccion_id AND i.status = 'activo'
            LEFT JOIN profesores p ON s.profesor_id = p.id
            WHERE s.año_escolar_id = ?";

        $params = [$ano_escolar_id];

        if ($grado !== null && $grado !== '') {
            $sql .= " AND s.nombre LIKE ?";
            $params[] = $grado . '%';
        }

        $sql .= " AND s.nivel = 'primaria'
            GROUP BY s.id
            ORDER BY s.nombre";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $secciones = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode([
            'success' => true,
            'secciones' => $secciones
        ]);

    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'error' => $e->getMessage()
        ]);
    }
} else {
    echo json_encode([
        'success' => false,
        'error' => 'Parámetros incompletos'
    ]);
}
?>