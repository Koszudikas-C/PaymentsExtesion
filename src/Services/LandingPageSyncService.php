<?php

namespace App\Services;

use App\Interfaces\LandingPageSyncServiceInterface;
use App\Entity\Customer;
use Monolog\Logger;

class LandingPageSyncService implements LandingPageSyncServiceInterface
{
    private string $webhookUrl;

    public function __construct(string $webhookUrl)
    {
        $this->webhookUrl = $webhookUrl;
    }

    public function notifySale(Customer $customer, Logger $log, int $currentCount): void
    {
        if (empty($this->webhookUrl)) {
            $log->info('LandingPage Webhook URL not configured. Skipping notification.');
            return;
        }

        $data = [
            'event' => 'sale_confirmed',
            'timestamp' => date('c'),
            'data' => [
                'count' => $currentCount,
                'target' => 100,
                'customer_name' => $customer->getName(),
                'plan' => $customer->getPlan()
            ]
        ];

        $payload = json_encode($data);

        $ch = curl_init($this->webhookUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Content-Length: ' . strlen($payload)
        ]);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);

        if ($error) {
            $log->error('Error sending webhook to LandingPage', ['error' => $error]);
        } else {
            $log->info('Webhook sent to LandingPage', ['httpCode' => $httpCode, 'response' => $response]);
        }
    }
}
