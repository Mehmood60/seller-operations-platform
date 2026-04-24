<?php

declare(strict_types=1);

namespace App\Services;

use App\eBay\EbayClientInterface;
use App\Storage\Json\ListingRepository;

class EbayCompetitorService
{
    public function __construct(
        private readonly ListingRepository    $listingRepo,
        private readonly EbayClientInterface  $ebayClient,
    ) {}

    // ── Check a single listing against eBay Germany competitors ──────────────

    public function checkCompetitors(string $listingId): array
    {
        $listing = $this->listingRepo->find($listingId);
        if ($listing === null) {
            throw new \RuntimeException("Listing {$listingId} not found.");
        }

        $title = trim($listing['title'] ?? '');
        if ($title === '') {
            throw new \RuntimeException('Listing has no title to search for.');
        }

        $keywords = $this->buildKeywords($title);
        $results  = $this->ebayClient->searchItems($keywords, 10);

        $lowestTotal = null;
        $lowestUrl   = null;
        foreach ($results as $item) {
            $total = (float)$item['total_price'];
            if ($lowestTotal === null || $total < $lowestTotal) {
                $lowestTotal = $total;
                $lowestUrl   = $item['url'];
            }
        }

        $check = [
            'last_checked_at' => date('c'),
            'keywords_used'   => $keywords,
            'result_count'    => count($results),
            'lowest_total'    => $lowestTotal !== null ? number_format($lowestTotal, 2, '.', '') : null,
            'lowest_url'      => $lowestUrl,
            'results'         => $results,
        ];

        $listing['competitor_check'] = $check;
        $this->listingRepo->save($listing);

        return $check;
    }

    // ── Check all active listings (for cron use) ──────────────────────────────

    public function checkAllCompetitors(): array
    {
        $output = [];
        foreach ($this->listingRepo->findAllActive() as $listing) {
            try {
                $check    = $this->checkCompetitors($listing['id']);
                $output[] = [
                    'id'           => $listing['id'],
                    'title'        => $listing['title'] ?? '',
                    'lowest_total' => $check['lowest_total'],
                    'result_count' => $check['result_count'],
                ];
            } catch (\Throwable $e) {
                $output[] = [
                    'id'    => $listing['id'],
                    'title' => $listing['title'] ?? '',
                    'error' => $e->getMessage(),
                ];
            }
        }
        return $output;
    }

    // ── Internal ──────────────────────────────────────────────────────────────

    private function buildKeywords(string $title): string
    {
        // Strip special chars, take first 6 words — specific enough to find
        // similar items, broad enough to get results
        $clean = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $title) ?? $title;
        $words = array_filter(explode(' ', $clean));
        return implode(' ', array_slice(array_values($words), 0, 6));
    }
}
