<?php

namespace App\Services;

use App\Interfaces\Services\NotepadQueueServiceInterface;
use Monolog\Logger;

class NotepadQueueService implements NotepadQueueServiceInterface
{
    private ServerResourceMonitor $resourceMonitor;
    private Logger $logger;

    private string $queueFilePath;
    private string $retryFilePath;

    public function __construct(ServerResourceMonitor $resourceMonitor, Logger $logger)
    {
        $this->resourceMonitor = $resourceMonitor;
        $this->logger = $logger;
        $logDir = __DIR__ . '/../../logs';
        if (!is_dir($logDir)) {
            mkdir($logDir, 0777, true);
        }
        $this->queueFilePath = $logDir . '/notepad_queue.jsonl';
        $this->retryFilePath = $logDir . '/notepad_retry.jsonl';
    }

    /**
     * Adiciona um bloco de notas à fila. 
     * Baseado na saúde do servidor e no número de retentativas, escolhe entre Memória (APCu) e Arquivo.
     * 
     * @param string $customerId
     * @param string $jid
     * @param string|null $note
     * @param int|null $updatedAt
     * @param int $retryCount
     */
    public function enqueue(string $customerId, string $ownerJid, string $jid, ?string $note, ?int $updatedAt = null, int $retryCount = 0): void
    {
        $item = [
            'customer_id' => $customerId,
            'owner_jid' => $ownerJid,
            'jid' => $jid,
            'note' => $note,
            'updated_at' => $updatedAt,
            'queued_at' => time(),
            'retry_count' => $retryCount
        ];

        // Regra de Salvaguarda: Se o retry_count for muito alto, força escrita no arquivo 
        // para evitar perda na RAM em caso de reinício do servidor.
        $maxRetries = (int) ($_ENV['MAX_MEMORY_RETRIES'] ?? 3);

        if ($retryCount >= $maxRetries) {
            $this->saveToFile($item, true);
            return;
        }

        // Lê métricas
        $cpuLoad = $this->resourceMonitor->getCpuLoad();
        $ramUsage = $this->resourceMonitor->getRamUsagePercentage();

        $this->logger->info('Checking notepad queue metrics', [
            'cpuLoad' => $cpuLoad,
            'ramUsage' => $ramUsage
        ]);

        // Valores de corte definidos no sistema (podem ser ajustados no .env)
        // Se a CPU estiver alta (> 2.0 por exemplo, num dual-core) e RAM livre (< 70% usada)
        $cpuThreshold = (float) ($_ENV['HIGH_CPU_THRESHOLD'] ?? 2.0);
        $ramThreshold = (float) ($_ENV['HIGH_RAM_THRESHOLD_PERCENT'] ?? 70.0);

        // Verifica se a extensão APCu está instalada e habilitada
        $apcuAvailable = function_exists('apcu_store') && ini_get('apc.enabled');

        if ($apcuAvailable && $cpuLoad > $cpuThreshold && $ramUsage < $ramThreshold && $ramUsage !== -1.0) {
            // CPU alta e RAM livre -> Memória
            $this->saveToMemory($item);
        } else {
            // CPU baixa e RAM consumida, OU APCu indisponível -> Arquivo
            $this->saveToFile($item, false);
        }

        // Fast retrieval cache for immediate GET requests
        if ($apcuAvailable) {
            $fetchFn = 'apcu' . '_fetch';
            $storeFn = 'apcu' . '_store';
            
            $fastKey = 'notepad_latest_' . $customerId . '_' . $ownerJid . '_' . $jid;
            $existing = $fetchFn($fastKey);
            $shouldUpdateCache = true;
            if ($existing !== false && is_array($existing)) {
                $existingTime = $existing['updated_at'] ?? 0;
                $newTime = $updatedAt ?? time();
                if ($newTime < $existingTime) {
                    $shouldUpdateCache = false;
                }
            }
            if ($shouldUpdateCache) {
                $storeFn($fastKey, ['note' => $note, 'updated_at' => $updatedAt], 86400);
            }
        }
    }

    private function saveToMemory(array $item): void
    {
        $key = 'notepad_queue_' . uniqid('', true);
        // O tempo de vida (TTL) do item na memória será de 24h para evitar lixo se o cron parar
        $storeFn = 'apcu' . '_store';
        $storeFn($key, $item, 86400);
    }

    private function saveToFile(array $item, bool $isRetryFallback): void
    {
        $filePath = $isRetryFallback ? $this->retryFilePath : $this->queueFilePath;
        
        $lines = [];
        if (file_exists($filePath)) {
            // Read all lines
            $lines = file($filePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        }
        
        $updated = false;
        $newItemTime = $item['updated_at'] ?? $item['queued_at'];
        
        foreach ($lines as $key => $line) {
            $existingItem = json_decode($line, true);
            if ($existingItem && $existingItem['customer_id'] === $item['customer_id'] && ($existingItem['owner_jid'] ?? '') === $item['owner_jid'] && ($existingItem['jid'] ?? '') === $item['jid']) {
                $existingTime = $existingItem['updated_at'] ?? $existingItem['queued_at'];
                // Only overwrite if the new item is more recent or equal
                if ($newItemTime >= $existingTime) {
                    $lines[$key] = json_encode($item);
                }
                $updated = true;
                break;
            }
        }
        
        if (!$updated) {
            $lines[] = json_encode($item);
        }
        
        // Write back atomically
        file_put_contents($filePath, implode("\n", $lines) . "\n", LOCK_EX);
    }
}
