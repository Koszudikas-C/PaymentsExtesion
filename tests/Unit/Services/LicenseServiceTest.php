<?php

namespace Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use App\Services\LicenseService;

class LicenseServiceTest extends TestCase
{
    public function testGenerateLicenseProducesExpectedFormat()
    {
        $service = new LicenseService();
        $identifier = 'test@example.com';
        $salt = 'my_secret_salt';

        $license = $service->generateLicense($identifier, $salt);

        // Expected hash format validation
        $this->assertEquals(19, strlen($license)); // 16 chars + 3 dashes
        $this->assertMatchesRegularExpression('/^[A-Z0-9]{4}-[A-Z0-9]{4}-[A-Z0-9]{4}-[A-Z0-9]{4}$/', $license);
    }

    public function testGenerateLicenseIsDeterministic()
    {
        $service = new LicenseService();
        $identifier = 'test@example.com';
        $salt = 'my_secret_salt';

        $license1 = $service->generateLicense($identifier, $salt);
        $license2 = $service->generateLicense($identifier, $salt);

        $this->assertEquals($license1, $license2);
    }

    public function testGenerateLicenseDiffersWithDifferentSaltOrIdentifier()
    {
        $service = new LicenseService();
        
        $license1 = $service->generateLicense('test1@example.com', 'salt1');
        $license2 = $service->generateLicense('test2@example.com', 'salt1');
        $license3 = $service->generateLicense('test1@example.com', 'salt2');

        $this->assertNotEquals($license1, $license2);
        $this->assertNotEquals($license1, $license3);
    }
}
