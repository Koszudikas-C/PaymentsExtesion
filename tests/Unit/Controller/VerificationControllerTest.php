<?php

namespace Tests\Unit\Controller;

use PHPUnit\Framework\TestCase;
use App\Controllers\VerificationController;
use App\Services\CustomerValidationService;
use App\Entity\Customer;
use Monolog\Logger;

class VerificationControllerTest extends TestCase
{
    private $validationService;
    private $logger;

    protected function setUp(): void
    {
        $this->validationService = $this->createMock(CustomerValidationService::class);
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

        $controller = new VerificationController($this->validationService, $this->logger);

        ob_start();
        $controller->handleRequest();
        $output = ob_get_clean();

        $response = json_decode($output, true);
        $this->assertEquals('error', $response['status']);
        $this->assertStringContainsString('Parameters chrome_identity_id and extension_id are required.', $response['message']);
    }

    public function testHandleRequestInvalidExtensionIdReturns403()
    {
        $this->setRequestParams([
            'chrome_identity_id' => 'chrome_user_123',
            'extension_id' => 'wrongextensionidhere'
        ]);

        $controller = new VerificationController($this->validationService, $this->logger);

        ob_start();
        $controller->handleRequest();
        $output = ob_get_clean();

        $response = json_decode($output, true);
        $this->assertEquals('error', $response['status']);
        $this->assertStringContainsString('Unauthorized access for this extension.', $response['message']);
    }

    public function testHandleRequestCustomerNotFoundReturns200WithNotFound()
    {
        $this->setRequestParams([
            'chrome_identity_id' => 'chrome_user_123',
            'extension_id' => 'gpjjhlfkdaakcpllhfobnmdjnbgpefgc'
        ]);

        $this->validationService->expects($this->once())
            ->method('validateRequest')
            ->with('chrome_user_123', null)
            ->willReturn([
                'status' => 'not_found',
                'message' => 'No active license linked to this profile.'
            ]);

        $controller = new VerificationController($this->validationService, $this->logger);

        ob_start();
        $controller->handleRequest();
        $output = ob_get_clean();

        $response = json_decode($output, true);
        $this->assertEquals('not_found', $response['status']);
        $this->assertStringContainsString('No active license linked', $response['message']);
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

        $this->validationService->expects($this->once())
            ->method('validateRequest')
            ->with('chrome_user_123', null)
            ->willReturn($activeCustomer);

        $controller = new VerificationController($this->validationService, $this->logger);

        ob_start();
        $controller->handleRequest();
        $output = ob_get_clean();

        $response = json_decode($output, true);
        $this->assertEquals('active', $response['status']);
        $this->assertEquals('LIFETIME', $response['plan']);
        $this->assertNull($response['expiresAt']);
        $this->assertStringContainsString('Active and valid license', $response['message']);
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

        $this->validationService->expects($this->once())
            ->method('validateRequest')
            ->with('chrome_user_123', null)
            ->willReturn($inactiveCustomer);

        $controller = new VerificationController($this->validationService, $this->logger);

        ob_start();
        $controller->handleRequest();
        $output = ob_get_clean();

        $response = json_decode($output, true);
        $this->assertEquals('inactive', $response['status']);
        $this->assertStringContainsString('The license linked to this profile is inactive or expired.', $response['message']);
    }

    public function testHandleRequestOptions()
    {
        $_SERVER['REQUEST_METHOD'] = 'OPTIONS';
        $controller = new VerificationController($this->validationService, $this->logger);

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

        $this->validationService->method('validateRequest')->willThrowException(new \Exception('DB failure'));

        $controller = new VerificationController($this->validationService, $this->logger);

        ob_start();
        $controller->handleRequest();
        $output = ob_get_clean();

        $response = json_decode($output, true);
        $this->assertEquals('error', $response['status']);
        $this->assertEquals(500, http_response_code());
    }

    public function testHandleRequestFallbackToEmailAutoLink()
    {
        // This logic is now inside validationService, so we just mock the return
        $this->setRequestParams([
            'chrome_identity_id' => 'chrome_new_123',
            'extension_id' => 'gpjjhlfkdaakcpllhfobnmdjnbgpefgc',
            'email' => 'fallback@example.com'
        ]);

        $customer = new Customer('Fallback User', 'fallback@example.com', '5511999999999');
        $customer->markAsPaid('pay_999');
        $customer->setPlan('LIFETIME');
        $customer->setChromeIdentityId('chrome_new_123'); // assuming service auto-linked it

        $this->validationService->method('validateRequest')->willReturn($customer);

        $controller = new VerificationController($this->validationService, $this->logger);

        ob_start();
        $controller->handleRequest();
        $output = ob_get_clean();

        $response = json_decode($output, true);
        $this->assertEquals('active', $response['status']);
    }

    public function testHandleRequestFallbackToEmailConflict()
    {
        $this->setRequestParams([
            'chrome_identity_id' => 'chrome_new_123',
            'extension_id' => 'gpjjhlfkdaakcpllhfobnmdjnbgpefgc',
            'email' => 'conflict@example.com'
        ]);

        $this->validationService->method('validateRequest')->willReturn([
            'status' => 'conflict',
            'message' => 'Sua licença está ativada em outro perfil ou dispositivo.'
        ]);

        $controller = new VerificationController($this->validationService, $this->logger);

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

        // validateRequest receives null for email because of sanitization
        $this->validationService->expects($this->once())
            ->method('validateRequest')
            ->with('c1', null)
            ->willReturn([
                'status' => 'not_found',
                'message' => 'Nenhuma licença ativa vinculada a este perfil.'
            ]);

        $controller = new VerificationController($this->validationService, $this->logger);

        ob_start();
        $controller->handleRequest();
        $output = ob_get_clean();

        $response = json_decode($output, true);
        $this->assertEquals('not_found', $response['status']);
    }

    public function testHandleRequestWithInvalidExtensionIdIsRejected()
    {
        $this->setRequestParams([
            'email' => 'test@test.com',
            'chrome_identity_id' => 'c1',
            'extension_id' => 'invalid-ext'
        ]);

        $controller = new VerificationController($this->validationService, $this->logger);

        ob_start();
        $controller->handleRequest();
        $output = ob_get_clean();

        $response = json_decode($output, true);
        $this->assertEquals('error', $response['status']);
        $this->assertStringContainsString('Unauthorized access for this extension.', $response['message']);
    }
}
