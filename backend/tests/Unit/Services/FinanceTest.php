<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\eBay\EbayClientInterface;
use App\eBay\OrderMapper;
use App\Services\EarningsCalculator;
use App\Services\EbaySyncService;
use App\Services\FinanceService;
use App\Storage\Json\EbayChargeRepository;
use App\Storage\Json\ExpenseRepository;
use App\Storage\Json\ListingRepository;
use App\Storage\Json\OrderRepository;
use App\Storage\Json\TokenRepository;
use Tests\TestCase;

/**
 * Covers what the seller actually keeps per order (the Seller Hub
 * "Bestelleinnahmen" figure) and the profit summary built on it.
 */
class FinanceTest extends TestCase
{
    private string $dir;
    private OrderRepository $orders;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir    = $this->createTempDir();
        $this->orders = new OrderRepository($this->dir);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->dir);
        parent::tearDown();
    }

    private function order(string $id, string $total, string $date = '2026-10-31T15:15:00.000Z', array $extra = []): array
    {
        return array_merge([
            'id'          => $id,
            'status'      => 'DELIVERED',
            'line_items'  => [['title' => 'Car drying towels']],
            'totals'      => ['grand_total' => ['value' => $total, 'currency' => 'EUR']],
            'shipping'    => ['tracking_number' => null, 'carrier' => null, 'shipped_at' => null],
            'created_at'  => $date,
        ], $extra);
    }

    /** A Finances API SALE: credited net of the fees listed on its line items. */
    private function saleTx(string $orderId, string $net, array $fees): array
    {
        return [
            'transactionId'   => 'S-' . $orderId,
            'transactionType' => 'SALE',
            'bookingEntry'    => 'CREDIT',
            'orderId'         => $orderId,
            'amount'          => ['value' => $net, 'currency' => 'EUR'],
            'transactionDate' => '2026-10-31T15:20:00.000Z',
            'transactionStatus' => 'FUNDS_ON_HOLD',
            'orderLineItems'  => [[
                'marketplaceFees' => array_map(
                    static fn ($t, $a) => ['feeType' => $t, 'amount' => ['value' => $a, 'currency' => 'EUR']],
                    array_keys($fees), $fees
                ),
            ]],
        ];
    }

    private function adFeeTx(string $orderId, string $amount): array
    {
        return [
            'transactionId'   => 'A-' . $orderId,
            'transactionType' => 'NON_SALE_CHARGE',
            'bookingEntry'    => 'DEBIT',
            'feeType'         => 'AD_FEE',
            'amount'          => ['value' => $amount, 'currency' => 'EUR'],
            'transactionDate' => '2026-11-01T08:00:00.000Z',
            'references'      => [['referenceId' => $orderId, 'referenceType' => 'ORDER_ID']],
        ];
    }

    // ── EarningsCalculator ───────────────────────────────────────────────────

    public function testMatchesSellerHubOrderEarnings(): void
    {
        // Seller Hub: 13,50 − 2,79 transaction fees − 1,61 ad fee = 9,10.
        $txs = [];
        foreach ([$this->saleTx('5351-04882', '10.71', ['FINAL_VALUE_FEE' => '2.44', 'FINAL_VALUE_FEE_FIXED_PER_ORDER' => '0.35']),
                  $this->adFeeTx('5351-04882', '1.61')] as $tx) {
            $txs[EarningsCalculator::key($tx)] = EarningsCalculator::slim($tx);
        }

        $e = EarningsCalculator::fromTransactions($txs, $this->order('5351-04882', '13.50'));

        $this->assertSame('finances', $e['source']);
        $this->assertSame('13.50', $e['gross']);
        $this->assertSame('4.40', $e['total_fees']);
        $this->assertSame('9.10', $e['net']);
        $this->assertTrue($e['on_hold']);
        $this->assertSame(
            ['FINAL_VALUE_FEE', 'FINAL_VALUE_FEE_FIXED_PER_ORDER', 'AD_FEE'],
            array_column($e['fees'], 'type')
        );
    }

    public function testRefundBringsEarningsBelowZero(): void
    {
        $refund = [
            'transactionId' => 'R1', 'transactionType' => 'REFUND', 'bookingEntry' => 'DEBIT',
            'orderId' => 'O1', 'amount' => ['value' => '13.50', 'currency' => 'EUR'],
        ];
        $txs = [];
        foreach ([$this->saleTx('O1', '10.71', ['FINAL_VALUE_FEE' => '2.79']), $refund] as $tx) {
            $txs[EarningsCalculator::key($tx)] = EarningsCalculator::slim($tx);
        }

        $e = EarningsCalculator::fromTransactions($txs, $this->order('O1', '13.50'));

        $this->assertSame('13.50', $e['refunds']);
        $this->assertSame('-2.79', $e['net']);
    }

    public function testAdFeeIsLinkedToItsOrderThroughReferences(): void
    {
        $this->assertSame('O1', EarningsCalculator::orderIdOf($this->adFeeTx('O1', '1.00')));
        $this->assertNull(EarningsCalculator::orderIdOf(['transactionType' => 'NON_SALE_CHARGE']));
    }

    public function testPayoutsAreNotCountedAsCharges(): void
    {
        $this->assertFalse(EarningsCalculator::isAccountCharge(['transactionType' => 'PAYOUT']));
        $this->assertTrue(EarningsCalculator::isAccountCharge(['transactionType' => 'NON_SALE_CHARGE']));
    }

    // ── OrderMapper fallback ─────────────────────────────────────────────────

    public function testOrderEstimateUsesMarketplaceFee(): void
    {
        $mapped = (new OrderMapper())->map([
            'orderId'             => 'O1',
            'pricingSummary'      => ['total' => ['value' => '23.00', 'currency' => 'EUR']],
            'totalMarketplaceFee' => ['value' => '4.37', 'currency' => 'EUR'],
        ]);

        $this->assertSame('order', $mapped['earnings']['source']);
        $this->assertSame('18.63', $mapped['earnings']['net']);
    }

    public function testOrderWithoutFeeDataHasNoNet(): void
    {
        $mapped = (new OrderMapper())->map(['orderId' => 'O1']);
        $this->assertSame('unknown', $mapped['earnings']['source']);
        $this->assertNull($mapped['earnings']['net']);
    }

    // ── EbaySyncService::syncEarnings ────────────────────────────────────────

    private function syncService(array $transactions): EbaySyncService
    {
        $client = $this->createMock(EbayClientInterface::class);
        $client->method('getFinanceTransactions')->willReturn([
            'transactions' => $transactions,
            'total'        => count($transactions),
        ]);

        return new EbaySyncService(
            $client,
            $this->orders,
            new ListingRepository($this->dir),
            $this->createMock(TokenRepository::class),
            $this->dir
        );
    }

    public function testSyncRecordsPayoutOnOrderAndKeepsAccountCharges(): void
    {
        $this->orders->save($this->order('O1', '13.50'));
        $insertion = [
            'transactionId' => 'N1', 'transactionType' => 'NON_SALE_CHARGE', 'bookingEntry' => 'DEBIT',
            'feeType' => 'INSERTION_FEE', 'amount' => ['value' => '0.35', 'currency' => 'EUR'],
            'transactionDate' => '2026-10-20T10:00:00.000Z',
        ];

        $result = $this->syncService([
            $this->saleTx('O1', '10.71', ['FINAL_VALUE_FEE' => '2.79']),
            $this->adFeeTx('O1', '1.61'),
            $insertion,
            ['transactionId' => 'P1', 'transactionType' => 'PAYOUT', 'bookingEntry' => 'DEBIT',
             'amount' => ['value' => '50.00', 'currency' => 'EUR'], 'transactionDate' => '2026-10-21T10:00:00.000Z'],
        ])->syncEarnings();

        $this->assertSame(['updated' => 1, 'charges' => 1], $result);
        $this->assertSame('9.10', $this->orders->find('O1')['earnings']['net']);

        $charges = (new EbayChargeRepository($this->dir))->findBetween('2026-10-01', '2026-10-31');
        $this->assertCount(1, $charges);
        $this->assertSame('Insertion fee', $charges[0]['label']);
        $this->assertSame('0.35', $charges[0]['amount']);
    }

    public function testLaterSyncMergesWithTransactionsSeenEarlier(): void
    {
        $this->orders->save($this->order('O1', '13.50'));
        $this->syncService([$this->saleTx('O1', '10.71', ['FINAL_VALUE_FEE' => '2.79'])])->syncEarnings();

        // The sale has slid out of the look-back window; only the ad fee is returned.
        $this->syncService([$this->adFeeTx('O1', '1.61')])->syncEarnings();

        $this->assertSame('9.10', $this->orders->find('O1')['earnings']['net']);
    }

    public function testOrderResyncKeepsPayoutAndLocallyRecordedFulfillment(): void
    {
        $this->orders->save($this->order('O1', '13.50', '2026-10-31T15:15:00.000Z', [
            'earnings'    => ['source' => 'finances', 'net' => '9.10', 'transactions' => []],
            'fulfillment' => ['status' => 'shipped', 'aliexpress_order_id' => '8190001'],
            'shipping'    => ['tracking_number' => 'JD0146', 'carrier' => 'DHL', 'shipped_at' => '2026-11-01T10:00:00+00:00'],
        ]));

        $client = $this->createMock(EbayClientInterface::class);
        $client->method('get')->willReturn(['orders' => [[
            'orderId'             => 'O1',
            'pricingSummary'      => ['total' => ['value' => '13.50', 'currency' => 'EUR']],
            'totalMarketplaceFee' => ['value' => '2.79', 'currency' => 'EUR'],
        ]], 'total' => 1]);
        $client->method('getFinanceTransactions')->willReturn(['transactions' => [], 'total' => 0]);

        (new EbaySyncService($client, $this->orders, new ListingRepository($this->dir),
            $this->createMock(TokenRepository::class), $this->dir))->syncOrders();

        $order = $this->orders->find('O1');
        $this->assertSame('9.10', $order['earnings']['net']);
        $this->assertSame('8190001', $order['fulfillment']['aliexpress_order_id']);
        $this->assertSame('JD0146', $order['shipping']['tracking_number']);
    }

    // ── FinanceService ───────────────────────────────────────────────────────

    public function testProfitIsPayoutMinusChargesMinusExpenses(): void
    {
        $this->orders->save($this->order('O1', '13.50', '2026-10-31T15:15:00.000Z', [
            'earnings' => ['source' => 'finances', 'gross' => '13.50', 'total_fees' => '4.40', 'net' => '9.10'],
        ]));
        $this->orders->save($this->order('O2', '23.00', '2026-10-04T16:16:00.000Z', [
            'earnings' => ['source' => 'order', 'gross' => '23.00', 'total_fees' => '4.37', 'net' => '18.63'],
        ]));
        // Cancelled without any booked money: left out.
        $this->orders->save($this->order('O3', '99.00', '2026-10-05T10:00:00.000Z', ['status' => 'CANCELLED']));
        // Outside the period.
        $this->orders->save($this->order('O4', '50.00', '2026-09-30T10:00:00.000Z'));

        (new EbayChargeRepository($this->dir))->upsertMany([
            ['id' => 'N1', 'date' => '2026-10-20', 'type' => 'INSERTION_FEE', 'label' => 'Insertion fee', 'amount' => '0.35'],
        ]);

        $service = new FinanceService($this->orders, new ExpenseRepository($this->dir), new EbayChargeRepository($this->dir));
        $service->createExpense(['date' => '2026-10-02', 'amount' => '26,00', 'category' => 'business_registration', 'description' => 'Gewerbeanmeldung']);
        $service->createExpense(['date' => '2026-11-02', 'amount' => '5', 'category' => 'other', 'description' => 'Next month']);

        $s = $service->summary('2026-10-01', '2026-10-31');

        $this->assertSame(2, $s['orders']['count']);
        $this->assertSame('36.50', $s['orders']['gross']);
        $this->assertSame('27.73', $s['orders']['net']);
        $this->assertSame(1, $s['orders']['exact_count']);
        $this->assertSame(1, $s['orders']['estimated_count']);
        $this->assertSame('0.35', $s['ebay_charges']['total']);
        $this->assertSame('26.00', $s['expenses']['total']);
        // 27.73 − 0.35 − 26.00
        $this->assertSame('1.38', $s['profit']);
    }

    public function testRejectsInvalidExpenses(): void
    {
        $service = new FinanceService($this->orders, new ExpenseRepository($this->dir), new EbayChargeRepository($this->dir));

        foreach ([
            ['date' => '2026-02-30', 'amount' => '5', 'category' => 'other', 'description' => 'x'],
            ['date' => '2026-10-01', 'amount' => '-5', 'category' => 'other', 'description' => 'x'],
            ['date' => '2026-10-01', 'amount' => '5', 'category' => 'nope', 'description' => 'x'],
            ['date' => '2026-10-01', 'amount' => '5', 'category' => 'other', 'description' => ''],
        ] as $bad) {
            try {
                $service->createExpense($bad);
                $this->fail('Accepted ' . json_encode($bad));
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
