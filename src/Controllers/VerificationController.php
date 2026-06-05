<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Interfaces\Repositories\CustomerRepositoryInterface;
use Monolog\Logger;

class VerificationController
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

        $startTime = microtime(true);
        try {
            $params = $this->captureAndSanitizeInputs();

            // chrome_identity_id, extension_id são sempre obrigatórios; email opcional para fallback
            if (empty($params['chrome_identity_id']) || empty($params['extension_id'])) {
                $this->respondWithError(400, 'Parâmetros chrome_identity_id e extension_id são obrigatórios.');
                return;
            }

            if (!$this->isValidExtensionId($params['extension_id'])) {
                $this->logger->warning('Unrecognized extension ID attempted verification', ['extension_id' => $params['extension_id']]);
                $this->respondWithError(403, 'Acesso não autorizado para esta extensão.');
                return;
            }

            // Primeiro tenta localizar pelo chrome_identity_id
            $startChromeLookup = microtime(true);
            $customer = $this->customerRepository->findByChromeIdentityId($params['chrome_identity_id']);
            $durationChromeLookup = round((microtime(true) - $startChromeLookup) * 1000, 2);
            $this->logPerformance('CustomerRepository', 'findByChromeIdentityId', $durationChromeLookup);

            // Se não encontrou e email foi fornecido, tenta buscar pelo email
            if (!$customer && !empty($params['email'])) {
                $startEmailLookup = microtime(true);
                $customer = $this->customerRepository->findByEmail($params['email']);
                $durationEmailLookup = round((microtime(true) - $startEmailLookup) * 1000, 2);
                $this->logPerformance('CustomerRepository', 'findByEmail', $durationEmailLookup, 'Fallback');
                
                if ($customer) {
                    $savedChromeId = $customer->getChromeIdentityId();
                    
                    if (empty($savedChromeId)) {
                        // Se encontrou pelo email mas ainda não há chrome_identity_id associado, vincula agora
                        $startSave = microtime(true);
                        $customer->setChromeIdentityId($params['chrome_identity_id']);
                        $this->customerRepository->save($customer);
                        $durationSave = round((microtime(true) - $startSave) * 1000, 2);
                        $this->logPerformance('CustomerRepository', 'save', $durationSave, 'Auto Link Chrome ID');
                    } elseif ($savedChromeId !== $params['chrome_identity_id']) {
                        // Conflito: O email existe mas está vinculado a outro chrome_identity_id.
                        // A verificação silenciosa deve falhar para não permitir uso duplo.
                        $this->respondWithJson(200, [
                            'status' => 'conflict',
                            'message' => 'Sua licença está ativada em outro perfil ou dispositivo. Faça a ativação novamente neste perfil para transferir o uso.'
                        ]);
                        return;
                    }
                }
            }

            if (!$customer) {
                $this->respondWithJson(200, [
                    'status' => 'not_found',
                    'message' => 'Nenhuma licença ativa vinculada a este perfil.'
                ]);
                return;
            }

            if ($customer->getPaymentStatus() === 'RECEIVED' && $customer->isLicenseActive()) {
                $duration = round((microtime(true) - $startTime) * 1000, 2);
                $this->logPerformance('VerificationController', 'handleRequest', $duration, 'Active License');

                $this->respondWithJson(200, [
                    'status' => 'active',
                    'message' => 'Licença ativa e válida.',
                    'plan' => $customer->getPlan(),
                    'expiresAt' => $customer->getLicenseExpiresAt() ? $customer->getLicenseExpiresAt()->format('Y-m-d H:i:s') : null
                ]);
                return;
            }

            $duration = round((microtime(true) - $startTime) * 1000, 2);
            $this->logPerformance('VerificationController', 'handleRequest', $duration, 'Inactive License');

            $this->respondWithJson(200, [
                'status' => 'inactive',
                'message' => 'A licença vinculada a este perfil está inativa ou expirada.'
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

    private function isValidExtensionId(string $extensionId): bool
    {
        $allowedExtensionId = $_ENV['CHROME_EXTENSION_ID'] ?? '';
        if (empty($allowedExtensionId)) {
            return true; // Se não configurado no .env, desabilita a restrição para evitar bloqueios
        }
        return $extensionId === $allowedExtensionId;
    }

    private function captureAndSanitizeInputs(): array
    {
        $rawInput = file_get_contents('php://input') ?: '';
        if ($rawInput !== '' && !mb_check_encoding($rawInput, 'UTF-8')) {
            $rawInput = mb_convert_encoding($rawInput, 'UTF-8', 'auto');
        }

        $input = json_decode($rawInput, true) ?: [];

        $rawChromeId = $_REQUEST['chrome_identity_id'] ?? $input['chrome_identity_id'] ?? null;
        $rawExtensionId = $_REQUEST['extension_id'] ?? $input['extension_id'] ?? null;
        $rawEmail = $_REQUEST['email'] ?? $input['email'] ?? null;

        return [
            'chrome_identity_id' => $this->sanitizeChromeIdentityId($rawChromeId),
            'extension_id' => $this->sanitizeExtensionId($rawExtensionId),
            'email' => $this->sanitizeEmail($rawEmail)
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
        $extensionId = preg_replace('/[^a-z]/', '', strtolower($rawExtensionId));
        return substr($extensionId, 0, 100);
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
        $this->logger->error('Error in VerificationController processing request', [
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);
    }

    private function logPerformance(string $class, string $method, float $durationMs, string $additionalInfo = ''): void
    {
        $threshold = (float)($_ENV['PERFORMANCE_THRESHOLD_MS'] ?? 1000.0);
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


