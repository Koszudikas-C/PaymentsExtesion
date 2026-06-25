<?php

declare(strict_types=1);

namespace App\Interfaces\Services;

interface AuthTokenServiceInterface
{
    /**
     * Generates a short-lived access token.
     */
    public function generateAccessToken(string $customerId, string $email, ?string $chromeIdentityId, ?string $clientIp): string;

    /**
     * Generates a long-lived secure random refresh token.
     */
    public function generateRefreshToken(): string;

    /**
     * Validates an access token and performs IP checking.
     * Returns the payload object if valid, throws an exception otherwise.
     */
    public function validateAccessToken(string $token, ?string $currentIp = null): \stdClass;

    /**
     * Helper to determine if the origin is allowed without token (bypass).
     */
    public function isOriginAllowedForBypass(?string $origin): bool;
}
