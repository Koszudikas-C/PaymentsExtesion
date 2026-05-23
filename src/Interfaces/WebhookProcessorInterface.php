<?php

namespace App\Interfaces;

interface WebhookProcessorInterface
{
    public function process(array $data): void;
}
