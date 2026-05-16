<?php

require __DIR__ . '/../vendor/autoload.php';

use Dotenv\Dotenv;
use App\Config\Container;
use Doctrine\ORM\EntityManagerInterface;

$dotenv = Dotenv::createImmutable(__DIR__ . '/../');
$dotenv->load();

$container = Container::build();

try {
    echo "--- Teste de Conexao com Banco de Dados ---\n";

    /** @var EntityManagerInterface $em */
    $em = $container->get(EntityManagerInterface::class);

    $connection = $em->getConnection();

    echo "Driver: " . ($connection->getParams()['driver'] ?? 'unknown') . "\n";

    // Tenta uma query simples para forçar a conexão
    $result = $connection->executeQuery('SELECT 1')->fetchOne();

    if ($result == 1) {
        echo "Sucesso: Conectado ao banco de dados e query executada com exito.\n";
    }
} catch (\Exception $e) {
    echo "ERRO: " . $e->getMessage() . "\n";
    echo "Trace: " . $e->getTraceAsString() . "\n";
}
