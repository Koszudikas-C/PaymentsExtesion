<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Handlers\WebhookHandler;
use Monolog\Logger;

class WebhookController
{
    private WebhookHandler $webhookHandler;
    private Logger $logger;

    public function __construct(WebhookHandler $webhookHandler, Logger $logger)
    {
        $this->webhookHandler = $webhookHandler;
        $this->logger = $logger;
    }

    public function handleRequest(): void
    {
        // Remove informações de versão do PHP por segurança
        header_remove('X-Powered-By');
        header('Content-Type: application/json; charset=utf-8');

        $headers = $this->getRequestHeaders();
        $asaasToken = $headers['asaas-access-token'] ?? '';

        $expectedToken = $_ENV['ASAAS_TOKEN'] ?? '';
        if (empty($expectedToken) || $asaasToken !== $expectedToken) {
            $this->logger->warning('Invalid Token', ['received' => $asaasToken]);
            http_response_code(401);
            echo json_encode(['status' => 'error', 'message' => 'Unauthorized'], JSON_UNESCAPED_UNICODE);
            return;
        }

        $input = file_get_contents('php://input') ?: '';
        if ($input !== '' && !mb_check_encoding($input, 'UTF-8')) {
            $input = mb_convert_encoding($input, 'UTF-8', 'auto');
        }
        $data = json_decode($input, true);

        try {
            if (!$data) {
                $this->logger->error('Empty Payload');
                http_response_code(400);
                echo json_encode(['status' => 'error', 'message' => 'Empty Payload'], JSON_UNESCAPED_UNICODE);
                return;
            }

            $this->webhookHandler->handle($data);

            http_response_code(200);
            echo json_encode(["status" => "success"], JSON_UNESCAPED_UNICODE);
        } catch (\Throwable $e) {
            $this->logger->critical('Unhandled Exception during webhook capture', [
                'msg' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            // FALLBACK: Se o evento era PAYMENT_RECEIVED ou PAYMENT_CONFIRMED, salva o payload bruto em um arquivo JSON
            $isPaymentEvent = false;
            $payloadToSave = $input ?? '';
            
            $targetEvents = ['PAYMENT_RECEIVED', 'PAYMENT_CONFIRMED'];
            if (isset($data) && is_array($data) && isset($data['event']) && in_array($data['event'], $targetEvents)) {
                $isPaymentEvent = true;
            } else {
                foreach ($targetEvents as $target) {
                    if (isset($payloadToSave) && strpos($payloadToSave, $target) !== false) {
                        $isPaymentEvent = true;
                        break;
                    }
                }
            }

            if ($isPaymentEvent) {
                $this->saveToFallbackFile($e->getMessage(), $data, $payloadToSave);
            }

            http_response_code(500);
            echo json_encode(['status' => 'error', 'message' => 'Internal Server Error'], JSON_UNESCAPED_UNICODE);
        }
    }

    private function saveToFallbackFile(string $errorMsg, ?array $data, string $payloadToSave): void
    {
        try {
            $fallbackDir = __DIR__ . '/../../data';
            if (!is_dir($fallbackDir)) {
                mkdir($fallbackDir, 0777, true);
            }
            
            $fallbackFile = $fallbackDir . '/failed_webhooks.json';
            
            $failedRecord = [
                'timestamp' => date('c'),
                'error' => $errorMsg,
                'payload' => is_array($data) ? $data : (json_decode($payloadToSave, true) ?: $payloadToSave)
            ];

            $existing = [];
            if (file_exists($fallbackFile)) {
                $existingContent = file_get_contents($fallbackFile);
                $existing = json_decode($existingContent, true) ?: [];
            }

            $existing[] = $failedRecord;
            file_put_contents($fallbackFile, json_encode($existing, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            
            $this->logger->info('Saved failed PAYMENT_RECEIVED webhook payload to fallback file', ['file' => $fallbackFile]);
        } catch (\Throwable $fallbackEx) {
            $this->logger->emergency('Failed to save webhook payload to fallback file', [
                'error' => $fallbackEx->getMessage()
            ]);
        }
    }

    private function getRequestHeaders(): array
    {
        if (function_exists('getallheaders')) {
            return array_change_key_case(getallheaders(), CASE_LOWER);
        }

        $headers = [];
        foreach ($_SERVER as $name => $value) {
            if (substr($name, 0, 5) == 'HTTP_') {
                $headers[str_replace(' ', '-', strtolower(str_replace('_', ' ', substr($name, 5))))] = $value;
            }
        }
        return $headers;
    }
}
