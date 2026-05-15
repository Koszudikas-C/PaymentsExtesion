<?php

namespace App\Interfaces;

use Monolog\Logger;

interface EmailServiceInterface
{
    /**
     * @param string $to
     * @param string $licenseCode
     * @param Logger $log
     * @return bool
     */
    public function sendLicenseEmail(string $to, string $licenseCode, Logger $log): bool;
}
