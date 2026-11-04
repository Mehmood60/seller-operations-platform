<?php

declare(strict_types=1);

namespace App\Services;

use App\eBay\EbayClientInterface;
use App\eBay\OrderMapper;
use App\eBay\ListingMapper;
use App\Storage\Json\EbayChargeRepository;
use App\Storage\Json\OrderRepository;
use App\Storage\Json\ListingRepository;
use App\Storage\Json\TokenRepository;

class EbaySyncService
{
    private string $syncStateFile;
    private EbayChargeRepository $chargeRepo;

    /**
     * How far back each earnings sync re-reads finance transactions. Ad fees,
     * refunds and shipping labels post days or weeks after the sale.
     */
    private const EARNINGS_LOOKBACK_DAYS = 60;

    public function __construct(
        private readonly EbayClientInterface $ebayClient,
        private readonly OrderRepository $orderRepo,
        private readonly ListingRepository $listingRepo,
        private readonly TokenRepository $tokenRepo,
        string $dataDir,
    ) {
        $this->syncStateFile = rtrim($dataDir, '/\\') . DIRECTORY_SEPARATOR . 'sync_state.json';
        $this->chargeRepo    = new EbayChargeRepository($dataDir);
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

            // One full pass so orders synced before fees were recorded pick them up.
            $filterFrom = empty($state['orders']['earnings_backfilled'])
                ? null
                : $this->toEbayTimestamp($lastSyncedAt);
            if ($filterFrom !== null) {
                $query['filter'] = 'lastmodifieddate:[' . $filterFrom . '..]';
            }

            try {
                $response = $this->ebayClient->get('/sell/fulfillment/v1/order', $query);
            } catch (\Throwable $e) {
                return ['error' => $e->getMessage(), 'synced' => $synced];
            }

            $orders = $response['orders'] ?? [];
            $total  = $response['total'] ?? 0;

            foreach ($orders as $ebayOrder) {
                $mapped   = $mapper->map($ebayOrder);
                $existing = $this->orderRepo->find($mapped['id']);
                // The actual payout from the Finances API beats the order's own estimate.
                if (($existing['earnings']['source'] ?? '') === 'finances') {
                    $mapped['earnings'] = $existing['earnings'];
                }
                // eBay knows nothing of what was recorded here; keep it.
                if ($existing !== null) {
                    if (isset($existing['fulfillment'])) {
                        $mapped['fulfillment'] = $existing['fulfillment'];
                    }
                    $mapped['notes'] = $existing['notes'] ?? $mapped['notes'];
                    foreach (['tracking_number', 'carrier', 'shipped_at'] as $field) {
                        if (empty($mapped['shipping'][$field]) && !empty($existing['shipping'][$field])) {
                            $mapped['shipping'][$field] = $existing['shipping'][$field];
                        }
                    }
                }
                $this->orderRepo->save($mapped);
                $synced++;
            }

            $offset += $limit;
        } while ($offset < $total && count($orders) === $limit);

        // Update sync state
        $state['orders']['last_synced_at'] = date('c');
        $state['orders']['total_synced']   = ($state['orders']['total_synced'] ?? 0) + $synced;
        $state['orders']['earnings_backfilled'] = true;
        $this->saveSyncState($state);

        // Non-fatal: without the sell.finances scope orders keep their estimate.
        try {
            $earnings = $this->syncEarnings();
        } catch (\Throwable $e) {
            $state = $this->getSyncState();
            $state['earnings']['error'] = $e->getMessage();
            $this->saveSyncState($state);
            $earnings = ['error' => $e->getMessage()];
        }

        return ['synced' => $synced, 'total_available' => $total, 'earnings' => $earnings];
    }

    /**
     * Pull Finances API transactions and record, per order, what eBay actually pays
     * out after fees, ad fees, labels and refunds. Charges tied to no order
     * (insertion fees, subscription) are kept separately for the profit summary.
     */
    public function syncEarnings(): array
    {
        $state = $this->getSyncState();
        $last  = $state['earnings']['last_synced_at'] ?? null;
        $now   = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        $from = $last !== null
            ? (new \DateTimeImmutable($last))->modify('-' . self::EARNINGS_LOOKBACK_DAYS . ' days')
            : $this->oldestOrderDate();
        if ($from === null) {
            return ['updated' => 0, 'charges' => 0];
        }

        $range   = 'transactionDate:[' . $this->toEbayTimestamp($from->format('c')) . '..'
            . $this->toEbayTimestamp($now->format('c')) . ']';
        $byOrder = [];
        $charges = [];
        $offset  = 0;
        $limit   = 1000;

        do {
            $page = $this->ebayClient->getFinanceTransactions([
                'filter' => $range,
                'limit'  => $limit,
                'offset' => $offset,
            ]);
            $transactions = $page['transactions'] ?? [];

            foreach ($transactions as $tx) {
                $orderId = EarningsCalculator::orderIdOf($tx);
                if ($orderId !== null) {
                    $byOrder[$orderId][EarningsCalculator::key($tx)] = EarningsCalculator::slim($tx);
                } elseif (EarningsCalculator::isAccountCharge($tx)) {
                    $charges[] = EarningsCalculator::toCharge($tx);
                }
            }

            $offset += $limit;
        } while ($offset < (int)($page['total'] ?? 0) && count($transactions) === $limit);

        $updated = 0;
        foreach ($byOrder as $orderId => $transactions) {
            $order = $this->orderRepo->find((string)$orderId);
            if ($order === null) {
                continue;
            }
            // Merge with what earlier syncs saw: the look-back window can cut an
            // order's transactions in half.
            $known = ($order['earnings']['source'] ?? '') === 'finances'
                ? ($order['earnings']['transactions'] ?? [])
                : [];
            $order['earnings'] = EarningsCalculator::fromTransactions(array_merge($known, $transactions), $order);
            $this->orderRepo->save($order);
            $updated++;
        }

        $this->chargeRepo->upsertMany($charges);

        $state = $this->getSyncState();
        $state['earnings'] = ['last_synced_at' => $now->format('c'), 'error' => null];
        $this->saveSyncState($state);

        return ['updated' => $updated, 'charges' => count($charges)];
    }

    private function oldestOrderDate(): ?\DateTimeImmutable
    {
        $dates = array_filter(array_column($this->orderRepo->findAll([], 1, 100000)['items'], 'created_at'));
        if ($dates === []) {
            return null;
        }
        try {
            return (new \DateTimeImmutable(min($dates)))->modify('-1 day');
        } catch (\Exception) {
            return null;
        }
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

    /**
     * eBay's Fulfillment API only accepts UTC ISO 8601 timestamps of the form
     * 2026-07-09T07:43:38.000Z. Anything else (e.g. date('c')'s +00:00 offset)
     * comes back as errorId 30810 "Invalid date format".
     */
    private function toEbayTimestamp(?string $timestamp): ?string
    {
        if ($timestamp === null || $timestamp === '') {
            return null;
        }

        try {
            $date = new \DateTimeImmutable($timestamp);
        } catch (\Exception $e) {
            return null;
        }

        return $date->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.v\Z');
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
