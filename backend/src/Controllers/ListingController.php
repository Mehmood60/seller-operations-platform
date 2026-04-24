<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\EbayCompetitorService;
use App\Services\ListingHealthService;
use App\Services\ListingService;
use App\Helpers\Response;

class ListingController
{
    public function __construct(
        private readonly ListingService         $listingService,
        private readonly EbayCompetitorService  $competitorService,
        private readonly ListingHealthService   $healthService,
    ) {}

    private const ALLOWED_STATUSES = ['ACTIVE', 'ENDED', 'OUT_OF_STOCK', 'DRAFT'];

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

    public function create(array $params): void
    {
        $body = json_decode((string) file_get_contents('php://input'), true) ?? [];

        $title = trim((string) ($body['title'] ?? ''));
        if ($title === '') {
            Response::error('title is required.', 422);
            return;
        }

        try {
            $listing = $this->listingService->createDraft($body);
            Response::json($listing, [], 201);
        } catch (\Exception $e) {
            Response::error($e->getMessage(), 500);
        }
    }

    public function update(array $params): void
    {
        $id   = $params['id'] ?? '';
        $body = json_decode((string) file_get_contents('php://input'), true) ?? [];

        try {
            $listing = $this->listingService->updateDraft($id, $body);
            if ($listing === null) {
                Response::error('Listing not found.', 404);
                return;
            }
            Response::json($listing);
        } catch (\Exception $e) {
            Response::error($e->getMessage(), 500);
        }
    }

    public function destroy(array $params): void
    {
        $id = $params['id'] ?? '';

        $deleted = $this->listingService->deleteDraft($id);
        if (!$deleted) {
            Response::error('Draft not found.', 404);
            return;
        }
        Response::json(['deleted' => true]);
    }

    public function publish(array $params): void
    {
        $id = $params['id'] ?? '';

        try {
            $listing = $this->listingService->publish($id);
            Response::json($listing);
        } catch (\InvalidArgumentException $e) {
            Response::error($e->getMessage(), 422);
        } catch (\RuntimeException $e) {
            Response::error($e->getMessage(), 500);
        }
    }

    public function revise(array $params): void
    {
        $id   = $params['id'] ?? '';
        $body = json_decode((string) file_get_contents('php://input'), true) ?? [];

        try {
            $listing = $this->listingService->reviseListing($id, $body);
            Response::json($listing);
        } catch (\InvalidArgumentException $e) {
            Response::error($e->getMessage(), 422);
        } catch (\RuntimeException $e) {
            Response::error($e->getMessage(), 500);
        }
    }

    // ── Competitor price check ────────────────────────────────────────────────

    public function checkCompetitors(array $params): void
    {
        try {
            $result = $this->competitorService->checkCompetitors($params['id'] ?? '');
            Response::json($result);
        } catch (\RuntimeException $e) {
            Response::error($e->getMessage(), 422);
        }
    }

    public function checkAllCompetitors(array $params): void
    {
        Response::json($this->competitorService->checkAllCompetitors());
    }

    // ── Listing health score ──────────────────────────────────────────────────

    public function healthScore(array $params): void
    {
        $listing = $this->listingService->get($params['id'] ?? '');
        if ($listing === null) {
            Response::error('Listing not found.', 404);
            return;
        }
        Response::json($this->healthService->score($listing));
    }

    public function suggestCategories(array $params): void
    {
        $query = mb_substr(strip_tags(trim($_GET['q'] ?? '')), 0, 200);
        if ($query === '') {
            Response::json([]);
            return;
        }

        try {
            $suggestions = $this->listingService->suggestCategories($query);
            Response::json($suggestions);
        } catch (\Throwable $e) {
            // Category search is non-fatal — return empty list + the reason so the UI can surface it
            Response::json([], ['suggest_error' => $e->getMessage()]);
        }
    }
}
