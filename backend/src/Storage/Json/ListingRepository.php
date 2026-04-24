<?php

declare(strict_types=1);

namespace App\Storage\Json;

class ListingRepository extends JsonRepository
{
    public function __construct(string $dataDir)
    {
        parent::__construct($dataDir, 'listings');
    }

    public function findByEbayItemId(string $ebayItemId): ?array
    {
        foreach ($this->readIndex() as $entry) {
            if (isset($entry['ebay_item_id']) && (string)$entry['ebay_item_id'] === $ebayItemId) {
                return $this->find((string)$entry['id']);
            }
        }
        return null;
    }

    public function findAllActive(): array
    {
        $result = [];
        foreach ($this->readIndex() as $entry) {
            if (($entry['status'] ?? '') === 'ACTIVE') {
                $entity = $this->find((string)$entry['id']);
                if ($entity !== null) {
                    $result[] = $entity;
                }
            }
        }
        return $result;
    }

    public function findMonitorable(): array
    {
        $result = [];
        foreach ($this->readIndex() as $entry) {
            if (!empty($entry['source_url'])) {
                $entity = $this->find((string)$entry['id']);
                if ($entity !== null) {
                    $result[] = $entity;
                }
            }
        }
        return $result;
    }

    protected function buildIndexEntry(array $entity): array
    {
        return [
            'id'                 => $entity['id'],
            'ebay_item_id'       => $entity['ebay_item_id'] ?? $entity['id'],
            'title'              => $entity['title'] ?? '',
            'status'             => $entity['status'] ?? 'UNKNOWN',
            'price'              => $entity['price'] ?? ['value' => '0.00', 'currency' => 'GBP'],
            'quantity_available' => $entity['quantity']['available'] ?? 0,
            'quantity_sold'      => $entity['quantity']['sold'] ?? 0,
            'listed_at'          => $entity['listed_at'] ?? date('c'),
            'created_at'         => $entity['listed_at'] ?? date('c'),
            'source_url'         => $entity['source_url'] ?? '',
            'monitor_enabled'    => $entity['monitor']['enabled'] ?? false,
        ];
    }
}
