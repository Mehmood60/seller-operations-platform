<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\EbayAuthService;
use App\Helpers\Response;

class AuthController
{
    public function __construct(private readonly EbayAuthService $authService) {}

    public function status(array $params): void
    {
        Response::json($this->authService->getConnectionStatus());
    }

    public function connect(array $params): void
    {
        $url = $this->authService->getConnectUrl();
        Response::redirect($url);
    }

    public function callback(array $params): void
    {
        $code  = $_GET['code'] ?? '';
        $error = $_GET['error'] ?? '';

        if ($error) {
            $frontendUrl = $_ENV['FRONTEND_URL'] ?? 'http://localhost:3000';
            Response::redirect($frontendUrl . '/settings?error=' . urlencode($error));
            return;
        }

        if (empty($code)) {
            Response::error('Authorization code missing.', 400);
            return;
        }

        try {
            $this->authService->handleCallback($code);
            $frontendUrl = $_ENV['FRONTEND_URL'] ?? 'http://localhost:3000';
            Response::redirect($frontendUrl . '/settings?connected=1');
        } catch (\Throwable $e) {
            Response::error('OAuth callback failed: ' . $e->getMessage(), 500);
        }
    }

    public function disconnect(array $params): void
    {
        $this->authService->disconnect();
        Response::json(['disconnected' => true]);
    }
}
