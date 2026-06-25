<?php

declare(strict_types=1);

namespace App\Services;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Monolog\Logger;
use App\Interfaces\Services\AuthTokenServiceInterface;

class AuthTokenService implements AuthTokenServiceInterface
{
    private string $secretKey;
    private Logger $logger;

    public function __construct(Logger $logger)
    {
        $this->logger = $logger;
        $this->secretKey = $_ENV['JWT_SECRET_KEY'] ?? 'default_insecure_secret_key_change_me';
    }

    /**
     * Generates a short-lived access token.
     */
    public function generateAccessToken(string $customerId, string $email, ?string $chromeIdentityId, ?string $clientIp): string
    {
        // Token expires in 7 days by default to minimize DB hits
        $expiresInSeconds = (int)($_ENV['JWT_EXPIRES_IN_SECONDS'] ?? 604800); 
        $issuedAt = time();
        $expire = $issuedAt + $expiresInSeconds;

        $payload = [
            'iat'  => $issuedAt,
            'exp'  => $expire,
            'sub'  => $customerId,
            'email' => $email,
            'chrome_identity_id' => $chromeIdentityId,
            'client_ip' => $clientIp
        ];

        return JWT::encode($payload, $this->secretKey, 'HS256');
    }

    /**
     * Generates a long-lived secure random refresh token.
     */
    public function generateRefreshToken(): string
    {
        return bin2hex(random_bytes(32));
    }

    /**
     * Validates an access token and performs IP checking.
     * Returns the payload object if valid, throws an exception otherwise.
     */
    public function validateAccessToken(string $token, ?string $currentIp = null): \stdClass
    {
        $payload = JWT::decode($token, new Key($this->secretKey, 'HS256'));

        // Check IP if available
        if ($currentIp !== null && isset($payload->client_ip) && $payload->client_ip !== $currentIp) {
            $this->logger->warning('IP Address mismatch during token validation', [
                'token_ip' => $payload->client_ip,
                'current_ip' => $currentIp,
                'customer_id' => $payload->sub ?? 'unknown'
            ]);
        }

        return $payload;
    }

    /**
     * Helper to determine if the origin is allowed without token (bypass).
     */
    public function isOriginAllowedForBypass(?string $origin): bool
    {
        if (empty($origin)) {
            return false;
        }
        
        $allowedOrigins = isset($_ENV['CORS_ALLOWED_ORIGINS']) ? explode(',', $_ENV['CORS_ALLOWED_ORIGINS']) : [];
        if (in_array('*', $allowedOrigins)) {
            return true;
        }

        return in_array($origin, $allowedOrigins);
    }
}
