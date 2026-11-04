<?php

declare(strict_types=1);

namespace App\eBay;

use App\eBay\EbayClientInterface;
use App\Storage\Json\TokenRepository;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

class EbayClient implements EbayClientInterface
{
    private Client $http;
    private TokenRepository $tokenRepo;
    private bool $sandbox;
    private string $apiBaseUrl;
    private string $tokenUrl;

    public function __construct(TokenRepository $tokenRepo)
    {
        $this->tokenRepo  = $tokenRepo;
        $this->sandbox    = filter_var($_ENV['EBAY_SANDBOX'] ?? 'true', FILTER_VALIDATE_BOOLEAN);
        $this->apiBaseUrl = $this->sandbox
            ? ($_ENV['EBAY_SANDBOX_API_URL'] ?? 'https://api.sandbox.ebay.com')
            : ($_ENV['EBAY_PROD_API_URL'] ?? 'https://api.ebay.com');
        $this->tokenUrl   = $this->sandbox
            ? ($_ENV['EBAY_SANDBOX_TOKEN_URL'] ?? 'https://api.sandbox.ebay.com/identity/v1/oauth2/token')
            : ($_ENV['EBAY_PROD_TOKEN_URL'] ?? 'https://api.ebay.com/identity/v1/oauth2/token');

        $this->http = new Client([
            'base_uri' => $this->apiBaseUrl,
            'timeout'  => 30,
        ]);
    }

    /**
     * Make an authenticated GET request to the eBay API.
     */
    public function get(string $path, array $query = []): array
    {
        $token = $this->getValidAccessToken();

        try {
            $response = $this->http->get($path, [
                'headers' => [
                    'Authorization'           => 'Bearer ' . $token,
                    'Content-Type'            => 'application/json',
                    'X-EBAY-C-MARKETPLACE-ID' => 'EBAY_GB',
                ],
                'query' => $query,
            ]);

            $body = (string)$response->getBody();
            $data = json_decode($body, true);
            return is_array($data) ? $data : [];
        } catch (GuzzleException $e) {
            throw new \RuntimeException('eBay API error on GET ' . $path . ': ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Exchange an authorization code for access + refresh tokens.
     */
    public function exchangeCodeForTokens(string $code): array
    {
        $clientId     = $_ENV['EBAY_CLIENT_ID'] ?? '';
        $clientSecret = $_ENV['EBAY_CLIENT_SECRET'] ?? '';
        $redirectUri  = $_ENV['EBAY_REDIRECT_URI'] ?? '';

        try {
            $response = $this->http->post($this->tokenUrl, [
                'headers' => [
                    'Content-Type'  => 'application/x-www-form-urlencoded',
                    'Authorization' => 'Basic ' . base64_encode($clientId . ':' . $clientSecret),
                ],
                'form_params' => [
                    'grant_type'   => 'authorization_code',
                    'code'         => $code,
                    'redirect_uri' => $redirectUri,
                ],
            ]);

            $data = json_decode((string)$response->getBody(), true);
            if (!is_array($data)) {
                throw new \RuntimeException('Invalid token response from eBay.');
            }
            return $data;
        } catch (GuzzleException $e) {
            throw new \RuntimeException('Token exchange failed: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Refresh the access token using the refresh token.
     */
    public function refreshAccessToken(string $refreshToken): array
    {
        $clientId     = $_ENV['EBAY_CLIENT_ID'] ?? '';
        $clientSecret = $_ENV['EBAY_CLIENT_SECRET'] ?? '';
        $scopes       = $_ENV['EBAY_SCOPES'] ?? '';

        try {
            $response = $this->http->post($this->tokenUrl, [
                'headers' => [
                    'Content-Type'  => 'application/x-www-form-urlencoded',
                    'Authorization' => 'Basic ' . base64_encode($clientId . ':' . $clientSecret),
                ],
                'form_params' => [
                    'grant_type'    => 'refresh_token',
                    'refresh_token' => $refreshToken,
                    'scope'         => $scopes,
                ],
            ]);

            $data = json_decode((string)$response->getBody(), true);
            if (!is_array($data)) {
                throw new \RuntimeException('Invalid refresh token response from eBay.');
            }
            return $data;
        } catch (GuzzleException $e) {
            throw new \RuntimeException('Token refresh failed: ' . $e->getMessage(), 0, $e);
        }
    }

    private function getValidAccessToken(): string
    {
        $tokens = $this->tokenRepo->getTokens();

        if ($tokens === null || empty($tokens['access_token'])) {
            throw new \RuntimeException('No eBay tokens found. Please connect your eBay account first.');
        }

        $expiresAt = strtotime($tokens['expires_at'] ?? '');
        if ($expiresAt !== false && $expiresAt <= time() + 60) {
            // Token expired or about to expire — refresh it
            $refreshed  = $this->refreshAccessToken($tokens['refresh_token']);
            $newTokens  = $this->normalizeTokenResponse(
                $refreshed,
                $tokens['refresh_token'],
                $tokens['refresh_expires_at'] ?? null
            );
            if (!empty($tokens['seller_username'])) {
                $newTokens['seller_username'] = $tokens['seller_username'];
            }
            $this->tokenRepo->saveTokens($newTokens);
            $tokens = $newTokens;
        }

        return $tokens['access_token'];
    }

    public function normalizeTokenResponse(
        array $response,
        ?string $existingRefreshToken = null,
        ?string $existingRefreshExpiry = null
    ): array {
        $expiresIn        = (int)($response['expires_in'] ?? 7200);
        $refreshExpiresIn = (int)($response['refresh_token_expires_in'] ?? 47304000); // ~18 months

        return [
            'access_token'       => $response['access_token'] ?? '',
            'refresh_token'      => $response['refresh_token'] ?? $existingRefreshToken ?? '',
            'token_type'         => $response['token_type'] ?? 'User Access Token',
            'expires_at'         => date('c', time() + $expiresIn),
            'refresh_expires_at' => ($response['refresh_token'] ?? null)
                ? date('c', time() + $refreshExpiresIn)
                : ($existingRefreshExpiry ?? date('c', time() + $refreshExpiresIn)),
            'scopes'             => explode(' ', $response['scope'] ?? $_ENV['EBAY_SCOPES'] ?? ''),
        ];
    }

    public function getMyActiveSellListings(int $page = 1, int $perPage = 200): array
    {
        $accessToken = $this->getValidAccessToken();
        $tradingUrl  = $this->sandbox
            ? 'https://api.sandbox.ebay.com/ws/api.dll'
            : 'https://api.ebay.com/ws/api.dll';

        $xml = <<<XML
<?xml version="1.0" encoding="utf-8"?>
<GetMyeBaySellingRequest xmlns="urn:ebay:apis:eBLBaseComponents">
  <RequesterCredentials>
    <eBayAuthToken>{$accessToken}</eBayAuthToken>
  </RequesterCredentials>
  <ActiveList>
    <Include>true</Include>
    <GranularityLevel>Fine</GranularityLevel>
    <Pagination>
      <EntriesPerPage>{$perPage}</EntriesPerPage>
      <PageNumber>{$page}</PageNumber>
    </Pagination>
  </ActiveList>
  <ErrorLanguage>en_US</ErrorLanguage>
  <WarningLevel>High</WarningLevel>
</GetMyeBaySellingRequest>
XML;

        try {
            $response = $this->http->post($tradingUrl, [
                'headers' => [
                    'Content-Type'                   => 'text/xml',
                    'X-EBAY-API-CALL-NAME'           => 'GetMyeBaySelling',
                    'X-EBAY-API-SITEID'              => $_ENV['EBAY_SITE_ID'] ?? '3',
                    'X-EBAY-API-APP-NAME'            => $_ENV['EBAY_CLIENT_ID'] ?? '',
                    'X-EBAY-API-DEV-NAME'            => $_ENV['EBAY_DEV_ID'] ?? '',
                    'X-EBAY-API-CERT-NAME'           => $_ENV['EBAY_CLIENT_SECRET'] ?? '',
                    'X-EBAY-API-COMPATIBILITY-LEVEL' => '1155',
                ],
                'body' => $xml,
            ]);

            return $this->parseTradingApiResponse((string)$response->getBody());
        } catch (GuzzleException $e) {
            throw new \RuntimeException('eBay Trading API error: ' . $e->getMessage(), 0, $e);
        }
    }

    private function parseTradingApiResponse(string $xmlBody): array
    {
        $xml = @simplexml_load_string($xmlBody);
        if ($xml === false) {
            return ['items' => [], 'total' => 0, 'pages' => 0];
        }

        // Surface eBay API errors instead of silently returning 0 items
        $ack = strtolower((string)($xml->Ack ?? ''));
        if ($ack === 'failure') {
            $errorMsg = trim(strip_tags((string)($xml->Errors->ShortMessage ?? 'Unknown eBay API error')));
            $errorCode = (string)($xml->Errors->ErrorCode ?? '');
            throw new \RuntimeException(
                'eBay API error' . ($errorCode ? " (code {$errorCode})" : '') . ': ' . $errorMsg
            );
        }

        $items = [];
        foreach ($xml->ActiveList->ItemArray->Item ?? [] as $item) {
            // Collect images — GalleryURL is a thumbnail; swap to s-l500 for display quality
            $pictures = [];
            $galleryUrl = (string)($item->PictureDetails->GalleryURL ?? '');
            if ($galleryUrl !== '') {
                $pictures[] = str_replace('s-l140.jpg', 's-l500.jpg', $galleryUrl);
            }
            foreach ($item->PictureDetails->PictureURL ?? [] as $url) {
                $u = (string)$url;
                if ($u !== '') {
                    $pictures[] = $u;
                }
            }

            $quantity  = (int)($item->QuantityAvailable ?? $item->Quantity ?? 0);
            $totalQty  = (int)($item->Quantity ?? $quantity);
            $sold      = max(0, $totalQty - $quantity);

            // Price: prefer the original listing currency (e.g. EUR for .de listings)
            $rawPrice       = $item->SellingStatus->CurrentPrice ?? $item->BuyItNowPrice ?? null;
            $convertedPrice = $item->SellingStatus->ConvertedCurrentPrice ?? null;
            $priceNode      = $rawPrice ?? $convertedPrice;
            if ($priceNode !== null) {
                $priceValue    = number_format((float)$priceNode, 2, '.', '');
                $priceCurrency = (string)($priceNode['currencyID'] ?? 'EUR');
            } else {
                $priceValue    = '0.00';
                $priceCurrency = 'EUR';
            }

            $items[] = [
                'itemId'        => (string)($item->ItemID ?? ''),
                'title'         => (string)($item->Title ?? ''),
                'price'         => ['value' => $priceValue, 'currency' => $priceCurrency],
                'quantity'      => $quantity,
                'quantitySold'  => $sold,
                'startTime'     => (string)($item->ListingDetails->StartTime ?? ''),
                'endTime'       => (string)($item->ListingDetails->EndTime ?? ''),
                'listingStatus' => 'Active',
                'sku'           => (string)($item->SKU ?? ''),
                'condition'     => (string)($item->ConditionDisplayName ?? ''),
                'viewItemUrl'   => (string)($item->ListingDetails->ViewItemURL ?? ''),
                'pictureUrls'   => $pictures,
            ];
        }

        $totalPages   = (int)($xml->ActiveList->PaginationResult->TotalNumberOfPages ?? 1);
        $totalEntries = (int)($xml->ActiveList->PaginationResult->TotalNumberOfEntries ?? count($items));

        return ['items' => $items, 'total' => $totalEntries, 'pages' => $totalPages];
    }

    public function fetchSellerUsername(string $accessToken): string
    {
        $identityBaseUrl = $this->sandbox
            ? 'https://apiz.sandbox.ebay.com'
            : 'https://apiz.ebay.com';

        try {
            $response = $this->http->get($identityBaseUrl . '/commerce/identity/v1/user/', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $accessToken,
                    'Content-Type'  => 'application/json',
                ],
            ]);

            $data = json_decode((string)$response->getBody(), true);
            return $data['username'] ?? '';
        } catch (\Throwable) {
            return '';
        }
    }

    public function getItemDetails(string $itemId): array
    {
        $accessToken = $this->getValidAccessToken();
        $tradingUrl  = $this->sandbox
            ? 'https://api.sandbox.ebay.com/ws/api.dll'
            : 'https://api.ebay.com/ws/api.dll';

        $xml = <<<XML
<?xml version="1.0" encoding="utf-8"?>
<GetItemRequest xmlns="urn:ebay:apis:eBLBaseComponents">
  <RequesterCredentials>
    <eBayAuthToken>{$accessToken}</eBayAuthToken>
  </RequesterCredentials>
  <ItemID>{$itemId}</ItemID>
  <DetailLevel>ReturnAll</DetailLevel>
  <ErrorLanguage>en_US</ErrorLanguage>
  <WarningLevel>High</WarningLevel>
</GetItemRequest>
XML;

        try {
            $response = $this->http->post($tradingUrl, [
                'headers' => [
                    'Content-Type'                   => 'text/xml',
                    'X-EBAY-API-CALL-NAME'           => 'GetItem',
                    'X-EBAY-API-SITEID'              => $_ENV['EBAY_SITE_ID'] ?? '3',
                    'X-EBAY-API-APP-NAME'            => $_ENV['EBAY_CLIENT_ID'] ?? '',
                    'X-EBAY-API-DEV-NAME'            => $_ENV['EBAY_DEV_ID'] ?? '',
                    'X-EBAY-API-CERT-NAME'           => $_ENV['EBAY_CLIENT_SECRET'] ?? '',
                    'X-EBAY-API-COMPATIBILITY-LEVEL' => '1155',
                ],
                'body' => $xml,
            ]);

            return $this->parseGetItemResponse((string)$response->getBody());
        } catch (\Throwable) {
            return [];
        }
    }

    private function parseGetItemResponse(string $xmlBody): array
    {
        $xml = @simplexml_load_string($xmlBody);
        if ($xml === false || !isset($xml->Item)) {
            return [];
        }

        $item = $xml->Item;

        return [
            'categoryId'   => (string)($item->PrimaryCategory->CategoryID ?? ''),
            'categoryName' => (string)($item->PrimaryCategory->CategoryName ?? ''),
            'condition'    => (string)($item->ConditionDisplayName ?? ''),
            'endTime'      => (string)($item->ListingDetails->EndTime ?? ''),
            'description'  => (string)($item->Description ?? ''),
            'pictureUrls'  => $this->extractPictureUrls($item),
            'itemSpecifics'=> $this->extractItemSpecifics($item),
        ];
    }

    private function extractPictureUrls(\SimpleXMLElement $item): array
    {
        $urls = [];
        foreach ($item->PictureDetails->PictureURL ?? [] as $url) {
            $u = trim((string)$url);
            if ($u !== '') {
                $urls[] = $u;
            }
        }
        return $urls;
    }

    private function extractItemSpecifics(\SimpleXMLElement $item): array
    {
        $specifics = [];
        foreach ($item->ItemSpecifics->NameValueList ?? [] as $pair) {
            $name  = trim((string)($pair->Name ?? ''));
            $value = trim((string)($pair->Value ?? ''));
            if ($name !== '') {
                $specifics[$name] = $value;
            }
        }
        return $specifics;
    }

    public function getApiBaseUrl(): string
    {
        return $this->apiBaseUrl;
    }

    // ── Sell API (JSON) helpers ───────────────────────────────────────────────

    /**
     * The marketplace this seller account trades on, derived from EBAY_SITE_ID.
     * The Marketing API scopes campaigns per marketplace, so it has to match the
     * site the listings actually live on rather than a hard-coded default.
     */
    public function marketplaceId(): string
    {
        return $this->getSiteInfo()['marketplace'];
    }

    /**
     * Authenticated JSON call against a Sell API (Marketing, Inventory, …).
     *
     * Unlike get(), a 4xx is returned rather than thrown: the caller needs to tell
     * "no ad exists yet" (404) apart from a real failure, and eBay's own error text
     * is more useful than Guzzle's generic message.
     *
     * @return array{status:int, data:array, location:string, error:string}
     */
    public function sellRequest(string $method, string $path, ?array $body = null, array $query = []): array
    {
        $token = $this->getValidAccessToken();

        $options = [
            'headers' => [
                'Authorization'           => 'Bearer ' . $token,
                'Content-Type'            => 'application/json',
                'Accept'                  => 'application/json',
                'X-EBAY-C-MARKETPLACE-ID' => $this->marketplaceId(),
            ],
            'http_errors' => false,
        ];
        if ($query !== []) {
            $options['query'] = $query;
        }
        if ($body !== null) {
            $options['json'] = $body;
        }

        try {
            $response = $this->http->request($method, $path, $options);
        } catch (GuzzleException $e) {
            throw new \RuntimeException('eBay API error on ' . $method . ' ' . $path . ': ' . $e->getMessage(), 0, $e);
        }

        $status = $response->getStatusCode();
        $raw    = (string)$response->getBody();
        $data   = json_decode($raw, true);
        $data   = is_array($data) ? $data : [];

        return [
            'status'   => $status,
            'data'     => $data,
            'location' => $response->getHeaderLine('Location'),
            'error'    => $status >= 400 ? $this->describeSellError($data, $status, $raw) : '',
        ];
    }

    /**
     * Flatten eBay's errors[] envelope into one readable line. A missing-scope 403 gets
     * an explicit hint appended — the fix (re-running OAuth consent) is not something
     * eBay's own wording makes obvious.
     */
    private function describeSellError(array $data, int $status, string $raw, string $scope = 'sell.marketing'): string
    {
        $parts = [];
        foreach ($data['errors'] ?? [] as $err) {
            $msg = trim((string)($err['longMessage'] ?? $err['message'] ?? ''));
            if ($msg === '') {
                continue;
            }
            $code    = (string)($err['errorId'] ?? '');
            $parts[] = ($code !== '' ? "(code {$code}) " : '') . $msg;
        }

        $text = $parts !== []
            ? implode(' | ', $parts)
            : 'HTTP ' . $status . ($raw !== '' ? ': ' . mb_substr($raw, 0, 300) : '');

        if (($status === 403 || $status === 401) && stripos($text, 'scope') !== false) {
            $text .= ' — reconnect your eBay account so the ' . $scope . ' scope is granted.';
        }

        return $text;
    }

    // ── Trading API helpers ───────────────────────────────────────────────────

    private function tradingUrl(): string
    {
        return $this->sandbox
            ? 'https://api.sandbox.ebay.com/ws/api.dll'
            : 'https://api.ebay.com/ws/api.dll';
    }

    private function tradingHeaders(string $callName): array
    {
        return [
            'Content-Type'                   => 'text/xml',
            'X-EBAY-API-CALL-NAME'           => $callName,
            'X-EBAY-API-SITEID'              => $_ENV['EBAY_SITE_ID'] ?? '77',
            'X-EBAY-API-APP-NAME'            => $_ENV['EBAY_CLIENT_ID'] ?? '',
            'X-EBAY-API-DEV-NAME'            => $_ENV['EBAY_DEV_ID'] ?? '',
            'X-EBAY-API-CERT-NAME'           => $_ENV['EBAY_CLIENT_SECRET'] ?? '',
            'X-EBAY-API-COMPATIBILITY-LEVEL' => '1155',
        ];
    }

    private function getSiteInfo(): array
    {
        $map = [
            '0'  => ['name' => 'US',      'country' => 'US', 'currency' => 'USD', 'marketplace' => 'EBAY_US'],
            '2'  => ['name' => 'Canada',  'country' => 'CA', 'currency' => 'CAD', 'marketplace' => 'EBAY_CA'],
            '3'  => ['name' => 'UK',      'country' => 'GB', 'currency' => 'GBP', 'marketplace' => 'EBAY_GB'],
            '71' => ['name' => 'France',  'country' => 'FR', 'currency' => 'EUR', 'marketplace' => 'EBAY_FR'],
            '77' => ['name' => 'Germany', 'country' => 'DE', 'currency' => 'EUR', 'marketplace' => 'EBAY_DE'],
        ];
        return $map[$_ENV['EBAY_SITE_ID'] ?? '77'] ?? ['name' => 'Germany', 'country' => 'DE', 'currency' => 'EUR', 'marketplace' => 'EBAY_DE'];
    }

    /**
     * The value eBay expects when a product genuinely has no barcode. Sending a made-up
     * number would be worse than sending nothing — this is the sanctioned opt-out.
     */
    private function noIdentifierValue(): string
    {
        return $this->getSiteInfo()['country'] === 'DE' ? 'Nicht zutreffend' : 'Does not apply';
    }

    /** Item specific names that hold a product identifier, lowercased. */
    private const IDENTIFIER_SPECIFICS = ['ean', 'upc', 'gtin', 'isbn', 'mpn', 'barcode', 'herstellernummer'];

    /**
     * Fill in the defaults eBay insists on before a listing goes out: a brand, and a
     * usable value for identifier aspects. "N/A" is rejected as an aspect value, so an
     * unknown barcode becomes the sanctioned opt-out instead.
     *
     * @param  array<string,string> $specifics
     * @return array<string,string>
     */
    private function normaliseSpecifics(array $specifics): array
    {
        if (empty($specifics['Marke'])) {
            $specifics['Marke'] = 'Ohne Markenzeichen';
        }

        foreach ($specifics as $name => $value) {
            if (!in_array(strtolower(trim((string)$name)), self::IDENTIFIER_SPECIFICS, true)) {
                continue;
            }
            $value = trim((string)$value);
            if ($value === '' || strcasecmp($value, 'N/A') === 0) {
                $specifics[$name] = $this->noIdentifierValue();
            }
        }

        return $specifics;
    }

    /**
     * Build <ProductListingDetails>. Many categories require EAN as a *structured* field
     * (error 21919301 "The EAN field is missing"), which item specifics do not satisfy.
     *
     * EAN always goes out — with the seller's code, or the opt-out value when the product
     * has no barcode. ISBN and UPC are only sent when the seller actually supplied one, so
     * the request never volunteers identifiers eBay did not ask for. Element order follows
     * the Trading API schema sequence.
     *
     * @param array<string,string> $specifics item specifics, searched for seller-supplied codes
     */
    private function buildProductListingDetails(array $specifics): string
    {
        // Case-insensitive lookup so "EAN", "ean" and "Ean" all resolve.
        $lookup = [];
        foreach ($specifics as $name => $value) {
            $lookup[strtolower(trim((string)$name))] = trim((string)$value);
        }

        $pick = static function (array $keys) use ($lookup): ?string {
            foreach ($keys as $key) {
                $value = $lookup[$key] ?? '';
                if ($value !== ''
                    && strcasecmp($value, 'N/A') !== 0
                    && strcasecmp($value, 'Nicht zutreffend') !== 0
                    && strcasecmp($value, 'Does not apply') !== 0
                ) {
                    return $value;
                }
            }
            return null;
        };

        $ean  = $pick(['ean', 'gtin', 'barcode']) ?? $this->noIdentifierValue();
        $isbn = $pick(['isbn']);
        $upc  = $pick(['upc']);

        $xml = '      <EAN>' . htmlspecialchars($ean, ENT_XML1) . "</EAN>\n";
        if ($isbn !== null) {
            $xml .= '      <ISBN>' . htmlspecialchars($isbn, ENT_XML1) . "</ISBN>\n";
        }
        if ($upc !== null) {
            $xml .= '      <UPC>' . htmlspecialchars($upc, ENT_XML1) . "</UPC>\n";
        }

        return "<ProductListingDetails>\n{$xml}    </ProductListingDetails>";
    }

    private function mapConditionId(string $condition): int
    {
        return match (true) {
            str_contains($condition, 'Wie neu')     => 3000,
            str_contains($condition, 'Gut')          => 4000,
            str_contains($condition, 'Akzeptabel')   => 5000,
            str_contains($condition, 'ohne Etikett') => 1500,
            default                                  => 1000,  // Neu
        };
    }

    private function mapShippingService(string $service, string $origin): string
    {
        $lower = strtolower($service);
        if (str_contains($lower, 'dhl'))    return 'DE_DHLPaket';
        if (str_contains($lower, 'hermes')) return 'DE_Hermes';
        if (str_contains($lower, 'dpd'))    return 'DE_DPD';
        if (str_contains($lower, 'ups'))    return 'DE_UPS';
        if (str_contains($lower, 'gls'))    return 'DE_GLS';
        // DE_DHLPaket is a universally accepted code on eBay Germany for all origins
        return 'DE_DHLPaket';
    }

    /**
     * Turn the seller's images into picture URLs eBay will take for a listing.
     *
     * eBay refuses a listing that mixes its own EPS pictures with self-hosted
     * ones ("A mixture of Self Hosted and EPS pictures are not allowed",
     * code 20004), so everything is pushed through EPS: files from the computer
     * arrive as data: URLs and are uploaded, pasted links are copied into EPS by
     * URL, and pictures already hosted by eBay are passed straight through. That
     * is what lets one listing use both sources at once.
     */
    private function resolvePictureUrls(array $images): array
    {
        $pictureUrls = [];

        foreach (array_slice(array_values($images), 0, 12) as $i => $img) {
            $img  = trim((string)$img);
            $name = 'image_' . ($i + 1);

            if (str_starts_with($img, 'data:')) {
                if (preg_match('/^data:[^;,]*;base64,(.+)$/s', $img, $m)) {
                    $pictureUrls[] = $this->uploadPicture($m[1], $name);
                }
                continue;
            }
            if (!preg_match('#^https?://#i', $img)) {
                continue;
            }
            $pictureUrls[] = $this->isEbayHostedPicture($img)
                ? $img
                : $this->uploadPictureFromUrl($img, $name);
        }

        return $pictureUrls;
    }

    /** True for pictures eBay already hosts — EPS URLs live on ebayimg.com. */
    private function isEbayHostedPicture(string $url): bool
    {
        $host = strtolower((string)(parse_url($url, PHP_URL_HOST) ?? ''));

        return $host === 'ebayimg.com' || str_ends_with($host, '.ebayimg.com');
    }

    // ── Picture upload (Media API) ────────────────────────────────────────────
    //
    // eBay decommissioned the Trading API's UploadSiteHostedPictures on
    // 2026-09-30 — every call now answers "404 Resource not found". Its
    // replacement is the Commerce Media API, which returns the same kind of
    // EPS (i.ebayimg.com) URL, so listings keep using pictures exactly as before.

    /** Image formats EPS hosts, keyed by detected MIME type. */
    private const EPS_FORMATS = [
        'image/jpeg'     => 'jpg',
        'image/pjpeg'    => 'jpg',
        'image/png'      => 'png',
        'image/gif'      => 'gif',
        'image/bmp'      => 'bmp',
        'image/x-ms-bmp' => 'bmp',
        'image/tiff'     => 'tif',
    ];

    /** EPS rejects anything larger than 12 MB of image data. */
    private const EPS_MAX_BYTES = 12 * 1024 * 1024;

    /** Image CDNs serve bot user agents smaller files, or nothing at all. */
    private const IMAGE_FETCH_USER_AGENT =
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36';

    private function mediaUrl(): string
    {
        return $this->sandbox
            ? 'https://apim.sandbox.ebay.com/commerce/media/v1_beta'
            : 'https://apim.ebay.com/commerce/media/v1_beta';
    }

    public function uploadPicture(string $base64Data, string $name = 'image'): string
    {
        return $this->uploadBinaryPicture($this->decodeImageData($base64Data), $name);
    }

    /** Send raw image bytes to EPS as a multipart file upload. */
    private function uploadBinaryPicture(string $binary, string $name): string
    {
        $mime = $this->detectImageMime($binary);

        return $this->sendPictureUpload('/image/create_image_from_file', [
            // Guzzle adds "Expect: 100-Continue" to any body over 1 MB, which
            // eBay's gateway does not answer reliably.
            'expect'    => false,
            'multipart' => [
                [
                    'name'     => 'image',
                    'contents' => $binary,
                    'filename' => $this->pictureName($name) . '.' . self::EPS_FORMATS[$mime],
                    'headers'  => ['Content-Type' => $mime],
                ],
            ],
        ]);
    }

    /**
     * Copy a picture that lives on someone else's server into EPS.
     *
     * eBay fetches the URL itself. Hosts that refuse its fetcher — Amazon above
     * all — are downloaded here and sent as bytes, so a pasted link ends up as an
     * eBay-hosted picture either way.
     */
    public function uploadPictureFromUrl(string $url, string $name = 'image'): string
    {
        try {
            return $this->sendPictureUpload('/image/create_image_from_url', [
                'json' => ['imageUrl' => $url],
            ]);
        } catch (\RuntimeException $e) {
            $binary = $this->downloadImage($url);
            if ($binary === null) {
                throw new \RuntimeException(sprintf(
                    'eBay could not load the image at %s (%s). Save it to your computer and upload the file instead.',
                    $url,
                    $e->getMessage()
                ), 0, $e);
            }
            return $this->uploadBinaryPicture($binary, $name);
        }
    }

    /** A file name safe to send to eBay: letters, digits, dash and underscore only. */
    private function pictureName(string $name): string
    {
        $safe = preg_replace('/[^A-Za-z0-9_-]+/', '_', mb_substr($name, 0, 80)) ?? '';
        return $safe !== '' ? $safe : 'image';
    }

    /**
     * Run a Media API image upload and return the hosted picture URL.
     *
     * The URL normally comes back in the 201 body; when it does not, the image
     * resource named in the Location header is fetched for it.
     */
    private function sendPictureUpload(string $endpoint, array $options): string
    {
        $auth = [
            'Authorization' => 'Bearer ' . $this->getValidAccessToken(),
            'Accept'        => 'application/json',
        ];
        $options['headers']     = $auth;
        $options['http_errors'] = false;

        try {
            $response = $this->http->post($this->mediaUrl() . $endpoint, $options);
        } catch (GuzzleException $e) {
            throw new \RuntimeException('Failed to upload picture: ' . $e->getMessage(), 0, $e);
        }

        $status = $response->getStatusCode();
        $raw    = (string)$response->getBody();
        $data   = json_decode($raw, true);
        $data   = is_array($data) ? $data : [];

        if ($status >= 400) {
            throw new \RuntimeException(
                'eBay picture upload failed: ' . $this->describeSellError($data, $status, $raw, 'sell.inventory')
            );
        }

        $url      = (string)($data['imageUrl'] ?? '');
        $location = $response->getHeaderLine('Location');
        if ($url === '' && $location !== '') {
            try {
                $image = $this->http->get($location, ['headers' => $auth, 'http_errors' => false]);
                $body  = json_decode((string)$image->getBody(), true);
                $url   = is_array($body) ? (string)($body['imageUrl'] ?? '') : '';
            } catch (GuzzleException) {
                $url = '';
            }
        }

        if ($url === '') {
            throw new \RuntimeException('No URL returned from eBay picture upload.');
        }
        return $url;
    }

    /** Fetch a remote image as bytes, or null when the host will not serve it to us. */
    private function downloadImage(string $url): ?string
    {
        if (!$this->isPublicHttpUrl($url)) {
            return null;
        }

        try {
            $response = $this->http->get($url, [
                'headers' => [
                    'User-Agent' => self::IMAGE_FETCH_USER_AGENT,
                    'Accept'     => 'image/avif,image/webp,image/png,image/jpeg,*/*;q=0.8',
                    'Referer'    => $this->originOf($url),
                ],
                'allow_redirects' => ['max' => 5],
                'timeout'         => 20,
            ]);
        } catch (GuzzleException) {
            return null;
        }

        $binary = (string)$response->getBody();
        if ($binary === '' || strlen($binary) > self::EPS_MAX_BYTES) {
            return null;
        }
        return $binary;
    }

    /**
     * Whether the server may fetch this URL at all. The address comes from the
     * seller, so it must not be turned into a request against our own network
     * (localhost, the VM's metadata service, other containers).
     */
    private function isPublicHttpUrl(string $url): bool
    {
        $parts  = parse_url($url);
        $scheme = strtolower((string)($parts['scheme'] ?? ''));
        $host   = (string)($parts['host'] ?? '');

        if ($host === '' || !in_array($scheme, ['http', 'https'], true)) {
            return false;
        }

        // gethostbyname() echoes the input back when it cannot resolve the name.
        $ip = filter_var($host, FILTER_VALIDATE_IP) ? $host : gethostbyname($host);

        return (bool)filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
    }

    /** Scheme + host of a URL, which some CDNs insist on seeing as the referer. */
    private function originOf(string $url): string
    {
        $parts = parse_url($url);
        return isset($parts['scheme'], $parts['host'])
            ? $parts['scheme'] . '://' . $parts['host'] . '/'
            : '';
    }

    /**
     * Turn the base64 payload of a data: URL into raw image bytes.
     * Tolerates the line breaks and URL-safe alphabet some clients produce.
     */
    private function decodeImageData(string $base64Data): string
    {
        $clean  = strtr(preg_replace('/\s+/', '', $base64Data) ?? '', '-_', '+/');
        $binary = base64_decode($clean, true);

        if ($binary === false || $binary === '') {
            throw new \RuntimeException(
                'Image data could not be read (invalid encoding). Please remove the image and add it again.'
            );
        }
        if (strlen($binary) > self::EPS_MAX_BYTES) {
            throw new \RuntimeException(sprintf(
                'Image is too large for eBay (%.1f MB, limit 12 MB). Please use a smaller image.',
                strlen($binary) / 1048576
            ));
        }
        return $binary;
    }

    /**
     * Identify the image format from its own header bytes — the data: URL's stated
     * MIME type is whatever the uploading browser guessed and cannot be trusted.
     * Converts formats eBay does not host (WebP/AVIF) when GD can read them.
     */
    private function detectImageMime(string &$binary): string
    {
        $info = @getimagesizefromstring($binary);
        $mime = is_array($info) ? strtolower((string)($info['mime'] ?? '')) : '';

        if (isset(self::EPS_FORMATS[$mime])) {
            return $mime;
        }

        $jpeg = $this->convertToJpeg($binary);
        if ($jpeg !== null) {
            $binary = $jpeg;
            return 'image/jpeg';
        }

        throw new \RuntimeException(sprintf(
            'eBay does not accept this image format (%s). Please upload a JPG, PNG, GIF, BMP or TIFF file.',
            $mime !== '' ? $mime : 'unrecognised'
        ));
    }

    /** Re-encode an image as JPEG via GD, or null when GD cannot decode it. */
    private function convertToJpeg(string $binary): ?string
    {
        if (!function_exists('imagecreatefromstring') || !function_exists('imagejpeg')) {
            return null;
        }

        $image = @imagecreatefromstring($binary);
        if ($image === false) {
            return null;
        }

        // Flatten transparency onto white; JPEG has no alpha channel.
        $flat = @imagecreatetruecolor(imagesx($image), imagesy($image));
        if ($flat !== false) {
            imagefill($flat, 0, 0, imagecolorallocate($flat, 255, 255, 255));
            imagecopy($flat, $image, 0, 0, 0, 0, imagesx($image), imagesy($image));
            imagedestroy($image);
            $image = $flat;
        }

        ob_start();
        $ok = imagejpeg($image, null, 90);
        $jpeg = (string)ob_get_clean();
        imagedestroy($image);

        return $ok && $jpeg !== '' ? $jpeg : null;
    }

    private function getApplicationToken(): string
    {
        $clientId     = $_ENV['EBAY_CLIENT_ID'] ?? '';
        $clientSecret = $_ENV['EBAY_CLIENT_SECRET'] ?? '';

        try {
            $response = $this->http->post($this->tokenUrl, [
                'headers' => [
                    'Content-Type'  => 'application/x-www-form-urlencoded',
                    'Authorization' => 'Basic ' . base64_encode($clientId . ':' . $clientSecret),
                ],
                'form_params' => [
                    'grant_type' => 'client_credentials',
                    'scope'      => 'https://api.ebay.com/oauth/api_scope',
                ],
            ]);

            $data = json_decode((string)$response->getBody(), true);
            return $data['access_token'] ?? '';
        } catch (GuzzleException $e) {
            throw new \RuntimeException('Could not obtain app token: ' . $e->getMessage(), 0, $e);
        }
    }

    // ── GetSuggestedCategories (REST Taxonomy API) ────────────────────────────

    public function getSuggestedCategories(string $query): array
    {
        $token     = $this->getApplicationToken();
        $siteInfo  = $this->getSiteInfo();
        $treeId    = $_ENV['EBAY_SITE_ID'] ?? '77';

        try {
            $response = $this->http->get(
                "/commerce/taxonomy/v1/category_tree/{$treeId}/get_category_suggestions",
                [
                    'headers' => [
                        'Authorization'           => 'Bearer ' . $token,
                        'Content-Type'            => 'application/json',
                        'X-EBAY-C-MARKETPLACE-ID' => $siteInfo['marketplace'],
                    ],
                    'query' => ['q' => $query],
                ]
            );

            $data        = json_decode((string)$response->getBody(), true);
            $suggestions = [];
            foreach ($data['categorySuggestions'] ?? [] as $item) {
                $cat  = $item['category'] ?? [];
                $id   = (string)($cat['categoryId']   ?? '');
                $name = (string)($cat['categoryName']  ?? '');
                if ($id !== '' && $name !== '') {
                    $suggestions[] = ['id' => $id, 'name' => $name, 'percent' => 0];
                }
            }
            return $suggestions;
        } catch (GuzzleException $e) {
            throw new \RuntimeException('Kategoriesuche fehlgeschlagen: ' . $e->getMessage(), 0, $e);
        }
    }

    // ── AddFixedPriceItem ─────────────────────────────────────────────────────

    public function addFixedPriceItem(array $listing): array
    {
        $token = $this->getValidAccessToken();

        $pictureUrls = $this->resolvePictureUrls($listing['images'] ?? []);

        $title       = htmlspecialchars(mb_substr($listing['title'] ?? '', 0, 80), ENT_XML1);
        $description = $listing['description'] ?? '';
        $price       = number_format((float)($listing['price']['value'] ?? 0), 2, '.', '');
        $quantity    = max(1, (int)($listing['quantity']['available'] ?? 1));
        $conditionId = $this->mapConditionId($listing['condition'] ?? 'Neu');
        $categoryId  = $listing['category']['ebay_category_id'] ?? '';
        if ($categoryId === '') {
            throw new \InvalidArgumentException('No eBay category ID provided. Please select a category and try again.');
        }
        $sku         = htmlspecialchars($listing['sku'] ?? '', ENT_XML1);
        $siteInfo    = $this->getSiteInfo();
        $currency    = $siteInfo['currency'];

        $shipping        = $listing['shipping'] ?? [];
        $isFree          = ($shipping['type'] ?? 'free') === 'free';
        $shippingCost    = number_format((float)($shipping['cost'] ?? 0), 2, '.', '');
        $dispatchMax     = max(1, (int)($shipping['processing_days_max'] ?? 2));
        $shippingService = $this->mapShippingService(
            $shipping['service'] ?? '',
            $shipping['origin']  ?? 'DE'
        );

        $pictureXml = '';
        foreach ($pictureUrls as $url) {
            $escaped     = htmlspecialchars($url, ENT_XML1);
            $pictureXml .= "    <PictureURL>{$escaped}</PictureURL>\n";
        }
        $pictureDetailsXml = $pictureXml !== ''
            ? "<PictureDetails>\n{$pictureXml}    </PictureDetails>"
            : '';

        $originToLocation = ['DE' => 'Deutschland', 'CN' => 'China', 'GB' => 'United Kingdom', 'US' => 'United States'];
        $location         = $originToLocation[$shipping['origin'] ?? ''] ?? $siteInfo['country'];

        $specifics = $this->normaliseSpecifics((array)($listing['item_specifics'] ?? []));
        $itemSpecificsXml = '';
        foreach ($specifics as $specName => $specValue) {
            $n = htmlspecialchars((string)$specName,  ENT_XML1);
            $v = htmlspecialchars((string)$specValue, ENT_XML1);
            $itemSpecificsXml .= "    <NameValueList><Name>{$n}</Name><Value>{$v}</Value></NameValueList>\n";
        }
        $itemSpecificsBlock = "<ItemSpecifics>\n{$itemSpecificsXml}  </ItemSpecifics>";
        $productDetailsXml  = $this->buildProductListingDetails($specifics);

        $shippingCostXml = $isFree ? '0.00' : $shippingCost;
        $skuXml          = $sku !== '' ? "<SKU>{$sku}</SKU>" : '';

        $xml = <<<XML
<?xml version="1.0" encoding="utf-8"?>
<AddFixedPriceItemRequest xmlns="urn:ebay:apis:eBLBaseComponents">
  <RequesterCredentials><eBayAuthToken>{$token}</eBayAuthToken></RequesterCredentials>
  <Item>
    <Title>{$title}</Title>
    <Description><![CDATA[{$description}]]></Description>
    <PrimaryCategory><CategoryID>{$categoryId}</CategoryID></PrimaryCategory>
    <StartPrice currencyID="{$currency}">{$price}</StartPrice>
    <Quantity>{$quantity}</Quantity>
    <ListingType>FixedPriceItem</ListingType>
    <ListingDuration>GTC</ListingDuration>
    <ConditionID>{$conditionId}</ConditionID>
    <Country>{$siteInfo['country']}</Country>
    <Currency>{$siteInfo['currency']}</Currency>
    <Location>{$location}</Location>
    <Site>{$siteInfo['name']}</Site>
    {$itemSpecificsBlock}
    {$productDetailsXml}
    {$pictureDetailsXml}
    <ShippingDetails>
      <ShippingType>Flat</ShippingType>
      <ShippingServiceOptions>
        <ShippingServicePriority>1</ShippingServicePriority>
        <ShippingService>{$shippingService}</ShippingService>
        <ShippingServiceCost currencyID="{$currency}">{$shippingCostXml}</ShippingServiceCost>
        <ShippingServiceAdditionalCost currencyID="{$currency}">0.00</ShippingServiceAdditionalCost>
      </ShippingServiceOptions>
    </ShippingDetails>
    <DispatchTimeMax>{$dispatchMax}</DispatchTimeMax>
    <ReturnPolicy>
      <ReturnsAcceptedOption>ReturnsAccepted</ReturnsAcceptedOption>
      <ReturnsWithinOption>Days_30</ReturnsWithinOption>
      <ShippingCostPaidByOption>Buyer</ShippingCostPaidByOption>
    </ReturnPolicy>
    {$skuXml}
  </Item>
  <ErrorLanguage>en_US</ErrorLanguage>
  <WarningLevel>High</WarningLevel>
</AddFixedPriceItemRequest>
XML;

        try {
            $response = $this->http->post($this->tradingUrl(), [
                'headers' => $this->tradingHeaders('AddFixedPriceItem'),
                'body'    => $xml,
            ]);

            $xmlResp = @simplexml_load_string((string)$response->getBody());
            if ($xmlResp === false) {
                throw new \RuntimeException('Invalid response from eBay AddFixedPriceItem.');
            }

            $ack = strtolower((string)($xmlResp->Ack ?? ''));
            if (in_array($ack, ['failure', 'partialfailure'], true)) {
                $messages = [];
                foreach ($xmlResp->Errors ?? [] as $err) {
                    if (strtolower((string)($err->SeverityCode ?? '')) !== 'error') {
                        continue;
                    }
                    $code = (string)($err->ErrorCode   ?? '');
                    $msg  = trim(strip_tags((string)($err->LongMessage ?? $err->ShortMessage ?? '')));
                    if ($msg !== '') {
                        $messages[] = ($code !== '' ? "(code {$code}) " : '') . $msg;
                    }
                }
                if (!empty($messages)) {
                    throw new \RuntimeException('eBay listing failed: ' . implode(' | ', $messages));
                }
            }

            return [
                'ebay_item_id' => (string)($xmlResp->ItemID    ?? ''),
                'start_time'   => (string)($xmlResp->StartTime ?? ''),
                'end_time'     => (string)($xmlResp->EndTime   ?? ''),
                // The EPS URLs the uploads became, so the caller can store those
                // instead of keeping (and re-uploading) the base64 originals.
                'picture_urls' => $pictureUrls,
            ];
        } catch (GuzzleException $e) {
            throw new \RuntimeException('Failed to create eBay listing: ' . $e->getMessage(), 0, $e);
        }
    }

    // ── ReviseFixedPriceItem ──────────────────────────────────────────────────

    public function reviseFixedPriceItem(array $listing): array
    {
        $token      = $this->getValidAccessToken();
        $ebayItemId = $listing['ebay_item_id'] ?? '';
        if ($ebayItemId === '') {
            throw new \InvalidArgumentException('Listing has no ebay_item_id — cannot revise.');
        }

        $pictureUrls = $this->resolvePictureUrls($listing['images'] ?? []);

        $title       = htmlspecialchars(mb_substr($listing['title'] ?? '', 0, 80), ENT_XML1);
        $description = $listing['description'] ?? '';
        $price       = number_format((float)($listing['price']['value'] ?? 0), 2, '.', '');
        $quantity    = max(1, (int)($listing['quantity']['available'] ?? 1));
        $conditionId = $this->mapConditionId($listing['condition'] ?? 'Neu');
        $sku         = htmlspecialchars($listing['sku'] ?? '', ENT_XML1);
        $siteInfo    = $this->getSiteInfo();
        $currency    = $siteInfo['currency'];

        $shipping        = $listing['shipping'] ?? [];
        $isFree          = ($shipping['type'] ?? 'free') === 'free';
        $shippingCost    = number_format((float)($shipping['cost'] ?? 0), 2, '.', '');
        $dispatchMax     = max(1, (int)($shipping['processing_days_max'] ?? 2));
        $shippingService = $this->mapShippingService($shipping['service'] ?? '', $shipping['origin'] ?? 'DE');

        $specifics = $this->normaliseSpecifics((array)($listing['item_specifics'] ?? []));
        $itemSpecificsXml = '';
        foreach ($specifics as $specName => $specValue) {
            $n = htmlspecialchars((string)$specName,  ENT_XML1);
            $v = htmlspecialchars((string)$specValue, ENT_XML1);
            $itemSpecificsXml .= "    <NameValueList><Name>{$n}</Name><Value>{$v}</Value></NameValueList>\n";
        }
        $itemSpecificsBlock = "<ItemSpecifics>\n{$itemSpecificsXml}  </ItemSpecifics>";
        $productDetailsXml  = $this->buildProductListingDetails($specifics);

        $pictureXml = '';
        foreach ($pictureUrls as $url) {
            $escaped     = htmlspecialchars($url, ENT_XML1);
            $pictureXml .= "    <PictureURL>{$escaped}</PictureURL>\n";
        }
        $pictureDetailsXml = $pictureXml !== ''
            ? "<PictureDetails>\n{$pictureXml}    </PictureDetails>"
            : '';

        $shippingCostXml = $isFree ? '0.00' : $shippingCost;
        $skuXml          = $sku !== '' ? "<SKU>{$sku}</SKU>" : '';

        $xml = <<<XML
<?xml version="1.0" encoding="utf-8"?>
<ReviseFixedPriceItemRequest xmlns="urn:ebay:apis:eBLBaseComponents">
  <RequesterCredentials><eBayAuthToken>{$token}</eBayAuthToken></RequesterCredentials>
  <Item>
    <ItemID>{$ebayItemId}</ItemID>
    <Title>{$title}</Title>
    <Description><![CDATA[{$description}]]></Description>
    <StartPrice currencyID="{$currency}">{$price}</StartPrice>
    <Quantity>{$quantity}</Quantity>
    <ConditionID>{$conditionId}</ConditionID>
    {$itemSpecificsBlock}
    {$productDetailsXml}
    {$pictureDetailsXml}
    <ShippingDetails>
      <ShippingType>Flat</ShippingType>
      <ShippingServiceOptions>
        <ShippingServicePriority>1</ShippingServicePriority>
        <ShippingService>{$shippingService}</ShippingService>
        <ShippingServiceCost currencyID="{$currency}">{$shippingCostXml}</ShippingServiceCost>
        <ShippingServiceAdditionalCost currencyID="{$currency}">0.00</ShippingServiceAdditionalCost>
      </ShippingServiceOptions>
    </ShippingDetails>
    <DispatchTimeMax>{$dispatchMax}</DispatchTimeMax>
    {$skuXml}
  </Item>
  <ErrorLanguage>en_US</ErrorLanguage>
  <WarningLevel>High</WarningLevel>
</ReviseFixedPriceItemRequest>
XML;

        try {
            $response = $this->http->post($this->tradingUrl(), [
                'headers' => $this->tradingHeaders('ReviseFixedPriceItem'),
                'body'    => $xml,
            ]);

            $xmlResp = @simplexml_load_string((string)$response->getBody());
            if ($xmlResp === false) {
                throw new \RuntimeException('Invalid response from eBay ReviseFixedPriceItem.');
            }

            $ack = strtolower((string)($xmlResp->Ack ?? ''));
            if (in_array($ack, ['failure', 'partialfailure'], true)) {
                $messages = [];
                foreach ($xmlResp->Errors ?? [] as $err) {
                    if (strtolower((string)($err->SeverityCode ?? '')) !== 'error') {
                        continue;
                    }
                    $code = (string)($err->ErrorCode   ?? '');
                    $msg  = trim(strip_tags((string)($err->LongMessage ?? $err->ShortMessage ?? '')));
                    if ($msg !== '') {
                        $messages[] = ($code !== '' ? "(code {$code}) " : '') . $msg;
                    }
                }
                if (!empty($messages)) {
                    throw new \RuntimeException('eBay update failed: ' . implode(' | ', $messages));
                }
            }

            return [
                'ebay_item_id' => (string)($xmlResp->ItemID  ?? $ebayItemId),
                'end_time'     => (string)($xmlResp->EndTime ?? ''),
                'picture_urls' => $pictureUrls,
            ];
        } catch (GuzzleException $e) {
            throw new \RuntimeException('Failed to update eBay listing: ' . $e->getMessage(), 0, $e);
        }
    }

    // ── CompleteSale (mark shipped + push tracking) ───────────────────────────

    public function completeSale(string $ebayOrderId, string $trackingNumber, string $carrier): void
    {
        $token       = $this->getValidAccessToken();
        $shippedTime = gmdate('Y-m-d\TH:i:s\Z');
        $carrierXml  = htmlspecialchars($carrier,        ENT_XML1);
        $trackingXml = htmlspecialchars($trackingNumber, ENT_XML1);
        $orderXml    = htmlspecialchars($ebayOrderId,    ENT_XML1);

        $xml = <<<XML
<?xml version="1.0" encoding="utf-8"?>
<CompleteSaleRequest xmlns="urn:ebay:apis:eBLBaseComponents">
  <RequesterCredentials><eBayAuthToken>{$token}</eBayAuthToken></RequesterCredentials>
  <OrderID>{$orderXml}</OrderID>
  <Shipped>true</Shipped>
  <Shipment>
    <ShippedTime>{$shippedTime}</ShippedTime>
    <ShipmentTrackingDetails>
      <ShippingCarrierUsed>{$carrierXml}</ShippingCarrierUsed>
      <ShipmentTrackingNumber>{$trackingXml}</ShipmentTrackingNumber>
    </ShipmentTrackingDetails>
  </Shipment>
</CompleteSaleRequest>
XML;

        try {
            $response = $this->http->post($this->tradingUrl(), [
                'headers' => $this->tradingHeaders('CompleteSale'),
                'body'    => $xml,
            ]);

            $xmlResp = @simplexml_load_string((string)$response->getBody());
            if ($xmlResp === false) {
                throw new \RuntimeException('Invalid response from eBay CompleteSale.');
            }

            $ack = strtolower((string)($xmlResp->Ack ?? ''));
            if (in_array($ack, ['failure', 'partialfailure'], true)) {
                $messages = [];
                foreach ($xmlResp->Errors ?? [] as $err) {
                    if (strtolower((string)($err->SeverityCode ?? '')) !== 'error') {
                        continue;
                    }
                    $code = (string)($err->ErrorCode   ?? '');
                    $msg  = trim(strip_tags((string)($err->LongMessage ?? $err->ShortMessage ?? '')));
                    if ($msg !== '') {
                        $messages[] = ($code !== '' ? "(code {$code}) " : '') . $msg;
                    }
                }
                if (!empty($messages)) {
                    throw new \RuntimeException('eBay CompleteSale failed: ' . implode(' | ', $messages));
                }
            }
        } catch (GuzzleException $e) {
            throw new \RuntimeException('Failed to complete eBay sale: ' . $e->getMessage(), 0, $e);
        }
    }

    // ── Browse API — competitor price search ──────────────────────────────────

    public function searchItems(string $keywords, int $limit = 10): array
    {
        $token = $this->getApplicationToken();

        try {
            $response = $this->http->get('/buy/browse/v1/item_summary/search', [
                'headers' => [
                    'Authorization'           => 'Bearer ' . $token,
                    'Content-Type'            => 'application/json',
                    'X-EBAY-C-MARKETPLACE-ID' => 'EBAY_DE',
                ],
                'query' => [
                    'q'      => $keywords,
                    'filter' => 'deliveryCountry:DE,buyingOptions:{FIXED_PRICE}',
                    'sort'   => 'price',
                    'limit'  => (string)$limit,
                ],
            ]);

            $data = json_decode((string)$response->getBody(), true);
            return $this->parseBrowseResponse(is_array($data) ? $data : []);
        } catch (GuzzleException $e) {
            throw new \RuntimeException('eBay Browse API error: ' . $e->getMessage(), 0, $e);
        }
    }

    private function parseBrowseResponse(array $data): array
    {
        $results = [];
        foreach ($data['itemSummaries'] ?? [] as $item) {
            try {
                $price    = (float)($item['price']['value'] ?? 0);
                $currency = $item['price']['currency'] ?? 'EUR';

                $shippingCost = 0.0;
                $shippingLabel = 'free';
                foreach ($item['shippingOptions'] ?? [] as $opt) {
                    $type = strtolower($opt['shippingCostType'] ?? '');
                    if ($type === 'free' || $type === 'freedomestic') {
                        $shippingCost  = 0.0;
                        $shippingLabel = 'free';
                    } else {
                        $shippingCost  = (float)($opt['shippingCost']['value'] ?? 0);
                        $shippingLabel = number_format($shippingCost, 2, '.', '');
                    }
                    break;
                }

                $total    = $price + $shippingCost;
                $location = trim(
                    ($item['itemLocation']['city'] ?? '') . ', ' .
                    ($item['itemLocation']['country'] ?? '')
                , ', ');

                $results[] = [
                    'item_id'    => $item['itemId'] ?? '',
                    'title'      => $item['title'] ?? '',
                    'url'        => $item['itemWebUrl'] ?? '',
                    'image'      => $item['image']['imageUrl'] ?? null,
                    'condition'  => $item['conditionDisplayName'] ?? ($item['condition'] ?? '—'),
                    'price'      => number_format($price, 2, '.', ''),
                    'shipping'   => $shippingLabel,
                    'total_price'=> number_format($total, 2, '.', ''),
                    'currency'   => $currency,
                    'location'   => $location ?: '—',
                ];
            } catch (\Throwable) {
                // Skip malformed items
            }
        }
        return $results;
    }
}
