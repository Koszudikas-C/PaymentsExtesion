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
    private $authTokenService;
    private $logger;

    protected function setUp(): void
    {
        $this->customerRepo = $this->createMock(CustomerRepositoryInterface::class);
        $this->authTokenService = $this->createMock(\App\Interfaces\Services\AuthTokenServiceInterface::class);
        $this->authTokenService->method('generateAccessToken')->willReturn('fake_access_token');
        $this->authTokenService->method('generateRefreshToken')->willReturn('fake_refresh_token');
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

        $controller = new ActivationController($this->customerRepo, $this->authTokenService, $this->logger);

        ob_start();
        $controller->handleRequest();
        $output = ob_get_clean();

        $response = json_decode($output, true);
        $this->assertEquals('error', $response['status']);
        $this->assertStringContainsString('Parameters chrome_identity_id, email, extension_id, and license_key are required.', $response['message']);
    }

    public function testHandleRequestWithInvalidEmailIsSanitizedToNull()
    {
        $this->setRequestParams([
            'email' => 'invalid-email',
            'license_key' => 'LIC-123',
            'chrome_identity_id' => 'c1',
            'extension_id' => 'gpjjhlfkdaakcpllhfobnmdjnbgpefgc'
        ]);

        $this->customerRepo->method('findByLicenseKey')->willReturn(null);

        $controller = new ActivationController($this->customerRepo, $this->authTokenService, $this->logger, 'salt');

        ob_start();
        $controller->handleRequest();
        $output = ob_get_clean();

        $response = json_decode($output, true);
        $this->assertEquals('error', $response['status']);
        $this->assertStringContainsString('Parameters chrome_identity_id, email, extension_id, and license_key are required.', $response['message']);
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

        $controller = new ActivationController($this->customerRepo, $this->authTokenService, $this->logger);

        ob_start();
        $controller->handleRequest();
        $output = ob_get_clean();

        $response = json_decode($output, true);
        $this->assertEquals('invalid_key', $response['status']);
        $this->assertStringContainsString('Invalid license key or email.', $response['message']);
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

        $controller = new ActivationController($this->customerRepo, $this->authTokenService, $this->logger);

        ob_start();
        $controller->handleRequest();
        $output = ob_get_clean();

        $response = json_decode($output, true);
        $this->assertEquals('invalid_key', $response['status']);
        $this->assertStringContainsString('Invalid license key or email.', $response['message']);
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

        $this->customerRepo->expects($this->atLeastOnce())
            ->method('save')
            ->with($this->callback(function (Customer $customer) {
                return $customer->getChromeIdentityId() === 'chrome_user_123';
            }));

        $controller = new ActivationController($this->customerRepo, $this->authTokenService, $this->logger);

        ob_start();
        $controller->handleRequest();
        $output = ob_get_clean();

        $response = json_decode($output, true);
        if ($response['status'] !== 'success') { var_dump($response); }
        $this->assertEquals('success', $response['status']);
        $this->assertEquals('lic_key_abc', $response['licenseKey']);
        $this->assertEquals('LIFETIME', $response['plan']);
        $this->assertStringContainsString('Extension successfully activated!', $response['message']);
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
        $activeCustomer->setPlan('LIFETIME');
        $activeCustomer->assignLicense('lic_key_abc');
        $activeCustomer->setChromeIdentityId('chrome_user_123'); // Already bound to another profile

        $this->customerRepo->expects($this->once())
            ->method('findByLicenseKey')
            ->with('lic_key_abc')
            ->willReturn($activeCustomer);

        $controller = new ActivationController($this->customerRepo, $this->authTokenService, $this->logger);

        ob_start();
        $controller->handleRequest();
        $output = ob_get_clean();

        $response = json_decode($output, true);
        $this->assertEquals('conflict', $response['status']);
        $this->assertTrue($response['can_force']);
        $this->assertStringContainsString('This license is already activated on another Chrome profile or device. Do you want to transfer the activation to this profile?', $response['message']);
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
        $activeCustomer->setPlan('LIFETIME');
        $activeCustomer->assignLicense('lic_key_abc');
        $activeCustomer->setChromeIdentityId('chrome_user_123'); // Already bound to another profile

        $this->customerRepo->expects($this->once())
            ->method('findByLicenseKey')
            ->with('lic_key_abc')
            ->willReturn($activeCustomer);

        $this->customerRepo->expects($this->atLeastOnce())
            ->method('save')
            ->with($this->callback(function (Customer $customer) {
                return $customer->getChromeIdentityId() === 'chrome_another_device';
            }));

        $controller = new ActivationController($this->customerRepo, $this->authTokenService, $this->logger);

        ob_start();
        $controller->handleRequest();
        $output = ob_get_clean();

        $response = json_decode($output, true);
        if ($response['status'] !== 'success') { var_dump($response); }
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

        $controller = new ActivationController($this->customerRepo, $this->authTokenService, $this->logger);

        ob_start();
        $controller->handleRequest();
        $output = ob_get_clean();

        $response = json_decode($output, true);
        $this->assertEquals('inactive', $response['status']);
        $this->assertStringContainsString('Your license is inactive or expired. Please make a payment to activate.', $response['message']);
    }

    public function testHandleRequestActiveCustomerUnbindsExistingChromeIdFromOtherCustomer()
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

        $otherCustomer = new Customer('Other User', 'other@example.com', '999999999');
        $otherCustomer->setChromeIdentityId('chrome_user_123');

        $this->customerRepo->expects($this->once())
            ->method('findByLicenseKey')
            ->with('lic_key_abc')
            ->willReturn($activeCustomer);

        // Retorna o outro cliente já associado ao mesmo chromeIdentityId
        $this->customerRepo->expects($this->once())
            ->method('findByChromeIdentityId')
            ->with('chrome_user_123')
            ->willReturn($otherCustomer);

        // Espera no mínimo duas chamadas
        $this->customerRepo->expects($this->atLeastOnce())
            ->method('save')
            ->with($this->callback(function (Customer $customer) use ($activeCustomer, $otherCustomer) {
                if ($customer->getId() === $otherCustomer->getId()) {
                    return $customer->getChromeIdentityId() === null;
                }
                if ($customer->getId() === $activeCustomer->getId()) {
                    return $customer->getChromeIdentityId() === 'chrome_user_123';
                }
                return false;
            }));

        $controller = new ActivationController($this->customerRepo, $this->authTokenService, $this->logger);

        ob_start();
        $controller->handleRequest();
        $output = ob_get_clean();

        $response = json_decode($output, true);
        if ($response['status'] !== 'success') { var_dump($response); }
        $this->assertEquals('success', $response['status']);
        $this->assertEquals('chrome_user_123', $activeCustomer->getChromeIdentityId());
        $this->assertNull($otherCustomer->getChromeIdentityId());
    }

    public function testHandleRequestOptions()
    {
        $_SERVER['REQUEST_METHOD'] = 'OPTIONS';
        $controller = new ActivationController($this->customerRepo, $this->authTokenService, $this->logger);

        ob_start();
        $controller->handleRequest();
        $output = ob_get_clean();

        $this->assertEmpty($output);
        unset($_SERVER['REQUEST_METHOD']);
    }

    public function testHandleRequestExceptionTriggers500()
    {
        $this->setRequestParams([
            'chrome_identity_id' => 'chrome_user_123',
            'email' => 'active@example.com',
            'extension_id' => 'gpjjhlfkdaakcpllhfobnmdjnbgpefgc',
            'license_key' => 'lic_key_abc'
        ]);

        $this->customerRepo->method('findByLicenseKey')->willThrowException(new \Exception('DB Error'));

        $controller = new ActivationController($this->customerRepo, $this->authTokenService, $this->logger);

        ob_start();
        $controller->handleRequest();
        $output = ob_get_clean();

        $response = json_decode($output, true);
        $this->assertEquals('error', $response['status']);
        $this->assertEquals(500, http_response_code());
    }

    public function testHandleRequestInputFallbackFromPhpInput()
    {
        $this->setRequestParams([]);

        @stream_wrapper_unregister('php');
        stream_wrapper_register('php', \Tests\Unit\Helpers\PhpStreamWrapperMock::class);
        \Tests\Unit\Helpers\PhpStreamWrapperMock::$buffer = json_encode([
            'chrome_identity_id' => 'json_user',
            'email' => 'json@example.com',
            'extension_id' => 'gpjjhlfkdaakcpllhfobnmdjnbgpefgc',
            'license_key' => 'json_key'
        ]);

        $activeCustomer = new Customer('JSON User', 'json@example.com', '1234');
        $activeCustomer->markAsPaid('pay_123');
        $activeCustomer->setPlan('LIFETIME');
        $activeCustomer->assignLicense('json_key');

        $this->customerRepo->method('findByLicenseKey')->willReturn($activeCustomer);

        $controller = new ActivationController($this->customerRepo, $this->authTokenService, $this->logger);

        ob_start();
        $controller->handleRequest();
        $output = ob_get_clean();

        @stream_wrapper_restore('php');

        $response = json_decode($output, true);
        $this->assertEquals('success', $response['status']);
        $this->assertEquals('json_user', $activeCustomer->getChromeIdentityId());
    }
}
