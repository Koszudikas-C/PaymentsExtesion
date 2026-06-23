<?php

namespace Tests\Unit\Handlers\Processors;

use App\Handlers\Processors\PaymentReceivedProcessor;
use App\Interfaces\PaymentGatewayInterface;
use App\Interfaces\EmailServiceInterface;
use App\Interfaces\LicenseServiceInterface;
use App\Interfaces\PromotionServiceInterface;
use App\Interfaces\LandingPageSyncServiceInterface;
use App\Interfaces\Repositories\CustomerRepositoryInterface;
use App\Interfaces\Repositories\AuditLogRepositoryInterface;
use App\Entity\Customer;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;

class PaymentReceivedProcessorTest extends TestCase
{
    private PaymentGatewayInterface|MockObject $gateway;
    private EmailServiceInterface|MockObject $emailService;
    private LicenseServiceInterface|MockObject $licenseService;
    private CustomerRepositoryInterface|MockObject $customerRepo;
    private AuditLogRepositoryInterface|MockObject $auditRepo;
    private Logger|MockObject $logger;
    private PromotionServiceInterface|MockObject $promotionService;
    private LandingPageSyncServiceInterface|MockObject $syncService;
    private PaymentReceivedProcessor $processor;

    protected function setUp(): void
    {
        $this->gateway = $this->createMock(PaymentGatewayInterface::class);
        $this->emailService = $this->createMock(EmailServiceInterface::class);
        $this->licenseService = $this->createMock(LicenseServiceInterface::class);
        $this->customerRepo = $this->createMock(CustomerRepositoryInterface::class);
        $this->auditRepo = $this->createMock(AuditLogRepositoryInterface::class);
        $this->logger = $this->createMock(Logger::class);
        $this->promotionService = $this->createMock(PromotionServiceInterface::class);
        $this->syncService = $this->createMock(LandingPageSyncServiceInterface::class);

        $this->processor = new PaymentReceivedProcessor(
            $this->gateway,
            $this->emailService,
            $this->licenseService,
            $this->customerRepo,
            $this->auditRepo,
            $this->logger,
            'test_salt',
            $this->promotionService,
            $this->syncService
        );
        $_ENV['MONTHLY_VALUE'] = 29.99;
        $_ENV['APP_ENV'] = 'testing';
    }

    protected function tearDown(): void
    {
        // Clean up fallback logs
        $fallbackDir = __DIR__ . '/../../../../logs_test';
        if (is_dir($fallbackDir)) {
            if (file_exists($fallbackDir . '/failed_licenses.json')) {
                unlink($fallbackDir . '/failed_licenses.json');
            }
            if (file_exists($fallbackDir . '/double_payments.json')) {
                unlink($fallbackDir . '/double_payments.json');
            }
            @rmdir($fallbackDir);
        }
    }

    public function testProcessSkipsIfAlreadyProcessed()
    {
        $data = ['payment' => ['id' => 'pay_123', 'customer' => 'cus_123']];

        $this->auditRepo->expects($this->once())
            ->method('hasPaymentBeenProcessed')
            ->with('pay_123')
            ->willReturn(true);

        $this->gateway->expects($this->never())->method('getCustomerInfo');

        $this->processor->process($data);
    }

    public function testProcessCustomerNotFound()
    {
        $data = ['payment' => ['id' => 'pay_123', 'customer' => 'cus_123']];

        $this->auditRepo->method('hasPaymentBeenProcessed')->willReturn(false);

        $this->gateway->expects($this->once())
            ->method('getCustomerInfo')
            ->with('cus_123')
            ->willReturn(null);

        $this->customerRepo->expects($this->never())->method('findByEmail');

        $this->processor->process($data);
    }

    public function testProcessNewLifetimeCustomer()
    {
        $data = [
            'payment' => [
                'id' => 'pay_123',
                'customer' => 'cus_123',
                'value' => 99.99 // Not 29.99, no subscription = LIFETIME
            ]
        ];

        $this->auditRepo->method('hasPaymentBeenProcessed')->willReturn(false);
        $this->gateway->method('getCustomerInfo')->willReturn([
            'email' => 'test@example.com',
            'name' => 'Test User',
            'mobilePhone' => '123456789'
        ]);

        $this->customerRepo->method('findByEmail')->willReturn(null);

        $this->licenseService->expects($this->once())
            ->method('generateLicense')
            ->willReturn('lic_abc');

        $this->emailService->expects($this->once())
            ->method('sendLicenseEmail')
            ->willReturn(true);

        $this->customerRepo->expects($this->once())
            ->method('save')
            ->with($this->callback(function (Customer $customer) {
                return $customer->getPlan() === 'LIFETIME'
                    && $customer->getLicenseKey() === 'lic_abc'
                    && $customer->isLicenseDelivered() === true
                    && $customer->getPaymentStatus() === 'RECEIVED';
            }));

        $this->syncService->expects($this->once())->method('notifySale');
        $this->promotionService->expects($this->once())->method('handlePromotionGoal');

        $this->processor->process($data);
    }

    public function testProcessCoCreatorDoublePaymentFromLifetimeCustomer()
    {
        $data = [
            'payment' => [
                'id' => 'pay_123',
                'customer' => 'cus_123',
                'value' => 29.99 // CO-CREATOR
            ]
        ];

        $this->auditRepo->method('hasPaymentBeenProcessed')->willReturn(false);
        $this->gateway->method('getCustomerInfo')->willReturn([
            'email' => 'test@example.com',
            'name' => 'Test User',
            'mobilePhone' => '123456789'
        ]);

        $customer = new Customer('Test User', 'test@example.com', '123456789');
        $customer->setPlan('LIFETIME');
        $customer->markAsPaid('pay_old');

        $this->customerRepo->method('findByEmail')->willReturn($customer);

        // Since target plan is CO-CREATOR, it allows transition.
        $this->customerRepo->expects($this->once())
            ->method('save')
            ->with($this->callback(function (Customer $c) {
                return $c->getPlan() === 'CO-CREATOR' && $c->getFallbackPlan() === 'LIFETIME';
            }));

        $this->processor->process($data);
    }

    public function testProcessDoublePaymentLifetimeUserBuyingLifetimeAgain()
    {
        $data = [
            'payment' => [
                'id' => 'pay_dup',
                'customer' => 'cus_123',
                'value' => 99.99 // LIFETIME
            ]
        ];

        $this->auditRepo->method('hasPaymentBeenProcessed')->willReturn(false);
        $this->gateway->method('getCustomerInfo')->willReturn([
            'email' => 'test@example.com',
            'name' => 'Test User',
            'mobilePhone' => '123456789'
        ]);

        $customer = new Customer('Test User', 'test@example.com', '123456789');
        $customer->setPlan('LIFETIME');
        $customer->markAsPaid('pay_old');

        $this->customerRepo->method('findByEmail')->willReturn($customer);

        // Target plan is LIFETIME, old plan is LIFETIME. Double payment!
        $this->customerRepo->expects($this->once())->method('save');
        // Double payment skips license gen
        $this->licenseService->expects($this->never())->method('generateLicense');

        $this->processor->process($data);
        
        $this->assertFileExists(__DIR__ . '/../../../../logs_test/double_payments.json');
    }

    public function testProcessExtendsMonthlyPlan()
    {
        $data = [
            'payment' => [
                'id' => 'pay_sub',
                'customer' => 'cus_123',
                'value' => 19.99,
                'subscription' => 'sub_123'
            ]
        ];

        $this->auditRepo->method('hasPaymentBeenProcessed')->willReturn(false);
        $this->gateway->method('getCustomerInfo')->willReturn([
            'email' => 'test@example.com',
            'name' => 'Test User',
            'mobilePhone' => '123456789'
        ]);

        $customer = new Customer('Test User', 'test@example.com', '123456789');
        $customer->setPlan('MONTHLY');
        $customer->setSubscriptionId('sub_123');
        $customer->markAsPaid('pay_old');
        
        $oldDate = new \DateTime();
        $customer->setLicenseExpiresAt($oldDate);

        $this->customerRepo->method('findBySubscriptionId')->willReturn($customer);

        // Plan extended
        $this->customerRepo->expects($this->once())->method('save');

        $this->processor->process($data);
    }

    public function testProcessDatabaseFailureUsesFallback()
    {
        $data = [
            'payment' => [
                'id' => 'pay_dbfail',
                'customer' => 'cus_123',
                'value' => 99.99
            ]
        ];

        $this->auditRepo->method('hasPaymentBeenProcessed')->willThrowException(new \Exception('Audit Error'));
        
        $this->gateway->method('getCustomerInfo')->willReturn([
            'email' => 'test@example.com',
            'name' => 'Test User',
            'mobilePhone' => '123456789'
        ]);

        $this->customerRepo->method('findByEmail')->willThrowException(new \Exception('DB Offline'));

        $this->licenseService->method('generateLicense')->willReturn('lic_fallback');

        // It shouldn't save to DB
        $this->customerRepo->expects($this->never())->method('save');

        $this->processor->process($data);

        // Verify fallback file was created
        $this->assertFileExists(__DIR__ . '/../../../../logs_test/failed_licenses.json');
    }

    public function testProcessLicenseCollisionLoop()
    {
        $data = [
            'payment' => [
                'id' => 'pay_col',
                'customer' => 'cus_col',
                'value' => 99.99
            ]
        ];

        $this->auditRepo->method('hasPaymentBeenProcessed')->willReturn(false);
        $this->gateway->method('getCustomerInfo')->willReturn([
            'email' => 'col@example.com',
            'name' => 'Col User',
            'mobilePhone' => '5511999999999'
        ]);

        $this->customerRepo->method('findByEmail')->willReturn(null);

        // Simulate collisions! 10 times returning an existing customer
        $otherCustomer = new Customer('Other', 'other@test.com', '1');
        $this->customerRepo->method('findByLicenseKey')->willReturn($otherCustomer);

        $this->licenseService->expects($this->exactly(11))->method('generateLicense')->willReturn('lic_collided_then_fixed');

        $this->processor->process($data);
    }

    public function testProcessMissingPaymentData()
    {
        $data = [];
        $this->logger->expects($this->once())
            ->method('error')
            ->with('Invalid or missing payment data in webhook payload');

        $this->processor->process($data);
    }

    public function testProcessMissingCustomerId()
    {
        $data = ['payment' => []];
        $this->logger->expects($this->once())
            ->method('error')
            ->with('Missing customer ID in payment data');

        $this->processor->process($data);
    }

    public function testProcessCatchesAuditRepoExceptionAndContinues()
    {
        $data = ['payment' => ['id' => 'pay_audit_err', 'customer' => 'cus_123']];

        $this->auditRepo->method('hasPaymentBeenProcessed')->willThrowException(new \Exception('Audit Error'));
        
        $this->gateway->expects($this->once())
            ->method('getCustomerInfo')
            ->willReturn(null);

        // It should log the error and then continue to try getCustomerInfo (which returns null here to end early)
        $this->processor->process($data);
        $this->assertTrue(true);
    }

    public function testProcessHandlesOriginalValue()
    {
        $data = [
            'payment' => [
                'id' => 'pay_orig_val',
                'customer' => 'cus_123',
                'originalValue' => 29.99
            ]
        ];

        $this->auditRepo->method('hasPaymentBeenProcessed')->willReturn(false);
        $this->gateway->method('getCustomerInfo')->willReturn(['email' => 'a@b.c']);
        $this->customerRepo->method('findByEmail')->willReturn(null);
        
        $this->customerRepo->expects($this->once())
            ->method('save')
            ->with($this->callback(function (Customer $c) {
                return $c->getPlan() === 'CO-CREATOR';
            }));

        $this->processor->process($data);
    }

    public function testProcessSendLicenseEmailFails()
    {
        $data = ['payment' => ['id' => 'pay_123', 'customer' => 'cus_123', 'value' => 99.99]];
        $this->auditRepo->method('hasPaymentBeenProcessed')->willReturn(false);
        $this->gateway->method('getCustomerInfo')->willReturn(['email' => 'a@b.c']);
        $this->licenseService->method('generateLicense')->willReturn('lic_123');
        
        $this->emailService->method('sendLicenseEmail')->willReturn(false); // Simulate failure

        $this->customerRepo->expects($this->once())
            ->method('save')
            ->with($this->callback(function (Customer $c) {
                return $c->getDeliveryFailureCount() === 1 && $c->isLicenseDelivered() === false;
            }));

        $this->processor->process($data);
    }

    public function testProcessNotifiesSyncAndPromoAndHandlesTheirExceptions()
    {
        $data = ['payment' => ['id' => 'pay_123', 'customer' => 'cus_123', 'value' => 99.99]];
        $this->auditRepo->method('hasPaymentBeenProcessed')->willReturn(false);
        $this->gateway->method('getCustomerInfo')->willReturn(['email' => 'a@b.c']);
        
        // Throw exceptions in sync and promo to ensure they are caught
        $this->syncService->method('notifySale')->willThrowException(new \Exception('Sync fail'));
        $this->promotionService->method('handlePromotionGoal')->willThrowException(new \Exception('Promo fail'));

        $this->processor->process($data);
        
        // Process should finish without throwing
        $this->assertTrue(true);
    }

    public function testProcessAuditDbException2()
    {
        $data = ['payment' => ['id' => 'pay_123', 'customer' => 'cus_123']];
        $this->auditRepo->method('hasPaymentBeenProcessed')->willThrowException(new \Exception('DB Error'));
        $this->logger->expects($this->once())->method('error')->with($this->stringContains('Error checking if payment was already processed'));
        
        $this->gateway->method('getCustomerInfo')->willReturn(null); // to exit early
        
        $this->processor->process($data);
    }
    
    public function testProcessOverallException2()
    {
        $data = ['payment' => ['id' => 'pay_123', 'customer' => 'cus_123']];
        $this->auditRepo->method('hasPaymentBeenProcessed')->willReturn(false);
        $this->gateway->method('getCustomerInfo')->willThrowException(new \Exception('Fatal Gateway Error'));
        
        $this->logger->expects($this->once())->method('error')->with($this->stringContains('Failed to process payment'));
        
        $this->processor->process($data);
    }
}
