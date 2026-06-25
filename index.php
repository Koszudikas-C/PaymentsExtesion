<?php

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use Dotenv\Dotenv;
use App\Config\Container;
use App\Controllers\CheckoutController;
use App\Controllers\WebhookController;
use App\Controllers\ActivationController;
use App\Controllers\VerificationController;
use App\Controllers\DeactivationController;
use App\Controllers\AuthController;
use App\Controllers\FeedbackController;
use App\Controllers\CampaignController;
use App\Controllers\HealthCheckController;
use App\Controllers\NotepadController;
use Bramus\Router\Router;

// Carrega variáveis do ambiente (.env)
$dotenv = Dotenv::createImmutable(__DIR__);
$dotenv->load();


// Configuração Global de CORS e resposta imediata para requisições de preflight (OPTIONS)
$allowedOrigins = isset($_ENV['CORS_ALLOWED_ORIGINS']) ? explode(',', $_ENV['CORS_ALLOWED_ORIGINS']) : ['*'];
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';

if (in_array('*', $allowedOrigins) || in_array($origin, $allowedOrigins)) {
    header('Access-Control-Allow-Origin: ' . ($origin ?: '*'));
}

header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Requested-With, Asaas-Access-Token, Authorization');
header_remove('X-Powered-By');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    exit(0);
}

// Instancia o roteador robusto
$router = new Router();

// Constrói o container PHP-DI
$container = Container::build();

// Rota de Checkout Limpa: /checkout
$router->match('GET|POST', '/checkout', function () use ($container) {
    $controller = $container->get(CheckoutController::class);
    $controller->handleRequest();
});

// Rota de Ativação Limpa: /activate
$router->match('GET|POST', '/activate', function () use ($container) {
    $controller = $container->get(ActivationController::class);
    $controller->handleRequest();
});

// Rota de Verificação Ultrarrápida: /verify
$router->match('GET|POST', '/verify', function () use ($container) {
    $controller = $container->get(VerificationController::class);
    $controller->handleRequest();
});

// Rota de Desativação de Licença: /deactivate
$router->match('GET|POST', '/deactivate', function () use ($container) {
    $controller = $container->get(DeactivationController::class);
    $controller->handleRequest();
});

// Rota de Atualização de Token: /refresh
$router->post('/refresh', function () use ($container) {
    $controller = $container->get(AuthController::class);
    $controller->handleRefresh();
});

// Rota de Sincronização do Bloco de Notas: /notes (GET e POST)
$router->match('GET|POST', '/notes', function () use ($container) {
    $controller = $container->get(NotepadController::class);
    $controller->handleRequest();
});

// Rota de Feedback e Novas Funcionalidades: /feedback
$router->post('/feedback', function () use ($container) {
    $controller = $container->get(FeedbackController::class);
    $controller->handleRequest();
});

// Rota de Stats da Campanha: /campaign-stats
$router->get('/campaign-stats', function () use ($container) {
    $controller = $container->get(CampaignController::class);
    $controller->getStats();
});

// Rota de Health Check: /health
$router->get('/health', function () use ($container) {
    $controller = $container->get(HealthCheckController::class);
    $controller->check();
});

// Rota de Webhook Limpa: /webhook (Asaas)
$router->post('/webhook', function () use ($container) {
    $controller = $container->get(WebhookController::class);
    $controller->handleRequest();
});

// Rota de Webhook Internacional: /stripe-webhook
$router->post('/stripe-webhook', function () use ($container) {
    $controller = $container->get(\App\Controllers\StripeWebhookController::class);
    $controller->handleRequest();
});

// Rota Fallback 404
$router->set404(function () {
    header('Content-Type: application/json; charset=utf-8');
    header_remove('X-Powered-By');
    http_response_code(404);
    echo json_encode([
        'status' => 'error',
        'message' => 'Endpoint not found.'
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
});

// Executa o roteamento
$router->run();
