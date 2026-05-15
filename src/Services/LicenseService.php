<?php

namespace App\Services;

use App\Interfaces\LicenseServiceInterface;

class LicenseService implements LicenseServiceInterface
{
    public function generateLicense(string $identifier, string $salt): string
    {
        $license = strtoupper(substr(hash('sha256', $identifier . $salt), 0, 16));
        return implode('-', str_split($license, 4));
    }
}
