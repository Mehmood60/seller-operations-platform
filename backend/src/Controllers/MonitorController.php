<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Helpers\Response;
use App\Services\PriceMonitorService;

class MonitorController
{
    public function __construct(
        private readonly PriceMonitorService $monitorService,
    ) {}

    public function status(array $params): void
    {
        Response::json($this->monitorService->status());
    }

    public function checkOne(array $params): void
    {
        try {
            Response::json($this->monitorService->checkListing($params['id'] ?? ''));
        } catch (\RuntimeException $e) {
            Response::error($e->getMessage(), 422);
        }
    }

    public function checkAll(array $params): void
    {
        Response::json($this->monitorService->checkAll());
    }

    public function toggle(array $params): void
    {
        $body    = json_decode((string) file_get_contents('php://input'), true) ?? [];
        $enabled = (bool)($body['enabled'] ?? false);
        try {
            Response::json($this->monitorService->toggle($params['id'] ?? '', $enabled));
        } catch (\RuntimeException $e) {
            Response::error($e->getMessage(), 422);
        }
    }

    public function applyUpdate(array $params): void
    {
        $body     = json_decode((string) file_get_contents('php://input'), true) ?? [];
        $newPrice = (float)($body['new_price'] ?? 0);
        if ($newPrice <= 0) {
            Response::error('new_price must be > 0.', 422);
            return;
        }
        try {
            $this->monitorService->applyUpdate($params['id'] ?? '', $newPrice);
            Response::json(['updated' => true]);
        } catch (\Throwable $e) {
            Response::error($e->getMessage(), 422);
        }
    }
}
