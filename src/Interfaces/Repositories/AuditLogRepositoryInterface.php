<?php

namespace App\Interfaces\Repositories;

use App\Entity\AuditLog;

interface AuditLogRepositoryInterface
{
    public function save(AuditLog $log): void;
}
