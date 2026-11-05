<?php
// admin/documentos/generar_buena_conducta.php
require_once '../../includes/auth.php';
require_once '../../includes/database.php';
checkAuth();
checkRole(['admin']);

$estudiante_id = intval($_GET['estudiante_id'] ?? 0);

if (!$estudiante_id) {
    die("ID de estudiante no especificado");
}

// Obtener datos del estudiante + representante + inscripción
try {
    $stmt = $pdo->prepare("
        SELECT 
            e.*,
            r.nombre AS rep_nombre,
            r.apellido AS rep_apellido,
            r.cedula AS rep_cedula,
            r.parentesco,
            s.nombre AS seccion_nombre,
            g.nombre AS grado_nombre,
            ae.nombre AS año_escolar,
            p.nombre AS profesor_nombre,
            p.apellido AS profesor_apellido
        FROM estudiantes e
        LEFT JOIN representantes r ON e.representante_id = r.id
        LEFT JOIN inscripciones i ON e.id = i.estudiante_id AND i.status = 'activo'
        LEFT JOIN secciones s ON i.seccion_id = s.id
        LEFT JOIN grados g ON s.grado_id = g.id
        LEFT JOIN años_escolares ae ON i.año_escolar_id = ae.id
        LEFT JOIN profesores p ON s.profesor_id = p.id
        WHERE e.id = ?
        ORDER BY i.fecha_inscripcion DESC
        LIMIT 1
    ");
    $stmt->execute([$estudiante_id]);
    $est = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$est) {
        die("Estudiante no encontrado");
    }
} catch (PDOException $e) {
    error_log("Error en generar_buena_conducta: " . $e->getMessage());
    die("Error al cargar datos del estudiante");
}

$edad = $est['fecha_nacimiento'] 
    ? (new DateTime($est['fecha_nacimiento']))->diff(new DateTime())->y 
    : null;

function mesEspanol($m) {
    return [1=>'Enero','Febrero','Marzo','Abril','Mayo','Junio','Julio',
            'Agosto','Septiembre','Octubre','Noviembre','Diciembre'][$m] ?? '';
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Constancia de Buena Conducta - <?php echo $est['nombre'] . ' ' . $est['apellido']; ?></title>
    <style>
        @page { margin: 2.5cm; size: letter; }
        body { font-family: 'Times New Roman', Times, serif; font-size: 14px; line-height: 1.6; color: #000; margin: 0; padding: 0; }
        .container { max-width: 21cm; margin: 0 auto; padding: 20px; }
        .title { text-align: center; font-size: 16px; font-weight: bold; margin: 25px 0; text-decoration: underline; text-transform: uppercase; }
        .content { text-align: justify; margin-bottom: 20px; }
        .highlight { font-weight: bold; text-transform: uppercase; }
        .signature { margin-top: 60px; text-align: center; }
        .signature-line { border-top: 1px solid #000; width: 300px; margin: 0 auto 5px auto; padding-top: 10px; }
        .footer-note { font-size: 12px; margin-top: 5px; }
        .no-print { position: fixed; top: 20px; right: 20px; z-index: 1000; }
        .no-print button { padding: 10px 20px; border: none; border-radius: 5px; cursor: pointer; color: white; font-size: 14px; }
        @media print { .no-print { display: none; } }
    </style>
</head>
<body>
    <div class="no-print">
        <button onclick="window.print()" style="background:#007bff;">🖨️ Imprimir</button>
        <button onclick="window.close()" style="background:#dc3545; margin-left:8px;">❌ Cerrar</button>
    </div>

    <div class="container">
        <div class="title">Constancia de Buena Conducta</div>

        <div class="content">
            <p>
                Quien Suscribe <span class="highlight">Dra. Norena Hernández</span>, 
                Directora de la Unidad Educativa Colegio <span class="highlight">"Simón Rodríguez"</span>, 
                que funciona en Valle de la Pascua, Municipio Autónomo Leonardo Infante del Estado Guárico, 
                hace constar que el(la) estudiante:
            </p>

            <p style="margin: 20px 0; padding: 15px; border: 1px solid #ccc; background: #f9f9f9;">
                <strong>Nombre:</strong> <?php echo strtoupper($est['apellido'] . ', ' . $est['nombre']); ?><br>
                <strong>Cédula Escolar:</strong> <?php echo htmlspecialchars($est['cedula_escolar'] ?: 'No asignada'); ?><br>
                <strong>Grado/Sección:</strong> <?php echo htmlspecialchars(($est['grado_nombre'] ?? 'N/A') . ' ' . ($est['seccion_nombre'] ?? '')); ?><br>
                <strong>Año Escolar:</strong> <?php echo htmlspecialchars($est['año_escolar'] ?? 'No asignado'); ?>
                <?php if ($edad): ?><br><strong>Edad:</strong> <?php echo $edad; ?> años<?php endif; ?>
            </p>

            <p>
                Ha observado durante su permanencia en esta institución una conducta 
                <span class="highlight">EXCELENTE</span>, demostrando respeto hacia sus profesores, 
                compañeros y personal administrativo, cumpliendo con las normas de convivencia 
                establecidas en el manual de la institución.
            </p>

            <p>
                Constancia que se expide a petición de la parte interesada en Valle de la Pascua, 
                a los <strong><?php echo date('d'); ?></strong> días del mes de 
                <strong><?php echo mesEspanol((int)date('n')); ?></strong> del 
                <strong><?php echo date('Y'); ?></strong>.
            </p>
        </div>

        <div class="signature">
            <div class="signature-line"></div>
            <div><strong>Dra. Norena Hernández</strong></div>
            <div>Directora</div>
            <div class="footer-note">NH/lc.</div>
        </div>
    </div>
</body>
</html>