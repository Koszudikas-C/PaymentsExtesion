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
        $processStart = microtime(true);
        $payment = $data['payment'];
        $customerId = $payment['customer'];
        $paymentId = $payment['id'] ?? '';

        // Evita processamento duplicado do mesmo pagamento (ex: PAYMENT_CONFIRMED e PAYMENT_RECEIVED para o mesmo Pix/cartão)
        if ($paymentId) {
            try {
                if ($this->auditLogRepository->hasPaymentBeenProcessed($paymentId)) {
                    $this->logger->info('Payment already processed, skipping duplicate event.', ['payment_id' => $paymentId]);
                    $durationProcess = round((microtime(true) - $processStart) * 1000, 2);
                    $this->logPerformance('PaymentReceivedProcessor', 'process', $durationProcess, 'Already Processed');
                    return;
                }
            } catch (\Throwable $e) {
                // Se o banco falhar na verificação, loga e segue pelo fluxo normal
                $this->logger->error('Error checking if payment was already processed.', ['error' => $e->getMessage()]);
            }
        }

        $startGateway = microtime(true);
        $customerInfo = $this->paymentGateway->getCustomerInfo($customerId, $this->logger);
        $durationGateway = round((microtime(true) - $startGateway) * 1000, 2);
        $this->logPerformance('PaymentGateway', 'getCustomerInfo', $durationGateway);

        if (!$customerInfo) {
            $this->logger->error('Customer info not found in Asaas', ['customer_id' => $customerId]);
            $durationProcess = round((microtime(true) - $processStart) * 1000, 2);
            $this->logPerformance('PaymentReceivedProcessor', 'process', $durationProcess, 'Customer Not Found');
            return;
        }

        $email = $customerInfo['email'] ?? '';
        $name = $customerInfo['name'] ?? 'Usuário';
        $whatsapp = $customerInfo['mobilePhone'] ?? $customerInfo['phone'] ?? 'unknown';

        $subscriptionId = $payment['subscription'] ?? null;
        $value = isset($payment['value']) ? (float)$payment['value'] : (isset($payment['originalValue']) ? (float)$payment['originalValue'] : 0.0);

        // Determina o plano-alvo pelo valor do pagamento ANTES de qualquer verificação de estado.
        // CO-CREATOR via link de pagamento Pix NÃO tem campo 'subscription'.
        // Por isso a detecção deve ser feita por valor, não por presença de subscriptionId.
        $targetPlan = $this->resolveTargetPlan($value, $subscriptionId);
        $isUpgrade = false;

        $customer = null;
        $dbFailed = false;
        $dbErrorMsg = '';

        // 1. Tenta buscar cliente existente no banco caso seja uma renovação de assinatura ou compra por e-mail existente
        try {
            if ($subscriptionId) {
                $startSubLookup = microtime(true);
                $customer = $this->customerRepository->findBySubscriptionId($subscriptionId);
                $durationSubLookup = round((microtime(true) - $startSubLookup) * 1000, 2);
                $this->logPerformance('CustomerRepository', 'findBySubscriptionId', $durationSubLookup);
            }

            if (!$customer && $email) {
                $startEmailLookup = microtime(true);
                $customer = $this->customerRepository->findByEmail($email);
                $durationEmailLookup = round((microtime(true) - $startEmailLookup) * 1000, 2);
                $this->logPerformance('CustomerRepository', 'findByEmail', $durationEmailLookup);
            }
        } catch (\Throwable $e) {
            $dbFailed = true;
            $dbErrorMsg = 'Lookup failed: ' . $e->getMessage();
            $this->logger->error('Database offline during customer lookup. Using fallback flow.', ['error' => $e->getMessage()]);
        }

        if (!$customer) {
            $customer = new Customer($name, $email, $whatsapp);
        } else {
            if ($customer->getPaymentStatus() === 'RECEIVED') {
                if ($customer->getPlan() === 'LIFETIME') {
                    // Pagamento duplicado somente se o plano-alvo for novamente LIFETIME.
                    // Um usuário vitalício comprando CO-CREATOR (29.99 via link Pix) NÃO é duplicata.
                    if ($targetPlan === 'LIFETIME') {
                        $this->logDoublePayment($customer, $customerId, $payment);
                        $customer->recordAudit('DOUBLE_PAYMENT_DETECTED', "Double payment received for payment ID: $paymentId. Added to refund queue.");
                        if (!$dbFailed) {
                            $this->customerRepository->save($customer);
                        }
                        $durationProcess = round((microtime(true) - $processStart) * 1000, 2);
                        $this->logPerformance('PaymentReceivedProcessor', 'process', $durationProcess, 'Double Payment');
                        return;
                    }
                    // targetPlan é CO-CREATOR ou MONTHLY → transição de plano permitida
                } elseif ($customer->getPlan() === 'MONTHLY' || $customer->getPlan() === 'CO-CREATOR') {
                    // Se a renovação for da mesma assinatura ativa, estende o prazo
                    if ($subscriptionId && $customer->getSubscriptionId() === $subscriptionId) {
                        $prevExpires = $customer->getLicenseExpiresAt();
                        $prevExpiresStr = $prevExpires ? $prevExpires->format('Y-m-d H:i:s') : 'never';
                        
                        $newExpires = $prevExpires ? clone $prevExpires : new \DateTime('now');
                        $newExpires->modify('+30 days');
                        $customer->setLicenseExpiresAt($newExpires);
                        
                        $customer->recordAudit('PLAN_EXTENDED', "{$customer->getPlan()} subscription extended. Previous expiration: $prevExpiresStr. New expiration: " . $newExpires->format('Y-m-d H:i:s'));
                        $this->logger->info("{$customer->getPlan()} subscription extended for customer", [
                            'email' => $email,
                            'prevExpiration' => $prevExpiresStr,
                            'newExpiration' => $newExpires->format('Y-m-d H:i:s')
                        ]);
                        
                        if (!$dbFailed) {
                            $this->customerRepository->save($customer);
                        }
                        $durationProcess = round((microtime(true) - $processStart) * 1000, 2);
                        $this->logPerformance('PaymentReceivedProcessor', 'process', $durationProcess, 'Plan Extended');
                        return;
                    }
                    // Se for uma assinatura nova/diferente ou nova compra avulsa (LIFETIME), deixa seguir para transição/atualização
                }
            }
        }

        $customer->markAsPaid($paymentId);
        $customer->setSystemAccess();

        $oldPlan = $customer->getPlan();

        if ($targetPlan === 'CO-CREATOR' || $targetPlan === 'MONTHLY') {
            // Guarda o plano vitalício como fallback caso a assinatura expire
            if ($oldPlan === 'LIFETIME') {
                $customer->setFallbackPlan('LIFETIME');
            }

            if ($oldPlan !== $targetPlan || $customer->getSubscriptionId() !== $subscriptionId) {
                if ($oldPlan === 'LIFETIME' && $targetPlan === 'CO-CREATOR') {
                    $isUpgrade = true;
                }
                $customer->recordAudit(
                    'PLAN_TRANSITION',
                    "Transitioning plan from {$oldPlan} to {$targetPlan}. Subscription ID: " . ($subscriptionId ?? 'none (payment link)')
                );
            }

            $customer->setPlan($targetPlan);

            if ($subscriptionId) {
                $customer->setSubscriptionId($subscriptionId);
            }

            $dueDateStr = $payment['dueDate'] ?? null;
            if ($dueDateStr) {
                $dueDate = new \DateTime($dueDateStr);
                $dueDate->modify('+1 month');
                $dueDate->modify('+3 days');
                $customer->setLicenseExpiresAt($dueDate);
            } else {
                $customer->setLicenseExpiresAt((new \DateTime('now'))->modify('+33 days'));
            }
        } else {
            // LIFETIME: pagamento avulso
            if ($oldPlan !== 'LIFETIME') {
                $customer->recordAudit('PLAN_TRANSITION', "Transitioning plan from {$oldPlan} to LIFETIME.");
            }
            $customer->setPlan('LIFETIME');
            $customer->setSubscriptionId(null);
            $customer->setLicenseExpiresAt(null);
            $customer->setFallbackPlan(null);
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
                    $startLicenseLookup = microtime(true);
                    $otherCustomer = $this->customerRepository->findByLicenseKey($generatedLicense);
                    $durationLicenseLookup = round((microtime(true) - $startLicenseLookup) * 1000, 2);
                    $this->logPerformance('CustomerRepository', 'findByLicenseKey', $durationLicenseLookup);

                    if ($otherCustomer) {
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
        $this->attemptLicenseDelivery($customer, $targetPlan, $isUpgrade);

        // 4. Persistência ou Fallback
        if ($dbFailed) {
            $this->saveToFallbackFile($customer, $dbErrorMsg);
        } else {
            try {
                $startSave = microtime(true);
                $this->customerRepository->save($customer);
                $durationSave = round((microtime(true) - $startSave) * 1000, 2);
                $this->logPerformance('CustomerRepository', 'save', $durationSave);

                // NOTIFICA A LANDING PAGE PARA SINCRONIZAÇÃO DE VENDAS
                try {
                    $startSync = microtime(true);
                    $lifetimeCount = $this->customerRepository->countPaidLifetimeCustomers();
                    $this->syncService->notifySale($customer, $this->logger, $lifetimeCount);
                    $durationSync = round((microtime(true) - $startSync) * 1000, 2);
                    $this->logPerformance('LandingPageSyncService', 'notifySale', $durationSync);
                } catch (\Throwable $e) {
                    $this->logger->error('Error notifying LandingPage of sale', ['error' => $e->getMessage()]);
                }

                // VERIFICAÇÃO AUTOMÁTICA DE META DE LANÇAMENTO
                try {
                    $startPromo = microtime(true);
                    $this->promotionService->handlePromotionGoal($customer, $this->logger);
                    $durationPromo = round((microtime(true) - $startPromo) * 1000, 2);
                    $this->logPerformance('PromotionService', 'handlePromotionGoal', $durationPromo);
                } catch (\Throwable $e) {
                    $this->logger->error('Error handling promotion goal', ['error' => $e->getMessage()]);
                }
            } catch (\Throwable $e) {
                $this->logger->critical('Database error persisting customer. Falling back to local file.', [
                    'email' => $customer->getEmail(),
                    'error' => $e->getMessage()
                ]);
                $this->saveToFallbackFile($customer, $e->getMessage());
            }
        }

        $durationProcess = round((microtime(true) - $processStart) * 1000, 2);
        $this->logPerformance('PaymentReceivedProcessor', 'process', $durationProcess, 'Success');
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

    private function attemptLicenseDelivery(Customer $customer, string $targetPlan = 'LIFETIME', bool $isUpgrade = false): void
    {
        if (!$isUpgrade && (!$customer->getLicenseKey() || $customer->isLicenseDelivered())) {
            return;
        }

        $this->logger->info('Attempting License Delivery', ['email' => $customer->getEmail(), 'isUpgrade' => $isUpgrade]);

        $templateName = 'license_email.html';
        if ($isUpgrade) {
            $templateName = 'license_email_co_creator_upgrade.html';
        } elseif ($targetPlan === 'CO-CREATOR') {
            $templateName = 'license_email_co_creator.html';
        }

        $startEmail = microtime(true);
        $sent = $this->emailService->sendLicenseEmail($customer->getEmail(), $customer->getLicenseKey(), $this->logger, $customer->getName(), $templateName);
        $durationEmail = round((microtime(true) - $startEmail) * 1000, 2);
        $this->logPerformance('EmailService', 'sendLicenseEmail', $durationEmail);

        if ($sent) {
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

    /**
     * Determina o plano-alvo baseado no valor do pagamento e presença de assinatura.
     *
     * Regra de negócio:
     *   - 29.99 (MONTHLY_VALUE)      → CO-CREATOR (mensal, mesmo sem subscriptionId — compra via link de pagamento Pix)
     *   - Outro valor + subscriptionId → MONTHLY
     *   - Outro valor sem subscriptionId → LIFETIME (pagamento único)
     *
     * Esta detecção antecipada garante que um usuário LIFETIME comprando CO-CREATOR via
     * link Pix não seja erroneamente bloqueado como "pagamento duplicado".
     */
    private function resolveTargetPlan(float $value, ?string $subscriptionId): string
    {
        $coCreatorPrice = (float)($_ENV['MONTHLY_VALUE'] ?? 29.99);

        if (abs($value - $coCreatorPrice) < 0.01) {
            return 'CO-CREATOR';
        }

        if ($subscriptionId) {
            return 'MONTHLY';
        }

        return 'LIFETIME';
    }

    private function logPerformance(string $class, string $method, float $durationMs, string $additionalInfo = ''): void
    {
        $threshold = (float)($_ENV['PERFORMANCE_THRESHOLD_MS'] ?? 1000.0);
        $isAlert = $durationMs > $threshold;
        $level = $isAlert ? 'error' : 'info';
        $tag = $isAlert ? '[PERFORMANCE_ALERT]' : '[PERFORMANCE]';

        $message = "{$tag} {$class}::{$method}" . ($additionalInfo !== '' ? " ({$additionalInfo})" : '') . " took {$durationMs}ms";

        $this->logger->$level($message, [
            'type'        => 'performance',
            'class'       => $class,
            'method'      => $method,
            'duration_ms' => $durationMs,
            'alert'       => $isAlert,
        ]);
    }
}

