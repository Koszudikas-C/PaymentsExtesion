<?php

namespace App\Interfaces;

use Stripe\Event;

interface StripeWebhookProcessorInterface
{
    public function process(Event $event): void;
}
