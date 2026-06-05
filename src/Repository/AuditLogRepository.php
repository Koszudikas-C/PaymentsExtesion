<?php

namespace App\Repository;

use App\Entity\AuditLog;
use App\Interfaces\Repositories\AuditLogRepositoryInterface;
use Doctrine\ORM\EntityRepository;

class AuditLogRepository extends EntityRepository implements AuditLogRepositoryInterface
{
    public function save(AuditLog $log): void
    {
        $this->getEntityManager()->persist($log);
        $this->getEntityManager()->flush();
    }

    public function hasPaymentBeenProcessed(string $paymentId): bool
    {
        $count = (int) $this->createQueryBuilder('a')
            ->select('COUNT(a.id)')
            ->where('a.details LIKE :paymentId')
            ->setParameter('paymentId', "%$paymentId%")
            ->getQuery()
            ->getSingleScalarResult();
        return $count > 0;
    }
}
