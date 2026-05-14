<?php
require __DIR__ . '/vendor/autoload.php';

use Dotenv\Dotenv;
use Monolog\Logger;
use Monolog\Handler\RotatingFileHandler;
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

$dotenv = Dotenv::createImmutable(__DIR__);
$dotenv->load();

$log = new Logger('payments');
$log->pushHandler(new RotatingFileHandler(__DIR__ . '/logs/app.log', 30, Logger::DEBUG));

$headers = getallheaders();
$asaasToken = $headers['asaas-access-token'] ?? '';

if ($asaasToken !== $_ENV['ASAAS_TOKEN']) {
    $log->warning('Invalid Token', ['received' => $asaasToken]);
    http_response_code(401);
    exit;
}

http_response_code(200);

$input = file_get_contents('php://input');
$data = json_decode($input, true);

if (!$data) {
    $log->error('Empty Payload');
    exit;
}

if ($data['event'] === 'PAYMENT_RECEIVED') {
    $payment = $data['payment'];
    $customerId = $payment['customer'];

    $customerInfo = getInfoUserAsaas($customerId, $log);

    if (!$customerInfo) {
        exit;
    }

    $email = $customerInfo['email'] ?? '';
    $whatsapp = $customerInfo['mobilePhone'] ?? $customerInfo['phone'] ?? 'unknown';

    $log->info('Payment Confirmed', ['whatsapp' => $whatsapp, 'email' => $email]);

    $license = strtoupper(substr(hash('sha256', $whatsapp . $_ENV['LICENSE_SALT']), 0, 16));
    $formattedLicense = implode('-', str_split($license, 4));

    if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $log->error('Invalid Email Address', ['received' => $email, 'customer_id' => $customerId]);
        exit;
    }

    if (sendEmail($email, $formattedLicense, $log)) {
        $log->info('License Delivered', ['to' => $email, 'license' => $formattedLicense]);
    } else {
        $log->error('Delivery Failed', ['to' => $email]);
    }
}

function getInfoUserAsaas(string $customerId, Logger $log): ?array
{
    $ch = curl_init();

    curl_setopt($ch, CURLOPT_URL, "https://{$_ENV['BASE_URL_ASAAS']}/v3/customers/{$customerId}");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "User-Agent: {$_ENV['NAME_APP']}/1.0",
        "accept: application/json",
        "access_token: {$_ENV['ASAAS_ACCESS_TOKEN']}"
    ]);

    $response = curl_exec($ch);
    if (curl_errno($ch)) {
        $log->error('cURL Error', ['msg' => curl_error($ch)]);
        return null;
    }

    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    if ($httpCode !== 200) {
        $log->error('ASAAS API Error', ['code' => $httpCode, 'response' => $response]);
        return null;
    }

    return json_decode($response, true);
}

function sendEmail(string $to, string $code, Logger $log): bool
{
    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host       = $_ENV['MAIL_SMTP_HOST'];
        $mail->SMTPAuth   = true;
        $mail->Username   = $_ENV['MAIL_USER'];
        $mail->Password   = $_ENV['MAIL_PASS'];
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
        $mail->Port       = $_ENV['MAIL_SMTP_PORT'];
        $mail->CharSet    = 'UTF-8';
        $mail->SMTPDebug = 2;
        
        $mail->Debugoutput = function ($str, $level) use ($log) {
            $log->debug("SMTP Detail: " . trim($str));
        };

        $mail->SMTPOptions = array(
            'ssl' => array(
                'verify_peer' => false,
                'verify_peer_name' => false,
                'allow_self_signed' => true
            )
        );

        $mail->setFrom($_ENV['MAIL_USER'], 'Equipe ExtensionWebDrive');
        $mail->addAddress($to);

        $mail->isHTML(true);
        $mail->Subject = 'Seu Acesso Pro Liberado!';
        $mail->Body    = "Sua chave de ativacao vitalicia e: <b>{$code}</b>";

        $mail->send();
        return true;
    } catch (Exception $e) {
        $log->critical('SMTP Error', ['msg' => $mail->ErrorInfo]);
        return false;
    }
}
