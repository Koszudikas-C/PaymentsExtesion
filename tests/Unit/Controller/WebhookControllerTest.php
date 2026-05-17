<?php

namespace Tests\Unit\Controller;

use PHPUnit\Framework\TestCase;
use App\Controllers\WebhookController;
use App\Handlers\WebhookHandler;
use Monolog\Logger;

// Polyfill getallheaders if not exists in CLI
if (!function_exists('getallheaders')) {
    function getallheaders() {
        return [
            'Asaas-Access-Token' => $_SERVER['HTTP_ASAAS_ACCESS_TOKEN'] ?? ''
        ];
    }
}

class WebhookControllerTest extends TestCase
{
    private $webhookHandler;
    private $logger;

    protected function setUp(): void
    {
        $this->webhookHandler = $this->createMock(WebhookHandler::class);
        $this->logger = $this->createMock(Logger::class);
        $_ENV['ASAAS_TOKEN'] = 'super_secret_token';
    }

    protected function tearDown(): void
    {
        unset($_ENV['ASAAS_TOKEN']);
        unset($_SERVER['HTTP_ASAAS_ACCESS_TOKEN']);
    }

    public function testHandleRequestInvalidTokenReturns401()
    {
        $_SERVER['HTTP_ASAAS_ACCESS_TOKEN'] = 'wrong_token';

        $controller = new WebhookController($this->webhookHandler, $this->logger);

        ob_start();
        $controller->handleRequest();
        $output = ob_get_clean();

        $response = json_decode($output, true);
        $this->assertEquals('error', $response['status']);
        $this->assertEquals('Unauthorized', $response['message']);
    }

    public function testHandleRequestEmptyPayloadReturns400()
    {
        $_SERVER['HTTP_ASAAS_ACCESS_TOKEN'] = 'super_secret_token';

        // Mock empty php://input or empty decode
        // In the controller, json_decode(file_get_contents('php://input'), true) is empty.
        $controller = new WebhookController($this->webhookHandler, $this->logger);

        ob_start();
        $controller->handleRequest();
        $output = ob_get_clean();

        $response = json_decode($output, true);
        $this->assertEquals('error', $response['status']);
        $this->assertEquals('Empty Payload', $response['message']);
    }
}
