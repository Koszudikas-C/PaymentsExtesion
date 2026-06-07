<?php

namespace Tests\Unit\Factories;

use App\Factories\StripeWebhookProcessorFactory;
use App\Interfaces\StripeWebhookProcessorInterface;
use PHPUnit\Framework\TestCase;
use DI\Container;

class StripeWebhookProcessorFactoryTest extends TestCase
{
    private Container|\PHPUnit\Framework\MockObject\MockObject $container;
    private StripeWebhookProcessorFactory $factory;

    protected function setUp(): void
    {
        $this->container = $this->createMock(Container::class);
        $this->factory = new StripeWebhookProcessorFactory($this->container);
    }

    public function testGetProcessorReturnsProcessor()
    {
        $processor = $this->createMock(StripeWebhookProcessorInterface::class);
        
        $this->container->expects($this->once())
            ->method('get')
            ->with('MockProcessorClass')
            ->willReturn($processor);

        $this->factory->registerProcessor('checkout.session.completed', 'MockProcessorClass');
        
        $result = $this->factory->getProcessor('checkout.session.completed');
        $this->assertSame($processor, $result);
    }

    public function testGetProcessorReturnsNull()
    {
        $this->assertNull($this->factory->getProcessor('unknown.event'));
    }
}
