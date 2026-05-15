<?php

namespace Tests\Unit\Service;

use App\Services\PHPMailerService;
use PHPUnit\Framework\TestCase;
use Monolog\Logger;

class PHPMailerServiceTest extends TestCase
{
    public function testSendLicenseEmailReturnsFalseOnInvalidConfig()
    {
        $logger = $this->createMock(Logger::class);
        $config = [
            'host' => 'localhost',
            'username' => 'test',
            'password' => 'test',
            'port' => 25
        ];
        
        $service = new PHPMailerService($config);

        // This should fail because there is no SMTP server at localhost:25
        $result = $service->sendLicenseEmail('test@example.com', 'CODE-123', $logger);

        $this->assertFalse($result);
    }
}
