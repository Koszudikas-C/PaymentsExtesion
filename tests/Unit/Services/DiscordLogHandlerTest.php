<?php

namespace Tests\Unit\Services;

use App\Interfaces\DiscordServiceInterface;
use App\Services\DiscordLogHandler;
use Monolog\LogRecord;
use Monolog\Level;
use PHPUnit\Framework\TestCase;

class DiscordLogHandlerTest extends TestCase
{
    public function testHandleWritesToDiscord()
    {
        $discordService = $this->createMock(DiscordServiceInterface::class);
        $handler = new DiscordLogHandler($discordService);

        $discordService->expects($this->once())
            ->method('sendEmbed')
            ->with(
                $this->stringContains('Alerta IMEDIATO'),
                $this->stringContains('Critical error occurred'),
                0xff0000,
                $this->callback(function ($fields) {
                    return count($fields) === 2 && $fields[0]['name'] === 'Canal';
                })
            );

        $record = new LogRecord(
            new \DateTimeImmutable(),
            'app',
            Level::Critical,
            'Critical error occurred',
            ['userId' => 1]
        );

        $handler->handle($record);
    }

    public function testHandleTruncatesLongMessage()
    {
        $discordService = $this->createMock(DiscordServiceInterface::class);
        $handler = new DiscordLogHandler($discordService);

        $longMessage = str_repeat('A', 2500);
        $longContext = ['data' => str_repeat('B', 1500)];

        $discordService->expects($this->once())
            ->method('sendEmbed')
            ->with(
                $this->anything(),
                $this->stringContains('(truncado)'),
                0xff0000,
                $this->callback(function ($fields) {
                    return strpos($fields[1]['value'], '(truncado)') !== false;
                })
            );

        $record = new LogRecord(
            new \DateTimeImmutable(),
            'app',
            Level::Critical,
            $longMessage,
            $longContext
        );

        $handler->handle($record);
    }
}
