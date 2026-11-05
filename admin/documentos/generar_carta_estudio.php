<?php
// admin/documentos/generar_carta_estudio.php
require_once '../../includes/auth.php';
require_once '../../includes/database.php';
checkAuth();
checkRole(['admin']);

$estudiante_id = intval($_GET['estudiante_id'] ?? 0);

if (!$estudiante_id) {
    die("ID de estudiante no especificado");
}

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
            g.numero AS grado_numero,
            ae.nombre AS año_escolar,
            ae.fecha_inicio,
            ae.fecha_fin,
            i.fecha_inscripcion,
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
    error_log("Error en generar_carta_estudio: " . $e->getMessage());
    die("Error al cargar datos del estudiante");
}

function mesEspanol($m) {
    return [1=>'Enero','Febrero','Marzo','Abril','Mayo','Junio','Julio',
            'Agosto','Septiembre','Octubre','Noviembre','Diciembre'][$m] ?? '';
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Constancia de Estudio - <?php echo $est['nombre'] . ' ' . $est['apellido']; ?></title>
    <style>
        @page { margin: 2cm; size: letter; }
        body { font-family: 'Times New Roman', Times, serif; font-size: 14px; line-height: 1.5; color: #000; margin: 0; padding: 0; }
        .container { max-width: 21cm; margin: 0 auto; padding: 20px; }
        .header { text-align: center; border-bottom: 2px solid #000; padding-bottom: 15px; margin-bottom: 25px; }
        .header h1 { font-size: 16px; font-weight: bold; margin: 0 0 5px 0; }
        .header h2 { font-size: 13px; font-weight: normal; margin: 0; font-style: italic; }
        .title { text-align: center; font-size: 16px; font-weight: bold; margin: 25px 0; text-decoration: underline; text-transform: uppercase; }
        .content { text-align: justify; margin-bottom: 20px; }
        .highlight { font-weight: bold; text-transform: uppercase; }
        .info-box { margin: 15px 0; padding: 15px; border: 1px solid #ccc; background: #f9f9f9; }
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
        <div class="header">
            <h1>REPÚBLICA BOLIVARIANA DE VENEZUELA</h1>
            <h2>MINISTERIO DEL PODER POPULAR PARA LA EDUCACIÓN</h2>
            <h1>U.E.C "SIMÓN RODRÍGUEZ"</h1>
            <h2>VALLE DE LA PASCUA - ESTADO GUÁRICO</h2>
        </div>

        <div class="title">Constancia de Estudio</div>

        <div class="content">
            <p>
                Quien Suscribe <span class="highlight">Dra. Norena Hernández</span>, Directora de la 
                Unidad Educativa Colegio <span class="highlight">"Simón Rodríguez"</span>, hace constar 
                que el(la) estudiante:
            </p>

            <div class="info-box">
                <strong>Nombre:</strong> <?php echo strtoupper($est['apellido'] . ', ' . $est['nombre']); ?><br>
                <strong>Cédula Escolar:</strong> <?php echo htmlspecialchars($est['cedula_escolar'] ?: 'No asignada'); ?><br>
                <?php if ($est['fecha_nacimiento']): ?>
                    <strong>Fecha de Nacimiento:</strong> <?php echo date('d/m/Y', strtotime($est['fecha_nacimiento'])); ?><br>
                <?php endif; ?>
                <strong>Grado:</strong> <?php echo htmlspecialchars($est['grado_nombre'] ?? 'N/A'); ?><br>
                <strong>Sección:</strong> <?php echo htmlspecialchars($est['seccion_nombre'] ?? 'N/A'); ?><br>
                <strong>Año Escolar:</strong> <?php echo htmlspecialchars($est['año_escolar'] ?? 'No asignado'); ?>
            </div>

            <p>
                Se encuentra <span class="highlight">debidamente inscrito(a)</span> y cursando estudios 
                en esta institución durante el presente año escolar, cumpliendo con sus responsabilidades 
                académicas y asistiendo regularmente a clases.
            </p>

            <?php if ($est['rep_nombre']): ?>
            <p>
                <strong>Representante:</strong> <?php echo htmlspecialchars($est['rep_nombre'] . ' ' . $est['rep_apellido']); ?> 
                (C.I: <?php echo htmlspecialchars($est['rep_cedula']); ?>) — 
                <?php echo ucfirst($est['parentesco'] ?? ''); ?>
            </p>
            <?php endif; ?>

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