<?php

declare(strict_types=1);

namespace App\Services;

use App\Storage\Json\OrderRepository;
use App\Storage\Json\ListingRepository;

class AnalyticsService
{
    public function __construct(
        private readonly OrderRepository $orderRepo,
        private readonly ListingRepository $listingRepo,
    ) {}

    public function getDashboard(): array
    {
        $from30d = date('Y-m-d', strtotime('-30 days'));
        $today   = date('Y-m-d');
        $orders  = $this->getOrdersInRange($from30d, $today);

        return [
            'revenue_30d'     => $this->sumRevenue($orders),
            'orders_30d'      => count($orders),
            'avg_order_value' => $this->avgOrderValue($orders),
            'top_listings'    => $this->topListings($orders),
            'revenue_by_day'  => $this->revenueByDay($orders, $from30d, $today),
        ];
    }

    public function getSalesReport(string $from, string $to): array
    {
        $orders = $this->getOrdersInRange($from, $to);

        return [
            'date_range'      => ['from' => $from, 'to' => $to],
            'total_orders'    => count($orders),
            'total_revenue'   => $this->sumRevenue($orders),
            'avg_order_value' => $this->avgOrderValue($orders),
            'top_listings'    => $this->topListings($orders, 10),
            'revenue_by_day'  => $this->revenueByDay($orders, $from, $to),
            'orders'          => array_map(fn($o) => [
                'id'          => $o['id'],
                'buyer'       => $o['buyer']['username'] ?? '',
                'grand_total' => $o['totals']['grand_total'] ?? [],
                'status'      => $o['status'] ?? '',
                'created_at'  => $o['created_at'] ?? '',
            ], $orders),
        ];
    }

    private function getOrdersInRange(string $from, string $to): array
    {
        $result = $this->orderRepo->findAll(
            ['date_from' => $from, 'date_to' => $to],
            1,
            10000
        );
        return array_values(array_filter(
            $result['items'] ?? [],
            fn($o) => ($o['status'] ?? '') !== 'CANCELLED'
        ));
    }

    private function sumRevenue(array $orders): array
    {
        $total    = 0.0;
        $currency = 'GBP';

        foreach ($orders as $order) {
            $amount   = $order['totals']['grand_total'] ?? [];
            $total   += (float)($amount['value'] ?? 0);
            $currency = $amount['currency'] ?? $currency;
        }

        return ['value' => number_format($total, 2), 'currency' => $currency];
    }

    private function avgOrderValue(array $orders): array
    {
        if (empty($orders)) {
            return ['value' => '0.00', 'currency' => 'GBP'];
        }
        $revenue  = (float)$this->sumRevenue($orders)['value'];
        $currency = $orders[0]['totals']['grand_total']['currency'] ?? 'GBP';
        return [
            'value'    => number_format($revenue / count($orders), 2),
            'currency' => $currency,
        ];
    }

    private function topListings(array $orders, int $limit = 5): array
    {
        $listing_stats = [];

        foreach ($orders as $order) {
            foreach ($order['line_items'] ?? [] as $item) {
                $id    = $item['ebay_item_id'] ?? '';
                $title = $item['title'] ?? '';
                $qty   = (int)($item['quantity'] ?? 1);
                $rev   = (float)($item['total_price']['value'] ?? 0);
                $cur   = $item['total_price']['currency'] ?? 'GBP';

                if (!isset($listing_stats[$id])) {
                    $listing_stats[$id] = [
                        'listing_id'    => $id,
                        'title'         => $title,
                        'total_sold'    => 0,
                        'total_revenue' => 0.0,
                        'currency'      => $cur,
                    ];
                }
                $listing_stats[$id]['total_sold']    += $qty;
                $listing_stats[$id]['total_revenue'] += $rev;
            }
        }

        usort($listing_stats, fn($a, $b) => $b['total_revenue'] <=> $a['total_revenue']);

        return array_map(fn($s) => [
            'listing_id'    => $s['listing_id'],
            'title'         => $s['title'],
            'total_sold'    => $s['total_sold'],
            'total_revenue' => [
                'value'    => number_format($s['total_revenue'], 2),
                'currency' => $s['currency'],
            ],
        ], array_slice(array_values($listing_stats), 0, $limit));
    }

    private function revenueByDay(array $orders, string $from, string $to): array
    {
        $byDay = [];

        // Build a map with zero values for every day in range
        $cursor = strtotime($from);
        $end    = strtotime($to);
        while ($cursor <= $end) {
            $day         = date('Y-m-d', $cursor);
            $byDay[$day] = ['date' => $day, 'revenue' => 0.0, 'orders' => 0];
            $cursor      = strtotime('+1 day', $cursor);
        }

        foreach ($orders as $order) {
            $day = substr($order['created_at'] ?? '', 0, 10);
            if (isset($byDay[$day])) {
                $byDay[$day]['revenue'] += (float)($order['totals']['grand_total']['value'] ?? 0);
                $byDay[$day]['orders']++;
            }
        }

        return array_values($byDay);
    }
}
