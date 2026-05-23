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
        $context = !empty($record->context) ? json_encode($record->context, JSON_PRETTY_PRINT) : 'Nenhum';

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
