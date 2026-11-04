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
 *   "File contains corrupt image data"  — a data: URL has to decode to the
 *   raw bytes of a format eBay hosts.
 *   code 20004, "A mixture of Self Hosted and EPS pictures are not allowed"
 *   — every picture in a listing has to end up on eBay's own servers, whether
 *   the seller uploaded a file or pasted a link.
 * Uploads go through the Media API; the Trading API's UploadSiteHostedPictures
 * was decommissioned and answers 404.
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

    private function success(string $imageUrl = 'https://i.ebayimg.com/00/s/test.jpg'): Response
    {
        return new Response(201, ['Content-Type' => 'application/json'], json_encode([
            'imageUrl'       => $imageUrl,
            'expirationDate' => '2026-12-04T00:00:00.000Z',
        ]));
    }

    private function failure(string $message, int $status = 400): Response
    {
        return new Response($status, ['Content-Type' => 'application/json'], json_encode([
            'errors' => [['errorId' => 190002, 'message' => $message, 'longMessage' => $message]],
        ]));
    }

    private function cannotDownload(): Response
    {
        return $this->failure('The image at the given URL could not be downloaded.');
    }

    /** Wire the client to a queue of canned responses and record what it sends. */
    private function primeClient(array $responses): void
    {
        $sent  = [];
        $stack = HandlerStack::create(new MockHandler($responses));
        // Recorded after prepare_body so headers Guzzle adds on the way out are visible.
        $stack->after('prepare_body', Middleware::history($sent));

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

    public function testSendsPictureToMediaApiAsMultipartFile(): void
    {
        $this->primeClient([$this->success()]);

        $url = $this->client->uploadPicture(base64_encode($this->pngBytes()), 'image_1');
        $this->assertSame('https://i.ebayimg.com/00/s/test.jpg', $url);

        $request = $this->request(0);
        $body    = (string)$request->getBody();

        // The retired Trading call (UploadSiteHostedPictures) now answers 404.
        $this->assertSame('apim.sandbox.ebay.com', $request->getUri()->getHost());
        $this->assertSame('/commerce/media/v1_beta/image/create_image_from_file', $request->getUri()->getPath());
        $this->assertSame('Bearer test-token', $request->getHeaderLine('Authorization'));
        $this->assertStringStartsWith('multipart/form-data', $request->getHeaderLine('Content-Type'));

        $this->assertStringContainsString('name="image"', $body);
        $this->assertStringContainsString('filename="image_1.png"', $body);
        $this->assertStringContainsString($this->pngBytes(), $body);
    }

    public function testFollowsLocationWhenUrlIsNotInTheBody(): void
    {
        $this->primeClient([
            new Response(201, ['Location' => 'https://apim.sandbox.ebay.com/commerce/media/v1_beta/image/abc'], ''),
            new Response(200, [], json_encode(['imageUrl' => 'https://i.ebayimg.com/00/s/abc.jpg'])),
        ]);

        $url = $this->client->uploadPicture(base64_encode($this->pngBytes()), 'image_1');

        $this->assertSame('https://i.ebayimg.com/00/s/abc.jpg', $url);
        $this->assertSame('/commerce/media/v1_beta/image/abc', $this->request(1)->getUri()->getPath());
    }

    public function testLargePictureIsSentWithoutExpectContinue(): void
    {
        $this->primeClient([$this->success()]);

        // A valid PNG padded past Guzzle's 1 MB Expect threshold.
        $big = $this->pngBytes() . str_repeat("\0", 2 * 1024 * 1024);
        $this->client->uploadPicture(base64_encode($big), 'image_1');

        $this->assertFalse($this->request(0)->hasHeader('Expect'));
    }

    public function testReportsEbayFailureWithLongMessage(): void
    {
        $this->primeClient([$this->failure('File contains corrupt image data.')]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/corrupt image data/');

        $this->client->uploadPicture(base64_encode($this->pngBytes()), 'image_1');
    }

    // ── uploadPictureFromUrl ─────────────────────────────────────────────────

    public function testAsksEbayToFetchAPastedLinkItself(): void
    {
        $this->primeClient([$this->success('https://i.ebayimg.com/00/s/url.jpg')]);

        $url = $this->client->uploadPictureFromUrl('https://m.media-amazon.com/images/I/x.jpg', 'image_2');
        $this->assertSame('https://i.ebayimg.com/00/s/url.jpg', $url);

        $request = $this->request(0);
        $this->assertSame('/commerce/media/v1_beta/image/create_image_from_url', $request->getUri()->getPath());
        $this->assertSame(
            ['imageUrl' => 'https://m.media-amazon.com/images/I/x.jpg'],
            json_decode((string)$request->getBody(), true)
        );
    }

    public function testDownloadsThePictureItselfWhenEbayCannotFetchIt(): void
    {
        $this->primeClient([
            $this->cannotDownload(),
            new Response(200, ['Content-Type' => 'image/png'], $this->pngBytes()),
            $this->success('https://i.ebayimg.com/00/s/copied.jpg'),
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
            $this->cannotDownload(),
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
        $this->primeClient([$this->cannotDownload()]);

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
            $this->success('https://i.ebayimg.com/00/s/from-file.jpg'),
            $this->success('https://i.ebayimg.com/00/s/from-link.jpg'),
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
