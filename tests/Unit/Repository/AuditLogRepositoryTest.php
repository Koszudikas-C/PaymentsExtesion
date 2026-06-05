<?php

namespace Tests\Unit\Repository;

use App\Entity\AuditLog;
use App\Entity\Customer;
use App\Repository\AuditLogRepository;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\UnitOfWork;
use PHPUnit\Framework\TestCase;

class AuditLogRepositoryTest extends TestCase
{
    private $entityManager;
    private $classMetadata;
    private $repository;

    protected function setUp(): void
    {
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        
        $this->classMetadata = $this->createMock(ClassMetadata::class);
        $this->classMetadata->name = AuditLog::class;

        $this->repository = new AuditLogRepository($this->entityManager, $this->classMetadata);
    }

    public function testSave()
    {
        $customer = new Customer('T', 't@test.com', '1');
        $log = new AuditLog($customer, 'ACTION');

        $this->entityManager->expects($this->once())
            ->method('persist')
            ->with($log);
            
        $this->entityManager->expects($this->once())
            ->method('flush');

        $this->repository->save($log);
    }

    public function testHasPaymentBeenProcessed()
    {
        $qb = $this->createMock(\Doctrine\ORM\QueryBuilder::class);
        $query = $this->createMock(\Doctrine\ORM\Query::class);
        
        $this->entityManager->expects($this->once())
            ->method('createQueryBuilder')
            ->willReturn($qb);
            
        $qb->expects($this->any())->method('select')->willReturnSelf();
        $qb->expects($this->any())->method('from')->willReturnSelf();
        $qb->expects($this->any())->method('where')->willReturnSelf();
        $qb->expects($this->any())->method('setParameter')->willReturnSelf();
        $qb->expects($this->once())->method('getQuery')->willReturn($query);
        
        $query->expects($this->once())->method('getSingleScalarResult')->willReturn(1);
        
        $this->assertTrue($this->repository->hasPaymentBeenProcessed('PAY-123'));
    }
}
