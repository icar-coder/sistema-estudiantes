<?php
require_once '../../includes/auth.php';
require_once '../../includes/database.php';
checkAuth();
checkRole(['admin']);

// Verificar que se proporcionó un ID de inscripción
if (!isset($_GET['inscripcion_id']) || empty($_GET['inscripcion_id'])) {
    error_log("Error en " . __FILE__ . ": No se proporcionó ID de inscripción.");
    $_SESSION['error_sistema'] = "Ocurrió un error al procesar la solicitud. El equipo técnico fue notificado.";
    header('Location: ' . ($_SERVER['HTTP_REFERER'] ?? app_url()));
    exit;
}

$inscripcion_id = $_GET['inscripcion_id'];

// Función para obtener datos de la inscripción
function obtenerDatosInscripcion($inscripcion_id, $pdo) {
    try {
        $stmt = $pdo->prepare("
            SELECT 
                e.cedula_escolar,
                e.nombre as estudiante_nombre,
                e.apellido as estudiante_apellido,
                e.fecha_nacimiento,
                e.lugar_nacimiento,
                e.direccion,
                e.telefono,
                e.email as estudiante_email,
                s.nombre as seccion_nombre,
                s.nivel,
                ae.nombre as año_escolar,
                ae.fecha_inicio,
                ae.fecha_fin,
                i.fecha_inscripcion,
                i.status,
                r.cedula as cedula_representante,
                r.nombre as representante_nombre,
                r.apellido as representante_apellido,
                r.parentesco,
                r.telefono as telefono_representante,
                r.email as email_representante,
                r.profesion,
                r.lugar_trabajo
            FROM inscripciones i
            JOIN estudiantes e ON i.estudiante_id = e.id
            JOIN secciones s ON i.seccion_id = s.id
            JOIN años_escolares ae ON i.año_escolar_id = ae.id
            JOIN representantes r ON e.representante_id = r.id
            WHERE i.id = ?
        ");
        $stmt->execute([$inscripcion_id]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
        
    } catch (PDOException $e) {
        error_log("Error al obtener datos de inscripción: " . $e->getMessage());
        return false;
    }
}

// Obtener datos
$datos = obtenerDatosInscripcion($inscripcion_id, $pdo);

if (!$datos) {
    error_log("Error en " . __FILE__ . ": No se encontraron datos para la inscripción ID: " . $inscripcion_id);
    $_SESSION['error_sistema'] = "Ocurrió un error al procesar la solicitud. El equipo técnico fue notificado.";
    header('Location: ' . ($_SERVER['HTTP_REFERER'] ?? app_url()));
    exit;
}

// Función auxiliar para obtener nombre del mes
function obtenerNombreMes($numeroMes) {
    $meses = [
        1 => 'enero', 2 => 'febrero', 3 => 'marzo', 4 => 'abril',
        5 => 'mayo', 6 => 'junio', 7 => 'julio', 8 => 'agosto',
        9 => 'septiembre', 10 => 'octubre', 11 => 'noviembre', 12 => 'diciembre'
    ];
    return $meses[intval($numeroMes)] ?? '';
}

// Generar constancia con TCPDF
generarConstanciaPDF($datos);

function generarConstanciaPDF($datos) {
    // Incluir TCPDF
    require_once('../../includes/tcpdf/tcpdf.php');
    
    // Fechas formateadas
    $fecha_actual = date('d/m/Y');
    $fecha_nacimiento = $datos['fecha_nacimiento'] ? date('d/m/Y', strtotime($datos['fecha_nacimiento'])) : 'No especificada';
    $fecha_inscripcion = $datos['fecha_inscripcion'] ? date('d/m/Y', strtotime($datos['fecha_inscripcion'])) : $fecha_actual;
    
    // Nivel educativo formateado
    $nivel_educativo = ucfirst($datos['nivel']);
    
    // Crear nuevo documento PDF
    $pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
    
    // Configurar documento
    $pdf->SetCreator('Sistema de Gestión Académica');
    $pdf->SetAuthor('Colegio Simón Rodríguez');
    $pdf->SetTitle('Constancia de Matrícula - ' . $datos['estudiante_nombre'] . ' ' . $datos['estudiante_apellido']);
    $pdf->SetSubject('Constancia de Matrícula');
    
    // Eliminar header y footer por defecto
    $pdf->setPrintHeader(false);
    $pdf->setPrintFooter(false);
    
    // Establecer márgenes
    $pdf->SetMargins(15, 15, 15);
    $pdf->SetAutoPageBreak(TRUE, 15);
    
    // Agregar página
    $pdf->AddPage();
    
    // Configurar fuente
    $pdf->SetFont('helvetica', '', 10);
    
    // Número de constancia
    $pdf->SetFont('helvetica', 'B', 9);
    $pdf->Cell(0, 0, 'Constancia N°: MAT-' . str_pad($datos['cedula_escolar'], 6, '0', STR_PAD_LEFT) . '-' . date('Y'), 0, 1, 'R');
    $pdf->Ln(5);
    
    // Encabezado institucional
    $pdf->SetFont('helvetica', 'B', 12);
    $pdf->Cell(0, 0, 'REPÚBLICA BOLIVARIANA DE VENEZUELA', 0, 1, 'C');
    $pdf->Cell(0, 0, 'MINISTERIO DEL PODER POPULAR PARA LA EDUCACIÓN', 0, 1, 'C');
    $pdf->Ln(5);
    
    $pdf->SetFont('helvetica', 'B', 14);
    $pdf->Cell(0, 0, 'UNIDAD EDUCATIVA COLEGIO "SIMÓN RODRÍGUEZ"', 0, 1, 'C');
    $pdf->SetFont('helvetica', 'B', 12);
    $pdf->Cell(0, 0, 'VALLE DE LA PASCUA - ESTADO GUÁRICO', 0, 1, 'C');
    $pdf->Ln(10);
    
    // Línea decorativa
    $pdf->Line(15, $pdf->GetY(), 195, $pdf->GetY());
    $pdf->Ln(5);
    
    // Título de la constancia
    $pdf->SetFont('helvetica', 'B', 16);
    $pdf->Cell(0, 0, 'CONSTANCIA DE MATRÍCULA', 0, 1, 'C');
    $pdf->Ln(15);
    
    // Texto introductorio
    $pdf->SetFont('helvetica', '', 11);
    $intro_text = "Quien suscribe, Dirección de la Unidad Educativa Colegio \"Simón Rodríguez\", hace constar por medio de la presente que el(la) estudiante mencionado(a) a continuación se encuentra oficialmente matriculado(a) en esta institución educativa.";
    $pdf->MultiCell(0, 6, $intro_text, 0, 'J');
    $pdf->Ln(10);
    
    // Datos del estudiante
    $pdf->SetFont('helvetica', 'B', 12);
    $pdf->Cell(0, 0, 'DATOS DEL ESTUDIANTE', 0, 1, 'L');
    $pdf->Ln(5);
    
    $pdf->SetFont('helvetica', '', 10);
    
    // Tabla de datos del estudiante
    $estudiante_data = array(
        'Cédula Escolar:' => $datos['cedula_escolar'],
        'Nombre Completo:' => $datos['estudiante_nombre'] . ' ' . $datos['estudiante_apellido'],
        'Fecha de Nacimiento:' => $fecha_nacimiento,
        'Lugar de Nacimiento:' => $datos['lugar_nacimiento'] ?: 'No especificado',
        'Año Escolar:' => $datos['año_escolar'],
        'Sección/Grado:' => $datos['seccion_nombre'] . ' - ' . $nivel_educativo,
        'Fecha de Inscripción:' => $fecha_inscripcion,
        'Estado de Matrícula:' => ucfirst($datos['status'])
    );
    
    foreach ($estudiante_data as $label => $value) {
        $pdf->SetFont('helvetica', 'B', 10);
        $pdf->Cell(50, 6, $label, 0, 0, 'L');
        $pdf->SetFont('helvetica', '', 10);
        $pdf->Cell(0, 6, $value, 0, 1, 'L');
        $pdf->Ln(2);
    }
    
    $pdf->Ln(8);
    
    // Datos del representante
    $pdf->SetFont('helvetica', 'B', 12);
    $pdf->Cell(0, 0, 'DATOS DEL REPRESENTANTE', 0, 1, 'L');
    $pdf->Ln(5);
    
    $pdf->SetFont('helvetica', '', 10);
    
    // Tabla de datos del representante
    $representante_data = array(
        'Cédula de Identidad:' => $datos['cedula_representante'],
        'Nombre Completo:' => $datos['representante_nombre'] . ' ' . $datos['representante_apellido'],
        'Parentesco:' => ucfirst($datos['parentesco']),
        'Teléfono:' => $datos['telefono_representante'] ?: 'No especificado',
        'Profesión:' => $datos['profesion'] ?: 'No especificada',
        'Lugar de Trabajo:' => $datos['lugar_trabajo'] ?: 'No especificado'
    );
    
    foreach ($representante_data as $label => $value) {
        $pdf->SetFont('helvetica', 'B', 10);
        $pdf->Cell(50, 6, $label, 0, 0, 'L');
        $pdf->SetFont('helvetica', '', 10);
        $pdf->Cell(0, 6, $value, 0, 1, 'L');
        $pdf->Ln(2);
    }
    
    $pdf->Ln(10);
    
    // Texto final
    $pdf->SetFont('helvetica', '', 11);
    $texto_final = "La presente constancia se expide a solicitud de la parte interesada en Valle de la Pascua, a los " . date('d') . " días del mes de " . obtenerNombreMes(date('m')) . " del " . date('Y') . ".";
    $pdf->MultiCell(0, 6, $texto_final, 0, 'J');
    $pdf->Ln(20);
    
    // Firmas
    $firma_y = $pdf->GetY();
    
    // Firma de la directora
    $pdf->SetFont('helvetica', 'B', 10);
    $pdf->Cell(85, 0, '', 0, 0, 'C');
    $pdf->Cell(0, 0, '_________________________', 0, 1, 'C');
    $pdf->Cell(85, 6, '', 0, 0, 'C');
    $pdf->Cell(0, 6, 'Dra. Norena Hernández', 0, 1, 'C');
    $pdf->Cell(85, 6, '', 0, 0, 'C');
    $pdf->Cell(0, 6, 'Directora', 0, 1, 'C');
    $pdf->Cell(85, 6, '', 0, 0, 'C');
    $pdf->Cell(0, 6, 'Unidad Educativa Colegio "Simón Rodríguez"', 0, 1, 'C');
    
    // Sello de la institución (lado izquierdo)
    $pdf->SetY($firma_y);
    $pdf->SetFont('helvetica', 'B', 10);
    $pdf->Cell(0, 0, '_________________________', 0, 1, 'L');
    $pdf->Cell(0, 6, 'Sello y Timbre de la Institución', 0, 1, 'L');
    $pdf->Cell(0, 6, '', 0, 1, 'L');
    $pdf->Cell(0, 6, '', 0, 1, 'L');
    
    $pdf->Ln(15);
    
    // Sello electrónico
    $pdf->SetFont('helvetica', 'I', 8);
    $pdf->Cell(0, 0, 'Constancia generada electrónicamente el ' . $fecha_actual, 0, 1, 'C');
    
    $pdf->Ln(10);
    
    // Información de contacto (footer)
    $pdf->SetFont('helvetica', '', 8);
    $pdf->Cell(0, 0, 'Unidad Educativa Colegio "Simón Rodríguez"', 0, 1, 'C');
    $pdf->Cell(0, 0, 'Calle Guamachal, Valle de la Pascua, Estado Guárico', 0, 1, 'C');
    $pdf->Cell(0, 0, 'Teléfono: (0238) XXX-XXXX | Email: uesimonrodriguez@educacion.edu.ve', 0, 1, 'C');
    
    // Salida del PDF
    $pdf->Output('constancia_matricula_' . $datos['cedula_escolar'] . '.pdf', 'D');
}
?>