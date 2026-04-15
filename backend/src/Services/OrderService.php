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
}
