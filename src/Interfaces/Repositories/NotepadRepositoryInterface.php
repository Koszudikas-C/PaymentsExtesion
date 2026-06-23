<?php

namespace App\Interfaces\Repositories;

use App\Entity\Notepad;
use App\Entity\Customer;

interface NotepadRepositoryInterface
{
    /**
     * Encontra uma anotação específica por cliente e JID.
     *
     * @param Customer $customer
     * @param string $jid
     * @return Notepad|null
     */
    public function findOneByCustomerAndJid(Customer $customer, string $jid): ?Notepad;
}
