<?php

namespace App\Services;

use App\Interfaces\DiscordServiceInterface;

class DiscordService implements DiscordServiceInterface
{
    private string $botToken;
    private string $channelId;

    public function __construct(string $botToken, string $channelId)
    {
        $this->botToken = $botToken;
        $this->channelId = $channelId;
    }

    public function sendLog(string $message, string $level = 'info'): void
    {
        $color = match (strtolower($level)) {
            'critical', 'error' => 0xff0000,
            'warning' => 0xffff00,
            default => 0x00ff00,
        };

        $this->sendEmbed(strtoupper($level) . " Log", $message, $color);
    }

    public function sendEmbed(string $title, string $description, int $color = 0x00ff00, array $fields = []): void
    {
        if (empty($this->botToken) || empty($this->channelId)) {
            error_log('[DiscordService] Warning: Discord Bot Token or Channel ID not configured.');
            return;
        }

        // Usar a API REST do Discord diretamente é muito mais eficiente para comandos CLI
        // do que abrir um WebSocket (Gateway) que mantém o processo vivo.
        $url = "https://discord.com/api/v10/channels/{$this->channelId}/messages";

        $embed = [
            'title' => $title,
            'description' => $description,
            'color' => $color,
            'timestamp' => date('c'),
            'footer' => [
                'text' => 'AIFreelas Payments Bot'
            ]
        ];

        foreach ($fields as $field) {
            $embed['fields'][] = [
                'name' => $field['name'],
                'value' => (string)$field['value'],
                'inline' => $field['inline'] ?? false
            ];
        }

        $payload = json_encode(['embeds' => [$embed]]);

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Authorization: Bot ' . $this->botToken
        ]);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            error_log('[DiscordService] Error sending REST request: ' . $error);
        } elseif ($httpCode >= 400) {
            error_log("[DiscordService] Discord API returned error {$httpCode}: " . $response);
        }
    }
}
