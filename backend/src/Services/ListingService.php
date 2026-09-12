<?php

declare(strict_types=1);

namespace App\Services;

use App\eBay\EbayClientInterface;
use App\Storage\Json\ListingRepository;

class ListingService
{
    public function __construct(
        private readonly ListingRepository $repo,
        private readonly ?EbayClientInterface $ebayClient = null,
    ) {}

    public function list(array $filters = [], int $page = 1, int $limit = 25): array
    {
        return $this->repo->findAll($filters, $page, $limit);
    }

    public function get(string $id): ?array
    {
        return $this->repo->find($id);
    }

    public function createDraft(array $data): array
    {
        $id = 'draft_' . bin2hex(random_bytes(8));

        $listing = [
            'id'                  => $id,
            'ebay_item_id'        => '',
            'title'               => $data['title'] ?? '',
            'sku'                 => $data['sku'] ?? '',
            'status'              => 'DRAFT',
            'category'            => ['ebay_category_id' => $data['category_id'] ?? '', 'name' => $data['category'] ?? ''],
            'price'               => ['value' => $data['price'] ?? '0.00', 'currency' => 'EUR'],
            'quantity'            => ['available' => (int)($data['quantity'] ?? 1), 'sold' => 0],
            'images'              => $data['images'] ?? [],
            'condition'           => $data['condition'] ?? 'New',
            'description'         => $data['description'] ?? '',
            'description_snippet' => mb_substr(strip_tags($data['description'] ?? ''), 0, 200),
            'keywords'            => $data['keywords'] ?? [],
            'item_specifics'      => $data['item_specifics'] ?? [],
            'shipping'            => $data['shipping'] ?? [],
            'source_url'          => $data['source_url'] ?? '',
            'listing_url'         => '',
            'listed_at'           => '',
            'ends_at'             => '',
            'synced_at'           => '',
            'created_at'          => date('c'),
        ];

        return $this->repo->save($listing);
    }

    public function updateDraft(string $id, array $data): ?array
    {
        $existing = $this->repo->find($id);
        if ($existing === null) {
            return null;
        }

        $merged = array_merge($existing, [
            'title'               => $data['title']       ?? $existing['title'],
            'sku'                 => $data['sku']          ?? $existing['sku'],
            'category'            => (isset($data['category']) || isset($data['category_id']))
                ? [
                    'ebay_category_id' => $data['category_id'] ?? $existing['category']['ebay_category_id'] ?? '',
                    'name'             => $data['category']    ?? $existing['category']['name'] ?? '',
                  ]
                : $existing['category'],
            'price'               => isset($data['price'])
                ? ['value' => $data['price'], 'currency' => 'EUR']
                : $existing['price'],
            'quantity'            => isset($data['quantity'])
                ? ['available' => (int)$data['quantity'], 'sold' => $existing['quantity']['sold'] ?? 0]
                : $existing['quantity'],
            'images'              => $data['images']      ?? $existing['images'],
            'condition'           => $data['condition']   ?? $existing['condition'],
            'description'         => $data['description'] ?? $existing['description'],
            'description_snippet' => mb_substr(strip_tags($data['description'] ?? $existing['description'] ?? ''), 0, 200),
            'keywords'            => $data['keywords']       ?? $existing['keywords'] ?? [],
            'item_specifics'      => $data['item_specifics'] ?? $existing['item_specifics'] ?? [],
            'shipping'            => $data['shipping']       ?? $existing['shipping'],
            'source_url'          => $data['source_url']  ?? $existing['source_url'] ?? '',
        ]);

        return $this->repo->save($merged);
    }

    public function publish(string $id): array
    {
        if ($this->ebayClient === null) {
            throw new \RuntimeException('eBay client not available.');
        }

        $draft = $this->repo->find($id);
        if ($draft === null) {
            throw new \InvalidArgumentException('Listing not found: ' . $id);
        }
        if (($draft['status'] ?? '') !== 'DRAFT') {
            throw new \InvalidArgumentException('Only drafts can be published. Current status: ' . ($draft['status'] ?? 'unknown'));
        }

        $result = $this->ebayClient->addFixedPriceItem($draft);

        $ebayItemId = $result['ebay_item_id'] ?? '';
        $domain     = str_contains($_ENV['EBAY_SITE_ID'] ?? '77', '77') ? 'ebay.de' : 'ebay.com';

        $updated = array_merge($draft, [
            'status'       => 'ACTIVE',
            'ebay_item_id' => $ebayItemId,
            // Uploaded images come back as eBay-hosted URLs: store those so the
            // draft's base64 copies are not kept and re-uploaded on every edit.
            'images'       => !empty($result['picture_urls']) ? $result['picture_urls'] : $draft['images'],
            'listed_at'    => $result['start_time'] ?? date('c'),
            'ends_at'      => $result['end_time']   ?? '',
            'listing_url'  => $ebayItemId ? "https://www.{$domain}/itm/{$ebayItemId}" : '',
            'published_at' => date('c'),
        ]);

        return $this->repo->save($updated);
    }

    public function reviseListing(string $id, array $data): array
    {
        if ($this->ebayClient === null) {
            throw new \RuntimeException('eBay client not available.');
        }

        $existing = $this->repo->find($id);
        if ($existing === null) {
            throw new \InvalidArgumentException('Listing not found: ' . $id);
        }
        if (($existing['status'] ?? '') !== 'ACTIVE') {
            throw new \InvalidArgumentException('Only active listings can be revised. Current status: ' . ($existing['status'] ?? 'unknown'));
        }

        $merged = array_merge($existing, [
            'title'          => $data['title']       ?? $existing['title'],
            'description'    => $data['description'] ?? $existing['description'],
            'condition'      => $data['condition']   ?? $existing['condition'],
            'price'          => isset($data['price'])
                ? ['value' => $data['price'], 'currency' => 'EUR']
                : $existing['price'],
            'quantity'       => isset($data['quantity'])
                ? ['available' => (int)$data['quantity'], 'sold' => $existing['quantity']['sold'] ?? 0]
                : $existing['quantity'],
            'sku'            => $data['sku']            ?? $existing['sku'],
            'category'       => (isset($data['category']) || isset($data['category_id']))
                ? ['ebay_category_id' => $data['category_id'] ?? $existing['category']['ebay_category_id'] ?? '', 'name' => $data['category'] ?? $existing['category']['name'] ?? '']
                : $existing['category'],
            'images'         => $data['images']         ?? $existing['images'],
            'keywords'       => $data['keywords']        ?? $existing['keywords'] ?? [],
            'item_specifics' => $data['item_specifics']  ?? $existing['item_specifics'] ?? [],
            'shipping'       => $data['shipping']        ?? $existing['shipping'],
        ]);

        $result = $this->ebayClient->reviseFixedPriceItem($merged);

        if (!empty($result['picture_urls'])) {
            $merged['images'] = $result['picture_urls'];
        }

        return $this->repo->save($merged);
    }

    public function suggestCategories(string $query): array
    {
        if ($this->ebayClient === null) {
            throw new \RuntimeException('eBay client not available.');
        }
        return $this->ebayClient->getSuggestedCategories($query);
    }

    public function deleteDraft(string $id): bool
    {
        $existing = $this->repo->find($id);
        if ($existing === null || ($existing['status'] ?? '') !== 'DRAFT') {
            return false;
        }
        return $this->repo->delete($id);
    }
}
