<?php

declare(strict_types=1);

namespace App\Services;

use App\Storage\Json\ListingRepository;

class ListingService
{
    public function __construct(private readonly ListingRepository $repo) {}

    public function list(array $filters = [], int $page = 1, int $limit = 25): array
    {
        return $this->repo->findAll($filters, $page, $limit);
    }

    public function get(string $id): ?array
    {
        return $this->repo->find($id);
    }
}
