<?php

namespace Tests\Unit\Handlers\Processors;

use App\Entity\Customer;
use App\Handlers\Processors\StripeCheckoutCompletedProcessor;
use App\Interfaces\EmailServiceInterface;
use App\Interfaces\LandingPageSyncServiceInterface;
use App\Interfaces\LicenseServiceInterface;
use App\Interfaces\PromotionServiceInterface;
use App\Interfaces\Repositories\AuditLogRepositoryInterface;
use App\Interfaces\Repositories\CustomerRepositoryInterface;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Stripe\Event;

class StripeCheckoutCompletedProcessorTest extends TestCase
{
    private EmailServiceInterface|MockObject $emailService;
    private LicenseServiceInterface|MockObject $licenseService;
    private CustomerRepositoryInterface|MockObject $customerRepo;
    private AuditLogRepositoryInterface|MockObject $auditRepo;
    private Logger|MockObject $logger;
    private PromotionServiceInterface|MockObject $promotionService;
    private LandingPageSyncServiceInterface|MockObject $syncService;
    private StripeCheckoutCompletedProcessor $processor;

    protected function setUp(): void
    {
        $this->emailService = $this->createMock(EmailServiceInterface::class);
        $this->licenseService = $this->createMock(LicenseServiceInterface::class);
        $this->customerRepo = $this->createMock(CustomerRepositoryInterface::class);
        $this->auditRepo = $this->createMock(AuditLogRepositoryInterface::class);
        $this->logger = $this->createMock(Logger::class);
        $this->promotionService = $this->createMock(PromotionServiceInterface::class);
        $this->syncService = $this->createMock(LandingPageSyncServiceInterface::class);

        $this->processor = new StripeCheckoutCompletedProcessor(
            $this->emailService,
            $this->licenseService,
            $this->customerRepo,
            $this->auditRepo,
            $this->logger,
            'salt',
            $this->promotionService,
            $this->syncService
        );

        $_ENV['MONTHLY_VALUE_USD'] = 5.99;
    }

    private function createEventMock($amount, $email = 'test@example.com', $status = 'paid', $subId = null)
    {
        $session = [
            'id' => 'sess_123',
            'payment_intent' => 'pi_123',
            'payment_status' => $status,
            'amount_total' => $amount,
            'customer_details' => ['email' => $email, 'name' => 'User', 'phone' => '123'],
        ];
        if ($subId) {
            $session['subscription'] = $subId;
        }

        return Event::constructFrom([
            'type' => 'checkout.session.completed',
            'data' => [
                'object' => $session
            ]
        ]);
    }

    public function testProcessIgnoresUnpaid()
    {
        $event = $this->createEventMock(1000, 'test@example.com', 'unpaid');
        $this->logger->expects($this->once())->method('info')->with($this->stringContains('not paid'));
        $this->processor->process($event);
    }

    public function testProcessMissingEmail()
    {
        $event = $this->createEventMock(1000, '');
        $this->logger->expects($this->once())->method('error')->with($this->stringContains('missing customer email'));
        $this->processor->process($event);
    }

    public function testProcessAlreadyProcessed()
    {
        $event = $this->createEventMock(1000);
        $this->auditRepo->method('hasPaymentBeenProcessed')->willReturn(true);
        $this->logger->expects($this->once())->method('info')->with($this->stringContains('already processed'));
        $this->processor->process($event);
    }

    public function testProcessNewLifetimeCustomer()
    {
        $event = $this->createEventMock(4999); // 49.99 LIFETIME
        $this->auditRepo->method('hasPaymentBeenProcessed')->willReturn(false);
        $this->customerRepo->method('findByEmail')->willReturn(null);

        $this->licenseService->method('generateLicense')->willReturn('NEW-LIC-123');
        $this->emailService->expects($this->once())->method('sendLicenseEmail')->willReturn(true);

        $this->customerRepo->expects($this->once())->method('save')->with($this->callback(function (Customer $customer) {
            return $customer->getEmail() === 'test@example.com' &&
                $customer->getPlan() === 'LIFETIME' &&
                $customer->getLicenseKey() === 'NEW-LIC-123';
        }));

        $this->processor->process($event);
    }

    public function testProcessNewMonthlyCustomer()
    {
        $event = $this->createEventMock(599, 'test@example.com', 'paid', 'sub_123'); // 5.99 CO-CREATOR
        $this->auditRepo->method('hasPaymentBeenProcessed')->willReturn(false);
        $this->customerRepo->method('findBySubscriptionId')->willReturn(null);
        $this->customerRepo->method('findByEmail')->willReturn(null);

        $this->licenseService->method('generateLicense')->willReturn('NEW-LIC-SUB');

        $this->customerRepo->expects($this->once())->method('save')->with($this->callback(function (Customer $customer) {
            return $customer->getPlan() === 'CO-CREATOR';
        }));

        $this->processor->process($event);
    }



    public function testProcessDatabaseFailureOnLookup()
    {
        $event = Event::constructFrom([
            'type' => 'checkout.session.completed',
            'data' => [
                'object' => [
                    'id' => 'cs_dbfail',
                    'payment_status' => 'paid',
                    'amount_total' => 9999,
                    'customer_details' => [
                        'email' => 'fail@example.com'
                    ]
                ]
            ]
        ]);

        $this->auditRepo->method('hasPaymentBeenProcessed')->willReturn(false);
        $this->customerRepo->method('findByEmail')->willThrowException(new \Exception('DB Offline'));

        $this->licenseService->method('generateLicense')->willReturn('lic_fallback');

        // It shouldn't save to DB on dbFailed
        $this->customerRepo->expects($this->never())->method('save');
        // Emails should still be sent if new
        $this->emailService->expects($this->once())->method('sendLicenseEmail')->willReturn(true);

        $this->processor->process($event);
    }

    public function testProcessDatabaseFailureOnSaveIsCaught()
    {
        $event = Event::constructFrom([
            'type' => 'checkout.session.completed',
            'data' => [
                'object' => [
                    'id' => 'cs_save_fail',
                    'payment_status' => 'paid',
                    'amount_total' => 9999,
                    'customer_details' => [
                        'email' => 'savefail@example.com'
                    ]
                ]
            ]
        ]);

        $this->auditRepo->method('hasPaymentBeenProcessed')->willReturn(false);
        $this->customerRepo->method('findByEmail')->willReturn(null);
        $this->licenseService->method('generateLicense')->willReturn('lic_123');
        $this->emailService->method('sendLicenseEmail')->willReturn(true);

        $this->customerRepo->method('save')->willThrowException(new \Exception('DB Save Offline'));

        // Shouldn't crash
        $this->processor->process($event);
        $this->assertTrue(true); // Reached end without fatal error
    }

    public function testProcessCoCreatorStripeAmount()
    {
        $event = Event::constructFrom([
            'type' => 'checkout.session.completed',
            'data' => [
                'object' => [
                    'id' => 'cs_coc',
                    'payment_status' => 'paid',
                    'amount_total' => 599, // 5.99 USD => CO-CREATOR
                    'customer_details' => [
                        'email' => 'cocreator@example.com'
                    ]
                ]
            ]
        ]);

        $this->auditRepo->method('hasPaymentBeenProcessed')->willReturn(false);

        $customer = new Customer('Test User', 'cocreator@example.com', '123');
        $customer->setPlan('LIFETIME'); // Should allow transition and fallback
        $this->customerRepo->method('findByEmail')->willReturn($customer);

        $this->customerRepo->expects($this->once())
            ->method('save')
            ->with($this->callback(function (Customer $c) {
                return $c->getPlan() === 'CO-CREATOR' && $c->getFallbackPlan() === 'LIFETIME';
            }));

        $this->processor->process($event);
    }

    public function testProcessCatchesAuditRepoException()
    {
        $event = $this->createEventMock(1000);
        $this->auditRepo->method('hasPaymentBeenProcessed')->willThrowException(new \Exception('Audit Error'));
        $this->customerRepo->method('findByEmail')->willReturn(null);

        // It shouldn't crash
        $this->processor->process($event);
        $this->assertTrue(true);
    }

    public function testProcessDoublePaymentLifetime()
    {
        $event = $this->createEventMock(4999);
        $this->auditRepo->method('hasPaymentBeenProcessed')->willReturn(false);

        $customer = new Customer('Test', 'test@example.com', '123');
        $customer->setPlan('LIFETIME');
        $customer->markAsPaid('old_pay');

        $this->customerRepo->method('findByEmail')->willReturn($customer);

        // It should detect double payment and just return after save
        $this->customerRepo->expects($this->once())->method('save');
        $this->emailService->expects($this->never())->method('sendLicenseEmail');

        $this->processor->process($event);
    }

    public function testProcessMonthlyRenewal()
    {
        $event = $this->createEventMock(1000, 'test@example.com', 'paid', 'sub_123');
        $this->auditRepo->method('hasPaymentBeenProcessed')->willReturn(false);

        $customer = new Customer('Test', 'test@example.com', '123');
        $customer->setPlan('MONTHLY');
        $customer->setSubscriptionId('sub_123');
        $customer->markAsPaid('old_pay');
        $customer->setLicenseExpiresAt(new \DateTime('now'));

        $this->customerRepo->method('findBySubscriptionId')->willReturn($customer);

        // Should extend date
        $this->customerRepo->expects($this->once())->method('save');

        $this->processor->process($event);
    }

    public function testProcessHandlesNotifyAndPromoExceptions()
    {
        $event = $this->createEventMock(4999);
        $this->auditRepo->method('hasPaymentBeenProcessed')->willReturn(false);
        $this->customerRepo->method('findByEmail')->willReturn(null);

        $this->syncService->method('notifySale')->willThrowException(new \Exception('Sync fail'));
        $this->promotionService->method('handlePromotionGoal')->willThrowException(new \Exception('Promo fail'));

        // Should catch and not crash
        $this->processor->process($event);
        $this->assertTrue(true);
    }

    public function testProcessAuditDbExceptionInStripe()
    {
        $event = $this->createEventMock(1000);
        $this->auditRepo->method('hasPaymentBeenProcessed')->willThrowException(new \Exception('DB Error'));

        $this->processor->process($event);
        $this->assertTrue(true);
    }

    public function testProcessOverallExceptionInStripe()
    {
        $event = $this->createEventMock(1000);
        $this->auditRepo->method('hasPaymentBeenProcessed')->willReturn(false);
        $this->customerRepo->method('findByEmail')->willThrowException(new \Exception('Fatal Error in Repo'));

        $this->processor->process($event);
        $this->assertTrue(true);
    }

    public function testProcessSendLicenseEmailFailsInStripe()
    {
        $event = $this->createEventMock(4999);
        $this->auditRepo->method('hasPaymentBeenProcessed')->willReturn(false);
        $this->customerRepo->method('findByEmail')->willReturn(null);

        $this->licenseService->method('generateLicense')->willReturn('NEW-LIC-123');
        $this->emailService->method('sendLicenseEmail')->willReturn(false);

        $this->customerRepo->expects($this->once())->method('save')->with($this->callback(function (Customer $c) {
            return $c->isLicenseDelivered() === false;
        }));

        $this->processor->process($event);
    }

    public function testResolveTargetPlanFallbackEnv()
    {
        unset($_ENV['MONTHLY_VALUE_USD']);
        // 5.99 USD is 599 cents. Since no ENV, it defaults to 5.99 in resolveTargetPlan.
        $event = $this->createEventMock(599, 'test@example.com', 'paid');
        $this->auditRepo->method('hasPaymentBeenProcessed')->willReturn(false);
        $this->customerRepo->method('findByEmail')->willReturn(null);

        $this->customerRepo->expects($this->once())->method('save')->with($this->callback(function (Customer $c) {
            return $c->getPlan() === 'CO-CREATOR';
        }));

        $this->processor->process($event);
        $_ENV['MONTHLY_VALUE_USD'] = 5.99; // Restore
    }

    public function testProcessDoublePaymentLifetimeUserBuyingLifetimeAgainInStripe()
    {
        $event = $this->createEventMock(4999);
        $this->auditRepo->method('hasPaymentBeenProcessed')->willReturn(false);

        $customer = new Customer('Test', 'a@b.c', '123');
        $customer->setPlan('LIFETIME');
        $customer->markAsPaid('old_pay');

        $this->customerRepo->method('findByEmail')->willReturn($customer);

        $this->customerRepo->expects($this->once())->method('save');

        $this->processor->process($event);
    }
}
