<?php

namespace App\Interfaces;

use App\Entity\Customer;
use Monolog\Logger;

interface LandingPageSyncServiceInterface
{
    public function notifySale(Customer $customer, Logger $log, int $currentCount): void;
}
