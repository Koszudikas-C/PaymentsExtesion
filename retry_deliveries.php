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
$logger = $container->get('logger.retry');

$logger->info('Starting License Retry Task');

// 1. Recover any failed database persistences from fallback file
$fallbackFile = __DIR__ . '/logs/failed_licenses.json';
if (file_exists($fallbackFile)) {
    $logger->info('Found failed database persistence records. Attempting recovery.');
    $content = file_get_contents($fallbackFile);
    $records = json_decode($content, true) ?: [];
    $remainingRecords = [];
    $recoveredCount = 0;

    foreach ($records as $record) {
        try {
            $email = $record['email'] ?? '';
            if (empty($email)) {
                $logger->warning('Skipping invalid fallback record (no email)', ['record' => $record]);
                continue;
            }

            $customer = $customerRepository->findByEmail($email);
            if (!$customer) {
                $customer = new \App\Entity\Customer(
                    $record['name'] ?? 'Usuário',
                    $email,
                    $record['phone'] ?? 'unknown'
                );
            }

            if (($record['paymentStatus'] ?? '') === 'RECEIVED') {
                $customer->markAsPaid('FALLBACK_RECOVERED');
            }

            if (isset($record['licenseKey'])) {
                try {
                    $customer->assignLicense($record['licenseKey']);
                } catch (\DomainException $e) {
                    // Already assigned, which is fine
                }
            }

            if ($record['isLicenseDelivered'] ?? false) {
                $customer->markLicenseAsDelivered();
            }

            if (isset($record['plan'])) {
                $customer->setPlan($record['plan']);
            }
            if (isset($record['subscriptionId'])) {
                $customer->setSubscriptionId($record['subscriptionId']);
            }
            if (isset($record['licenseExpiresAt']) && $record['licenseExpiresAt'] !== null) {
                $customer->setLicenseExpiresAt(new \DateTime($record['licenseExpiresAt']));
            }

            $customerRepository->save($customer);
            $logger->info('Recovered customer from fallback file', ['email' => $email]);
            $recoveredCount++;
        } catch (\Throwable $e) {
            $logger->error('Failed to recover customer from fallback file', [
                'email' => $record['email'] ?? 'unknown',
                'error' => $e->getMessage()
            ]);
            $remainingRecords[] = $record;
        }
    }

    if (empty($remainingRecords)) {
        unlink($fallbackFile);
        $logger->info('All fallback records recovered successfully. File deleted.');
    } else {
        file_put_contents($fallbackFile, json_encode($remainingRecords, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $logger->warning('Some fallback records could not be recovered. Kept in file.', ['remaining' => count($remainingRecords)]);
    }
}

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

    $phone = $customer->getPhone();
    $email = $customer->getEmail();
    $isBrPhone = (strpos($phone, '55') === 0 || strpos($phone, '+55') === 0);
    $isBrEmail = (substr(strtolower($email), -3) === '.br');

    $appName = 'Salvar Conversas WhatsApp';
    if (!$isBrPhone && !$isBrEmail && ($customer->getName() === 'Usuário Internacional' || strpos($phone, 'unknown') !== false || empty($phone))) {
        $appName = 'Export Chat WhatsApp';
    }

    if ($emailService->sendLicenseEmail($customer->getEmail(), $customer->getLicenseKey(), $logger, $customer->getName(), 'license_email.html', $appName)) {
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
