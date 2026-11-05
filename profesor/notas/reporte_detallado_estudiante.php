<?php
// [Código de autenticación...]
require_once '../../includes/auth.php';
require_once '../../includes/database.php';
checkAuth();
checkRole(['profesor']);
// Obtener notas detalladas por evaluación
$notas_detalladas = [];
if ($estudiante) {
    try {
        $stmt = $pdo->prepare("
            SELECT 
                n.valor_decimal,
                n.es_promedio,
                te.nombre as tipo_evaluacion,
                te.peso,
                m.nombre as materia_nombre,
                l.nombre as lapso_nombre,
                l.numero as lapso_numero
            FROM notas n
            LEFT JOIN tipos_evaluacion te ON n.tipo_evaluacion_id = te.id
            JOIN materias m ON n.materia_id = m.id
            JOIN lapsos l ON n.lapso_id = l.id
            WHERE n.inscripcion_id = ?
            ORDER BY l.numero, m.nombre, n.es_promedio, te.orden
        ");
        $stmt->execute([$estudiante['inscripcion_id']]);
        $notas_detalladas = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $notas_detalladas = [];
    }
}

// Organizar por lapso y materia
$notas_organizadas = [];
foreach ($notas_detalladas as $nota) {
    $lapso_key = $nota['lapso_nombre'];
    $materia_key = $nota['materia_nombre'];
    
    if (!isset($notas_organizadas[$lapso_key])) {
        $notas_organizadas[$lapso_key] = [];
    }
    if (!isset($notas_organizadas[$lapso_key][$materia_key])) {
        $notas_organizadas[$lapso_key][$materia_key] = [
            'evaluaciones' => [],
            'promedio' => null
        ];
    }
    
    if ($nota['es_promedio']) {
        $notas_organizadas[$lapso_key][$materia_key]['promedio'] = $nota;
    } else {
        $notas_organizadas[$lapso_key][$materia_key]['evaluaciones'][] = $nota;
    }
}
?>

<!-- En el HTML, mostrar tabla detallada -->
<?php foreach ($notas_organizadas as $lapso => $materias): ?>
<div class="card mb-4">
    <div class="card-header">
        <h5 class="card-title mb-0"><?php echo htmlspecialchars($lapso); ?></h5>
    </div>
    <div class="card-body">
        <?php foreach ($materias as $materia_nombre => $datos_materia): ?>
        <h6 class="mt-3"><?php echo htmlspecialchars($materia_nombre); ?></h6>
        <div class="table-responsive">
            <table class="table table-sm table-bordered">
                <thead>
                    <tr>
                        <th>Tipo de Evaluación</th>
                        <th>Peso</th>
                        <th>Nota</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($datos_materia['evaluaciones'] as $evaluacion): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($evaluacion['tipo_evaluacion']); ?></td>
                        <td><?php echo ($evaluacion['peso'] * 100); ?>%</td>
                        <td><strong><?php echo $evaluacion['valor_decimal']; ?></strong></td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if ($datos_materia['promedio']): ?>
                    <tr class="table-success">
                        <td colspan="2" class="text-end"><strong>PROMEDIO FINAL:</strong></td>
                        <td><strong class="fs-5"><?php echo $datos_materia['promedio']['valor_decimal']; ?></strong></td>
                    </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php endforeach; ?>
    </div>
</div>
<?php endforeach; ?>