<?php

declare(strict_types=1);

namespace App\Services;

use App\eBay\EbayClientInterface;
use App\eBay\OrderMapper;
use App\eBay\ListingMapper;
use App\Storage\Json\OrderRepository;
use App\Storage\Json\ListingRepository;

class EbaySyncService
{
    private string $syncStateFile;

    public function __construct(
        private readonly EbayClientInterface $ebayClient,
        private readonly OrderRepository $orderRepo,
        private readonly ListingRepository $listingRepo,
        string $dataDir,
    ) {
        $this->syncStateFile = rtrim($dataDir, '/\\') . DIRECTORY_SEPARATOR . 'sync_state.json';
        $this->ensureSyncState();
    }

    public function syncOrders(): array
    {
        $state        = $this->getSyncState();
        $lastSyncedAt = $state['orders']['last_synced_at'] ?? null;
        $synced       = 0;
        $offset       = 0;
        $limit        = 50;
        $mapper       = new OrderMapper();
        $total        = 0;

        do {
            $query = [
                'limit'  => $limit,
                'offset' => $offset,
            ];

            if ($lastSyncedAt) {
                $query['filter'] = 'lastmodifieddate:[' . $lastSyncedAt . '..]';
            }

            try {
                $response = $this->ebayClient->get('/sell/fulfillment/v1/order', $query);
            } catch (\Throwable $e) {
                return ['error' => $e->getMessage(), 'synced' => $synced];
            }

            $orders = $response['orders'] ?? [];
            $total  = $response['total'] ?? 0;

            foreach ($orders as $ebayOrder) {
                $mapped = $mapper->map($ebayOrder);
                $this->orderRepo->save($mapped);
                $synced++;
            }

            $offset += $limit;
        } while ($offset < $total && count($orders) === $limit);

        // Update sync state
        $state['orders']['last_synced_at'] = date('c');
        $state['orders']['total_synced']   = ($state['orders']['total_synced'] ?? 0) + $synced;
        $this->saveSyncState($state);

        return ['synced' => $synced, 'total_available' => $total];
    }

    public function syncListings(): array
    {
        $state  = $this->getSyncState();
        $synced = 0;
        $limit  = 100;
        $offset = 0;
        $mapper = new ListingMapper();
        $total  = 0;

        do {
            try {
                $response = $this->ebayClient->get('/sell/inventory/v1/inventory_item', [
                    'limit'  => $limit,
                    'offset' => $offset,
                ]);
            } catch (\Throwable $e) {
                return ['error' => $e->getMessage(), 'synced' => $synced];
            }

            $items = $response['inventoryItems'] ?? [];
            $total = $response['total'] ?? 0;

            foreach ($items as $item) {
                $itemId = $item['sku'] ?? '';
                if (empty($itemId)) {
                    continue;
                }

                $mapped = $mapper->mapInventoryItem($item, $itemId);
                $this->listingRepo->save($mapped);
                $synced++;
            }

            $offset += $limit;
        } while ($offset < $total && count($items) === $limit);

        // Update sync state
        $state['listings']['last_synced_at'] = date('c');
        $state['listings']['total_synced']   = ($state['listings']['total_synced'] ?? 0) + $synced;
        $this->saveSyncState($state);

        return ['synced' => $synced, 'total_available' => $total];
    }

    public function getSyncStateData(): array
    {
        return $this->getSyncState();
    }

    private function getSyncState(): array
    {
        if (!file_exists($this->syncStateFile)) {
            return $this->defaultSyncState();
        }
        $data = json_decode(file_get_contents($this->syncStateFile), true);
        return is_array($data) ? $data : $this->defaultSyncState();
    }

    private function saveSyncState(array $state): void
    {
        file_put_contents($this->syncStateFile, json_encode($state, JSON_PRETTY_PRINT));
    }

    private function defaultSyncState(): array
    {
        return [
            'orders'   => ['last_synced_at' => null, 'last_offset' => 0, 'total_synced' => 0],
            'listings' => ['last_synced_at' => null, 'total_synced' => 0],
        ];
    }

    private function ensureSyncState(): void
    {
        $dir = dirname($this->syncStateFile);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        if (!file_exists($this->syncStateFile)) {
            file_put_contents(
                $this->syncStateFile,
                json_encode($this->defaultSyncState(), JSON_PRETTY_PRINT)
            );
        }
    }
}
