<?php
session_start();
if (isset($_SESSION['user_id'])) {
    switch ($_SESSION['rol']) {
        case 'admin':
            header('Location: admin/dashboard.php');
            break;
        case 'profesor':
            header('Location: profesor/dashboard.php');
            break;
        case 'estudiante':
            header('Location: estudiante/dashboard.php');
            break;
        default:
            header('Location: login.php');
    }
    exit();
} else {
    header('Location: login.php');
    exit();
}
?>