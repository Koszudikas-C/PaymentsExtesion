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
            exit(0);
        }

        try {
            $params = $this->captureAndSanitizeInputs();

            if (!$this->validateParams($params['chrome_identity_id'], $params['email'])) {
                $this->respondWithError(400, 'Parâmetros chrome_identity_id e email são obrigatórios e devem conter dados válidos.');
                return;
            }

            $customer = $this->findCustomer($params['chrome_identity_id'], $params['email']);

            if ($customer) {
                $hasActiveLicense = $this->processExistingCustomer($customer, $params['chrome_identity_id']);
                if ($hasActiveLicense) {
                    return;
                }
            } else {
                $this->createPreRegisteredCustomer($params['name'], $params['email'], $params['phone'], $params['chrome_identity_id']);
            }

            $checkoutUrl = $this->generateCheckoutUrl($params['email'], $params['name'], $params['phone']);
            if (!$checkoutUrl) {
                $this->respondWithError(500, 'Configurações de pagamento ausentes no servidor.');
                return;
            }

            $this->respondWithJson(200, [
                'status' => 'pending',
                'checkoutUrl' => $checkoutUrl,
                'message' => 'Redirecionando para o pagamento seguro no Asaas.'
            ]);

        } catch (\Throwable $e) {
            $this->logException($e);
            $this->respondWithError(500, 'Erro interno de processamento do servidor.');
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
        $customer = $this->customerRepository->findByChromeIdentityId($chromeIdentityId);
        if (!$customer) {
            $customer = $this->customerRepository->findByEmail($email);
        }
        return $customer;
    }

    /**
     * Vincula a identidade se necessário e verifica se já possui licença ativa.
     * Retorna true se o cliente possuir licença ativa para encerrar o fluxo de checkout.
     */
    private function processExistingCustomer(Customer $customer, string $chromeIdentityId): bool
    {
        if (empty($customer->getChromeIdentityId())) {
            $customer->setChromeIdentityId($chromeIdentityId);
            $this->customerRepository->save($customer);
        }

        if ($customer->getPaymentStatus() === 'RECEIVED' && $customer->isLicenseActive()) {
            $this->respondWithJson(200, [
                'status' => 'active',
                'plan' => $customer->getPlan(),
                'licenseKey' => $customer->getLicenseKey(),
                'expiresAt' => $customer->getLicenseExpiresAt() ? $customer->getLicenseExpiresAt()->format('Y-m-d H:i:s') : null,
                'message' => 'Você já possui uma licença ativa! Não é necessário comprar novamente.'
            ]);
            return true;
        }

        return false;
    }

    private function createPreRegisteredCustomer(string $name, string $email, string $phone, string $chromeIdentityId): Customer
    {
        $customer = new Customer($name, $email, $phone);
        $customer->setChromeIdentityId($chromeIdentityId);
        $this->customerRepository->save($customer);
        return $customer;
    }

    private function generateCheckoutUrl(string $email, string $name, string $phone): ?string
    {
        $linkId = $this->settings['asaas']['payment_link_id'] ?? null;
        if (empty($linkId)) {
            $this->logger->critical('Asaas Payment Link ID is missing in settings config.');
            return null;
        }

        $baseLink = $_ENV['ASAAS_PAYMENT_LINK'] ?? 'https://cobranca.asaas.com/c/';
        if (!str_ends_with($baseLink, '/')) {
            $baseLink .= '/';
        }
        $baseUrl = $baseLink . $linkId;
        $queryParams = http_build_query([
            'email' => $email,
            'name' => $name,
            'mobilePhone' => $phone
        ]);

        return $baseUrl . '?' . $queryParams;
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
        $rawName = $_REQUEST['name'] ?? $input['name'] ?? 'Usuário';
        $rawPhone = $_REQUEST['phone'] ?? $input['phone'] ?? 'unknown';

        return [
            'chrome_identity_id' => $this->sanitizeChromeIdentityId($rawChromeId),
            'email' => $this->sanitizeEmail($rawEmail),
            'name' => $this->sanitizeName($rawName),
            'phone' => $this->sanitizePhone($rawPhone)
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
        $email = strtolower(trim((string)$email));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return null;
        }
        return substr($email, 0, 255);
    }

    private function sanitizeName(?string $rawName): string
    {
        if ($rawName === null) {
            return 'Usuário';
        }
        $cleanName = strip_tags($rawName);
        $cleanName = htmlspecialchars($cleanName, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $cleanName = trim($cleanName);
        return !empty($cleanName) ? substr($cleanName, 0, 100) : 'Usuário';
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
        header('Access-Control-Allow-Origin: *');
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
}
