<?php

namespace App\Interfaces;

use Monolog\Logger;

interface EmailServiceInterface
{
    /**
     * @param string $to
     * @param string $licenseCode
     * @param Logger $log
     * @param string $customerName
     * @return bool
     */
    public function sendLicenseEmail(
        string $to,
        string $licenseCode,
        Logger $log,
        string $customerName = 'Usuário',
        string $templateName = 'license_email.html',
        string $appName = 'Salvar Conversas WhatsApp'
    ): bool;
}
