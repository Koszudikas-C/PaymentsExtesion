<?php

namespace Tests\Unit\Handler;

use App\Handlers\WebhookHandler;
use App\Factories\WebhookProcessorFactory;
use App\Interfaces\WebhookProcessorInterface;
use PHPUnit\Framework\TestCase;
use Monolog\Logger;

class WebhookHandlerTest extends TestCase
{
    private WebhookProcessorFactory|\PHPUnit\Framework\MockObject\MockObject $factory;
    private Logger|\PHPUnit\Framework\MockObject\MockObject $logger;
    private WebhookHandler $handler;

    protected function setUp(): void
    {
        $this->factory = $this->createMock(WebhookProcessorFactory::class);
        $this->logger = $this->createMock(Logger::class);

        $this->handler = new WebhookHandler(
            $this->factory,
            $this->logger
        );
    }

    public function testHandleDelegatesToProcessor()
    {
        $data = [
            'event' => 'PAYMENT_RECEIVED',
            'payment' => ['customer' => 'cus_123']
        ];

        $processor = $this->createMock(WebhookProcessorInterface::class);
        
        $this->factory->expects($this->once())
            ->method('getProcessor')
            ->with('PAYMENT_RECEIVED')
            ->willReturn($processor);

        $processor->expects($this->once())
            ->method('process')
            ->with($data);

        $this->handler->handle($data);
    }

    public function testHandleIgnoresUnknownEvent()
    {
        $data = [
            'event' => 'UNKNOWN_EVENT'
        ];

        $this->factory->expects($this->once())
            ->method('getProcessor')
            ->with('UNKNOWN_EVENT')
            ->willReturn(null);

        $this->logger->expects($this->once())
            ->method('debug')
            ->with($this->stringContains('Ignoring unhandled webhook event'));

        $this->handler->handle($data);
    }
}
