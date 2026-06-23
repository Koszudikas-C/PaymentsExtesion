<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Interfaces\Repositories\CustomerRepositoryInterface;
use App\Entity\Customer;
use Monolog\Logger;

class DeactivationController
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
            return;
        }

        try {
            $params = $this->captureAndSanitizeInputs();

            if (empty($params['chrome_identity_id'])) {
                $this->respondWithError(400, 'Parameter chrome_identity_id is required.');
                return;
            }

            $customer = $this->customerRepository->findByChromeIdentityId($params['chrome_identity_id']);

            if (!$customer) {
                // Se não encontrar, já está desativado (ou nunca existiu), retorna sucesso silencioso
                $this->respondWithJson(200, [
                    'status' => 'success',
                    'message' => 'License deactivated from this profile (it was already unbound).'
                ]);
                return;
            }

            // Opcional: validar email se foi enviado
            if (!empty($params['email']) && $customer->getEmail() !== $params['email']) {
                $this->logger->warning('Attempted deactivation with mismatching email', [
                    'expected' => $customer->getEmail(),
                    'provided' => $params['email'],
                    'chrome_identity_id' => $params['chrome_identity_id']
                ]);
                $this->respondWithError(403, 'Unauthorized access. Mismatching email.');
                return;
            }

            $customer->setChromeIdentityId(null);
            $customer->recordAudit('LICENSE_DEACTIVATED', "License deactivated manually from Chrome Identity: {$params['chrome_identity_id']}");
            $this->customerRepository->save($customer);

            $this->logger->info('License deactivated and unbound from chrome identity', [
                'email' => $customer->getEmail(),
                'chrome_identity_id' => $params['chrome_identity_id']
            ]);

            $this->respondWithJson(200, [
                'status' => 'success',
                'message' => 'License successfully deactivated and unbound from this profile!'
            ]);

        } catch (\Throwable $e) {
            $this->logException($e);
            $this->respondWithError(500, 'Internal server error.');
        }
    }

    private function isPreflightRequest(): bool
    {
        return ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS';
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

        return [
            'chrome_identity_id' => $this->sanitizeString($rawChromeId),
            'email' => $this->sanitizeEmail($rawEmail)
        ];
    }

    private function sanitizeString(?string $raw): ?string
    {
        if ($raw === null) {
            return null;
        }
        $str = preg_replace('/[^a-zA-Z0-9@\.\-_]/', '', $raw);
        return substr($str, 0, 255);
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

    private function setupCorsHeaders(): void
    {
        header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, X-Requested-With');
    }

    private function respondWithJson(int $statusCode, array $data): void
    {
        http_response_code($statusCode);
        header('Content-Type: application/json; charset=utf-8');
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
        $this->logger->error('Error in DeactivationController processing request', [
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);
    }
}
