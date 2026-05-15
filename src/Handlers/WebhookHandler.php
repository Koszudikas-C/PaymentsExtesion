<?php

namespace App\Handlers;

use App\Interfaces\PaymentGatewayInterface;
use App\Interfaces\EmailServiceInterface;
use App\Interfaces\LicenseServiceInterface;
use Monolog\Logger;

class WebhookHandler
{
    private PaymentGatewayInterface $paymentGateway;
    private EmailServiceInterface $emailService;
    private LicenseServiceInterface $licenseService;
    private Logger $logger;
    private string $licenseSalt;

    public function __construct(
        PaymentGatewayInterface $paymentGateway,
        EmailServiceInterface $emailService,
        LicenseServiceInterface $licenseService,
        Logger $logger,
        string $licenseSalt
    ) {
        $this->paymentGateway = $paymentGateway;
        $this->emailService = $emailService;
        $this->licenseService = $licenseService;
        $this->logger = $logger;
        $this->licenseSalt = $licenseSalt;
    }

    public function handle(array $data): void
    {
        if ($data['event'] !== 'PAYMENT_RECEIVED') {
            return;
        }

        $payment = $data['payment'];
        $customerId = $payment['customer'];

        $customerInfo = $this->paymentGateway->getCustomerInfo($customerId, $this->logger);

        if (!$customerInfo) {
            $this->logger->error('Customer info not found', ['customer_id' => $customerId]);
            return;
        }

        $email = $customerInfo['email'] ?? '';
        $whatsapp = $customerInfo['mobilePhone'] ?? $customerInfo['phone'] ?? 'unknown';

        if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->logger->error('Invalid Email Address', ['received' => $email, 'customer_id' => $customerId]);
            return;
        }

        $this->logger->info('Payment Confirmed', ['whatsapp' => $whatsapp, 'email' => $email]);

        $formattedLicense = $this->licenseService->generateLicense($whatsapp, $this->licenseSalt);

        if ($this->emailService->sendLicenseEmail($email, $formattedLicense, $this->logger)) {
            $this->logger->info('License Delivered', ['to' => $email, 'license' => $formattedLicense]);
        } else {
            $this->logger->error('Delivery Failed', ['to' => $email]);
        }
    }
}
