<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\PDF\InvoicePdf;
use App\Services\InvoiceService;
use App\Services\ProfileService;
use App\Storage\Json\OrderRepository;
use Tests\TestCase;

class InvoiceServiceTest extends TestCase
{
    private string $tempDir;
    private OrderRepository $orderRepo;
    private InvoicePdf $mockPdf;
    private ProfileService $mockProfileService;
    private InvoiceService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir            = $this->createTempDir();
        $this->orderRepo          = new OrderRepository($this->tempDir);
        $this->mockPdf            = $this->createMock(InvoicePdf::class);
        $this->mockProfileService = $this->createMock(ProfileService::class);
        $this->mockProfileService->method('getFirst')->willReturn([]);
        $this->service = new InvoiceService($this->orderRepo, $this->mockPdf, $this->mockProfileService);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $this->removeDir($this->tempDir);
    }

    private function seedOrder(string $id): void
    {
        $this->orderRepo->save([
            'id'            => $id,
            'ebay_order_id' => $id,
            'status'        => 'PAID',
            'buyer'         => [
                'username'         => 'testbuyer',
                'email'            => '',
                'shipping_address' => [
                    'name' => 'Test Buyer', 'line1' => '1 Test St', 'line2' => '',
                    'city' => 'London', 'state' => 'England',
                    'postal_code' => 'W1A 1AA', 'country_code' => 'GB',
                ],
            ],
            'line_items'    => [[
                'ebay_item_id' => 'item-001',
                'title'        => 'Test Product',
                'sku'          => 'SKU-001',
                'quantity'     => 1,
                'unit_price'   => ['value' => '25.00', 'currency' => 'GBP'],
                'total_price'  => ['value' => '25.00', 'currency' => 'GBP'],
            ]],
            'payment'  => ['method' => 'EBAY_MANAGED', 'status' => 'PAID', 'amount' => ['value' => '25.00', 'currency' => 'GBP'], 'paid_at' => '2026-04-10T10:00:00Z'],
            'shipping' => ['service' => 'Royal Mail', 'cost' => ['value' => '0.00', 'currency' => 'GBP'], 'tracking_number' => null, 'shipped_at' => null, 'delivered_at' => null],
            'totals'   => [
                'subtotal'    => ['value' => '25.00', 'currency' => 'GBP'],
                'shipping'    => ['value' => '0.00', 'currency' => 'GBP'],
                'grand_total' => ['value' => '25.00', 'currency' => 'GBP'],
            ],
            'notes'      => '',
            'created_at' => '2026-04-10T10:00:00+00:00',
            'updated_at' => '2026-04-10T10:00:00+00:00',
            'synced_at'  => '2026-04-10T10:00:00+00:00',
        ]);
    }

    public function testGenerateForOrderThrowsRuntimeExceptionWhenOrderNotFound(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/not found/i');

        $this->service->generateForOrder('nonexistent-id');
    }

    public function testGenerateForOrderCallsPdfGeneratorWithCorrectOrder(): void
    {
        $this->seedOrder('order-001');

        $this->mockPdf
            ->expects($this->once())
            ->method('generate')
            ->with(
                $this->callback(fn(array $order): bool => $order['id'] === 'order-001'),
                $this->isType('array')
            )
            ->willReturn('%PDF-1.4 fake-content');

        $this->service->generateForOrder('order-001');
    }

    public function testGenerateForOrderReturnsPdfStringFromGenerator(): void
    {
        $this->seedOrder('order-002');

        $this->mockPdf
            ->method('generate')
            ->willReturn('%PDF-fake-content');

        $result = $this->service->generateForOrder('order-002');

        $this->assertIsString($result);
        $this->assertEquals('%PDF-fake-content', $result);
    }

    public function testPdfGeneratorIsNotCalledWhenOrderMissing(): void
    {
        $this->mockPdf->expects($this->never())->method('generate');

        try {
            $this->service->generateForOrder('ghost-order');
        } catch (\RuntimeException) {
            // Expected
        }
    }
}
