<?php

declare(strict_types=1);

namespace App\Services;

use App\eBay\EbayClientInterface;
use App\Storage\Json\OrderRepository;

class OrderFulfillmentService
{
    public function __construct(
        private readonly OrderRepository     $orderRepo,
        private readonly EbayClientInterface $ebayClient,
    ) {}

    // ── Record AliExpress order (fulfillment step 1) ──────────────────────────

    public function fulfill(string $orderId, string $aliexpressOrderId, string $sourceUrl): array
    {
        $order = $this->orderRepo->find($orderId);
        if ($order === null) {
            throw new \RuntimeException("Order {$orderId} not found.");
        }

        $order['fulfillment'] = array_merge($order['fulfillment'] ?? [], [
            'status'              => 'ordered',
            'aliexpress_order_id' => $aliexpressOrderId,
            'source_url'          => $sourceUrl,
            'ordered_at'          => date('c'),
        ]);

        return $this->orderRepo->save($order);
    }

    // ── Add tracking and push to eBay (fulfillment step 2) ───────────────────

    public function addTracking(string $orderId, string $trackingNumber, string $carrier): array
    {
        $order = $this->orderRepo->find($orderId);
        if ($order === null) {
            throw new \RuntimeException("Order {$orderId} not found.");
        }

        $now = date('c');

        $order['shipping']['tracking_number'] = $trackingNumber;
        $order['shipping']['carrier']         = $carrier;
        $order['shipping']['shipped_at']      = $now;

        $order['fulfillment'] = array_merge($order['fulfillment'] ?? [], [
            'status'              => 'shipped',
            'tracking_number'     => $trackingNumber,
            'carrier'             => $carrier,
            'tracking_pushed_at'  => null,
        ]);

        $order['status'] = 'SHIPPED';

        // Push tracking to eBay
        $ebayOrderId = $order['ebay_order_id'] ?? '';
        if ($ebayOrderId !== '') {
            try {
                $this->ebayClient->completeSale($ebayOrderId, $trackingNumber, $carrier);
                $order['fulfillment']['tracking_pushed_at'] = date('c');
            } catch (\Throwable $e) {
                // Save locally even if eBay push fails; caller can see the null pushed_at
                $order['fulfillment']['tracking_push_error'] = $e->getMessage();
            }
        }

        return $this->orderRepo->save($order);
    }
}
