<?php

declare(strict_types=1);

namespace App\eBay;

interface EbayClientInterface
{
    /**
     * Make an authenticated GET request to the eBay REST API.
     */
    public function get(string $path, array $query = []): array;

    /**
     * Exchange an OAuth authorization code for access + refresh tokens.
     */
    public function exchangeCodeForTokens(string $code): array;

    /**
     * Use a refresh token to get a new access token.
     */
    public function refreshAccessToken(string $refreshToken): array;

    /**
     * Normalize a raw eBay token response into our internal token shape.
     */
    public function normalizeTokenResponse(
        array $response,
        ?string $existingRefreshToken = null,
        ?string $existingRefreshExpiry = null,
    ): array;

    /**
     * Fetch the authenticated seller's eBay username from the Identity API.
     * Requires the commerce.identity.readonly scope on the token.
     * Returns an empty string on failure (non-fatal).
     */
    public function fetchSellerUsername(string $accessToken): string;

    /**
     * Fetch the authenticated seller's active listings via the Trading API
     * GetMyeBaySelling call. Handles token refresh automatically.
     * Returns ['items' => [], 'total' => N, 'pages' => N].
     */
    public function getMyActiveSellListings(int $page = 1, int $perPage = 200): array;

    /**
     * Fetch full item details via Trading API GetItem (category, condition, end time).
     * Handles token refresh automatically.
     * Returns an array with keys: categoryId, categoryName, condition, endTime.
     * Returns an empty array on failure (non-fatal enrichment).
     */
    public function getItemDetails(string $itemId): array;

    /**
     * Upload a base64-encoded image to eBay Picture Services (EPS).
     * Returns the full hosted picture URL.
     */
    public function uploadPicture(string $base64Data, string $name = 'image'): string;

    /**
     * Get eBay category suggestions for a search query.
     * Returns array of ['id' => string, 'name' => string, 'percent' => float].
     */
    public function getSuggestedCategories(string $query): array;

    /**
     * Create a fixed-price listing on eBay via AddFixedPriceItem.
     * Automatically uploads data: images to EPS before publishing.
     * Returns ['ebay_item_id' => string, 'start_time' => string, 'end_time' => string].
     */
    public function addFixedPriceItem(array $listing): array;

    /**
     * Update an existing fixed-price listing on eBay via ReviseFixedPriceItem.
     * Listing must have a valid ebay_item_id. Automatically uploads new data: images.
     * Returns ['ebay_item_id' => string, 'end_time' => string].
     */
    public function reviseFixedPriceItem(array $listing): array;

    /**
     * Search eBay Germany for active fixed-price listings matching the given keywords.
     * Uses the Browse API with an application token — no user auth required.
     * Returns an array of competitor listings sorted cheapest-first.
     */
    public function searchItems(string $keywords, int $limit = 10): array;
}
