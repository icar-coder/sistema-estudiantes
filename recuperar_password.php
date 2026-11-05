<?php
session_start();
require_once __DIR__ . '/includes/database.php';

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
                $expira = date('Y-m-d H:i:s', strtotime('+1 hour'));

                $update = $pdo->prepare('UPDATE usuarios SET reset_token = ?, reset_expira = ? WHERE id = ?');
                $update->execute([$token, $expira, $usuario['id']]);

                $base = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . dirname($_SERVER['SCRIPT_NAME']);
                $reset_link = rtrim($base, '/') . '/reset_password.php?token=' . urlencode($token);

                $body = "Hola {$usuario['username']},\n\n"
                    . "Recibimos una solicitud para restablecer tu contraseña.\n"
                    . "Haz clic en el siguiente enlace para continuar: \n\n"
                    . "$reset_link\n\n"
                    . "Si no pediste este cambio, puedes ignorar este mensaje.\n\n"
                    . "Este enlace expira en 1 hora.";

                $email_enviado = false;
                if (function_exists('mail')) {
                    $headers = "From: no-reply@sistemaestudiantes.local\r\n" . "Reply-To: no-reply@sistemaestudiantes.local\r\n" . "X-Mailer: PHP/" . phpversion();
                    $email_enviado = @mail($email, 'Recuperación de contraseña - Sistema Estudiantil', $body, $headers);
                }

                $_SESSION['reset_debug_link'] = $reset_link;
                $mensaje = $email_enviado
                    ? 'Se envió un enlace de recuperación a tu correo electrónico.'
                    : 'Se generó un enlace de recuperación. Copia este enlace para continuar: ' . $reset_link;
                $tipo_mensaje = 'success';
            } else {
                $mensaje = 'Si el correo está registrado en el sistema, recibirás un enlace de recuperación.';
                $tipo_mensaje = 'success';
            }
        } catch (Exception $e) {
            error_log('Error al recuperar contraseña: ' . $e->getMessage());
            $mensaje = 'Ocurrió un problema al procesar la solicitud. Inténtalo nuevamente.';
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

            <?php if (!empty($_SESSION['reset_debug_link'])): ?>
                <div class="alert alert-warning">
                    <strong>Enlace de prueba:</strong><br>
                    <a href="<?php echo htmlspecialchars($_SESSION['reset_debug_link']); ?>"><?php echo htmlspecialchars($_SESSION['reset_debug_link']); ?></a>
                </div>
                <?php unset($_SESSION['reset_debug_link']); ?>
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
