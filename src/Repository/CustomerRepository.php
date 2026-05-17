<?php

namespace App\Repository;

use App\Entity\Customer;
use App\Interfaces\Repositories\CustomerRepositoryInterface;
use Doctrine\ORM\EntityRepository;

class CustomerRepository extends EntityRepository implements CustomerRepositoryInterface
{
    public function findByEmail(string $email): ?Customer
    {
        return $this->findOneBy(['email' => $email]);
    }

    public function findByLicenseKey(string $licenseKey): ?Customer
    {
        return $this->findOneBy(['licenseKey' => $licenseKey]);
    }

    public function findPendingDeliveries(): array
    {
        return $this->findBy([
            'paymentStatus' => 'RECEIVED',
            'isLicenseDelivered' => false
        ]);
    }

    public function countPaidLifetimeCustomers(): int
    {
        return (int) $this->getEntityManager()
            ->createQuery('SELECT COUNT(c.id) FROM App\Entity\Customer c WHERE c.paymentStatus = :status AND c.plan = :plan')
            ->setParameter('status', 'RECEIVED')
            ->setParameter('plan', 'LIFETIME')
            ->getSingleScalarResult();
    }

    public function save(Customer $customer): void
    {
        $this->getEntityManager()->persist($customer);
        $this->getEntityManager()->flush();
    }
}
