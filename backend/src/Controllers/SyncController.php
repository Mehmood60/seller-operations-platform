<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\EbaySyncService;
use App\Helpers\Response;

class SyncController
{
    public function __construct(private readonly EbaySyncService $syncService) {}

    public function syncOrders(array $params): void
    {
        try {
            $result = $this->syncService->syncOrders();
            Response::json($result);
        } catch (\Throwable $e) {
            Response::error('Order sync failed: ' . $e->getMessage(), 500);
        }
    }

    public function syncListings(array $params): void
    {
        try {
            $result = $this->syncService->syncListings();
            Response::json($result);
        } catch (\Throwable $e) {
            Response::error('Listing sync failed: ' . $e->getMessage(), 500);
        }
    }

    public function status(array $params): void
    {
        Response::json($this->syncService->getSyncStateData());
    }
}
