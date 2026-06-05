<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use App\Repository\FeedbackRepository;

#[ORM\Entity(repositoryClass: FeedbackRepository::class)]
#[ORM\Table(name: 'feedbacks')]
#[ORM\HasLifecycleCallbacks]
class Feedback extends BaseEntity
{
    #[ORM\ManyToOne(targetEntity: Customer::class)]
    #[ORM\JoinColumn(name: 'customer_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?Customer $customer = null;

    #[ORM\Column(type: 'string', length: 50)]
    private string $type; // e.g. EVALUATION, FEATURE_REQUEST

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $rating = null;

    #[ORM\Column(type: 'text')]
    private string $message;

    public function __construct(string $type, string $message, ?int $rating = null, ?Customer $customer = null)
    {
        parent::__construct();
        $this->type = $type;
        $this->message = $message;
        $this->rating = $rating;
        $this->customer = $customer;
    }

    public function getCustomer(): ?Customer
    {
        return $this->customer;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function getRating(): ?int
    {
        return $this->rating;
    }

    public function getMessage(): string
    {
        return $this->message;
    }

    #[ORM\PreUpdate]
    public function onPreUpdate(): void
    {
        $this->dateUpdated = new \DateTime('now');
    }
}
