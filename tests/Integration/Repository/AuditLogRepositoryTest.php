<?php

namespace Tests\Integration\Repository;

use App\Entity\Customer;
use App\Entity\AuditLog;
use App\Interfaces\Repositories\AuditLogRepositoryInterface;
use Tests\DatabaseTestCase;

class AuditLogRepositoryTest extends DatabaseTestCase
{
    private AuditLogRepositoryInterface $repository;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = $this->entityManager->getRepository(AuditLog::class);
    }

    public function testSaveAuditLog()
    {
        $customer = new Customer('Audit User', 'audit@example.com', '000');
        
        $this->entityManager->persist($customer);
        $this->entityManager->flush();

        $log = new AuditLog($customer, 'TEST_EVENT', 'Details here');
        $this->repository->save($log);

        $this->assertNotNull($log->getId());
        
        $dbLog = $this->entityManager->find(AuditLog::class, $log->getId());
        $this->assertEquals('TEST_EVENT', $dbLog->getAction());
        $this->assertEquals($customer->getId(), $dbLog->getCustomer()->getId());
    }
}
