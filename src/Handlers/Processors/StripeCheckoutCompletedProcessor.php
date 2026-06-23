<?php

namespace App\Handlers\Processors;

use App\Interfaces\StripeWebhookProcessorInterface;
use App\Interfaces\EmailServiceInterface;
use App\Interfaces\LicenseServiceInterface;
use App\Interfaces\PromotionServiceInterface;
use App\Interfaces\LandingPageSyncServiceInterface;
use App\Interfaces\Repositories\CustomerRepositoryInterface;
use App\Interfaces\Repositories\AuditLogRepositoryInterface;
use App\Entity\Customer;
use Monolog\Logger;
use Stripe\Event;
use Stripe\Checkout\Session;

class StripeCheckoutCompletedProcessor implements StripeWebhookProcessorInterface
{
    private EmailServiceInterface $emailService;
    private LicenseServiceInterface $licenseService;
    private CustomerRepositoryInterface $customerRepository;
    private AuditLogRepositoryInterface $auditLogRepository;
    private Logger $logger;
    private string $licenseSalt;
    private PromotionServiceInterface $promotionService;
    private LandingPageSyncServiceInterface $syncService;

    public function __construct(
        EmailServiceInterface $emailService,
        LicenseServiceInterface $licenseService,
        CustomerRepositoryInterface $customerRepository,
        AuditLogRepositoryInterface $auditLogRepository,
        Logger $logger,
        string $licenseSalt,
        PromotionServiceInterface $promotionService,
        LandingPageSyncServiceInterface $syncService
    ) {
        $this->emailService = $emailService;
        $this->licenseService = $licenseService;
        $this->customerRepository = $customerRepository;
        $this->auditLogRepository = $auditLogRepository;
        $this->logger = $logger;
        $this->licenseSalt = $licenseSalt;
        $this->promotionService = $promotionService;
        $this->syncService = $syncService;
    }

    public function process(Event $event): void
    {
        /** @var Session $session */
        $session = $event->data->object;
        
        // Apenas processa se estiver pago
        if ($session->payment_status !== 'paid') {
            $this->logger->info('Stripe checkout session not paid, ignoring.', ['session_id' => $session->id]);
            return;
        }

        $paymentId = $session->payment_intent ?? $session->id;
        $email = $session->customer_details->email ?? $session->customer_email ?? '';
        $name = $session->customer_details->name ?? 'Usuário Internacional';
        $whatsapp = $session->customer_details->phone ?? 'unknown';
        $chromeIdentityId = $session->client_reference_id ?? null;
        $value = $session->amount_total / 100; // Stripe passa valor em centavos
        $subscriptionId = $session->subscription ?? null;

        if (empty($email)) {
            $this->logger->error('Stripe webhook missing customer email', ['session_id' => $session->id]);
            return;
        }

        try {
            if ($this->auditLogRepository->hasPaymentBeenProcessed($paymentId)) {
                $this->logger->info('Stripe payment already processed.', ['payment_id' => $paymentId]);
                return;
            }
        } catch (\Throwable $e) {}

        $targetPlan = $this->resolveTargetPlan($value, $subscriptionId);
        $isUpgrade = false;

        $customer = null;
        $dbFailed = false;
        $dbErrorMsg = '';

        try {
            if ($subscriptionId) {
                $customer = $this->customerRepository->findBySubscriptionId($subscriptionId);
            }

            if (!$customer) {
                $customer = $this->customerRepository->findByEmail($email);
            }
        } catch (\Throwable $e) {
            $dbFailed = true;
            $dbErrorMsg = 'Lookup failed: ' . $e->getMessage();
        }

        if (!$customer) {
            $customer = new Customer($name, $email, $whatsapp);
            if ($chromeIdentityId) {
                $customer->setChromeIdentityId($chromeIdentityId);
            }
        } else {
            if ($customer->getPaymentStatus() === 'RECEIVED') {
                if ($customer->getPlan() === 'LIFETIME' && $targetPlan === 'LIFETIME') {
                    $customer->recordAudit('DOUBLE_PAYMENT_DETECTED', "Double payment received from Stripe. ID: $paymentId.");
                    if (!$dbFailed) {
                        $this->customerRepository->save($customer);
                    }
                    return;
                } elseif ($customer->getPlan() === 'MONTHLY' || $customer->getPlan() === 'CO-CREATOR') {
                    if ($subscriptionId && $customer->getSubscriptionId() === $subscriptionId) {
                        $prevExpires = $customer->getLicenseExpiresAt();
                        $newExpires = $prevExpires ? clone $prevExpires : new \DateTime('now');
                        $newExpires->modify('+30 days');
                        $customer->setLicenseExpiresAt($newExpires);
                        if (!$dbFailed) {
                            $this->customerRepository->save($customer);
                        }
                        return;
                    }
                }
            }
        }

        $customer->markAsPaid($paymentId);
        $customer->setSystemAccess();

        $oldPlan = $customer->getPlan();

        if ($targetPlan === 'CO-CREATOR' || $targetPlan === 'MONTHLY') {
            if ($oldPlan === 'LIFETIME') {
                $customer->setFallbackPlan('LIFETIME');
                $isUpgrade = true;
            }

            $customer->setPlan($targetPlan);
            if ($subscriptionId) {
                $customer->setSubscriptionId($subscriptionId);
            }
            $customer->setLicenseExpiresAt((new \DateTime('now'))->modify('+33 days'));
        } else {
            $customer->setPlan('LIFETIME');
            $customer->setSubscriptionId(null);
            $customer->setLicenseExpiresAt(null);
            $customer->setFallbackPlan(null);
        }

        if (!$customer->getLicenseKey()) {
            $generatedLicense = $this->licenseService->generateLicense($email, $this->licenseSalt);
            $customer->assignLicense($generatedLicense);
        }

        $this->attemptLicenseDelivery($customer, $targetPlan, $isUpgrade);

        if (!$dbFailed) {
            try {
                $this->customerRepository->save($customer);
                $lifetimeCount = $this->customerRepository->countPaidLifetimeCustomers();
                $this->syncService->notifySale($customer, $this->logger, $lifetimeCount);
                $this->promotionService->handlePromotionGoal($customer, $this->logger);
            } catch (\Throwable $e) {}
        }
    }

    private function resolveTargetPlan(float $value, ?string $subscriptionId): string
    {
        $coCreatorPrice = (float)($_ENV['MONTHLY_VALUE_USD'] ?? 5.99); // Valor em dólar para Co-Creator
        if (abs($value - $coCreatorPrice) < 0.01) {
            return 'CO-CREATOR';
        }
        if ($subscriptionId) {
            return 'MONTHLY';
        }
        return 'LIFETIME';
    }

    private function attemptLicenseDelivery(Customer $customer, string $targetPlan, bool $isUpgrade): void
    {
        if (!$isUpgrade && (!$customer->getLicenseKey() || $customer->isLicenseDelivered())) {
            return;
        }

        $templateName = 'license_email.html';
        if ($isUpgrade) {
            $templateName = 'license_email_co_creator_upgrade.html';
        } elseif ($targetPlan === 'CO-CREATOR') {
            $templateName = 'license_email_co_creator.html';
        }

        $sent = $this->emailService->sendLicenseEmail($customer->getEmail(), $customer->getLicenseKey(), $this->logger, $customer->getName(), $templateName, 'Export Chat WhatsApp');
        if ($sent) {
            $customer->markLicenseAsDelivered();
        }
    }
}
