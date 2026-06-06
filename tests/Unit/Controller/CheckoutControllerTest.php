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
        $this->assertStringContainsString('Parâmetros chrome_identity_id e email são obrigatórios', $response['message']);
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
        $this->assertStringContainsString('Você já possui uma licença ativa', $response['message']);
    }

    public function testHandleRequestNewCustomerPreRegistersAndReturnsCheckoutUrl()
    {
        $this->setRequestParams([
            'chrome_identity_id' => 'chrome_new_999',
            'email' => 'new@example.com',
            'name' => 'New User',
            'phone' => '5511888888888'
        ]);

        $this->customerRepo->expects($this->never())
            ->method('findByChromeIdentityId');

        $this->customerRepo->expects($this->once())
            ->method('findByEmail')
            ->with('new@example.com')
            ->willReturn(null);

        // Expect pre-registration save
        $this->customerRepo->expects($this->once())
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
        $this->assertEquals('pending', $response['status']);
        $this->assertStringContainsString($_ENV['ASAAS_PAYMENT_LINK'], $response['checkoutUrl']);
        $this->assertStringContainsString('email=new%40example.com', $response['checkoutUrl']);
    }
}
