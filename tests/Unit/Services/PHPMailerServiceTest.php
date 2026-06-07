<?php

namespace Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use App\Services\PHPMailerService;
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
use Monolog\Logger;
use Monolog\Handler\TestHandler;

class TestablePHPMailerService extends PHPMailerService
{
    public $mockMailer;

    protected function createMailer(): PHPMailer
    {
        return $this->mockMailer;
    }
}

class PHPMailerServiceTest extends TestCase
{
    private $logger;
    private $testHandler;

    protected function setUp(): void
    {
        $this->testHandler = new TestHandler();
        $this->logger = new Logger('test');
        $this->logger->pushHandler($this->testHandler);
    }

    public function testSendLicenseEmailSuccess()
    {
        $mockMailer = $this->createMock(PHPMailer::class);
        $mockMailer->expects($this->once())->method('send')->willReturn(true);
        $mockMailer->expects($this->once())->method('setFrom');
        $mockMailer->expects($this->once())->method('addAddress');
        
        $service = new TestablePHPMailerService([
            'host' => 'smtp.test.com',
            'username' => 'test_user',
            'password' => 'test_pass',
            'port' => 587,
            'from' => 'no-reply@test.com',
            'from_name' => 'Test Sender'
        ]);
        $service->mockMailer = $mockMailer;

        $result = $service->sendLicenseEmail('client@test.com', 'LIC-123', $this->logger, 'Client');

        $this->assertTrue($result);
        $this->assertFalse($this->testHandler->hasErrorRecords());
    }

    public function testSendLicenseEmailHandlesException()
    {
        $mockMailer = $this->createMock(PHPMailer::class);
        $mockMailer->expects($this->once())->method('send')->willThrowException(new Exception("SMTP Connect Error"));
        $mockMailer->ErrorInfo = "SMTP Connect Error";
        
        $service = new TestablePHPMailerService([
            'host' => 'smtp.test.com',
            'username' => 'test_user',
            'password' => 'test_pass',
            'port' => 465
        ]);
        $service->mockMailer = $mockMailer;

        $result = $service->sendLicenseEmail('client@test.com', 'LIC-123', $this->logger, 'Client');

        $this->assertFalse($result);
        $this->assertTrue($this->testHandler->hasCriticalThatContains('SMTP Error'));
    }

    public function testSendLicenseEmailMissingTemplate()
    {
        $mockMailer = $this->createMock(PHPMailer::class);
        $mockMailer->expects($this->once())->method('send')->willReturn(true);
        
        $service = new TestablePHPMailerService([
            'host' => 'smtp.test.com',
            'username' => 'test_user',
            'password' => 'test_pass',
            'port' => 465
        ]);
        $service->mockMailer = $mockMailer;

        // "invalid_template.html" doesn't exist
        $result = $service->sendLicenseEmail('client@test.com', 'LIC-123', $this->logger, 'Client', 'invalid_template.html');

        $this->assertTrue($result);
        $this->assertTrue($this->testHandler->hasWarningThatContains('Email template not found'));
    }
}
