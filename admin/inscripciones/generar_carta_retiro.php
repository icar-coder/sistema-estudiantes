
<?php
require_once '../../includes/auth.php';
require_once '../../includes/database.php';
checkAuth();
checkRole(['admin']);

$inscripcion_id = $_GET['id'] ?? null;

if (!$inscripcion_id) {
    error_log("Error en " . __FILE__ . ": ID de inscripciÃ³n no especificado.");
    $_SESSION['error_sistema'] = "OcurriÃ³ un error al procesar la solicitud. El equipo tÃ©cnico fue notificado.";
    header('Location: ' . ($_SERVER['HTTP_REFERER'] ?? '/SistemaEstudiantes/'));
    exit;
}

// Procesar el formulario de retiro
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $causa_retiro = $_POST['causa_retiro'];
    $observaciones = $_POST['observaciones'] ?? '';
    
    // AquÃ­ podrÃ­as guardar esta informaciÃ³n en la base de datos si quieres
    // Por ahora solo generamos la carta
    
    // Redirigir a la vista de la carta
    header("Location: ver_carta_retiro.php?id=$inscripcion_id&causa=" . urlencode($causa_retiro) . "&observaciones=" . urlencode($observaciones));
    exit;
}

// Obtener informaciÃ³n de la inscripciÃ³n
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
        error_log("Error en " . __FILE__ . ": InscripciÃ³n no encontrada.");
        $_SESSION['error_sistema'] = "OcurriÃ³ un error al procesar la solicitud. El equipo tÃ©cnico fue notificado.";
        header('Location: ' . ($_SERVER['HTTP_REFERER'] ?? '/SistemaEstudiantes/'));
        exit;
    }
    
} catch (PDOException $e) {
    error_log("Error en " . __FILE__ . ": " . $e->getMessage());
    $_SESSION['error_sistema'] = "OcurriÃ³ un error al procesar la solicitud. El equipo tÃ©cnico fue notificado.";
    header('Location: ' . ($_SERVER['HTTP_REFERER'] ?? '/SistemaEstudiantes/'));
    exit;
}

// Calcular edad
$edad = '';
if ($inscripcion['fecha_nacimiento']) {
    $fecha_nac = new DateTime($inscripcion['fecha_nacimiento']);
    $hoy = new DateTime();
    $edad = $hoy->diff($fecha_nac)->y;
}
?>

<?php include '../../includes/header.php'; ?>

<div class="content-wrapper" style="padding: 20px;">
    <?php include '../../includes/sidebar_admin.php'; ?>
    
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2>Generar Carta de Retiro</h2>
        <a href="gestionar.php" class="btn btn-outline-secondary">
            <i class="fas fa-arrow-left me-2"></i>Volver a Inscripciones
        </a>
    </div>

    <div class="card">
        <div class="card-header">
            <h5 class="card-title mb-0">Datos del Estudiante</h5>
        </div>
        <div class="card-body">
            <div class="row mb-4">
                <div class="col-md-6">
                    <strong>Estudiante:</strong> <?php echo htmlspecialchars($inscripcion['estudiante_nombre'] . ' ' . $inscripcion['estudiante_apellido']); ?>
                </div>
                <div class="col-md-6">
                    <strong>CÃ©dula Escolar:</strong> <?php echo htmlspecialchars($inscripcion['cedula_escolar'] ?: 'No asignada'); ?>
                </div>
            </div>
            <div class="row mb-4">
                <div class="col-md-6">
                    <strong>SecciÃ³n/Grado:</strong> <?php echo htmlspecialchars($inscripcion['seccion_nombre'] . ' - ' . ucfirst($inscripcion['nivel'])); ?>
                </div>
                <div class="col-md-6">
                    <strong>Año Escolar:</strong> <?php echo htmlspecialchars($inscripcion['año_escolar']); ?>
                </div>
            </div>
        </div>
    </div>

    <div class="card mt-4">
        <div class="card-header">
            <h5 class="card-title mb-0">Motivo del Retiro</h5>
        </div>
        <div class="card-body">
            <form method="POST" action="">
                <div class="row">
                    <div class="col-md-12 mb-3">
                        <label for="causa_retiro" class="form-label">Causa del Retiro *</label>
                        <select class="form-select" id="causa_retiro" name="causa_retiro" required>
                            <option value="">Seleccionar causa...</option>
                            <option value="CAMBIO DE INSTITUCION">CAMBIO DE INSTITUCIÃ“N</option>
                            <option value="CAMBIO DE RESIDENCIA">CAMBIO DE RESIDENCIA</option>
                            <option value="PROBLEMAS ECONOMICOS">PROBLEMAS ECONÃ“MICOS</option>
                            <option value="PROBLEMAS DE SALUD">PROBLEMAS DE SALUD</option>
                            <option value="OTROS MOTIVOS PERSONALES">OTROS MOTIVOS PERSONALES</option>
                            <option value="FINALIZACION DE ESTUDIOS">FINALIZACIÃ“N DE ESTUDIOS</option>
                            <option value="OTRO">OTRO (Especificar en observaciones)</option>
                        </select>
                    </div>
                </div>
                
                <div class="row">
                    <div class="col-md-12 mb-3">
                        <label for="observaciones" class="form-label">Observaciones Adicionales</label>
                        <textarea class="form-control" id="observaciones" name="observaciones" rows="3" 
                                  placeholder="Detalles adicionales sobre el retiro..."></textarea>
                    </div>
                </div>
                
                <div class="d-grid gap-2 d-md-flex justify-content-md-end">
                    <a href="gestionar.php" class="btn btn-outline-secondary me-md-2">
                        <i class="fas fa-times me-2"></i>Cancelar
                    </a>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-file-pdf me-2"></i>Generar Carta de Retiro
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Mostrar/ocultar campo de observaciones segÃºn selecciÃ³n
    const causaSelect = document.getElementById('causa_retiro');
    const observacionesTextarea = document.getElementById('observaciones');
    
    causaSelect.addEventListener('change', function() {
        if (this.value === 'OTRO') {
            observacionesTextarea.placeholder = 'Por favor, especifique la causa del retiro...';
            observacionesTextarea.required = true;
        } else {
            observacionesTextarea.placeholder = 'Detalles adicionales sobre el retiro...';
            observacionesTextarea.required = false;
        }
    });
});
</script>

<?php include '../../includes/footer.php'; ?>
