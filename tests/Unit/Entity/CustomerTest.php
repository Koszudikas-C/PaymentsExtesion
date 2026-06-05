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

    public function testLicenseActiveFallbackToLifetime()
    {
        $customer = new Customer('John Doe', 'john@example.com', '123456789');
        $customer->setPlan('CO-CREATOR');
        $customer->setFallbackPlan('LIFETIME');
        $customer->setSubscriptionId('sub_123');
        
        // Expiração no passado
        $expiredDate = (new \DateTime('now'))->modify('-1 day');
        $customer->setLicenseExpiresAt($expiredDate);

        // Deve retornar TRUE e restaurar o plano vitalício automaticamente (self-healing)
        $this->assertTrue($customer->isLicenseActive());
        
        $this->assertEquals('LIFETIME', $customer->getPlan());
        $this->assertNull($customer->getSubscriptionId());
        $this->assertNull($customer->getLicenseExpiresAt());
    }

    public function testLicenseActiveNoFallback()
    {
        $customer = new Customer('John Doe', 'john@example.com', '123456789');
        $customer->setPlan('CO-CREATOR');
        $customer->setFallbackPlan(null);
        $customer->setSubscriptionId('sub_123');
        
        // Expiração no passado
        $expiredDate = (new \DateTime('now'))->modify('-1 day');
        $customer->setLicenseExpiresAt($expiredDate);

        // Sem fallback de LIFETIME, a licença deve ficar inativa/expirada
        $this->assertFalse($customer->isLicenseActive());
        $this->assertEquals('CO-CREATOR', $customer->getPlan());
    }

    public function testCanRetryDelivery()
    {
        $customer = new Customer('John Doe', 'john@example.com', '123456789');
        $this->assertTrue($customer->canRetryDelivery());

        for ($i = 0; $i < 10; $i++) {
            $customer->recordDeliveryFailure("Reason $i");
        }
        $this->assertFalse($customer->canRetryDelivery(), "Should return false after 10 failures");

        $customer = new Customer('John Doe 2', 'john2@example.com', '123456789');
        $customer->markLicenseAsDelivered();
        $this->assertFalse($customer->canRetryDelivery(), "Should return false if already delivered");
    }

    public function testInvalidEmailThrowsException()
    {
        $this->expectException(\InvalidArgumentException::class);
        new Customer('John Doe', 'invalid-email', '123456789');
    }

    public function testEmptyNameThrowsException()
    {
        $this->expectException(\InvalidArgumentException::class);
        new Customer('', 'john@example.com', '123456789');
    }

    public function testSetChromeIdentityIdAndGetPhone()
    {
        $customer = new Customer('John Doe', 'john@example.com', '123456789');
        $customer->setChromeIdentityId('chrome_xyz');
        
        $this->assertEquals('chrome_xyz', $customer->getChromeIdentityId());
        $this->assertEquals('123456789', $customer->getPhone());
    }

    public function testOnPreUpdate()
    {
        $customer = new Customer('John Doe', 'john@example.com', '123456789');
        $oldDate = (new \DateTime('now'))->modify('-1 day');
        
        $reflection = new \ReflectionClass($customer);
        $prop = $reflection->getProperty('dateUpdated');
        $prop->setValue($customer, $oldDate);

        $customer->onPreUpdate();
        $this->assertGreaterThan($oldDate, $customer->getDateUpdated());
    }

    public function testLicenseActiveOtherPlans()
    {
        $customer = new Customer('John Doe', 'john@example.com', '123456789');
        $customer->setPlan('UNKNOWN_PLAN');
        
        $this->assertFalse($customer->isLicenseActive(), "Should be false if no expiration date is set for unknown plan");
        
        $futureDate = (new \DateTime('now'))->modify('+1 day');
        $customer->setLicenseExpiresAt($futureDate);
        $this->assertTrue($customer->isLicenseActive(), "Should be true if expiration date is in the future for unknown plan");
    }
}
