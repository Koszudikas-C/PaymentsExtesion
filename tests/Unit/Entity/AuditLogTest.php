<?php

namespace Tests\Unit\Entity;

use App\Entity\Customer;
use App\Entity\AuditLog;
use PHPUnit\Framework\TestCase;

class AuditLogTest extends TestCase
{
    public function testAuditLogInitialization()
    {
        $customer = new Customer('Test User', 'test@example.com', '123');
        $log = new AuditLog($customer, 'TEST_ACTION', 'Some details');
        
        $this->assertNotEmpty($log->getId());
        $this->assertSame($customer, $log->getCustomer());
        $this->assertEquals('TEST_ACTION', $log->getAction());
        $this->assertEquals('Some details', $log->getDetails());
        $this->assertInstanceOf(\DateTime::class, $log->getDateCreated());
    }
}
