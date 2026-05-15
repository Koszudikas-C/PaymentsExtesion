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
}
