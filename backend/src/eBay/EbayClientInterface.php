<?php

declare(strict_types=1);

namespace App\eBay;

interface EbayClientInterface
{
    /**
     * Make an authenticated GET request to the eBay REST API.
     */
    public function get(string $path, array $query = []): array;

    /**
     * Exchange an OAuth authorization code for access + refresh tokens.
     */
    public function exchangeCodeForTokens(string $code): array;

    /**
     * Use a refresh token to get a new access token.
     */
    public function refreshAccessToken(string $refreshToken): array;

    /**
     * Normalize a raw eBay token response into our internal token shape.
     */
    public function normalizeTokenResponse(
        array $response,
        ?string $existingRefreshToken = null,
        ?string $existingRefreshExpiry = null,
    ): array;
}
