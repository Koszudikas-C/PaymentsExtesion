<?php

namespace App\Repository;

use App\Entity\Feedback;
use App\Interfaces\Repositories\FeedbackRepositoryInterface;
use Doctrine\ORM\EntityRepository;

class FeedbackRepository extends EntityRepository implements FeedbackRepositoryInterface
{
    public function save(Feedback $feedback): void
    {
        $this->getEntityManager()->persist($feedback);
        $this->getEntityManager()->flush();
    }

    public function findById(string $id): ?Feedback
    {
        return $this->find($id);
    }
}
