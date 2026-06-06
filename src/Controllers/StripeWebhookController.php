<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Handlers\StripeWebhookHandler;
use Monolog\Logger;
use Stripe\Webhook;
use Stripe\Exception\SignatureVerificationException;

class StripeWebhookController
{
    private StripeWebhookHandler $webhookHandler;
    private Logger $logger;

    public function __construct(StripeWebhookHandler $webhookHandler, Logger $logger)
    {
        $this->webhookHandler = $webhookHandler;
        $this->logger = $logger;
    }

    public function handleRequest(): void
    {
        header_remove('X-Powered-By');
        header('Content-Type: application/json; charset=utf-8');

        $payload = file_get_contents('php://input');
        $sigHeader = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';
        $endpointSecret = $_ENV['STRIPE_WEBHOOK_SECRET'] ?? '';

        if (empty($endpointSecret)) {
            $this->logger->critical('Stripe webhook secret is missing.');
            http_response_code(500);
            echo json_encode(['error' => 'Server Configuration Error']);
            return;
        }

        try {
            // Aumentado a tolerância para 24 horas (86400) devido a dessincronização de relógio no ambiente de desenvolvimento local
            $event = Webhook::constructEvent(
                $payload, $sigHeader, $endpointSecret, 86400
            );
        } catch (\UnexpectedValueException $e) {
            $this->logger->error('Stripe Webhook: Invalid payload', ['error' => $e->getMessage()]);
            http_response_code(400);
            return;
        } catch (SignatureVerificationException $e) {
            $this->logger->warning('Stripe Webhook: Invalid signature', ['error' => $e->getMessage()]);
            http_response_code(400);
            return;
        }

        try {
            // Process the event
            $this->webhookHandler->handle($event);

            http_response_code(200);
            echo json_encode(['status' => 'success']);
        } catch (\Throwable $e) {
            $this->logger->critical('Stripe Webhook: Unhandled Exception', [
                'msg' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            http_response_code(500);
            echo json_encode(['status' => 'error', 'message' => 'Internal Server Error']);
        }
    }
}
