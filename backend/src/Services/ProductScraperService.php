<?php

declare(strict_types=1);

namespace App\Services;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

class ProductScraperService
{
    private const USER_AGENT = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36';

    private Client $http;

    public function __construct()
    {
        $this->http = new Client([
            'timeout'         => 20,
            'connect_timeout' => 10,
            'allow_redirects' => ['max' => 5],
            'verify'          => false,
        ]);
    }

    public function scrape(string $url): array
    {
        $this->validateUrl($url);

        $html    = $this->fetchUrl($url);
        $baseUrl = $this->extractBaseUrl($url);

        $jsonLd    = $this->extractJsonLd($html);
        $openGraph = $this->extractOpenGraph($html);
        $images    = $this->extractImages($html, $baseUrl);
        $price     = $this->extractPrice($html, $jsonLd, $openGraph);
        $origin    = $this->detectOriginCountry($html, $url);
        $snippet   = $this->extractTextSnippet($html);
        $blockNote = $this->detectBlockedPage($html, $url);

        // Bot walls serve a stub page with no product photos. Amazon still hands out
        // the main product image over its public ASIN image endpoint, so use that
        // rather than leaving the listing without a single picture.
        if (empty($images)) {
            $fallback = $this->amazonAsinImage($url);
            if ($fallback !== null) {
                $images[] = $fallback;
            }
        }

        $title = $jsonLd['name']
            ?? $openGraph['og:title']
            ?? $this->extractHtmlTitle($html)
            ?? '';

        $description = $jsonLd['description']
            ?? $openGraph['og:description']
            ?? '';

        // A bot wall's <title> ("Amazon.de") and body text (captcha boilerplate) are
        // worse than nothing — they only mislead the AI. Leave the URL as its input.
        if ($blockNote !== null) {
            $title       = '';
            $description = '';
            $snippet     = '';
        }

        return [
            'url'          => $url,
            'title'        => html_entity_decode(strip_tags($title), ENT_QUOTES),
            'description'  => html_entity_decode(strip_tags($description), ENT_QUOTES),
            'images'       => $images,
            'price'        => $price,
            'origin'       => $origin,
            'text_snippet' => $snippet,
            'blocked'      => $blockNote !== null,
            'warning'      => $blockNote ?? '',
        ];
    }

    private function validateUrl(string $url): void
    {
        $parsed = parse_url($url);
        if (!$parsed || !in_array($parsed['scheme'] ?? '', ['http', 'https'], true)) {
            throw new \InvalidArgumentException('URL must use http or https.');
        }
        $host = strtolower($parsed['host'] ?? '');
        foreach (['localhost', '127.0.0.1', '0.0.0.0', '::1'] as $blocked) {
            if (str_contains($host, $blocked)) {
                throw new \InvalidArgumentException('Internal URLs are not allowed.');
            }
        }
        // CJDropshipping uses aggressive bot protection — scraping is blocked.
        // Price monitoring for CJ products requires the CJDropshipping API instead.
        if (str_contains($host, 'cjdropshipping.com')) {
            throw new \RuntimeException(
                'CJDropshipping blocks automated price lookups (bot protection). ' .
                'Connect your CJ account under Settings → CJ API to enable ' .
                'price monitoring for CJ products.'
            );
        }
    }

    /**
     * Detect pages that answered with a bot wall or an empty JS shell instead of
     * product data. Without this the AI silently invents a whole listing from the
     * words in the URL — plausible looking, completely wrong.
     *
     * @return string|null Warning to show the seller, or null if the page is usable
     */
    private function detectBlockedPage(string $html, string $url): ?string
    {
        $lower = strtolower($html);

        $blockSignals = [
            'to discuss automated access to amazon data', // Amazon bot wall
            'api-services-support@amazon.com',
            '/errors/validatecaptcha',
            'enter the characters you see below',
            'geben sie die zeichen unten ein',
            'type the characters you see in this image',
            'cf-browser-verification',                    // Cloudflare
            'attention required! | cloudflare',
            'checking if the site connection is secure',
            'please verify you are a human',
            'px-captcha',                                 // PerimeterX
            'unusual traffic from your',
        ];
        foreach ($blockSignals as $signal) {
            if (str_contains($lower, $signal)) {
                return $this->blockedPageMessage($url);
            }
        }

        // No captcha marker, but nothing a product page would ever be missing:
        // real product pages are 100 KB+ and always carry OG tags, JSON-LD or
        // microdata. A tiny page without any of them is a soft block.
        $hasProductMarkup = str_contains($lower, 'og:title')
            || str_contains($lower, 'application/ld+json')
            || str_contains($lower, 'itemprop="name"');
        if (strlen($html) < 15000 && !$hasProductMarkup) {
            return $this->blockedPageMessage($url);
        }

        return null;
    }

    private function blockedPageMessage(string $url): string
    {
        $host = strtolower(parse_url($url, PHP_URL_HOST) ?? '');

        if (str_contains($host, 'amazon.')) {
            return 'Amazon blocks automated reading of product pages. Only the main image '
                . 'and the words in the URL could be used — title, price, category and item '
                . 'specifics are pure AI guesses. Please check every field and upload the '
                . 'remaining images yourself.';
        }

        return 'The product page returned no product data (bot protection or a '
            . 'JavaScript-only page). The fields are pure AI guesses based on the URL — '
            . 'please check everything and upload the images yourself.';
    }

    private function fetchUrl(string $url): string
    {
        try {
            $response = $this->http->get($url, [
                'headers' => [
                    'User-Agent'               => self::USER_AGENT,
                    'Accept'                   => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,*/*;q=0.8',
                    'Accept-Language'          => 'en-US,en;q=0.9,de;q=0.8',
                    'Accept-Encoding'          => 'gzip, deflate',
                    'Cache-Control'            => 'max-age=0',
                    'Upgrade-Insecure-Requests'=> '1',
                    'Sec-Fetch-Dest'           => 'document',
                    'Sec-Fetch-Mode'           => 'navigate',
                    'Sec-Fetch-Site'           => 'none',
                ],
            ]);
            return (string) $response->getBody();
        } catch (GuzzleException $e) {
            throw new \RuntimeException('Failed to fetch product page: ' . $e->getMessage());
        }
    }

    private function extractBaseUrl(string $url): string
    {
        $p = parse_url($url);
        return ($p['scheme'] ?? 'https') . '://' . ($p['host'] ?? '');
    }

    private function extractJsonLd(string $html): array
    {
        preg_match_all(
            '/<script[^>]+type=["\']application\/ld\+json["\'][^>]*>(.*?)<\/script>/si',
            $html,
            $matches
        );

        foreach ($matches[1] as $raw) {
            $data = json_decode(trim($raw), true);
            if (!is_array($data)) {
                continue;
            }
            // Handle @graph wrapper
            if (isset($data['@graph']) && is_array($data['@graph'])) {
                foreach ($data['@graph'] as $node) {
                    if (isset($node['@type']) && in_array($node['@type'], ['Product', 'ItemPage'], true)) {
                        return $node;
                    }
                }
            }
            if (isset($data['@type']) && in_array($data['@type'], ['Product', 'ItemPage'], true)) {
                return $data;
            }
        }
        return [];
    }

    private function extractOpenGraph(string $html): array
    {
        $og = [];
        // property="og:X"
        preg_match_all(
            '/<meta[^>]+property=["\']og:([^"\']+)["\'][^>]+content=["\']([^"\']*)["\'][^>]*\/?>/i',
            $html, $m, PREG_SET_ORDER
        );
        foreach ($m as $match) {
            $og['og:' . $match[1]] = html_entity_decode($match[2], ENT_QUOTES);
        }
        // name="og:X" (some sites use name instead of property)
        preg_match_all(
            '/<meta[^>]+name=["\']og:([^"\']+)["\'][^>]+content=["\']([^"\']*)["\'][^>]*\/?>/i',
            $html, $m, PREG_SET_ORDER
        );
        foreach ($m as $match) {
            $og['og:' . $match[1]] ??= html_entity_decode($match[2], ENT_QUOTES);
        }
        return $og;
    }

    private function extractHtmlTitle(string $html): ?string
    {
        preg_match('/<title[^>]*>(.*?)<\/title>/si', $html, $m);
        if (empty($m[1])) {
            return null;
        }
        return html_entity_decode(strip_tags($m[1]), ENT_QUOTES);
    }

    private function extractImages(string $html, string $baseUrl): array
    {
        $images = [];
        $seen   = [];

        $add = function (string $url) use (&$images, &$seen, $baseUrl): void {
            $abs = $this->toAbsoluteUrl($url, $baseUrl);
            if ($abs && !isset($seen[$abs]) && $this->looksLikeProductImage($abs)) {
                $images[] = $abs;
                $seen[$abs] = true;
            }
        };

        // 0. Amazon gallery — its photos are only in JS blobs and dynamic-image
        //    attributes, never in a plain img src or og:image.
        foreach ($this->extractAmazonImages($html) as $u) {
            $add($u);
        }

        // 1. AliExpress / DHgate JS image list — highest priority, finds ALL gallery images
        foreach ($this->extractJsImageList($html) as $u) {
            $add($u);
        }

        // 2. OG image (usually just 1 main image)
        preg_match_all(
            '/<meta[^>]+(?:property|name)=["\']og:image["\'][^>]+content=["\']([^"\']+)["\'][^>]*\/?>/i',
            $html, $m
        );
        foreach ($m[1] as $u) {
            $add($u);
        }

        // 3. JSON-LD image arrays
        preg_match_all('/"image"\s*:\s*\[([^\]]+)\]/s', $html, $m);
        foreach ($m[1] as $group) {
            preg_match_all('/"(https?:[^"\\\\]+\.(?:jpg|jpeg|png|webp)[^"]*)"/i', $group, $urls);
            foreach ($urls[1] as $u) {
                $add(str_replace('\/', '/', $u));
            }
        }

        // 4. Protocol-relative JSON-LD image arrays  (//ae01.alicdn.com/...)
        preg_match_all('/"image"\s*:\s*\[([^\]]+)\]/s', $html, $m);
        foreach ($m[1] as $group) {
            preg_match_all('/"(\/\/[^"\\\\]+\.(?:jpg|jpeg|png|webp)[^"]*)"/i', $group, $urls);
            foreach ($urls[1] as $u) {
                $add('https:' . str_replace('\/', '/', $u));
            }
        }

        // 5. Single JSON-LD image string
        preg_match_all('/"image"\s*:\s*"(https?:[^"]+\.(?:jpg|jpeg|png|webp)[^"]*)"/i', $html, $m);
        foreach ($m[1] as $u) {
            $add(str_replace('\/', '/', $u));
        }

        // 6. img src tags (last resort)
        preg_match_all('/<img[^>]+src=["\']([^"\']+)["\'][^>]*>/i', $html, $m);
        foreach ($m[1] as $u) {
            if (preg_match('/\.(?:jpg|jpeg|png|webp)/i', $u)) {
                $add($u);
            }
        }

        return array_slice($images, 0, 12);
    }

    /**
     * Extract the full product image list from AliExpress / DHgate JavaScript data.
     * These sites embed all gallery images in window.runParams or similar JS blobs —
     * they are never in standard HTML tags, which is why the generic extractor only
     * finds the single og:image.
     */
    private function extractJsImageList(string $html): array
    {
        $images = [];

        // Pattern 1: "imagePathList":["//ae01.alicdn.com/...","//ae01.alicdn.com/..."]}
        preg_match_all('/"imagePathList"\s*:\s*\[([^\]]+)\]/s', $html, $matches);
        foreach ($matches[1] as $group) {
            preg_match_all('/"((?:https?:)?\/\/[^"]+\.(?:jpg|jpeg|png|webp)[^"]*)"/i', $group, $urls);
            foreach ($urls[1] as $u) {
                $u = str_replace('\/', '/', $u);
                $images[] = str_starts_with($u, '//') ? 'https:' . $u : $u;
            }
        }

        // Pattern 2: "imageUrl":"//ae01.alicdn.com/..." (individual entries)
        if (empty($images)) {
            preg_match_all('/"imageUrl"\s*:\s*"((?:https?:)?\/\/[^"]+\.(?:jpg|jpeg|png|webp)[^"]*)"/i', $html, $m);
            foreach ($m[1] as $u) {
                $u = str_replace('\/', '/', $u);
                $images[] = str_starts_with($u, '//') ? 'https:' . $u : $u;
            }
        }

        // Pattern 3: "picUrl":"//ae01.alicdn.com/..." (AliExpress variant)
        if (empty($images)) {
            preg_match_all('/"picUrl"\s*:\s*"((?:https?:)?\/\/[^"]+\.(?:jpg|jpeg|png|webp)[^"]*)"/i', $html, $m);
            foreach ($m[1] as $u) {
                $u = str_replace('\/', '/', $u);
                $images[] = str_starts_with($u, '//') ? 'https:' . $u : $u;
            }
        }

        // Pattern 4: raw AliExpress CDN URLs anywhere in JS (ae01.alicdn.com, ae-pic-a1)
        if (empty($images)) {
            preg_match_all(
                '/(https?:\/\/ae[0-9a-z\-]+\.alicdn\.com\/[^\s"\'<>]+\.(?:jpg|jpeg|png|webp))/i',
                $html, $m
            );
            foreach ($m[1] as $u) {
                $images[] = $u;
            }
        }

        // Deduplicate while preserving order
        return array_values(array_unique($images));
    }

    /**
     * Extract the Amazon product gallery. Amazon keeps its photos in JS blobs and
     * dynamic-image attributes only — never in og:image or a plain img src — which
     * is why the generic extractors find nothing on an Amazon page.
     *
     * @return string[]
     */
    private function extractAmazonImages(string $html): array
    {
        // Product photos live under /images/I/ on the image CDN; UI chrome sits
        // under /images/G/. Renditions of one photo share a hash and differ only in
        // the two leading size characters (71xVXcJEt8L / 41xVXcJEt8L), so collapse
        // by hash and re-request a large size.
        $urlPattern = '#https?://[a-z0-9\-.]*(?:media-amazon|ssl-images-amazon)\.com/images/I/'
            . '([A-Za-z0-9%+\-]{5,})(?:\.[A-Za-z0-9_,\-]+)*\.(?:jpg|jpeg|png|webp)#i';

        $byHash  = [];
        $collect = function (string $blob) use (&$byHash, $urlPattern): void {
            preg_match_all($urlPattern, str_replace('\/', '/', $blob), $m);
            foreach ($m[1] as $id) {
                $hash = mb_strlen($id) > 4 ? mb_substr($id, 2) : $id;
                $byHash[$hash] ??= 'https://m.media-amazon.com/images/I/' . $id . '._SL1600_.jpg';
            }
        };

        // Full-size renditions first, so the seller gets the sharpest copy of each
        // photo and the gallery keeps Amazon's own order.
        foreach (['hiRes', 'mainUrl', 'large', 'thumb'] as $key) {
            preg_match_all('/"' . $key . '"\s*:\s*"([^"]+)"/i', $html, $m);
            foreach ($m[1] as $u) {
                $collect($u);
            }
        }
        preg_match_all('/data-old-hires=["\']([^"\']+)["\']/i', $html, $m);
        foreach ($m[1] as $u) {
            $collect($u);
        }
        preg_match_all('/data-a-dynamic-image=(?:"([^"]*)"|\'([^\']*)\')/i', $html, $m, PREG_SET_ORDER);
        foreach ($m as $match) {
            $collect($match[1] !== '' ? $match[1] : ($match[2] ?? ''));
        }

        // Only if the gallery markup gave nothing: sweep the whole page. Done first
        // this would pull in "customers also bought" thumbnails of other products.
        if (empty($byHash)) {
            $collect($html);
        }

        return array_values($byHash);
    }

    /**
     * Amazon's public ASIN image endpoint — the only product image reachable when
     * the product page itself is behind the bot wall. It answers 200 with a 43-byte
     * 1x1 GIF when the ASIN has no image, so verify before using it.
     */
    private function amazonAsinImage(string $url): ?string
    {
        $host = strtolower(parse_url($url, PHP_URL_HOST) ?? '');
        if (!str_contains($host, 'amazon.')) {
            return null;
        }
        if (!preg_match('#/(?:dp|gp/product|gp/aw/d|ASIN)/([A-Z0-9]{10})#i', $url, $m)) {
            return null;
        }

        $image = 'https://m.media-amazon.com/images/P/' . strtoupper($m[1]) . '.01._SCLZZZZZZZ_.jpg';

        try {
            $response = $this->http->head($image, ['headers' => ['User-Agent' => self::USER_AGENT]]);
            if ($response->getStatusCode() !== 200) {
                return null;
            }
            $length = (int) $response->getHeaderLine('Content-Length');
            if (str_contains($response->getHeaderLine('Content-Type'), 'gif') || ($length > 0 && $length < 1000)) {
                return null;
            }
        } catch (GuzzleException) {
            return null;
        }

        return $image;
    }

    private function looksLikeProductImage(string $url): bool
    {
        $lower = strtolower($url);

        // Amazon serves all of its UI assets from /images/G/
        if (str_contains($lower, 'amazon.com/images/g/')) {
            return false;
        }

        // Skip videos
        foreach (['.mp4', '.webm', '.mov', '.avi', 'video/', '_video_', 'video_play', '/video.'] as $v) {
            if (str_contains($lower, $v)) {
                return false;
            }
        }

        // Skip UI chrome
        foreach (['logo', 'icon', 'favicon', 'banner', 'sprite', 'placeholder', 'avatar', 'captcha'] as $skip) {
            if (str_contains($lower, $skip)) {
                return false;
            }
        }

        return true;
    }

    private function toAbsoluteUrl(string $url, string $baseUrl): ?string
    {
        $url = html_entity_decode(trim($url), ENT_QUOTES);
        if (str_starts_with($url, 'http://') || str_starts_with($url, 'https://')) {
            return $url;
        }
        if (str_starts_with($url, '//')) {
            return 'https:' . $url;
        }
        if (str_starts_with($url, '/')) {
            return rtrim($baseUrl, '/') . $url;
        }
        return null;
    }

    private function extractPrice(string $html, array $jsonLd, array $og): array
    {
        // 1. JSON-LD offers
        if (!empty($jsonLd['offers'])) {
            $offer = is_array($jsonLd['offers'][0] ?? null)
                ? $jsonLd['offers'][0]
                : $jsonLd['offers'];
            if (!empty($offer['price'])) {
                return [
                    'value'    => (string) $offer['price'],
                    'currency' => $offer['priceCurrency'] ?? 'EUR',
                ];
            }
        }

        // 2. OG price
        if (!empty($og['og:price:amount'])) {
            return [
                'value'    => $og['og:price:amount'],
                'currency' => $og['og:price:currency'] ?? 'EUR',
            ];
        }

        // 3. Regex on raw HTML
        if (preg_match('/(?:EUR|€)\s*(\d+[.,]\d{2})/', $html, $m)) {
            return ['value' => str_replace(',', '.', $m[1]), 'currency' => 'EUR'];
        }
        if (preg_match('/(\d+[.,]\d{2})\s*(?:EUR|€)/', $html, $m)) {
            return ['value' => str_replace(',', '.', $m[1]), 'currency' => 'EUR'];
        }

        return ['value' => '', 'currency' => 'EUR'];
    }

    private function detectOriginCountry(string $html, string $url): string
    {
        $host = strtolower(parse_url($url, PHP_URL_HOST) ?? '');

        // Domain-based — most reliable
        $cnDomains = ['aliexpress', 'alibaba', 'dhgate', 'banggood', 'gearbest', 'wish.com', '.cn/'];
        foreach ($cnDomains as $d) {
            if (str_contains($host, $d)) {
                return 'CN';
            }
        }

        $lower = strtolower($html);

        $cnSignals = [
            'ships from china', 'shipped from china', 'versand aus china',
            'lieferung aus china', 'china warehouse', 'free shipping from china',
        ];
        foreach ($cnSignals as $sig) {
            if (str_contains($lower, $sig)) {
                return 'CN';
            }
        }

        $deSignals = [
            'versand aus deutschland', 'ships from germany', 'lieferung aus deutschland',
            'lager deutschland', 'deutsche post', 'dhl paket',
        ];
        foreach ($deSignals as $sig) {
            if (str_contains($lower, $sig)) {
                return 'DE';
            }
        }

        return 'UNKNOWN';
    }

    private function extractTextSnippet(string $html): string
    {
        // Remove non-content elements
        $html = preg_replace('/<(script|style|nav|footer|header|aside)[^>]*>.*?<\/\1>/si', '', $html) ?? $html;
        $text = strip_tags($html);
        $text = preg_replace('/\s+/', ' ', $text) ?? $text;
        return mb_substr(trim($text), 0, 3000);
    }
}
