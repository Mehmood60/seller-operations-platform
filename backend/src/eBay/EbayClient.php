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

    // ── UploadSiteHostedPictures ──────────────────────────────────────────────

    public function uploadPicture(string $base64Data, string $name = 'image'): string
    {
        $token        = $this->getValidAccessToken();
        $safeName     = htmlspecialchars($name, ENT_XML1);

        $xml = <<<XML
<?xml version="1.0" encoding="utf-8"?>
<UploadSiteHostedPicturesRequest xmlns="urn:ebay:apis:eBLBaseComponents">
  <RequesterCredentials><eBayAuthToken>{$token}</eBayAuthToken></RequesterCredentials>
  <PictureName>{$safeName}</PictureName>
  <PictureData>{$base64Data}</PictureData>
</UploadSiteHostedPicturesRequest>
XML;

        try {
            $response = $this->http->post($this->tradingUrl(), [
                'headers' => $this->tradingHeaders('UploadSiteHostedPictures'),
                'body'    => $xml,
            ]);

            $xmlResp = @simplexml_load_string((string)$response->getBody());
            if ($xmlResp === false) {
                throw new \RuntimeException('Invalid response from eBay picture upload.');
            }

            $ack = strtolower((string)($xmlResp->Ack ?? ''));
            if ($ack === 'failure') {
                $msg = trim(strip_tags((string)($xmlResp->Errors->ShortMessage ?? 'Unknown upload error')));
                throw new \RuntimeException('eBay picture upload failed: ' . $msg);
            }

            $url = (string)($xmlResp->SiteHostedPictureDetails->FullURL ?? '');
            if ($url === '') {
                throw new \RuntimeException('No URL returned from eBay picture upload.');
            }
            return $url;
        } catch (GuzzleException $e) {
            throw new \RuntimeException('Failed to upload picture: ' . $e->getMessage(), 0, $e);
        }
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
            throw new \RuntimeException('App-Token konnte nicht abgerufen werden: ' . $e->getMessage(), 0, $e);
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

        // Resolve images: upload data: URLs to EPS; pass https:// as-is
        $pictureUrls = [];
        foreach (array_slice($listing['images'] ?? [], 0, 12) as $i => $img) {
            if (str_starts_with($img, 'data:')) {
                if (preg_match('/^data:[^;]+;base64,(.+)$/s', $img, $m)) {
                    $pictureUrls[] = $this->uploadPicture($m[1], 'image_' . ($i + 1));
                }
            } elseif (str_starts_with($img, 'http')) {
                $pictureUrls[] = $img;
            }
        }

        $title       = htmlspecialchars(mb_substr($listing['title'] ?? '', 0, 80), ENT_XML1);
        $description = $listing['description'] ?? '';
        $price       = number_format((float)($listing['price']['value'] ?? 0), 2, '.', '');
        $quantity    = max(1, (int)($listing['quantity']['available'] ?? 1));
        $conditionId = $this->mapConditionId($listing['condition'] ?? 'Neu');
        $categoryId  = $listing['category']['ebay_category_id'] ?? '';
        if ($categoryId === '') {
            throw new \InvalidArgumentException('Keine eBay-Kategorie-ID angegeben. Bitte wähle eine Kategorie aus und versuche es erneut.');
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

        $specifics = (array)($listing['item_specifics'] ?? []);
        if (empty($specifics['Marke'])) {
            $specifics['Marke'] = 'Ohne Markenzeichen';
        }
        $itemSpecificsXml = '';
        foreach ($specifics as $specName => $specValue) {
            $n = htmlspecialchars((string)$specName,  ENT_XML1);
            $v = htmlspecialchars((string)$specValue, ENT_XML1);
            $itemSpecificsXml .= "    <NameValueList><Name>{$n}</Name><Value>{$v}</Value></NameValueList>\n";
        }
        $itemSpecificsBlock = "<ItemSpecifics>\n{$itemSpecificsXml}  </ItemSpecifics>";

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

        // Resolve images: upload data: URLs to EPS; pass https:// as-is
        $pictureUrls = [];
        foreach (array_slice($listing['images'] ?? [], 0, 12) as $i => $img) {
            if (str_starts_with($img, 'data:')) {
                if (preg_match('/^data:[^;]+;base64,(.+)$/s', $img, $m)) {
                    $pictureUrls[] = $this->uploadPicture($m[1], 'image_' . ($i + 1));
                }
            } elseif (str_starts_with($img, 'http')) {
                $pictureUrls[] = $img;
            }
        }

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

        $specifics = (array)($listing['item_specifics'] ?? []);
        if (empty($specifics['Marke'])) {
            $specifics['Marke'] = 'Ohne Markenzeichen';
        }
        $itemSpecificsXml = '';
        foreach ($specifics as $specName => $specValue) {
            $n = htmlspecialchars((string)$specName,  ENT_XML1);
            $v = htmlspecialchars((string)$specValue, ENT_XML1);
            $itemSpecificsXml .= "    <NameValueList><Name>{$n}</Name><Value>{$v}</Value></NameValueList>\n";
        }
        $itemSpecificsBlock = "<ItemSpecifics>\n{$itemSpecificsXml}  </ItemSpecifics>";

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
