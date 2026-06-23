<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use InvalidArgumentException;

use App\Repository\NotepadRepository;

#[ORM\Entity(repositoryClass: NotepadRepository::class)]
#[ORM\Table(name: 'notepads')]
#[ORM\UniqueConstraint(name: 'customer_owner_jid_unique', columns: ['customer_id', 'owner_jid', 'jid'])]
class Notepad extends BaseEntity
{
    #[ORM\ManyToOne(targetEntity: Customer::class)]
    #[ORM\JoinColumn(name: 'customer_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private Customer $customer;

    #[ORM\Column(type: 'string', length: 100, nullable: false)]
    private string $jid;

    #[ORM\Column(name: 'owner_jid', type: 'string', length: 100, nullable: true)]
    private ?string $ownerJid = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $note = null;

    public function __construct(Customer $customer, string $jid, ?string $ownerJid = null)
    {
        parent::__construct();
        $this->customer = $customer;
        $this->jid = $jid;
        $this->ownerJid = $ownerJid;
    }

    public function getCustomer(): Customer
    {
        return $this->customer;
    }

    public function getJid(): string
    {
        return $this->jid;
    }

    public function getNote(): ?string
    {
        return $this->note;
    }

    public function setNote(?string $note): self
    {
        $this->note = $note;
        return $this;
    }

    public function getOwnerJid(): ?string
    {
        return $this->ownerJid;
    }

    public function setOwnerJid(?string $ownerJid): self
    {
        $this->ownerJid = $ownerJid;
        return $this;
    }
}
