<?php

require __DIR__ . '/vendor/autoload.php';

use Dotenv\Dotenv;
use App\Config\Container;
use App\Handlers\WebhookHandler;
use Monolog\Logger;

$dotenv = Dotenv::createImmutable(__DIR__);
$dotenv->load();

$container = Container::build();

$log = $container->get(Logger::class);

$headers = array_change_key_case(getallheaders(), CASE_LOWER);
$asaasToken = $headers['asaas-access-token'] ?? '';

if ($asaasToken !== $_ENV['ASAAS_TOKEN']) {
    $log->warning('Invalid Token', ['received' => $asaasToken]);
    $log->warning('Headers', $headers);
    http_response_code(401);
    exit;
}

$input = file_get_contents('php://input');
$data = json_decode($input, true);

if (!$data) {
    $log->error('Empty Payload');
    http_response_code(400);
    exit;
}

try {
    // 6. Resolve o Handler via Container (Injeção Automática)
    /** @var WebhookHandler $handler */
    $handler = $container->get(WebhookHandler::class);

    $handler->handle($data);

    http_response_code(200);
    echo json_encode(["status" => "success"]);
} catch (\Exception $e) {
    $log->critical('Unhandled Exception', [
        'msg' => $e->getMessage(),
        'trace' => $e->getTraceAsString()
    ]);
    http_response_code(500);
}
