<?php

namespace Tests\Unit\Handlers;

use App\Factories\StripeWebhookProcessorFactory;
use App\Handlers\StripeWebhookHandler;
use App\Interfaces\StripeWebhookProcessorInterface;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Stripe\Event;

class StripeWebhookHandlerTest extends TestCase
{
    private StripeWebhookProcessorFactory|MockObject $factory;
    private Logger|MockObject $logger;
    private StripeWebhookHandler $handler;

    protected function setUp(): void
    {
        $this->factory = $this->createMock(StripeWebhookProcessorFactory::class);
        $this->logger = $this->createMock(Logger::class);
        $this->handler = new StripeWebhookHandler($this->factory, $this->logger);
    }

    public function testHandleCallsProcessorWhenFound()
    {
        $event = Event::constructFrom(['type' => 'checkout.session.completed']);

        $processor = $this->createMock(StripeWebhookProcessorInterface::class);
        
        $this->factory->expects($this->once())
            ->method('getProcessor')
            ->with('checkout.session.completed')
            ->willReturn($processor);

        $processor->expects($this->once())
            ->method('process')
            ->with($event);

        $this->logger->expects($this->never())->method('debug');

        $this->handler->handle($event);
    }

    public function testHandleIgnoresWhenProcessorNotFound()
    {
        $event = Event::constructFrom(['type' => 'unknown.event']);

        $this->factory->expects($this->once())
            ->method('getProcessor')
            ->with('unknown.event')
            ->willReturn(null);

        $this->logger->expects($this->once())
            ->method('debug')
            ->with($this->stringContains('Ignoring unhandled Stripe webhook'));

        $this->handler->handle($event);
    }
}
