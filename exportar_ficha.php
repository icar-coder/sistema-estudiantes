<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/database.php';
checkAuth();
checkRole(['admin']);

$estudiante_id = (int) ($_GET['estudiante_id'] ?? 0);
if ($estudiante_id <= 0) {
    die('ID de estudiante no válido.');
}

$stmt = $pdo->prepare('SELECT e.*, r.nombre AS rep_nombre, r.apellido AS rep_apellido, r.cedula AS rep_cedula, r.telefono AS rep_telefono, s.nombre AS seccion_nombre, g.nombre AS grado_nombre FROM estudiantes e LEFT JOIN representantes r ON r.id = e.representante_id LEFT JOIN inscripciones i ON i.estudiante_id = e.id AND i.status = "activo" LEFT JOIN secciones s ON s.id = i.seccion_id LEFT JOIN grados g ON g.id = s.grado_id WHERE e.id = ? LIMIT 1');
$stmt->execute([$estudiante_id]);
$estudiante = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$estudiante) {
    die('No se encontró el estudiante solicitado.');
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Ficha del estudiante</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 30px; color: #1f2937; }
        h1 { text-align: center; margin-bottom: 20px; }
        table { width: 100%; border-collapse: collapse; margin-top: 20px; }
        th, td { border: 1px solid #d1d5db; padding: 10px; text-align: left; }
        th { background: #f3f4f6; }
        .print-btn { margin-bottom: 20px; } 
        @media print { .print-btn { display: none; } }
    </style>
</head>
<body>
    <button class="print-btn" onclick="window.print()">Imprimir / Guardar como PDF</button>
    <h1>Ficha del estudiante</h1>
    <table>
        <tr><th>Nombre</th><td><?php echo htmlspecialchars($estudiante['nombre'] . ' ' . $estudiante['apellido']); ?></td></tr>
        <tr><th>Cédula escolar</th><td><?php echo htmlspecialchars($estudiante['cedula_escolar'] ?? 'No registrada'); ?></td></tr>
        <tr><th>Fecha de nacimiento</th><td><?php echo htmlspecialchars($estudiante['fecha_nacimiento'] ?? 'No registrada'); ?></td></tr>
        <tr><th>Género</th><td><?php echo htmlspecialchars($estudiante['genero'] ?? 'No registrado'); ?></td></tr>
        <tr><th>Teléfono</th><td><?php echo htmlspecialchars($estudiante['telefono'] ?? 'No registrado'); ?></td></tr>
        <tr><th>Email</th><td><?php echo htmlspecialchars($estudiante['email'] ?? 'No registrado'); ?></td></tr>
        <tr><th>Dirección</th><td><?php echo htmlspecialchars($estudiante['direccion'] ?? 'No registrada'); ?></td></tr>
        <tr><th>Representante</th><td><?php echo htmlspecialchars(trim(($estudiante['rep_nombre'] ?? '') . ' ' . ($estudiante['rep_apellido'] ?? '')) ?: 'No registrado'); ?></td></tr>
        <tr><th>Cédula del representante</th><td><?php echo htmlspecialchars($estudiante['rep_cedula'] ?? 'No registrada'); ?></td></tr>
        <tr><th>Sección</th><td><?php echo htmlspecialchars($estudiante['seccion_nombre'] ?? 'No registrada'); ?></td></tr>
        <tr><th>Grado</th><td><?php echo htmlspecialchars($estudiante['grado_nombre'] ?? 'No registrado'); ?></td></tr>
    </table>
</body>
</html>
