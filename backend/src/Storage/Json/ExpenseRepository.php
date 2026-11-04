<?php

declare(strict_types=1);

namespace App\Storage\Json;

class ExpenseRepository extends JsonRepository
{
    public function __construct(string $dataDir)
    {
        parent::__construct($dataDir, 'expenses');
    }

    /**
     * The index is filtered and sorted on created_at, so it carries the date the
     * expense was incurred rather than when it was typed in.
     */
    protected function buildIndexEntry(array $entity): array
    {
        return [
            'id'         => $entity['id'],
            'category'   => $entity['category'] ?? 'other',
            'amount'     => $entity['amount'] ?? '0.00',
            'created_at' => ($entity['date'] ?? substr($entity['created_at'] ?? date('c'), 0, 10)) . 'T00:00:00+00:00',
        ];
    }

    public function findBetween(string $from, string $to): array
    {
        return $this->findAll(['date_from' => $from, 'date_to' => $to], 1, 10000)['items'];
    }
}
