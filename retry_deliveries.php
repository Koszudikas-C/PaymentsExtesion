<?php

use App\Config\Container;
use App\Interfaces\EmailServiceInterface;
use App\Interfaces\Repositories\CustomerRepositoryInterface;
use Dotenv\Dotenv;
use Monolog\Logger;

require __DIR__ . '/vendor/autoload.php';

$dotenv = Dotenv::createImmutable(__DIR__);
$dotenv->load();

$container = Container::build();

$customerRepository = $container->get(CustomerRepositoryInterface::class);
$emailService = $container->get(EmailServiceInterface::class);
$logger = $container->get(Logger::class);

$logger->info('Starting License Retry Task');

$pendingCustomers = $customerRepository->findPendingDeliveries();

$successCount = 0;
$failCount = 0;

foreach ($pendingCustomers as $customer) {
    if (!$customer->canRetryDelivery()) {
        $logger->warning('Maximum retry attempts reached or already delivered', ['email' => $customer->getEmail()]);
        continue;
    }

    $logger->info('Retrying delivery', [
        'email' => $customer->getEmail(), 
        'attempt' => $customer->getDeliveryFailureCount() + 1
    ]);

    if ($emailService->sendLicenseEmail($customer->getEmail(), $customer->getLicenseKey(), $logger)) {
        $customer->markLicenseAsDelivered();
        $successCount++;
    } else {
        $customer->recordDeliveryFailure('Retry failed');
        $failCount++;
    }
    
    $customerRepository->save($customer);
}

$logger->info('Retry Task Completed', ['success' => $successCount, 'failed' => $failCount]);

echo "Task Completed. Success: $successCount, Failed: $failCount\n";
