<?php

declare(strict_types=1);

namespace App\Services;

use App\Storage\Json\OrderRepository;

class OrderService
{
    public function __construct(private readonly OrderRepository $repo) {}

    public function list(array $filters = [], int $page = 1, int $limit = 25): array
    {
        return $this->repo->findAll($filters, $page, $limit);
    }

    public function get(string $id): ?array
    {
        return $this->repo->find($id);
    }

    /**
     * Pin what the seller received for an order when eBay's figure is wrong or
     * missing. Null clears it, so the synced figure applies again.
     *
     * @throws \InvalidArgumentException for an unreadable amount
     */
    public function setEarningsOverride(string $id, ?string $net): ?array
    {
        $order = $this->repo->find($id);
        if ($order === null) {
            return null;
        }

        if ($net === null || trim($net) === '') {
            $order['earnings_override'] = null;
            return $this->repo->save($order);
        }

        $value = str_replace(',', '.', trim($net));
        if (!preg_match('/^-?\d{1,9}(\.\d{1,2})?$/', $value)) {
            throw new \InvalidArgumentException('Amount must be a number with at most two decimals.');
        }

        $order['earnings_override'] = [
            'net'        => number_format((float)$value, 2, '.', ''),
            'updated_at' => date('c'),
        ];
        return $this->repo->save($order);
    }
}
