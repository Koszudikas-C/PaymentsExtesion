<?php

namespace App\Interfaces;

interface LicenseServiceInterface
{
    /**
     * @param string $identifier Usually phone number or unique string
     * @param string $salt
     * @return string
     */
    public function generateLicense(string $identifier, string $salt): string;
}
