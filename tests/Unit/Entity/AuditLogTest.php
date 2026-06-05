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

    public function testSetCustomer()
    {
        $customer1 = new Customer('Test User 1', 'test1@example.com', '123');
        $customer2 = new Customer('Test User 2', 'test2@example.com', '456');
        
        $log = new AuditLog($customer1, 'TEST_ACTION');
        $this->assertSame($customer1, $log->getCustomer());
        
        $log->setCustomer($customer2);
        $this->assertSame($customer2, $log->getCustomer());
    }
}
