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
}
