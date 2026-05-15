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

    public function sendLicenseEmail(string $to, string $licenseCode, Logger $log): bool
    {
        $mail = new PHPMailer(true);
        try {
            $mail->isSMTP();
            $mail->Host       = $this->config['host'];
            $mail->SMTPAuth   = true;
            $mail->Username   = $this->config['username'];
            $mail->Password   = $this->config['password'];
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
            $mail->Port       = $this->config['port'];
            $mail->CharSet    = 'UTF-8';

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

            $mail->setFrom($this->config['username'], 'Equipe ExtensionWebDrive');
            $mail->addAddress($to);

            $mail->isHTML(true);
            $mail->Subject = 'Seu Acesso Pro Liberado!';
            $mail->Body    = "Sua chave de ativacao vitalicia e: <b>{$licenseCode}</b>";

            $mail->send();
            return true;
        } catch (Exception $e) {
            $log->critical('SMTP Error', ['msg' => $mail->ErrorInfo]);
            return false;
        }
    }
}
