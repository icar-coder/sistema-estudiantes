<?php
session_start();
require_once __DIR__ . '/includes/database.php';
require_once __DIR__ . '/includes/paths.php';

$token = trim((string) ($_GET['token'] ?? ''));
$mensaje = '';
$tipo_mensaje = 'info';
$token_valido = false;

if ($token !== '') {
    try {
        $stmt = $pdo->prepare('SELECT id, username, email FROM usuarios WHERE reset_token = ? AND reset_expira > NOW() LIMIT 1');
        $stmt->execute([hash('sha256', $token)]);
        $usuario = $stmt->fetch(PDO::FETCH_ASSOC);
        $token_valido = (bool) $usuario;
    } catch (Exception $e) {
        error_log('Error validando token: ' . $e->getMessage());
        $token_valido = false;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token_post = trim((string) ($_POST['token'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');
    $confirm_password = (string) ($_POST['confirm_password'] ?? '');

    if ($token_post === '' || $password === '' || $confirm_password === '') {
        $mensaje = 'Todos los campos son obligatorios.';
        $tipo_mensaje = 'danger';
    } elseif (strlen($password) < 8) {
        $mensaje = 'La nueva contraseña debe tener al menos 8 caracteres.';
        $tipo_mensaje = 'danger';
    } elseif ($password !== $confirm_password) {
        $mensaje = 'Las contraseñas no coinciden.';
        $tipo_mensaje = 'danger';
    } else {
        try {
            $stmt = $pdo->prepare('SELECT id FROM usuarios WHERE reset_token = ? AND reset_expira > NOW() LIMIT 1');
            $stmt->execute([hash('sha256', $token_post)]);
            $usuario = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$usuario) {
                $mensaje = 'El enlace de recuperación no es válido o ya expiró.';
                $tipo_mensaje = 'danger';
            } else {
                $hash = password_hash($password, PASSWORD_DEFAULT);
                $update = $pdo->prepare('UPDATE usuarios SET password = ?, reset_token = NULL, reset_expira = NULL WHERE id = ?');
                $update->execute([$hash, $usuario['id']]);

                $_SESSION['mensaje_login'] = 'Tu contraseña se actualizó correctamente. Inicia sesión con tu nueva clave.';
                $_SESSION['tipo_mensaje'] = 'success';
                header('Location: ' . app_url('login.php'));
                exit;
            }
        } catch (Exception $e) {
            error_log('Error al guardar la nueva contraseña: ' . $e->getMessage());
            $mensaje = 'No se pudo actualizar la contraseña. Inténtalo nuevamente.';
            $tipo_mensaje = 'danger';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Restablecer contraseña</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { background: linear-gradient(135deg, #0f172a 0%, #334155 100%); min-height: 100vh; }
        .card { border-radius: 18px; box-shadow: 0 15px 35px rgba(0,0,0,0.12); }
    </style>
</head>
<body>
    <div class="container d-flex justify-content-center align-items-center min-vh-100">
        <div class="card p-4 w-100" style="max-width: 480px;">
            <div class="text-center mb-4">
                <div class="rounded-circle bg-success d-inline-flex align-items-center justify-content-center text-white mb-3" style="width: 64px; height: 64px;">
                    <i class="fas fa-key"></i>
                </div>
                <h2 class="mb-1">Restablecer contraseña</h2>
                <p class="text-muted mb-0">Crea una nueva contraseña segura.</p>
            </div>

            <?php if ($mensaje): ?>
                <div class="alert alert-<?php echo htmlspecialchars($tipo_mensaje); ?>" role="alert"><?php echo htmlspecialchars($mensaje); ?></div>
            <?php endif; ?>

            <?php if (!$token_valido && $token !== ''): ?>
                <div class="alert alert-warning">El enlace de recuperación no es válido o ya expiró.</div>
            <?php endif; ?>

            <?php if ($token !== '' && $token_valido): ?>
                <form method="POST" action="">
                    <input type="hidden" name="token" value="<?php echo htmlspecialchars($token); ?>">
                    <div class="mb-3">
                        <label for="password" class="form-label">Nueva contraseña</label>
                        <input type="password" class="form-control" id="password" name="password" minlength="8" maxlength="100" required>
                    </div>
                    <div class="mb-3">
                        <label for="confirm_password" class="form-label">Confirmar contraseña</label>
                        <input type="password" class="form-control" id="confirm_password" name="confirm_password" minlength="8" maxlength="100" required>
                    </div>
                    <button type="submit" class="btn btn-success w-100">Guardar nueva contraseña</button>
                </form>
            <?php elseif ($token === ''): ?>
                <div class="alert alert-warning">No se proporcionó un token válido para restablecer la contraseña.</div>
            <?php endif; ?>

            <div class="text-center mt-3">
                <a href="login.php" class="text-decoration-none">Ir al inicio</a>
            </div>
        </div>
    </div>
</body>
</html>
