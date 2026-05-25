<?php

namespace App\Interfaces\Repositories;

use App\Entity\Customer;

interface CustomerRepositoryInterface
{
    public function findByEmail(string $email): ?Customer;
    public function findBySubscriptionId(string $subscriptionId): ?Customer;
    public function findByChromeIdentityId(string $chromeIdentityId): ?Customer;
    public function findByLicenseKey(string $licenseKey): ?Customer;
    public function findPendingDeliveries(): array;
    public function countPaidLifetimeCustomers(): int;
    public function save(Customer $customer): void;
}
