<?php
require __DIR__ . '/vendor/autoload.php';

use Dotenv\Dotenv;
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;


$dotenv = Dotenv::createImmutable(__DIR__);
$dotenv->load();

$headers = getallheaders();
$asaasToken = $headers['asaas-access-token'] ?? '';

if ($asaasToken !== $_ENV['ASAAS_TOKEN']) {
    http_response_code(401);
    exit;
}

$input = file_get_contents('php://input');
$data = json_decode($input, true);

if ($data['event'] === 'PAYMENT_RECEIVED') {
    $payment = $data['payment'];
    $whatsapp = $payment['externalReference'];
    $email = $payment['customer'];

    $license = strtoupper(substr(hash('sha256', $whatsapp . $_ENV['LICENSE_SALT']), 0, 16));
    
    $formattedLicense = implode('-', str_split($license, 4));

    if (sendEmail($email, $formattedLicense)) {
        http_response_code(200);
        echo json_encode(["status" => "success"]);
    } else {
        http_response_code(500);
    }
}

function sendEmail($to, $code) {
    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host       = $_ENV['MAIL_HOST'];
        $mail->SMTPAuth   = true;
        $mail->Username   = $_ENV['MAIL_USER'];
        $mail->Password   = $_ENV['MAIL_PASS'];
        $mail->Port       = $_ENV['MAIL_PORT'];

        $mail->setFrom($_ENV['MAIL_USER'], 'Extensão de Pagamentos');
        $mail->addAddress($to);

        $mail->isHTML(true);
        $mail->Subject = 'Seu Acesso Pro Liberado!';
        $mail->Body    = "Sua chave de ativacao vitalicia e: <b>{$code}</b>";

        $mail->send();
        return true;
    } catch (Exception $e) {
        return false;
    }
}

?>