<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\ListingService;
use App\Helpers\Response;

class ListingController
{
    public function __construct(private readonly ListingService $listingService) {}

    private const ALLOWED_STATUSES = ['ACTIVE', 'ENDED', 'OUT_OF_STOCK'];

    public function index(array $params): void
    {
        $page    = max(1, (int)($_GET['page'] ?? 1));
        $limit   = min(max(1, (int)($_GET['limit'] ?? 25)), 100);
        $filters = [];

        if (!empty($_GET['status'])) {
            $status = strtoupper(trim($_GET['status']));
            if (in_array($status, self::ALLOWED_STATUSES, true)) {
                $filters['status'] = $status;
            }
        }
        if (!empty($_GET['search'])) {
            $filters['search'] = mb_substr(strip_tags(trim($_GET['search'])), 0, 100);
        }

        $result = $this->listingService->list($filters, $page, $limit);

        Response::json($result['items'], [
            'page'  => $result['page'],
            'limit' => $result['limit'],
            'total' => $result['total'],
        ]);
    }

    public function show(array $params): void
    {
        $listing = $this->listingService->get($params['id'] ?? '');
        if ($listing === null) {
            Response::error('Listing not found.', 404);
            return;
        }
        Response::json($listing);
    }
}
