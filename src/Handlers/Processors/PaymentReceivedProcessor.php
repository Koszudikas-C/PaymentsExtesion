<?php

namespace App\Handlers\Processors;

use App\Interfaces\WebhookProcessorInterface;
use App\Interfaces\PaymentGatewayInterface;
use App\Interfaces\EmailServiceInterface;
use App\Interfaces\LicenseServiceInterface;
use App\Interfaces\PromotionServiceInterface;
use App\Interfaces\LandingPageSyncServiceInterface;
use App\Interfaces\Repositories\CustomerRepositoryInterface;
use App\Interfaces\Repositories\AuditLogRepositoryInterface;
use App\Entity\Customer;
use Monolog\Logger;

class PaymentReceivedProcessor implements WebhookProcessorInterface
{
    private PaymentGatewayInterface $paymentGateway;
    private EmailServiceInterface $emailService;
    private LicenseServiceInterface $licenseService;
    private CustomerRepositoryInterface $customerRepository;
    private AuditLogRepositoryInterface $auditLogRepository;
    private Logger $logger;
    private string $licenseSalt;
    private PromotionServiceInterface $promotionService;
    private LandingPageSyncServiceInterface $syncService;

    public function __construct(
        PaymentGatewayInterface $paymentGateway,
        EmailServiceInterface $emailService,
        LicenseServiceInterface $licenseService,
        CustomerRepositoryInterface $customerRepository,
        AuditLogRepositoryInterface $auditLogRepository,
        Logger $logger,
        string $licenseSalt,
        PromotionServiceInterface $promotionService,
        LandingPageSyncServiceInterface $syncService
    ) {
        $this->paymentGateway = $paymentGateway;
        $this->emailService = $emailService;
        $this->licenseService = $licenseService;
        $this->customerRepository = $customerRepository;
        $this->auditLogRepository = $auditLogRepository;
        $this->logger = $logger;
        $this->licenseSalt = $licenseSalt;
        $this->promotionService = $promotionService;
        $this->syncService = $syncService;
    }

    public function process(array $data): void
    {
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

        $subscriptionId = $payment['subscription'] ?? null;

        $customer = null;
        $dbFailed = false;
        $dbErrorMsg = '';

        // 1. Tenta buscar cliente existente no banco caso seja uma renovação de assinatura
        try {
            if ($subscriptionId) {
                $customer = $this->customerRepository->findBySubscriptionId($subscriptionId);
            }
        } catch (\Throwable $e) {
            $dbFailed = true;
            $dbErrorMsg = 'Lookup failed: ' . $e->getMessage();
            $this->logger->error('Database offline during customer lookup by subscription. Using fallback flow.', ['error' => $e->getMessage()]);
        }

        if (!$customer) {
            $customer = new Customer($name, $email, $whatsapp);
        } else {
            if ($customer->getPaymentStatus() === 'RECEIVED') {
                if ($customer->getPlan() === 'LIFETIME') {
                    // Pagamento duplo vitalício -> registra para reembolso
                    $this->logDoublePayment($customer, $customerId, $payment);
                    $customer->recordAudit('DOUBLE_PAYMENT_DETECTED', "Double payment received for payment ID: $customerId. Added to refund queue.");
                    if (!$dbFailed) {
                        $this->customerRepository->save($customer);
                    }
                    return;
                } elseif ($customer->getPlan() === 'MONTHLY') {
                    // Extensão mensal com backup para revocabilidade
                    $prevExpires = $customer->getLicenseExpiresAt();
                    $prevExpiresStr = $prevExpires ? $prevExpires->format('Y-m-d H:i:s') : 'never';
                    
                    $newExpires = $prevExpires ? clone $prevExpires : new \DateTime('now');
                    $newExpires->modify('+30 days');
                    $customer->setLicenseExpiresAt($newExpires);
                    
                    $customer->recordAudit('PLAN_EXTENDED', "Monthly subscription extended. Previous expiration: $prevExpiresStr. New expiration: " . $newExpires->format('Y-m-d H:i:s'));
                    $this->logger->info('Monthly subscription extended for customer', [
                        'email' => $email,
                        'prevExpiration' => $prevExpiresStr,
                        'newExpiration' => $newExpires->format('Y-m-d H:i:s')
                    ]);
                    
                    if (!$dbFailed) {
                        $this->customerRepository->save($customer);
                    }
                    return;
                }
            }
        }

        $customer->markAsPaid($customerId);
        $customer->setSystemAccess();
        
        // Define o plano (Mensal ou Vitalício) com base no pagamento do Asaas
        $subscriptionId = $payment['subscription'] ?? null;
        if ($subscriptionId) {
            $customer->setPlan('MONTHLY');
            $customer->setSubscriptionId($subscriptionId);
            
            $dueDateStr = $payment['dueDate'] ?? null;
            if ($dueDateStr) {
                $dueDate = new \DateTime($dueDateStr);
                $dueDate->modify('+3 days'); // tolerância
                $customer->setLicenseExpiresAt($dueDate);
            } else {
                $customer->setLicenseExpiresAt((new \DateTime('now'))->modify('+33 days'));
            }
        } else {
            $customer->setPlan('LIFETIME');
            $customer->setSubscriptionId(null);
            $customer->setLicenseExpiresAt(null);
        }
        
        // 2. Tenta gerar/atribuir licença
        if (!$customer->getLicenseKey()) {
            $attempts = 0;
            $licenseAssigned = false;

            while (!$licenseAssigned && $attempts < 10) {
                $identifier = $attempts === 0 ? $whatsapp : $whatsapp . '_' . $attempts;
                $generatedLicense = $this->licenseService->generateLicense($identifier, $this->licenseSalt);

                if ($dbFailed) {
                    // Se o banco de dados falhou anteriormente, não conseguimos fazer o lookup.
                    // Nesse caso, atribuímos diretamente em memória para não travar o cliente, e o fallback lidará com isso.
                    $customer->assignLicense($generatedLicense);
                    $licenseAssigned = true;
                    break;
                }

                try {
                    $otherCustomer = $this->customerRepository->findByLicenseKey($generatedLicense);

                    if ($otherCustomer && $otherCustomer->getEmail() !== $email) {
                        $this->logger->warning('Security Alert: WhatsApp reuse or license collision attempt', [
                            'whatsapp' => $whatsapp,
                            'attempt' => $attempts,
                            'colliding_email' => $otherCustomer->getEmail(),
                            'current_email' => $email
                        ]);
                        $attempts++;
                    } else {
                        $customer->assignLicense($generatedLicense);
                        $licenseAssigned = true;
                    }
                } catch (\Throwable $e) {
                    $dbFailed = true;
                    $dbErrorMsg = 'License lookup failed: ' . $e->getMessage();
                    $this->logger->error('Database offline during license validation.', ['error' => $e->getMessage()]);
                    
                    // Se o banco falhou no lookup, atribuímos diretamente em memória para não travar o cliente
                    $customer->assignLicense($generatedLicense);
                    $licenseAssigned = true;
                }
            }

            if (!$licenseAssigned) {
                // Caso extremo após 10 tentativas de colisão: gera uma licença com identificador único (com hash aleatório)
                $randomIdentifier = $whatsapp . '_' . bin2hex(random_bytes(5));
                $generatedLicense = $this->licenseService->generateLicense($randomIdentifier, $this->licenseSalt);
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

                // NOTIFICA A LANDING PAGE PARA SINCRONIZAÇÃO DE VENDAS
                try {
                    $lifetimeCount = $this->customerRepository->countPaidLifetimeCustomers();
                    $this->syncService->notifySale($customer, $this->logger, $lifetimeCount);
                } catch (\Throwable $e) {
                    $this->logger->error('Error notifying LandingPage of sale', ['error' => $e->getMessage()]);
                }

                // VERIFICAÇÃO AUTOMÁTICA DE META DE LANÇAMENTO
                $this->promotionService->handlePromotionGoal($customer, $this->logger);
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
        $fallbackDir = ($_ENV['APP_ENV'] ?? '') === 'testing'
            ? __DIR__ . '/../../../logs_test'
            : __DIR__ . '/../../../logs';
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
            'plan' => $customer->getPlan(),
            'subscriptionId' => $customer->getSubscriptionId(),
            'licenseExpiresAt' => $customer->getLicenseExpiresAt() ? $customer->getLicenseExpiresAt()->format('c') : null,
            'error' => $errorMessage
        ];

        $existing = [];
        if (file_exists($fallbackFile)) {
            $content = file_get_contents($fallbackFile);
            $existing = json_decode($content, true) ?: [];
        }

        $existing[] = $fallbackData;

        file_put_contents($fallbackFile, json_encode($existing, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
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

    private function logDoublePayment(Customer $customer, string $paymentId, array $paymentData): void
    {
        $logFile = ($_ENV['APP_ENV'] ?? '') === 'testing'
            ? __DIR__ . '/../../../logs_test/double_payments.json'
            : __DIR__ . '/../../../logs/double_payments.json';
        $dir = dirname($logFile);
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }

        $records = [];
        if (file_exists($logFile)) {
            $records = json_decode(file_get_contents($logFile), true) ?: [];
        }

        $records[] = [
            'email' => $customer->getEmail(),
            'name' => $customer->getName(),
            'whatsapp' => $customer->getPhone(),
            'paymentId' => $paymentId,
            'plan' => $customer->getPlan(),
            'timestamp' => (new \DateTime('now'))->format('Y-m-d H:i:s'),
            'action' => 'REFUND_REQUIRED',
            'details' => $paymentData
        ];

        file_put_contents($logFile, json_encode($records, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        
        $this->logger->warning('Double payment detected for Lifetime user. Logged for refund.', [
            'email' => $customer->getEmail(),
            'paymentId' => $paymentId
        ]);
    }
}
