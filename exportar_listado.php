<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/database.php';
checkAuth();
checkRole(['admin']);

$stmt = $pdo->query('SELECT e.id, e.cedula_escolar, e.nombre, e.apellido, e.fecha_nacimiento, e.telefono, r.nombre AS rep_nombre, r.apellido AS rep_apellido, s.nombre AS seccion_nombre, g.nombre AS grado_nombre FROM estudiantes e LEFT JOIN representantes r ON r.id = e.representante_id LEFT JOIN inscripciones i ON i.estudiante_id = e.id AND i.status = "activo" LEFT JOIN secciones s ON s.id = i.seccion_id LEFT JOIN grados g ON g.id = s.grado_id ORDER BY e.apellido, e.nombre');
$estudiantes = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Listado de estudiantes</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 30px; color: #111827; }
        h1 { text-align: center; }
        table { width: 100%; border-collapse: collapse; margin-top: 20px; }
        th, td { border: 1px solid #d1d5db; padding: 8px; text-align: left; font-size: 12px; }
        th { background: #f3f4f6; }
        .print-btn { margin-bottom: 20px; }
        @media print { .print-btn { display: none; } }
    </style>
</head>
<body>
    <button class="print-btn" onclick="window.print()">Imprimir / Guardar como PDF</button>
    <h1>Listado de estudiantes</h1>
    <table>
        <thead>
            <tr>
                <th>#</th>
                <th>Cédula</th>
                <th>Nombre</th>
                <th>Fecha nacimiento</th>
                <th>Teléfono</th>
                <th>Representante</th>
                <th>Sección</th>
                <th>Grado</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($estudiantes as $index => $estudiante): ?>
                <tr>
                    <td><?php echo $index + 1; ?></td>
                    <td><?php echo htmlspecialchars($estudiante['cedula_escolar'] ?? ''); ?></td>
                    <td><?php echo htmlspecialchars($estudiante['nombre'] . ' ' . $estudiante['apellido']); ?></td>
                    <td><?php echo htmlspecialchars($estudiante['fecha_nacimiento'] ?? ''); ?></td>
                    <td><?php echo htmlspecialchars($estudiante['telefono'] ?? ''); ?></td>
                    <td><?php echo htmlspecialchars(trim(($estudiante['rep_nombre'] ?? '') . ' ' . ($estudiante['rep_apellido'] ?? '')) ?: 'Sin registro'); ?></td>
                    <td><?php echo htmlspecialchars($estudiante['seccion_nombre'] ?? ''); ?></td>
                    <td><?php echo htmlspecialchars($estudiante['grado_nombre'] ?? ''); ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</body>
</html>
