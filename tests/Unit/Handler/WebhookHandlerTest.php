<?php

namespace Tests\Unit\Handler;

use App\Handlers\WebhookHandler;
use App\Interfaces\PaymentGatewayInterface;
use App\Interfaces\EmailServiceInterface;
use App\Interfaces\LicenseServiceInterface;
use App\Interfaces\Repositories\CustomerRepositoryInterface;
use App\Interfaces\Repositories\AuditLogRepositoryInterface;
use App\Entity\Customer;
use PHPUnit\Framework\TestCase;
use Monolog\Logger;

class WebhookHandlerTest extends TestCase
{
    private PaymentGatewayInterface|\PHPUnit\Framework\MockObject\MockObject $gateway;
    private EmailServiceInterface|\PHPUnit\Framework\MockObject\MockObject $emailService;
    private LicenseServiceInterface|\PHPUnit\Framework\MockObject\MockObject $licenseService;
    private CustomerRepositoryInterface|\PHPUnit\Framework\MockObject\MockObject $customerRepo;
    private AuditLogRepositoryInterface|\PHPUnit\Framework\MockObject\MockObject $auditRepo;
    private Logger|\PHPUnit\Framework\MockObject\MockObject $logger;
    private WebhookHandler $handler;

    protected function setUp(): void
    {
        $this->gateway = $this->createMock(PaymentGatewayInterface::class);
        $this->emailService = $this->createMock(EmailServiceInterface::class);
        $this->licenseService = $this->createMock(LicenseServiceInterface::class);
        $this->customerRepo = $this->createMock(CustomerRepositoryInterface::class);
        $this->auditRepo = $this->createMock(AuditLogRepositoryInterface::class);
        $this->logger = $this->createMock(Logger::class);

        $this->handler = new WebhookHandler(
            $this->gateway,
            $this->emailService,
            $this->licenseService,
            $this->customerRepo,
            $this->auditRepo,
            $this->logger,
            'test-salt'
        );
    }

    public function testHandlePaymentReceivedSuccess()
    {
        $data = [
            'event' => 'PAYMENT_RECEIVED',
            'payment' => ['customer' => 'cus_123']
        ];

        $this->gateway->expects($this->once())
            ->method('getCustomerInfo')
            ->willReturn([
                'email' => 'user@example.com',
                'name' => 'User Test',
                'mobilePhone' => '5511999999999'
            ]);

        $this->customerRepo->expects($this->once())
            ->method('findByEmail')
            ->willReturn(null);

        $this->licenseService->expects($this->once())
            ->method('generateLicense')
            ->willReturn('ABCD-1234');

        $this->emailService->expects($this->once())
            ->method('sendLicenseEmail')
            ->with('user@example.com', 'ABCD-1234', $this->logger, 'User Test')
            ->willReturn(true);

        $this->customerRepo->expects($this->once())
            ->method('save')
            ->with($this->callback(function (Customer $customer) {
                return $customer->getEmail() === 'user@example.com' &&
                    $customer->isLicenseDelivered() === true;
            }));

        $this->handler->handle($data);
    }

    public function testHandleGatewayFailure()
    {
        $data = [
            'event' => 'PAYMENT_RECEIVED',
            'payment' => ['customer' => 'cus_error']
        ];

        $this->gateway->expects($this->once())
            ->method('getCustomerInfo')
            ->willReturn(null);

        $this->logger->expects($this->once())
            ->method('error')
            ->with($this->stringContains('Customer info not found'));

        $this->customerRepo->expects($this->never())
            ->method('save');

        $this->handler->handle($data);
    }

    public function testHandlePaymentReceivedDatabaseFailureFallback()
    {
        $data = [
            'event' => 'PAYMENT_RECEIVED',
            'payment' => ['customer' => 'cus_123']
        ];

        $this->gateway->expects($this->once())
            ->method('getCustomerInfo')
            ->willReturn([
                'email' => 'fallback-user@example.com',
                'name' => 'Fallback User',
                'mobilePhone' => '5511999999999'
            ]);

        $this->customerRepo->expects($this->once())
            ->method('findByEmail')
            ->willReturn(null);

        $this->licenseService->expects($this->once())
            ->method('generateLicense')
            ->willReturn('XYZ-9876');

        $this->emailService->expects($this->once())
            ->method('sendLicenseEmail')
            ->willReturn(true);

        // Simulate database failure
        $this->customerRepo->expects($this->once())
            ->method('save')
            ->willThrowException(new \RuntimeException("Database connection lost"));

        // Expect critical log
        $this->logger->expects($this->once())
            ->method('critical')
            ->with($this->stringContains('Database error persisting customer. Falling back to local file.'));

        // Clean up fallback file if it already exists
        $fallbackFile = __DIR__ . '/../../../logs/failed_licenses.json';
        if (file_exists($fallbackFile)) {
            unlink($fallbackFile);
        }

        $this->handler->handle($data);

        // Assert fallback file exists and has correct data
        $this->assertFileExists($fallbackFile);
        $content = file_get_contents($fallbackFile);
        $savedData = json_decode($content, true);

        $this->assertCount(1, $savedData);
        $record = $savedData[0];
        $this->assertEquals('Fallback User', $record['name']);
        $this->assertEquals('fallback-user@example.com', $record['email']);
        $this->assertEquals('XYZ-9876', $record['licenseKey']);
        $this->assertEquals('Database connection lost', $record['error']);

        // Clean up after test
        unlink($fallbackFile);
    }

    public function testHandlePaymentReceivedLookupFailureFallback()
    {
        $data = [
            'event' => 'PAYMENT_RECEIVED',
            'payment' => ['customer' => 'cus_123']
        ];

        $this->gateway->expects($this->once())
            ->method('getCustomerInfo')
            ->willReturn([
                'email' => 'lookup-fail@example.com',
                'name' => 'Lookup Fail User',
                'mobilePhone' => '5511999999999'
            ]);

        // Simulate database lookup failure
        $this->customerRepo->expects($this->once())
            ->method('findByEmail')
            ->willThrowException(new \RuntimeException("Connection timeout"));

        $this->licenseService->expects($this->once())
            ->method('generateLicense')
            ->willReturn('OFFLINE-123');

        $this->emailService->expects($this->once())
            ->method('sendLicenseEmail')
            ->willReturn(true);

        // Expect offline log
        $this->logger->expects($this->once())
            ->method('error')
            ->with($this->stringContains('Database offline during customer lookup. Using fallback flow.'));

        // Clean up fallback file if it already exists
        $fallbackFile = __DIR__ . '/../../../logs/failed_licenses.json';
        if (file_exists($fallbackFile)) {
            unlink($fallbackFile);
        }

        $this->handler->handle($data);

        // Assert fallback file exists and has correct data
        $this->assertFileExists($fallbackFile);
        $content = file_get_contents($fallbackFile);
        $savedData = json_decode($content, true);

        $this->assertCount(1, $savedData);
        $record = $savedData[0];
        $this->assertEquals('Lookup Fail User', $record['name']);
        $this->assertEquals('lookup-fail@example.com', $record['email']);
        $this->assertEquals('OFFLINE-123', $record['licenseKey']);
        $this->assertEquals('Lookup failed: Connection timeout', $record['error']);

        // Clean up after test
        unlink($fallbackFile);
    }
}
