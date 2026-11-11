<?php
require_once '../../includes/auth.php';
require_once '../../includes/database.php';
checkAuth();
checkRole(['admin']);

$historial_id = $_GET['id'] ?? null;

if (!$historial_id) {
    error_log("Error en " . __FILE__ . ": ID de historial no especificado.");
    $_SESSION['error_sistema'] = "Ocurrió un error al procesar la solicitud. El equipo técnico fue notificado.";
    header('Location: ' . ($_SERVER['HTTP_REFERER'] ?? app_url()));
    exit;
}

// Obtener datos del adelantamiento
try {
    $sql = "
        SELECT 
            ha.*,
            e.cedula_escolar,
            e.nombre AS estudiante_nombre,
            e.apellido AS estudiante_apellido,
            e.fecha_nacimiento,
            e.genero,
            ae.nombre AS año_escolar,
            g.nombre AS grado_anterior,
            g.numero AS grado_numero
        FROM historial_academico ha
        JOIN estudiantes e ON ha.estudiante_id = e.id
        JOIN años_escolares ae ON ha.año_escolar_id = ae.id
        JOIN grados g ON ha.grado_id = g.id
        WHERE ha.id = ?
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$historial_id]);
    $adelanto = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$adelanto) {
        error_log("Error en " . __FILE__ . ": Adelantamiento no encontrado.");
        $_SESSION['error_sistema'] = "Ocurrió un error al procesar la solicitud. El equipo técnico fue notificado.";
        header('Location: ' . ($_SERVER['HTTP_REFERER'] ?? app_url()));
        exit;
    }

    // Indicadores evaluados en ese historial
    $sql_ind = "
        SELECT 
            ai.nombre AS indicador_nombre,
            aa.nombre AS area_nombre,
            hi.valoracion
        FROM historial_indicadores hi
        JOIN areas_indicadores ai ON hi.indicador_id = ai.id
        JOIN areas_aprendizaje aa ON ai.area_id = aa.id
        WHERE hi.historial_id = ?
        ORDER BY aa.nombre, ai.orden
    ";
    $stmt_ind = $pdo->prepare($sql_ind);
    $stmt_ind->execute([$historial_id]);
    $indicadores = $stmt_ind->fetchAll(PDO::FETCH_ASSOC);

    // Inscripción actual del estudiante (situación después del adelanto)
    $sql_actual = "
        SELECT s.nombre AS seccion_actual, g_act.nombre AS grado_actual, ae_act.nombre AS año_actual
        FROM inscripciones i
        JOIN secciones s ON i.seccion_id = s.id
        JOIN grados g_act ON s.grado_id = g_act.id
        JOIN años_escolares ae_act ON i.año_escolar_id = ae_act.id
        WHERE i.estudiante_id = ? AND i.status = 'activo'
        ORDER BY i.fecha_inscripcion DESC
        LIMIT 1
    ";
    $stmt_actual = $pdo->prepare($sql_actual);
    $stmt_actual->execute([$adelanto['estudiante_id']]);
    $situacion_actual = $stmt_actual->fetch(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    error_log("Error en " . __FILE__ . ": " . $e->getMessage());
    $_SESSION['error_sistema'] = "Ocurrió un error al procesar la solicitud. El equipo técnico fue notificado.";
    header('Location: ' . ($_SERVER['HTTP_REFERER'] ?? app_url()));
    exit;
}

// Etiquetas legibles
$tipos = [
    'academico'     => 'Mérito Académico',
    'transferencia' => 'Transferencia de Otra Institución',
    'otros'         => 'Otros Motivos'
];
$tipo_texto = $tipos[$adelanto['tipo_adelantamiento']] ?? 'No especificado';

$edad = $adelanto['fecha_nacimiento'] 
    ? (new DateTime($adelanto['fecha_nacimiento']))->diff(new DateTime())->y 
    : null;
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Reporte de Adelantamiento - <?php echo $adelanto['estudiante_nombre'] . ' ' . $adelanto['estudiante_apellido']; ?></title>
    <style>
        @page { margin: 2cm; size: letter; }
        body {
            font-family: 'Times New Roman', Times, serif;
            font-size: 13px;
            line-height: 1.5;
            color: #000;
            margin: 0;
            padding: 0;
        }
        .container { max-width: 21cm; margin: 0 auto; padding: 20px; }
        .header { text-align: center; margin-bottom: 25px; border-bottom: 2px solid #000; padding-bottom: 12px; }
        .header h1 { font-size: 16px; font-weight: bold; margin: 0 0 4px 0; text-transform: uppercase; }
        .header h2 { font-size: 12px; font-weight: normal; margin: 0; font-style: italic; }
        .title {
            text-align: center;
            font-size: 15px;
            font-weight: bold;
            margin: 20px 0;
            text-decoration: underline;
            text-transform: uppercase;
        }
        .section-title {
            font-size: 13px;
            font-weight: bold;
            background: #e9ecef;
            padding: 6px 10px;
            margin-top: 18px;
            border-left: 4px solid #333;
        }
        .info-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 8px;
        }
        .info-table td {
            padding: 5px 8px;
            border: 1px solid #ccc;
            vertical-align: top;
        }
        .info-table td:first-child {
            font-weight: bold;
            width: 30%;
            background: #f8f9fa;
        }
        .indicadores-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 8px;
            font-size: 12px;
        }
        .indicadores-table th,
        .indicadores-table td {
            border: 1px solid #000;
            padding: 5px 8px;
        }
        .indicadores-table th {
            background: #e0e0e0;
            font-weight: bold;
            text-align: center;
        }
        .area-row {
            background: #f0f0f0;
            font-weight: bold;
        }
        .literal-box {
            display: inline-block;
            padding: 6px 14px;
            font-size: 16px;
            font-weight: bold;
            border: 2px solid #000;
            margin: 4px 0;
        }
        .signature {
            margin-top: 60px;
            text-align: center;
        }
        .signature-line {
            border-top: 1px solid #000;
            width: 300px;
            margin: 0 auto 4px auto;
            padding-top: 8px;
        }
        .footer-note { font-size: 11px; margin-top: 4px; font-style: italic; }
        .no-print {
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: 1000;
        }
        .no-print button {
            padding: 10px 18px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            color: white;
            font-size: 14px;
        }
        @media print { .no-print { display: none; } }
    </style>
</head>
<body>
    <div class="no-print">
        <button onclick="window.print()" style="background:#007bff;">
            🖨️ Imprimir Reporte
        </button>
        <button onclick="window.close()" style="background:#dc3545; margin-left:8px;">
            ❌ Cerrar
        </button>
    </div>

    <div class="container">
        <div class="header">
            <h1>República Bolivariana de Venezuela</h1>
            <h2>Ministerio del Poder Popular para la Educación</h2>
            <h1>U.E.C "Simón Rodríguez"</h1>
            <h2>Valle de la Pascua - Estado Guárico</h2>
        </div>

        <div class="title">Reporte de Adelantamiento Académico</div>

        <div class="section-title">Datos del Estudiante</div>
        <table class="info-table">
            <tr>
                <td>Nombre Completo</td>
                <td><?php echo htmlspecialchars($adelanto['estudiante_apellido'] . ', ' . $adelanto['estudiante_nombre']); ?></td>
            </tr>
            <tr>
                <td>Cédula Escolar</td>
                <td><?php echo htmlspecialchars($adelanto['cedula_escolar'] ?: 'Sin registrar'); ?></td>
            </tr>
            <tr>
                <td>Fecha de Nacimiento</td>
                <td>
                    <?php echo $adelanto['fecha_nacimiento'] 
                        ? date('d/m/Y', strtotime($adelanto['fecha_nacimiento'])) . ($edad !== null ? " ({$edad} años)" : '')
                        : 'No registrada'; ?>
                </td>
            </tr>
            <tr>
                <td>Género</td>
                <td><?php echo ucfirst($adelanto['genero'] ?: 'No especificado'); ?></td>
            </tr>
        </table>

        <div class="section-title">Detalles del Adelantamiento</div>
        <table class="info-table">
            <tr>
                <td>Tipo de Adelantamiento</td>
                <td><strong><?php echo htmlspecialchars($tipo_texto); ?></strong></td>
            </tr>
            <tr>
                <td>Grado Anterior</td>
                <td><?php echo htmlspecialchars($adelanto['grado_anterior']); ?></td>
            </tr>
            <tr>
                <td>Año Escolar</td>
                <td><?php echo htmlspecialchars($adelanto['año_escolar']); ?></td>
            </tr>
            <tr>
                <td>Literal Final Obtenido</td>
                <td><span class="literal-box"><?php echo htmlspecialchars($adelanto['literal_final']); ?></span></td>
            </tr>
            <tr>
                <td>Fecha de Registro</td>
                <td><?php echo date('d/m/Y H:i', strtotime($adelanto['fecha_registro'])); ?></td>
            </tr>
            <?php if (!empty($adelanto['observaciones'])): ?>
            <tr>
                <td>Observaciones</td>
                <td><?php echo nl2br(htmlspecialchars($adelanto['observaciones'])); ?></td>
            </tr>
            <?php endif; ?>
        </table>

        <?php if ($situacion_actual): ?>
        <div class="section-title">Situación Actual</div>
        <table class="info-table">
            <tr>
                <td>Grado Actual</td>
                <td><?php echo htmlspecialchars($situacion_actual['grado_actual']); ?></td>
            </tr>
            <tr>
                <td>Sección Actual</td>
                <td><?php echo htmlspecialchars($situacion_actual['seccion_actual']); ?></td>
            </tr>
            <tr>
                <td>Año Escolar Actual</td>
                <td><?php echo htmlspecialchars($situacion_actual['año_actual']); ?></td>
            </tr>
        </table>
        <?php endif; ?>

        <?php if (!empty($indicadores)): ?>
        <div class="section-title">Evaluación por Indicadores</div>
        <table class="indicadores-table">
            <thead>
                <tr>
                    <th width="70%">Indicador</th>
                    <th width="30%">Valoración</th>
                </tr>
            </thead>
            <tbody>
                <?php
                $area_actual = '';
                foreach ($indicadores as $ind):
                    if ($area_actual !== $ind['area_nombre']):
                        $area_actual = $ind['area_nombre'];
                ?>
                <tr class="area-row">
                    <td colspan="2"><?php echo htmlspecialchars(strtoupper($area_actual)); ?></td>
                </tr>
                <?php endif; ?>
                <tr>
                    <td><?php echo htmlspecialchars($ind['indicador_nombre']); ?></td>
                    <td style="text-align:center;"><?php echo htmlspecialchars($ind['valoracion']); ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <div style="margin-top: 8px; font-size: 11px;">
            <strong>LEYENDA:</strong>
            LE = Logrado con Excelencia · L = Logrado · PA = Proceso Avanzado · IP = Iniciado en el Proceso · NL = No Logrado
        </div>
        <?php endif; ?>

        <div class="signature">
            <div class="signature-line"></div>
            <div><strong>Dra. Norena Hernández</strong></div>
            <div>Directora</div>
            <div class="footer-note">
                Reporte generado el <?php echo date('d/m/Y \a \l\a\s H:i'); ?>
            </div>
        </div>
    </div>
</body>
</html>