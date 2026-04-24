<?php

declare(strict_types=1);

namespace App\Services;

use App\eBay\EbayClientInterface;
use App\Storage\Json\ListingRepository;

class PriceMonitorService
{
    public function __construct(
        private readonly ProductScraperService $scraper,
        private readonly ListingRepository     $listingRepo,
        private readonly EbayClientInterface   $ebayClient,
    ) {}

    // ── List all listings that have a source URL ──────────────────────────────

    public function status(): array
    {
        $items = [];
        foreach ($this->listingRepo->findMonitorable() as $listing) {
            $items[] = $this->summarise($listing);
        }
        return $items;
    }

    // ── Check a single listing ────────────────────────────────────────────────

    public function checkListing(string $listingId): array
    {
        $listing = $this->listingRepo->find($listingId);
        if ($listing === null) {
            throw new \RuntimeException("Listing {$listingId} not found.");
        }

        $sourceUrl = $listing['source_url'] ?? '';
        if ($sourceUrl === '') {
            throw new \RuntimeException('Listing has no source URL to monitor.');
        }

        $scraped    = $this->scraper->scrape($sourceUrl);
        $now        = date('c');
        $newSrcPrice = (float)($scraped['price']['value'] ?? 0);
        $ebayPrice   = (float)($listing['price']['value'] ?? 0);

        $listing['monitor'] = array_merge($listing['monitor'] ?? [], [
            'enabled'          => $listing['monitor']['enabled'] ?? false,
            'last_checked_at'  => $now,
            'source_price'     => $scraped['price']['value'] ?? '',
            'source_currency'  => $scraped['price']['currency'] ?? 'EUR',
        ]);

        $priceChanged = false;
        $suggestedEbayPrice = null;

        if ($newSrcPrice > 0 && $ebayPrice > 0) {
            $lastSrcPrice = (float)($listing['monitor']['last_source_price'] ?? 0);
            $delta        = $lastSrcPrice > 0 ? abs($newSrcPrice - $lastSrcPrice) : 0;

            if ($delta > 0.01) {
                // Preserve the seller's markup ratio
                $ratio              = $lastSrcPrice > 0 ? ($ebayPrice / $lastSrcPrice) : 2.8;
                $suggestedEbayPrice = round($newSrcPrice * $ratio, 2);
                $priceChanged       = true;

                $listing['monitor']['pending_change'] = [
                    'old_source_price'    => (string)$lastSrcPrice,
                    'new_source_price'    => $scraped['price']['value'] ?? '',
                    'suggested_ebay_price'=> (string)$suggestedEbayPrice,
                    'detected_at'         => $now,
                    'applied'             => false,
                ];
            }
        }

        $listing['monitor']['last_source_price'] = $scraped['price']['value'] ?? '';
        $this->listingRepo->save($listing);

        return array_merge($this->summarise($listing), [
            'price_changed'       => $priceChanged,
            'suggested_ebay_price'=> $suggestedEbayPrice,
        ]);
    }

    // ── Check all monitor-enabled listings ────────────────────────────────────

    public function checkAll(): array
    {
        $results = [];
        foreach ($this->listingRepo->findMonitorable() as $listing) {
            if (!($listing['monitor']['enabled'] ?? false)) {
                continue;
            }
            try {
                $results[] = $this->checkListing($listing['id']);
            } catch (\Throwable $e) {
                $results[] = [
                    'id'    => $listing['id'],
                    'title' => $listing['title'] ?? '',
                    'error' => $e->getMessage(),
                ];
            }
        }
        return $results;
    }

    // ── Apply a suggested price update to eBay ────────────────────────────────

    public function applyUpdate(string $listingId, float $newPrice): void
    {
        $listing = $this->listingRepo->find($listingId);
        if ($listing === null) {
            throw new \RuntimeException("Listing {$listingId} not found.");
        }
        if ($newPrice <= 0) {
            throw new \InvalidArgumentException('Price must be greater than 0.');
        }

        $listing['price']['value'] = number_format($newPrice, 2, '.', '');

        if (!empty($listing['monitor']['pending_change'])) {
            $listing['monitor']['pending_change']['applied']    = true;
            $listing['monitor']['pending_change']['applied_at'] = date('c');
        }

        $this->listingRepo->save($listing);

        if (($listing['status'] ?? '') === 'ACTIVE' && !empty($listing['ebay_item_id'])) {
            $this->ebayClient->reviseFixedPriceItem($listing);
        }
    }

    // ── Enable / disable monitoring for a listing ─────────────────────────────

    public function toggle(string $listingId, bool $enabled): array
    {
        $listing = $this->listingRepo->find($listingId);
        if ($listing === null) {
            throw new \RuntimeException("Listing {$listingId} not found.");
        }

        $listing['monitor'] = array_merge($listing['monitor'] ?? [], ['enabled' => $enabled]);
        $this->listingRepo->save($listing);

        return $this->summarise($listing);
    }

    // ── Internal ──────────────────────────────────────────────────────────────

    private function summarise(array $listing): array
    {
        $cc = $listing['competitor_check'] ?? null;

        $images = $listing['images'] ?? [];
        $image  = is_array($images) && !empty($images) ? (string)$images[0] : null;

        return [
            'id'         => $listing['id'],
            'title'      => $listing['title'] ?? '',
            'status'     => $listing['status'] ?? 'UNKNOWN',
            'image'      => $image,
            'ebay_price' => $listing['price'] ?? ['value' => '0', 'currency' => 'EUR'],
            'source_url' => $listing['source_url'] ?? '',
            'listing_url'=> $listing['listing_url'] ?? '',
            'monitor'    => array_merge([
                'enabled'         => false,
                'last_checked_at' => null,
                'source_price'    => null,
                'source_currency' => null,
                'pending_change'  => null,
            ], $listing['monitor'] ?? []),
            'competitor_check' => $cc ? [
                'last_checked_at' => $cc['last_checked_at'] ?? null,
                'lowest_total'    => $cc['lowest_total'] ?? null,
                'lowest_url'      => $cc['lowest_url'] ?? null,
                'result_count'    => $cc['result_count'] ?? 0,
                'keywords_used'   => $cc['keywords_used'] ?? null,
            ] : null,
        ];
    }
}
