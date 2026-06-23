<?php

namespace App\Command;

use App\Interfaces\DiscordServiceInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:sync-logs',
    description: 'Sincroniza os últimos logs críticos com o Discord evitando duplicatas.',
)]
class SyncLogsCommand extends Command
{
    private DiscordServiceInterface $discordService;
    private string $logDir;
    private string $stateFile;

    public function __construct(DiscordServiceInterface $discordService, string $logDir)
    {
        parent::__construct();
        $this->discordService = $discordService;
        $this->logDir = $logDir;
        $this->stateFile = __DIR__ . '/../../data/sync_state.json';
    }

    protected function configure(): void
    {
        $this->addOption(
            'all',
            'a',
            InputOption::VALUE_NONE,
            'If set, synchronizes all log levels (including INFO)'
        );

        $this->addOption(
            'performance',
            'p',
            InputOption::VALUE_NONE,
            'If set, synchronizes performance logs ([PERFORMANCE]) to Discord'
        );

        $this->addOption(
            'continuous',
            'c',
            InputOption::VALUE_NONE,
            'Runs in a continuous loop monitoring the log file in real-time.'
        );

        $this->addOption(
            'watch',
            'w',
            InputOption::VALUE_NONE,
            'Runs in a continuous loop monitoring the log file in real-time.'
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $syncAll = $input->getOption('all');
        $syncPerformance = $input->getOption('performance');
        $continuous = $input->getOption('continuous') || $input->getOption('watch');

        $state = $this->loadState();

        if ($continuous) {
            $io->info("Starting real-time log monitoring (Continuous Mode)...");
        }

        do {
            $today = date('Y-m-d');
            $logPath = rtrim($this->logDir, '/') . "/app-{$today}.log";

            if (!file_exists($logPath)) {
                if ($continuous) {
                    usleep(1000000);
                    continue;
                }
                $io->warning("Today's log file not found: {$logPath}");
                return Command::SUCCESS;
            }

            $lastLineProcessed = $state[$logPath] ?? 0;
            $handle = fopen($logPath, 'r');
            if (!$handle) {
                if ($continuous) {
                    usleep(1000000);
                    continue;
                }
                $io->error("Could not open log file.");
                return Command::FAILURE;
            }

            $currentLineNum = 0;
            $syncCount = 0;

            while (($line = fgets($handle)) !== false) {
                $currentLineNum++;
                
                if ($currentLineNum <= $lastLineProcessed) {
                    continue;
                }

                $data = json_decode($line, true);
                if (!$data) continue;

                $level = strtoupper($data['level_name'] ?? 'INFO');
                $message = $data['message'] ?? '';
                $contextData = $data['context'] ?? [];

                $isPerformance = (strpos($message, '[PERFORMANCE]') !== false) || 
                                 (strpos($message, '[PERFORMANCE_ALERT]') !== false) ||
                                 (isset($contextData['type']) && $contextData['type'] === 'performance');

                $shouldSync = $syncAll || 
                              in_array($level, ['ERROR', 'CRITICAL', 'ALERT', 'EMERGENCY', 'WARNING']) ||
                              ($syncPerformance && $isPerformance);

                if ($shouldSync) {
                    $messageStr = $message !== '' ? $message : 'No message';
                    $context = !empty($contextData) ? json_encode($contextData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : 'None';
                    
                    // Evita extrapolar os limites do Discord (max 4096 para descrição, 1024 para campos)
                    if (strlen($messageStr) > 2000) {
                        $messageStr = mb_strcut($messageStr, 0, 1980, 'UTF-8') . " ... (truncated)";
                    }

                    if (strlen($context) > 980) {
                        $context = mb_strcut($context, 0, 960, 'UTF-8') . "\n... (truncated)";
                    }

                    $isAlert = (strpos($message, '[PERFORMANCE_ALERT]') !== false) || ($isPerformance && ($level === 'ERROR' || $level === 'CRITICAL'));

                    if ($isAlert) {
                        $color = 0xe74c3c; // vermelho para alertas de lentidão
                        $emoji = '⚠️⚡'; // Alerta com raio
                    } elseif ($isPerformance) {
                        $color = 0x2ecc71; // verde para performance comum
                        $emoji = '⚡'; // raio para performance
                    } else {
                        $color = ($level === 'INFO') ? 0x3498db : 0xff0000;
                        $emoji = ($level === 'INFO') ? 'ℹ️' : '🚨';
                    }

                    $this->discordService->sendEmbed(
                        "$emoji Log: " . ($isPerformance ? ($isAlert ? 'PERFORMANCE_ALERT' : 'PERFORMANCE') : $level),
                        $messageStr,
                        $color,
                        [
                            ['name' => 'Channel', 'value' => $data['channel'] ?? 'payments', 'inline' => true],
                            ['name' => 'Line', 'value' => (string)$currentLineNum, 'inline' => true],
                            ['name' => 'Context', 'value' => "```json\n$context\n```"]
                        ]
                    );
                    $syncCount++;
                }
            }

            fclose($handle);

            if ($currentLineNum > $lastLineProcessed) {
                $state[$logPath] = $currentLineNum;
                $this->saveState($state);
            }

            if ($syncCount > 0) {
                $io->success("Synchronization completed. $syncCount new entries sent to Discord.");
            }

            if ($continuous) {
                // Dorme por 1 segundo no monitoramento contínuo
                usleep(1000000);
                // Recarrega o estado atualizado
                $state = $this->loadState();
            }
        } while ($continuous);

        return Command::SUCCESS;
    }

    private function loadState(): array
    {
        if (file_exists($this->stateFile)) {
            return json_decode(file_get_contents($this->stateFile), true) ?: [];
        }
        return [];
    }

    private function saveState(array $state): void
    {
        // Limpa estados de arquivos com mais de 7 dias para não crescer indefinidamente
        foreach ($state as $file => $line) {
            if (strpos($file, 'app-') !== false) {
                $fileDate = substr(basename($file), 4, 10);
                if (strtotime($fileDate) < strtotime('-7 days')) {
                    unset($state[$file]);
                }
            }
        }

        file_put_contents($this->stateFile, json_encode($state, JSON_PRETTY_PRINT));
    }
}
