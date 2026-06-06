<?php

namespace App\Factories;

use App\Interfaces\WebhookProcessorInterface;
use App\Handlers\Processors\PaymentReceivedProcessor;
use Psr\Container\ContainerInterface;

class WebhookProcessorFactory
{
    private ContainerInterface $container;
    private array $processors = [];

    public function __construct(ContainerInterface $container)
    {
        $this->container = $container;
    }

    public function registerProcessor(string $eventType, string $processorClass): void
    {
        $this->processors[$eventType] = $processorClass;
    }

    public function getProcessor(string $event): ?WebhookProcessorInterface
    {
        if (!isset($this->processors[$event])) {
            return null;
        }

        return $this->container->get($this->processors[$event]);
    }
}
