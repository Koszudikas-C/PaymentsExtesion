<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Interfaces\Repositories\CustomerRepositoryInterface;
use App\Entity\Customer;
use Monolog\Logger;

class CheckoutController
{
    private CustomerRepositoryInterface $customerRepository;
    private array $settings;
    private Logger $logger;

    public function __construct(
        CustomerRepositoryInterface $customerRepository,
        array $settings,
        Logger $logger
    ) {
        $this->customerRepository = $customerRepository;
        $this->settings = $settings;
        $this->logger = $logger;
    }

    /**
     * Ponto de entrada do processamento de checkout da extensão.
     */
    public function handleRequest(): void
    {
        header_remove('X-Powered-By');
        $this->setupCorsHeaders();

        if ($this->isPreflightRequest()) {
            return;
        }

        $startTime = microtime(true);
        try {
            $params = $this->captureAndSanitizeInputs();

            if (!$this->validateParams($params['chrome_identity_id'], $params['email'])) {
                $this->respondWithError(400, 'Parameters chrome_identity_id and email are required and must contain valid data.');
                return;
            }

            $customer = $this->findCustomer($params['chrome_identity_id'], $params['email']);

            $planType = strtoupper($params['plan'] ?? 'LIFETIME');

            if ($customer) {
                $hasActiveLicense = $this->processExistingCustomer($customer, $params['chrome_identity_id'], $planType);
                if ($hasActiveLicense) {
                    $duration = round((microtime(true) - $startTime) * 1000, 2);
                    $this->logPerformance('CheckoutController', 'handleRequest', $duration, 'Existing Customer');
                    return;
                }
            } else {
                $startCreate = microtime(true);
                $this->createPreRegisteredCustomer($params['name'] ?? '', $params['email'], $params['phone'] ?? '', $params['chrome_identity_id']);
                $durationCreate = round((microtime(true) - $startCreate) * 1000, 2);
                $this->logPerformance('CheckoutController', 'createPreRegisteredCustomer', $durationCreate);
            }

            $clientIp = $this->getClientIp();
            $countryCode = $this->getCountryCodeFromIp($clientIp);

            // Log do país detectado
            $this->logger->info("Checkout request from IP {$clientIp} resolved to country: {$countryCode}");

            // Validação de Vagas da Campanha Vitalícia
            if ($planType === 'LIFETIME') {
                $target = (int) ($this->settings['campaign']['target'] ?? 100);
                $lifetimeCount = $this->customerRepository->countPaidLifetimeCustomers();
                
                if ($lifetimeCount >= $target) {
                    $planType = 'MONTHLY'; // Força fallback para mensal caso esgotado
                    $this->logger->info("LIFETIME campaign limit reached ({$lifetimeCount}/{$target}). Forcing {$planType} checkout for IP {$clientIp}");
                }
            }
            
            if ($countryCode === 'BR') {
                $checkoutUrl = $this->generateCheckoutUrl($params['email'], $params['name'] ?? '', $params['phone'] ?? '', $planType);
                $message = 'Redirecting to secure payment in Asaas.';
            } else {
                $checkoutUrl = $this->generateStripeCheckoutUrl($params['email'], $params['chrome_identity_id'], $planType, $params['name'] ?? '', $params['phone'] ?? '');
                $message = 'Redirecting to secure international payment in Stripe.';
                
                // Fallback de segurança: Se o Stripe não estiver configurado/vazio, usa o Asaas
                if (!$checkoutUrl) {
                    $checkoutUrl = $this->generateCheckoutUrl($params['email'], $params['name'] ?? '', $params['phone'] ?? '', $planType);
                    $message = 'Redirecting to secure payment in Asaas.';
                }
            }

            if (!$checkoutUrl) {
                $this->respondWithError(500, 'Payment configurations missing on server.');
                return;
            }

            $duration = round((microtime(true) - $startTime) * 1000, 2);
            $this->logPerformance('CheckoutController', 'handleRequest', $duration, 'New Checkout');

            $this->respondWithJson(200, [
                'status' => 'pending',
                'checkoutUrl' => $checkoutUrl,
                'message' => $message
            ]);

        } catch (\Throwable $e) {
            $this->logException($e);
            $this->respondWithError(500, 'Internal server error: ' . $e->getMessage() . ' at ' . $e->getFile() . ':' . $e->getLine());
        }
    }

    private function isPreflightRequest(): bool
    {
        return ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS';
    }

    private function validateParams(?string $chromeIdentityId, ?string $email): bool
    {
        return !empty($chromeIdentityId) && !empty($email);
    }

    private function findCustomer(string $chromeIdentityId, string $email): ?Customer
    {
        $startFind = microtime(true);
        // Busca estritamente pelo E-mail, pois ele é a única chave absoluta do cliente.
        $customer = $this->customerRepository->findByEmail($email);

        $durationFind = round((microtime(true) - $startFind) * 1000, 2);
        $this->logPerformance('CheckoutController', 'findCustomer', $durationFind);
        return $customer;
    }

    /**
     * Vincula a identidade se necessário e verifica se já possui licença ativa.
     * Retorna true se o cliente possuir licença ativa para encerrar o fluxo de checkout.
     */
    private function processExistingCustomer(Customer $customer, string $chromeIdentityId, string $requestedPlan): bool
    {
        if (empty($customer->getChromeIdentityId())) {
            $existingOwner = $this->customerRepository->findByChromeIdentityId($chromeIdentityId);
            if (!$existingOwner) {
                $customer->setChromeIdentityId($chromeIdentityId);
                $this->customerRepository->save($customer);
            }
        }

        if ($customer->getPaymentStatus() === 'RECEIVED' && $customer->isLicenseActive()) {
            $currentPlan = $customer->getPlan() ?: 'LIFETIME';
            
            // Se ele já é LIFETIME, ele não precisa comprar mais nada, tem acesso infinito.
            if ($currentPlan === 'LIFETIME') {
                $this->respondWithActiveLicense($customer);
                return true;
            }
            
            // Se o plano atual é CO-CREATOR/MONTHLY, permitimos gerar um novo Checkout
            // para que o usuário possa interagir com a data de expiração (renovar) ou fazer upgrade.
            return false;
        }

        return false;
    }

    private function respondWithActiveLicense(Customer $customer): void
    {
        $this->respondWithJson(200, [
            'status' => 'active',
            'plan' => $customer->getPlan(),
            'expiresAt' => $customer->getLicenseExpiresAt() ? $customer->getLicenseExpiresAt()->format('Y-m-d H:i:s') : null,
            'message' => 'You already have an active license with this email! Check your inbox to see the license key.'
        ]);
    }

    private function createPreRegisteredCustomer(string $name, string $email, string $phone, string $chromeIdentityId): Customer
    {
        $customer = new Customer($name, $email, $phone);
        
        // Verifica se o chromeIdentityId já pertence a outro usuário antes de associar ao novo
        $existingOwner = $this->customerRepository->findByChromeIdentityId($chromeIdentityId);
        if (!$existingOwner) {
            $customer->setChromeIdentityId($chromeIdentityId);
        }
        
        $this->customerRepository->save($customer);
        return $customer;
    }

    private function generateCheckoutUrl(string $email, string $name, string $phone, string $planType): ?string
    {
        $linkId = $planType === 'MONTHLY' || $planType === 'CO-CREATOR'
            ? ($this->settings['asaas']['payment_link_id_monthly'] ?? null)
            : ($this->settings['asaas']['payment_link_id_lifetime'] ?? null);

        if (empty($linkId)) {
            $linkId = $_ENV['ASAAS_PAYMENT_LINK_ID'] ?? null;
        }

        if ($linkId && str_contains($linkId, ',')) {
            $links = explode(',', $linkId);
            $linkId = $planType === 'MONTHLY' || $planType === 'CO-CREATOR' ? trim($links[1] ?? $links[0]) : trim($links[0]);
        }

        if (empty($linkId)) {
            $this->logger->critical("Asaas Payment Link ID for plan {$planType} is missing in settings config.");
            return null;
        }

        if (str_starts_with($linkId, 'http://') || str_starts_with($linkId, 'https://')) {
            $baseUrl = $linkId;
        } else {
            $baseLink = $_ENV['ASAAS_PAYMENT_LINK'] ?? 'https://cobranca.asaas.com/c/';
            if (!str_ends_with($baseLink, '/')) {
                $baseLink .= '/';
            }
            $baseUrl = $baseLink . ltrim($linkId, '/');
        }

        $queryParams = http_build_query([
            'email' => $email,
            'name' => $name,
            'mobilePhone' => $phone
        ]);

        return $baseUrl . '?' . $queryParams;
    }

    private function generateStripeCheckoutUrl(string $email, string $chromeIdentityId, string $planType, string $name, string $phone): ?string
    {
        // Pega as variáveis específicas de plano, se existirem
        $priceId = null;
        if ($planType === 'MONTHLY' || $planType === 'CO-CREATOR') {
            $priceId = $_ENV['STRIPE_PRICE_ID_MONTHLY'] ?? $_ENV['STRIPE_PAYMENT_LINK_ID_MONTHLY'] ?? null;
        } else {
            $priceId = $_ENV['STRIPE_PRICE_ID_LIFETIME'] ?? $_ENV['STRIPE_PAYMENT_LINK_ID_LIFETIME'] ?? null;
        }

        if (empty($priceId)) {
            $priceId = $_ENV['STRIPE_PRICE_ID'] ?? $_ENV['STRIPE_PAYMENT_LINK_ID'] ?? null;
        }

        if ($priceId && str_contains($priceId, ',')) {
            $links = explode(',', $priceId);
            $priceId = $planType === 'MONTHLY' || $planType === 'CO-CREATOR' ? trim($links[1] ?? $links[0]) : trim($links[0]);
        }

        if (empty($priceId)) {
            $this->logger->critical("Stripe Payment Link / Price ID for plan {$planType} is missing in config.");
            return null;
        }

        // Se o admin configurou um ID de Preço nativo (começa com price_), gera a Sessão Dinâmica c/ Nome!
        if (str_starts_with($priceId, 'price_')) {
            if (empty($_ENV['STRIPE_SECRET_KEY'])) {
                $this->logger->critical("Stripe Secret Key is missing for Session API.");
                return null;
            }

            try {
                \Stripe\Stripe::setApiKey($_ENV['STRIPE_SECRET_KEY']);
                
                // Buscar cliente na Stripe ou criar um novo cravando o NOME
                $customers = \Stripe\Customer::all(['email' => $email, 'limit' => 1]);
                if (count($customers->data) > 0) {
                    $stripeCustomer = $customers->data[0];
                } else {
                    $stripeCustomer = \Stripe\Customer::create([
                        'email' => $email,
                        'name' => $name,
                        'phone' => $phone,
                    ]);
                }
                
                $successUrl = $_ENV['STRIPE_SUCCESS_URL'] ?? 'https://aifreelas.com.br?payment=success';
                $cancelUrl = $_ENV['STRIPE_CANCEL_URL'] ?? 'https://aifreelas.com.br?payment=cancelled';

                $session = \Stripe\Checkout\Session::create([
                    'customer' => $stripeCustomer->id,
                    'payment_method_types' => ['card'],
                    'line_items' => [[
                        'price' => $priceId,
                        'quantity' => 1,
                    ]],
                    'mode' => ($planType === 'MONTHLY' || $planType === 'CO-CREATOR') ? 'subscription' : 'payment',
                    'client_reference_id' => $chromeIdentityId,
                    'success_url' => $successUrl,
                    'cancel_url' => $cancelUrl,
                ]);

                return $session->url;
            } catch (\Exception $e) {
                $this->logger->error("Failed to create Stripe Checkout Session: " . $e->getMessage());
                return null;
            }
        }

        // --- FALLBACK PARA PAYMENT LINKS ANTIGOS (Não suporta preenchimento de nome) ---
        $linkId = $priceId;

        // Se o usuário colou a URL inteira no .env, não concatena de novo
        if (str_starts_with($linkId, 'http://') || str_starts_with($linkId, 'https://')) {
            $baseUrl = $linkId;
        } else {
            $baseUrl = 'https://buy.stripe.com/' . ltrim($linkId, '/');
        }

        $queryParams = http_build_query([
            'prefilled_email' => $email,
            'client_reference_id' => $chromeIdentityId
        ]);

        return $baseUrl . '?' . $queryParams;
    }

    private function getClientIp(): string
    {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
        if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
            $ip = $_SERVER['HTTP_CLIENT_IP'];
        } elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            // Se houver múltiplos IPs no header (proxies), pega o primeiro
            $ipList = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
            $ip = trim($ipList[0]);
        }
        return $ip;
    }

    private function getCountryCodeFromIp(string $ip): string
    {
        // Se for IP local/privado, assumir BR por padrão (útil para dev local)
        if ($ip === '127.0.0.1' || $ip === '::1' || str_starts_with($ip, '192.168.') || str_starts_with($ip, '10.')) {
            return 'BR';
        }

        try {
            $url = "http://ip-api.com/json/{$ip}?fields=countryCode";
            $context = stream_context_create([
                'http' => [
                    'timeout' => 2.0 // Timeout curto para não travar o checkout
                ]
            ]);
            $response = @file_get_contents($url, false, $context);
            if ($response) {
                $data = json_decode($response, true);
                if (isset($data['countryCode']) && !empty($data['countryCode'])) {
                    return strtoupper($data['countryCode']);
                }
            }
        } catch (\Throwable $e) {
            $this->logger->warning('Failed to resolve IP location from ip-api.com', [
                'ip' => $ip,
                'error' => $e->getMessage()
            ]);
        }

        // Falha segura: assume BR em caso de erro na API de geolocalização
        return 'BR';
    }

    /**
     * Captura os inputs de forma segura via GET, POST ou JSON payload,
     * aplicando filtros estritos contra XSS (Cross Site Scripting) e sanitização profunda.
     */
    private function captureAndSanitizeInputs(): array
    {
        $rawInput = file_get_contents('php://input') ?: '';
        if ($rawInput !== '' && !mb_check_encoding($rawInput, 'UTF-8')) {
            $rawInput = mb_convert_encoding($rawInput, 'UTF-8', 'auto');
        }

        $input = json_decode($rawInput, true) ?: [];

        $rawChromeId = $_REQUEST['chrome_identity_id'] ?? $input['chrome_identity_id'] ?? null;
        $rawEmail = $_REQUEST['email'] ?? $input['email'] ?? null;
        $rawName = $_REQUEST['name'] ?? $input['name'] ?? 'User';
        $rawPhone = $_REQUEST['phone'] ?? $input['phone'] ?? 'unknown';
        $rawPlan = $_REQUEST['plan'] ?? $input['plan'] ?? 'LIFETIME';

        return [
            'chrome_identity_id' => $this->sanitizeChromeIdentityId($rawChromeId),
            'email' => $this->sanitizeEmail($rawEmail),
            'name' => $this->sanitizeName($rawName),
            'phone' => $this->sanitizePhone($rawPhone),
            'plan' => strtoupper(trim(strip_tags((string) $rawPlan)))
        ];
    }

    private function sanitizeChromeIdentityId(?string $rawChromeId): ?string
    {
        if ($rawChromeId === null) {
            return null;
        }
        $chromeIdentityId = preg_replace('/[^a-zA-Z0-9@\.\-_]/', '', $rawChromeId);
        return substr($chromeIdentityId, 0, 255);
    }

    private function sanitizeEmail(?string $rawEmail): ?string
    {
        if ($rawEmail === null) {
            return null;
        }
        $email = filter_var($rawEmail, FILTER_SANITIZE_EMAIL);
        $email = strtolower(trim((string) $email));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return null;
        }
        return substr($email, 0, 255);
    }

    private function sanitizeName(?string $rawName): string
    {
        if ($rawName === null) {
            return 'User';
        }
        $cleanName = strip_tags($rawName);
        $cleanName = htmlspecialchars($cleanName, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $cleanName = trim($cleanName);
        return !empty($cleanName) ? substr($cleanName, 0, 100) : 'User';
    }

    private function sanitizePhone(?string $rawPhone): string
    {
        if ($rawPhone === null) {
            return 'unknown';
        }
        $cleanPhone = preg_replace('/[^0-9\+\(\)\-\s]/', '', $rawPhone);
        $cleanPhone = trim($cleanPhone);
        return !empty($cleanPhone) ? substr($cleanPhone, 0, 30) : 'unknown';
    }

    private function setupCorsHeaders(): void
    {

        header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, X-Requested-With');
    }

    private function respondWithJson(int $statusCode, array $data): void
    {
        http_response_code($statusCode);
        echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private function respondWithError(int $statusCode, string $message): void
    {
        $this->respondWithJson($statusCode, [
            'status' => 'error',
            'message' => $message
        ]);
    }

    private function logException(\Throwable $e): void
    {
        $this->logger->error('Error in CheckoutController processing request', [
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);
    }

    private function logPerformance(string $class, string $method, float $durationMs, string $additionalInfo = ''): void
    {
        $threshold = (float) ($_ENV['PERFORMANCE_THRESHOLD_MS'] ?? 1000.0);
        $isAlert = $durationMs > $threshold;
        $level = $isAlert ? 'error' : 'info';
        $tag = $isAlert ? '[PERFORMANCE_ALERT]' : '[PERFORMANCE]';

        $message = "{$tag} {$class}::{$method}" . ($additionalInfo !== '' ? " ({$additionalInfo})" : "") . " took {$durationMs}ms";

        $this->logger->$level($message, [
            'type' => 'performance',
            'class' => $class,
            'method' => $method,
            'duration_ms' => $durationMs,
            'alert' => $isAlert
        ]);
    }
}


