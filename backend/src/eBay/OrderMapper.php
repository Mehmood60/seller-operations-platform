<?php

declare(strict_types=1);

namespace App\eBay;

class OrderMapper
{
    /**
     * Map an eBay Fulfillment API order response to our internal Order model.
     */
    public function map(array $ebayOrder): array
    {
        $orderId = $ebayOrder['orderId'] ?? '';

        return [
            'id'            => $orderId,
            'ebay_order_id' => $orderId,
            'status'        => $this->mapStatus(
                $ebayOrder['orderFulfillmentStatus'] ?? '',
                $ebayOrder['cancelStatus'] ?? []
            ),
            'buyer'         => $this->mapBuyer($ebayOrder),
            'line_items'    => $this->mapLineItems($ebayOrder['lineItems'] ?? []),
            'payment'       => $this->mapPayment($ebayOrder),
            'shipping'      => $this->mapShipping($ebayOrder),
            'totals'        => $this->mapTotals($ebayOrder),
            'notes'         => '',
            'created_at'    => $ebayOrder['creationDate'] ?? date('c'),
            'updated_at'    => $ebayOrder['lastModifiedDate'] ?? date('c'),
            'synced_at'     => date('c'),
        ];
    }

    private function mapStatus(string $fulfillmentStatus, array $cancelStatus): string
    {
        if (!empty($cancelStatus['cancelState']) && $cancelStatus['cancelState'] !== 'NONE_REQUESTED') {
            return 'CANCELLED';
        }
        return match ($fulfillmentStatus) {
            'FULFILLED'   => 'DELIVERED',
            'IN_PROGRESS' => 'SHIPPED',
            'NOT_STARTED' => 'PAID',
            default       => 'PAID',
        };
    }

    private function mapBuyer(array $order): array
    {
        $buyer           = $order['buyer'] ?? [];
        $shippingAddress = $order['fulfillmentStartInstructions'][0]['shippingStep']['shipTo'] ?? [];
        $addr            = $shippingAddress['contactAddress'] ?? [];

        return [
            'username' => $buyer['username'] ?? '',
            'email'    => $buyer['taxAddress']['stateOrProvince'] ?? '',
            'shipping_address' => [
                'name'         => $shippingAddress['fullName'] ?? '',
                'line1'        => $addr['addressLine1'] ?? '',
                'line2'        => $addr['addressLine2'] ?? '',
                'city'         => $addr['city'] ?? '',
                'state'        => $addr['stateOrProvince'] ?? '',
                'postal_code'  => $addr['postalCode'] ?? '',
                'country_code' => $addr['countryCode'] ?? '',
            ],
        ];
    }

    private function mapLineItems(array $lineItems): array
    {
        return array_map(function (array $item): array {
            return [
                'ebay_item_id' => $item['legacyItemId'] ?? $item['lineItemId'] ?? '',
                'title'        => $item['title'] ?? '',
                'sku'          => $item['sku'] ?? '',
                'quantity'     => (int)($item['quantity'] ?? 1),
                'unit_price'   => $this->mapMoney($item['lineItemCost'] ?? []),
                'total_price'  => $this->mapMoney($item['total'] ?? $item['lineItemCost'] ?? []),
            ];
        }, $lineItems);
    }

    private function mapPayment(array $order): array
    {
        $payment      = $order['paymentSummary'] ?? [];
        $payments     = $payment['payments'] ?? [];
        $firstPayment = $payments[0] ?? [];

        return [
            'method'  => $firstPayment['paymentMethod'] ?? 'EBAY_MANAGED',
            'status'  => 'PAID',
            'amount'  => $this->mapMoney($payment['totalDueSeller'] ?? []),
            'paid_at' => $firstPayment['paymentDate'] ?? null,
        ];
    }

    private function mapShipping(array $order): array
    {
        $fulfillment  = $order['fulfillmentStartInstructions'][0] ?? [];
        $shippingStep = $fulfillment['shippingStep'] ?? [];

        return [
            'service'         => $shippingStep['shippingServiceCode'] ?? '',
            'cost'            => $this->mapMoney($order['shippingCost'] ?? []),
            'tracking_number' => null,
            'shipped_at'      => null,
            'delivered_at'    => null,
        ];
    }

    private function mapTotals(array $order): array
    {
        $pricing = $order['pricingSummary'] ?? [];

        return [
            'subtotal'    => $this->mapMoney($pricing['priceSubtotal'] ?? []),
            'shipping'    => $this->mapMoney($pricing['deliveryCost'] ?? []),
            'grand_total' => $this->mapMoney($pricing['total'] ?? []),
        ];
    }

    private function mapMoney(array $money): array
    {
        return [
            'value'    => $money['value'] ?? '0.00',
            'currency' => $money['currency'] ?? 'GBP',
        ];
    }
}
