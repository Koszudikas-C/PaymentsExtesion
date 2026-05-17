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

try {
    $input = file_get_contents('php://input');
    $data = json_decode($input, true);

    if (!$data) {
        $log->error('Empty Payload');
        http_response_code(400);
        exit;
    }

    // 6. Resolve o Handler via Container (Injeção Automática)
    /** @var WebhookHandler $handler */
    $handler = $container->get(WebhookHandler::class);

    $handler->handle($data);

    http_response_code(200);
    echo json_encode(["status" => "success"]);
} catch (\Throwable $e) {
    $log->critical('Unhandled Exception during webhook capture', [
        'msg' => $e->getMessage(),
        'trace' => $e->getTraceAsString()
    ]);

    // FALLBACK: Se o evento era PAYMENT_RECEIVED, salva o payload bruto em um arquivo
    $isPaymentReceived = false;
    $payloadToSave = $input ?? '';
    
    if (isset($data) && is_array($data) && isset($data['event']) && $data['event'] === 'PAYMENT_RECEIVED') {
        $isPaymentReceived = true;
    } elseif (isset($payloadToSave) && strpos($payloadToSave, 'PAYMENT_RECEIVED') !== false) {
        $isPaymentReceived = true;
    }

    if ($isPaymentReceived) {
        try {
            $fallbackDir = __DIR__ . '/logs';
            if (!is_dir($fallbackDir)) {
                mkdir($fallbackDir, 0777, true);
            }
            
            $fallbackFile = $fallbackDir . '/failed_webhooks.json';
            
            $failedRecord = [
                'timestamp' => date('c'),
                'error' => $e->getMessage(),
                'payload' => is_array($data) ? $data : (json_decode($payloadToSave, true) ?: $payloadToSave)
            ];

            $existing = [];
            if (file_exists($fallbackFile)) {
                $existingContent = file_get_contents($fallbackFile);
                $existing = json_decode($existingContent, true) ?: [];
            }

            $existing[] = $failedRecord;
            file_put_contents($fallbackFile, json_encode($existing, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            
            $log->info('Saved failed PAYMENT_RECEIVED webhook payload to fallback file', ['file' => $fallbackFile]);
        } catch (\Throwable $fallbackEx) {
            $log->emergency('Failed to save webhook payload to fallback file', [
                'error' => $fallbackEx->getMessage()
            ]);
        }
    }

    http_response_code(500);
}
