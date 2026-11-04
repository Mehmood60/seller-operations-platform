<?php

declare(strict_types=1);

namespace App\Services;

use App\Storage\Json\EbayChargeRepository;
use App\Storage\Json\ExpenseRepository;
use App\Storage\Json\OrderRepository;

/**
 * Profit and loss: what eBay paid out for orders, minus eBay charges that belong
 * to no order, minus the business expenses the seller records by hand.
 */
class FinanceService
{
    public const CATEGORIES = [
        'business_registration' => 'Business registration',
        'ebay_fees'             => 'eBay fees',
        'product_purchases'     => 'Product purchases',
        'shipping'              => 'Shipping & packaging',
        'software'              => 'Software & subscriptions',
        'taxes'                 => 'Taxes & accounting',
        'marketing'             => 'Marketing',
        'other'                 => 'Other',
    ];

    public function __construct(
        private readonly OrderRepository      $orderRepo,
        private readonly ExpenseRepository    $expenseRepo,
        private readonly EbayChargeRepository $chargeRepo,
    ) {}

    public function summary(string $from, string $to): array
    {
        $orders = ['count' => 0, 'gross' => 0.0, 'fees' => 0.0, 'refunds' => 0.0, 'net' => 0.0,
                   'exact' => 0, 'manual' => 0, 'estimated' => 0, 'unknown' => 0];
        $rows   = [];

        foreach ($this->orderRepo->findByDateRange($from, $to) as $order) {
            $e      = $order['earnings'] ?? [];
            $source = $e['source'] ?? 'unknown';

            // A cancelled order only counts if eBay actually booked money for it.
            if (($order['status'] ?? '') === 'CANCELLED' && $source !== 'finances') {
                continue;
            }

            $gross   = (float)($e['gross'] ?? $order['totals']['grand_total']['value'] ?? 0);
            $fees    = (float)($e['total_fees'] ?? 0);
            $refunds = (float)($e['refunds'] ?? 0);
            // Without any fee data the sale price is the best available figure.
            $net     = isset($e['net']) ? (float)$e['net'] : $gross;

            // The seller's own correction wins; the difference is shown as fees.
            if (!empty($order['earnings_override']['net'])) {
                $net     = (float)$order['earnings_override']['net'];
                $fees    = $gross - $net;
                $refunds = 0.0;
                $source  = 'manual';
            }

            $orders['count']++;
            $orders['gross']   += $gross;
            $orders['fees']    += $fees;
            $orders['refunds'] += $refunds;
            $orders['net']     += $net;
            $orders[match ($source) {
                'finances' => 'exact', 'manual' => 'manual', 'order' => 'estimated', default => 'unknown',
            }]++;

            $rows[] = [
                'id'         => $order['id'],
                'date'       => $order['created_at'] ?? '',
                'title'      => $order['line_items'][0]['title'] ?? '',
                'status'     => $order['status'] ?? '',
                'gross'      => self::amount($gross),
                'total_fees' => self::amount($fees),
                'net'        => self::amount($net),
                'source'     => $source,
                'on_hold'    => (bool)($e['on_hold'] ?? false),
            ];
        }

        $charges      = $this->chargeRepo->findBetween($from, $to);
        $chargesTotal = array_sum(array_map(static fn (array $c): float => (float)$c['amount'], $charges));

        $byCategory    = [];
        $expensesTotal = 0.0;
        foreach ($this->expenseRepo->findBetween($from, $to) as $expense) {
            $cat = $expense['category'] ?? 'other';
            $byCategory[$cat] = ($byCategory[$cat] ?? 0) + (float)$expense['amount'];
            $expensesTotal   += (float)$expense['amount'];
        }
        arsort($byCategory);

        return [
            'period'   => ['from' => $from, 'to' => $to],
            'currency' => 'EUR',
            'orders'   => [
                'count'           => $orders['count'],
                'gross'           => self::amount($orders['gross']),
                'fees'            => self::amount($orders['fees']),
                'refunds'         => self::amount($orders['refunds']),
                'net'             => self::amount($orders['net']),
                'exact_count'     => $orders['exact'],
                'manual_count'    => $orders['manual'],
                'estimated_count' => $orders['estimated'],
                'unknown_count'   => $orders['unknown'],
            ],
            'ebay_charges' => [
                'total' => self::amount($chargesTotal),
                'items' => $charges,
            ],
            'expenses' => [
                'total'       => self::amount($expensesTotal),
                'by_category' => array_map(
                    static fn (string $cat, float $total): array => [
                        'category' => $cat,
                        'label'    => self::CATEGORIES[$cat] ?? $cat,
                        'total'    => self::amount($total),
                    ],
                    array_keys($byCategory),
                    array_values($byCategory)
                ),
            ],
            'profit'     => self::amount($orders['net'] - $chargesTotal - $expensesTotal),
            'order_rows' => $rows,
        ];
    }

    public function listExpenses(string $from, string $to): array
    {
        return $this->expenseRepo->findBetween($from, $to);
    }

    public function createExpense(array $data): array
    {
        $expense       = $this->validate($data);
        $expense['id'] = 'exp_' . bin2hex(random_bytes(6));
        return $this->expenseRepo->save($expense);
    }

    public function updateExpense(string $id, array $data): ?array
    {
        $existing = $this->expenseRepo->find($id);
        if ($existing === null) {
            return null;
        }
        return $this->expenseRepo->save(array_merge($existing, $this->validate($data)));
    }

    public function deleteExpense(string $id): bool
    {
        return $this->expenseRepo->delete($id);
    }

    /** @throws \InvalidArgumentException with a message fit to show the user */
    private function validate(array $data): array
    {
        $date = trim((string)($data['date'] ?? ''));
        $d    = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if ($d === false || $d->format('Y-m-d') !== $date) {
            throw new \InvalidArgumentException('Date must be a valid date (YYYY-MM-DD).');
        }

        $amountRaw = str_replace(',', '.', trim((string)($data['amount'] ?? '')));
        if (!preg_match('/^\d{1,9}(\.\d{1,2})?$/', $amountRaw) || (float)$amountRaw <= 0) {
            throw new \InvalidArgumentException('Amount must be a positive number with at most two decimals.');
        }

        $category = (string)($data['category'] ?? '');
        if (!isset(self::CATEGORIES[$category])) {
            throw new \InvalidArgumentException('Unknown expense category.');
        }

        $description = mb_substr(strip_tags(trim((string)($data['description'] ?? ''))), 0, 200);
        if ($description === '') {
            throw new \InvalidArgumentException('Please describe the expense.');
        }

        return [
            'date'        => $date,
            'amount'      => self::amount((float)$amountRaw),
            'currency'    => 'EUR',
            'category'    => $category,
            'description' => $description,
        ];
    }

    private static function amount(float $value): string
    {
        return EarningsCalculator::amount($value);
    }
}
