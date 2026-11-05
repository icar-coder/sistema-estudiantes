<?php
// admin/areas_aprendizaje/procesar_indicadores_predefinidos.php
require_once '../../includes/auth.php';
require_once '../../includes/database.php';
checkAuth();
checkRole(['admin']);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: gestionar_indicadores.php');
    exit;
}

$area_id = $_POST['area_id'] ?? null;

if (!$area_id) {
    $_SESSION['mensaje'] = "No se especificó el área";
    $_SESSION['tipo_mensaje'] = "danger";
    header('Location: gestionar_indicadores.php');
    exit;
}

// Verificar que el área existe
try {
    $stmt = $pdo->prepare("SELECT * FROM areas_aprendizaje WHERE id = ? AND estado = 'activa'");
    $stmt->execute([$area_id]);
    $area = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$area) {
        $_SESSION['mensaje'] = "Área de aprendizaje no encontrada";
        $_SESSION['tipo_mensaje'] = "danger";
        header('Location: gestionar_indicadores.php');
        exit;
    }
} catch (PDOException $e) {
    $_SESSION['mensaje'] = "Error al verificar el área: " . $e->getMessage();
    $_SESSION['tipo_mensaje'] = "danger";
    header('Location: gestionar_indicadores.php');
    exit;
}

// Indicadores predefinidos por área
$indicadores_predefinidos = [
    'Lengua y Literatura' => [
        ['nombre' => 'Destreza cognitiva en la comunicación', 'orden' => 1],
        ['nombre' => 'Identifica, organiza la lectura', 'orden' => 2],
        ['nombre' => 'Expresa con claridad sus necesidades e intereses', 'orden' => 3],
        ['nombre' => 'Expresa sentimientos e ideas en forma diferentes', 'orden' => 4],
        ['nombre' => 'Forma palabras en grupos', 'orden' => 5]
    ],
    'Matemática' => [
        ['nombre' => 'Comprende diferentes procesos matemáticos', 'orden' => 1],
        ['nombre' => 'Lectura de Números Naturales', 'orden' => 2],
        ['nombre' => 'Realiza operaciones suma y resta', 'orden' => 3],
        ['nombre' => 'Reconoce Cantidades', 'orden' => 4],
        ['nombre' => 'Relaciona con objetos más que-menos que tanto como', 'orden' => 5],
        ['nombre' => 'Ordena números en recta', 'orden' => 6]
    ],
    'Ciencias Naturales' => [
        ['nombre' => 'Observa y describe fenómenos naturales', 'orden' => 1],
        ['nombre' => 'Identifica seres vivos y su entorno', 'orden' => 2],
        ['nombre' => 'Realiza experimentos sencillos', 'orden' => 3],
        ['nombre' => 'Cuida y valora el medio ambiente', 'orden' => 4]
    ],
    'Ciencias Sociales' => [
        ['nombre' => 'Conocimiento y valoración de los héroes patrios nacionales', 'orden' => 1],
        ['nombre' => 'Conmemoración y valoración de efemérides nacionales', 'orden' => 2],
        ['nombre' => 'Pueblos originarios', 'orden' => 3],
        ['nombre' => 'Valoración y aplicación de las normas de convivencias', 'orden' => 4]
    ],
    'Educación Física' => [
        ['nombre' => 'Manifiesta placer en las actividades en los que participa', 'orden' => 1],
        ['nombre' => 'Expresa respeto y tolerancia hacia los demás', 'orden' => 2],
        ['nombre' => 'Demuestra constancia y perseverancia en las actividades realizadas', 'orden' => 3],
        ['nombre' => 'Manifiesta confianza en sí misma', 'orden' => 4]
    ],
    'Inglés' => [
        ['nombre' => 'Demuestra Interés por la materia', 'orden' => 1],
        ['nombre' => 'Realiza practicas con entusiasmo', 'orden' => 2],
        ['nombre' => 'Participa con entusiasmo en los talleres de la materia', 'orden' => 3]
    ],
    'Arte y Patrimonio' => [
        ['nombre' => 'Expresa creatividad en sus trabajos', 'orden' => 1],
        ['nombre' => 'Muestra interés por las manifestaciones artísticas', 'orden' => 2],
        ['nombre' => 'Valora el patrimonio cultural', 'orden' => 3]
    ],
    'Formación Ciudadana' => [
        ['nombre' => 'Respeta las normas de convivencia', 'orden' => 1],
        ['nombre' => 'Muestra valores de solidaridad y respeto', 'orden' => 2],
        ['nombre' => 'Participa activamente en actividades comunitarias', 'orden' => 3]
    ]
];

// Verificar si hay indicadores predefinidos para esta área
if (!isset($indicadores_predefinidos[$area['nombre']])) {
    $_SESSION['mensaje'] = "No hay indicadores predefinidos para el área '{$area['nombre']}'";
    $_SESSION['tipo_mensaje'] = "warning";
    header('Location: gestionar_indicadores.php');
    exit;
}

// Procesar la carga de indicadores predefinidos
try {
    $pdo->beginTransaction();
    $indicadores_agregados = 0;
    $indicadores_existentes = 0;
    
    // Obtener indicadores existentes para evitar duplicados
    $stmt = $pdo->prepare("SELECT nombre FROM areas_indicadores WHERE area_id = ?");
    $stmt->execute([$area_id]);
    $indicadores_existentes_nombres = array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'nombre');
    
    foreach ($indicadores_predefinidos[$area['nombre']] as $indicador_predefinido) {
        // Verificar si ya existe un indicador con el mismo nombre
        if (in_array($indicador_predefinido['nombre'], $indicadores_existentes_nombres)) {
            $indicadores_existentes++;
            continue;
        }
        
        // Insertar el indicador predefinido
        $stmt = $pdo->prepare("
            INSERT INTO areas_indicadores (area_id, nombre, escala, orden) 
            VALUES (?, ?, 'L', ?)
        ");
        $stmt->execute([
            $area_id, 
            $indicador_predefinido['nombre'],
            $indicador_predefinido['orden']
        ]);
        
        $indicadores_agregados++;
    }
    
    $pdo->commit();
    
    // Preparar mensaje de resultado
    $mensaje = "Indicadores predefinidos cargados para '{$area['nombre']}': ";
    $detalles = [];
    
    if ($indicadores_agregados > 0) {
        $detalles[] = "$indicadores_agregados nuevos agregados";
    }
    
    if ($indicadores_existentes > 0) {
        $detalles[] = "$indicadores_existentes ya existían";
    }
    
    $mensaje .= implode(', ', $detalles);
    
    $_SESSION['mensaje'] = $mensaje;
    $_SESSION['tipo_mensaje'] = "success";
    
} catch (PDOException $e) {
    $pdo->rollBack();
    $_SESSION['mensaje'] = "Error al cargar indicadores predefinidos: " . $e->getMessage();
    $_SESSION['tipo_mensaje'] = "danger";
}

header('Location: gestionar_indicadores.php');
exit;
?>