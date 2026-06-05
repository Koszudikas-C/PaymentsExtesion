<?php

namespace App\Interfaces\Repositories;

use App\Entity\AuditLog;

interface AuditLogRepositoryInterface
{
    public function save(AuditLog $log): void;
    public function hasPaymentBeenProcessed(string $paymentId): bool;
}
