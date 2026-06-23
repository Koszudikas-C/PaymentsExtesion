<?php

namespace Tests\Unit\Controller;

use PHPUnit\Framework\TestCase;
use App\Controllers\CheckoutController;
use App\Interfaces\Repositories\CustomerRepositoryInterface;
use App\Entity\Customer;
use Monolog\Logger;

class CheckoutControllerTest extends TestCase
{
    private $customerRepo;
    private $logger;
    private $settings;

    protected function setUp(): void
    {
        $this->customerRepo = $this->createMock(CustomerRepositoryInterface::class);
        $this->logger = $this->createMock(Logger::class);
        $this->logger->method('error')->willReturnCallback(function($msg) {
            // Do not echo here, it breaks ob_get_clean JSON parsing
            error_log("[LOGGER ERROR]: $msg");
        });
        $this->settings = [
            'asaas' => [
                'payment_link_id_lifetime' => 'lnk_abc123'
            ]
        ];
        $_ENV['ASAAS_PAYMENT_LINK'] = 'https://cobranca.asaas.com/c/';
    }

    /**
     * Helper to mock raw PHP inputs or request parameters
     */
    private function setRequestParams(array $params): void
    {
        $_REQUEST = $params;
    }

    protected function tearDown(): void
    {
        $_REQUEST = [];
        unset($_SERVER['HTTP_X_FORWARDED_FOR']);
        unset($_SERVER['HTTP_CLIENT_IP']);
        unset($_SERVER['REMOTE_ADDR']);
    }

    public function testHandleRequestMissingParamsReturns400()
    {
        $this->setRequestParams([]);

        $controller = new CheckoutController($this->customerRepo, $this->settings, $this->logger);

        ob_start();
        $controller->handleRequest();
        $output = ob_get_clean();

        $response = json_decode($output, true);
        $this->assertEquals('error', $response['status']);
        $this->assertStringContainsString('Parameters chrome_identity_id and email are required', $response['message']);
    }

    public function testHandleRequestActiveLifetimeCustomerReturnsActiveState()
    {
        $this->setRequestParams([
            'chrome_identity_id' => 'chrome_user_123',
            'email' => 'active@example.com'
        ]);

        $activeCustomer = new Customer('Active User', 'active@example.com', '5511999999999');
        $activeCustomer->setChromeIdentityId('chrome_user_123');
        $activeCustomer->markAsPaid('pay_123');
        $activeCustomer->setPlan('LIFETIME');

        $this->customerRepo->expects($this->once())
            ->method('findByEmail')
            ->with('active@example.com')
            ->willReturn($activeCustomer);

        $controller = new CheckoutController($this->customerRepo, $this->settings, $this->logger);

        ob_start();
        $controller->handleRequest();
        $output = ob_get_clean();

        $response = json_decode($output, true);
        $this->assertEquals('active', $response['status']);
        $this->assertEquals('LIFETIME', $response['plan']);
        $this->assertStringContainsString('You already have an active license', $response['message']);
    }

    public function testHandleRequestNewCustomerPreRegistersAndReturnsCheckoutUrl()
    {
        $this->setRequestParams([
            'chrome_identity_id' => 'chrome_new_999',
            'email' => 'new@example.com',
            'name' => 'New User',
            'phone' => '5511888888888'
        ]);

        $this->customerRepo->expects($this->once())
            ->method('findByChromeIdentityId')
            ->willReturn(null);

        $this->customerRepo->expects($this->once())
            ->method('findByEmail')
            ->with('new@example.com')
            ->willReturn(null);

        // Expect pre-registration save
        $this->customerRepo->expects($this->atLeastOnce())
            ->method('save')
            ->with($this->callback(function (Customer $customer) {
                return $customer->getEmail() === 'new@example.com'
                    && $customer->getChromeIdentityId() === 'chrome_new_999'
                    && $customer->getPaymentStatus() === 'PENDING';
            }));

        $controller = new CheckoutController($this->customerRepo, $this->settings, $this->logger);

        ob_start();
        $controller->handleRequest();
        $output = ob_get_clean();

        $response = json_decode($output, true);
        if ($response['status'] === 'error') {
            print_r("\nERROR: " . $response['message'] . "\n");
        }
        $this->assertEquals('pending', $response['status']);
        $this->assertStringContainsString($_ENV['ASAAS_PAYMENT_LINK'], $response['checkoutUrl']);
        $this->assertStringContainsString('email=new%40example.com', $response['checkoutUrl']);
    }

    public function testHandleRequestUpgradeFromMonthlyToLifetime()
    {
        $this->setRequestParams([
            'chrome_identity_id' => 'chrome_user_123',
            'email' => 'upgrade@example.com',
            'plan' => 'LIFETIME'
        ]);

        $monthlyCustomer = new Customer('Upgrade User', 'upgrade@example.com', '5511999999999');
        $monthlyCustomer->setChromeIdentityId('chrome_user_123');
        $monthlyCustomer->markAsPaid('pay_123');
        $monthlyCustomer->setPlan('MONTHLY');
        $monthlyCustomer->setLicenseExpiresAt((new \DateTime())->modify('+10 days'));

        $this->customerRepo->method('findByEmail')->willReturn($monthlyCustomer);

        $controller = new CheckoutController($this->customerRepo, $this->settings, $this->logger);

        ob_start();
        $controller->handleRequest();
        $output = ob_get_clean();

        $response = json_decode($output, true);
        $this->assertEquals('pending', $response['status']);
    }

    public function testHandleRequestCampaignLimitForcesMonthly()
    {
        $this->setRequestParams([
            'chrome_identity_id' => 'chrome_new_999',
            'email' => 'limit@example.com',
            'plan' => 'LIFETIME'
        ]);

        $this->customerRepo->method('findByEmail')->willReturn(null);
        $this->customerRepo->method('countPaidLifetimeCustomers')->willReturn(100);

        $this->settings['campaign']['target'] = 100;
        $this->settings['asaas']['payment_link_id_monthly'] = 'lnk_monthly123';

        $controller = new CheckoutController($this->customerRepo, $this->settings, $this->logger);

        ob_start();
        $controller->handleRequest();
        $output = ob_get_clean();

        $response = json_decode($output, true);
        $this->assertEquals('pending', $response['status']);
        $this->assertStringContainsString('lnk_monthly123', $response['checkoutUrl']);
    }

    public function testHandleRequestInternationalIpUsesStripe()
    {
        $this->setRequestParams([
            'chrome_identity_id' => 'chrome_new_999',
            'email' => 'intl@example.com',
            'plan' => 'LIFETIME'
        ]);

        $_SERVER['HTTP_X_FORWARDED_FOR'] = '8.8.8.8'; // IP Google dos EUA
        $_ENV['STRIPE_PAYMENT_LINK_ID_LIFETIME'] = 'plink_stripe_123';

        $this->customerRepo->method('findByEmail')->willReturn(null);
        $this->customerRepo->method('countPaidLifetimeCustomers')->willReturn(0);

        $controller = new CheckoutController($this->customerRepo, $this->settings, $this->logger);

        ob_start();
        $controller->handleRequest();
        $output = ob_get_clean();

        unset($_SERVER['HTTP_X_FORWARDED_FOR']);
        unset($_ENV['STRIPE_PAYMENT_LINK_ID_LIFETIME']);

        $response = json_decode($output, true);
        $this->assertEquals('pending', $response['status']);
        $this->assertStringContainsString('stripe.com', $response['checkoutUrl']);
    }

    public function testHandleRequestOptions()
    {
        $_SERVER['REQUEST_METHOD'] = 'OPTIONS';
        $controller = new CheckoutController($this->customerRepo, $this->settings, $this->logger);

        ob_start();
        $controller->handleRequest();
        $output = ob_get_clean();

        $this->assertEmpty($output);
        unset($_SERVER['REQUEST_METHOD']);
    }

    public function testHandleRequestExceptionTriggers500()
    {
        $this->setRequestParams([
            'chrome_identity_id' => 'chrome_new_999',
            'email' => 'error@example.com'
        ]);

        $this->customerRepo->method('findByEmail')->willThrowException(new \Exception('DB Error'));

        $controller = new CheckoutController($this->customerRepo, $this->settings, $this->logger);

        ob_start();
        $controller->handleRequest();
        $output = ob_get_clean();

        $response = json_decode($output, true);
        $this->assertEquals('error', $response['status']);
        $this->assertEquals(500, http_response_code());
    }

    public function testHandleRequestMissingAsaasLinkReturns500()
    {
        $this->setRequestParams([
            'chrome_identity_id' => 'chrome_new_999',
            'email' => 'missinglink@example.com'
        ]);

        $this->settings['asaas']['payment_link_id_lifetime'] = null;
        unset($_ENV['ASAAS_PAYMENT_LINK_ID']);

        $controller = new CheckoutController($this->customerRepo, $this->settings, $this->logger);

        ob_start();
        $controller->handleRequest();
        $output = ob_get_clean();

        $response = json_decode($output, true);
        $this->assertEquals('error', $response['status']);
        $this->assertEquals(500, http_response_code());
    }

    public function testHandleRequestStripeFallbackToAsaas()
    {
        $this->setRequestParams([
            'chrome_identity_id' => 'chrome_new_999',
            'email' => 'intl@example.com'
        ]);

        $_SERVER['HTTP_X_FORWARDED_FOR'] = '8.8.8.8';
        // Force Stripe missing
        unset($_ENV['STRIPE_PAYMENT_LINK_ID_LIFETIME']);
        unset($_ENV['STRIPE_PAYMENT_LINK_ID']);

        $controller = new CheckoutController($this->customerRepo, $this->settings, $this->logger);

        ob_start();
        $controller->handleRequest();
        $output = ob_get_clean();

        unset($_SERVER['HTTP_X_FORWARDED_FOR']);

        $response = json_decode($output, true);
        $this->assertEquals('pending', $response['status']);
        $this->assertStringContainsString('cobranca.asaas.com', $response['checkoutUrl']);
    }

    public function testHandleRequestProcessExistingCustomerSamePlanReturnsActive()
    {
        $this->setRequestParams([
            'chrome_identity_id' => 'chrome_user_123',
            'email' => 'monthly@example.com',
            'plan' => 'MONTHLY'
        ]);

        $monthlyCustomer = new Customer('Same Plan User', 'monthly@example.com', '5511999999999');
        $monthlyCustomer->setChromeIdentityId('chrome_user_123');
        $monthlyCustomer->markAsPaid('pay_123');
        $monthlyCustomer->setPlan('MONTHLY');
        $monthlyCustomer->setLicenseExpiresAt((new \DateTime())->modify('+10 days'));

        $this->customerRepo->method('findByEmail')->willReturn($monthlyCustomer);

        $controller = new CheckoutController($this->customerRepo, $this->settings, $this->logger);

        ob_start();
        $controller->handleRequest();
        $output = ob_get_clean();

        $response = json_decode($output, true);
        $this->assertEquals('active', $response['status']);
    }

    public function testHandleRequestStripeWithFullUrl()
    {
        $this->setRequestParams([
            'chrome_identity_id' => 'chrome_new_999',
            'email' => 'intl2@example.com',
            'plan' => 'LIFETIME'
        ]);

        $_SERVER['HTTP_X_FORWARDED_FOR'] = '8.8.8.8';
        $_ENV['STRIPE_PAYMENT_LINK_ID_LIFETIME'] = 'https://buy.stripe.com/full_url_123';

        $this->customerRepo->method('findByEmail')->willReturn(null);

        $controller = new CheckoutController($this->customerRepo, $this->settings, $this->logger);

        ob_start();
        $controller->handleRequest();
        $output = ob_get_clean();

        unset($_SERVER['HTTP_X_FORWARDED_FOR']);
        unset($_ENV['STRIPE_PAYMENT_LINK_ID_LIFETIME']);

        $response = json_decode($output, true);
        $this->assertEquals('pending', $response['status']);
        $this->assertStringContainsString('https://buy.stripe.com/full_url_123', $response['checkoutUrl']);
    }

    public function testHandleRequestAsaasWithFullUrl()
    {
        $this->setRequestParams([
            'chrome_identity_id' => 'chrome_new_999',
            'email' => 'br@example.com',
            'plan' => 'LIFETIME'
        ]);

        $_SERVER['HTTP_X_FORWARDED_FOR'] = '192.168.0.1'; // BR fallback
        $this->settings['asaas']['payment_link_id_lifetime'] = 'https://custom.asaas.com/pay';

        $this->customerRepo->method('findByEmail')->willReturn(null);

        $controller = new CheckoutController($this->customerRepo, $this->settings, $this->logger);

        ob_start();
        $controller->handleRequest();
        $output = ob_get_clean();

        unset($_SERVER['HTTP_X_FORWARDED_FOR']);

        $response = json_decode($output, true);
        $this->assertEquals('pending', $response['status']);
        $this->assertStringContainsString('https://custom.asaas.com/pay', $response['checkoutUrl']);
    }
}
