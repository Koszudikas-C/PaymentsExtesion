<?php
require __DIR__ . '/../vendor/autoload.php';

use Dotenv\Dotenv;
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

$path = __DIR__.'/../';

$dotenv = Dotenv::createImmutable($path);
$dotenv->load();

$mail = new PHPMailer(true);
try {
    $mail->isSMTP();
    $mail->Host       = $_ENV['MAIL_SMTP_HOST'];
    $mail->SMTPAuth   = true;
    $mail->Username   = $_ENV['MAIL_USER'];
    $mail->Password   = $_ENV['MAIL_PASS'];
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
    $mail->Port       = $_ENV['MAIL_SMTP_PORT'];

    $mail->setFrom($_ENV['MAIL_USER'], 'Teste');
    $mail->addAddress('matheusprgc@gmail.com');
    $mail->Subject = 'Teste de Autenticacao';
    $mail->Body    = 'Se este e-mail chegar, o .env foi corrigido!';

    $mail->send();
    echo "Sucesso! Autenticação funcionando.\n";
} catch (Exception $e) {
    echo "Erro ainda persiste: " . $mail->ErrorInfo . "\n";
}
