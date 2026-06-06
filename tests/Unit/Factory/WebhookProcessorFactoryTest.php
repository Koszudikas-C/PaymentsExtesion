<?php

namespace Tests\Unit\Factory;

use App\Factories\WebhookProcessorFactory;
use App\Handlers\Processors\PaymentReceivedProcessor;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

class WebhookProcessorFactoryTest extends TestCase
{
    private ContainerInterface|\PHPUnit\Framework\MockObject\MockObject $container;
    private WebhookProcessorFactory $factory;

    protected function setUp(): void
    {
        $this->container = $this->createMock(ContainerInterface::class);
        $this->factory = new WebhookProcessorFactory($this->container);
    }

    public function testGetProcessorPaymentReceived()
    {
        $processor = $this->createMock(PaymentReceivedProcessor::class);
        
        $this->container->expects($this->once())
            ->method('get')
            ->with(PaymentReceivedProcessor::class)
            ->willReturn($processor);

        $this->factory->registerProcessor('PAYMENT_RECEIVED', PaymentReceivedProcessor::class);
        $result = $this->factory->getProcessor('PAYMENT_RECEIVED');
        $this->assertSame($processor, $result);
    }

    public function testGetProcessorUnknownReturnsNull()
    {
        $result = $this->factory->getProcessor('UNKNOWN');
        $this->assertNull($result);
    }
}
