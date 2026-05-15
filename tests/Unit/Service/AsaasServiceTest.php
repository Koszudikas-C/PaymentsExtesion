<?php

namespace Tests\Unit\Service;

use App\Services\AsaasService;
use PHPUnit\Framework\TestCase;
use Monolog\Logger;

class AsaasServiceTest extends TestCase
{
    public function testGetCustomerInfoReturnsNullOnFailure()
    {
        $logger = $this->createMock(Logger::class);
        $service = new AsaasService('invalid-url', 'token', 'app');

        // We expect null because curl will fail on 'invalid-url'
        $result = $service->getCustomerInfo('cus_123', $logger);

        $this->assertNull($result);
    }
}
