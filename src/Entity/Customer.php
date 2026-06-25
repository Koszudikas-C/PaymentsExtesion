<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use App\Repository\CustomerRepository;
use InvalidArgumentException;

#[ORM\Entity(repositoryClass: CustomerRepository::class)]
#[ORM\Table(name: 'customers')]
#[ORM\HasLifecycleCallbacks]
class Customer extends BaseEntity
{
    #[ORM\Column(length: 255)]
    private string $name;

    #[ORM\Column(length: 255)]
    private string $email;

    #[ORM\Column(length: 50)]
    private string $phone;

    #[ORM\Column(length: 50, nullable: true, unique: true)]
    private ?string $licenseKey = null;

    #[ORM\Column(length: 50)]
    private string $paymentStatus = 'PENDING';

    #[ORM\Column(type: 'boolean')]
    private bool $isLicenseDelivered = false;

    #[ORM\Column(type: 'integer')]
    private int $deliveryFailureCount = 0;

    #[ORM\Column(length: 20)]
    private string $plan = 'PENDING';

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $subscriptionId = null;

    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?\DateTime $licenseExpiresAt = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $chromeIdentityId = null;

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $fallbackPlan = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $refreshTokenHash = null;

    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?\DateTime $refreshTokenExpiresAt = null;

    #[ORM\Column(length: 45, nullable: true)]
    private ?string $lastIpAddress = null;

    #[ORM\OneToMany(mappedBy: 'customer', targetEntity: AuditLog::class, cascade: ['persist', 'remove'])]
    private Collection $auditLogs;

    public function __construct(string $name, string $email, string $phone)
    {
        parent::__construct();
        $this->setName($name);
        $this->setEmail($email);
        $this->setPhone($phone);
        $this->auditLogs = new ArrayCollection();
        
        $this->recordAudit('CUSTOMER_CREATED', "Customer $name initialized.");
    }

    public function markAsPaid(string $externalId): void
    {
        if ($this->paymentStatus === 'RECEIVED') {
            return;
        }

        $this->paymentStatus = 'RECEIVED';
        $this->recordAudit('PAYMENT_RECEIVED', "Payment confirmed via Asaas (ID: $externalId)");
    }

    public function assignLicense(string $licenseKey): void
    {
        if ($this->licenseKey !== null && $this->licenseKey !== $licenseKey) {
            throw new \DomainException("Customer already has a different license assigned.");
        }

        $this->licenseKey = $licenseKey;
        $this->recordAudit('LICENSE_ASSIGNED', "License $licenseKey linked to customer.");
    }

    public function markLicenseAsDelivered(): void
    {
        $this->isLicenseDelivered = true;
        $this->deliveryFailureCount = 0;
        $this->recordAudit('LICENSE_DELIVERED', "License successfully sent to {$this->email}");
    }

    public function recordDeliveryFailure(string $reason): void
    {
        $this->deliveryFailureCount++;
        $this->recordAudit('DELIVERY_FAILED', "Failed attempt #{$this->deliveryFailureCount}. Reason: $reason");
    }

    public function canRetryDelivery(): bool
    {
        return !$this->isLicenseDelivered && $this->deliveryFailureCount < 10;
    }

    public function recordAudit(string $action, string $details): void
    {
        $this->auditLogs->add(new AuditLog($this, $action, $details));
    }

    // Getters (Mantidos para leitura, Setters tornados protegidos/privados quando possível)

    public function getName(): string { return $this->name; }
    private function setName(string $name): void {
        if (empty($name)) throw new InvalidArgumentException("Name cannot be empty");
        $this->name = $name;
    }

    public function getEmail(): string { return $this->email; }
    private function setEmail(string $email): void {
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) throw new InvalidArgumentException("Invalid email format");
        $this->email = $email;
    }

    public function getPhone(): string { return $this->phone; }
    private function setPhone(string $phone): void { $this->phone = $phone; }

    public function getLicenseKey(): ?string { return $this->licenseKey; }
    public function getPaymentStatus(): string { return $this->paymentStatus; }
    public function isLicenseDelivered(): bool { return $this->isLicenseDelivered; }
    public function getDeliveryFailureCount(): int { return $this->deliveryFailureCount; }

    public function getPlan(): string { return $this->plan; }
    public function setPlan(string $plan): void { $this->plan = $plan; }

    public function getSubscriptionId(): ?string { return $this->subscriptionId; }
    public function setSubscriptionId(?string $subscriptionId): void { $this->subscriptionId = $subscriptionId; }

    public function getLicenseExpiresAt(): ?\DateTime { return $this->licenseExpiresAt; }
    public function setLicenseExpiresAt(?\DateTime $licenseExpiresAt): void { $this->licenseExpiresAt = $licenseExpiresAt; }

    public function getFallbackPlan(): ?string { return $this->fallbackPlan; }
    public function setFallbackPlan(?string $fallbackPlan): void { $this->fallbackPlan = $fallbackPlan; }

    public function getChromeIdentityId(): ?string { return $this->chromeIdentityId; }
    public function setChromeIdentityId(?string $chromeIdentityId): void { $this->chromeIdentityId = $chromeIdentityId; }

    public function getRefreshTokenHash(): ?string { return $this->refreshTokenHash; }
    public function setRefreshTokenHash(?string $refreshTokenHash): void { $this->refreshTokenHash = $refreshTokenHash; }

    public function getRefreshTokenExpiresAt(): ?\DateTime { return $this->refreshTokenExpiresAt; }
    public function setRefreshTokenExpiresAt(?\DateTime $refreshTokenExpiresAt): void { $this->refreshTokenExpiresAt = $refreshTokenExpiresAt; }

    public function getLastIpAddress(): ?string { return $this->lastIpAddress; }
    public function setLastIpAddress(?string $lastIpAddress): void { $this->lastIpAddress = $lastIpAddress; }

    public function isLicenseActive(): bool
    {
        // Licenças LIFETIME são vitalícias e não expiram
        if ($this->plan === 'LIFETIME') {
            return true;
        }
        
        // Licenças dos planos MONTHLY e CO-CREATOR possuem prazo de expiração
        if ($this->plan === 'MONTHLY' || $this->plan === 'CO-CREATOR') {
            if ($this->licenseExpiresAt === null) {
                return false;
            }
            if ($this->licenseExpiresAt > new \DateTime('now')) {
                return true;
            }
            // Se expirou e o usuário tinha LIFETIME anteriormente (como fallback):
            if ($this->fallbackPlan === 'LIFETIME') {
                $this->plan = 'LIFETIME';
                $this->subscriptionId = null;
                $this->licenseExpiresAt = null;
                $this->recordAudit('PLAN_REVERTED', "Subscription expired. Reverted to previous LIFETIME plan.");
                return true;
            }
            return false;
        }

        if ($this->licenseExpiresAt === null) {
            return false;
        }
        return $this->licenseExpiresAt > new \DateTime('now');
    }

    #[ORM\PreUpdate]
    public function onPreUpdate(): void { $this->dateUpdated = new \DateTime('now'); }

    public function getAuditLogs(): Collection { return $this->auditLogs; }
}
