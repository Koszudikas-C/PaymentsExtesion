<?php

namespace App\Interfaces;

use App\Entity\Customer;
use Monolog\Logger;

interface PromotionServiceInterface
{
    /**
     * Handles any post-payment promotion/campaign logic.
     * e.g., verifying limits and converting payment links.
     *
     * @param Customer $customer
     * @param Logger $log
     * @return void
     */
    public function handlePromotionGoal(Customer $customer, Logger $log): void;
}
