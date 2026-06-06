<?php

namespace App\Services {
    if (!function_exists('App\Services\curl_exec')) {
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
}

namespace Tests\Unit\Services {

    use PHPUnit\Framework\TestCase;
    use App\Services\LandingPageSyncService;
    use App\Entity\Customer;
    use Monolog\Logger;
    use Monolog\Handler\TestHandler;

    class LandingPageSyncServiceTest extends TestCase
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

        public function testNotifySaleSkipsIfWebhookUrlIsEmpty()
        {
            $service = new LandingPageSyncService('');
            $customer = new Customer('Test', 'test@example.com', '123');

            $service->notifySale($customer, $this->logger, 10);

            $this->assertTrue($this->testHandler->hasInfoThatContains('LandingPage Webhook URL not configured'));
        }

        public function testNotifySaleSendsWebhookSuccessfully()
        {
            global $mockCurlResponse, $mockCurlInfoHttpCode, $mockCurlError;
            $mockCurlResponse = 'ok';
            $mockCurlInfoHttpCode = 200;
            $mockCurlError = '';

            $service = new LandingPageSyncService('http://test.webhook.url');
            $customer = new Customer('Test', 'test@example.com', '123');
            $customer->setPlan('LIFETIME');

            $service->notifySale($customer, $this->logger, 15);

            $this->assertTrue($this->testHandler->hasInfoThatContains('Webhook sent to LandingPage'));
        }

        public function testNotifySaleHandlesCurlError()
        {
            global $mockCurlResponse, $mockCurlInfoHttpCode, $mockCurlError;
            $mockCurlResponse = false;
            $mockCurlInfoHttpCode = 0;
            $mockCurlError = 'Connection refused';

            $service = new LandingPageSyncService('http://test.webhook.url');
            $customer = new Customer('Test', 'test@example.com', '123');

            $service->notifySale($customer, $this->logger, 15);

            $this->assertTrue($this->testHandler->hasErrorThatContains('Error sending webhook to LandingPage'));
        }
    }
}
