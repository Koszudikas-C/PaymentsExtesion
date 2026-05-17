<?php

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use Dotenv\Dotenv;
use App\Config\Container;
use App\Controllers\CheckoutController;
use App\Controllers\WebhookController;
use Bramus\Router\Router;

// Carrega variáveis do ambiente (.env)
$dotenv = Dotenv::createImmutable(__DIR__);
$dotenv->load();

// Instancia o roteador robusto
$router = new Router();

// Constrói o container PHP-DI
$container = Container::build();

// Rota de Checkout Limpa: /checkout
$router->match('GET|POST', '/checkout', function () use ($container) {
    $controller = $container->get(CheckoutController::class);
    $controller->handleRequest();
});

// Rota de Webhook Limpa: /webhook
$router->post('/webhook', function () use ($container) {
    $controller = $container->get(WebhookController::class);
    $controller->handleRequest();
});

// Trata requisições OPTIONS globais para CORS
$router->options('/.*', function () {
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, X-Requested-With, Asaas-Access-Token');
    header_remove('X-Powered-By');
    exit(0);
});

// Rota Fallback 404
$router->set404(function () {
    header('Content-Type: application/json; charset=utf-8');
    header_remove('X-Powered-By');
    http_response_code(404);
    echo json_encode([
        'status' => 'error',
        'message' => 'Endpoint não encontrado.'
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
});

// Executa o roteamento
$router->run();
