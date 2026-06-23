<?php
require_once __DIR__ . '/vendor/autoload.php';

use App\Config\Container;
use Doctrine\ORM\EntityManagerInterface;
use App\Entity\Customer;
use App\Entity\Notepad;

// Inicializa o ambiente da aplicação real (Doctrine, Dotenv, etc)
$dotenv = \Dotenv\Dotenv::createImmutable(__DIR__);
$dotenv->safeLoad();

$container = Container::build();
$entityManager = $container->get(EntityManagerInterface::class);

echo "Iniciando a criação de dados mockados no banco...\n";

// Puxa o primeiro Customer do banco de dados (que provavelmente é a sua conta de teste com a extensão)
$customerRepository = $entityManager->getRepository(Customer::class);
$customer = $customerRepository->findOneBy([]);

if (!$customer) {
    echo "Erro: Nenhum cliente encontrado no banco de dados. Cadastre um usuário primeiro.\n";
    exit(1);
}

// Garante que o plano está apto a sincronizar
$customer->setPlan('CO-CREATOR');
$entityManager->persist($customer);
$entityManager->flush();

echo "Injetando notas para o Cliente ID: " . $customer->getId() . " (Email: " . $customer->getEmail() . ")\n";

echo "Limpando anotações antigas deste cliente para evitar duplicidade...\n";
$entityManager->createQuery('DELETE FROM App\Entity\Notepad n WHERE n.customer = :customer')
    ->setParameter('customer', $customer)
    ->execute();

$totalNotes = 10000;
$batchSize = 1000;
$count = 0;

for ($i = 1; $i <= $totalNotes; $i++) {
    // Tenta encontrar se a nota mockada já existe para não duplicar infinitamente se rodar de novo
    $jid = "55119" . str_pad((string)$i, 8, '0', STR_PAD_LEFT) . "@c.us";
    $ownerJid = "5511999999999@c.us"; // Número fictício do dono da conta para testes locais
    
    $notepad = new Notepad($customer, $jid, $ownerJid);
    $notepad->setNote("TESTE DE ESTRESSE: Anotação do cliente " . $i . ". Essa nota foi gerada automaticamente para testar o particionamento (chunking) e o fura-fila da extensão da AIFreelas. Data: " . date('Y-m-d H:i:s'));
    $notepad->setDateUpdated(new \DateTime());
    
    $entityManager->persist($notepad);
    $count++;
    
    if (($count % $batchSize) === 0) {
        $entityManager->flush();
        $entityManager->clear(); // Limpa a RAM do Doctrine
        $customer = $entityManager->getRepository(Customer::class)->find($customer->getId()); // Refaz a conexão da entidade
        echo "Lote inserido: $count / $totalNotes\n";
    }
}

$entityManager->flush();
echo "\nSucesso! 10.000 notas atreladas ao seu usuário. Você já pode abrir a extensão e testar a performance absurda de O(1) e o fatiamento em tempo real.\n";
