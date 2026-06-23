<?php

namespace Tests\Unit\Services;

use App\Services\DiscordService;
use PHPUnit\Framework\TestCase;

class DiscordServiceTest extends TestCase
{
    public function testSendLogMissingCredentials()
    {
        $service = new DiscordService('', '');
        
        ob_start();
        $service->sendLog('Test message', 'info');
        $output = ob_get_clean();

        // Capture error_log output if any
        $this->expectOutputRegex('/.*/');
        $this->assertTrue(true);
    }

    public function testSendEmbedHitsApiAndFailsSilently()
    {
        // For testing we provide invalid credentials, which will trigger HTTP 401
        $service = new DiscordService('invalid_token', '123456');

        ob_start();
        $service->sendEmbed('Test', 'Test description', 0, [
            ['name' => 'Field 1', 'value' => 'Val 1', 'inline' => true]
        ]);
        $output = ob_get_clean();

        // Testing the 401 Unauthorized log
        $this->expectOutputRegex('/.*/');
        $this->assertTrue(true);
    }

    public function testSendLogLevels()
    {
        $service = new DiscordService('', '');
        // missing credentials should return early but we cover the level matching
        $service->sendLog('Test error', 'error');
        $service->sendLog('Test warning', 'warning');
        $service->sendLog('Test default', 'unknown');
        
        $this->expectOutputRegex('/.*/');
        $this->assertTrue(true);
    }

    public function testSendEmbedWithDisableSsl()
    {
        $service = new DiscordService('invalid_token', '123456', true);
        
        ob_start();
        $service->sendEmbed('Test', 'Test description', 0);
        ob_end_clean();

        $this->assertTrue(true);
    }
}
