<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use App\Repository\AuditLogRepository;

#[ORM\Entity(repositoryClass: AuditLogRepository::class)]
#[ORM\Table(name: 'audit_logs')]
class AuditLog extends BaseEntity
{
    #[ORM\ManyToOne(targetEntity: Customer::class, inversedBy: 'auditLogs')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Customer $customer;

    #[ORM\Column(length: 100)]
    private string $action;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $details = null;

    public function __construct(Customer $customer, string $action, ?string $details = null)
    {
        parent::__construct();
        $this->customer = $customer;
        $this->action = $action;
        $this->details = $details;
    }

    public function getCustomer(): Customer { return $this->customer; }
    public function setCustomer(Customer $customer): self { $this->customer = $customer; return $this; }

    public function getAction(): string { return $this->action; }
    public function getDetails(): ?string { return $this->details; }
}
