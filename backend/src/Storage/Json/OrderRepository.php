<?php

declare(strict_types=1);

namespace App\Storage\Json;

class OrderRepository extends JsonRepository
{
    public function __construct(string $dataDir)
    {
        parent::__construct($dataDir, 'orders');
    }

    protected function buildIndexEntry(array $entity): array
    {
        return [
            'id'             => $entity['id'],
            'ebay_order_id'  => $entity['ebay_order_id'] ?? $entity['id'],
            'status'         => $entity['status'] ?? 'UNKNOWN',
            'buyer_username' => $entity['buyer']['username'] ?? '',
            'grand_total'    => $entity['totals']['grand_total'] ?? ['value' => '0.00', 'currency' => 'GBP'],
            'created_at'     => $entity['created_at'] ?? date('c'),
            'updated_at'     => $entity['updated_at'] ?? date('c'),
        ];
    }

    public function findByDateRange(string $from, string $to): array
    {
        return $this->findAll(['date_from' => $from, 'date_to' => $to], 1, 10000)['items'];
    }

    public function findByStatus(string $status): array
    {
        return $this->findBy('status', $status);
    }
}
