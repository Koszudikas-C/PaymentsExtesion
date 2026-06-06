<?php

namespace App\Services;

use App\Interfaces\EmailServiceInterface;
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
use Monolog\Logger;

class PHPMailerService implements EmailServiceInterface
{
    private array $config;

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    protected function createMailer(): PHPMailer
    {
        return new PHPMailer(true);
    }

    public function sendLicenseEmail(string $to, string $licenseCode, Logger $log, string $customerName = 'Usuário', string $templateName = 'license_email.html'): bool
    {
        $mail = $this->createMailer();
        try {
            // SMTP Config
            $mail->isSMTP();
            $mail->Host = $this->config['host'];
            $mail->SMTPAuth = true;
            $mail->Username = $this->config['username'];
            $mail->Password = $this->config['password'];
            $mail->Port = $this->config['port'];

            // Define o tipo de criptografia com base na porta
            if ($mail->Port == 587) {
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            } else {
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
            }
            $mail->CharSet = 'UTF-8';

            $mail->Debugoutput = function ($str, $level) use ($log) {
                $log->debug("SMTP Detail: " . trim($str));
            };

            $mail->SMTPOptions = [
                'ssl' => [
                    'verify_peer' => false,
                    'verify_peer_name' => false,
                    'allow_self_signed' => true
                ]
            ];

            // Recipients
            $fromEmail = $this->config['from'] ?? $this->config['username'];
            $fromName  = $this->config['from_name'] ?? 'Exportador Histórico Pro Licença';
            $mail->setFrom($fromEmail, $fromName);
            $mail->addAddress($to);

            // Content
            $templatePath = __DIR__ . '/../../templates/' . ltrim($templateName, '/\\');

            if (file_exists($templatePath)) {
                $body = file_get_contents($templatePath);
                $body = str_replace('{{customer_name}}', $customerName, $body);
                $body = str_replace('{{license_key}}', $licenseCode, $body);
            } else {
                $log->warning('Email template not found, using fallback body', ['path' => $templatePath]);
                $body = "Sua chave de ativacao vitalicia e: <b>{$licenseCode}</b>";
            }

            $mail->isHTML(true);
            $mail->Subject = 'Seu Acesso Pro Liberado!';
            $mail->Body = $body;

            $mail->send();
            return true;
        } catch (Exception $e) {
            $log->critical('SMTP Error', ['msg' => $mail->ErrorInfo]);
            return false;
        }
    }
}
