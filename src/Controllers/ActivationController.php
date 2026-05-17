<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Interfaces\Repositories\CustomerRepositoryInterface;
use App\Entity\Customer;
use Monolog\Logger;

class ActivationController
{
    private CustomerRepositoryInterface $customerRepository;
    private Logger $logger;

    public function __construct(
        CustomerRepositoryInterface $customerRepository,
        Logger $logger
    ) {
        $this->customerRepository = $customerRepository;
        $this->logger = $logger;
    }

    public function handleRequest(): void
    {
        header_remove('X-Powered-By');
        $this->setupCorsHeaders();

        if ($this->isPreflightRequest()) {
            exit(0);
        }

        try {
            $params = $this->captureAndSanitizeInputs();

            if (!$this->validateParams($params['chrome_identity_id'], $params['email'], $params['extension_id'])) {
                $this->respondWithError(400, 'Parâmetros chrome_identity_id, email e extension_id são obrigatórios.');
                return;
            }

            if (!$this->isValidExtensionId($params['extension_id'])) {
                $this->logger->warning('Unrecognized extension ID attempted activation', ['extension_id' => $params['extension_id']]);
                $this->respondWithError(403, 'Acesso não autorizado para esta extensão.');
                return;
            }

            $customer = $this->findCustomer($params['chrome_identity_id'], $params['email']);

            if (!$customer) {
                $this->respondWithJson(200, [
                    'status' => 'not_found',
                    'message' => 'Nenhum cadastro ou licença ativa encontrada para este usuário.'
                ]);
                return;
            }

            // Garante que o chrome_identity_id esteja associado se estiver vazio
            if (empty($customer->getChromeIdentityId())) {
                $customer->setChromeIdentityId($params['chrome_identity_id']);
                $this->customerRepository->save($customer);
            }

            if ($customer->getPaymentStatus() === 'RECEIVED' && $customer->isLicenseActive()) {
                $this->respondWithJson(200, [
                    'status' => 'success',
                    'message' => 'Extensão ativada com sucesso!',
                    'licenseKey' => $customer->getLicenseKey(),
                    'plan' => $customer->getPlan(),
                    'expiresAt' => $customer->getLicenseExpiresAt() ? $customer->getLicenseExpiresAt()->format('Y-m-d H:i:s') : null
                ]);
                return;
            }

            $this->respondWithJson(200, [
                'status' => 'inactive',
                'message' => 'A sua licença está inativa ou expirada. Efetue o pagamento para ativar.'
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

    private function validateParams(?string $chromeIdentityId, ?string $email, ?string $extensionId): bool
    {
        return !empty($chromeIdentityId) && !empty($email) && !empty($extensionId);
    }

    private function isValidExtensionId(string $extensionId): bool
    {
        $allowedExtensionId = $_ENV['CHROME_EXTENSION_ID'] ?? '';
        if (empty($allowedExtensionId)) {
            return true; // Se não configurado no .env, desabilita a restrição para evitar bloqueios
        }
        return $extensionId === $allowedExtensionId;
    }

    private function findCustomer(string $chromeIdentityId, string $email): ?Customer
    {
        $customer = $this->customerRepository->findByChromeIdentityId($chromeIdentityId);
        if (!$customer) {
            $customer = $this->customerRepository->findByEmail($email);
        }
        return $customer;
    }

    private function captureAndSanitizeInputs(): array
    {
        $rawInput = file_get_contents('php://input') ?: '';
        if ($rawInput !== '' && !mb_check_encoding($rawInput, 'UTF-8')) {
            $rawInput = mb_convert_encoding($rawInput, 'UTF-8', 'auto');
        }

        $input = json_decode($rawInput, true) ?: [];

        $rawChromeId = $_REQUEST['chrome_identity_id'] ?? $input['chrome_identity_id'] ?? null;
        $rawEmail = $_REQUEST['email'] ?? $input['email'] ?? null;
        $rawExtensionId = $_REQUEST['extension_id'] ?? $input['extension_id'] ?? null;

        return [
            'chrome_identity_id' => $this->sanitizeChromeIdentityId($rawChromeId),
            'email' => $this->sanitizeEmail($rawEmail),
            'extension_id' => $this->sanitizeExtensionId($rawExtensionId)
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

    private function sanitizeExtensionId(?string $rawExtensionId): ?string
    {
        if ($rawExtensionId === null) {
            return null;
        }
        // IDs de extensão do Chrome contêm apenas caracteres de a-z minúsculos e comprimento fixo de 32 caracteres geralmente
        $extensionId = preg_replace('/[^a-z]/', '', strtolower($rawExtensionId));
        return substr($extensionId, 0, 100);
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
        $this->logger->error('Error in ActivationController processing request', [
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);
    }
}
