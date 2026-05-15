<?php
namespace Tests\Mocks;

use App\Interfaces\EmailServiceInterface;
use Monolog\Logger;

class MockEmailService implements EmailServiceInterface
{
    public function sendLicenseEmail(string $to, string $licenseCode, Logger $log): bool
    {
        $log->info("MockEmailService: Simulating email send to {$to} with license {$licenseCode}");
        return true;
    }
}
