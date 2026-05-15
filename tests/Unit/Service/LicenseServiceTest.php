<?php

namespace Tests\Unit\Service;

use App\Services\LicenseService;
use PHPUnit\Framework\TestCase;

class LicenseServiceTest extends TestCase
{
    public function testGenerateLicenseIsConsistent()
    {
        $service = new LicenseService();
        $whatsapp = '5511999999999';
        $salt = 'test-salt';

        $license1 = $service->generateLicense($whatsapp, $salt);
        $license2 = $service->generateLicense($whatsapp, $salt);

        $this->assertEquals($license1, $license2);
        $this->assertMatchesRegularExpression('/^[A-Z0-9]{4}-[A-Z0-9]{4}-[A-Z0-9]{4}-[A-Z0-9]{4}$/', $license1);
    }

    public function testDifferentInputsProduceDifferentLicenses()
    {
        $service = new LicenseService();
        $salt = 'test-salt';

        $license1 = $service->generateLicense('user1', $salt);
        $license2 = $service->generateLicense('user2', $salt);

        $this->assertNotEquals($license1, $license2);
    }
}
