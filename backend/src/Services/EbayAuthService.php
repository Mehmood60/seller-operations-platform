<?php

declare(strict_types=1);

namespace App\Services;

use App\eBay\EbayClientInterface;
use App\Storage\Json\TokenRepository;

class EbayAuthService
{
    public function __construct(
        private readonly TokenRepository $tokenRepo,
        private readonly EbayClientInterface $ebayClient,
    ) {}

    public function getConnectionStatus(): array
    {
        $connected = $this->tokenRepo->isConnected();
        $tokens    = $connected ? $this->tokenRepo->getTokens() : null;

        return [
            'connected'          => $connected,
            'expires_at'         => $tokens['expires_at'] ?? null,
            'refresh_expires_at' => $tokens['refresh_expires_at'] ?? null,
            'scopes'             => $tokens['scopes'] ?? [],
        ];
    }

    public function getConnectUrl(): string
    {
        $sandbox     = filter_var($_ENV['EBAY_SANDBOX'] ?? 'true', FILTER_VALIDATE_BOOLEAN);
        $authBaseUrl = $sandbox
            ? ($_ENV['EBAY_SANDBOX_AUTH_URL'] ?? 'https://auth.sandbox.ebay.com/oauth2/authorize')
            : ($_ENV['EBAY_PROD_AUTH_URL'] ?? 'https://auth.ebay.com/oauth2/authorize');

        $state = bin2hex(random_bytes(16));

        // PHP_QUERY_RFC3986 encodes spaces as %20 (not +).
        // eBay's OAuth server requires %20 in the scope parameter.
        $params = http_build_query([
            'client_id'     => $_ENV['EBAY_CLIENT_ID'] ?? '',
            'redirect_uri'  => $_ENV['EBAY_REDIRECT_URI'] ?? '',
            'response_type' => 'code',
            'scope'         => $_ENV['EBAY_SCOPES'] ?? '',
            'state'         => $state,
        ], '', '&', PHP_QUERY_RFC3986);

        return $authBaseUrl . '?' . $params;
    }

    public function handleCallback(string $code): array
    {
        $response = $this->ebayClient->exchangeCodeForTokens($code);
        $tokens   = $this->ebayClient->normalizeTokenResponse($response);
        $this->tokenRepo->saveTokens($tokens);

        return [
            'connected'  => true,
            'expires_at' => $tokens['expires_at'],
        ];
    }

    public function disconnect(): void
    {
        $this->tokenRepo->clearTokens();
    }
}
