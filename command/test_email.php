<?php

require __DIR__ . '/../vendor/autoload.php';

use Dotenv\Dotenv;
use App\Config\Container;
use App\Interfaces\EmailServiceInterface;
use Monolog\Logger;

$dotenv = Dotenv::createImmutable(__DIR__ . '/../');
$dotenv->load();

$container = Container::build();

/** @var Logger $log */
$log = $container->get(Logger::class);

/** @var EmailServiceInterface $emailService */
$emailService = $container->get(EmailServiceInterface::class);

$testEmail = $argv[1] ?? $_ENV['MAIL_USER'] ?? 'test@example.com';
$testLicense = 'TEST-KEY-1234-5678';
$testName = 'Desenvolvedor Teste';

echo "--- Teste de Envio de Email ---\n";
echo "Destinatario: $testEmail\n";
echo "Enviando...\n";

if ($emailService->sendLicenseEmail($testEmail, $testLicense, $log, $testName)) {
    echo "Sucesso: O e-mail foi enviado para $testEmail\n";
    echo "Verifique sua caixa de entrada (e pasta de spam).\n";
} else {
    echo "ERRO: Falha ao enviar o e-mail. Verifique os logs em logs/app.log para mais detalhes.\n";
}
