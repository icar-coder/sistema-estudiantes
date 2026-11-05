<?php
// generar_boleta.php
require_once '../../includes/auth.php';
require_once '../../includes/database.php';
checkAuth();
checkRole(['admin']);

$estudiante_id = $_GET['estudiante_id'] ?? null;

if (!$estudiante_id) {
    error_log("Error en " . __FILE__ . ": ID de estudiante no especificado.");
    $_SESSION['error_sistema'] = "Ocurrió un error al procesar la solicitud. El equipo técnico fue notificado.";
    header('Location: ' . ($_SERVER['HTTP_REFERER'] ?? '/SistemaEstudiantes/'));
    exit;
}

// Obtener información completa del estudiante para la boleta
try {
    $sql = "
        SELECT 
            e.*,
            i.id as inscripcion_id,
            s.nombre as seccion_nombre,
            s.nivel,
            g.numero as grado_numero,
            g.nombre as grado_nombre,
            ae.nombre as año_escolar,
            p.nombre as profesor_nombre,
            p.apellido as profesor_apellido,
            lf.literal_final,
            lf.observaciones_finales
        FROM estudiantes e
        JOIN inscripciones i ON e.id = i.estudiante_id AND i.status = 'activo'
        JOIN secciones s ON i.seccion_id = s.id
        JOIN grados g ON s.grado_id = g.id
        JOIN años_escolares ae ON i.año_escolar_id = ae.id
        LEFT JOIN profesores p ON s.profesor_id = p.id
        LEFT JOIN literales_finales lf ON i.id = lf.inscripcion_id AND lf.año_escolar_id = ae.id
        WHERE e.id = ?
        ORDER BY i.fecha_inscripcion DESC
        LIMIT 1
    ";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$estudiante_id]);
    $estudiante = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$estudiante) {
        error_log("Error en " . __FILE__ . ": Estudiante no encontrado o no tiene inscripción activa.");
        $_SESSION['error_sistema'] = "Ocurrió un error al procesar la solicitud. El equipo técnico fue notificado.";
        header('Location: ' . ($_SERVER['HTTP_REFERER'] ?? '/SistemaEstudiantes/'));
        exit;
    }
    
    // Obtener indicadores evaluados
    $sql_indicadores = "
        SELECT 
            ai.nombre as indicador_nombre,
            aa.nombre as area_nombre,
            hi.valoracion
        FROM historial_indicadores hi
        JOIN areas_indicadores ai ON hi.indicador_id = ai.id
        JOIN areas_aprendizaje aa ON ai.area_id = aa.id
        JOIN historial_academico ha ON hi.historial_id = ha.id
        WHERE ha.estudiante_id = ? AND ha.año_escolar_id = ?
        ORDER BY aa.nombre, ai.orden
    ";
    
    $stmt_indicadores = $pdo->prepare($sql_indicadores);
    $stmt_indicadores->execute([$estudiante_id, $estudiante['año_escolar_id']]);
    $indicadores = $stmt_indicadores->fetchAll(PDO::FETCH_ASSOC);
    
} catch (PDOException $e) {
    error_log("Error en " . __FILE__ . ": " . $e->getMessage());
    $_SESSION['error_sistema'] = "Ocurrió un error al procesar la solicitud. El equipo técnico fue notificado.";
    header('Location: ' . ($_SERVER['HTTP_REFERER'] ?? '/SistemaEstudiantes/'));
    exit;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Boleta - <?php echo $estudiante['nombre'] . ' ' . $estudiante['apellido']; ?></title>
    <style>
        @page {
            margin: 1cm;
            size: letter;
        }
        body {
            font-family: 'Times New Roman', Times, serif;
            font-size: 12px;
            line-height: 1.4;
            color: #000;
            margin: 0;
            padding: 0;
            background: white;
        }
        .container {
            max-width: 21cm;
            margin: 0 auto;
            padding: 15px;
        }
        .header {
            text-align: center;
            margin-bottom: 20px;
            border-bottom: 2px solid #000;
            padding-bottom: 10px;
        }
        .school-name {
            font-size: 16px;
            font-weight: bold;
            margin-bottom: 5px;
        }
        .school-address {
            font-size: 12px;
            margin-bottom: 5px;
        }
        .document-title {
            text-align: center;
            font-size: 14px;
            font-weight: bold;
            margin: 15px 0;
            text-decoration: underline;
        }
        .student-info {
            margin: 15px 0;
            padding: 10px;
            border: 1px solid #000;
        }
        .info-row {
            display: flex;
            margin-bottom: 5px;
        }
        .info-label {
            font-weight: bold;
            width: 200px;
        }
        .info-value {
            flex: 1;
        }
        .table-boleta {
            width: 100%;
            border-collapse: collapse;
            margin: 15px 0;
            font-size: 10px;
        }
        .table-boleta th,
        .table-boleta td {
            border: 1px solid #000;
            padding: 4px 6px;
            text-align: left;
            vertical-align: top;
        }
        .table-boleta th {
            background-color: #f0f0f0;
            font-weight: bold;
            text-align: center;
        }
        .area-header {
            background-color: #e0e0e0 !important;
            font-weight: bold;
        }
        .signature-section {
            margin-top: 30px;
            text-align: center;
        }
        .signature-line {
            border-top: 1px solid #000;
            width: 300px;
            margin: 0 auto 5px auto;
            padding-top: 10px;
        }
        .footer-note {
            font-size: 10px;
            margin-top: 5px;
        }
        .no-print {
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: 1000;
        }
        .print-btn {
            padding: 10px 20px;
            background: #007bff;
            color: white;
            border: none;
            border-radius: 5px;
            cursor: pointer;
        }
        .close-btn {
            padding: 10px 20px;
            background: #dc3545;
            color: white;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            margin-left: 10px;
        }
        @media print {
            .no-print {
                display: none;
            }
            body {
                margin: 0;
                padding: 0;
            }
        }
        .literal-final {
            text-align: center;
            font-size: 14px;
            font-weight: bold;
            margin: 10px 0;
            padding: 5px;
            border: 2px solid #000;
        }
    </style>
</head>
<body>
    <div class="no-print">
        <button onclick="window.print()" class="print-btn">
            🖨️ Imprimir Boleta
        </button>
        <button onclick="window.close()" class="close-btn">
            ❌ Cerrar
        </button>
    </div>

    <div class="container">
        <!-- Encabezado -->
        <div class="header">
            <div class="school-name">U.E. COLEGIO "SIMÓN RODRÍGUEZ"</div>
            <div class="school-address">
                CALLE GUAMACHAL N° 33 -- Telf.- 0412-0333639<br>
                VALLE DE LA PASCUA -- EDO. GUARICO<br>
                RIF- J-504041084 PD- 14181205
            </div>
        </div>

        <!-- Título del documento -->
        <div class="document-title">
            REGISTRO HISTORICO ACADEMICO<br>
            AÑO- ESCOLAR <?php echo $estudiante['año_escolar']; ?>
        </div>

        <!-- Información del estudiante -->
        <div class="student-info">
            <div class="info-row">
                <div class="info-label">NOMBRE Y ESTUDIANTE:</div>
                <div class="info-value"><?php echo strtoupper($estudiante['apellido'] . ', ' . $estudiante['nombre']); ?></div>
            </div>
            <div class="info-row">
                <div class="info-label">CEDULA ESC.:</div>
                <div class="info-value"><?php echo $estudiante['cedula_escolar'] ?: 'SIN REGISTRAR'; ?></div>
            </div>
            <div class="info-row">
                <div class="info-label">F.NAC.:</div>
                <div class="info-value"><?php echo $estudiante['fecha_nacimiento'] ? date('d/m/Y', strtotime($estudiante['fecha_nacimiento'])) : 'NO REGISTRADA'; ?></div>
            </div>
            <div class="info-row">
                <div class="info-label">GRADO:</div>
                <div class="info-value"><?php echo strtoupper($estudiante['grado_nombre']); ?></div>
            </div>
            <div class="info-row">
                <div class="info-label">SECCION:</div>
                <div class="info-value">UNICA</div>
            </div>
            <div class="info-row">
                <div class="info-label">DOCENTE:</div>
                <div class="info-value"><?php echo $estudiante['profesor_nombre'] ? 'LCDA. ' . strtoupper($estudiante['profesor_nombre'] . ' ' . $estudiante['profesor_apellido']) : 'NO ASIGNADO'; ?></div>
            </div>
        </div>

        <!-- Literal Final -->
        <?php if ($estudiante['literal_final']): ?>
            <div class="literal-final">
                LITERAL FINAL: <?php echo $estudiante['literal_final']; ?>
            </div>
        <?php endif; ?>

        <!-- Tabla de indicadores -->
        <table class="table-boleta">
            <thead>
                <tr>
                    <th width="5%">AA</th>
                    <th width="65%">ÁREA DE APRENDIZAJE</th>
                    <th width="6%">LE</th>
                    <th width="6%">L</th>
                    <th width="6%">PA</th>
                    <th width="6%">IP</th>
                    <th width="6%">NL</th>
                </tr>
            </thead>
            <tbody>
                <?php
                $area_actual = '';
                $contador_area = 0;
                foreach ($indicadores as $indicador):
                    if ($area_actual != $indicador['area_nombre']):
                        $contador_area++;
                        $area_actual = $indicador['area_nombre'];
                ?>
                <tr>
                    <td class="area-header" colspan="7">
                        <strong><?php echo strtoupper($indicador['area_nombre']); ?></strong>
                    </td>
                </tr>
                <?php endif; ?>
                <tr>
                    <td style="text-align: center;"><?php echo $contador_area; ?></td>
                    <td><?php echo $indicador['indicador_nombre']; ?></td>
                    <td style="text-align: center;"><?php echo $indicador['valoracion'] == 'LE' ? 'X' : ''; ?></td>
                    <td style="text-align: center;"><?php echo $indicador['valoracion'] == 'L' ? 'X' : ''; ?></td>
                    <td style="text-align: center;"><?php echo $indicador['valoracion'] == 'PA' ? 'X' : ''; ?></td>
                    <td style="text-align: center;"><?php echo $indicador['valoracion'] == 'IP' ? 'X' : ''; ?></td>
                    <td style="text-align: center;"><?php echo $indicador['valoracion'] == 'NL' ? 'X' : ''; ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <!-- Leyenda -->
        <div style="margin-top: 10px; font-size: 10px;">
            <strong>LEYENDA:</strong> 
            <strong>LE:</strong> Logrado con Excelencia, 
            <strong>L:</strong> Logrado, 
            <strong>PA:</strong> Proceso Avanzado, 
            <strong>IP:</strong> Iniciado en el Proceso, 
            <strong>NL:</strong> No logrado
        </div>

        <!-- Observaciones -->
        <?php if ($estudiante['observaciones_finales']): ?>
            <div style="margin-top: 15px; padding: 10px; border: 1px solid #000;">
                <strong>OBSERVACIONES:</strong><br>
                <?php echo nl2br(htmlspecialchars($estudiante['observaciones_finales'])); ?>
            </div>
        <?php endif; ?>

        <!-- Firmas -->
        <div class="signature-section">
            <div style="display: flex; justify-content: space-around; margin-top: 30px;">
                <div>
                    <div class="signature-line"></div>
                    <div><strong>Directora</strong></div>
                    <div class="footer-note">Dra. NORENA HERNÁNDEZ</div>
                </div>
                <div>
                    <div class="signature-line"></div>
                    <div><strong>Docente</strong></div>
                    <div class="footer-note">
                        <?php echo $estudiante['profesor_nombre'] ? 'LCDA. ' . strtoupper($estudiante['profesor_nombre'] . ' ' . $estudiante['profesor_apellido']) : 'NO ASIGNADO'; ?>
                    </div>
                </div>
                <div>
                    <div class="signature-line"></div>
                    <div><strong>Representante</strong></div>
                    <div class="footer-note">Sello y Firma</div>
                </div>
            </div>
        </div>
    </div>
</body>
</html>