<?php

declare(strict_types=1);

namespace Tests\Unit\eBay;

use App\eBay\EbayClient;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Covers the product-identifier handling that answers eBay error 21919301
 * ("The EAN field is missing").
 */
class EbayProductIdentifierTest extends TestCase
{
    private EbayClient $client;
    private ReflectionClass $ref;

    protected function setUp(): void
    {
        $_ENV['EBAY_SITE_ID'] = '77'; // Germany
        $this->ref    = new ReflectionClass(EbayClient::class);
        // The constructor needs a TokenRepository; none of these methods touch it.
        $this->client = $this->ref->newInstanceWithoutConstructor();
    }

    protected function tearDown(): void
    {
        $_ENV['EBAY_SITE_ID'] = '77';
    }

    private function call(string $method, mixed ...$args): mixed
    {
        $m = $this->ref->getMethod($method);
        $m->setAccessible(true);
        return $m->invoke($this->client, ...$args);
    }

    // ── ProductListingDetails ────────────────────────────────────────────────

    public function testSendsOptOutEanWhenSellerHasNoBarcode(): void
    {
        $xml = $this->call('buildProductListingDetails', ['Marke' => 'Ohne Markenzeichen']);

        $this->assertStringContainsString('<EAN>Nicht zutreffend</EAN>', $xml);
    }

    public function testUsesSellerSuppliedEan(): void
    {
        $xml = $this->call('buildProductListingDetails', ['EAN' => '4006381333931']);

        $this->assertStringContainsString('<EAN>4006381333931</EAN>', $xml);
        $this->assertStringNotContainsString('Nicht zutreffend', $xml);
    }

    public function testMatchesIdentifierNamesCaseInsensitivelyAndByAlias(): void
    {
        $this->assertStringContainsString(
            '<EAN>4006381333931</EAN>',
            $this->call('buildProductListingDetails', ['ean' => '4006381333931'])
        );
        $this->assertStringContainsString(
            '<EAN>4006381333931</EAN>',
            $this->call('buildProductListingDetails', ['GTIN' => '4006381333931'])
        );
    }

    public function testTreatsPlaceholderValuesAsNoBarcode(): void
    {
        foreach (['N/A', 'Nicht zutreffend', 'does not apply', ' '] as $placeholder) {
            $xml = $this->call('buildProductListingDetails', ['EAN' => $placeholder]);
            $this->assertStringContainsString('<EAN>Nicht zutreffend</EAN>', $xml, "for '{$placeholder}'");
        }
    }

    public function testOmitsIsbnAndUpcUnlessSellerSuppliedThem(): void
    {
        $xml = $this->call('buildProductListingDetails', ['EAN' => '4006381333931']);
        $this->assertStringNotContainsString('<ISBN>', $xml);
        $this->assertStringNotContainsString('<UPC>', $xml);

        $withBoth = $this->call('buildProductListingDetails', ['ISBN' => '9783499001234', 'UPC' => '012345678905']);
        $this->assertStringContainsString('<ISBN>9783499001234</ISBN>', $withBoth);
        $this->assertStringContainsString('<UPC>012345678905</UPC>', $withBoth);
    }

    public function testEscapesXmlUnsafeValues(): void
    {
        $xml = $this->call('buildProductListingDetails', ['EAN' => 'A&B<C']);

        $this->assertStringContainsString('A&amp;B&lt;C', $xml);
        $this->assertStringNotContainsString('A&B<C', $xml);
    }

    public function testUsesEnglishOptOutOnNonGermanSites(): void
    {
        $_ENV['EBAY_SITE_ID'] = '0'; // US

        $this->assertStringContainsString(
            '<EAN>Does not apply</EAN>',
            $this->call('buildProductListingDetails', [])
        );
    }

    // ── normaliseSpecifics ───────────────────────────────────────────────────

    public function testReplacesUnusableIdentifierAspectValues(): void
    {
        $result = $this->call('normaliseSpecifics', ['EAN' => 'N/A', 'MPN' => '', 'Farbe' => 'Schwarz']);

        $this->assertSame('Nicht zutreffend', $result['EAN']);
        $this->assertSame('Nicht zutreffend', $result['MPN']);
        $this->assertSame('Schwarz', $result['Farbe'], 'Non-identifier aspects must be left alone.');
    }

    public function testKeepsRealIdentifiersAndDefaultsBrand(): void
    {
        $result = $this->call('normaliseSpecifics', ['EAN' => '4006381333931']);

        $this->assertSame('4006381333931', $result['EAN']);
        $this->assertSame('Ohne Markenzeichen', $result['Marke']);
    }

    public function testDoesNotOverrideAnExistingBrand(): void
    {
        $result = $this->call('normaliseSpecifics', ['Marke' => 'Bosch']);

        $this->assertSame('Bosch', $result['Marke']);
    }
}
