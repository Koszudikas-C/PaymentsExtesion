<?php

namespace Tests\Unit\Repository;

use App\Entity\Customer;
use App\Repository\CustomerRepository;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Query;
use Doctrine\ORM\AbstractQuery;
use Doctrine\ORM\UnitOfWork;
use PHPUnit\Framework\TestCase;

class CustomerRepositoryTest extends TestCase
{
    private $entityManager;
    private $classMetadata;
    private $repository;

    protected function setUp(): void
    {
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        
        $this->classMetadata = $this->createMock(ClassMetadata::class);
        $this->classMetadata->name = Customer::class;

        $this->repository = new CustomerRepository($this->entityManager, $this->classMetadata);
    }

    public function testFindById()
    {
        $customer = new Customer('Test', 't@t.com', '123');
        $this->entityManager->expects($this->once())
            ->method('find')
            ->with(Customer::class, 'uuid-123', null, null)
            ->willReturn($customer);

        $this->assertSame($customer, $this->repository->findById('uuid-123'));
    }

    public function testSave()
    {
        $customer = new Customer('Test', 't@t.com', '123');
        
        $this->entityManager->expects($this->once())
            ->method('persist')
            ->with($customer);
            
        $this->entityManager->expects($this->once())
            ->method('flush');

        $this->repository->save($customer);
    }

    public function testCountPaidLifetimeCustomers()
    {
        $query = $this->createMock(Query::class);
        $query->expects($this->exactly(2))
            ->method('setParameter')
            ->willReturnCallback(function($key, $value) use ($query) {
                return $query;
            });
            
        $query->expects($this->once())
            ->method('getSingleScalarResult')
            ->willReturn(10);

        $this->entityManager->expects($this->once())
            ->method('createQuery')
            ->with('SELECT COUNT(c.id) FROM App\Entity\Customer c WHERE c.paymentStatus = :status AND c.plan = :plan')
            ->willReturn($query);

        $this->assertEquals(10, $this->repository->countPaidLifetimeCustomers());
    }

    // Since we cannot easily test findOneBy and findBy from EntityRepository without mocking UnitOfWork completely,
    // we just use Reflection to set a mock UnitOfWork, or since they are inherited methods, we don't strictly need to test 
    // the internal logic of Doctrine, but we can mock UnitOfWork to at least test that our parameters are passed correctly.
    public function testFindMethodsPassingCorrectCriteria()
    {
        $uow = $this->createMock(UnitOfWork::class);
        
        // EntityPersister is what Doctrine uses internally
        $persister = $this->createMock(\Doctrine\ORM\Persisters\Entity\EntityPersister::class);
        
        $uow->method('getEntityPersister')->willReturn($persister);
        $this->entityManager->method('getUnitOfWork')->willReturn($uow);

        $customer = new Customer('T', 't@test.com', '1');

        // findByEmail
        $persister->expects($this->exactly(4))
            ->method('load')
            ->willReturnCallback(function($criteria) use ($customer) {
                if (isset($criteria['email']) && $criteria['email'] === 't@test.com') return $customer;
                if (isset($criteria['subscriptionId']) && $criteria['subscriptionId'] === 'sub_123') return $customer;
                if (isset($criteria['chromeIdentityId']) && $criteria['chromeIdentityId'] === 'chrome_1') return $customer;
                if (isset($criteria['licenseKey']) && $criteria['licenseKey'] === 'LIC') return $customer;
                return null;
            });

        $this->assertSame($customer, $this->repository->findByEmail('t@test.com'));
        $this->assertSame($customer, $this->repository->findBySubscriptionId('sub_123'));
        $this->assertSame($customer, $this->repository->findByChromeIdentityId('chrome_1'));
        $this->assertSame($customer, $this->repository->findByLicenseKey('LIC'));
    }

    public function testFindPendingDeliveries()
    {
        $uow = $this->createMock(UnitOfWork::class);
        $persister = $this->createMock(\Doctrine\ORM\Persisters\Entity\EntityPersister::class);
        $uow->method('getEntityPersister')->willReturn($persister);
        $this->entityManager->method('getUnitOfWork')->willReturn($uow);

        $customer = new Customer('T', 't@test.com', '1');

        $persister->expects($this->once())
            ->method('loadAll')
            ->willReturnCallback(function($criteria) use ($customer) {
                if ($criteria['paymentStatus'] === 'RECEIVED' && $criteria['isLicenseDelivered'] === false) {
                    return [$customer];
                }
                return [];
            });

        $result = $this->repository->findPendingDeliveries();
        $this->assertCount(1, $result);
        $this->assertSame($customer, $result[0]);
    }
}
