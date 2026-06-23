<?php
require __DIR__ . '/vendor/autoload.php';

use Dotenv\Dotenv;
use App\Config\Container;
use App\Entity\Notepad;

$dotenv = Dotenv::createImmutable(__DIR__);
$dotenv->load();

$container = Container::build();
$entityManager = $container->get(\Doctrine\ORM\EntityManagerInterface::class);

$notepadRepo = $entityManager->getRepository(Notepad::class);
$notepads = $notepadRepo->findAll();

echo "Total notepads: " . count($notepads) . "\n";
foreach ($notepads as $n) {
    echo "ID: " . $n->getId() . " | Customer: " . $n->getCustomer()->getId() . " | JID: " . $n->getJid() . " | Note: " . substr($n->getNote(), 0, 20) . " | Date: " . $n->getDateUpdated()->format('Y-m-d H:i:s') . "\n";
}
