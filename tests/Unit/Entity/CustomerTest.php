<?php

namespace Tests\Unit\Entity;

use App\Entity\Customer;
use PHPUnit\Framework\TestCase;

class CustomerTest extends TestCase
{
    public function testCustomerInitialization()
    {
        $customer = new Customer('John Doe', 'john@example.com', '123456789');
        
        $this->assertNotEmpty($customer->getId());
        $this->assertEquals('John Doe', $customer->getName());
        $this->assertEquals('john@example.com', $customer->getEmail());
        $this->assertEquals('PENDING', $customer->getPaymentStatus());
        $this->assertFalse($customer->isLicenseDelivered());
        $this->assertEquals(0, $customer->getDeliveryFailureCount());
    }

    public function testMarkAsPaid()
    {
        $customer = new Customer('John Doe', 'john@example.com', '123456789');
        $customer->markAsPaid('PAY-123');

        $this->assertEquals('RECEIVED', $customer->getPaymentStatus());
        $this->assertCount(2, $customer->getAuditLogs()); // CREATED + PAYMENT_RECEIVED
    }

    public function testAssignLicense()
    {
        $customer = new Customer('John Doe', 'john@example.com', '123456789');
        $customer->assignLicense('ABCD-1234');

        $this->assertEquals('ABCD-1234', $customer->getLicenseKey());
    }

    public function testCannotAssignDifferentLicense()
    {
        $this->expectException(\DomainException::class);
        
        $customer = new Customer('John Doe', 'john@example.com', '123456789');
        $customer->assignLicense('ABCD-1234');
        $customer->assignLicense('XXXX-YYYY');
    }

    public function testDeliveryTracking()
    {
        $customer = new Customer('John Doe', 'john@example.com', '123456789');
        
        $customer->recordDeliveryFailure('Server Down');
        $this->assertEquals(1, $customer->getDeliveryFailureCount());
        $this->assertFalse($customer->isLicenseDelivered());

        $customer->markLicenseAsDelivered();
        $this->assertTrue($customer->isLicenseDelivered());
        $this->assertEquals(0, $customer->getDeliveryFailureCount());
    }
}
