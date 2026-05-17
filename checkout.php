<?php

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use App\Config\Container;
use App\Controllers\CheckoutController;
use Dotenv\Dotenv;

// Carrega variáveis do ambiente (.env)
$dotenv = Dotenv::createImmutable(__DIR__);
$dotenv->load();

// Constrói o container PHP-DI
$container = Container::build();

// Resolve o controller e delega o processamento da requisição com segurança estrita
$controller = $container->get(CheckoutController::class);
$controller->handleRequest();
