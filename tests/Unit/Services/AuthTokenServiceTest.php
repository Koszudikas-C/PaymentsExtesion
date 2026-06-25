<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\AuthTokenService;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

class AuthTokenServiceTest extends TestCase
{
    private AuthTokenService $authTokenService;
    private Logger $loggerMock;
    private string $secretKey = 'test_secret_key_for_jwt_which_needs_to_be_long_enough';

    protected function setUp(): void
    {
        // Mock do Logger
        $this->loggerMock = $this->createMock(Logger::class);

        // Simulando variáveis de ambiente
        $_ENV['JWT_SECRET_KEY'] = $this->secretKey;
        $_ENV['JWT_EXPIRES_IN_SECONDS'] = '3600';
        $_ENV['CORS_ALLOWED_ORIGINS'] = 'https://export.aifreelas.com.br';

        $this->authTokenService = new AuthTokenService($this->loggerMock);
    }

    public function testGenerateAccessToken(): void
    {
        $customerId = '1';
        $email = 'test@example.com';
        $chromeIdentityId = 'chrome_123';
        $clientIp = '192.168.1.10';

        $token = $this->authTokenService->generateAccessToken($customerId, $email, $chromeIdentityId, $clientIp);

        $this->assertNotEmpty($token);

        // Validar token manualmente
        $decoded = JWT::decode($token, new Key($this->secretKey, 'HS256'));

        $this->assertEquals($customerId, $decoded->sub);
        $this->assertEquals($email, $decoded->email);
        $this->assertEquals($chromeIdentityId, $decoded->chrome_identity_id);
        $this->assertEquals($clientIp, $decoded->client_ip);
        $this->assertNotNull($decoded->iat);
        $this->assertNotNull($decoded->exp);
        $this->assertEquals($decoded->iat + 3600, $decoded->exp);
    }

    public function testGenerateRefreshToken(): void
    {
        $refreshToken = $this->authTokenService->generateRefreshToken();
        $this->assertNotEmpty($refreshToken);
        $this->assertEquals(64, strlen($refreshToken)); // bin2hex(random_bytes(32)) == 64 chars
    }

    public function testValidateAccessTokenValid(): void
    {
        $token = $this->authTokenService->generateAccessToken('1', 'test@example.com', 'chrome_123', '10.0.0.1');

        $payload = $this->authTokenService->validateAccessToken($token, '10.0.0.1');

        $this->assertNotNull($payload);
        $this->assertEquals('1', $payload->sub);
    }

    public function testValidateAccessTokenDifferentIpLogsWarningButSucceeds(): void
    {
        $this->loggerMock->expects($this->once())
            ->method('warning')
            ->with($this->stringContains('IP Address mismatch during token validation'));

        $token = $this->authTokenService->generateAccessToken('1', 'test@example.com', 'chrome_123', '10.0.0.1');

        // Validar com um IP diferente
        $payload = $this->authTokenService->validateAccessToken($token, '192.168.1.5');
        
        $this->assertNotNull($payload);
        $this->assertEquals('1', $payload->sub);
    }

    public function testValidateAccessTokenExpired(): void
    {
        $_ENV['JWT_EXPIRES_IN_SECONDS'] = '-3600'; // Token criado já expirado
        $service = new AuthTokenService($this->loggerMock);

        $token = $service->generateAccessToken('1', 'test@example.com', 'chrome123', '192.168.1.1');

        $this->expectException(\Exception::class);
        $service->validateAccessToken($token, '10.0.0.1');
    }

    public function testIsOriginAllowedForBypass(): void
    {
        $this->assertTrue($this->authTokenService->isOriginAllowedForBypass('https://export.aifreelas.com.br'));
        
        $this->assertFalse($this->authTokenService->isOriginAllowedForBypass('https://other.com'));
        $this->assertFalse($this->authTokenService->isOriginAllowedForBypass(null));
        
        $_ENV['CURL_SSL_NO_VERIFY'] = true; // Not related, just checking coverage
    }

    public function testIsOriginAllowedForBypassWithoutEnvVariable(): void
    {
        unset($_ENV['CURL_SSL_NO_VERIFY']); // Forçando unset
        unset($_ENV['CORS_ALLOWED_ORIGINS']);
        $service = new AuthTokenService($this->loggerMock);

        $this->assertFalse($service->isOriginAllowedForBypass('https://export.aifreelas.com.br'));
    }

    protected function tearDown(): void
    {
        unset($_ENV['JWT_SECRET_KEY']);
        unset($_ENV['JWT_EXPIRES_IN_SECONDS']);
        unset($_ENV['CORS_ALLOWED_ORIGINS']);
    }
}
