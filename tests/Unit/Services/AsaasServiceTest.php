<?php

namespace App\Services {
    // Globais para simular respostas cURL no AsaasService
    $mockCurlResponse = false;
    $mockCurlInfoHttpCode = 200;
    $mockCurlErrno = 0;
    $mockCurlError = '';

    function curl_exec($ch) {
        global $mockCurlResponse;
        return $mockCurlResponse;
    }

    function curl_getinfo($ch, $opt) {
        global $mockCurlInfoHttpCode;
        if ($opt === CURLINFO_HTTP_CODE) {
            return $mockCurlInfoHttpCode;
        }
        return null;
    }

    function curl_errno($ch) {
        global $mockCurlErrno;
        return $mockCurlErrno;
    }

    function curl_error($ch) {
        global $mockCurlError;
        return $mockCurlError;
    }
}

namespace Tests\Unit\Services {

    use PHPUnit\Framework\TestCase;
    use App\Services\AsaasService;
    use Monolog\Logger;
    use Monolog\Handler\TestHandler;

    class AsaasServiceTest extends TestCase
    {
        private $logger;
        private $testHandler;

        protected function setUp(): void
        {
            $this->testHandler = new TestHandler();
            $this->logger = new Logger('test');
            $this->logger->pushHandler($this->testHandler);

            // Reset mocks
            global $mockCurlResponse, $mockCurlInfoHttpCode, $mockCurlErrno, $mockCurlError;
            $mockCurlResponse = false;
            $mockCurlInfoHttpCode = 200;
            $mockCurlErrno = 0;
            $mockCurlError = '';
        }

        public function testGetCustomerInfoSuccess()
        {
            global $mockCurlResponse, $mockCurlInfoHttpCode;
            $mockCurlResponse = json_encode(['id' => 'cus_123', 'email' => 'test@example.com', 'name' => 'John']);
            $mockCurlInfoHttpCode = 200;

            $service = new AsaasService('sandbox.asaas.com', 'fake_token', 'MyApp');
            $result = $service->getCustomerInfo('cus_123', $this->logger);

            $this->assertIsArray($result);
            $this->assertEquals('test@example.com', $result['email']);
            $this->assertFalse($this->testHandler->hasErrorRecords());
        }

        public function testGetCustomerInfoCurlError()
        {
            global $mockCurlErrno, $mockCurlError;
            $mockCurlErrno = 28; // Timeout
            $mockCurlError = 'Timeout was reached';

            $service = new AsaasService('sandbox.asaas.com', 'fake_token', 'MyApp');
            $result = $service->getCustomerInfo('cus_123', $this->logger);

            $this->assertNull($result);
            $this->assertTrue($this->testHandler->hasErrorRecords());
            $this->assertTrue($this->testHandler->hasErrorThatContains('cURL Error'));
        }

        public function testGetCustomerInfoApiError()
        {
            global $mockCurlResponse, $mockCurlInfoHttpCode;
            $mockCurlResponse = json_encode(['errors' => [['description' => 'Not found']]]);
            $mockCurlInfoHttpCode = 404;

            $service = new AsaasService('sandbox.asaas.com', 'fake_token', 'MyApp');
            $result = $service->getCustomerInfo('cus_123', $this->logger);

            $this->assertNull($result);
            $this->assertTrue($this->testHandler->hasErrorRecords());
            $this->assertTrue($this->testHandler->hasErrorThatContains('ASAAS API Error'));
        }

        public function testUpdatePaymentLinkToMonthlySuccess()
        {
            global $mockCurlResponse, $mockCurlInfoHttpCode;
            $mockCurlResponse = json_encode(['id' => 'link_123', 'value' => 29.99]);
            $mockCurlInfoHttpCode = 200;

            $service = new AsaasService('sandbox.asaas.com', 'fake_token', 'MyApp');
            $result = $service->updatePaymentLinkToMonthly('link_123', 29.99, 'Test Link', $this->logger);

            $this->assertTrue($result);
            $this->assertTrue($this->testHandler->hasInfoThatContains('Successfully updated payment link on Asaas'));
        }

        public function testUpdatePaymentLinkToMonthlyCurlError()
        {
            global $mockCurlErrno, $mockCurlError;
            $mockCurlErrno = 5;
            $mockCurlError = 'Could not resolve host';

            $service = new AsaasService('sandbox.asaas.com', 'fake_token', 'MyApp');
            $result = $service->updatePaymentLinkToMonthly('link_123', 29.99, 'Test Link', $this->logger);

            $this->assertFalse($result);
            $this->assertTrue($this->testHandler->hasErrorThatContains('cURL Error updating payment link'));
        }

        public function testUpdatePaymentLinkToMonthlyApiError()
        {
            global $mockCurlResponse, $mockCurlInfoHttpCode;
            $mockCurlResponse = 'Invalid request';
            $mockCurlInfoHttpCode = 400;

            $service = new AsaasService('sandbox.asaas.com', 'fake_token', 'MyApp');
            $result = $service->updatePaymentLinkToMonthly('link_123', 29.99, 'Test Link', $this->logger);

            $this->assertFalse($result);
            $this->assertTrue($this->testHandler->hasErrorThatContains('ASAAS API Error updating payment link'));
        }
    }
}
