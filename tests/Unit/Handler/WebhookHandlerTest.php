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
            ->willReturn(null); // New customer

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
}
