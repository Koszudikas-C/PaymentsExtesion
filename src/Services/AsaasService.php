<?php

namespace App\Services;

use App\Interfaces\PaymentGatewayInterface;
use Monolog\Logger;

class AsaasService implements PaymentGatewayInterface
{
    private string $baseUrl;
    private string $accessToken;
    private string $appName;

    public function __construct(string $baseUrl, string $accessToken, string $appName)
    {
        $this->baseUrl = $baseUrl;
        $this->accessToken = $accessToken;
        $this->appName = $appName;
    }

    public function getCustomerInfo(string $customerId, Logger $log): ?array
    {
        $ch = curl_init();

        curl_setopt($ch, CURLOPT_URL, "https://{$this->baseUrl}/v3/customers/{$customerId}");
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "User-Agent: {$this->appName}/1.0",
            "accept: application/json",
            "access_token: {$this->accessToken}"
        ]);

        $response = curl_exec($ch);
        if (curl_errno($ch)) {
            $log->error('cURL Error', ['msg' => curl_error($ch)]);
            return null;
        }

        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if ($httpCode !== 200) {
            $log->error('ASAAS API Error', ['code' => $httpCode, 'response' => $response]);
            return null;
        }

        return json_decode($response, true);
    }

    public function updatePaymentLinkToMonthly(string $linkId, float $value, string $name, Logger $log): bool
    {
        $ch = curl_init();

        $payload = [
            'chargeType' => 'RECURRENT',
            'subscriptionCycle' => 'MONTHLY',
            'value' => $value,
            'name' => $name
        ];

        curl_setopt($ch, CURLOPT_URL, "https://{$this->baseUrl}/v3/paymentLinks/{$linkId}");
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "User-Agent: {$this->appName}/1.0",
            "accept: application/json",
            "content-type: application/json",
            "access_token: {$this->accessToken}"
        ]);

        $response = curl_exec($ch);
        if (curl_errno($ch)) {
            $log->error('cURL Error updating payment link', ['msg' => curl_error($ch)]);
            return false;
        }

        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if ($httpCode !== 200) {
            $log->error('ASAAS API Error updating payment link', ['code' => $httpCode, 'response' => $response]);
            return false;
        }

        $log->info('Successfully updated payment link on Asaas to Monthly Subscription', [
            'linkId' => $linkId,
            'value' => $value,
            'name' => $name
        ]);
        return true;
    }
}
