<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\ProductScraperService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class ProductScraperServiceTest extends TestCase
{
    private ProductScraperService $service;

    protected function setUp(): void
    {
        $this->service = new ProductScraperService();
    }

    /** @return mixed */
    private function call(string $method, mixed ...$args): mixed
    {
        $ref = new ReflectionMethod(ProductScraperService::class, $method);
        $ref->setAccessible(true);
        return $ref->invoke($this->service, ...$args);
    }

    // ── Amazon gallery ────────────────────────────────────────────────────────

    public function testExtractsAmazonGalleryFromImageBlockJs(): void
    {
        $html = <<<'HTML'
        <script>var data = jQuery.parseJSON('{"colorImages":{"initial":[
          {"hiRes":"https://m.media-amazon.com/images/I/71xVXcJEt8L._AC_SL1500_.jpg",
           "thumb":"https://m.media-amazon.com/images/I/41xVXcJEt8L._AC_US40_.jpg",
           "large":"https://m.media-amazon.com/images/I/41xVXcJEt8L._AC_.jpg","variant":"MAIN"},
          {"hiRes":"https://m.media-amazon.com/images/I/81QcOhbtLtL._AC_SL1500_.jpg",
           "thumb":"https://m.media-amazon.com/images/I/41QcOhbtLtL._AC_US40_.jpg","variant":"PT01"}
        ]}}');</script>
        HTML;

        $images = $this->call('extractAmazonImages', $html);

        $this->assertSame([
            'https://m.media-amazon.com/images/I/71xVXcJEt8L._SL1600_.jpg',
            'https://m.media-amazon.com/images/I/81QcOhbtLtL._SL1600_.jpg',
        ], $images, 'Renditions of one photo must collapse to a single full-size URL.');
    }

    public function testExtractsAmazonGalleryFromImageAttributes(): void
    {
        $html = '<img data-old-hires="https://m.media-amazon.com/images/I/91abcDEFghL._AC_SL1500_.jpg"'
            . ' src="https://m.media-amazon.com/images/I/61abcDEFghL._AC_SX679_.jpg"'
            . ' data-a-dynamic-image="{&quot;https://m.media-amazon.com/images/I/61abcDEFghL._AC_SX679_.jpg&quot;:[679,679],'
            . '&quot;https://m.media-amazon.com/images/I/61zzzYYYxxL._AC_SX522_.jpg&quot;:[522,522]}">';

        $this->assertSame([
            'https://m.media-amazon.com/images/I/91abcDEFghL._SL1600_.jpg',
            'https://m.media-amazon.com/images/I/61zzzYYYxxL._SL1600_.jpg',
        ], $this->call('extractAmazonImages', $html));
    }

    public function testHandlesEscapedSlashesAndMultiSegmentSizeModifiers(): void
    {
        $html = '{"hiRes":"https:\/\/m.media-amazon.com\/images\/I\/71ABCdefghL._AC_SX679_.SX38_SY50_CR,0,0,38,50_.jpg"}';

        $this->assertSame(
            ['https://m.media-amazon.com/images/I/71ABCdefghL._SL1600_.jpg'],
            $this->call('extractAmazonImages', $html)
        );
    }

    public function testIgnoresAmazonUiAssets(): void
    {
        $html = '<img src="https://images-na.ssl-images-amazon.com/images/G/03/gno/sprites/nav-sprite.png">'
            . '<link href="https://images-na.ssl-images-amazon.com/images/G/01/AUIClients/AmazonUI.css">';

        $this->assertSame([], $this->call('extractAmazonImages', $html));
    }

    public function testFallsBackToPageSweepWhenGalleryMarkupIsMissing(): void
    {
        $html = '<div style="background:url(https://m.media-amazon.com/images/I/71SweptImgL._AC_.jpg)"></div>';

        $this->assertSame(
            ['https://m.media-amazon.com/images/I/71SweptImgL._SL1600_.jpg'],
            $this->call('extractAmazonImages', $html)
        );
    }

    // ── Bot-wall detection ────────────────────────────────────────────────────

    public function testDetectsAmazonBotWall(): void
    {
        $html = '<html><head><title dir="ltr">Amazon.de</title></head><body>'
            . '<!-- To discuss automated access to Amazon data please contact api-services-support@amazon.com. -->'
            . '<div>Geben Sie die Zeichen unten ein</div></body></html>';

        $warning = $this->call('detectBlockedPage', $html, 'https://www.amazon.de/dp/B08L5NP6NG');

        $this->assertNotNull($warning);
        $this->assertStringContainsString('Amazon blocks', $warning);
    }

    public function testDetectsEmptyShellAsBlocked(): void
    {
        $html = '<html><head><title>Loading…</title></head><body><div id="root"></div></body></html>';

        $this->assertNotNull($this->call('detectBlockedPage', $html, 'https://shop.example.com/p/1'));
    }

    public function testDoesNotFlagARealProductPage(): void
    {
        $html = '<html><head><meta property="og:title" content="Hundeleine 2m"></head><body>'
            . str_repeat('<p>Produktbeschreibung mit vielen Details.</p>', 20)
            . '</body></html>';

        $this->assertNull($this->call('detectBlockedPage', $html, 'https://shop.example.com/p/1'));
    }

    // ── ASIN fallback ─────────────────────────────────────────────────────────

    public function testAsinImageIgnoresNonAmazonAndAsinlessUrls(): void
    {
        $this->assertNull($this->call('amazonAsinImage', 'https://www.aliexpress.com/item/100500.html'));
        $this->assertNull($this->call('amazonAsinImage', 'https://www.amazon.de/s?k=wipes'));
    }
}
