<?php

namespace Tests\Unit\Entity;

use App\Entity\BaseEntity;
use PHPUnit\Framework\TestCase;

class BaseEntityAdditionalTest extends TestCase
{
    public function testGenerateUuidReturnsValidUuid()
    {
        $reflection = new \ReflectionClass(BaseEntity::class);
        $method = $reflection->getMethod('generateUuid');
        
        $dummy = $this->createMock(BaseEntity::class);
        $uuid = $method->invoke($dummy);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $uuid);
    }
    public function testSetDateUpdatedUpdatesDate()
    {
        $entity = new class extends BaseEntity {};
        $newDate = new \DateTime('2020-01-01');
        $entity->setDateUpdated($newDate);
        $this->assertSame($newDate, $entity->getDateUpdated());
    }

}
