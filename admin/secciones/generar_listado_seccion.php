<?php
require_once '../../includes/auth.php';
require_once '../../includes/database.php';
checkAuth();
checkRole(['admin']);

if (!isset($_GET['seccion_id']) || empty($_GET['seccion_id'])) {
    error_log("Error en " . __FILE__ . ": No se proporcionó ID de sección.");
    $_SESSION['error_sistema'] = "Ocurrió un error al procesar la solicitud. El equipo técnico fue notificado.";
    header('Location: ' . ($_SERVER['HTTP_REFERER'] ?? app_url()));
    exit;
}

$seccion_id = $_GET['seccion_id'];

// Función para obtener datos completos de la sección y estudiantes
function obtenerDatosSeccionCompleta($seccion_id, $pdo) {
    try {
        // Obtener información de la sección
        $stmt_seccion = $pdo->prepare("
            SELECT s.*, ae.nombre as año_escolar, ae.fecha_inicio, ae.fecha_fin
            FROM secciones s 
            JOIN años_escolares ae ON s.año_escolar_id = ae.id 
            WHERE s.id = ?
        ");
        $stmt_seccion->execute([$seccion_id]);
        $seccion = $stmt_seccion->fetch(PDO::FETCH_ASSOC);
        
        if (!$seccion) {
            return false;
        }
        
        // Obtener estudiantes de la sección
        $stmt_estudiantes = $pdo->prepare("
            SELECT 
                e.cedula_escolar,
                e.nombre as estudiante_nombre,
                e.apellido as estudiante_apellido,
                e.fecha_nacimiento,
                e.lugar_nacimiento,
                e.direccion,
                e.telefono,
                e.email as estudiante_email,
                r.cedula as cedula_representante,
                r.nombre as representante_nombre,
                r.apellido as representante_apellido,
                r.parentesco,
                r.telefono as telefono_representante,
                r.email as email_representante,
                r.profesion,
                r.lugar_trabajo,
                i.fecha_inscripcion
            FROM inscripciones i
            JOIN estudiantes e ON i.estudiante_id = e.id
            JOIN representantes r ON e.representante_id = r.id
            WHERE i.seccion_id = ? AND i.status = 'activo'
            ORDER BY e.apellido, e.nombre
        ");
        $stmt_estudiantes->execute([$seccion_id]);
        $estudiantes = $stmt_estudiantes->fetchAll(PDO::FETCH_ASSOC);
        
        return [
            'seccion' => $seccion,
            'estudiantes' => $estudiantes
        ];
        
    } catch (PDOException $e) {
        error_log("Error al obtener datos de sección: " . $e->getMessage());
        return false;
    }
}

// Obtener datos
$datos = obtenerDatosSeccionCompleta($seccion_id, $pdo);

if (!$datos) {
    error_log("Error en " . __FILE__ . ": No se encontraron datos para la sección ID: " . $seccion_id);
    $_SESSION['error_sistema'] = "Ocurrió un error al procesar la solicitud. El equipo técnico fue notificado.";
    header('Location: ' . ($_SERVER['HTTP_REFERER'] ?? app_url()));
    exit;
}

// Generar listado PDF
generarListadoPDF($datos);

function generarListadoPDF($datos) {
    // Incluir TCPDF
    require_once('../../includes/tcpdf/tcpdf.php');
    
    $seccion = $datos['seccion'];
    $estudiantes = $datos['estudiantes'];
    $fecha_actual = date('d/m/Y');
    $total_estudiantes = count($estudiantes);
    
    // Crear nuevo documento PDF
    $pdf = new TCPDF('L', 'mm', 'A4', true, 'UTF-8', false);
    
    // Configurar documento
    $pdf->SetCreator('Sistema de Gestión Académica');
    $pdf->SetAuthor('Colegio Simón Rodríguez');
    $pdf->SetTitle('Listado de Estudiantes - ' . $seccion['nombre']);
    $pdf->SetSubject('Listado de Estudiantes');
    
    // Eliminar header y footer por defecto
    $pdf->setPrintHeader(false);
    $pdf->setPrintFooter(false);
    
    // Establecer márgenes
    $pdf->SetMargins(10, 10, 10);
    $pdf->SetAutoPageBreak(TRUE, 10);
    
    // Agregar página
    $pdf->AddPage();
    
    // Configurar fuente
    $pdf->SetFont('helvetica', 'B', 16);
    
    // Encabezado institucional
    $pdf->Cell(0, 0, 'REPÚBLICA BOLIVARIANA DE VENEZUELA', 0, 1, 'C');
    $pdf->Cell(0, 0, 'MINISTERIO DEL PODER POPULAR PARA LA EDUCACIÓN', 0, 1, 'C');
    $pdf->Ln(5);
    
    $pdf->SetFont('helvetica', 'B', 14);
    $pdf->Cell(0, 0, 'UNIDAD EDUCATIVA COLEGIO "SIMÓN RODRÍGUEZ"', 0, 1, 'C');
    $pdf->SetFont('helvetica', 'B', 12);
    $pdf->Cell(0, 0, 'VALLE DE LA PASCUA - ESTADO GUÁRICO', 0, 1, 'C');
    $pdf->Ln(8);
    
    // Línea decorativa
    $pdf->Line(10, $pdf->GetY(), 287, $pdf->GetY());
    $pdf->Ln(5);
    
    // Título del listado
    $pdf->SetFont('helvetica', 'B', 18);
    $pdf->Cell(0, 0, 'LISTADO OFICIAL DE ESTUDIANTES', 0, 1, 'C');
    $pdf->Ln(8);
    
    // Información de la sección
    $pdf->SetFont('helvetica', 'B', 12);
    $pdf->Cell(40, 0, 'SECCIÓN:', 0, 0, 'L');
    $pdf->SetFont('helvetica', '', 12);
    $pdf->Cell(60, 0, $seccion['nombre'], 0, 0, 'L');
    
    $pdf->SetFont('helvetica', 'B', 12);
    $pdf->Cell(40, 0, 'AÑO ESCOLAR:', 0, 0, 'L');
    $pdf->SetFont('helvetica', '', 12);
    $pdf->Cell(0, 0, $seccion['año_escolar'], 0, 1, 'L');
    
    $pdf->SetFont('helvetica', 'B', 12);
    $pdf->Cell(40, 6, 'NIVEL:', 0, 0, 'L');
    $pdf->SetFont('helvetica', '', 12);
    $pdf->Cell(60, 6, ucfirst($seccion['nivel']), 0, 0, 'L');
    
    $pdf->SetFont('helvetica', 'B', 12);
    $pdf->Cell(40, 6, 'TOTAL ESTUDIANTES:', 0, 0, 'L');
    $pdf->SetFont('helvetica', '', 12);
    $pdf->Cell(0, 6, $total_estudiantes, 0, 1, 'L');
    
    $pdf->SetFont('helvetica', 'B', 12);
    $pdf->Cell(40, 6, 'FECHA DE EMISIÓN:', 0, 0, 'L');
    $pdf->SetFont('helvetica', '', 12);
    $pdf->Cell(0, 6, $fecha_actual, 0, 1, 'L');
    
    $pdf->Ln(10);
    
    // Encabezado de la tabla
    $pdf->SetFillColor(60, 60, 60);
    $pdf->SetTextColor(255, 255, 255);
    $pdf->SetFont('helvetica', 'B', 9);
    
    // Definir anchos de columnas
    $widths = array(15, 25, 50, 50, 25, 50, 25, 30);
    
    // Encabezados de la tabla
    $headers = array(
        'N°',
        'CÉDULA ESCOLAR',
        'APELLIDOS',
        'NOMBRES', 
        'FECHA NAC.',
        'REPRESENTANTE',
        'PARENTESCO',
        'TELÉFONO'
    );
    
    for ($i = 0; $i < count($headers); $i++) {
        $pdf->Cell($widths[$i], 8, $headers[$i], 1, 0, 'C', true);
    }
    $pdf->Ln();
    
    // Restaurar color del texto
    $pdf->SetTextColor(0, 0, 0);
    $pdf->SetFont('helvetica', '', 8);
    
    // Datos de los estudiantes
    $fill = false;
    $contador = 1;
    
    foreach ($estudiantes as $estudiante) {
        // Alternar color de fondo para mejor lectura
        if ($fill) {
            $pdf->SetFillColor(240, 240, 240);
        } else {
            $pdf->SetFillColor(255, 255, 255);
        }
        
        $fecha_nacimiento = $estudiante['fecha_nacimiento'] ? date('d/m/Y', strtotime($estudiante['fecha_nacimiento'])) : '';
        
        // Número
        $pdf->Cell($widths[0], 6, $contador, 1, 0, 'C', $fill);
        
        // Cédula escolar
        $pdf->Cell($widths[1], 6, $estudiante['cedula_escolar'], 1, 0, 'C', $fill);
        
        // Apellidos
        $pdf->Cell($widths[2], 6, $estudiante['estudiante_apellido'], 1, 0, 'L', $fill);
        
        // Nombres
        $pdf->Cell($widths[3], 6, $estudiante['estudiante_nombre'], 1, 0, 'L', $fill);
        
        // Fecha nacimiento
        $pdf->Cell($widths[4], 6, $fecha_nacimiento, 1, 0, 'C', $fill);
        
        // Representante
        $pdf->Cell($widths[5], 6, $estudiante['representante_apellido'] . ' ' . $estudiante['representante_nombre'], 1, 0, 'L', $fill);
        
        // Parentesco
        $pdf->Cell($widths[6], 6, ucfirst($estudiante['parentesco']), 1, 0, 'C', $fill);
        
        // Teléfono
        $pdf->Cell($widths[7], 6, $estudiante['telefono_representante'] ?: 'N/A', 1, 1, 'C', $fill);
        
        $contador++;
        $fill = !$fill;
        
        // Verificar si necesita nueva página
        if ($pdf->GetY() > 180) {
            $pdf->AddPage();
            
            // Volver a poner el encabezado en la nueva página
            $pdf->SetFillColor(60, 60, 60);
            $pdf->SetTextColor(255, 255, 255);
            $pdf->SetFont('helvetica', 'B', 9);
            
            for ($i = 0; $i < count($headers); $i++) {
                $pdf->Cell($widths[$i], 8, $headers[$i], 1, 0, 'C', true);
            }
            $pdf->Ln();
            
            $pdf->SetTextColor(0, 0, 0);
            $pdf->SetFont('helvetica', '', 8);
            $fill = false;
        }
    }
    
    $pdf->Ln(10);
    
    // Resumen
    $pdf->SetFont('helvetica', 'B', 10);
    $pdf->Cell(0, 6, 'RESUMEN:', 0, 1, 'L');
    $pdf->SetFont('helvetica', '', 10);
    $pdf->Cell(0, 6, 'Total de estudiantes matriculados: ' . $total_estudiantes, 0, 1, 'L');
    
    if ($seccion['capacidad']) {
        $pdf->Cell(0, 6, 'Capacidad de la sección: ' . $seccion['capacidad'] . ' estudiantes', 0, 1, 'L');
        $ocupacion = round(($total_estudiantes / $seccion['capacidad']) * 100, 1);
        $pdf->Cell(0, 6, 'Porcentaje de ocupación: ' . $ocupacion . '%', 0, 1, 'L');
    }
    
    $pdf->Ln(15);
    
    // Firmas
    $firma_y = $pdf->GetY();
    
    // Firma de la directora
    $pdf->SetFont('helvetica', 'B', 10);
    $pdf->Cell(100, 0, '', 0, 0, 'C');
    $pdf->Cell(0, 0, '_________________________', 0, 1, 'C');
    $pdf->Cell(100, 6, '', 0, 0, 'C');
    $pdf->Cell(0, 6, 'Dra. Norena Hernández', 0, 1, 'C');
    $pdf->Cell(100, 6, '', 0, 0, 'C');
    $pdf->Cell(0, 6, 'Directora', 0, 1, 'C');
    $pdf->Cell(100, 6, '', 0, 0, 'C');
    $pdf->Cell(0, 6, 'Unidad Educativa Colegio "Simón Rodríguez"', 0, 1, 'C');
    
    // Sello de la institución (lado izquierdo)
    $pdf->SetY($firma_y);
    $pdf->SetFont('helvetica', 'B', 10);
    $pdf->Cell(0, 0, '_________________________', 0, 1, 'L');
    $pdf->Cell(0, 6, 'Sello y Timbre de la Institución', 0, 1, 'L');
    
    $pdf->Ln(10);
    
    // Pie de página
    $pdf->SetFont('helvetica', 'I', 8);
    $pdf->Cell(0, 0, 'Documento generado electrónicamente el ' . $fecha_actual, 0, 1, 'C');
    
    // Salida del PDF
    $pdf->Output('listado_estudiantes_' . $seccion['nombre'] . '.pdf', 'D');
}
?>