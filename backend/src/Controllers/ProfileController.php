<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Helpers\Response;
use App\Services\ProfileService;
use App\Services\UserAuthService;

class ProfileController
{
    public function __construct(
        private readonly UserAuthService $authService,
        private readonly ProfileService  $profileService,
    ) {}

    /** GET /api/profile */
    public function show(array $params): void
    {
        $user    = $this->authService->requireAuth();
        $profile = $this->profileService->get($user['id']);
        Response::json($profile);
    }

    /** PUT /api/profile */
    public function update(array $params): void
    {
        $user = $this->authService->requireAuth();

        $raw  = file_get_contents('php://input');
        $body = [];
        if ($raw) {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $body = $decoded;
            }
        }

        try {
            $profile = $this->profileService->update($user['id'], $body);
            Response::json($profile);
        } catch (\InvalidArgumentException $e) {
            Response::error($e->getMessage(), 422);
        }
    }
}
