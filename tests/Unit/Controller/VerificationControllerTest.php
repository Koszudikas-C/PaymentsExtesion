<?php

namespace Tests\Unit\Controller;

use PHPUnit\Framework\TestCase;
use App\Controllers\VerificationController;
use App\Interfaces\Repositories\CustomerRepositoryInterface;
use App\Entity\Customer;
use Monolog\Logger;

class VerificationControllerTest extends TestCase
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

        $controller = new VerificationController($this->customerRepo, $this->logger);

        ob_start();
        $controller->handleRequest();
        $output = ob_get_clean();

        $response = json_decode($output, true);
        $this->assertEquals('error', $response['status']);
        $this->assertStringContainsString('Parâmetros chrome_identity_id e extension_id são obrigatórios', $response['message']);
    }

    public function testHandleRequestInvalidExtensionIdReturns403()
    {
        $this->setRequestParams([
            'chrome_identity_id' => 'chrome_user_123',
            'extension_id' => 'wrongextensionidhere'
        ]);

        $controller = new VerificationController($this->customerRepo, $this->logger);

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
            'extension_id' => 'gpjjhlfkdaakcpllhfobnmdjnbgpefgc'
        ]);

        $this->customerRepo->expects($this->once())
            ->method('findByChromeIdentityId')
            ->with('chrome_user_123')
            ->willReturn(null);

        $controller = new VerificationController($this->customerRepo, $this->logger);

        ob_start();
        $controller->handleRequest();
        $output = ob_get_clean();

        $response = json_decode($output, true);
        $this->assertEquals('not_found', $response['status']);
        $this->assertStringContainsString('Nenhuma licença ativa vinculada', $response['message']);
    }

    public function testHandleRequestActiveCustomerReturnsActive()
    {
        $this->setRequestParams([
            'chrome_identity_id' => 'chrome_user_123',
            'extension_id' => 'gpjjhlfkdaakcpllhfobnmdjnbgpefgc'
        ]);

        $activeCustomer = new Customer('Active User', 'active@example.com', '5511999999999');
        $activeCustomer->markAsPaid('pay_999');
        $activeCustomer->assignLicense('lic_key_abc');
        $activeCustomer->setChromeIdentityId('chrome_user_123');
        $activeCustomer->setPlan('LIFETIME');

        $this->customerRepo->expects($this->once())
            ->method('findByChromeIdentityId')
            ->with('chrome_user_123')
            ->willReturn($activeCustomer);

        $controller = new VerificationController($this->customerRepo, $this->logger);

        ob_start();
        $controller->handleRequest();
        $output = ob_get_clean();

        $response = json_decode($output, true);
        $this->assertEquals('active', $response['status']);
        $this->assertEquals('LIFETIME', $response['plan']);
        $this->assertNull($response['expiresAt']);
        $this->assertStringContainsString('Licença ativa e válida', $response['message']);
    }

    public function testHandleRequestInactiveCustomerReturnsInactive()
    {
        $this->setRequestParams([
            'chrome_identity_id' => 'chrome_user_123',
            'extension_id' => 'gpjjhlfkdaakcpllhfobnmdjnbgpefgc'
        ]);

        $inactiveCustomer = new Customer('Inactive User', 'inactive@example.com', '5511999999999');
        $inactiveCustomer->setChromeIdentityId('chrome_user_123');
        // Payment is PENDING, so license is inactive

        $this->customerRepo->expects($this->once())
            ->method('findByChromeIdentityId')
            ->with('chrome_user_123')
            ->willReturn($inactiveCustomer);

        $controller = new VerificationController($this->customerRepo, $this->logger);

        ob_start();
        $controller->handleRequest();
        $output = ob_get_clean();

        $response = json_decode($output, true);
        $this->assertEquals('inactive', $response['status']);
        $this->assertStringContainsString('A licença vinculada a este perfil está inativa ou expirada', $response['message']);
    }

    public function testHandleRequestOptions()
    {
        $_SERVER['REQUEST_METHOD'] = 'OPTIONS';
        $controller = new VerificationController($this->customerRepo, $this->logger);

        ob_start();
        $controller->handleRequest();
        $output = ob_get_clean();

        $this->assertEmpty($output);
        unset($_SERVER['REQUEST_METHOD']);
    }

    public function testHandleRequestException()
    {
        $this->setRequestParams([
            'chrome_identity_id' => 'chrome_user_123',
            'extension_id' => 'gpjjhlfkdaakcpllhfobnmdjnbgpefgc'
        ]);

        $this->customerRepo->method('findByChromeIdentityId')->willThrowException(new \Exception('DB failure'));

        $controller = new VerificationController($this->customerRepo, $this->logger);

        ob_start();
        $controller->handleRequest();
        $output = ob_get_clean();

        $response = json_decode($output, true);
        $this->assertEquals('error', $response['status']);
        $this->assertEquals(500, http_response_code());
    }

    public function testHandleRequestFallbackToEmailAutoLink()
    {
        $this->setRequestParams([
            'chrome_identity_id' => 'chrome_new_123',
            'extension_id' => 'gpjjhlfkdaakcpllhfobnmdjnbgpefgc',
            'email' => 'fallback@example.com'
        ]);

        $customer = new Customer('Fallback User', 'fallback@example.com', '5511999999999');
        $customer->markAsPaid('pay_999');
        $customer->setPlan('LIFETIME');

        $this->customerRepo->method('findByChromeIdentityId')->willReturn(null);
        $this->customerRepo->method('findByEmail')->willReturn($customer);
        $this->customerRepo->expects($this->once())->method('save')->with($customer);

        $controller = new VerificationController($this->customerRepo, $this->logger);

        ob_start();
        $controller->handleRequest();
        $output = ob_get_clean();

        $response = json_decode($output, true);
        $this->assertEquals('active', $response['status']);
        $this->assertEquals('chrome_new_123', $customer->getChromeIdentityId());
    }

    public function testHandleRequestFallbackToEmailConflict()
    {
        $this->setRequestParams([
            'chrome_identity_id' => 'chrome_new_123',
            'extension_id' => 'gpjjhlfkdaakcpllhfobnmdjnbgpefgc',
            'email' => 'conflict@example.com'
        ]);

        $customer = new Customer('Conflict User', 'conflict@example.com', '5511999999999');
        $customer->setChromeIdentityId('chrome_old_999');

        $this->customerRepo->method('findByChromeIdentityId')->willReturn(null);
        $this->customerRepo->method('findByEmail')->willReturn($customer);
        $this->customerRepo->expects($this->never())->method('save');

        $controller = new VerificationController($this->customerRepo, $this->logger);

        ob_start();
        $controller->handleRequest();
        $output = ob_get_clean();

        $response = json_decode($output, true);
        $this->assertEquals('conflict', $response['status']);
        $this->assertStringContainsString('outro perfil', $response['message']);
    }

    public function testHandleRequestWithInvalidEmailIsSanitizedToNull()
    {
        $this->setRequestParams([
            'email' => 'invalid-email',
            'chrome_identity_id' => 'c1',
            'extension_id' => 'gpjjhlfkdaakcpllhfobnmdjnbgpefgc'
        ]);

        $this->customerRepo->method('findByChromeIdentityId')->willReturn(null);

        $controller = new VerificationController($this->customerRepo, $this->logger);

        ob_start();
        $controller->handleRequest();
        $output = ob_get_clean();

        $response = json_decode($output, true);
        $this->assertEquals('not_found', $response['status']);
        $this->assertStringContainsString('Nenhuma licença', $response['message']);
    }

    public function testHandleRequestWithInvalidExtensionIdIsRejected()
    {
        $this->setRequestParams([
            'email' => 'test@test.com',
            'chrome_identity_id' => 'c1',
            'extension_id' => 'invalid-ext'
        ]);

        $controller = new VerificationController($this->customerRepo, $this->logger);

        ob_start();
        $controller->handleRequest();
        $output = ob_get_clean();

        $response = json_decode($output, true);
        $this->assertEquals('error', $response['status']);
        $this->assertStringContainsString('Acesso não autorizado', $response['message']);
    }
}
