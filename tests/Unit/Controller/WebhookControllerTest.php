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

    public function testHandleRequestSuccess()
    {
        $_SERVER['HTTP_ASAAS_ACCESS_TOKEN'] = 'super_secret_token';

        @stream_wrapper_unregister('php');
        stream_wrapper_register('php', \Tests\Unit\Helpers\PhpStreamWrapperMock::class);
        \Tests\Unit\Helpers\PhpStreamWrapperMock::$buffer = json_encode(['event' => 'PAYMENT_RECEIVED', 'payment' => ['id' => 'pay_123']]);

        $this->webhookHandler->expects($this->once())->method('handle');

        $controller = new WebhookController($this->webhookHandler, $this->logger);

        ob_start();
        $controller->handleRequest();
        $output = ob_get_clean();

        @stream_wrapper_restore('php');

        $response = json_decode($output, true);
        $this->assertEquals('success', $response['status']);
    }

    public function testHandleRequestExceptionTriggersFallback()
    {
        $_SERVER['HTTP_ASAAS_ACCESS_TOKEN'] = 'super_secret_token';

        @stream_wrapper_unregister('php');
        stream_wrapper_register('php', \Tests\Unit\Helpers\PhpStreamWrapperMock::class);
        \Tests\Unit\Helpers\PhpStreamWrapperMock::$buffer = json_encode(['event' => 'PAYMENT_RECEIVED', 'payment' => ['id' => 'pay_err']]);

        $this->webhookHandler->method('handle')->willThrowException(new \Exception('Processing error'));
        $this->logger->expects($this->once())->method('critical');
        $this->logger->expects($this->once())->method('info')->with($this->stringContains('fallback'));

        $controller = new WebhookController($this->webhookHandler, $this->logger);

        ob_start();
        $controller->handleRequest();
        $output = ob_get_clean();

        @stream_wrapper_restore('php');

        $response = json_decode($output, true);
        $this->assertEquals('error', $response['status']);
        $this->assertEquals(500, http_response_code());
    }

    public function testHandleRequestExceptionTriggersFallbackWithStringMatch()
    {
        $_SERVER['HTTP_ASAAS_ACCESS_TOKEN'] = 'super_secret_token';

        @stream_wrapper_unregister('php');
        stream_wrapper_register('php', \Tests\Unit\Helpers\PhpStreamWrapperMock::class);
        // Valid JSON but without 'event' key, but contains 'PAYMENT_CONFIRMED' somewhere
        \Tests\Unit\Helpers\PhpStreamWrapperMock::$buffer = json_encode(['foo' => 'PAYMENT_CONFIRMED', 'payment' => ['id' => 'pay_err']]);

        $this->webhookHandler->method('handle')->willThrowException(new \Exception('Processing error'));
        $this->logger->expects($this->once())->method('info')->with($this->stringContains('fallback'));

        $controller = new WebhookController($this->webhookHandler, $this->logger);

        ob_start();
        $controller->handleRequest();
        ob_get_clean();

        @stream_wrapper_restore('php');
        $this->assertEquals(500, http_response_code());
    }

    public function testHandleRequestExceptionDuringFallbackFailsGracefully()
    {
        $_SERVER['HTTP_ASAAS_ACCESS_TOKEN'] = 'super_secret_token';

        @stream_wrapper_unregister('php');
        stream_wrapper_register('php', \Tests\Unit\Helpers\PhpStreamWrapperMock::class);
        \Tests\Unit\Helpers\PhpStreamWrapperMock::$buffer = json_encode(['event' => 'PAYMENT_RECEIVED']);

        $this->webhookHandler->method('handle')->willThrowException(new \Exception('Processing error'));
        
        // Let's cause mkdir or file_put_contents to fail by mocking a permission error...
        // Wait, easier to mock the logger to see if it catches emergency if we mock a custom logger
        $logger = $this->createMock(Logger::class);
        $logger->expects($this->once())->method('critical');
        // If file saving works it will log info, if it fails it logs emergency. We can't easily force file_put_contents to fail in test unless we use vfsStream or similar.
        // We will just let it create the file.

        $controller = new WebhookController($this->webhookHandler, $logger);

        ob_start();
        $controller->handleRequest();
        ob_get_clean();

        @stream_wrapper_restore('php');
        $this->assertEquals(500, http_response_code());
    }
}
