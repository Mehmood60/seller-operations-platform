<?php

declare(strict_types=1);

namespace App\Services;

use App\eBay\EbayClientInterface;
use App\Storage\Json\CampaignRepository;
use App\Storage\Json\ListingRepository;

/**
 * Promoted Listings "Basis" — eBay's general campaign strategy, funding model
 * COST_PER_SALE.
 *
 * Only the cost-per-sale model is wired up on purpose. It costs nothing unless the
 * item actually sells, so a plain on/off switch is honest about what it does. The
 * cost-per-click model ("Premium") charges for clicks that never convert and needs a
 * daily budget, which is not something a single toggle can express.
 *
 * Everything hangs off one long-running campaign per marketplace: turning a listing on
 * adds an ad to that campaign, turning it off deletes the ad. Drafts have no eBay item
 * id yet, so their setting is stored and applied by applyOnPublish() once they go live.
 */
class PromotionService
{
    /** eBay rejects anything outside this band for a cost-per-sale ad rate. */
    public const MIN_AD_RATE = 2.0;
    public const MAX_AD_RATE = 100.0;
    public const DEFAULT_AD_RATE = '5.0';

    private const CAMPAIGN_NAME  = 'Seller Operations Platform - Basis';
    private const CAMPAIGN_BASE  = '/sell/marketing/v1/ad_campaign';
    private const DEAD_STATUSES  = ['ENDED', 'ARCHIVED'];

    public function __construct(
        private readonly ListingRepository   $listings,
        private readonly CampaignRepository  $campaigns,
        private readonly EbayClientInterface $ebay,
    ) {}

    /**
     * The shape every listing carries, whether or not it has ever been promoted.
     */
    public static function defaultState(): array
    {
        return [
            'enabled'     => false,
            'ad_rate'     => self::DEFAULT_AD_RATE,
            'ad_id'       => '',
            'campaign_id' => '',
            'synced_at'   => '',
            'sync_error'  => '',
        ];
    }

    /**
     * Initial state for a brand-new draft, from whatever the create form sent. Only the
     * two fields a seller controls are honoured — the eBay-side ids are not something a
     * client gets to set.
     */
    public static function seedState(mixed $input): array
    {
        $state = self::defaultState();

        if (!is_array($input)) {
            return $state;
        }

        $state['enabled'] = filter_var($input['enabled'] ?? false, FILTER_VALIDATE_BOOLEAN);
        if (isset($input['ad_rate']) && $input['ad_rate'] !== '') {
            $state['ad_rate'] = self::normaliseRate((string)$input['ad_rate']);
        }

        return $state;
    }

    /**
     * Current promotion state for a listing, reconciled against eBay when the listing
     * is live. A failed reconcile is reported alongside the stored state rather than
     * thrown — the page still has to render.
     */
    public function status(string $listingId): ?array
    {
        $listing = $this->listings->find($listingId);
        if ($listing === null) {
            return null;
        }

        $state = $this->stateOf($listing);

        if (!$this->isLive($listing)) {
            return $this->decorate($state, $listing);
        }

        try {
            $campaignId = $this->findExistingCampaignId();
            $ad         = $campaignId !== ''
                ? $this->findAd($campaignId, (string)$listing['ebay_item_id'])
                : null;

            $state['campaign_id'] = $campaignId;
            $state['enabled']     = $ad !== null;
            $state['ad_id']       = $ad['adId'] ?? '';
            $state['sync_error']  = '';
            if ($ad !== null && !empty($ad['bidPercentage'])) {
                $state['ad_rate'] = (string)$ad['bidPercentage'];
            }
            $state['synced_at'] = date('c');

            $this->persist($listing, $state);
        } catch (\RuntimeException $e) {
            $state['sync_error'] = $e->getMessage();
        }

        return $this->decorate($state, $listing);
    }

    /**
     * Turn promotion on or off for a listing and push the change to eBay.
     *
     * @param  string      $listingId Our listing id (draft or live).
     * @param  bool        $enabled   Desired state.
     * @param  string|null $adRate    Ad rate in percent; kept as-is when null.
     */
    public function set(string $listingId, bool $enabled, ?string $adRate = null): ?array
    {
        $listing = $this->listings->find($listingId);
        if ($listing === null) {
            return null;
        }

        $state = $this->stateOf($listing);
        $rate  = $adRate !== null
            ? self::normaliseRate($adRate)
            : self::normaliseRate($state['ad_rate']);

        $state['enabled'] = $enabled;
        $state['ad_rate'] = $rate;

        // A draft has no eBay item id to advertise yet. Remember the choice; publish applies it.
        if (!$this->isLive($listing)) {
            $state['ad_id']      = '';
            $state['synced_at']  = '';
            $state['sync_error'] = '';
            $this->persist($listing, $state);
            return $this->decorate($state, $listing);
        }

        $ebayItemId = (string)$listing['ebay_item_id'];
        $campaignId = $this->ensureCampaignId();
        $existing   = $this->findAd($campaignId, $ebayItemId);

        if ($enabled) {
            $state['ad_id'] = $existing === null
                ? $this->createAd($campaignId, $ebayItemId, $rate)
                : $this->updateAdRate($campaignId, (string)$existing['adId'], $rate);
        } else {
            if ($existing !== null) {
                $this->deleteAd($campaignId, (string)$existing['adId']);
            }
            $state['ad_id'] = '';
        }

        $state['campaign_id'] = $campaignId;
        $state['synced_at']   = date('c');
        $state['sync_error']  = '';

        $this->persist($listing, $state);

        return $this->decorate($state, $listing);
    }

    /**
     * Called right after a draft goes live. Publishing must not fail because an ad
     * could not be created, so the error is recorded on the listing instead of thrown.
     *
     * @param  array $listing The freshly published listing, with ebay_item_id set.
     * @return array The promotion state to store on it.
     */
    public function applyOnPublish(array $listing): array
    {
        $state = $this->stateOf($listing);

        if (!$state['enabled'] || !$this->isLive($listing)) {
            return $state;
        }

        try {
            $campaignId = $this->ensureCampaignId();
            $rate       = self::normaliseRate($state['ad_rate']);

            $state['ad_id']       = $this->createAd($campaignId, (string)$listing['ebay_item_id'], $rate);
            $state['ad_rate']     = $rate;
            $state['campaign_id'] = $campaignId;
            $state['synced_at']   = date('c');
            $state['sync_error']  = '';
        } catch (\Throwable $e) {
            // The listing is live either way; the toggle just could not be applied.
            $state['sync_error'] = $e->getMessage();
        }

        return $state;
    }

    // ── Listing state helpers ─────────────────────────────────────────────────

    /**
     * A listing can only be advertised once eBay has given it an item id. Anything
     * still in DRAFT, or synced without an id, is not promotable yet.
     */
    private function isLive(array $listing): bool
    {
        return ($listing['status'] ?? '') === 'ACTIVE'
            && trim((string)($listing['ebay_item_id'] ?? '')) !== '';
    }

    private function stateOf(array $listing): array
    {
        $stored = is_array($listing['promotion'] ?? null) ? $listing['promotion'] : [];
        return array_merge(self::defaultState(), $stored);
    }

    /**
     * Add the bits the UI needs but that are not worth persisting.
     */
    private function decorate(array $state, array $listing): array
    {
        $live = $this->isLive($listing);

        return $state + [
            'live'               => $live,
            'applies_on_publish' => !$live && $state['enabled'],
            'min_ad_rate'        => self::MIN_AD_RATE,
            'max_ad_rate'        => self::MAX_AD_RATE,
        ];
    }

    private function persist(array $listing, array $state): void
    {
        $listing['promotion'] = $state;
        $this->listings->save($listing);
    }

    /**
     * eBay takes a single decimal place — 4.1 and 5.0 are fine, 10.75 is rejected.
     */
    public static function normaliseRate(string|float $rate): string
    {
        $value = round((float)$rate, 1);

        if ($value < self::MIN_AD_RATE || $value > self::MAX_AD_RATE) {
            throw new \InvalidArgumentException(sprintf(
                'Ad rate must be between %.1f%% and %.1f%% — eBay rejects anything outside that range.',
                self::MIN_AD_RATE,
                self::MAX_AD_RATE,
            ));
        }

        return number_format($value, 1, '.', '');
    }

    // ── Campaign ──────────────────────────────────────────────────────────────

    /**
     * This app's cost-per-sale campaign if it already exists, otherwise an empty string.
     *
     * Merely looking at a listing must not create anything on the seller's account, so
     * the read path uses this and only set() escalates to ensureCampaignId().
     */
    private function findExistingCampaignId(): string
    {
        $marketplace = $this->ebay->marketplaceId();

        $cached = $this->campaigns->getCampaignId($marketplace);
        if ($cached !== '' && $this->campaignIsUsable($cached)) {
            return $cached;
        }
        if ($cached !== '') {
            $this->campaigns->forget($marketplace);
        }

        $found = $this->findCampaignByName($marketplace);
        if ($found !== '') {
            $this->campaigns->saveCampaignId($marketplace, $found);
        }

        return $found;
    }

    /**
     * The id of this app's cost-per-sale campaign, creating it on first use.
     */
    private function ensureCampaignId(): string
    {
        $campaignId = $this->findExistingCampaignId();
        if ($campaignId !== '') {
            return $campaignId;
        }

        $marketplace = $this->ebay->marketplaceId();
        $campaignId  = $this->createCampaign($marketplace);
        $this->campaigns->saveCampaignId($marketplace, $campaignId);

        return $campaignId;
    }

    private function campaignIsUsable(string $campaignId): bool
    {
        $res = $this->ebay->sellRequest('GET', self::CAMPAIGN_BASE . '/' . rawurlencode($campaignId));
        if ($res['status'] !== 200) {
            return false;
        }
        return !in_array(strtoupper((string)($res['data']['campaignStatus'] ?? '')), self::DEAD_STATUSES, true);
    }

    private function findCampaignByName(string $marketplace): string
    {
        $res = $this->ebay->sellRequest('GET', self::CAMPAIGN_BASE, null, [
            'campaign_name' => self::CAMPAIGN_NAME,
            'limit'         => 50,
        ]);

        if ($res['status'] >= 400) {
            throw new \RuntimeException('Could not read your eBay ad campaigns: ' . $res['error']);
        }

        foreach ($res['data']['campaigns'] ?? [] as $campaign) {
            $sameMarket = (string)($campaign['marketplaceId'] ?? '') === $marketplace;
            $costPerSale = strtoupper((string)($campaign['fundingStrategy']['fundingModel'] ?? '')) === 'COST_PER_SALE';
            $alive = !in_array(strtoupper((string)($campaign['campaignStatus'] ?? '')), self::DEAD_STATUSES, true);

            if ($sameMarket && $costPerSale && $alive) {
                return (string)$campaign['campaignId'];
            }
        }

        return '';
    }

    private function createCampaign(string $marketplace): string
    {
        $res = $this->ebay->sellRequest('POST', self::CAMPAIGN_BASE, [
            'marketplaceId'  => $marketplace,
            'campaignName'   => self::CAMPAIGN_NAME,
            'fundingStrategy' => [
                'fundingModel'  => 'COST_PER_SALE',
                // Campaign-level default only; every ad we create carries its own rate.
                'bidPercentage' => self::DEFAULT_AD_RATE,
            ],
            // eBay rejects a start date in the past, and clocks drift — leave a margin.
            'startDate' => gmdate('Y-m-d\TH:i:s\Z', time() + 120),
        ]);

        if ($res['status'] !== 201) {
            // A name clash means someone (or a previous run) already made it.
            $existing = $this->findCampaignByName($marketplace);
            if ($existing !== '') {
                return $existing;
            }
            throw new \RuntimeException('Could not create the eBay ad campaign: ' . $res['error']);
        }

        $campaignId = $this->idFromLocation($res['location'])
            ?: (string)($res['data']['campaignId'] ?? '');

        if ($campaignId === '') {
            $campaignId = $this->findCampaignByName($marketplace);
        }
        if ($campaignId === '') {
            throw new \RuntimeException('eBay created the ad campaign but returned no campaign id.');
        }

        return $campaignId;
    }

    // ── Ads ───────────────────────────────────────────────────────────────────

    private function findAd(string $campaignId, string $ebayItemId): ?array
    {
        $res = $this->ebay->sellRequest(
            'GET',
            self::CAMPAIGN_BASE . '/' . rawurlencode($campaignId) . '/ad',
            null,
            ['listing_ids' => $ebayItemId],
        );

        // 404 is eBay's way of saying this listing has no ad in the campaign.
        if ($res['status'] === 404) {
            return null;
        }
        if ($res['status'] >= 400) {
            throw new \RuntimeException('Could not read the ad status for this listing: ' . $res['error']);
        }

        foreach ($res['data']['ads'] ?? [] as $ad) {
            if ((string)($ad['listingId'] ?? '') === $ebayItemId) {
                return $ad;
            }
        }

        return null;
    }

    private function createAd(string $campaignId, string $ebayItemId, string $rate): string
    {
        $res = $this->ebay->sellRequest(
            'POST',
            self::CAMPAIGN_BASE . '/' . rawurlencode($campaignId) . '/ad',
            ['listingId' => $ebayItemId, 'bidPercentage' => $rate],
        );

        if ($res['status'] !== 201) {
            throw new \RuntimeException($this->describeAdFailure($res['error']));
        }

        return $this->idFromLocation($res['location']) ?: (string)($res['data']['adId'] ?? '');
    }

    private function updateAdRate(string $campaignId, string $adId, string $rate): string
    {
        $res = $this->ebay->sellRequest(
            'POST',
            self::CAMPAIGN_BASE . '/' . rawurlencode($campaignId) . '/ad/' . rawurlencode($adId) . '/update_bid',
            ['bidPercentage' => $rate],
        );

        if ($res['status'] >= 400) {
            throw new \RuntimeException('Could not update the ad rate on eBay: ' . $res['error']);
        }

        return $adId;
    }

    private function deleteAd(string $campaignId, string $adId): void
    {
        $res = $this->ebay->sellRequest(
            'DELETE',
            self::CAMPAIGN_BASE . '/' . rawurlencode($campaignId) . '/ad/' . rawurlencode($adId),
        );

        // Already gone is the state we wanted anyway.
        if ($res['status'] >= 400 && $res['status'] !== 404) {
            throw new \RuntimeException('Could not stop the ad on eBay: ' . $res['error']);
        }
    }

    /**
     * A listing may only sit in one cost-per-sale campaign. If it is already promoted
     * through Seller Hub, eBay refuses — say so instead of echoing the raw error.
     */
    private function describeAdFailure(string $error): string
    {
        if (preg_match('/already|duplicate|exists/i', $error) === 1) {
            return 'This listing is already promoted in another eBay campaign. '
                 . 'Turn that campaign off in Seller Hub first, then retry here. (eBay said: ' . $error . ')';
        }

        return 'Could not start the ad on eBay: ' . $error;
    }

    /**
     * eBay returns new resource ids only in the Location header, e.g.
     * ".../ad_campaign/10101/ad/20202" — the id is the last path segment.
     */
    private function idFromLocation(string $location): string
    {
        if ($location === '') {
            return '';
        }
        $path = parse_url($location, PHP_URL_PATH) ?: $location;
        $last = basename(rtrim($path, '/'));

        return preg_match('/^[A-Za-z0-9_-]+$/', $last) === 1 ? $last : '';
    }
}
