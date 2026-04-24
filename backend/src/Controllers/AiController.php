<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Helpers\Response;
use App\Services\AiListingService;
use App\Services\ProductScraperService;

class AiController
{
    public function __construct(
        private readonly ProductScraperService $scraper,
        private readonly AiListingService $aiService,
    ) {}

    public function analyze(array $params): void
    {
        $body = json_decode((string) file_get_contents('php://input'), true) ?? [];
        $url  = trim((string) ($body['url'] ?? ''));

        if ($url === '') {
            Response::error('url is required.', 422);
            return;
        }

        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            Response::error('Invalid URL format.', 422);
            return;
        }

        try {
            $scraped    = $this->scraper->scrape($url);
            $suggestion = $this->aiService->analyze($scraped);

            Response::json([
                'raw_product'   => $scraped,
                'ai_suggestion' => $suggestion,
            ]);
        } catch (\InvalidArgumentException $e) {
            Response::error($e->getMessage(), 422);
        } catch (\RuntimeException $e) {
            Response::error($e->getMessage(), 500);
        }
    }

    public function translate(array $params): void
    {
        $body        = json_decode((string) file_get_contents('php://input'), true) ?? [];
        $title       = trim((string) ($body['title'] ?? ''));
        $description = trim((string) ($body['description'] ?? ''));

        if ($title === '' && $description === '') {
            Response::error('title or description is required.', 422);
            return;
        }

        try {
            $result = $this->aiService->translate($title, $description);
            Response::json($result);
        } catch (\RuntimeException $e) {
            Response::error($e->getMessage(), 500);
        }
    }

    public function suggestSpecifics(array $params): void
    {
        $body          = json_decode((string) file_get_contents('php://input'), true) ?? [];
        $title         = trim((string) ($body['title'] ?? ''));
        $description   = (string) ($body['description'] ?? '');
        $itemSpecifics = is_array($body['item_specifics'] ?? null) ? $body['item_specifics'] : [];
        $missingFields = is_array($body['missing_fields'] ?? null) ? $body['missing_fields'] : [];

        if ($title === '' || empty($missingFields)) {
            Response::error('title and missing_fields are required.', 422);
            return;
        }

        try {
            $result = $this->aiService->suggestSpecifics($title, $description, $itemSpecifics, $missingFields);
            Response::json($result);
        } catch (\RuntimeException $e) {
            Response::error($e->getMessage(), 500);
        }
    }

    public function suggestPrice(array $params): void
    {
        $body             = json_decode((string) file_get_contents('php://input'), true) ?? [];
        $title            = trim((string) ($body['title']             ?? ''));
        $currentPrice     = (float)         ($body['current_price']   ?? 0);
        $shippingType     = trim((string) ($body['shipping_type']     ?? 'free'));
        $shippingCost     = (float)         ($body['shipping_cost']   ?? 0);
        $competitorLowest = isset($body['competitor_lowest']) ? (float) $body['competitor_lowest'] : null;
        $competitorCount  = (int)           ($body['competitor_count'] ?? 0);
        $topCompetitors   = is_array($body['top_competitors'] ?? null) ? $body['top_competitors'] : [];

        if ($title === '' || $currentPrice <= 0) {
            Response::error('title and current_price are required.', 422);
            return;
        }

        $allowedShipping = ['free', 'paid'];
        if (!in_array($shippingType, $allowedShipping, true)) {
            $shippingType = 'free';
        }

        try {
            $result = $this->aiService->suggestPrice(
                $title, $currentPrice, $shippingType, $shippingCost,
                $competitorLowest, $competitorCount, $topCompetitors
            );
            Response::json($result);
        } catch (\RuntimeException $e) {
            Response::error($e->getMessage(), 500);
        }
    }

    public function improveListing(array $params): void
    {
        $body        = json_decode((string) file_get_contents('php://input'), true) ?? [];
        $aspect      = trim((string) ($body['aspect']      ?? ''));
        $title       = trim((string) ($body['title']       ?? ''));
        $description = (string) ($body['description'] ?? '');

        $allowed = ['title', 'description'];
        if (!in_array($aspect, $allowed, true)) {
            Response::error('aspect must be "title" or "description".', 422);
            return;
        }
        if ($title === '' && $description === '') {
            Response::error('title or description is required.', 422);
            return;
        }

        try {
            if ($aspect === 'title') {
                $improved = $this->aiService->improveTitle($title, $description);
                Response::json(['title' => $improved]);
            } else {
                $improved = $this->aiService->improveDescription($title, $description);
                Response::json(['description' => $improved]);
            }
        } catch (\RuntimeException $e) {
            Response::error($e->getMessage(), 500);
        }
    }

    public function respondFeedback(array $params): void
    {
        $body = json_decode((string) file_get_contents('php://input'), true) ?? [];
        $text = trim((string) ($body['feedback_text'] ?? ''));
        $type = trim((string) ($body['type']          ?? 'buyer_message'));
        $tone = trim((string) ($body['tone']          ?? 'apologetic'));

        if ($text === '') {
            Response::error('feedback_text is required.', 422);
            return;
        }
        if (mb_strlen($text) > 2000) {
            Response::error('feedback_text must be 2000 characters or fewer.', 422);
            return;
        }

        $allowedTypes = ['negative_feedback', 'buyer_message', 'return_request', 'not_received'];
        $allowedTones = ['apologetic', 'refund', 'replacement', 'explanation', 'firm'];
        if (!in_array($type, $allowedTypes, true)) $type = 'buyer_message';
        if (!in_array($tone, $allowedTones, true)) $tone = 'apologetic';

        try {
            $response = $this->aiService->feedbackResponse($text, $type, $tone);
            Response::json(['response' => $response]);
        } catch (\RuntimeException $e) {
            Response::error($e->getMessage(), 500);
        }
    }
}
