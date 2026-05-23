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
        $this->stateFile = rtrim($logDir, '/') . '/sync_state.json';
    }

    protected function configure(): void
    {
        $this->addOption(
            'all',
            'a',
            InputOption::VALUE_NONE,
            'Se definido, sincroniza todos os níveis de log (incluindo INFO)'
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $syncAll = $input->getOption('all');

        $today = date('Y-m-d');
        $logPath = rtrim($this->logDir, '/') . "/app-{$today}.log";

        if (!file_exists($logPath)) {
            $io->warning("Arquivo de log de hoje não encontrado: {$logPath}");
            return Command::SUCCESS;
        }

        $state = $this->loadState();
        $lastLineProcessed = $state[$logPath] ?? 0;

        $io->info("Lendo logs de hoje: {$logPath} (Iniciando da linha: " . ($lastLineProcessed + 1) . ")");
        if ($syncAll) {
            $io->note("Modo MANUAL: Sincronizando todos os níveis de log (INFO inclusive).");
        }

        $handle = fopen($logPath, 'r');
        if (!$handle) {
            $io->error("Não foi possível abrir o arquivo de log.");
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
            
            $shouldSync = $syncAll || in_array($level, ['ERROR', 'CRITICAL', 'ALERT', 'EMERGENCY', 'WARNING']);

            if ($shouldSync) {
                $message = $data['message'] ?? 'Sem mensagem';
                $context = !empty($data['context']) ? json_encode($data['context'], JSON_PRETTY_PRINT) : 'Nenhum';
                
                $color = ($level === 'INFO') ? 0x3498db : 0xff0000;
                $emoji = ($level === 'INFO') ? 'ℹ️' : '🚨';

                $this->discordService->sendEmbed(
                    "$emoji Log: $level",
                    $message,
                    $color,
                    [
                        ['name' => 'Canal', 'value' => $data['channel'] ?? 'payments', 'inline' => true],
                        ['name' => 'Linha', 'value' => (string)$currentLineNum, 'inline' => true],
                        ['name' => 'Contexto', 'value' => "```json\n$context\n```"]
                    ]
                );
                $syncCount++;
            }
        }

        fclose($handle);

        // Atualiza o estado
        $state[$logPath] = $currentLineNum;
        $this->saveState($state);

        if ($syncCount > 0) {
            $io->success("Sincronização concluída. $syncCount novas entradas enviadas ao Discord.");
        } else {
            $io->note("Nenhum novo log crítico encontrado desde a última execução.");
        }

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
