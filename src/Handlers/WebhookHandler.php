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

        $customer = null;
        $dbFailed = false;
        $dbErrorMsg = '';

        // 1. Tenta buscar cliente existente no banco
        try {
            $customer = $this->customerRepository->findByEmail($email);
        } catch (\Throwable $e) {
            $dbFailed = true;
            $dbErrorMsg = 'Lookup failed: ' . $e->getMessage();
            $this->logger->error('Database offline during customer lookup. Using fallback flow.', ['error' => $e->getMessage()]);
        }

        if (!$customer) {
            $customer = new Customer($name, $email, $whatsapp);
        }

        $customer->markAsPaid($customerId);
        $customer->setSystemAccess();
        
        // 2. Tenta gerar/atribuir licença
        if (!$customer->getLicenseKey()) {
            $generatedLicense = $this->licenseService->generateLicense($whatsapp, $this->licenseSalt);
            
            $licenseAssigned = false;
            if (!$dbFailed) {
                try {
                    $otherCustomer = $this->customerRepository->findByLicenseKey($generatedLicense);
                    
                    if ($otherCustomer && $otherCustomer->getEmail() !== $email) {
                        $this->logger->warning('Security Alert: WhatsApp reuse attempt');
                    } else {
                        $customer->assignLicense($generatedLicense);
                        $licenseAssigned = true;
                    }
                } catch (\Throwable $e) {
                    $dbFailed = true;
                    $dbErrorMsg = 'License lookup failed: ' . $e->getMessage();
                    $this->logger->error('Database offline during license validation.', ['error' => $e->getMessage()]);
                }
            }

            // Se o banco falhou ou não conseguimos validar, atribuímos a licença diretamente em memória para não travar o cliente
            if (!$licenseAssigned) {
                $customer->assignLicense($generatedLicense);
            }
        }

        // 3. Tentativa de entrega por E-mail (Sempre roda, mesmo sem banco de dados!)
        $this->attemptLicenseDelivery($customer);

        // 4. Persistência ou Fallback
        if ($dbFailed) {
            $this->saveToFallbackFile($customer, $dbErrorMsg);
        } else {
            try {
                $this->customerRepository->save($customer);
            } catch (\Throwable $e) {
                $this->logger->critical('Database error persisting customer. Falling back to local file.', [
                    'email' => $customer->getEmail(),
                    'error' => $e->getMessage()
                ]);
                $this->saveToFallbackFile($customer, $e->getMessage());
            }
        }
    }

    private function saveToFallbackFile(Customer $customer, string $errorMessage): void
    {
        $fallbackDir = __DIR__ . '/../../logs';
        if (!is_dir($fallbackDir)) {
            mkdir($fallbackDir, 0777, true);
        }

        $fallbackFile = $fallbackDir . '/failed_licenses.json';
        
        $fallbackData = [
            'timestamp' => date('c'),
            'name' => $customer->getName(),
            'email' => $customer->getEmail(),
            'phone' => $customer->getPhone(),
            'licenseKey' => $customer->getLicenseKey(),
            'paymentStatus' => $customer->getPaymentStatus(),
            'isLicenseDelivered' => $customer->isLicenseDelivered(),
            'deliveryFailureCount' => $customer->getDeliveryFailureCount(),
            'error' => $errorMessage
        ];

        $existing = [];
        if (file_exists($fallbackFile)) {
            $content = file_get_contents($fallbackFile);
            $existing = json_decode($content, true) ?: [];
        }

        $existing[] = $fallbackData;

        file_put_contents($fallbackFile, json_encode($existing, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
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
