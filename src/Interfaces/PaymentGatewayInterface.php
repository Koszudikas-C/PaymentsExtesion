<?php

namespace App\Interfaces;

use Monolog\Logger;

interface PaymentGatewayInterface
{
    /**
     * @param string $customerId
     * @param Logger $log
     * @return array|null
     */
    public function getCustomerInfo(string $customerId, Logger $log): ?array;

    /**
     * @param string $linkId
     * @param float $value
     * @param string $name
     * @param Logger $log
     * @return bool
     */
    public function updatePaymentLinkToMonthly(string $linkId, float $value, string $name, Logger $log): bool;
}
