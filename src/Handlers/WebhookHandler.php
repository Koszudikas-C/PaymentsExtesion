<?php

namespace App\Handlers;

use App\Factories\WebhookProcessorFactory;
use Monolog\Logger;

class WebhookHandler
{
    private WebhookProcessorFactory $processorFactory;
    private Logger $logger;

    public function __construct(
        WebhookProcessorFactory $processorFactory,
        Logger $logger
    ) {
        $this->processorFactory = $processorFactory;
        $this->logger = $logger;
    }

    public function handle(array $data): void
    {
        $event = $data['event'] ?? '';
        
        $processor = $this->processorFactory->getProcessor($event);
        
        if ($processor) {
            $processor->process($data);
        } else {
            $this->logger->debug('Ignoring unhandled webhook event', ['event' => $event]);
        }
    }
}
