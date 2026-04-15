<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Helpers\Response;
use App\Services\ProfileService;
use App\Services\UserAuthService;

class UserController
{
    public function __construct(
        private readonly UserAuthService $authService,
        private readonly ProfileService  $profileService,
    ) {}

    /** POST /api/auth/register */
    public function register(array $params): void
    {
        $body = $this->jsonBody();

        $email    = $body['email']     ?? '';
        $password = $body['password']  ?? '';
        $fullName = $body['full_name'] ?? '';

        try {
            $user = $this->authService->register($email, $password, $fullName);

            // Pre-populate the profile with the data supplied at registration.
            $this->profileService->update($user['id'], [
                'full_name' => $user['full_name'],
                'email'     => $user['email'],
            ]);

            Response::json($user, [], 201);
        } catch (\RuntimeException $e) {
            Response::error($e->getMessage(), 422);
        }
    }

    /** POST /api/auth/login */
    public function login(array $params): void
    {
        $body = $this->jsonBody();

        $email    = $body['email']    ?? '';
        $password = $body['password'] ?? '';

        try {
            $result = $this->authService->login($email, $password);
            Response::json($result);
        } catch (\RuntimeException $e) {
            Response::error($e->getMessage(), 401);
        }
    }

    /** POST /api/auth/logout */
    public function logout(array $params): void
    {
        // requireAuth also validates the token — ensures only valid sessions are logged out
        $this->authService->requireAuth();

        $token = $this->authService->extractBearerToken();
        if ($token !== null) {
            $this->authService->logout($token);
        }

        Response::json(['logged_out' => true]);
    }

    /** GET /api/auth/me */
    public function me(array $params): void
    {
        $user = $this->authService->requireAuth();
        Response::json($user);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function jsonBody(): array
    {
        $raw = file_get_contents('php://input');
        if (!$raw) {
            return [];
        }

        $data = json_decode($raw, true);
        return is_array($data) ? $data : [];
    }
}
