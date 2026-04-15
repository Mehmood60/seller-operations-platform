<?php

declare(strict_types=1);

namespace App\Storage\Json;

class ListingRepository extends JsonRepository
{
    public function __construct(string $dataDir)
    {
        parent::__construct($dataDir, 'listings');
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
        ];
    }
}
