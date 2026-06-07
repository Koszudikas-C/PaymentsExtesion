<?php

namespace Tests\Unit\Controllers;

use App\Controllers\StripeWebhookController;
use App\Handlers\StripeWebhookHandler;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;

class StripeWebhookControllerTest extends TestCase
{
    private StripeWebhookHandler|MockObject $handler;
    private Logger|MockObject $logger;
    private StripeWebhookController $controller;

    protected function setUp(): void
    {
        $this->handler = $this->createMock(StripeWebhookHandler::class);
        $this->logger = $this->createMock(Logger::class);
        $this->controller = new StripeWebhookController($this->handler, $this->logger);
        
        $_ENV['STRIPE_WEBHOOK_SECRET'] = 'whsec_test_secret';
    }

    protected function tearDown(): void
    {
        unset($_ENV['STRIPE_WEBHOOK_SECRET']);
    }

    public function testMissingSecretReturns500()
    {
        $_ENV['STRIPE_WEBHOOK_SECRET'] = '';

        ob_start();
        $this->controller->handleRequest();
        $output = ob_get_clean();

        $this->assertEquals(500, http_response_code());
        $this->assertStringContainsString('Server Configuration Error', $output);
    }

    public function testInvalidPayloadReturns400()
    {
        $_SERVER['HTTP_STRIPE_SIGNATURE'] = 'invalid_sig';

        ob_start();
        $this->controller->handleRequest();
        ob_get_clean();

        $this->assertEquals(400, http_response_code());
    }

    public function testInvalidSignatureReturns400()
    {
        // Valid JSON but invalid signature will throw SignatureVerificationException
        @stream_wrapper_unregister('php');
        stream_wrapper_register('php', \Tests\Unit\Helpers\PhpStreamWrapperMock::class);
        \Tests\Unit\Helpers\PhpStreamWrapperMock::$buffer = '{"id":"evt_test"}';

        $_SERVER['HTTP_STRIPE_SIGNATURE'] = 't=' . time() . ',v1=wrongsignature123';

        ob_start();
        $this->controller->handleRequest();
        ob_get_clean();

        @stream_wrapper_restore('php');
        $this->assertEquals(400, http_response_code());
    }

    public function testSuccessReturns200()
    {
        $payload = '{"id":"evt_test","type":"checkout.session.completed"}';
        $timestamp = time();
        $secret = $_ENV['STRIPE_WEBHOOK_SECRET'];
        $signature = hash_hmac('sha256', "{$timestamp}.{$payload}", $secret);
        
        @stream_wrapper_unregister('php');
        stream_wrapper_register('php', \Tests\Unit\Helpers\PhpStreamWrapperMock::class);
        \Tests\Unit\Helpers\PhpStreamWrapperMock::$buffer = $payload;

        $_SERVER['HTTP_STRIPE_SIGNATURE'] = "t={$timestamp},v1={$signature}";

        $this->handler->expects($this->once())->method('handle');

        ob_start();
        $this->controller->handleRequest();
        $output = ob_get_clean();

        @stream_wrapper_restore('php');
        $this->assertEquals(200, http_response_code());
        $this->assertStringContainsString('success', $output);
    }

    public function testExceptionReturns500()
    {
        $payload = '{"id":"evt_test","type":"checkout.session.completed"}';
        $timestamp = time();
        $secret = $_ENV['STRIPE_WEBHOOK_SECRET'];
        $signature = hash_hmac('sha256', "{$timestamp}.{$payload}", $secret);
        
        @stream_wrapper_unregister('php');
        stream_wrapper_register('php', \Tests\Unit\Helpers\PhpStreamWrapperMock::class);
        \Tests\Unit\Helpers\PhpStreamWrapperMock::$buffer = $payload;

        $_SERVER['HTTP_STRIPE_SIGNATURE'] = "t={$timestamp},v1={$signature}";

        $this->handler->method('handle')->willThrowException(new \Exception('Error'));

        ob_start();
        $this->controller->handleRequest();
        $output = ob_get_clean();

        @stream_wrapper_restore('php');
        $this->assertEquals(500, http_response_code());
        $this->assertStringContainsString('Internal Server Error', $output);
    }
}
