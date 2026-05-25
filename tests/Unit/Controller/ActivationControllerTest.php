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
        $this->assertStringContainsString('Parâmetros chrome_identity_id, email, extension_id e license_key são obrigatórios', $response['message']);
    }

    public function testHandleRequestInvalidExtensionIdReturns403()
    {
        $this->setRequestParams([
            'chrome_identity_id' => 'chrome_user_123',
            'email' => 'user@example.com',
            'extension_id' => 'wrongextensionidhere',
            'license_key' => 'lic_abc123'
        ]);

        $controller = new ActivationController($this->customerRepo, $this->logger);

        ob_start();
        $controller->handleRequest();
        $output = ob_get_clean();

        $response = json_decode($output, true);
        $this->assertEquals('error', $response['status']);
        $this->assertStringContainsString('Acesso não autorizado para esta extensão', $response['message']);
    }

    public function testHandleRequestCustomerNotFoundReturns200WithInvalidKey()
    {
        $this->setRequestParams([
            'chrome_identity_id' => 'chrome_user_123',
            'email' => 'notfound@example.com',
            'extension_id' => 'gpjjhlfkdaakcpllhfobnmdjnbgpefgc',
            'license_key' => 'lic_abc123'
        ]);

        $this->customerRepo->expects($this->once())
            ->method('findByLicenseKey')
            ->with('lic_abc123')
            ->willReturn(null);

        $controller = new ActivationController($this->customerRepo, $this->logger);

        ob_start();
        $controller->handleRequest();
        $output = ob_get_clean();

        $response = json_decode($output, true);
        $this->assertEquals('invalid_key', $response['status']);
        $this->assertStringContainsString('Chave de licença ou e-mail inválido', $response['message']);
    }

    public function testHandleRequestInvalidLicenseKeyReturns200WithInvalidKey()
    {
        $this->setRequestParams([
            'chrome_identity_id' => 'chrome_user_123',
            'email' => 'active@example.com',
            'extension_id' => 'gpjjhlfkdaakcpllhfobnmdjnbgpefgc',
            'license_key' => 'wrong_lic_key'
        ]);

        // Retorna null pois a chave de licença é inválida/não encontrada
        $this->customerRepo->expects($this->once())
            ->method('findByLicenseKey')
            ->with('wrong_lic_key')
            ->willReturn(null);

        $controller = new ActivationController($this->customerRepo, $this->logger);

        ob_start();
        $controller->handleRequest();
        $output = ob_get_clean();

        $response = json_decode($output, true);
        $this->assertEquals('invalid_key', $response['status']);
        $this->assertStringContainsString('Chave de licença ou e-mail inválido', $response['message']);
    }

    public function testHandleRequestActiveCustomerReturnsSuccessAndLinksChromeId()
    {
        $this->setRequestParams([
            'chrome_identity_id' => 'chrome_user_123',
            'email' => 'active@example.com',
            'extension_id' => 'gpjjhlfkdaakcpllhfobnmdjnbgpefgc',
            'license_key' => 'lic_key_abc'
        ]);

        $activeCustomer = new Customer('Active User', 'active@example.com', '5511999999999');
        $activeCustomer->markAsPaid('pay_999');
        $activeCustomer->assignLicense('lic_key_abc');
        $activeCustomer->setPlan('LIFETIME');

        // Initially has no chromeIdentityId linked
        $this->assertNull($activeCustomer->getChromeIdentityId());

        $this->customerRepo->expects($this->once())
            ->method('findByLicenseKey')
            ->with('lic_key_abc')
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

    public function testHandleRequestActiveCustomerDeviceConflictReturnsConflict()
    {
        $this->setRequestParams([
            'chrome_identity_id' => 'chrome_another_device',
            'email' => 'active@example.com',
            'extension_id' => 'gpjjhlfkdaakcpllhfobnmdjnbgpefgc',
            'license_key' => 'lic_key_abc'
        ]);

        $activeCustomer = new Customer('Active User', 'active@example.com', '5511999999999');
        $activeCustomer->markAsPaid('pay_999');
        $activeCustomer->assignLicense('lic_key_abc');
        $activeCustomer->setChromeIdentityId('chrome_user_123'); // Already bound to another profile

        $this->customerRepo->expects($this->once())
            ->method('findByLicenseKey')
            ->with('lic_key_abc')
            ->willReturn($activeCustomer);

        $controller = new ActivationController($this->customerRepo, $this->logger);

        ob_start();
        $controller->handleRequest();
        $output = ob_get_clean();

        $response = json_decode($output, true);
        $this->assertEquals('conflict', $response['status']);
        $this->assertTrue($response['can_force']);
        $this->assertStringContainsString('Esta licença já está ativada em outro perfil', $response['message']);
    }

    public function testHandleRequestActiveCustomerForceResetOverwritesChromeId()
    {
        $this->setRequestParams([
            'chrome_identity_id' => 'chrome_another_device',
            'email' => 'active@example.com',
            'extension_id' => 'gpjjhlfkdaakcpllhfobnmdjnbgpefgc',
            'license_key' => 'lic_key_abc',
            'force' => true
        ]);

        $activeCustomer = new Customer('Active User', 'active@example.com', '5511999999999');
        $activeCustomer->markAsPaid('pay_999');
        $activeCustomer->assignLicense('lic_key_abc');
        $activeCustomer->setChromeIdentityId('chrome_user_123'); // Already bound to another profile

        $this->customerRepo->expects($this->once())
            ->method('findByLicenseKey')
            ->with('lic_key_abc')
            ->willReturn($activeCustomer);

        $this->customerRepo->expects($this->once())
            ->method('save')
            ->with($this->callback(function (Customer $customer) {
                return $customer->getChromeIdentityId() === 'chrome_another_device';
            }));

        $controller = new ActivationController($this->customerRepo, $this->logger);

        ob_start();
        $controller->handleRequest();
        $output = ob_get_clean();

        $response = json_decode($output, true);
        $this->assertEquals('success', $response['status']);
        $this->assertEquals('chrome_another_device', $activeCustomer->getChromeIdentityId());
    }

    public function testHandleRequestInactiveCustomerReturnsInactive()
    {
        $this->setRequestParams([
            'chrome_identity_id' => 'chrome_user_123',
            'email' => 'inactive@example.com',
            'extension_id' => 'gpjjhlfkdaakcpllhfobnmdjnbgpefgc',
            'license_key' => 'lic_key_abc'
        ]);

        $inactiveCustomer = new Customer('Inactive User', 'inactive@example.com', '5511999999999');
        $inactiveCustomer->assignLicense('lic_key_abc');
        // Payment is PENDING, so license is inactive

        $this->customerRepo->expects($this->once())
            ->method('findByLicenseKey')
            ->with('lic_key_abc')
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
