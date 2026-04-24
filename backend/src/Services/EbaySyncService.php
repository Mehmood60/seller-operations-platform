<?php

declare(strict_types=1);

namespace App\Services;

use App\eBay\EbayClientInterface;
use App\eBay\OrderMapper;
use App\eBay\ListingMapper;
use App\Storage\Json\OrderRepository;
use App\Storage\Json\ListingRepository;
use App\Storage\Json\TokenRepository;

class EbaySyncService
{
    private string $syncStateFile;

    public function __construct(
        private readonly EbayClientInterface $ebayClient,
        private readonly OrderRepository $orderRepo,
        private readonly ListingRepository $listingRepo,
        private readonly TokenRepository $tokenRepo,
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
        $tokens = $this->tokenRepo->getTokens();
        if ($tokens === null) {
            return ['error' => 'eBay account not connected. Please connect in Settings.', 'synced' => 0];
        }

        $state         = $this->getSyncState();
        $synced        = 0;
        $page          = 1;
        $perPage       = 200;
        $mapper        = new ListingMapper();
        $total         = 0;
        $pages         = 1;
        $syncedEbayIds = [];

        do {
            try {
                $response = $this->ebayClient->getMyActiveSellListings($page, $perPage);
            } catch (\Throwable $e) {
                return ['error' => $e->getMessage(), 'synced' => $synced];
            }

            $items = $response['items'] ?? [];
            $total = $response['total'] ?? 0;
            $pages = $response['pages'] ?? 1;

            foreach ($items as $item) {
                if (empty($item['itemId'])) {
                    continue;
                }

                try {
                    $details = $this->ebayClient->getItemDetails($item['itemId']);
                    if (!empty($details)) {
                        $item = array_merge($item, $details);
                    }
                } catch (\Throwable) {
                    // Enrichment is non-fatal
                }

                $mapped     = $mapper->mapTradingApiItem($item);
                $ebayItemId = $item['itemId'];
                $syncedEbayIds[] = $ebayItemId;

                $existing = $this->listingRepo->findByEbayItemId($ebayItemId);

                if ($existing !== null && $existing['id'] !== $ebayItemId) {
                    // draft_xxxx was published — merge into the draft entry, delete stale eBay-ID file
                    $mapped['id']             = $existing['id'];
                    $mapped['item_specifics'] = $existing['item_specifics'] ?? $mapped['item_specifics'] ?? [];
                    $mapped['keywords']       = $existing['keywords']       ?? [];
                    $mapped['source_url']     = $existing['source_url']     ?? '';
                    $mapped['shipping']       = $existing['shipping']       ?? [];
                    // Prefer locally-edited description; fall back to freshly fetched
                    $mapped['description']    = ($existing['description'] ?? '') !== ''
                        ? $existing['description']
                        : ($mapped['description'] ?? '');
                    $this->listingRepo->delete($ebayItemId);

                } elseif ($existing !== null) {
                    // Same-ID re-sync: always preserve user-edited fields so they
                    // are not wiped by an eBay sync that lacks certain data.
                    $mapped['source_url']     = $existing['source_url']     ?? '';
                    $mapped['keywords']       = $existing['keywords']       ?? [];
                    $mapped['shipping']       = $existing['shipping']       ?? [];
                    $mapped['monitor']        = $existing['monitor']        ?? [];
                    $mapped['competitor_check'] = $existing['competitor_check'] ?? null;
                    // Keep item specifics & description if user has already edited them
                    if (!empty($existing['item_specifics'])) {
                        $mapped['item_specifics'] = $existing['item_specifics'];
                    }
                    if (($existing['description'] ?? '') !== '') {
                        $mapped['description'] = $existing['description'];
                    }
                }

                $this->listingRepo->save($mapped);
                $synced++;
            }

            $page++;
        } while ($page <= $pages);

        // Mark local ACTIVE listings that are no longer on eBay as ENDED (e.g. deleted on eBay)
        if (!empty($syncedEbayIds)) {
            foreach ($this->listingRepo->findAllActive() as $localListing) {
                $localEbayId = $localListing['ebay_item_id'] ?? '';
                if ($localEbayId !== '' && !in_array($localEbayId, $syncedEbayIds, true)) {
                    $this->listingRepo->save(array_merge($localListing, ['status' => 'ENDED']));
                }
            }
        }

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
