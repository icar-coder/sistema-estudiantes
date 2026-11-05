<?php
require_once '../../includes/auth.php';
require_once '../../includes/database.php';
checkAuth();
checkRole(['admin']);

$inscripcion_id = $_GET['id'] ?? null;
$causa_retiro = $_GET['causa'] ?? 'CAMBIO DE INSTITUCION';
$observaciones = $_GET['observaciones'] ?? '';

if (!$inscripcion_id) {
    error_log("Error en " . __FILE__ . ": ID de inscripción no especificado.");
    $_SESSION['error_sistema'] = "Ocurrió un error al procesar la solicitud. El equipo técnico fue notificado.";
    header('Location: ' . ($_SERVER['HTTP_REFERER'] ?? '/SistemaEstudiantes/'));
    exit;
}

// Obtener información de la inscripción
try {
    $sql = "
        SELECT 
            i.*,
            e.cedula_escolar,
            e.nombre as estudiante_nombre,
            e.apellido as estudiante_apellido,
            e.fecha_nacimiento,
            e.lugar_nacimiento,
            s.nombre as seccion_nombre,
            s.nivel,
            ae.nombre as año_escolar,
            r.nombre as representante_nombre,
            r.apellido as representante_apellido
        FROM inscripciones i
        JOIN estudiantes e ON i.estudiante_id = e.id
        JOIN secciones s ON i.seccion_id = s.id
        JOIN años_escolares ae ON i.año_escolar_id = ae.id
        JOIN representantes r ON e.representante_id = r.id
        WHERE i.id = ?
    ";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$inscripcion_id]);
    $inscripcion = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$inscripcion) {
        error_log("Error en " . __FILE__ . ": Inscripción no encontrada.");
        $_SESSION['error_sistema'] = "Ocurrió un error al procesar la solicitud. El equipo técnico fue notificado.";
        header('Location: ' . ($_SERVER['HTTP_REFERER'] ?? '/SistemaEstudiantes/'));
        exit;
    }
    
} catch (PDOException $e) {
    error_log("Error en " . __FILE__ . ": " . $e->getMessage());
    $_SESSION['error_sistema'] = "Ocurrió un error al procesar la solicitud. El equipo técnico fue notificado.";
    header('Location: ' . ($_SERVER['HTTP_REFERER'] ?? '/SistemaEstudiantes/'));
    exit;
}

// Calcular edad y formato de fecha
$edad = '';
$fecha_nac_formatted = '';
if ($inscripcion['fecha_nacimiento']) {
    $fecha_nac = new DateTime($inscripcion['fecha_nacimiento']);
    $hoy = new DateTime();
    $edad = $hoy->diff($fecha_nac)->y;
    $fecha_nac_formatted = $fecha_nac->format('d/m/Y');
}

// Determinar el grado/año
$grado_texto = '';
if ($inscripcion['nivel'] === 'primaria') {
    $grado_texto = strtoupper($inscripcion['seccion_nombre']) . ' de educación primaria';
} else {
    $grado_texto = strtoupper($inscripcion['seccion_nombre']) . ' de Media General';
}

// Texto completo de la causa
$causa_completa = $causa_retiro;
if ($observaciones && $causa_retiro === 'OTRO') {
    $causa_completa = $observaciones;
} elseif ($observaciones) {
    $causa_completa = $causa_retiro . '. ' . $observaciones;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Carta de Retiro - <?php echo $inscripcion['estudiante_nombre'] . ' ' . $inscripcion['estudiante_apellido']; ?></title>
    <style>
        @page {
            margin: 2.5cm;
            size: letter;
        }
        body {
            font-family: 'Times New Roman', Times, serif;
            font-size: 14px;
            line-height: 1.6;
            color: #000;
            margin: 0;
            padding: 0;
        }
        .container {
            max-width: 21cm;
            margin: 0 auto;
            padding: 20px;
        }
        .header {
            text-align: center;
            margin-bottom: 30px;
        }
        .title {
            text-align: center;
            font-size: 16px;
            font-weight: bold;
            margin: 25px 0;
            text-decoration: underline;
            text-transform: uppercase;
        }
        .content {
            text-align: justify;
            margin-bottom: 20px;
        }
        .student-info {
            margin: 15px 0;
            padding: 10px;
        }
        .info-highlight {
            font-weight: bold;
            text-transform: uppercase;
        }
        .signature {
            margin-top: 60px;
            text-align: center;
        }
        .signature-line {
            border-top: 1px solid #000;
            width: 300px;
            margin: 0 auto 5px auto;
            padding-top: 10px;
        }
        .footer-note {
            font-size: 12px;
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
            margin-left: 10px;
        }
        .close-btn {
            padding: 10px 20px;
            background: #dc3545;
            color: white;
            border: none;
            border-radius: 5px;
            cursor: pointer;
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
    </style>
</head>
<body>
    <div class="no-print">
        <button onclick="window.print()" class="print-btn">
            🖨️ Imprimir Carta
        </button>
        <button onclick="window.close()" class="close-btn">
            ❌ Cerrar
        </button>
    </div>

    <div class="container">
        <!-- Título del documento -->
        <div class="title">
            CARTA DE RETIRO
        </div>

        <!-- Contenido de la carta -->
        <div class="content">
            <p>
                Quien Suscribe <span class="info-highlight">Dra. NORENA HERNÁNDEZ</span>, 
                Directora de la Unidad Educativa Colegio <span class="info-highlight">"SIMÓN RODRÍGUEZ"</span>, 
                que funciona en Valle de la Pascua, Municipio Infante del Estado Guárico, 
                a los fines legales pertinentes y en base a lo establecido en el Articulo 10 
                del Reglamento General de la Ley Orgánica de Educación,
            </p>
            
            <p style="text-align: center; margin: 20px 0;">
                <strong>CERTIFICA</strong>
            </p>
            
            <p>
                Que el alumno (a): <span class="info-highlight"><?php echo $inscripcion['estudiante_nombre'] . ' ' . $inscripcion['estudiante_apellido']; ?></span>, 
                portador de la cédula escolar: Nº <span class="info-highlight"><?php echo $inscripcion['cedula_escolar'] ?: 'SIN CÉDULA'; ?></span> 
                cursó el <span class="info-highlight"><?php echo $grado_texto; ?></span> del año escolar <span class="info-highlight"><?php echo $inscripcion['año_escolar']; ?></span>. 
                Nacido (a) el día <?php echo $fecha_nac_formatted ?: 'NO REGISTRADA'; ?>, 
                en <?php echo $inscripcion['lugar_nacimiento'] ?: 'el Municipio Infante, Estado Guárico'; ?> 
                de <?php echo $edad ?: 'N/D'; ?> años de edad, 
                por la siguiente causa <span class="info-highlight"><?php echo $causa_completa; ?></span>, 
                según lo manifiesta su representante legal.
            </p>

            <p>
                Constancia que se expide a petición de la parte interesada en Valle de la Pascua, 
                a los <strong><?php echo date('d'); ?></strong> días del mes de <strong><?php echo obtenerMesEspañol(date('n')); ?></strong> del <strong><?php echo date('Y'); ?></strong>.
            </p>
        </div>

        <!-- Firma -->
        <div class="signature">
            <div class="signature-line"></div>
            <div><strong>Dra. NORENA HERNÁNDEZ</strong></div>
            <div>Directora</div>
            <div class="footer-note">NH/lc.</div>
        </div>
    </div>
</body>
</html>

<?php
// Función para obtener mes en español
function obtenerMesEspañol($mes) {
    $meses = [
        1 => 'Enero', 2 => 'Febrero', 3 => 'Marzo', 4 => 'Abril',
        5 => 'Mayo', 6 => 'Junio', 7 => 'Julio', 8 => 'Agosto',
        9 => 'Septiembre', 10 => 'Octubre', 11 => 'Noviembre', 12 => 'Diciembre'
    ];
    return $meses[$mes] ?? '';
}
?>