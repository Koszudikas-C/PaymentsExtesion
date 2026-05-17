<?php

namespace Tests\Unit\Controller;

use PHPUnit\Framework\TestCase;
use App\Controllers\ActivationController;
use App\Interfaces\Repositories\CustomerRepositoryInterface;
use App\Entity\Customer;
use Monolog\Logger;

class ActivationControllerTest extends TestCase
{
    private $customerRepo;
    private $logger;

    protected function setUp(): void
    {
        $this->customerRepo = $this->createMock(CustomerRepositoryInterface::class);
        $this->logger = $this->createMock(Logger::class);
        $_ENV['CHROME_EXTENSION_ID'] = 'gpjjhlfkdaakcpllhfobnmdjnbgpefgc';
    }

    private function setRequestParams(array $params): void
    {
        $_REQUEST = $params;
    }

    protected function tearDown(): void
    {
        $_REQUEST = [];
        unset($_ENV['CHROME_EXTENSION_ID']);
    }

    public function testHandleRequestMissingParamsReturns400()
    {
        $this->setRequestParams([]);

        $controller = new ActivationController($this->customerRepo, $this->logger);

        ob_start();
        $controller->handleRequest();
        $output = ob_get_clean();

        $response = json_decode($output, true);
        $this->assertEquals('error', $response['status']);
        $this->assertStringContainsString('Parâmetros chrome_identity_id, email e extension_id são obrigatórios', $response['message']);
    }

    public function testHandleRequestInvalidExtensionIdReturns403()
    {
        $this->setRequestParams([
            'chrome_identity_id' => 'chrome_user_123',
            'email' => 'user@example.com',
            'extension_id' => 'wrongextensionidhere'
        ]);

        $controller = new ActivationController($this->customerRepo, $this->logger);

        ob_start();
        $controller->handleRequest();
        $output = ob_get_clean();

        $response = json_decode($output, true);
        $this->assertEquals('error', $response['status']);
        $this->assertStringContainsString('Acesso não autorizado para esta extensão', $response['message']);
    }

    public function testHandleRequestCustomerNotFoundReturns200WithNotFound()
    {
        $this->setRequestParams([
            'chrome_identity_id' => 'chrome_user_123',
            'email' => 'notfound@example.com',
            'extension_id' => 'gpjjhlfkdaakcpllhfobnmdjnbgpefgc'
        ]);

        $this->customerRepo->expects($this->once())
            ->method('findByChromeIdentityId')
            ->with('chrome_user_123')
            ->willReturn(null);

        $this->customerRepo->expects($this->once())
            ->method('findByEmail')
            ->with('notfound@example.com')
            ->willReturn(null);

        $controller = new ActivationController($this->customerRepo, $this->logger);

        ob_start();
        $controller->handleRequest();
        $output = ob_get_clean();

        $response = json_decode($output, true);
        $this->assertEquals('not_found', $response['status']);
        $this->assertStringContainsString('Nenhum cadastro ou licença ativa encontrada', $response['message']);
    }

    public function testHandleRequestActiveCustomerReturnsSuccessAndLinksChromeId()
    {
        $this->setRequestParams([
            'chrome_identity_id' => 'chrome_user_123',
            'email' => 'active@example.com',
            'extension_id' => 'gpjjhlfkdaakcpllhfobnmdjnbgpefgc'
        ]);

        $activeCustomer = new Customer('Active User', 'active@example.com', '5511999999999');
        $activeCustomer->markAsPaid('pay_999');
        $activeCustomer->assignLicense('lic_key_abc');
        $activeCustomer->setPlan('LIFETIME');

        // Initially has no chromeIdentityId linked
        $this->assertNull($activeCustomer->getChromeIdentityId());

        $this->customerRepo->expects($this->once())
            ->method('findByChromeIdentityId')
            ->with('chrome_user_123')
            ->willReturn(null);

        $this->customerRepo->expects($this->once())
            ->method('findByEmail')
            ->with('active@example.com')
            ->willReturn($activeCustomer);

        $this->customerRepo->expects($this->once())
            ->method('save')
            ->with($this->callback(function (Customer $customer) {
                return $customer->getChromeIdentityId() === 'chrome_user_123';
            }));

        $controller = new ActivationController($this->customerRepo, $this->logger);

        ob_start();
        $controller->handleRequest();
        $output = ob_get_clean();

        $response = json_decode($output, true);
        $this->assertEquals('success', $response['status']);
        $this->assertEquals('lic_key_abc', $response['licenseKey']);
        $this->assertEquals('LIFETIME', $response['plan']);
        $this->assertStringContainsString('Extensão ativada com sucesso', $response['message']);
    }

    public function testHandleRequestInactiveCustomerReturnsInactive()
    {
        $this->setRequestParams([
            'chrome_identity_id' => 'chrome_user_123',
            'email' => 'inactive@example.com',
            'extension_id' => 'gpjjhlfkdaakcpllhfobnmdjnbgpefgc'
        ]);

        $inactiveCustomer = new Customer('Inactive User', 'inactive@example.com', '5511999999999');
        // Payment is PENDING, so license is inactive

        $this->customerRepo->expects($this->once())
            ->method('findByChromeIdentityId')
            ->with('chrome_user_123')
            ->willReturn($inactiveCustomer);

        $controller = new ActivationController($this->customerRepo, $this->logger);

        ob_start();
        $controller->handleRequest();
        $output = ob_get_clean();

        $response = json_decode($output, true);
        $this->assertEquals('inactive', $response['status']);
        $this->assertStringContainsString('A sua licença está inativa ou expirada', $response['message']);
    }
}
