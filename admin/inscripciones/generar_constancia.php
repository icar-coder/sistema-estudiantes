<?php
require_once '../../includes/auth.php';
require_once '../../includes/database.php';
checkAuth();
checkRole(['admin']);

$inscripcion_id = $_GET['id'] ?? null;

if (!$inscripcion_id) {
    error_log("Error en " . __FILE__ . ": ID de inscripción no especificado.");
    $_SESSION['error_sistema'] = "Ocurrió un error al procesar la solicitud. El equipo técnico fue notificado.";
    header('Location: ' . ($_SERVER['HTTP_REFERER'] ?? '/SistemaEstudiantes/'));
    exit;
}

// Obtener información completa de la inscripción
try {
    $sql = "
        SELECT 
            i.*,
            e.cedula_escolar,
            e.nombre as estudiante_nombre,
            e.apellido as estudiante_apellido,
            e.fecha_nacimiento,
            e.lugar_nacimiento,
            e.direccion,
            e.telefono,
            s.nombre as seccion_nombre,
            s.nivel,
            s.tipo_grado,
            ae.nombre as año_escolar,
            ae.fecha_inicio,
            ae.fecha_fin,
            r.nombre as representante_nombre,
            r.apellido as representante_apellido,
            r.cedula as representante_cedula,
            r.parentesco,
            r.telefono as representante_telefono,
            r.profesion
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
    
    // Obtener materias inscritas
    $stmt_tabla = $pdo->query("SHOW TABLES LIKE 'inscripciones_materias'");
    $tabla_existe = $stmt_tabla && $stmt_tabla->fetch(PDO::FETCH_ASSOC);

    if ($tabla_existe) {
        $sql_materias = "
            SELECT m.nombre, m.codigo, m.descripcion
            FROM inscripciones_materias im
            JOIN materias m ON im.materia_id = m.id
            WHERE im.inscripcion_id = ?
            ORDER BY m.nombre
        ";

        $stmt = $pdo->prepare($sql_materias);
        $stmt->execute([$inscripcion_id]);
        $materias = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $materias = [];
        error_log("La tabla 'inscripciones_materias' no existe; la constancia se está generando sin materias asociadas.");
    }

} catch (PDOException $e) {
    error_log("Error en " . __FILE__ . ": " . $e->getMessage());
    $_SESSION['error_sistema'] = "Ocurrió un error al procesar la solicitud. El equipo técnico fue notificado.";
    header('Location: ' . ($_SERVER['HTTP_REFERER'] ?? '/SistemaEstudiantes/'));
    exit;
}

// Si no tienes TCPDF, usar esta versión HTML para imprimir
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Constancia de Inscripción - <?php echo $inscripcion['estudiante_nombre'] . ' ' . $inscripcion['estudiante_apellido']; ?></title>
    <style>
        @page {
            margin: 2cm;
            size: letter;
        }
        body {
            font-family: 'Times New Roman', Times, serif;
            font-size: 14px;
            line-height: 1.4;
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
            border-bottom: 2px solid #000;
            padding-bottom: 15px;
        }
        .header h1 {
            font-size: 18px;
            font-weight: bold;
            margin: 0 0 5px 0;
            text-transform: uppercase;
        }
        .header h2 {
            font-size: 14px;
            font-weight: normal;
            margin: 0;
            font-style: italic;
        }
        .title {
            text-align: center;
            font-size: 16px;
            font-weight: bold;
            margin: 25px 0;
            text-decoration: underline;
        }
        .content {
            text-align: justify;
            margin-bottom: 20px;
        }
        .student-info {
            margin: 20px 0;
            padding: 15px;
            border: 1px solid #ccc;
            background-color: #f9f9f9;
        }
        .info-row {
            margin-bottom: 8px;
            display: flex;
        }
        .info-label {
            font-weight: bold;
            width: 200px;
            flex-shrink: 0;
        }
        .info-value {
            flex-grow: 1;
        }
        .materias-table {
            width: 100%;
            border-collapse: collapse;
            margin: 15px 0;
        }
        .materias-table th,
        .materias-table td {
            border: 1px solid #000;
            padding: 8px;
            text-align: left;
        }
        .materias-table th {
            background-color: #f0f0f0;
            font-weight: bold;
        }
        .footer {
            margin-top: 40px;
            text-align: center;
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
        .note {
            font-size: 12px;
            font-style: italic;
            margin-top: 10px;
        }
        .watermark {
            position: fixed;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%) rotate(-45deg);
            font-size: 80px;
            color: rgba(0,0,0,0.1);
            z-index: -1;
            pointer-events: none;
        }
        @media print {
            body {
                margin: 0;
                padding: 0;
            }
            .container {
                padding: 0;
            }
            .no-print {
                display: none;
            }
        }
    </style>
</head>
<body>
    <div class="watermark">U.E. SIMÓN RODRÍGUEZ</div>
    
    <div class="container">
        <!-- Encabezado institucional -->
        <div class="header">
            <h1>REPÚBLICA BOLIVARIANA DE VENEZUELA</h1>
            <h2>MINISTERIO DEL PODER POPULAR PARA LA EDUCACIÓN</h2>
            <h1>U.E.C "SIMÓN RODRÍGUEZ"</h1>
            <h2>VALLE DE LA PASCUA - MUNICIPIO LEONARDO INFANTE</h2>
            <h2>ZONA EDUCATIVA DEL ESTADO GUÁRICO</h2>
        </div>

        <!-- Título del documento -->
        <div class="title">
            CONSTANCIA DE INSCRIPCIÓN Y MATRÍCULA
        </div>

        <!-- Contenido principal -->
        <div class="content">
            <p>Quien Suscribe <strong>Dra. NORENA HERNÁNDEZ</strong>, Directora de la Unidad Educativa Colegio <strong>"SIMÓN RODRÍGUEZ"</strong>, que funciona en Valle de la Pascua, Municipio Autónomo Leonardo Infante del Estado Guárico, <strong>CERTIFICA</strong> que el(la) alumno(a):</p>
            
            <!-- Información del estudiante -->
            <div class="student-info">
                <div class="info-row">
                    <div class="info-label">NOMBRE COMPLETO:</div>
                    <div class="info-value"><strong><?php echo $inscripcion['estudiante_nombre'] . ' ' . $inscripcion['estudiante_apellido']; ?></strong></div>
                </div>
                <div class="info-row">
                    <div class="info-label">CÉDULA ESCOLAR:</div>
                    <div class="info-value"><?php echo $inscripcion['cedula_escolar'] ?: 'No asignada'; ?></div>
                </div>
                <div class="info-row">
                    <div class="info-label">FECHA NACIMIENTO:</div>
                    <div class="info-value"><?php echo $inscripcion['fecha_nacimiento'] ? date('d/m/Y', strtotime($inscripcion['fecha_nacimiento'])) : 'No registrada'; ?></div>
                </div>
                <div class="info-row">
                    <div class="info-label">LUGAR NACIMIENTO:</div>
                    <div class="info-value"><?php echo $inscripcion['lugar_nacimiento'] ?: 'No registrado'; ?></div>
                </div>
                <div class="info-row">
                    <div class="info-label">DIRECCIÓN:</div>
                    <div class="info-value"><?php echo $inscripcion['direccion'] ?: 'No registrada'; ?></div>
                </div>
            </div>

            <!-- Información del representante -->
            <p>Siendo representado(a) por:</p>
            <div class="student-info">
                <div class="info-row">
                    <div class="info-label">NOMBRE COMPLETO:</div>
                    <div class="info-value"><strong><?php echo $inscripcion['representante_nombre'] . ' ' . $inscripcion['representante_apellido']; ?></strong></div>
                </div>
                <div class="info-row">
                    <div class="info-label">CÉDULA DE IDENTIDAD:</div>
                    <div class="info-value"><?php echo $inscripcion['representante_cedula']; ?></div>
                </div>
                <div class="info-row">
                    <div class="info-label">PARENTESCO:</div>
                    <div class="info-value"><?php echo ucfirst($inscripcion['parentesco']); ?></div>
                </div>
                <div class="info-row">
                    <div class="info-label">TELÉFONO:</div>
                    <div class="info-value"><?php echo $inscripcion['representante_telefono'] ?: 'No registrado'; ?></div>
                </div>
                <div class="info-row">
                    <div class="info-label">PROFESIÓN:</div>
                    <div class="info-value"><?php echo $inscripcion['profesion'] ?: 'No especificada'; ?></div>
                </div>
            </div>

            <!-- Información académica -->
            <p>Se encuentra oficialmente <strong>INSCRITO(A) Y MATRICULADO(A)</strong> en esta institución para el año escolar:</p>
            <div class="student-info">
                <div class="info-row">
                    <div class="info-label">AÑO ESCOLAR:</div>
                    <div class="info-value"><strong><?php echo $inscripcion['año_escolar']; ?></strong></div>
                </div>
                <div class="info-row">
                    <div class="info-label">SECCIÓN/GRADO:</div>
                    <div class="info-value"><strong><?php echo $inscripcion['seccion_nombre'] . ' - ' . ucfirst($inscripcion['nivel']); ?></strong></div>
                </div>
                <div class="info-row">
                    <div class="info-label">FECHA INSCRIPCIÓN:</div>
                    <div class="info-value"><?php echo date('d/m/Y', strtotime($inscripcion['fecha_inscripcion'])); ?></div>
                </div>
                <div class="info-row">
                    <div class="info-label">PERÍODO ACADÉMICO:</div>
                    <div class="info-value">Desde <?php echo date('d/m/Y', strtotime($inscripcion['fecha_inicio'])); ?> hasta <?php echo date('d/m/Y', strtotime($inscripcion['fecha_fin'])); ?></div>
                </div>
            </div>

            <!-- Materias inscritas -->
            <?php if (!empty($materias)): ?>
                <p>Quedando inscrito(a) en las siguientes asignaturas:</p>
                <table class="materias-table">
                    <thead>
                        <tr>
                            <th width="10%">#</th>
                            <th width="60%">NOMBRE DE LA ASIGNATURA</th>
                            <th width="30%">CÓDIGO</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($materias as $index => $materia): ?>
                        <tr>
                            <td><?php echo $index + 1; ?></td>
                            <td><?php echo $materia['nombre']; ?></td>
                            <td><?php echo $materia['codigo']; ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>

            <p>Constancia que se expide a petición de la parte interesada en Valle de la Pascua, a los <strong><?php echo date('d'); ?></strong> días del mes de <strong><?php echo obtenerMesEspañol(date('n')); ?></strong> del <strong><?php echo date('Y'); ?></strong>.</p>
        </div>

        <!-- Firma -->
        <div class="signature">
            <div class="signature-line"></div>
            <div><strong>Dra. NORENA HERNÁNDEZ</strong></div>
            <div>Directora</div>
            <div class="note">
                NOTA: Vigencia año escolar <?php echo $inscripcion['año_escolar']; ?><br>
                NH/lc.
            </div>
        </div>
    </div>

    <!-- Botón de impresión (solo visible en navegador) -->
    <div class="no-print" style="position: fixed; top: 20px; right: 20px;">
        <button onclick="window.print()" style="padding: 10px 20px; background: #007bff; color: white; border: none; border-radius: 5px; cursor: pointer;">
            🖨️ Imprimir Constancia
        </button>
        <button onclick="window.close()" style="padding: 10px 20px; background: #dc3545; color: white; border: none; border-radius: 5px; cursor: pointer; margin-left: 10px;">
            ❌ Cerrar
        </button>
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