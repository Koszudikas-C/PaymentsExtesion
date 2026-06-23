<?php

namespace App\Services;

use Doctrine\ORM\EntityManagerInterface;
use PDO;

class ServerResourceMonitor
{
    private EntityManagerInterface $entityManager;

    public function __construct(EntityManagerInterface $entityManager)
    {
        $this->entityManager = $entityManager;
    }

    /**
     * Retorna a carga atual de CPU (1 min average).
     */
    public function getCpuLoad(): float
    {
        if (function_exists('sys_getloadavg')) {
            $load = sys_getloadavg();
            return $load[0] ?? 0.0;
        }
        return 0.0;
    }

    /**
     * Verifica se a memória RAM está livre ou ocupada.
     * Retorna o uso de memória do sistema em porcentagem (0 a 100) ou -1 se não conseguir ler.
     */
    public function getRamUsagePercentage(): float
    {
        if (is_readable('/proc/meminfo')) {
            $meminfo = file_get_contents('/proc/meminfo');
            preg_match('/MemTotal:\s+(\d+)\s+kB/', $meminfo, $total);
            preg_match('/MemAvailable:\s+(\d+)\s+kB/', $meminfo, $available);

            if (isset($total[1], $available[1]) && (int)$total[1] > 0) {
                $totalMem = (int)$total[1];
                $availMem = (int)$available[1];
                $usedMem = $totalMem - $availMem;
                return ($usedMem / $totalMem) * 100;
            }
        }
        
        // Fallback: se não tiver /proc/meminfo (ex: Windows ou Docker restrito)
        return -1.0;
    }

    /**
     * Verifica se o banco de dados está estressado (tempo de resposta longo).
     * O limite padrão é de 50ms, ou parametrizado via .env DB_STRESS_TIMEOUT_MS
     */
    public function isDatabaseStressed(): bool
    {
        $thresholdMs = (float)($_ENV['DB_STRESS_TIMEOUT_MS'] ?? 50.0);
        $thresholdSec = $thresholdMs / 1000.0;

        $startTime = microtime(true);
        try {
            $conn = $this->entityManager->getConnection();
            $pdo = $conn->getNativeConnection();
            if ($pdo instanceof PDO) {
                $stmt = $pdo->query('SELECT 1');
                $stmt->fetchColumn();
            } else {
                $conn->executeQuery('SELECT 1');
            }
        } catch (\Exception $e) {
            // Se der erro de conexão, consideramos extremamente estressado/offline
            return true;
        }
        $duration = microtime(true) - $startTime;

        return $duration > $thresholdSec;
    }
}
