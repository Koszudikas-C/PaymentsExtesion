<?php

namespace Tests\Unit\Handler;

use App\Handlers\WebhookHandler;
use App\Interfaces\PaymentGatewayInterface;
use App\Interfaces\EmailServiceInterface;
use App\Interfaces\LicenseServiceInterface;
use App\Interfaces\PromotionServiceInterface;
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
    private PromotionServiceInterface|\PHPUnit\Framework\MockObject\MockObject $promotionService;
    private WebhookHandler $handler;

    protected function setUp(): void
    {
        $this->gateway = $this->createMock(PaymentGatewayInterface::class);
        $this->emailService = $this->createMock(EmailServiceInterface::class);
        $this->licenseService = $this->createMock(LicenseServiceInterface::class);
        $this->customerRepo = $this->createMock(CustomerRepositoryInterface::class);
        $this->auditRepo = $this->createMock(AuditLogRepositoryInterface::class);
        $this->logger = $this->createMock(Logger::class);
        $this->promotionService = $this->createMock(PromotionServiceInterface::class);

        $this->handler = new WebhookHandler(
            $this->gateway,
            $this->emailService,
            $this->licenseService,
            $this->customerRepo,
            $this->auditRepo,
            $this->logger,
            'test-salt',
            $this->promotionService
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

    public function testHandlePaymentReceivedSubscriptionMonthly()
    {
        $data = [
            'event' => 'PAYMENT_RECEIVED',
            'payment' => [
                'customer' => 'cus_123',
                'subscription' => 'sub_987654',
                'dueDate' => '2026-06-16'
            ]
        ];

        $this->gateway->expects($this->once())
            ->method('getCustomerInfo')
            ->willReturn([
                'email' => 'subscriber@example.com',
                'name' => 'Monthly Subscriber',
                'mobilePhone' => '5511999999999'
            ]);

        $this->customerRepo->expects($this->once())
            ->method('findByEmail')
            ->willReturn(null);

        $this->licenseService->expects($this->once())
            ->method('generateLicense')
            ->willReturn('SUB-1010');

        $this->emailService->expects($this->once())
            ->method('sendLicenseEmail')
            ->willReturn(true);

        $savedCustomer = null;
        $this->customerRepo->expects($this->once())
            ->method('save')
            ->with($this->callback(function ($customer) use (&$savedCustomer) {
                $savedCustomer = $customer;
                return true;
            }));

        $this->handler->handle($data);

        $this->assertInstanceOf(\App\Entity\Customer::class, $savedCustomer);
        $this->assertEquals('MONTHLY', $savedCustomer->getPlan());
        $this->assertEquals('sub_987654', $savedCustomer->getSubscriptionId());
        $this->assertNotNull($savedCustomer->getLicenseExpiresAt());
        // 2026-06-16 + 3 days = 2026-06-19
        $this->assertEquals('2026-06-19', $savedCustomer->getLicenseExpiresAt()->format('Y-m-d'));
        $this->assertTrue($savedCustomer->isLicenseActive());
    }

    public function testHandlePaymentReceivedReachesGoalTriggersAsaasLinkConversion()
    {
        $data = [
            'event' => 'PAYMENT_RECEIVED',
            'payment' => ['customer' => 'cus_123']
        ];

        $promotionService = new \App\Services\PromotionService(
            $this->customerRepo,
            $this->gateway,
            'lnk_test_goal',
            19.90,
            'AIFreelas - Assinatura Mensal'
        );

        // Instantiate handler with promotionService
        $handler = new WebhookHandler(
            $this->gateway,
            $this->emailService,
            $this->licenseService,
            $this->customerRepo,
            $this->auditRepo,
            $this->logger,
            'test-salt',
            $promotionService
        );

        $this->gateway->expects($this->once())
            ->method('getCustomerInfo')
            ->willReturn([
                'email' => 'goal@example.com',
                'name' => 'Goal User',
                'mobilePhone' => '5511999999999'
            ]);

        $this->customerRepo->expects($this->once())
            ->method('findByEmail')
            ->willReturn(null);

        $this->licenseService->expects($this->once())
            ->method('generateLicense')
            ->willReturn('GOAL-100');

        $this->emailService->expects($this->once())
            ->method('sendLicenseEmail')
            ->willReturn(true);

        $this->customerRepo->expects($this->once())
            ->method('save');

        // Expect countPaidLifetimeCustomers to return 100
        $this->customerRepo->expects($this->once())
            ->method('countPaidLifetimeCustomers')
            ->willReturn(100);

        // Expect updatePaymentLinkToMonthly to be called with correct arguments
        $this->gateway->expects($this->once())
            ->method('updatePaymentLinkToMonthly')
            ->with('lnk_test_goal', 19.90, 'AIFreelas - Assinatura Mensal', $this->logger)
            ->willReturn(true);

        $this->logger->expects($this->exactly(3))
            ->method('info');

        $handler->handle($data);
    }

    public function testHandlePaymentReceivedDoublePaymentLifetimeRefundQueue()
    {
        $data = [
            'event' => 'PAYMENT_RECEIVED',
            'payment' => [
                'id' => 'pay_double_123',
                'customer' => 'cus_123'
            ]
        ];

        $existingCustomer = new Customer('Lifetime User', 'lifetime@example.com', '5511999999999');
        $existingCustomer->markAsPaid('pay_initial_123');
        $existingCustomer->setPlan('LIFETIME');

        // Mock gateway customer info lookup
        $this->gateway->expects($this->once())
            ->method('getCustomerInfo')
            ->with('cus_123', $this->logger)
            ->willReturn([
                'email' => 'lifetime@example.com',
                'name' => 'Lifetime User',
                'mobilePhone' => '5511999999999'
            ]);

        // Pre-configure mock repo to return this customer
        $this->customerRepo->expects($this->once())
            ->method('findByEmail')
            ->with('lifetime@example.com')
            ->willReturn($existingCustomer);

        // Ensure we save the customer with the new audit log entry
        $this->customerRepo->expects($this->once())
            ->method('save')
            ->with($existingCustomer);

        $this->logger->expects($this->once())
            ->method('warning')
            ->with('Double payment detected for Lifetime user. Logged for refund.');

        $this->handler->handle($data);

        // Verify audit log has the double payment event
        $audits = $existingCustomer->getAuditLogs();
        $this->assertCount(3, $audits); // 1. CUSTOMER_CREATED, 2. PAYMENT_RECEIVED, 3. DOUBLE_PAYMENT_DETECTED
        $this->assertEquals('DOUBLE_PAYMENT_DETECTED', $audits->last()->getAction());
    }

    public function testHandlePaymentReceivedMonthlyExtension()
    {
        $data = [
            'event' => 'PAYMENT_RECEIVED',
            'payment' => [
                'id' => 'pay_renew_123',
                'customer' => 'cus_123'
            ]
        ];

        $existingCustomer = new Customer('Monthly User', 'monthly@example.com', '5511999999999');
        $existingCustomer->markAsPaid('pay_initial_123');
        $existingCustomer->setPlan('MONTHLY');

        $initialExpiration = new \DateTime('2026-06-01 12:00:00');
        $existingCustomer->setLicenseExpiresAt($initialExpiration);

        $this->gateway->expects($this->once())
            ->method('getCustomerInfo')
            ->with('cus_123', $this->logger)
            ->willReturn([
                'email' => 'monthly@example.com',
                'name' => 'Monthly User',
                'mobilePhone' => '5511999999999'
            ]);

        $this->customerRepo->expects($this->once())
            ->method('findByEmail')
            ->with('monthly@example.com')
            ->willReturn($existingCustomer);

        $this->customerRepo->expects($this->once())
            ->method('save')
            ->with($existingCustomer);

        $this->logger->expects($this->once())
            ->method('info')
            ->with('Monthly subscription extended for customer');

        $this->handler->handle($data);

        // Verify extended expiration date (2026-06-01 + 30 days = 2026-07-01)
        $this->assertEquals('2026-07-01 12:00:00', $existingCustomer->getLicenseExpiresAt()->format('Y-m-d H:i:s'));

        // Verify audit log has PLAN_EXTENDED
        $audits = $existingCustomer->getAuditLogs();
        $this->assertEquals('PLAN_EXTENDED', $audits->last()->getAction());
    }
}
