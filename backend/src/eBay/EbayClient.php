<?php

declare(strict_types=1);

namespace App\eBay;

use App\eBay\EbayClientInterface;
use App\Storage\Json\TokenRepository;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

class EbayClient implements EbayClientInterface
{
    private Client $http;
    private TokenRepository $tokenRepo;
    private bool $sandbox;
    private string $apiBaseUrl;
    private string $tokenUrl;

    public function __construct(TokenRepository $tokenRepo)
    {
        $this->tokenRepo  = $tokenRepo;
        $this->sandbox    = filter_var($_ENV['EBAY_SANDBOX'] ?? 'true', FILTER_VALIDATE_BOOLEAN);
        $this->apiBaseUrl = $this->sandbox
            ? ($_ENV['EBAY_SANDBOX_API_URL'] ?? 'https://api.sandbox.ebay.com')
            : ($_ENV['EBAY_PROD_API_URL'] ?? 'https://api.ebay.com');
        $this->tokenUrl   = $this->sandbox
            ? ($_ENV['EBAY_SANDBOX_TOKEN_URL'] ?? 'https://api.sandbox.ebay.com/identity/v1/oauth2/token')
            : ($_ENV['EBAY_PROD_TOKEN_URL'] ?? 'https://api.ebay.com/identity/v1/oauth2/token');

        $this->http = new Client([
            'base_uri' => $this->apiBaseUrl,
            'timeout'  => 30,
        ]);
    }

    /**
     * Make an authenticated GET request to the eBay API.
     */
    public function get(string $path, array $query = []): array
    {
        $token = $this->getValidAccessToken();

        try {
            $response = $this->http->get($path, [
                'headers' => [
                    'Authorization'           => 'Bearer ' . $token,
                    'Content-Type'            => 'application/json',
                    'X-EBAY-C-MARKETPLACE-ID' => 'EBAY_GB',
                ],
                'query' => $query,
            ]);

            $body = (string)$response->getBody();
            $data = json_decode($body, true);
            return is_array($data) ? $data : [];
        } catch (GuzzleException $e) {
            throw new \RuntimeException('eBay API error on GET ' . $path . ': ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Exchange an authorization code for access + refresh tokens.
     */
    public function exchangeCodeForTokens(string $code): array
    {
        $clientId     = $_ENV['EBAY_CLIENT_ID'] ?? '';
        $clientSecret = $_ENV['EBAY_CLIENT_SECRET'] ?? '';
        $redirectUri  = $_ENV['EBAY_REDIRECT_URI'] ?? '';

        try {
            $response = $this->http->post($this->tokenUrl, [
                'headers' => [
                    'Content-Type'  => 'application/x-www-form-urlencoded',
                    'Authorization' => 'Basic ' . base64_encode($clientId . ':' . $clientSecret),
                ],
                'form_params' => [
                    'grant_type'   => 'authorization_code',
                    'code'         => $code,
                    'redirect_uri' => $redirectUri,
                ],
            ]);

            $data = json_decode((string)$response->getBody(), true);
            if (!is_array($data)) {
                throw new \RuntimeException('Invalid token response from eBay.');
            }
            return $data;
        } catch (GuzzleException $e) {
            throw new \RuntimeException('Token exchange failed: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Refresh the access token using the refresh token.
     */
    public function refreshAccessToken(string $refreshToken): array
    {
        $clientId     = $_ENV['EBAY_CLIENT_ID'] ?? '';
        $clientSecret = $_ENV['EBAY_CLIENT_SECRET'] ?? '';
        $scopes       = $_ENV['EBAY_SCOPES'] ?? '';

        try {
            $response = $this->http->post($this->tokenUrl, [
                'headers' => [
                    'Content-Type'  => 'application/x-www-form-urlencoded',
                    'Authorization' => 'Basic ' . base64_encode($clientId . ':' . $clientSecret),
                ],
                'form_params' => [
                    'grant_type'    => 'refresh_token',
                    'refresh_token' => $refreshToken,
                    'scope'         => $scopes,
                ],
            ]);

            $data = json_decode((string)$response->getBody(), true);
            if (!is_array($data)) {
                throw new \RuntimeException('Invalid refresh token response from eBay.');
            }
            return $data;
        } catch (GuzzleException $e) {
            throw new \RuntimeException('Token refresh failed: ' . $e->getMessage(), 0, $e);
        }
    }

    private function getValidAccessToken(): string
    {
        $tokens = $this->tokenRepo->getTokens();

        if ($tokens === null || empty($tokens['access_token'])) {
            throw new \RuntimeException('No eBay tokens found. Please connect your eBay account first.');
        }

        $expiresAt = strtotime($tokens['expires_at'] ?? '');
        if ($expiresAt !== false && $expiresAt <= time() + 60) {
            // Token expired or about to expire — refresh it
            $refreshed = $this->refreshAccessToken($tokens['refresh_token']);
            $tokens    = $this->normalizeTokenResponse(
                $refreshed,
                $tokens['refresh_token'],
                $tokens['refresh_expires_at'] ?? null
            );
            $this->tokenRepo->saveTokens($tokens);
        }

        return $tokens['access_token'];
    }

    public function normalizeTokenResponse(
        array $response,
        ?string $existingRefreshToken = null,
        ?string $existingRefreshExpiry = null
    ): array {
        $expiresIn        = (int)($response['expires_in'] ?? 7200);
        $refreshExpiresIn = (int)($response['refresh_token_expires_in'] ?? 47304000); // ~18 months

        return [
            'access_token'       => $response['access_token'] ?? '',
            'refresh_token'      => $response['refresh_token'] ?? $existingRefreshToken ?? '',
            'token_type'         => $response['token_type'] ?? 'User Access Token',
            'expires_at'         => date('c', time() + $expiresIn),
            'refresh_expires_at' => ($response['refresh_token'] ?? null)
                ? date('c', time() + $refreshExpiresIn)
                : ($existingRefreshExpiry ?? date('c', time() + $refreshExpiresIn)),
            'scopes'             => explode(' ', $response['scope'] ?? $_ENV['EBAY_SCOPES'] ?? ''),
        ];
    }

    public function getApiBaseUrl(): string
    {
        return $this->apiBaseUrl;
    }
}
