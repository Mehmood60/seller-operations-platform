<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Turns eBay Finances API transactions into what the seller actually keeps from
 * an order — the "Bestelleinnahmen" figure in Seller Hub: the sale price minus
 * transaction fees, Promoted Listings ad fees, shipping labels and refunds.
 */
class EarningsCalculator
{
    /** Transaction types that only move money between eBay and the bank account. */
    private const MONEY_MOVEMENTS = ['PAYOUT', 'WITHDRAWAL', 'TRANSFER', 'LOAN_REPAYMENT', 'PURCHASE'];

    private const FEE_LABELS = [
        'FINAL_VALUE_FEE'                 => 'Final value fee',
        'FINAL_VALUE_FEE_FIXED_PER_ORDER' => 'Fixed fee per order',
        'FINAL_VALUE_SHIPPING_FEE'        => 'Final value fee on shipping',
        'INTERNATIONAL_FEE'               => 'International fee',
        'REGULATORY_OPERATING_FEE'        => 'Regulatory operating fee',
        'AD_FEE'                          => 'Promoted Listings ad fee',
        'INSERTION_FEE'                   => 'Insertion fee',
        'SUBSCRIPTION_FEE'                => 'Store subscription',
        'SHIPPING_LABEL'                  => 'Shipping label',
        'MARKETPLACE_FEE'                 => 'eBay fees',
        'OTHER_ADJUSTMENT'                => 'Other adjustments',
    ];

    public static function label(string $type): string
    {
        return self::FEE_LABELS[$type] ?? ucfirst(strtolower(str_replace('_', ' ', $type)));
    }

    /** The order a transaction belongs to, or null for account-level charges. */
    public static function orderIdOf(array $tx): ?string
    {
        $id = trim((string)($tx['orderId'] ?? ''));
        if ($id !== '') {
            return $id;
        }
        foreach ($tx['references'] ?? [] as $ref) {
            if (($ref['referenceType'] ?? '') === 'ORDER_ID' && trim((string)($ref['referenceId'] ?? '')) !== '') {
                return trim((string)$ref['referenceId']);
            }
        }
        return null;
    }

    /** Stable key — eBay reuses an id across a sale and its related fee entries. */
    public static function key(array $tx): string
    {
        return ($tx['transactionType'] ?? '') . ':' . ($tx['transactionId'] ?? '');
    }

    /** Keep only what the calculation needs, so stored orders stay small. */
    public static function slim(array $tx): array
    {
        $fees = [];
        foreach ($tx['orderLineItems'] ?? [] as $line) {
            foreach ($line['marketplaceFees'] ?? [] as $fee) {
                $fees[] = [
                    'type'   => (string)($fee['feeType'] ?? 'MARKETPLACE_FEE'),
                    'amount' => (float)($fee['amount']['value'] ?? 0),
                ];
            }
        }

        return [
            'id'       => (string)($tx['transactionId'] ?? ''),
            'type'     => (string)($tx['transactionType'] ?? ''),
            'booking'  => (string)($tx['bookingEntry'] ?? 'CREDIT'),
            'amount'   => (float)($tx['amount']['value'] ?? 0),
            'currency' => (string)($tx['amount']['currency'] ?? 'EUR'),
            'date'     => (string)($tx['transactionDate'] ?? ''),
            'status'   => (string)($tx['transactionStatus'] ?? ''),
            'fee_type' => (string)($tx['feeType'] ?? ''),
            'fees'     => $fees,
        ];
    }

    /**
     * @param array<string, array> $transactions slimmed transactions keyed by key()
     * @param array                $order        the stored order (for the sale price)
     */
    public static function fromTransactions(array $transactions, array $order): array
    {
        $gross    = (float)($order['totals']['grand_total']['value'] ?? 0);
        $currency = (string)($order['totals']['grand_total']['currency'] ?? 'EUR');
        $net      = 0.0;
        $refunds  = 0.0;
        $fees     = [];
        $onHold   = false;

        foreach ($transactions as $tx) {
            $sign   = ($tx['booking'] ?? 'CREDIT') === 'DEBIT' ? -1 : 1;
            $amount = (float)($tx['amount'] ?? 0);
            $net   += $sign * $amount;
            $onHold = $onHold || ($tx['status'] ?? '') === 'FUNDS_ON_HOLD';
            if (!empty($tx['currency'])) {
                $currency = $tx['currency'];
            }

            switch ($tx['type'] ?? '') {
                case 'SALE':
                    // The sale is credited net of these fees; they are listed for the breakdown.
                    foreach ($tx['fees'] ?? [] as $fee) {
                        $fees[$fee['type']] = ($fees[$fee['type']] ?? 0) + (float)$fee['amount'];
                    }
                    break;
                case 'REFUND':
                    $refunds -= $sign * $amount;
                    break;
                case 'SHIPPING_LABEL':
                    $fees['SHIPPING_LABEL'] = ($fees['SHIPPING_LABEL'] ?? 0) - $sign * $amount;
                    break;
                default:
                    // Ad fees and other order-linked charges; a credit reduces the cost.
                    $type = ($tx['fee_type'] ?? '') !== '' ? $tx['fee_type'] : 'OTHER_ADJUSTMENT';
                    $fees[$type] = ($fees[$type] ?? 0) - $sign * $amount;
            }
        }

        // Anything the itemised lines do not explain (rounding, tax adjustments).
        $explained = $gross - array_sum($fees) - $refunds;
        if ($transactions !== [] && abs($explained - $net) >= 0.01) {
            $fees['OTHER_ADJUSTMENT'] = ($fees['OTHER_ADJUSTMENT'] ?? 0) + ($explained - $net);
        }

        $feeLines = [];
        foreach ($fees as $type => $amount) {
            if (abs($amount) < 0.005) {
                continue;
            }
            $feeLines[] = ['type' => $type, 'label' => self::label($type), 'amount' => self::amount($amount)];
        }

        return [
            'source'       => 'finances',
            'currency'     => $currency,
            'gross'        => self::amount($gross),
            'fees'         => $feeLines,
            'total_fees'   => self::amount(array_sum($fees)),
            'refunds'      => self::amount($refunds),
            'net'          => self::amount($net),
            'on_hold'      => $onHold,
            'transactions' => $transactions,
            'updated_at'   => date('c'),
        ];
    }

    /** Whether a transaction with no order is a cost of selling (insertion fee, subscription, …). */
    public static function isAccountCharge(array $tx): bool
    {
        return !in_array($tx['transactionType'] ?? '', array_merge(['SALE'], self::MONEY_MOVEMENTS), true);
    }

    /** An account-level charge as stored: amount is the cost (negative for a credit). */
    public static function toCharge(array $tx): array
    {
        $slim = self::slim($tx);
        $type = $slim['fee_type'] !== '' ? $slim['fee_type'] : $slim['type'];

        return [
            'id'       => self::key($tx),
            'date'     => substr($slim['date'], 0, 10),
            'type'     => $type,
            'label'    => self::label($type),
            'memo'     => (string)($tx['transactionMemo'] ?? ''),
            'amount'   => self::amount($slim['booking'] === 'DEBIT' ? $slim['amount'] : -$slim['amount']),
            'currency' => $slim['currency'],
        ];
    }

    public static function amount(float $value): string
    {
        return number_format(round($value, 2) + 0.0, 2, '.', '');
    }
}
