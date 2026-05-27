<?php

namespace App\Services;

use App\Interfaces\DiscordServiceInterface;
use Monolog\Handler\AbstractProcessingHandler;
use Monolog\LogRecord;
use Monolog\Level;

class DiscordLogHandler extends AbstractProcessingHandler
{
    private DiscordServiceInterface $discordService;

    public function __construct(DiscordServiceInterface $discordService, $level = Level::Critical, bool $bubble = true)
    {
        parent::__construct($level, $bubble);
        $this->discordService = $discordService;
    }

    protected function write(LogRecord $record): void
    {
        $levelName = $record->level->name;
        $message = $record->message;
        $context = !empty($record->context) ? json_encode($record->context, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : 'Nenhum';

        // Evita extrapolar os limites do Discord (max 4096 para descrição, 1024 para campos)
        if (strlen($message) > 2000) {
            $message = mb_strcut($message, 0, 1980, 'UTF-8') . " ... (truncado)";
        }

        if (strlen($context) > 980) {
            $context = mb_strcut($context, 0, 960, 'UTF-8') . "\n... (truncado)";
        }

        $this->discordService->sendEmbed(
            "🔥 Alerta IMEDIATO: $levelName",
            $message,
            0xff0000,
            [
                ['name' => 'Canal', 'value' => $record->channel, 'inline' => true],
                ['name' => 'Contexto', 'value' => "```json\n$context\n```"]
            ]
        );
    }
}
