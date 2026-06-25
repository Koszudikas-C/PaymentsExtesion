<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Interfaces\Repositories\CustomerRepositoryInterface;
use App\Interfaces\Services\AuthTokenServiceInterface;
use Monolog\Logger;

class ActivationController
{
    private CustomerRepositoryInterface $customerRepository;
    private AuthTokenServiceInterface $authTokenService;
    private Logger $logger;

    public function __construct(
        CustomerRepositoryInterface $customerRepository,
        AuthTokenServiceInterface $authTokenService,
        Logger $logger
    ) {
        $this->customerRepository = $customerRepository;
        $this->authTokenService = $authTokenService;
        $this->logger = $logger;
    }

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

            if (!$this->validateParams($params['chrome_identity_id'], $params['email'], $params['extension_id'], $params['license_key'])) {
                $this->respondWithError(400, 'Parameters chrome_identity_id, email, extension_id, and license_key are required.');
                return;
            }

            if (!$this->isValidExtensionId($params['extension_id'])) {
                $this->logger->warning('Unrecognized extension ID attempted activation', ['extension_id' => $params['extension_id']]);
                $this->respondWithError(403, 'Unauthorized access for this extension.');
                return;
            }

            // Busca o cliente pela chave de licença fornecida
            $startLookup = microtime(true);
            $customer = $this->customerRepository->findByLicenseKey($params['license_key']);
            $durationLookup = round((microtime(true) - $startLookup) * 1000, 2);
            $this->logPerformance('CustomerRepository', 'findByLicenseKey', $durationLookup);

            if (!$customer || $customer->getEmail() !== $params['email']) {
                $this->respondWithJson(200, [
                    'status' => 'invalid_key',
                    'message' => 'Invalid license key or email.'
                ]);
                return;
            }

            // Verifica se o pagamento foi recebido e a licença está ativa
            if ($customer->getPaymentStatus() !== 'RECEIVED' || !$customer->isLicenseActive()) {
                $this->respondWithJson(200, [
                    'status' => 'inactive',
                    'message' => 'Your license is inactive or expired. Please make a payment to activate.'
                ]);
                return;
            }

            // Validação de segurança anti-compartilhamento:
            // Se já houver um chrome_identity_id cadastrado e for diferente do enviado:
            $savedChromeId = $customer->getChromeIdentityId();
            if (!empty($savedChromeId) && $savedChromeId !== $params['chrome_identity_id']) {
                $forceReset = $params['force'];

                if ($forceReset) {
                    // Transfere a licença para o novo perfil/dispositivo
                    // Primeiro, desvincula o novo chrome_identity_id de qualquer outra licença/cliente para evitar erros de restrição única
                    $startUnbind = microtime(true);
                    $existingBoundCustomer = $this->customerRepository->findByChromeIdentityId($params['chrome_identity_id']);
                    if ($existingBoundCustomer && $existingBoundCustomer->getId() !== $customer->getId()) {
                        $existingBoundCustomer->setChromeIdentityId(null);
                        $existingBoundCustomer->recordAudit('CHROME_ID_UNBOUND', "Unbound Chrome Identity because it was force-transferred to license: " . $customer->getLicenseKey());
                        $this->customerRepository->save($existingBoundCustomer);
                    }
                    $durationUnbind = round((microtime(true) - $startUnbind) * 1000, 2);
                    $this->logPerformance('ActivationController', 'unbindChromeIdentity', $durationUnbind);

                    $startSave = microtime(true);
                    $customer->setChromeIdentityId($params['chrome_identity_id']);
                    $customer->recordAudit('LICENSE_TRANSFERRED', "License transferred to new Chrome Identity: {$params['chrome_identity_id']}");
                    $this->customerRepository->save($customer);
                    $durationSave = round((microtime(true) - $startSave) * 1000, 2);
                    $this->logPerformance('CustomerRepository', 'save', $durationSave, 'Force Transfer');

                    $this->logger->info('License transferred to new chrome identity', [
                        'email' => $customer->getEmail(),
                        'new_chrome_identity_id' => $params['chrome_identity_id']
                    ]);
                } else {
                    $this->logger->warning('Device conflict on license activation attempt', [
                        'email' => $customer->getEmail(),
                        'saved_chrome_id' => $savedChromeId,
                        'attempted_chrome_id' => $params['chrome_identity_id']
                    ]);
                    $this->respondWithJson(200, [
                        'status' => 'conflict',
                        'message' => 'This license is already activated on another Chrome profile or device. Do you want to transfer the activation to this profile?',
                        'can_force' => true
                    ]);
                    return;
                }
            }

            // Associa o chrome_identity_id enviado se ainda estiver vazio
            if (empty($savedChromeId)) {
                // Antes de salvar, verifica se já existe outro cliente associado a esse mesmo chrome_identity_id e o desvincula
                $startUnbind = microtime(true);
                $existingBoundCustomer = $this->customerRepository->findByChromeIdentityId($params['chrome_identity_id']);
                if ($existingBoundCustomer && $existingBoundCustomer->getId() !== $customer->getId()) {
                    $existingBoundCustomer->setChromeIdentityId(null);
                    $existingBoundCustomer->recordAudit('CHROME_ID_UNBOUND', "Unbound Chrome Identity because it was linked to a new license: " . $customer->getLicenseKey());
                    $this->customerRepository->save($existingBoundCustomer);

                    $this->logger->info('Unbound chrome identity from other customer', [
                        'other_customer_email' => $existingBoundCustomer->getEmail(),
                        'chrome_identity_id' => $params['chrome_identity_id']
                    ]);
                }
                $durationUnbind = round((microtime(true) - $startUnbind) * 1000, 2);
                $this->logPerformance('ActivationController', 'unbindChromeIdentity', $durationUnbind);

                $startSave = microtime(true);
                $customer->setChromeIdentityId($params['chrome_identity_id']);
                $this->customerRepository->save($customer);
                $durationSave = round((microtime(true) - $startSave) * 1000, 2);
                $this->logPerformance('CustomerRepository', 'save', $durationSave, 'New Chrome ID');
                $this->logger->info('Linked new chrome identity to customer license', [
                    'email' => $customer->getEmail(),
                    'chrome_identity_id' => $params['chrome_identity_id']
                ]);
            }

            // Gera os tokens (Access e Refresh)
            $clientIp = $_SERVER['REMOTE_ADDR'] ?? null;
            $accessToken = $this->authTokenService->generateAccessToken($customer->getId(), $customer->getEmail(), $params['chrome_identity_id'], $clientIp);
            $refreshToken = $this->authTokenService->generateRefreshToken();

            $customer->setLastIpAddress($clientIp);
            $customer->setRefreshTokenHash(password_hash($refreshToken, PASSWORD_DEFAULT));
            $customer->setRefreshTokenExpiresAt((new \DateTime('now'))->modify('+1 year'));
            $this->customerRepository->save($customer);

            $duration = round((microtime(true) - $startTime) * 1000, 2);
            $this->logPerformance('ActivationController', 'handleRequest', $duration);

            $this->respondWithJson(200, [
                'status' => 'success',
                'message' => 'Extension successfully activated!',
                'licenseKey' => $customer->getLicenseKey(),
                'plan' => $customer->getPlan(),
                'expiresAt' => $customer->getLicenseExpiresAt() ? $customer->getLicenseExpiresAt()->format('Y-m-d H:i:s') : null,
                'accessToken' => $accessToken,
                'refreshToken' => $refreshToken
            ]);

        } catch (\Throwable $e) {
            $this->logException($e);
            $this->respondWithError(500, 'Internal server error: ' . $e->getMessage());
        }
    }

    private function isPreflightRequest(): bool
    {
        return ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS';
    }

    private function validateParams(?string $chromeIdentityId, ?string $email, ?string $extensionId, ?string $licenseKey): bool
    {
        return !empty($chromeIdentityId) && !empty($email) && !empty($extensionId) && !empty($licenseKey);
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
        $rawEmail = $_REQUEST['email'] ?? $input['email'] ?? null;
        $rawExtensionId = $_REQUEST['extension_id'] ?? $input['extension_id'] ?? null;
        $rawLicenseKey = $_REQUEST['license_key'] ?? $input['license_key'] ?? null;
        $rawForce = $_REQUEST['force'] ?? $input['force'] ?? false;

        return [
            'chrome_identity_id' => $this->sanitizeChromeIdentityId($rawChromeId),
            'email' => $this->sanitizeEmail($rawEmail),
            'extension_id' => $this->sanitizeExtensionId($rawExtensionId),
            'license_key' => $this->sanitizeLicenseKey($rawLicenseKey),
            'force' => filter_var($rawForce, FILTER_VALIDATE_BOOLEAN)
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

    private function sanitizeExtensionId(?string $rawExtensionId): ?string
    {
        if ($rawExtensionId === null) {
            return null;
        }
        // IDs de extensão do Chrome contêm apenas caracteres de a-z minúsculos e comprimento fixo de 32 caracteres geralmente
        $extensionId = preg_replace('/[^a-z]/', '', strtolower($rawExtensionId));
        return substr($extensionId, 0, 100);
    }

    private function sanitizeLicenseKey(?string $rawLicenseKey): ?string
    {
        if ($rawLicenseKey === null) {
            return null;
        }
        $licenseKey = preg_replace('/[^a-zA-Z0-9_\-]/', '', $rawLicenseKey);
        return substr($licenseKey, 0, 100);
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
        $this->logger->error('Error in ActivationController processing request', [
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


