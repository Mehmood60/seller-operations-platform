<?php

declare(strict_types=1);

namespace App\Services;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

class ProductScraperService
{
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

        $title = $jsonLd['name']
            ?? $openGraph['og:title']
            ?? $this->extractHtmlTitle($html)
            ?? '';

        $description = $jsonLd['description']
            ?? $openGraph['og:description']
            ?? '';

        return [
            'url'          => $url,
            'title'        => html_entity_decode(strip_tags($title), ENT_QUOTES),
            'description'  => html_entity_decode(strip_tags($description), ENT_QUOTES),
            'images'       => $images,
            'price'        => $price,
            'origin'       => $origin,
            'text_snippet' => $snippet,
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
                'CJDropshipping blockiert automatische Preisabfragen (Bot-Schutz). ' .
                'Verbinde dein CJ-Konto unter Einstellungen → CJ API, ' .
                'um Preisüberwachung für CJ-Produkte zu aktivieren.'
            );
        }
    }

    private function fetchUrl(string $url): string
    {
        try {
            $response = $this->http->get($url, [
                'headers' => [
                    'User-Agent'               => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36',
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

    private function looksLikeProductImage(string $url): bool
    {
        $lower = strtolower($url);

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
