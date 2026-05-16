<?php

namespace App\Handlers;

use App\Interfaces\PaymentGatewayInterface;
use App\Interfaces\EmailServiceInterface;
use App\Interfaces\LicenseServiceInterface;
use App\Interfaces\Repositories\CustomerRepositoryInterface;
use App\Interfaces\Repositories\AuditLogRepositoryInterface;
use App\Entity\Customer;
use Monolog\Logger;

class WebhookHandler
{
    private PaymentGatewayInterface $paymentGateway;
    private EmailServiceInterface $emailService;
    private LicenseServiceInterface $licenseService;
    private CustomerRepositoryInterface $customerRepository;
    private AuditLogRepositoryInterface $auditLogRepository;
    private Logger $logger;
    private string $licenseSalt;

    public function __construct(
        PaymentGatewayInterface $paymentGateway,
        EmailServiceInterface $emailService,
        LicenseServiceInterface $licenseService,
        CustomerRepositoryInterface $customerRepository,
        AuditLogRepositoryInterface $auditLogRepository,
        Logger $logger,
        string $licenseSalt
    ) {
        $this->paymentGateway = $paymentGateway;
        $this->emailService = $emailService;
        $this->licenseService = $licenseService;
        $this->customerRepository = $customerRepository;
        $this->auditLogRepository = $auditLogRepository;
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
            $this->logger->error('Customer info not found in Asaas', ['customer_id' => $customerId]);
            return;
        }

        $email = $customerInfo['email'] ?? '';
        $name = $customerInfo['name'] ?? 'Usuário';
        $whatsapp = $customerInfo['mobilePhone'] ?? $customerInfo['phone'] ?? 'unknown';

        $customer = $this->customerRepository->findByEmail($email);

        if (!$customer) {
            $customer = new Customer($name, $email, $whatsapp);
        }

        $customer->markAsPaid($customerId);
        $customer->setSystemAccess();
        
        if (!$customer->getLicenseKey()) {
            $generatedLicense = $this->licenseService->generateLicense($whatsapp, $this->licenseSalt);
            
            $otherCustomer = $this->customerRepository->findByLicenseKey($generatedLicense);
            
            if ($otherCustomer && $otherCustomer->getEmail() !== $email) {
                $this->logger->warning('Security Alert: WhatsApp reuse attempt');
            } else {
                $customer->assignLicense($generatedLicense);
            }
        }

        // 3. Tentativa de entrega
        $this->attemptLicenseDelivery($customer);

        // 4. Persistência
        $this->customerRepository->save($customer);
    }

    private function attemptLicenseDelivery(Customer $customer): void
    {
        if (!$customer->getLicenseKey() || $customer->isLicenseDelivered()) {
            return;
        }

        $this->logger->info('Attempting License Delivery', ['email' => $customer->getEmail()]);

        if ($this->emailService->sendLicenseEmail($customer->getEmail(), $customer->getLicenseKey(), $this->logger, $customer->getName())) {
            $customer->markLicenseAsDelivered();
        } else {
            $customer->recordDeliveryFailure('SMTP Error - check logs');
            $this->logger->error('Delivery Failed', ['email' => $customer->getEmail()]);
        }
    }
}
