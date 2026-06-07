<?php

namespace Tests\Unit\Services;

use App\Entity\Customer;
use App\Services\PromotionService;
use App\Interfaces\PaymentGatewayInterface;
use App\Interfaces\Repositories\CustomerRepositoryInterface;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;

class PromotionServiceTest extends TestCase
{
    private $customerRepo;
    private $paymentGateway;
    private $logger;

    protected function setUp(): void
    {
        $this->customerRepo = $this->createMock(CustomerRepositoryInterface::class);
        $this->paymentGateway = $this->createMock(PaymentGatewayInterface::class);
        $this->logger = $this->createMock(Logger::class);
    }

    public function testHandlePromotionGoalSkipsIfCustomerNotLifetime()
    {
        $customer = new Customer('Test', 'test@test.com', '123');
        $customer->setPlan('MONTHLY');
        $customer->markAsPaid('pay_123');

        $service = new PromotionService($this->customerRepo, $this->paymentGateway, 'link_123');
        
        $this->customerRepo->expects($this->never())->method('countPaidLifetimeCustomers');
        $service->handlePromotionGoal($customer, $this->logger);
    }

    public function testHandlePromotionGoalSkipsIfCustomerNotPaid()
    {
        $customer = new Customer('Test', 'test@test.com', '123');
        $customer->setPlan('LIFETIME');

        $service = new PromotionService($this->customerRepo, $this->paymentGateway, 'link_123');
        
        $this->customerRepo->expects($this->never())->method('countPaidLifetimeCustomers');
        $service->handlePromotionGoal($customer, $this->logger);
    }

    public function testHandlePromotionGoalSkipsIfLinkEmpty()
    {
        $customer = new Customer('Test', 'test@test.com', '123');
        $customer->setPlan('LIFETIME');
        $customer->markAsPaid('pay_123');

        $service = new PromotionService($this->customerRepo, $this->paymentGateway, '');
        
        $this->logger->expects($this->once())->method('warning');
        $this->customerRepo->expects($this->never())->method('countPaidLifetimeCustomers');
        $service->handlePromotionGoal($customer, $this->logger);
    }

    public function testHandlePromotionGoalFailsGracefullyOnException()
    {
        $customer = new Customer('Test', 'test@test.com', '123');
        $customer->setPlan('LIFETIME');
        $customer->markAsPaid('pay_123');

        $service = new PromotionService($this->customerRepo, $this->paymentGateway, 'link_123');
        
        $this->customerRepo->method('countPaidLifetimeCustomers')->willThrowException(new \Exception('DB Error'));
        
        $this->logger->expects($this->once())->method('error');
        $service->handlePromotionGoal($customer, $this->logger);
    }

    public function testHandlePromotionGoalTriggersConversionWhen100Reached()
    {
        $customer = new Customer('Test', 'test@test.com', '123');
        $customer->setPlan('LIFETIME');
        $customer->markAsPaid('pay_123');

        $service = new PromotionService($this->customerRepo, $this->paymentGateway, 'link_123', 19.90, 'Mensal');
        
        $this->customerRepo->method('countPaidLifetimeCustomers')->willReturn(100);
        
        $this->paymentGateway->expects($this->once())
            ->method('updatePaymentLinkToMonthly')
            ->with('link_123', 19.90, 'Mensal', $this->logger)
            ->willReturn(true);
            
        $this->logger->expects($this->exactly(2))->method('info');

        $service->handlePromotionGoal($customer, $this->logger);
    }

    public function testHandlePromotionGoalLogsErrorIfConversionFails()
    {
        $customer = new Customer('Test', 'test@test.com', '123');
        $customer->setPlan('LIFETIME');
        $customer->markAsPaid('pay_123');

        $service = new PromotionService($this->customerRepo, $this->paymentGateway, 'link_123', 19.90, 'Mensal');
        
        $this->customerRepo->method('countPaidLifetimeCustomers')->willReturn(101);
        
        $this->paymentGateway->expects($this->once())
            ->method('updatePaymentLinkToMonthly')
            ->willReturn(false);
            
        $this->logger->expects($this->once())->method('error')->with($this->stringContains('Failed to convert'));

        $service->handlePromotionGoal($customer, $this->logger);
    }
}
