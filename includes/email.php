<?php

function enviarCorreoRecuperacion(string $destinatario, string $nombreUsuario, string $enlace): void
{
    $localConfigPath = __DIR__ . '/config.local.php';
    $localConfig = is_file($localConfigPath) ? require $localConfigPath : [];
    if (!is_array($localConfig)) {
        throw new RuntimeException('La configuración local debe devolver un arreglo.');
    }

    $mailConfig = $localConfig['mail'] ?? [];
    $getSetting = static function (string $name, string $localKey, $default = null) use ($mailConfig) {
        $environmentValue = getenv($name);
        if ($environmentValue !== false) {
            return $environmentValue;
        }

        return $mailConfig[$localKey] ?? $default;
    };

    $host = (string) $getSetting('MAIL_HOST', 'host', 'smtp.gmail.com');
    $port = (int) $getSetting('MAIL_PORT', 'port', 587);
    $username = (string) $getSetting('MAIL_USERNAME', 'username', '');
    $password = (string) $getSetting('MAIL_PASSWORD', 'password', '');
    $encryption = strtolower((string) $getSetting('MAIL_ENCRYPTION', 'encryption', 'tls'));
    $fromAddress = (string) $getSetting('MAIL_FROM_ADDRESS', 'from_address', $username);
    $fromName = (string) $getSetting('MAIL_FROM_NAME', 'from_name', 'Sistema de Gestión Estudiantil');

    if ($host === '' || $port < 1 || $port > 65535 || $username === '' || $password === '') {
        throw new RuntimeException('La configuración SMTP está incompleta.');
    }
    if (!filter_var($fromAddress, FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('La dirección remitente SMTP no es válida.');
    }
    if (!in_array($encryption, ['tls', 'ssl'], true)) {
        throw new RuntimeException('MAIL_ENCRYPTION debe ser tls o ssl.');
    }

    $autoloadPath = dirname(__DIR__) . '/vendor/autoload.php';
    if (!is_file($autoloadPath)) {
        throw new RuntimeException('Falta instalar las dependencias de Composer.');
    }
    require_once $autoloadPath;

    $mailer = new PHPMailer\PHPMailer\PHPMailer(true);
    $mailer->isSMTP();
    $mailer->Host = $host;
    $mailer->Port = $port;
    $mailer->SMTPAuth = true;
    $mailer->Username = $username;
    $mailer->Password = $password;
    $mailer->SMTPSecure = $encryption === 'ssl'
        ? PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS
        : PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
    $mailer->Timeout = 15;
    $mailer->CharSet = PHPMailer\PHPMailer\PHPMailer::CHARSET_UTF8;
    $mailer->setFrom($fromAddress, $fromName);
    $mailer->addAddress($destinatario);
    $mailer->isHTML(false);
    $mailer->Subject = 'Recuperación de contraseña - Sistema Estudiantil';
    $mailer->Body = "Hola {$nombreUsuario},\n\n"
        . "Recibimos una solicitud para restablecer tu contraseña.\n"
        . "Abre este enlace para continuar:\n\n"
        . $enlace . "\n\n"
        . "Si no solicitaste este cambio, puedes ignorar este mensaje.\n"
        . "El enlace vence en 1 hora.";
    $mailer->send();
}
