<?php
require_once '../../includes/auth.php';
require_once '../../includes/database.php';

checkAuth();
checkRole(['admin']);

$parametros = [];
$profesorRaw = $_GET['id'] ?? '';
$profesorId = is_string($profesorRaw)
    ? filter_var($profesorRaw, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])
    : false;
if ($profesorId !== false) {
    $parametros['profesor_id'] = (int) $profesorId;
}

$url = 'asignaciones.php';
if ($parametros !== []) {
    $url .= '?' . http_build_query($parametros);
}
header('Location: ' . $url);
exit;
