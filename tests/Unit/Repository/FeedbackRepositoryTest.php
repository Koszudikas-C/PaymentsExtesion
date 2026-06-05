<?php

namespace Tests\Unit\Repository;

use App\Entity\Feedback;
use App\Entity\Customer;
use App\Repository\FeedbackRepository;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\UnitOfWork;
use PHPUnit\Framework\TestCase;

class FeedbackRepositoryTest extends TestCase
{
    private $entityManager;
    private $classMetadata;
    private $repository;

    protected function setUp(): void
    {
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        
        $this->classMetadata = $this->createMock(ClassMetadata::class);
        $this->classMetadata->name = Feedback::class;

        $this->repository = new FeedbackRepository($this->entityManager, $this->classMetadata);
    }

    public function testSave()
    {
        $customer = new Customer('T', 't@test.com', '1');
        $feedback = new Feedback('TEST', 'Msg', 5, $customer);

        $this->entityManager->expects($this->once())
            ->method('persist')
            ->with($feedback);
            
        $this->entityManager->expects($this->once())
            ->method('flush');

        $this->repository->save($feedback);
    }

    public function testFindById()
    {
        $customer = new Customer('T', 't@test.com', '1');
        $feedback = new Feedback('TEST', 'Msg', 5, $customer);
        
        $this->entityManager->expects($this->once())
            ->method('find')
            ->with(Feedback::class, 'uuid-123', null, null)
            ->willReturn($feedback);

        $this->assertSame($feedback, $this->repository->findById('uuid-123'));
    }
}
