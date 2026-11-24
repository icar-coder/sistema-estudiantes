<?php

require_once '../../includes/auth.php';
require_once '../../includes/database.php';
checkAuth();
checkRole(['admin']);

$stmt = $pdo->query("SELECT id FROM años_escolares WHERE estado = 'activo' LIMIT 1");
$añoId = (int) $stmt->fetchColumn();
$destino = '../años_escolares/configurar_plan.php';
if ($añoId > 0) {
    $destino .= '?año_escolar_id=' . $añoId;
}

header('Location: ' . $destino, true, 303);
exit;
