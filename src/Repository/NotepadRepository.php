<?php

namespace App\Repository;

use App\Entity\Notepad;
use App\Entity\Customer;
use App\Interfaces\Repositories\NotepadRepositoryInterface;
use Doctrine\ORM\EntityRepository;

class NotepadRepository extends EntityRepository implements NotepadRepositoryInterface
{
    /**
     * Encontra uma anotação específica por cliente e JID.
     *
     * @param Customer $customer
     * @param string $jid
     * @return Notepad|null
     */
    public function findOneByCustomerAndJid(Customer $customer, string $jid): ?Notepad
    {
        return $this->findOneBy(['customer' => $customer, 'jid' => $jid]);
    }
}
