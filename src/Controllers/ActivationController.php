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

            if (!$this->validateParams($params['chrome_identity_id'], $params['email'], $params['extension_id'], $params['license_key'])) {
                $this->respondWithError(400, 'Parâmetros chrome_identity_id, email, extension_id e license_key são obrigatórios.');
                return;
            }

            if (!$this->isValidExtensionId($params['extension_id'])) {
                $this->logger->warning('Unrecognized extension ID attempted activation', ['extension_id' => $params['extension_id']]);
                $this->respondWithError(403, 'Acesso não autorizado para esta extensão.');
                return;
            }

            // Busca o cliente pelo email fornecido
            $customer = $this->customerRepository->findByEmail($params['email']);

            if (!$customer) {
                $this->respondWithJson(200, [
                    'status' => 'not_found',
                    'message' => 'Nenhum cadastro encontrado para o email fornecido.'
                ]);
                return;
            }

            // Verifica se a chave de licença enviada bate com a salva no banco de dados
            $savedLicenseKey = $customer->getLicenseKey();
            if (empty($savedLicenseKey) || $params['license_key'] !== $savedLicenseKey) {
                $this->respondWithJson(200, [
                    'status' => 'invalid_key',
                    'message' => 'Chave de licença inválida para o email fornecido.'
                ]);
                return;
            }

            // Verifica se o pagamento foi recebido e a licença está ativa
            if ($customer->getPaymentStatus() !== 'RECEIVED' || !$customer->isLicenseActive()) {
                $this->respondWithJson(200, [
                    'status' => 'inactive',
                    'message' => 'A sua licença está inativa ou expirada. Efetue o pagamento para ativar.'
                ]);
                return;
            }

            // Validação de segurança anti-compartilhamento:
            // Se já houver um chrome_identity_id cadastrado e for diferente do enviado:
            $savedChromeId = $customer->getChromeIdentityId();
            if (!empty($savedChromeId) && $savedChromeId !== $params['chrome_identity_id']) {
                $forceReset = (bool)($_REQUEST['force'] ?? $input['force'] ?? false);

                if ($forceReset) {
                    // Transfere a licença para o novo perfil/dispositivo
                    $customer->setChromeIdentityId($params['chrome_identity_id']);
                    $customer->recordAudit('LICENSE_TRANSFERRED', "License transferred to new Chrome Identity: {$params['chrome_identity_id']}");
                    $this->customerRepository->save($customer);
                    
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
                        'message' => 'Esta licença já está ativada em outro perfil ou dispositivo do Chrome. Deseja transferir a ativação para este perfil?',
                        'can_force' => true
                    ]);
                    return;
                }
            }

            // Associa o chrome_identity_id enviado se ainda estiver vazio
            if (empty($savedChromeId)) {
                $customer->setChromeIdentityId($params['chrome_identity_id']);
                $this->customerRepository->save($customer);
                $this->logger->info('Linked new chrome identity to customer license', [
                    'email' => $customer->getEmail(),
                    'chrome_identity_id' => $params['chrome_identity_id']
                ]);
            }

            $this->respondWithJson(200, [
                'status' => 'success',
                'message' => 'Extensão ativada com sucesso!',
                'licenseKey' => $customer->getLicenseKey(),
                'plan' => $customer->getPlan(),
                'expiresAt' => $customer->getLicenseExpiresAt() ? $customer->getLicenseExpiresAt()->format('Y-m-d H:i:s') : null
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

        return [
            'chrome_identity_id' => $this->sanitizeChromeIdentityId($rawChromeId),
            'email' => $this->sanitizeEmail($rawEmail),
            'extension_id' => $this->sanitizeExtensionId($rawExtensionId),
            'license_key' => $this->sanitizeLicenseKey($rawLicenseKey)
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
