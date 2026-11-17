<?php
session_start();
require_once __DIR__ . '/includes/database.php';
require_once __DIR__ . '/includes/paths.php';
require_once __DIR__ . '/includes/email.php';

$mensaje = '';
$tipo_mensaje = 'info';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim((string) ($_POST['email'] ?? ''));

    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $mensaje = 'Debe ingresar un correo electrónico válido.';
        $tipo_mensaje = 'danger';
    } else {
        try {
            $stmt = $pdo->prepare('SELECT id, username, email, estado FROM usuarios WHERE email = ? AND estado = "activo" LIMIT 1');
            $stmt->execute([$email]);
            $usuario = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($usuario) {
                $token = bin2hex(random_bytes(32));
                $tokenHash = hash('sha256', $token);

                $update = $pdo->prepare('UPDATE usuarios SET reset_token = ?, reset_expira = DATE_ADD(NOW(), INTERVAL 1 HOUR) WHERE id = ?');
                $update->execute([$tokenHash, $usuario['id']]);

                $localConfigPath = __DIR__ . '/includes/config.local.php';
                $localConfig = is_file($localConfigPath) ? require $localConfigPath : [];
                if (!is_array($localConfig)) {
                    throw new RuntimeException('La configuración local debe devolver un arreglo.');
                }

                $appUrl = getenv('APP_URL');
                if ($appUrl === false) {
                    $appUrl = $localConfig['app_url'] ?? '';
                }
                $appUrl = rtrim((string) $appUrl, '/');
                $appUrlParts = parse_url($appUrl);
                if (
                    $appUrlParts === false
                    || !isset($appUrlParts['scheme'], $appUrlParts['host'])
                    || !in_array(strtolower($appUrlParts['scheme']), ['http', 'https'], true)
                    || (isset($appUrlParts['path']) && trim($appUrlParts['path'], '/') !== '')
                    || isset($appUrlParts['user'])
                    || isset($appUrlParts['pass'])
                    || isset($appUrlParts['query'])
                    || isset($appUrlParts['fragment'])
                ) {
                    throw new RuntimeException('APP_URL debe ser el origen público del sistema, sin ruta ni parámetros.');
                }

                $resetLink = $appUrl . app_url('reset_password.php') . '?token=' . rawurlencode($token);
                enviarCorreoRecuperacion($email, $usuario['username'], $resetLink);

            }

            $mensaje = 'Si el correo está registrado en el sistema, recibirás un enlace de recuperación.';
            $tipo_mensaje = 'success';
        } catch (Exception $e) {
            if (isset($usuario['id'])) {
                try {
                    $invalidate = $pdo->prepare('UPDATE usuarios SET reset_token = NULL, reset_expira = NULL WHERE id = ?');
                    $invalidate->execute([$usuario['id']]);
                } catch (Exception $invalidateError) {
                    error_log('No se pudo invalidar el token de recuperación: ' . $invalidateError->getMessage());
                }
            }
            error_log('Error al recuperar contraseña: ' . $e->getMessage());
            $mensaje = 'Si el correo está registrado en el sistema, recibirás un enlace de recuperación.';
            $tipo_mensaje = 'success';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Recuperar contraseña</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        body { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); min-height: 100vh; }
        .card { border-radius: 18px; box-shadow: 0 15px 35px rgba(0,0,0,0.12); }
    </style>
</head>
<body>
    <div class="container d-flex justify-content-center align-items-center min-vh-100">
        <div class="card p-4 w-100" style="max-width: 480px;">
            <div class="text-center mb-4">
                <div class="rounded-circle bg-primary bg-gradient d-inline-flex align-items-center justify-content-center text-white mb-3" style="width: 64px; height: 64px;">
                    <i class="fas fa-lock"></i>
                </div>
                <h2 class="mb-1">Recuperar contraseña</h2>
                <p class="text-muted mb-0">Ingresa tu correo para recibir un enlace de restablecimiento.</p>
            </div>

            <?php if ($mensaje): ?>
                <div class="alert alert-<?php echo htmlspecialchars($tipo_mensaje); ?>" role="alert">
                    <?php echo nl2br(htmlspecialchars($mensaje)); ?>
                </div>
            <?php endif; ?>

            <form method="POST" action="">
                <div class="mb-3">
                    <label for="email" class="form-label">Correo electrónico</label>
                    <input type="email" class="form-control" id="email" name="email" required maxlength="100" placeholder="usuario@ejemplo.com">
                </div>
                <button type="submit" class="btn btn-primary w-100">
                    <i class="fas fa-paper-plane me-2"></i>Enviar enlace
                </button>
            </form>

            <div class="text-center mt-3">
                <a href="login.php" class="text-decoration-none">Volver al inicio de sesión</a>
            </div>
        </div>
    </div>
</body>
</html>
