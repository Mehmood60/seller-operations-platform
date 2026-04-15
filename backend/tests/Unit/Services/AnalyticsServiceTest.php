<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\AnalyticsService;
use App\Storage\Json\ListingRepository;
use App\Storage\Json\OrderRepository;
use Tests\TestCase;

class AnalyticsServiceTest extends TestCase
{
    private string $tempDir;
    private OrderRepository $orderRepo;
    private ListingRepository $listingRepo;
    private AnalyticsService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir     = $this->createTempDir();
        $this->orderRepo   = new OrderRepository($this->tempDir);
        $this->listingRepo = new ListingRepository($this->tempDir);
        $this->service     = new AnalyticsService($this->orderRepo, $this->listingRepo);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $this->removeDir($this->tempDir);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function seedOrder(
        string $id,
        float $amount,
        string $date,
        string $status = 'PAID',
        array $lineItems = [],
    ): void {
        $defaultItem = [
            'ebay_item_id' => 'item-' . $id,
            'title'        => 'Test Item ' . $id,
            'sku'          => '',
            'quantity'     => 1,
            'unit_price'   => ['value' => number_format($amount, 2), 'currency' => 'GBP'],
            'total_price'  => ['value' => number_format($amount, 2), 'currency' => 'GBP'],
        ];

        $this->orderRepo->save([
            'id'            => $id,
            'ebay_order_id' => $id,
            'status'        => $status,
            'buyer'         => [
                'username'         => 'buyer',
                'email'            => '',
                'shipping_address' => ['name' => '', 'line1' => '', 'line2' => '', 'city' => '', 'state' => '', 'postal_code' => '', 'country_code' => 'GB'],
            ],
            'line_items'    => $lineItems ?: [$defaultItem],
            'payment'       => ['method' => 'EBAY_MANAGED', 'status' => 'PAID', 'amount' => ['value' => number_format($amount, 2), 'currency' => 'GBP'], 'paid_at' => $date . 'T10:00:00Z'],
            'shipping'      => ['service' => '', 'cost' => ['value' => '0.00', 'currency' => 'GBP'], 'tracking_number' => null, 'shipped_at' => null, 'delivered_at' => null],
            'totals'        => [
                'subtotal'    => ['value' => number_format($amount, 2), 'currency' => 'GBP'],
                'shipping'    => ['value' => '0.00', 'currency' => 'GBP'],
                'grand_total' => ['value' => number_format($amount, 2), 'currency' => 'GBP'],
            ],
            'notes'         => '',
            'created_at'    => $date . 'T10:00:00+00:00',
            'updated_at'    => $date . 'T10:00:00+00:00',
            'synced_at'     => $date . 'T10:00:00+00:00',
        ]);
    }

    private function recentDate(int $daysAgo = 5): string
    {
        return date('Y-m-d', strtotime("-{$daysAgo} days"));
    }

    // ── getDashboard() ────────────────────────────────────────────────────────

    public function testGetDashboardReturnsCorrectedRevenue(): void
    {
        $this->seedOrder('ord-001', 50.00, $this->recentDate(5));
        $this->seedOrder('ord-002', 30.00, $this->recentDate(10));

        $dashboard = $this->service->getDashboard();

        $this->assertEquals('80.00', $dashboard['revenue_30d']['value']);
        $this->assertEquals(2, $dashboard['orders_30d']);
    }

    public function testGetDashboardExcludesCancelledOrders(): void
    {
        $this->seedOrder('ord-paid',      50.00, $this->recentDate(2), 'PAID');
        $this->seedOrder('ord-cancelled', 99.00, $this->recentDate(2), 'CANCELLED');

        $dashboard = $this->service->getDashboard();

        $this->assertEquals('50.00', $dashboard['revenue_30d']['value']);
        $this->assertEquals(1, $dashboard['orders_30d']);
    }

    public function testGetDashboardExcludesOrdersOlderThan30Days(): void
    {
        $this->seedOrder('ord-old',    100.00, date('Y-m-d', strtotime('-40 days')));
        $this->seedOrder('ord-recent',  50.00, $this->recentDate(5));

        $dashboard = $this->service->getDashboard();

        $this->assertEquals('50.00', $dashboard['revenue_30d']['value']);
        $this->assertEquals(1, $dashboard['orders_30d']);
    }

    public function testGetDashboardAvgOrderValueIsCorrect(): void
    {
        $this->seedOrder('ord-001', 60.00, $this->recentDate(2));
        $this->seedOrder('ord-002', 40.00, $this->recentDate(3));

        $dashboard = $this->service->getDashboard();

        $this->assertEquals('50.00', $dashboard['avg_order_value']['value']);
    }

    public function testGetDashboardReturnsZeroAvgWhenNoOrders(): void
    {
        $dashboard = $this->service->getDashboard();

        $this->assertEquals('0.00', $dashboard['avg_order_value']['value']);
        $this->assertEquals(0, $dashboard['orders_30d']);
    }

    public function testGetDashboardReturnsRequiredKeys(): void
    {
        $dashboard = $this->service->getDashboard();

        $this->assertArrayHasKey('revenue_30d',     $dashboard);
        $this->assertArrayHasKey('orders_30d',      $dashboard);
        $this->assertArrayHasKey('avg_order_value', $dashboard);
        $this->assertArrayHasKey('top_listings',    $dashboard);
        $this->assertArrayHasKey('revenue_by_day',  $dashboard);
    }

    // ── getSalesReport() ──────────────────────────────────────────────────────

    public function testGetSalesReportFiltersDateRange(): void
    {
        $this->seedOrder('ord-old',  100.00, '2025-01-15');
        $this->seedOrder('ord-jan',   50.00, '2026-01-10');
        $this->seedOrder('ord-feb',   75.00, '2026-02-10');

        $report = $this->service->getSalesReport('2026-01-01', '2026-01-31');

        $this->assertEquals(1, $report['total_orders']);
        $this->assertEquals('50.00', $report['total_revenue']['value']);
    }

    public function testGetSalesReportIncludesBothBoundaryDates(): void
    {
        $this->seedOrder('ord-start', 10.00, '2026-03-01');
        $this->seedOrder('ord-end',   20.00, '2026-03-31');
        $this->seedOrder('ord-out',   30.00, '2026-04-01');

        $report = $this->service->getSalesReport('2026-03-01', '2026-03-31');

        $this->assertEquals(2, $report['total_orders']);
    }

    // ── topListings() ─────────────────────────────────────────────────────────

    public function testTopListingsSortedByRevenueDescending(): void
    {
        $lineItems = fn(string $itemId, string $title, float $revenue) => [[
            'ebay_item_id' => $itemId, 'title' => $title, 'sku' => '', 'quantity' => 1,
            'unit_price'   => ['value' => number_format($revenue, 2), 'currency' => 'GBP'],
            'total_price'  => ['value' => number_format($revenue, 2), 'currency' => 'GBP'],
        ]];

        $this->seedOrder('ord-001', 0.0, $this->recentDate(2), 'PAID', $lineItems('item-A', 'Low Revenue',  10.00));
        $this->seedOrder('ord-002', 0.0, $this->recentDate(2), 'PAID', $lineItems('item-B', 'High Revenue', 50.00));
        $this->seedOrder('ord-003', 0.0, $this->recentDate(2), 'PAID', $lineItems('item-C', 'Mid Revenue',  25.00));

        $dashboard = $this->service->getDashboard();
        $top       = $dashboard['top_listings'];

        $this->assertEquals('item-B', $top[0]['listing_id']);
        $this->assertEquals('item-C', $top[1]['listing_id']);
        $this->assertEquals('item-A', $top[2]['listing_id']);
    }

    // ── revenueByDay() ────────────────────────────────────────────────────────

    public function testRevenueByDayFillsGapsWithZero(): void
    {
        $this->seedOrder('ord-001', 50.00, '2026-03-15');

        $report = $this->service->getSalesReport('2026-03-14', '2026-03-16');
        $byDay  = array_column($report['revenue_by_day'], null, 'date');

        $this->assertCount(3, $byDay);
        $this->assertEquals(0.0,  $byDay['2026-03-14']['revenue']);
        $this->assertEquals(50.0, $byDay['2026-03-15']['revenue']);
        $this->assertEquals(0.0,  $byDay['2026-03-16']['revenue']);
    }

    public function testRevenueByDayAggregatesMultipleOrdersSameDay(): void
    {
        $this->seedOrder('ord-001', 20.00, '2026-03-15');
        $this->seedOrder('ord-002', 30.00, '2026-03-15');

        $report = $this->service->getSalesReport('2026-03-15', '2026-03-15');
        $day    = $report['revenue_by_day'][0];

        $this->assertEquals(2,    $day['orders']);
        $this->assertEquals(50.0, $day['revenue']);
    }

    public function testRevenueByDayExcludesCancelledOrders(): void
    {
        $this->seedOrder('ord-paid',      50.00, '2026-03-15', 'PAID');
        $this->seedOrder('ord-cancelled', 99.00, '2026-03-15', 'CANCELLED');

        $report = $this->service->getSalesReport('2026-03-15', '2026-03-15');
        $day    = $report['revenue_by_day'][0];

        $this->assertEquals(1,    $day['orders']);
        $this->assertEquals(50.0, $day['revenue']);
    }
}
