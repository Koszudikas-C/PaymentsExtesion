<?php

namespace Tests\Integration\Repository;

use App\Entity\Customer;
use App\Interfaces\Repositories\CustomerRepositoryInterface;
use Tests\DatabaseTestCase;

class CustomerRepositoryTest extends DatabaseTestCase
{
    private CustomerRepositoryInterface $repository;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = $this->entityManager->getRepository(Customer::class);
    }

    public function testSaveAndFindByEmail()
    {
        $customer = new Customer('Test User', 'test@example.com', '999999999');

        $this->repository->save($customer);

        $found = $this->repository->findByEmail('test@example.com');
        
        $this->assertNotNull($found);
        $this->assertEquals('Test User', $found->getName());
        $this->assertEquals($customer->getId(), $found->getId());
    }

    public function testFindPendingDeliveries()
    {
        $c1 = new Customer('Paid but not delivered', 'c1@example.com', '1');
        $c1->markAsPaid('ASAAS-1');
        
        $c2 = new Customer('Delivered', 'c2@example.com', '2');
        $c2->markAsPaid('ASAAS-2');
        $c2->markLicenseAsDelivered();

        $this->repository->save($c1);
        $this->repository->save($c2);

        $pending = $this->repository->findPendingDeliveries();

        $this->assertCount(1, $pending);
        $this->assertEquals('c1@example.com', $pending[0]->getEmail());
    }
}
