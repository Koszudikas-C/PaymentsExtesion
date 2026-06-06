<?php

require __DIR__ . '/vendor/autoload.php';

use Dotenv\Dotenv;
use App\Config\Container;
use Doctrine\ORM\EntityManagerInterface;

$envFile = file_exists(__DIR__ . '/.env.development') ? '.env.development' : '.env';
$dotenv = Dotenv::createImmutable(__DIR__, $envFile);
$dotenv->load();

$container = Container::build();
$em = $container->get(EntityManagerInterface::class);
$conn = $em->getConnection();

try {
    $conn->executeStatement("ALTER TABLE customers DROP INDEX UNIQ_62534E213453DE0C");
    echo "Index UNIQ_62534E213453DE0C dropped successfully.\n";
} catch (\Exception $e) {
    echo "Error dropping index: " . $e->getMessage() . "\n";
}
