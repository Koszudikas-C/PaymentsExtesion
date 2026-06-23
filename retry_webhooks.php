<?php

use App\Config\Container;
use App\Handlers\WebhookHandler;
use Dotenv\Dotenv;

require __DIR__ . '/vendor/autoload.php';

$dotenv = Dotenv::createImmutable(__DIR__);
$dotenv->load();

$container = Container::build();
$webhookHandler = $container->get(WebhookHandler::class);

$failedWebhooksFile = __DIR__ . '/logs/failed_webhooks.json';

if (!file_exists($failedWebhooksFile)) {
    echo "No failed webhooks file found at logs/failed_webhooks.json.\n";
    exit(0);
}

$content = file_get_contents($failedWebhooksFile);
$records = json_decode($content, true) ?: [];

if (empty($records)) {
    echo "No records to process.\n";
    exit(0);
}

echo "Found " . count($records) . " failed webhook(s). Reprocessing...\n";

$remainingRecords = [];
$successCount = 0;
$failCount = 0;

foreach ($records as $record) {
    $payload = $record['payload'] ?? null;
    if (!$payload) {
        continue;
    }

    $paymentId = $payload['payment']['id'] ?? 'unknown';
    echo "Reprocessing payment event: {$paymentId}...\n";

    try {
        $webhookHandler->handle($payload);
        echo "Successfully processed payment event: {$paymentId}\n";
        $successCount++;
    } catch (\Throwable $e) {
        echo "Failed to process payment event: {$paymentId}. Error: " . $e->getMessage() . "\n";
        $record['error'] = $e->getMessage();
        $record['timestamp'] = date('c');
        $remainingRecords[] = $record;
        $failCount++;
    }
}

if (empty($remainingRecords)) {
    unlink($failedWebhooksFile);
    echo "All failed webhooks reprocessed successfully! File deleted.\n";
} else {
    file_put_contents($failedWebhooksFile, json_encode($remainingRecords, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    echo "Reprocessing finished. Success: {$successCount}, Failed: {$failCount}. Remaining records saved back to failed_webhooks.json.\n";
}
