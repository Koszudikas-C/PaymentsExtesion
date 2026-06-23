<?php

namespace Tests\Unit;

use Monolog\Logger;
use PHPUnit\Framework\TestCase;
use App\Services\NotepadQueueService;
use App\Services\ServerResourceMonitor;

class NotepadQueueServiceTest extends TestCase
{
    private $resourceMonitorMock;
    private $queueService;
    private $logDir;

    protected function setUp(): void
    {
        $this->logDir = __DIR__ . '/../../logs';
        if (!is_dir($this->logDir)) {
            mkdir($this->logDir, 0777, true);
        }
        $this->resourceMonitorMock = $this->createMock(ServerResourceMonitor::class);
        $loggerMock = $this->createMock(Logger::class);
        $this->queueService = new NotepadQueueService($this->resourceMonitorMock, $loggerMock);

        // Limpar arquivos e cache antes de cada teste
        @unlink($this->logDir . '/notepad_queue.jsonl');
        @unlink($this->logDir . '/notepad_retry.jsonl');

        if (function_exists('apcu_clear_cache')) {
            apcu_clear_cache();
        }

        // Define variáveis de ambiente pro teste
        $_ENV['MAX_MEMORY_RETRIES'] = 3;
        $_ENV['HIGH_CPU_THRESHOLD'] = 2.0;
        $_ENV['HIGH_RAM_THRESHOLD_PERCENT'] = 70.0;
    }

    protected function tearDown(): void
    {
        @unlink($this->logDir . '/notepad_queue.jsonl');
        @unlink($this->logDir . '/notepad_retry.jsonl');
    }

    public function testFallbackToFileWhenRetryLimitExceeded()
    {
        // Mesmo com CPU alta e RAM livre (que forçaria pra memória), 
        // se passar de 3 retries, tem que forçar pro arquivo.

        $this->queueService->enqueue('1', '5511999999999@c.us', 'some_jid@c.us', 'Note data', null, 3);

        $retryFile = $this->logDir . '/notepad_retry.jsonl';
        $this->assertFileExists($retryFile);

        $contents = file_get_contents($retryFile);
        $this->assertStringContainsString('"customer_id":"1"', $contents);
        $this->assertStringContainsString('"retry_count":3', $contents);
    }
}
