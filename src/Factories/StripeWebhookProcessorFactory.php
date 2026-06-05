<?php

namespace App\Factories;

use App\Interfaces\StripeWebhookProcessorInterface;
use DI\Container;

class StripeWebhookProcessorFactory
{
    private Container $container;
    private array $processors = [];

    public function __construct(Container $container)
    {
        $this->container = $container;
    }

    public function registerProcessor(string $eventType, string $processorClass): void
    {
        $this->processors[$eventType] = $processorClass;
    }

    public function getProcessor(string $eventType): ?StripeWebhookProcessorInterface
    {
        if (!isset($this->processors[$eventType])) {
            return null;
        }

        return $this->container->get($this->processors[$eventType]);
    }
}
