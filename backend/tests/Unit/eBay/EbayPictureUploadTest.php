<?php

declare(strict_types=1);

namespace Tests\Unit\eBay;

use App\eBay\EbayClient;
use App\Storage\Json\TokenRepository;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use ReflectionClass;

/**
 * Covers the picture handling behind two eBay rejections:
 *   "File contains corrupt image data"  — EPS wants raw bytes as a MIME
 *   attachment, so a data: URL has to decode to a format eBay hosts.
 *   code 20004, "A mixture of Self Hosted and EPS pictures are not allowed"
 *   — every picture in a listing has to end up on eBay's own servers, whether
 *   the seller uploaded a file or pasted a link.
 */
class EbayPictureUploadTest extends TestCase
{
    private EbayClient $client;
    private ReflectionClass $ref;

    /**
     * Requests the client sent, filled by reference as the handler stack runs —
     * deliberately untyped so it can hold the reference.
     *
     * @var array<int, array{request: RequestInterface}>
     */
    private $history = [];

    protected function setUp(): void
    {
        $this->ref = new ReflectionClass(EbayClient::class);
        // The constructor needs a TokenRepository; none of these methods touch it.
        $this->client = $this->ref->newInstanceWithoutConstructor();
    }

    private function call(string $method, mixed ...$args): mixed
    {
        $m = $this->ref->getMethod($method);
        $m->setAccessible(true);
        return $m->invoke($this->client, ...$args);
    }

    /** Detection takes the binary by reference, so it needs its own invocation path. */
    private function detect(string &$binary): string
    {
        $m = $this->ref->getMethod('detectImageMime');
        $m->setAccessible(true);
        return $m->invokeArgs($this->client, [&$binary]);
    }

    /** Smallest possible real PNG (1x1, transparent). */
    private function pngBytes(): string
    {
        return base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8DwHwAFAAH/q842iQAAAABJRU5ErkJggg=='
        );
    }

    // ── decodeImageData ──────────────────────────────────────────────────────

    public function testDecodesCleanBase64(): void
    {
        $png = $this->pngBytes();
        $this->assertSame($png, $this->call('decodeImageData', base64_encode($png)));
    }

    public function testDecodesBase64ContainingLineBreaks(): void
    {
        $png = $this->pngBytes();
        $wrapped = chunk_split(base64_encode($png), 16, "\n");

        $this->assertSame($png, $this->call('decodeImageData', $wrapped));
    }

    public function testDecodesUrlSafeBase64Alphabet(): void
    {
        $png = $this->pngBytes();
        $urlSafe = strtr(base64_encode($png), '+/', '-_');

        $this->assertSame($png, $this->call('decodeImageData', $urlSafe));
    }

    public function testRejectsUndecodableData(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/invalid encoding/');

        $this->call('decodeImageData', 'not%%%base64%%%at%%%all');
    }

    public function testRejectsEmptyData(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->call('decodeImageData', '');
    }

    public function testRejectsImageOverTwelveMegabytes(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/too large/');

        $this->call('decodeImageData', base64_encode(str_repeat('x', 13 * 1024 * 1024)));
    }

    // ── detectImageMime ──────────────────────────────────────────────────────

    public function testDetectsPngFromHeaderBytes(): void
    {
        $binary = $this->pngBytes();
        $this->assertSame('image/png', $this->detect($binary));
    }

    public function testDetectsJpegFromHeaderBytes(): void
    {
        // A JPEG produced here rather than hard-coded, so the bytes are genuine.
        if (!function_exists('imagecreatetruecolor')) {
            $this->markTestSkipped('GD is not installed.');
        }
        $img = imagecreatetruecolor(4, 4);
        ob_start();
        imagejpeg($img);
        $binary = (string)ob_get_clean();
        imagedestroy($img);

        $this->assertSame('image/jpeg', $this->detect($binary));
    }

    public function testRejectsFilesThatAreNotImages(): void
    {
        $binary = 'This is a text file pretending to be a picture.';

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/does not accept this image format/');

        $this->detect($binary);
    }

    public function testConvertsUnsupportedFormatToJpegWhenGdCanRead(): void
    {
        if (!function_exists('imagewebp') || !function_exists('imagecreatefromstring')) {
            $this->markTestSkipped('GD without WebP support.');
        }

        $img = imagecreatetruecolor(8, 8);
        ob_start();
        imagewebp($img);
        $webp = (string)ob_get_clean();
        imagedestroy($img);

        if ($webp === '' || (@getimagesizefromstring($webp)['mime'] ?? '') !== 'image/webp') {
            $this->markTestSkipped('GD could not produce a WebP fixture.');
        }

        $binary = $webp;
        $this->assertSame('image/jpeg', $this->detect($binary));
        // The binary is swapped for the converted JPEG, not left as WebP.
        $this->assertSame('image/jpeg', getimagesizefromstring($binary)['mime']);
    }

    // ── Canned eBay responses ────────────────────────────────────────────────

    private function successXml(string $fullUrl = 'https://i.ebayimg.com/00/s/test.jpg'): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?>'
            . '<UploadSiteHostedPicturesResponse xmlns="urn:ebay:apis:eBLBaseComponents">'
            . '<Ack>Success</Ack><SiteHostedPictureDetails>'
            . '<FullURL>' . $fullUrl . '</FullURL>'
            . '</SiteHostedPictureDetails></UploadSiteHostedPicturesResponse>';
    }

    private function failureXml(string $longMessage): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?>'
            . '<UploadSiteHostedPicturesResponse xmlns="urn:ebay:apis:eBLBaseComponents">'
            . '<Ack>Failure</Ack><Errors><ShortMessage>Upload failed.</ShortMessage>'
            . '<LongMessage>' . $longMessage . '</LongMessage></Errors>'
            . '</UploadSiteHostedPicturesResponse>';
    }

    private function cannotDownloadXml(): string
    {
        return $this->failureXml('The specified picture URL could not be downloaded.');
    }

    /** Wire the client to a queue of canned responses and record what it sends. */
    private function primeClient(array $responses): void
    {
        $sent  = [];
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($sent));

        $tokens = $this->createMock(TokenRepository::class);
        $tokens->method('getTokens')->willReturn([
            'access_token' => 'test-token',
            'expires_at'   => date('c', time() + 3600),
        ]);

        foreach ([
            'http'      => new Client(['handler' => $stack]),
            'tokenRepo' => $tokens,
            'sandbox'   => true,
        ] as $prop => $value) {
            $p = $this->ref->getProperty($prop);
            $p->setAccessible(true);
            $p->setValue($this->client, $value);
        }

        $this->history = &$sent;
    }

    private function request(int $index): RequestInterface
    {
        $this->assertArrayHasKey($index, $this->history, 'Expected a request at index ' . $index);
        return $this->history[$index]['request'];
    }

    // ── Request format ───────────────────────────────────────────────────────

    public function testSendsPictureAsBinaryMimeAttachment(): void
    {
        $this->primeClient([new Response(200, [], $this->successXml())]);

        $url = $this->client->uploadPicture(base64_encode($this->pngBytes()), 'image_1');
        $this->assertSame('https://i.ebayimg.com/00/s/test.jpg', $url);

        $request = $this->request(0);
        $body    = (string)$request->getBody();

        $this->assertStringStartsWith('multipart/form-data', $request->getHeaderLine('Content-Type'));
        $this->assertSame('UploadSiteHostedPictures', $request->getHeaderLine('X-EBAY-API-CALL-NAME'));

        // The XML part must come first and carry no inline picture data...
        $xmlPart = strpos($body, 'name="XML Payload"');
        $this->assertNotFalse($xmlPart);
        $this->assertStringNotContainsString('<PictureData>', $body);

        // ...followed by the untouched image bytes as their own part.
        $imagePart = strpos($body, 'name="image"');
        $this->assertNotFalse($imagePart);
        $this->assertGreaterThan($xmlPart, $imagePart);
        $this->assertStringContainsString('filename="image_1.png"', $body);
        $this->assertStringContainsString($this->pngBytes(), $body);
    }

    public function testReportsEbayFailureWithLongMessage(): void
    {
        $this->primeClient([new Response(200, [], $this->failureXml('File contains corrupt image data.'))]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/corrupt image data/');

        $this->client->uploadPicture(base64_encode($this->pngBytes()), 'image_1');
    }

    // ── uploadPictureFromUrl ─────────────────────────────────────────────────

    public function testAsksEbayToFetchAPastedLinkItself(): void
    {
        $this->primeClient([new Response(200, [], $this->successXml('https://i.ebayimg.com/00/s/url.jpg'))]);

        $url = $this->client->uploadPictureFromUrl('https://m.media-amazon.com/images/I/x.jpg', 'image_2');
        $this->assertSame('https://i.ebayimg.com/00/s/url.jpg', $url);

        $body = (string)$this->request(0)->getBody();
        $this->assertStringContainsString(
            '<ExternalPictureURL>https://m.media-amazon.com/images/I/x.jpg</ExternalPictureURL>',
            $body
        );
    }

    public function testDownloadsThePictureItselfWhenEbayCannotFetchIt(): void
    {
        $this->primeClient([
            new Response(200, [], $this->cannotDownloadXml()),
            new Response(200, ['Content-Type' => 'image/png'], $this->pngBytes()),
            new Response(200, [], $this->successXml('https://i.ebayimg.com/00/s/copied.jpg')),
        ]);

        $url = $this->client->uploadPictureFromUrl('https://m.media-amazon.com/images/I/x.jpg', 'image_2');
        $this->assertSame('https://i.ebayimg.com/00/s/copied.jpg', $url);

        // The picture was fetched from the original host, then posted as bytes.
        $this->assertSame('m.media-amazon.com', $this->request(1)->getUri()->getHost());
        $this->assertStringContainsString($this->pngBytes(), (string)$this->request(2)->getBody());
    }

    public function testNamesTheUnreachableUrlWhenNobodyCanFetchIt(): void
    {
        $this->primeClient([
            new Response(200, [], $this->cannotDownloadXml()),
            new Response(403, [], 'Forbidden'),
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('#https://blocked\.example/x\.jpg#');
        $this->expectExceptionMessageMatches('/upload the file instead/');

        $this->client->uploadPictureFromUrl('https://blocked.example/x.jpg', 'image_2');
    }

    public function testWillNotFetchAnImageFromOurOwnNetwork(): void
    {
        // eBay declines, and the fallback must not turn the seller's URL into a
        // request against localhost or the VM's metadata service.
        $this->primeClient([new Response(200, [], $this->cannotDownloadXml())]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/upload the file instead/');

        $this->client->uploadPictureFromUrl('http://169.254.169.254/latest/meta-data/x.jpg');

        // Only the eBay call was made — nothing was fetched.
        $this->assertCount(1, $this->history);
    }

    public function testAcceptsPublicHostsAndRefusesInternalOnes(): void
    {
        $this->assertTrue($this->call('isPublicHttpUrl', 'https://m.media-amazon.com/images/I/x.jpg'));
        $this->assertFalse($this->call('isPublicHttpUrl', 'http://localhost/x.jpg'));
        $this->assertFalse($this->call('isPublicHttpUrl', 'http://127.0.0.1/x.jpg'));
        $this->assertFalse($this->call('isPublicHttpUrl', 'http://10.0.0.5/x.jpg'));
        $this->assertFalse($this->call('isPublicHttpUrl', 'http://169.254.169.254/x.jpg'));
        $this->assertFalse($this->call('isPublicHttpUrl', 'file:///etc/passwd'));
    }

    // ── resolvePictureUrls ───────────────────────────────────────────────────

    /**
     * The listing has to end up with EPS pictures only, or eBay answers code
     * 20004 — this is what lets one listing mix uploads with pasted links.
     */
    public function testUploadedFilesAndPastedLinksBothBecomeEbayHostedPictures(): void
    {
        $this->primeClient([
            new Response(200, [], $this->successXml('https://i.ebayimg.com/00/s/from-file.jpg')),
            new Response(200, [], $this->successXml('https://i.ebayimg.com/00/s/from-link.jpg')),
        ]);

        $resolved = $this->call('resolvePictureUrls', [
            'data:image/png;base64,' . base64_encode($this->pngBytes()),  // from the computer
            'https://m.media-amazon.com/images/I/x.jpg',                  // pasted link
            'https://i.ebayimg.com/00/s/already-there.jpg',               // already on eBay
        ]);

        $this->assertSame([
            'https://i.ebayimg.com/00/s/from-file.jpg',
            'https://i.ebayimg.com/00/s/from-link.jpg',
            'https://i.ebayimg.com/00/s/already-there.jpg',
        ], $resolved);

        foreach ($resolved as $url) {
            $this->assertTrue($this->call('isEbayHostedPicture', $url), $url . ' is self hosted');
        }
    }

    public function testPassesEbayHostedPicturesThroughWithoutReUploading(): void
    {
        $this->primeClient([]);

        $images = [
            'https://i.ebayimg.com/00/s/a.jpg',
            'https://galleryplus.ebayimg.com/ws/b.jpg',
        ];

        $this->assertSame($images, $this->call('resolvePictureUrls', $images));
        $this->assertCount(0, $this->history);
    }

    public function testSkipsUnusableEntriesAndStopsAtTwelvePictures(): void
    {
        $this->primeClient([]);

        $images = array_merge(
            ['', '   ', 'not-a-url', 'ftp://host/x.jpg'],
            array_map(static fn (int $n): string => "https://i.ebayimg.com/00/s/{$n}.jpg", range(1, 14))
        );

        // eBay takes 12 images; the four junk entries fill none of those slots.
        $resolved = $this->call('resolvePictureUrls', $images);
        $this->assertCount(8, $resolved);
        $this->assertSame('https://i.ebayimg.com/00/s/1.jpg', $resolved[0]);
    }

    public function testRecognisesEbayPictureHostsOnly(): void
    {
        $this->assertTrue($this->call('isEbayHostedPicture', 'https://i.ebayimg.com/00/s/a.jpg'));
        $this->assertTrue($this->call('isEbayHostedPicture', 'https://ebayimg.com/a.jpg'));
        $this->assertFalse($this->call('isEbayHostedPicture', 'https://notebayimg.com/a.jpg'));
        $this->assertFalse($this->call('isEbayHostedPicture', 'https://evil.com/?x=i.ebayimg.com'));
    }
}
