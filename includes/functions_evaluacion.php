<?php
/**
 * Funciones helper para el sistema de evaluación por literales
 */

/**
 * Obtener el literal de un estudiante en un lapso específico
 */
function obtenerLiteralLapso($pdo, $inscripcion_id, $lapso_id) {
    try {
        $stmt = $pdo->prepare("
            SELECT literal, observaciones 
            FROM literales_lapso 
            WHERE inscripcion_id = ? AND lapso_id = ?
        ");
        $stmt->execute([$inscripcion_id, $lapso_id]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Error obteniendo literal: " . $e->getMessage());
        return null;
    }
}

/**
 * Obtener el literal final de un estudiante en un año escolar
 */
function obtenerLiteralFinal($pdo, $inscripcion_id, $año_escolar_id) {
    try {
        $stmt = $pdo->prepare("
            SELECT literal_final, observaciones_finales 
            FROM literales_finales 
            WHERE inscripcion_id = ? AND año_escolar_id = ?
        ");
        $stmt->execute([$inscripcion_id, $año_escolar_id]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Error obteniendo literal final: " . $e->getMessage());
        return null;
    }
}

/**
 * Calcular el progreso de evaluación de una sección en un lapso
 */
function calcularProgresoEvaluacion($pdo, $seccion_id, $lapso_id, $año_escolar_id) {
    try {
        // Total de estudiantes en la sección
        $stmt = $pdo->prepare("
            SELECT COUNT(*) as total 
            FROM inscripciones 
            WHERE seccion_id = ? AND año_escolar_id = ? AND status = 'activo'
        ");
        $stmt->execute([$seccion_id, $año_escolar_id]);
        $total_estudiantes = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
        
        // Estudiantes evaluados en este lapso
        $stmt = $pdo->prepare("
            SELECT COUNT(DISTINCT ll.inscripcion_id) as evaluados
            FROM literales_lapso ll
            JOIN inscripciones i ON ll.inscripcion_id = i.id
            WHERE i.seccion_id = ? AND i.año_escolar_id = ? AND ll.lapso_id = ?
        ");
        $stmt->execute([$seccion_id, $año_escolar_id, $lapso_id]);
        $estudiantes_evaluados = $stmt->fetch(PDO::FETCH_ASSOC)['evaluados'];
        
        return [
            'total_estudiantes' => $total_estudiantes,
            'evaluados' => $estudiantes_evaluados,
            'porcentaje' => $total_estudiantes > 0 ? round(($estudiantes_evaluados / $total_estudiantes) * 100) : 0
        ];
        
    } catch (PDOException $e) {
        error_log("Error calculando progreso: " . $e->getMessage());
        return ['total_estudiantes' => 0, 'evaluados' => 0, 'porcentaje' => 0];
    }
}

/**
 * Obtener estadísticas de literales por sección y lapso
 */
function obtenerEstadisticasLiterales($pdo, $seccion_id, $lapso_id, $año_escolar_id) {
    try {
        $stmt = $pdo->prepare("
            SELECT 
                ll.literal,
                COUNT(*) as cantidad
            FROM literales_lapso ll
            JOIN inscripciones i ON ll.inscripcion_id = i.id
            WHERE i.seccion_id = ? AND i.año_escolar_id = ? AND ll.lapso_id = ?
            GROUP BY ll.literal
            ORDER BY ll.literal
        ");
        $stmt->execute([$seccion_id, $año_escolar_id, $lapso_id]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
        
    } catch (PDOException $e) {
        error_log("Error obteniendo estadísticas: " . $e->getMessage());
        return [];
    }
}

/**
 * Convertir literal a texto descriptivo
 */
function literalATexto($literal) {
    $descripciones = [
        'A' => 'Excelente',
        'B' => 'Muy Bueno', 
        'C' => 'Bueno',
        'D' => 'Regular',
        'E' => 'Deficiente'
    ];
    
    return $descripciones[$literal] ?? 'No evaluado';
}

/**
 * Obtener color Bootstrap para un literal
 */
function colorParaLiteral($literal) {
    $colores = [
        'A' => 'success',
        'B' => 'info',
        'C' => 'primary', 
        'D' => 'warning',
        'E' => 'danger'
    ];
    
    return $colores[$literal] ?? 'secondary';
}
?>