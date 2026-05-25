<?php

namespace Tests\Unit\Handler\Processors;

use App\Handlers\Processors\PaymentReceivedProcessor;
use App\Interfaces\PaymentGatewayInterface;
use App\Interfaces\EmailServiceInterface;
use App\Interfaces\LicenseServiceInterface;
use App\Interfaces\PromotionServiceInterface;
use App\Interfaces\LandingPageSyncServiceInterface;
use App\Interfaces\Repositories\CustomerRepositoryInterface;
use App\Interfaces\Repositories\AuditLogRepositoryInterface;
use App\Entity\Customer;
use PHPUnit\Framework\TestCase;
use Monolog\Logger;

class PaymentReceivedProcessorTest extends TestCase
{
    private PaymentGatewayInterface|\PHPUnit\Framework\MockObject\MockObject $gateway;
    private EmailServiceInterface|\PHPUnit\Framework\MockObject\MockObject $emailService;
    private LicenseServiceInterface|\PHPUnit\Framework\MockObject\MockObject $licenseService;
    private CustomerRepositoryInterface|\PHPUnit\Framework\MockObject\MockObject $customerRepo;
    private AuditLogRepositoryInterface|\PHPUnit\Framework\MockObject\MockObject $auditRepo;
    private Logger|\PHPUnit\Framework\MockObject\MockObject $logger;
    private PromotionServiceInterface|\PHPUnit\Framework\MockObject\MockObject $promotionService;
    private LandingPageSyncServiceInterface|\PHPUnit\Framework\MockObject\MockObject $syncService;
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
            'test-salt',
            $this->promotionService,
            $this->syncService
        );
    }

    public function testProcessSuccess()
    {
        $data = [
            'payment' => ['customer' => 'cus_123']
        ];

        $this->gateway->expects($this->once())
            ->method('getCustomerInfo')
            ->willReturn([
                'email' => 'user@example.com',
                'name' => 'User Test',
                'mobilePhone' => '5511999999999'
            ]);


        $this->licenseService->expects($this->once())
            ->method('generateLicense')
            ->willReturn('ABCD-1234');

        $this->emailService->expects($this->once())
            ->method('sendLicenseEmail')
            ->with('user@example.com', 'ABCD-1234', $this->logger, 'User Test')
            ->willReturn(true);

        $this->customerRepo->expects($this->once())
            ->method('save');
            
        $this->customerRepo->expects($this->once())
            ->method('countPaidLifetimeCustomers')
            ->willReturn(10);
            
        $this->syncService->expects($this->once())
            ->method('notifySale');

        $this->processor->process($data);
    }

    public function testProcessGatewayFailure()
    {
        $data = [
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

        $this->processor->process($data);
    }

    public function testProcessSubscriptionMonthly()
    {
        $data = [
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
            ->method('findBySubscriptionId')
            ->with('sub_987654')
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

        $this->processor->process($data);

        $this->assertInstanceOf(Customer::class, $savedCustomer);
        $this->assertEquals('MONTHLY', $savedCustomer->getPlan());
        $this->assertEquals('sub_987654', $savedCustomer->getSubscriptionId());
        $this->assertNotNull($savedCustomer->getLicenseExpiresAt());
        $this->assertEquals('2026-06-19', $savedCustomer->getLicenseExpiresAt()->format('Y-m-d'));
    }

    public function testProcessMultipleLifetimePurchasesGenerateDifferentLicenses()
    {
        $data = [
            'payment' => [
                'id' => 'pay_second_123',
                'customer' => 'cus_123'
            ]
        ];

        $this->gateway->expects($this->once())
            ->method('getCustomerInfo')
            ->willReturn([
                'email' => 'lifetime@example.com',
                'name' => 'Lifetime User',
                'mobilePhone' => '5511999999999'
            ]);

        // E-mail já existe, mas como é um pagamento Lifetime (sem subscriptionId),
        // findBySubscriptionId não é chamado e gera uma nova licença para o mesmo e-mail.
        $this->licenseService->expects($this->once())
            ->method('generateLicense')
            ->willReturn('NEW-LIC-123');

        $this->emailService->expects($this->once())
            ->method('sendLicenseEmail')
            ->with('lifetime@example.com', 'NEW-LIC-123', $this->logger, 'Lifetime User')
            ->willReturn(true);

        $savedCustomer = null;
        $this->customerRepo->expects($this->once())
            ->method('save')
            ->with($this->callback(function (Customer $customer) use (&$savedCustomer) {
                $savedCustomer = $customer;
                return true;
            }));

        $this->processor->process($data);

        $this->assertNotNull($savedCustomer);
        $this->assertEquals('NEW-LIC-123', $savedCustomer->getLicenseKey());
        $this->assertEquals('lifetime@example.com', $savedCustomer->getEmail());
        $this->assertEquals('LIFETIME', $savedCustomer->getPlan());
    }

    public function testProcessMonthlyExtension()
    {
        $data = [
            'payment' => [
                'id' => 'pay_renew_123',
                'customer' => 'cus_123',
                'subscription' => 'sub_monthly_123'
            ]
        ];

        $existingCustomer = new Customer('Monthly User', 'monthly@example.com', '5511999999999');
        $existingCustomer->markAsPaid('pay_initial_123');
        $existingCustomer->setPlan('MONTHLY');
        $existingCustomer->setSubscriptionId('sub_monthly_123');

        $initialExpiration = new \DateTime('2026-06-01 12:00:00');
        $existingCustomer->setLicenseExpiresAt($initialExpiration);

        $this->gateway->expects($this->once())
            ->method('getCustomerInfo')
            ->willReturn([
                'email' => 'monthly@example.com',
                'name' => 'Monthly User',
                'mobilePhone' => '5511999999999'
            ]);

        $this->customerRepo->expects($this->once())
            ->method('findBySubscriptionId')
            ->with('sub_monthly_123')
            ->willReturn($existingCustomer);

        $this->customerRepo->expects($this->once())
            ->method('save')
            ->with($existingCustomer);

        $this->logger->expects($this->once())
            ->method('info')
            ->with('Monthly subscription extended for customer');

        $this->processor->process($data);

        $this->assertEquals('2026-07-01 12:00:00', $existingCustomer->getLicenseExpiresAt()->format('Y-m-d H:i:s'));
    }

    public function testProcessLicenseCollisionResolvesWithUniqueKey()
    {
        $data = [
            'payment' => ['customer' => 'cus_new_123']
        ];

        $this->gateway->expects($this->once())
            ->method('getCustomerInfo')
            ->willReturn([
                'email' => 'kain_1517@hotmail.com',
                'name' => 'Kain Test',
                'mobilePhone' => '5511999999999'
            ]);

        // A primeira chave gerada colide, a segunda é única
        $this->licenseService->expects($this->exactly(2))
            ->method('generateLicense')
            ->willReturnMap([
                ['5511999999999', 'test-salt', 'DUPLICATE-KEY'],
                ['5511999999999_1', 'test-salt', 'UNIQUE-KEY']
            ]);

        // Simula que 'DUPLICATE-KEY' já está com outro cliente
        $collidingCustomer = new Customer('Other User', 'other@example.com', '5511999999999');
        
        $this->customerRepo->expects($this->exactly(2))
            ->method('findByLicenseKey')
            ->willReturnMap([
                ['DUPLICATE-KEY', $collidingCustomer],
                ['UNIQUE-KEY', null]
            ]);

        $this->logger->expects($this->once())
            ->method('warning')
            ->with($this->stringContains('Security Alert: WhatsApp reuse or license collision attempt'));

        // Verifica que o e-mail recebe a licença correta (única)
        $this->emailService->expects($this->once())
            ->method('sendLicenseEmail')
            ->with('kain_1517@hotmail.com', 'UNIQUE-KEY', $this->logger, 'Kain Test')
            ->willReturn(true);

        $savedCustomer = null;
        $this->customerRepo->expects($this->once())
            ->method('save')
            ->with($this->callback(function ($customer) use (&$savedCustomer) {
                $savedCustomer = $customer;
                return true;
            }));

        $this->processor->process($data);

        $this->assertNotNull($savedCustomer);
        $this->assertEquals('UNIQUE-KEY', $savedCustomer->getLicenseKey());
        $this->assertEquals('kain_1517@hotmail.com', $savedCustomer->getEmail());
    }
}
