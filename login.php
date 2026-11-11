<?php
require_once __DIR__ . '/includes/paths.php';

if (headers_sent()) {
    error_log('Error en ' . __FILE__ . ': headers ya enviados antes de iniciar sesión.');
    echo 'Ocurrió un error al iniciar sesión. Intente nuevamente.';
    exit;
}

session_start();
require_once 'includes/database.php';

$error = '';
$intentos_restantes = null;
$mensaje_sesion = $_SESSION['mensaje_login'] ?? '';
$tipo_sesion = $_SESSION['tipo_mensaje'] ?? '';
unset($_SESSION['mensaje_login'], $_SESSION['tipo_mensaje']);

function obtenerNombreUsuario($pdo, $rol, $relacion_id) {
    if (!$relacion_id) {
        return 'Usuario';
    }

    try {
        switch ($rol) {
            case 'admin':
                return 'Administrador';
            case 'profesor':
                $stmt = $pdo->prepare('SELECT nombre, apellido FROM profesores WHERE id = ?');
                $stmt->execute([$relacion_id]);
                $profesor = $stmt->fetch(PDO::FETCH_ASSOC);
                return $profesor ? $profesor['nombre'] . ' ' . $profesor['apellido'] : 'Profesor';
            case 'estudiante':
                $stmt = $pdo->prepare('SELECT nombre, apellido FROM estudiantes WHERE id = ?');
                $stmt->execute([$relacion_id]);
                $estudiante = $stmt->fetch(PDO::FETCH_ASSOC);
                return $estudiante ? $estudiante['nombre'] . ' ' . $estudiante['apellido'] : 'Estudiante';
            default:
                return 'Usuario';
        }
    } catch (PDOException $e) {
        return 'Usuario';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($username === '' || $password === '') {
        $error = 'Usuario y contraseña son obligatorios';
    } else {
        try {
            $stmt = $pdo->prepare('SELECT id, username, password, email, rol, relacion_id, estado, intentos_fallidos, bloqueado_hasta FROM usuarios WHERE username = ? LIMIT 1');
            $stmt->execute([$username]);
            $usuario = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($usuario && $usuario['bloqueado_hasta']) {
                $bloqueo = new DateTime($usuario['bloqueado_hasta']);
                $ahora = new DateTime();

                if ($bloqueo > $ahora) {
                    $minutos = max(1, (int) ceil(($bloqueo->getTimestamp() - $ahora->getTimestamp()) / 60));
                    $error = "Cuenta bloqueada temporalmente. Intenta nuevamente en $minutos minutos.";
                    error_log('Login bloqueado: user=' . $username . ' intento desde ' . ($_SERVER['REMOTE_ADDR'] ?? 'IP desconocida'));
                } else {
                    $stmt = $pdo->prepare('UPDATE usuarios SET intentos_fallidos = 0, bloqueado_hasta = NULL WHERE id = ?');
                    $stmt->execute([$usuario['id']]);
                    $usuario['intentos_fallidos'] = 0;
                    $usuario['bloqueado_hasta'] = null;
                }
            }

            if ($error === '') {
                $credencialesValidas = $usuario && $usuario['estado'] === 'activo' && password_verify($password, $usuario['password']);

                if ($credencialesValidas) {
                    $stmt = $pdo->prepare('UPDATE usuarios SET intentos_fallidos = 0, bloqueado_hasta = NULL, ultimo_login = NOW() WHERE id = ?');
                    $stmt->execute([$usuario['id']]);

                    session_regenerate_id(true);

                    $_SESSION['user_id'] = $usuario['id'];
                    $_SESSION['username'] = $usuario['username'];
                    $_SESSION['email'] = $usuario['email'];
                    $_SESSION['rol'] = $usuario['rol'];
                    $_SESSION['relacion_id'] = $usuario['relacion_id'];
                    $_SESSION['ultima_actividad'] = time();
                    $_SESSION['creada_en'] = time();
                    $_SESSION['nombre'] = obtenerNombreUsuario($pdo, $usuario['rol'], $usuario['relacion_id']);

                    $redirecciones = [
                        'admin' => app_url('admin/dashboard.php'),
                        'profesor' => app_url('profesor/dashboard.php'),
                        'estudiante' => app_url('estudiante/dashboard.php')
                    ];

                    header('Location: ' . ($redirecciones[$usuario['rol']] ?? app_url()));
                    exit;
                }

                if (!$usuario) {
                    $error = 'Usuario o contraseña incorrectos';
                    error_log('Login fallido: usuario inexistente ' . $username . ' desde ' . ($_SERVER['REMOTE_ADDR'] ?? 'IP desconocida'));
                } elseif ($usuario['estado'] !== 'activo') {
                    $error = 'La cuenta está inactiva. Contacta al administrador.';
                } else {
                    $nuevosIntentos = (int) $usuario['intentos_fallidos'] + 1;
                    $maxIntentos = 5;

                    if ($nuevosIntentos >= $maxIntentos) {
                        $stmt = $pdo->prepare('UPDATE usuarios SET intentos_fallidos = ?, bloqueado_hasta = DATE_ADD(NOW(), INTERVAL 15 MINUTE), ultimo_intento = NOW() WHERE id = ?');
                        $stmt->execute([$nuevosIntentos, $usuario['id']]);
                        $error = 'Demasiados intentos fallidos. Cuenta bloqueada por 15 minutos.';
                        error_log('Cuenta bloqueada por intentos fallidos: user=' . $username);
                    } else {
                        $stmt = $pdo->prepare('UPDATE usuarios SET intentos_fallidos = ?, ultimo_intento = NOW() WHERE id = ?');
                        $stmt->execute([$nuevosIntentos, $usuario['id']]);
                        $intentos_restantes = $maxIntentos - $nuevosIntentos;
                        $error = 'Usuario o contraseña incorrectos. Te quedan ' . $intentos_restantes . ' intentos.';
                        error_log('Login fallido: user=' . $username . ' intento #' . $nuevosIntentos);
                    }
                }
            }
        } catch (PDOException $e) {
            error_log('Error en login: ' . $e->getMessage());
            $error = 'Error del sistema. Intenta más tarde.';
        }
    }
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - Sistema Estudiantil</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="css/style.css" rel="stylesheet">
    <style>
        .login-container {
            min-height: 100vh;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .login-card {
            background: white;
            border-radius: 15px;
            box-shadow: 0 15px 35px rgba(0,0,0,0.1);
            overflow: hidden;
            width: 100%;
            max-width: 400px;
        }
        .login-header {
            background: linear-gradient(45deg, #3498db, #2980b9);
            color: white;
            padding: 30px 20px;
            text-align: center;
        }
        .login-body {
            padding: 30px;
        }
        .btn-login {
            background: linear-gradient(45deg, #3498db, #2980b9);
            border: none;
            color: white;
            padding: 12px;
            font-weight: 600;
        }
        .btn-login:hover {
            background: linear-gradient(45deg, #2980b9, #3498db);
            color: white;
        }
    </style>
</head>
<body>
    <div class="login-container">
        <div class="login-card">
            <div class="login-header">
                <i class="fas fa-graduation-cap fa-3x mb-3"></i>
                <h2>Sistema Estudiantil</h2>
                <p class="mb-0">Iniciar Sesión</p>
            </div>
            <div class="login-body">
                <?php if ($mensaje_sesion): ?>
                    <div class="alert alert-<?php echo htmlspecialchars($tipo_sesion ?: 'info'); ?> alert-dismissible fade show" role="alert">
                        <i class="fas fa-info-circle me-2"></i>
                        <?php echo htmlspecialchars($mensaje_sesion); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <?php if ($error): ?>
                    <div class="alert alert-danger alert-dismissible fade show" role="alert">
                        <i class="fas fa-exclamation-circle me-2"></i>
                        <?php echo htmlspecialchars($error); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>
                
                <form method="POST" action="">
                    <div class="mb-3">
                        <label for="username" class="form-label">
                            <i class="fas fa-user me-2"></i>Usuario
                        </label>
                        <input type="text" class="form-control" id="username" name="username" 
                               value="<?php echo htmlspecialchars($_POST['username'] ?? ''); ?>" 
                               maxlength="50" required autofocus>
                    </div>
                    
                    <div class="mb-3">
                        <label for="password" class="form-label">
                            <i class="fas fa-lock me-2"></i>Contraseña
                        </label>
                        <input type="password" class="form-control" id="password" name="password" maxlength="100" required>
                    </div>
                    
                    <div class="d-grid gap-2">
                        <button type="submit" class="btn btn-login btn-lg">
                            <i class="fas fa-sign-in-alt me-2"></i>Ingresar
                        </button>
                    </div>
                </form>

                <div class="text-center mt-2">
                    <a href="recuperar_password.php" class="text-decoration-none small">
                        ¿Olvidaste tu contraseña?
                    </a>
                </div>

                <?php if ($intentos_restantes !== null && $intentos_restantes <= 2): ?>
                    <div class="alert alert-warning mt-3 mb-0 small" role="alert">
                        <i class="fas fa-exclamation-triangle me-1"></i>
                        <strong>Atención:</strong> Te quedan <?php echo (int) $intentos_restantes; ?> intentos antes del bloqueo temporal.
                    </div>
                <?php endif; ?>
                
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>