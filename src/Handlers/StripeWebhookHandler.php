<?php

namespace App\Handlers;

use App\Factories\StripeWebhookProcessorFactory;
use Monolog\Logger;
use Stripe\Event;

class StripeWebhookHandler
{
    private StripeWebhookProcessorFactory $processorFactory;
    private Logger $logger;

    public function __construct(
        StripeWebhookProcessorFactory $processorFactory,
        Logger $logger
    ) {
        $this->processorFactory = $processorFactory;
        $this->logger = $logger;
    }

    public function handle(Event $event): void
    {
        $processor = $this->processorFactory->getProcessor($event->type);
        
        if ($processor) {
            $processor->process($event);
        } else {
            $this->logger->debug('Ignoring unhandled Stripe webhook event', ['type' => $event->type]);
        }
    }
}
