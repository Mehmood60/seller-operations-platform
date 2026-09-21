<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\eBay\EbayClientInterface;
use App\Services\PromotionService;
use App\Storage\Json\CampaignRepository;
use App\Storage\Json\ListingRepository;
use PHPUnit\Framework\MockObject\MockObject;
use Tests\TestCase;

/**
 * Covers the Promoted Listings "Basis" toggle: the cost-per-sale ad rate eBay will
 * accept, the draft-versus-live split, and that flipping the switch off actually
 * deletes the ad on eBay rather than only clearing our own copy.
 */
class PromotionServiceTest extends TestCase
{
    private string $tempDir;
    private ListingRepository $listings;
    private CampaignRepository $campaigns;
    /** @var EbayClientInterface&MockObject */
    private $ebay;
    private PromotionService $service;

    /** Every sellRequest() the service made, as [method, path, body]. */
    private array $calls = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir   = $this->createTempDir();
        $this->listings  = new ListingRepository($this->tempDir);
        $this->campaigns = new CampaignRepository($this->tempDir);

        $this->ebay = $this->createMock(EbayClientInterface::class);
        $this->ebay->method('marketplaceId')->willReturn('EBAY_DE');

        $this->service = new PromotionService($this->listings, $this->campaigns, $this->ebay);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $this->removeDir($this->tempDir);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function seedListing(string $id, string $status, string $ebayItemId, array $promotion = []): void
    {
        $this->listings->save([
            'id'           => $id,
            'ebay_item_id' => $ebayItemId,
            'title'        => 'Suspenders',
            'sku'          => 'SKU-1',
            'status'       => $status,
            'price'        => ['value' => '19.99', 'currency' => 'EUR'],
            'quantity'     => ['available' => 3, 'sold' => 0],
            'promotion'    => $promotion,
        ]);
    }

    /**
     * Route sellRequest() to canned responses keyed by "METHOD path-suffix", and record
     * every call so a test can assert what did (or did not) reach eBay.
     *
     * Suffixes are anchored at the end of the path on purpose: ".../ad_campaign/9001"
     * and ".../ad_campaign/9001/ad" are different endpoints, and a loose prefix match
     * would quietly answer one with the other's payload.
     */
    private function respondWith(array $routes): void
    {
        $this->ebay->method('sellRequest')->willReturnCallback(
            function (string $method, string $path, ?array $body = null, array $query = []) use ($routes): array {
                $this->calls[] = [$method, $path, $body, $query];

                foreach ($routes as $key => $response) {
                    [$routeMethod, $suffix] = explode(' ', $key, 2);
                    if ($method === $routeMethod && str_ends_with($path, $suffix)) {
                        return $response + ['status' => 200, 'data' => [], 'location' => '', 'error' => ''];
                    }
                }

                return ['status' => 200, 'data' => [], 'location' => '', 'error' => ''];
            }
        );
    }

    private function callsMatching(string $method, string $suffix): array
    {
        return array_values(array_filter(
            $this->calls,
            fn(array $c): bool => $c[0] === $method && str_ends_with($c[1], $suffix),
        ));
    }

    // ── Ad rate validation ────────────────────────────────────────────────────

    public function testRejectsAdRateBelowEbayMinimum(): void
    {
        $this->seedListing('draft_1', 'DRAFT', '');

        $this->expectException(\InvalidArgumentException::class);
        $this->service->set('draft_1', true, '1.5');
    }

    public function testRejectsAdRateAboveOneHundredPercent(): void
    {
        $this->seedListing('draft_1', 'DRAFT', '');

        $this->expectException(\InvalidArgumentException::class);
        $this->service->set('draft_1', true, '101');
    }

    public function testRoundsAdRateToTheSingleDecimalEbayAccepts(): void
    {
        $this->seedListing('draft_1', 'DRAFT', '');

        $state = $this->service->set('draft_1', true, '6.44');

        $this->assertSame('6.4', $state['ad_rate']);
    }

    // ── Drafts ────────────────────────────────────────────────────────────────

    public function testDraftStoresTheChoiceWithoutCallingEbay(): void
    {
        $this->seedListing('draft_1', 'DRAFT', '');
        $this->ebay->expects($this->never())->method('sellRequest');

        $state = $this->service->set('draft_1', true, '5.0');

        $this->assertTrue($state['enabled']);
        $this->assertTrue($state['applies_on_publish']);
        $this->assertFalse($state['live']);
        $this->assertSame('', $state['ad_id']);
        $this->assertTrue($this->listings->find('draft_1')['promotion']['enabled']);
    }

    public function testListingSyncedWithoutAnItemIdIsNotTreatedAsLive(): void
    {
        $this->seedListing('l_1', 'ACTIVE', '');
        $this->ebay->expects($this->never())->method('sellRequest');

        $state = $this->service->set('l_1', true, '5.0');

        $this->assertFalse($state['live']);
    }

    public function testDefaultsToOffForAListingThatWasNeverPromoted(): void
    {
        $this->seedListing('draft_1', 'DRAFT', '');

        $state = $this->service->status('draft_1');

        $this->assertFalse($state['enabled']);
        $this->assertSame(PromotionService::DEFAULT_AD_RATE, $state['ad_rate']);
    }

    // ── Live listings ─────────────────────────────────────────────────────────

    public function testEnablingALiveListingCreatesTheCampaignAndTheAd(): void
    {
        $this->seedListing('l_1', 'ACTIVE', '110022334455');
        $this->respondWith([
            // No campaign exists yet, so one gets created…
            'GET /sell/marketing/v1/ad_campaign'  => ['data' => ['campaigns' => []]],
            'POST /sell/marketing/v1/ad_campaign' => [
                'status'   => 201,
                'location' => 'https://api.ebay.com/sell/marketing/v1/ad_campaign/9001',
            ],
            // …then the listing has no ad in it, so one gets created.
            'GET /ad'  => ['status' => 404],
            'POST /ad' => [
                'status'   => 201,
                'location' => 'https://api.ebay.com/sell/marketing/v1/ad_campaign/9001/ad/7002',
            ],
        ]);

        $state = $this->service->set('l_1', true, '8.0');

        $this->assertTrue($state['enabled']);
        $this->assertSame('7002', $state['ad_id']);
        $this->assertSame('9001', $state['campaign_id']);

        $adCall = $this->callsMatching('POST', '/ad_campaign/9001/ad')[0];
        $this->assertSame(['listingId' => '110022334455', 'bidPercentage' => '8.0'], $adCall[2]);
    }

    public function testReusesTheExistingCampaignInsteadOfCreatingASecondOne(): void
    {
        $this->campaigns->saveCampaignId('EBAY_DE', '9001');
        $this->seedListing('l_1', 'ACTIVE', '110022334455');
        $this->respondWith([
            'GET /sell/marketing/v1/ad_campaign/9001' => ['data' => ['campaignStatus' => 'RUNNING']],
            'GET /ad'  => ['status' => 404],
            'POST /ad' => ['status' => 201, 'location' => '/ad_campaign/9001/ad/7002'],
        ]);

        $this->service->set('l_1', true, '5.0');

        $this->assertSame([], $this->callsMatching('POST', '/sell/marketing/v1/ad_campaign'));
        $this->assertCount(1, $this->callsMatching('POST', '/ad_campaign/9001/ad'));
    }

    public function testChangingTheRateUpdatesTheExistingAdRatherThanDuplicatingIt(): void
    {
        $this->campaigns->saveCampaignId('EBAY_DE', '9001');
        $this->seedListing('l_1', 'ACTIVE', '110022334455');
        $this->respondWith([
            'GET /sell/marketing/v1/ad_campaign/9001' => ['data' => ['campaignStatus' => 'RUNNING']],
            'GET /ad' => ['data' => ['ads' => [['adId' => '7002', 'listingId' => '110022334455', 'bidPercentage' => '5.0']]]],
            'POST /ad/7002/update_bid' => ['status' => 204],
        ]);

        $state = $this->service->set('l_1', true, '9.5');

        $this->assertSame('7002', $state['ad_id']);
        $this->assertCount(1, $this->callsMatching('POST', '/update_bid'));
        $this->assertSame(['bidPercentage' => '9.5'], $this->callsMatching('POST', '/update_bid')[0][2]);
    }

    public function testTurningItOffDeletesTheAdOnEbay(): void
    {
        $this->campaigns->saveCampaignId('EBAY_DE', '9001');
        $this->seedListing('l_1', 'ACTIVE', '110022334455', [
            'enabled' => true, 'ad_rate' => '5.0', 'ad_id' => '7002', 'campaign_id' => '9001',
        ]);
        $this->respondWith([
            'GET /sell/marketing/v1/ad_campaign/9001' => ['data' => ['campaignStatus' => 'RUNNING']],
            'GET /ad' => ['data' => ['ads' => [['adId' => '7002', 'listingId' => '110022334455']]]],
            'DELETE /ad/7002' => ['status' => 204],
        ]);

        $state = $this->service->set('l_1', false);

        $this->assertFalse($state['enabled']);
        $this->assertSame('', $state['ad_id']);
        $this->assertCount(1, $this->callsMatching('DELETE', '/ad_campaign/9001/ad/7002'));
        $this->assertFalse($this->listings->find('l_1')['promotion']['enabled']);
    }

    public function testTurningOffAListingThatHasNoAdIsNotAnError(): void
    {
        $this->campaigns->saveCampaignId('EBAY_DE', '9001');
        $this->seedListing('l_1', 'ACTIVE', '110022334455');
        $this->respondWith([
            'GET /sell/marketing/v1/ad_campaign/9001' => ['data' => ['campaignStatus' => 'RUNNING']],
            'GET /ad' => ['status' => 404],
        ]);

        $state = $this->service->set('l_1', false);

        $this->assertFalse($state['enabled']);
        $this->assertSame([], $this->callsMatching('DELETE', '/ad'));
    }

    public function testStatusReportsEbayAsTheSourceOfTruth(): void
    {
        $this->campaigns->saveCampaignId('EBAY_DE', '9001');
        // Our copy says off, but the ad exists on eBay — eBay wins.
        $this->seedListing('l_1', 'ACTIVE', '110022334455', ['enabled' => false, 'ad_rate' => '5.0']);
        $this->respondWith([
            'GET /sell/marketing/v1/ad_campaign/9001' => ['data' => ['campaignStatus' => 'RUNNING']],
            'GET /ad' => ['data' => ['ads' => [['adId' => '7002', 'listingId' => '110022334455', 'bidPercentage' => '11.0']]]],
        ]);

        $state = $this->service->status('l_1');

        $this->assertTrue($state['enabled']);
        $this->assertSame('11.0', $state['ad_rate']);
        $this->assertTrue($this->listings->find('l_1')['promotion']['enabled']);
    }

    public function testReadingStatusNeverCreatesACampaign(): void
    {
        $this->seedListing('l_1', 'ACTIVE', '110022334455');
        $this->respondWith([
            'GET /sell/marketing/v1/ad_campaign' => ['data' => ['campaigns' => []]],
        ]);

        $state = $this->service->status('l_1');

        $this->assertFalse($state['enabled']);
        $this->assertSame([], $this->callsMatching('POST', '/sell/marketing/v1/ad_campaign'));
    }

    public function testStatusStillRendersWhenEbayIsUnreachable(): void
    {
        $this->campaigns->saveCampaignId('EBAY_DE', '9001');
        $this->seedListing('l_1', 'ACTIVE', '110022334455', ['enabled' => true, 'ad_rate' => '7.0']);
        $this->respondWith([
            'GET /sell/marketing/v1/ad_campaign/9001' => ['status' => 200, 'data' => ['campaignStatus' => 'RUNNING']],
            'GET /ad' => ['status' => 403, 'error' => 'Insufficient permissions to fulfill the request. scope'],
        ]);

        $state = $this->service->status('l_1');

        $this->assertNotSame('', $state['sync_error']);
        $this->assertTrue($state['enabled'], 'falls back to the stored value');
    }

    // ── Publish hand-off ──────────────────────────────────────────────────────

    public function testPublishAppliesTheChoiceTheDraftWasSavedWith(): void
    {
        $this->campaigns->saveCampaignId('EBAY_DE', '9001');
        $this->respondWith([
            'GET /sell/marketing/v1/ad_campaign/9001' => ['data' => ['campaignStatus' => 'RUNNING']],
            'POST /ad' => ['status' => 201, 'location' => '/ad_campaign/9001/ad/7002'],
        ]);

        $state = $this->service->applyOnPublish([
            'id'           => 'l_1',
            'status'       => 'ACTIVE',
            'ebay_item_id' => '110022334455',
            'promotion'    => ['enabled' => true, 'ad_rate' => '4.0'],
        ]);

        $this->assertSame('7002', $state['ad_id']);
        $this->assertSame('', $state['sync_error']);
    }

    public function testAFailedAdDoesNotSinkTheRestOfPublishing(): void
    {
        $this->campaigns->saveCampaignId('EBAY_DE', '9001');
        $this->respondWith([
            'GET /sell/marketing/v1/ad_campaign/9001' => ['data' => ['campaignStatus' => 'RUNNING']],
            'POST /ad' => ['status' => 403, 'error' => 'Insufficient permissions'],
        ]);

        $state = $this->service->applyOnPublish([
            'id'           => 'l_1',
            'status'       => 'ACTIVE',
            'ebay_item_id' => '110022334455',
            'promotion'    => ['enabled' => true, 'ad_rate' => '4.0'],
        ]);

        $this->assertStringContainsString('Insufficient permissions', $state['sync_error']);
        $this->assertSame('', $state['ad_id']);
    }

    public function testPublishDoesNothingWhenThePromotionWasLeftOff(): void
    {
        $this->ebay->expects($this->never())->method('sellRequest');

        $state = $this->service->applyOnPublish([
            'id'           => 'l_1',
            'status'       => 'ACTIVE',
            'ebay_item_id' => '110022334455',
            'promotion'    => ['enabled' => false, 'ad_rate' => '5.0'],
        ]);

        $this->assertFalse($state['enabled']);
    }
}
