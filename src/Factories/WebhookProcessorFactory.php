<?php

namespace App\Factories;

use App\Interfaces\WebhookProcessorInterface;
use App\Handlers\Processors\PaymentReceivedProcessor;
use Psr\Container\ContainerInterface;

class WebhookProcessorFactory
{
    private ContainerInterface $container;

    public function __construct(ContainerInterface $container)
    {
        $this->container = $container;
    }

    public function getProcessor(string $event): ?WebhookProcessorInterface
    {
        switch ($event) {
            case 'PAYMENT_RECEIVED':
            case 'PAYMENT_CONFIRMED':
                return $this->container->get(PaymentReceivedProcessor::class);
            // Novos eventos podem ser adicionados aqui
            default:
                return null;
        }
    }
}
