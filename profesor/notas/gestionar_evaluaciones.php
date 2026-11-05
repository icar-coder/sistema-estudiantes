<?php
require_once '../../includes/auth.php';
require_once '../../includes/database.php';
checkAuth();
checkRole(['profesor']);

// [Código similar para obtener asignaciones, lapsos, etc...]

// Obtener tipos de evaluación
try {
    $stmt = $pdo->query("SELECT * FROM tipos_evaluacion WHERE estado = 'activo' ORDER BY orden");
    $tipos_evaluacion = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $tipos_evaluacion = [];
}

// Procesar guardado de notas por evaluación
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['guardar_notas'])) {
    $lapso_id = $_POST['lapso_id'];
    $seccion_id = $_POST['seccion_id'];
    $materia_id = $_POST['materia_id'];
    $tipo_evaluacion_id = $_POST['tipo_evaluacion_id'];
    
    $notas_guardadas = 0;
    $errores = 0;
    
    foreach ($_POST['notas'] as $inscripcion_id => $valor_nota) {
        $valor_nota = trim($valor_nota);
        $observaciones = trim($_POST['observaciones'][$inscripcion_id] ?? '');
        
        if ($valor_nota === '' || (is_numeric($valor_nota) && $valor_nota >= 0 && $valor_nota <= 20)) {
            try {
                if ($valor_nota === '') {
                    // Eliminar nota si está vacía
                    $stmt = $pdo->prepare("
                        DELETE FROM notas 
                        WHERE inscripcion_id = ? AND materia_id = ? AND lapso_id = ? AND tipo_evaluacion_id = ?
                    ");
                    $stmt->execute([$inscripcion_id, $materia_id, $lapso_id, $tipo_evaluacion_id]);
                } else {
                    // Convertir a decimal
                    $valor_decimal = number_format(floatval($valor_nota), 2, '.', '');
                    $valor_letra = convertirNotaLetras($valor_decimal);
                    
                    // Insertar o actualizar nota individual
                    $stmt = $pdo->prepare("
                        INSERT INTO notas (inscripcion_id, materia_id, lapso_id, tipo_evaluacion_id, valor_decimal, valor_letra, comentario, es_promedio) 
                        VALUES (?, ?, ?, ?, ?, ?, ?, 0)
                        ON DUPLICATE KEY UPDATE 
                            valor_decimal = VALUES(valor_decimal),
                            valor_letra = VALUES(valor_letra),
                            comentario = VALUES(comentario),
                            fecha_registro = CURRENT_TIMESTAMP
                    ");
                    $stmt->execute([$inscripcion_id, $materia_id, $lapso_id, $tipo_evaluacion_id, $valor_decimal, $valor_letra, $observaciones]);
                    
                    // Recalcular promedio automáticamente
                    calcularPromedioLapso($pdo, $inscripcion_id, $materia_id, $lapso_id);
                }
                $notas_guardadas++;
            } catch (PDOException $e) {
                $errores++;
                error_log("Error guardando nota: " . $e->getMessage());
            }
        } else {
            $errores++;
        }
    }
    
    // [Mensajes de éxito/error...]
}

// Función para calcular promedio automáticamente
function calcularPromedioLapso($pdo, $inscripcion_id, $materia_id, $lapso_id) {
    try {
        // Obtener todas las notas del lapso con sus pesos
        $stmt = $pdo->prepare("
            SELECT n.valor_decimal, te.peso
            FROM notas n
            JOIN tipos_evaluacion te ON n.tipo_evaluacion_id = te.id
            WHERE n.inscripcion_id = ? AND n.materia_id = ? AND n.lapso_id = ? AND n.es_promedio = 0
        ");
        $stmt->execute([$inscripcion_id, $materia_id, $lapso_id]);
        $notas = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (count($notas) > 0) {
            $suma_ponderada = 0;
            $suma_pesos = 0;
            
            foreach ($notas as $nota) {
                $suma_ponderada += $nota['valor_decimal'] * $nota['peso'];
                $suma_pesos += $nota['peso'];
            }
            
            $promedio = $suma_pesos > 0 ? $suma_ponderada / $suma_pesos : 0;
            $promedio_final = number_format($promedio, 2, '.', '');
            $valor_letra = convertirNotaLetras($promedio_final);
            
            // Guardar/actualizar el promedio
            $stmt = $pdo->prepare("
                INSERT INTO notas (inscripcion_id, materia_id, lapso_id, valor_decimal, valor_letra, comentario, es_promedio) 
                VALUES (?, ?, ?, ?, ?, 'Promedio calculado automáticamente', 1)
                ON DUPLICATE KEY UPDATE 
                    valor_decimal = VALUES(valor_decimal),
                    valor_letra = VALUES(valor_letra),
                    fecha_registro = CURRENT_TIMESTAMP
            ");
            $stmt->execute([$inscripcion_id, $materia_id, $lapso_id, $promedio_final, $valor_letra]);
        }
    } catch (PDOException $e) {
        error_log("Error calculando promedio: " . $e->getMessage());
    }
}

// En el formulario HTML, agregar selector de tipo de evaluación:
?>
<!-- En la sección de filtros, agregar: -->
<div class="col-md-3">
    <label for="tipo_evaluacion_id" class="form-label">Tipo de Evaluación</label>
    <select class="form-select" id="tipo_evaluacion_id" name="tipo_evaluacion_id" required>
        <option value="">Seleccionar tipo...</option>
        <?php foreach ($tipos_evaluacion as $tipo): ?>
            <option value="<?php echo $tipo['id']; ?>">
                <?php echo htmlspecialchars($tipo['nombre'] . ' (' . ($tipo['peso'] * 100) . '%)'); ?>
            </option>
        <?php endforeach; ?>
    </select>
</div>