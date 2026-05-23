<?php

namespace App\Interfaces;

interface DiscordServiceInterface
{
    public function sendLog(string $message, string $level = 'info'): void;
    public function sendEmbed(string $title, string $description, int $color = 0x00ff00, array $fields = []): void;
}
