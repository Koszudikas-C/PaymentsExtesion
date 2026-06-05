<?php

namespace App\Interfaces\Repositories;

use App\Entity\Feedback;

interface FeedbackRepositoryInterface
{
    public function save(Feedback $feedback): void;
    public function findById(string $id): ?Feedback;
}
