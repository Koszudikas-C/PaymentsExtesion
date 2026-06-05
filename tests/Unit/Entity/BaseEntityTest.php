<?php

namespace Tests\Unit\Entity;

use App\Entity\BaseEntity;
use PHPUnit\Framework\TestCase;

class DummyEntity extends BaseEntity
{
    public string $publicProp = '';
    public string $publicProp2 = 'test';
}

class BaseEntityTest extends TestCase
{
    public function testConstructorInitializesDatesAndId()
    {
        $entity = new DummyEntity();

        $this->assertNotEmpty($entity->getId(), 'ID deve ser gerado pelo construtor');
        $this->assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $entity->getId(), 'ID deve ser um UUID v4 válido');

        $this->assertInstanceOf(\DateTime::class, $entity->getDateCreated());
        $this->assertInstanceOf(\DateTime::class, $entity->getDateUpdated());
        $this->assertInstanceOf(\DateTime::class, $entity->getSystemAccess());
    }

    public function testSetSystemAccessUpdatesDate()
    {
        $entity = new DummyEntity();
        
        // Simular que foi criado no passado
        $oldDate = (new \DateTime('now'))->modify('-1 day');
        
        $reflection = new \ReflectionClass(BaseEntity::class);
        $systemAccessProp = $reflection->getProperty('systemAccess');
        $systemAccessProp->setValue($entity, $oldDate);
        
        $this->assertEquals($oldDate->format('Y-m-d'), $entity->getSystemAccess()->format('Y-m-d'));

        $entity->setSystemAccess();

        $this->assertEquals((new \DateTime('now'))->format('Y-m-d'), $entity->getSystemAccess()->format('Y-m-d'));
    }

    public function testIsEmpty()
    {
        $entity = new DummyEntity();
        
        // DummyEntity has publicProp2 = 'test', so it's not empty
        $this->assertFalse($entity->isEmpty(), 'Entidade não deve estar vazia por causa da propriedade pública com valor');
        
        $entity->publicProp2 = '';
        
        $this->assertTrue($entity->isEmpty(), 'Entidade deve estar vazia quando todas as propriedades públicas estão vazias');
    }
}
