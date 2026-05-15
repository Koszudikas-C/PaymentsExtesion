<?php

namespace Tests;

use App\Config\Container;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use PHPUnit\Framework\TestCase;

abstract class DatabaseTestCase extends TestCase
{
    protected EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            $this->markTestSkipped('A extensão pdo_sqlite não está habilitada neste ambiente. Os testes de integração (banco de dados) foram pulados.');
        }

        if (file_exists(__DIR__ . '/../.env')) {
            $dotenv = \Dotenv\Dotenv::createImmutable(__DIR__ . '/../');
            $dotenv->safeLoad();
        }

        $container = Container::build();
        $this->entityManager = $container->get(EntityManagerInterface::class);

        $schemaTool = new SchemaTool($this->entityManager);
        $metadata = $this->entityManager->getMetadataFactory()->getAllMetadata();
        $schemaTool->createSchema($metadata);
    }

    protected function tearDown(): void
    {
        if (isset($this->entityManager)) {
            $this->entityManager->close();
            unset($this->entityManager);
        }
    }
}
