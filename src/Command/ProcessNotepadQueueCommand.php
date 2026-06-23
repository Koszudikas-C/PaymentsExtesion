<?php

namespace App\Command;

use App\Entity\Notepad;
use App\Services\ServerResourceMonitor;
use App\Services\NotepadQueueService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class ProcessNotepadQueueCommand extends Command
{
    protected static $defaultName = 'app:sync-notepad-queue';

    private EntityManagerInterface $entityManager;
    private ServerResourceMonitor $resourceMonitor;
    private NotepadQueueService $queueService;

    private string $queueFilePath;
    private string $retryFilePath;

    public function __construct(
        EntityManagerInterface $entityManager,
        ServerResourceMonitor $resourceMonitor,
        NotepadQueueService $queueService
    ) {
        $this->entityManager = $entityManager;
        $this->resourceMonitor = $resourceMonitor;
        $this->queueService = $queueService;

        $logDir = __DIR__ . '/../../logs';
        $this->queueFilePath = $logDir . '/notepad_queue.jsonl';
        $this->retryFilePath = $logDir . '/notepad_retry.jsonl';

        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('app:sync-notepad-queue')
             ->setDescription('Processa a fila de sincronização de blocos de notas, salvando no banco de dados em lotes.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // 1. Verifica a saúde do banco de dados ANTES de começar o lote
        if ($this->resourceMonitor->isDatabaseStressed()) {
            $output->writeln('<error>Database is stressed or offline. Batch synchronization deferred.</error>');
            return Command::FAILURE;
        }

        $items = $this->collectItemsFromQueues();

        if (empty($items)) {
            $output->writeln('<info>Queue is empty.</info>');
            return Command::SUCCESS;
        }

        // 2. Agrupar por customer_id + jid para pegar apenas a versão mais recente
        // 2. Group by customer_id + jid to keep only the latest version
        $groupedItems = [];
        foreach ($items as $item) {
            $cid = $item['customer_id'];
            $jid = $item['jid'] ?? '';
            $key = $cid . '_' . $jid;
            
            // Compare using updated_at if it exists, otherwise queued_at
            $currentTime = $item['updated_at'] ?? $item['queued_at'];
            $existingTime = isset($groupedItems[$key]) ? ($groupedItems[$key]['updated_at'] ?? $groupedItems[$key]['queued_at']) : 0;
            
            if (!isset($groupedItems[$key]) || $currentTime > $existingTime) {
                $groupedItems[$key] = $item;
            }
        }

        $output->writeln('<info>Unique items to synchronize: ' . count($groupedItems) . '</info>');

        // 3. Batch Save
        $batchSize = 100;
        $i = 0;

        $customerRepo = $this->entityManager->getRepository(\App\Entity\Customer::class);
        $notepadRepo = $this->entityManager->getRepository(Notepad::class);

        foreach ($groupedItems as $item) {
            // Timeout / Failover rule: check the database during the loop to abort if slow
            if ($i > 0 && $i % 10 === 0 && $this->resourceMonitor->isDatabaseStressed()) {
                $output->writeln('<error>Database became stressed during batch. Halting processing.</error>');
                $this->requeueRemainingItems(array_slice(array_values($groupedItems), $i));
                $this->entityManager->flush(); // save what has been processed
                $this->entityManager->clear();
                return Command::FAILURE;
            }

            try {
                $customer = $customerRepo->find($item['customer_id']);
                if ($customer && !empty($item['jid'])) {
                    $notepad = $notepadRepo->findOneBy(['customer' => $customer, 'jid' => $item['jid']]);
                    $shouldUpdate = true;
                    
                    if ($notepad) {
                        // Check if the queued item is older than the version already saved in the DB (Last-Write-Wins)
                        if (isset($item['updated_at'])) {
                            // updated_at from JS comes in milliseconds, convert to seconds
                            $itemSeconds = (int)($item['updated_at'] / 1000);
                            $dbSeconds = $notepad->getDateUpdated()->getTimestamp();
                            if ($itemSeconds < $dbSeconds) {
                                $shouldUpdate = false;
                                $output->writeln("<info>Ignored older note for {$item['jid']}</info>");
                            }
                        }
                    } else {
                        $notepad = new Notepad($customer, $item['jid']);
                        $this->entityManager->persist($notepad);
                    }
                    
                    if ($shouldUpdate) {
                        $notepad->setNote($item['note'] ?? null);
                        
                        if (isset($item['updated_at'])) {
                            $itemSeconds = (int)($item['updated_at'] / 1000);
                            $dt = new \DateTime();
                            $dt->setTimestamp($itemSeconds);
                            $notepad->setDateUpdated($dt);
                        } else {
                            $notepad->setDateUpdated(new \DateTime('now'));
                        }
                    }
                }
            } catch (\Exception $e) {
                $output->writeln("<error>Failed to prepare item {$item['customer_id']}: {$e->getMessage()}</error>");
                $item['retry_count']++;
                $this->queueService->enqueue($item['customer_id'], $item['jid'] ?? '', $item['note'] ?? null, $item['retry_count']);
            }

            $i++;
            if ($i % $batchSize === 0) {
                try {
                    $this->entityManager->flush();
                    $this->entityManager->clear();
                } catch (\Exception $e) {
                    $output->writeln("<error>Batch flush failed: {$e->getMessage()}</error>");
                    $this->requeueRemainingItems(array_slice(array_values($groupedItems), $i - $batchSize));
                    return Command::FAILURE;
                }
            }
        }

        // Final flush
        try {
            $this->entityManager->flush();
            $this->entityManager->clear();
        } catch (\Exception $e) {
            $output->writeln("<error>Final flush failed: {$e->getMessage()}</error>");
            $rem = count($groupedItems) % $batchSize;
            if ($rem === 0)
                $rem = $batchSize; // if exactly batchSize failed at the end
            $this->requeueRemainingItems(array_slice(array_values($groupedItems), -$rem));
            return Command::FAILURE;
        }

        // 4. Clear successfully processed queues
        $this->clearQueues();
        $output->writeln('<info>Synchronization completed successfully.</info>');

        return Command::SUCCESS;
    }

    private function collectItemsFromQueues(): array
    {
        $items = [];

        // Read from Memory (APCu)
        if (function_exists('apcu_cache_info') && ini_get('apc.enabled')) {
            $info = apcu_cache_info();
            $keysToDelete = [];
            foreach ($info['cache_list'] as $entry) {
                $key = $entry['info'] ?? $entry['key'];
                if (strpos($key, 'notepad_queue_') === 0) {
                    $item = apcu_fetch($key);
                    if ($item) {
                        $items[] = $item;
                    }
                    $keysToDelete[] = $key;
                }
            }
            // Clear the read keys (If the process fails, they will be returned to the queue with requeueRemainingItems)
            foreach ($keysToDelete as $key) {
                apcu_delete($key);
            }
        }

        // Read from the main file
        if (file_exists($this->queueFilePath)) {
            $lines = file($this->queueFilePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            foreach ($lines as $line) {
                $item = json_decode($line, true);
                if ($item) {
                    $items[] = $item;
                }
            }
            // Clear the file by truncating
            file_put_contents($this->queueFilePath, '');
        }

        // Read from the Retry file
        if (file_exists($this->retryFilePath)) {
            $lines = file($this->retryFilePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            foreach ($lines as $line) {
                $item = json_decode($line, true);
                if ($item) {
                    $items[] = $item;
                }
            }
            file_put_contents($this->retryFilePath, '');
        }

        return $items;
    }

    private function requeueRemainingItems(array $remainingItems): void
    {
        // Devolve os itens pra fila através do QueueService (que vai reavaliar a RAM/CPU)
        foreach ($remainingItems as $item) {
            $item['retry_count'] = ($item['retry_count'] ?? 0) + 1;
            $this->queueService->enqueue($item['customer_id'], $item['jid'] ?? '', $item['note'] ?? null, $item['updated_at'] ?? null, $item['retry_count']);
        }
    }

    private function clearQueues(): void
    {
        // O `collectItemsFromQueues` já trunca os arquivos e remove as keys lidas. 
        // Este método seria para limpezas adicionais se necessário.
    }
}
