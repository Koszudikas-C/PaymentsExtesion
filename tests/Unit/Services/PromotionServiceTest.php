<?php

namespace Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use App\Services\PromotionService;
use App\Entity\Customer;
use App\Interfaces\PaymentGatewayInterface;
use App\Interfaces\Repositories\CustomerRepositoryInterface;
use Monolog\Logger;
use Monolog\Handler\TestHandler;

class PromotionServiceTest extends TestCase
{
    private $logger;
    private $testHandler;

    protected function setUp(): void
    {
        $this->testHandler = new TestHandler();
        $this->logger = new Logger('test');
        $this->logger->pushHandler($this->testHandler);
    }

    public function testHandlePromotionGoalSkipsIfNotLifetimeOrNotReceived()
    {
        $customerRepoMock = $this->createMock(CustomerRepositoryInterface::class);
        $customerRepoMock->expects($this->never())->method('countPaidLifetimeCustomers');
        
        $paymentGatewayMock = $this->createMock(PaymentGatewayInterface::class);

        $service = new PromotionService($customerRepoMock, $paymentGatewayMock, 'link123');

        $customer1 = new Customer('Test', 'test@test.com', '123');
        $customer1->setPlan('MONTHLY');
        $customer1->markAsPaid('pay_1');

        $service->handlePromotionGoal($customer1, $this->logger);

        $customer2 = new Customer('Test', 'test2@test.com', '123');
        $customer2->setPlan('LIFETIME');
        // Not paid

        $service->handlePromotionGoal($customer2, $this->logger);
    }

    public function testHandlePromotionGoalSkipsIfNoLinkIdConfigured()
    {
        $customerRepoMock = $this->createMock(CustomerRepositoryInterface::class);
        $paymentGatewayMock = $this->createMock(PaymentGatewayInterface::class);

        $service = new PromotionService($customerRepoMock, $paymentGatewayMock, '');

        $customer = new Customer('Test', 'test@test.com', '123');
        $customer->setPlan('LIFETIME');
        $customer->markAsPaid('pay_1');

        $service->handlePromotionGoal($customer, $this->logger);

        $this->assertTrue($this->testHandler->hasWarningThatContains('Payment Link ID is not configured'));
    }

    public function testHandlePromotionGoalDoesNotTriggerIfTargetNotReached()
    {
        $customerRepoMock = $this->createMock(CustomerRepositoryInterface::class);
        $customerRepoMock->expects($this->once())->method('countPaidLifetimeCustomers')->willReturn(99);
        
        $paymentGatewayMock = $this->createMock(PaymentGatewayInterface::class);
        $paymentGatewayMock->expects($this->never())->method('updatePaymentLinkToMonthly');

        $service = new PromotionService($customerRepoMock, $paymentGatewayMock, 'link123');

        $customer = new Customer('Test', 'test@test.com', '123');
        $customer->setPlan('LIFETIME');
        $customer->markAsPaid('pay_1');

        $service->handlePromotionGoal($customer, $this->logger);
    }

    public function testHandlePromotionGoalTriggersConversionWhenTargetReached()
    {
        $customerRepoMock = $this->createMock(CustomerRepositoryInterface::class);
        $customerRepoMock->expects($this->once())->method('countPaidLifetimeCustomers')->willReturn(100);
        
        $paymentGatewayMock = $this->createMock(PaymentGatewayInterface::class);
        $paymentGatewayMock->expects($this->once())
                           ->method('updatePaymentLinkToMonthly')
                           ->with('link123', 29.90, 'Test Monthly')
                           ->willReturn(true);

        $service = new PromotionService($customerRepoMock, $paymentGatewayMock, 'link123', 29.90, 'Test Monthly');

        $customer = new Customer('Test', 'test@test.com', '123');
        $customer->setPlan('LIFETIME');
        $customer->markAsPaid('pay_1');

        $service->handlePromotionGoal($customer, $this->logger);

        $this->assertTrue($this->testHandler->hasInfoThatContains('Launch campaign goal reached'));
        $this->assertTrue($this->testHandler->hasInfoThatContains('Asaas payment link successfully converted'));
    }

    public function testHandlePromotionGoalLogsErrorIfConversionFails()
    {
        $customerRepoMock = $this->createMock(CustomerRepositoryInterface::class);
        $customerRepoMock->expects($this->once())->method('countPaidLifetimeCustomers')->willReturn(100);
        
        $paymentGatewayMock = $this->createMock(PaymentGatewayInterface::class);
        $paymentGatewayMock->expects($this->once())->method('updatePaymentLinkToMonthly')->willReturn(false);

        $service = new PromotionService($customerRepoMock, $paymentGatewayMock, 'link123');

        $customer = new Customer('Test', 'test@test.com', '123');
        $customer->setPlan('LIFETIME');
        $customer->markAsPaid('pay_1');

        $service->handlePromotionGoal($customer, $this->logger);

        $this->assertTrue($this->testHandler->hasErrorThatContains('Failed to convert Asaas payment link'));
    }
}
