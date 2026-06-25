<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Interfaces\Repositories\CustomerRepositoryInterface;
use App\Interfaces\Services\AuthTokenServiceInterface;
use Monolog\Logger;

class AuthController
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

    public function handleRefresh(): void
    {
        header_remove('X-Powered-By');
        $this->setupCorsHeaders();

        if ($this->isPreflightRequest()) {
            return;
        }

        try {
            $input = json_decode(file_get_contents('php://input'), true) ?: [];
            $refreshToken = $_REQUEST['refreshToken'] ?? $input['refreshToken'] ?? null;
            $chromeIdentityId = $_REQUEST['chrome_identity_id'] ?? $input['chrome_identity_id'] ?? null;

            if (empty($refreshToken) || empty($chromeIdentityId)) {
                $this->respondWithError(400, 'Parameters refreshToken and chrome_identity_id are required.');
                return;
            }

            // Find customer by chrome_identity_id
            $customer = $this->customerRepository->findByChromeIdentityId($chromeIdentityId);

            if (!$customer || !$customer->isLicenseActive()) {
                $this->respondWithError(401, 'Invalid or inactive license.');
                return;
            }

            // Check if refresh token matches
            $savedHash = $customer->getRefreshTokenHash();
            if (empty($savedHash) || !password_verify($refreshToken, $savedHash)) {
                $this->respondWithError(401, 'Invalid refresh token.');
                return;
            }

            // Check expiration
            $expiresAt = $customer->getRefreshTokenExpiresAt();
            if ($expiresAt !== null && $expiresAt < new \DateTime('now')) {
                $this->respondWithError(401, 'Refresh token expired. Please activate again.');
                return;
            }

            // Valid refresh token! Generate new tokens (Rotation)
            $clientIp = $_SERVER['REMOTE_ADDR'] ?? null;
            $newAccessToken = $this->authTokenService->generateAccessToken(
                $customer->getId(), 
                $customer->getEmail(), 
                $chromeIdentityId, 
                $clientIp
            );
            $newRefreshToken = $this->authTokenService->generateRefreshToken();

            $customer->setLastIpAddress($clientIp);
            $customer->setRefreshTokenHash(password_hash($newRefreshToken, PASSWORD_DEFAULT));
            $customer->setRefreshTokenExpiresAt((new \DateTime('now'))->modify('+1 year'));
            $this->customerRepository->save($customer);

            $this->respondWithJson(200, [
                'status' => 'success',
                'accessToken' => $newAccessToken,
                'refreshToken' => $newRefreshToken
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
        $this->logger->error('Error in AuthController processing request', [
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);
    }
}
