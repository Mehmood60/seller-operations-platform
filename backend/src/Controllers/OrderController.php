<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\OrderService;
use App\Services\InvoiceService;
use App\Services\OrderFulfillmentService;
use App\Helpers\Response;

class OrderController
{
    public function __construct(
        private readonly OrderService            $orderService,
        private readonly InvoiceService          $invoiceService,
        private readonly OrderFulfillmentService $fulfillmentService,
    ) {}

    private const ALLOWED_STATUSES = ['PAID', 'SHIPPED', 'DELIVERED', 'CANCELLED'];

    public function index(array $params): void
    {
        $page    = max(1, (int)($_GET['page'] ?? 1));
        $limit   = min(max(1, (int)($_GET['limit'] ?? 25)), 100);
        $filters = [];

        if (!empty($_GET['status'])) {
            $status = strtoupper(trim($_GET['status']));
            if (in_array($status, self::ALLOWED_STATUSES, true)) {
                $filters['status'] = $status;
            }
        }
        if (!empty($_GET['search'])) {
            $filters['search'] = mb_substr(strip_tags(trim($_GET['search'])), 0, 100);
        }

        $result = $this->orderService->list($filters, $page, $limit);

        Response::json($result['items'], [
            'page'  => $result['page'],
            'limit' => $result['limit'],
            'total' => $result['total'],
        ]);
    }

    public function show(array $params): void
    {
        $order = $this->orderService->get($params['id'] ?? '');
        if ($order === null) {
            Response::error('Order not found.', 404);
            return;
        }
        Response::json($order);
    }

    public function invoice(array $params): void
    {
        try {
            $pdf      = $this->invoiceService->generateForOrder($params['id'] ?? '');
            $filename = 'invoice-' . ($params['id'] ?? 'order') . '.pdf';
            Response::stream($pdf, $filename);
        } catch (\Throwable $e) {
            Response::error($e->getMessage(), 404);
        }
    }

    // ── One-click fulfillment: record AliExpress order ────────────────────────

    public function fulfill(array $params): void
    {
        $id   = $params['id'] ?? '';
        $body = json_decode((string) file_get_contents('php://input'), true) ?? [];

        $aliexpressOrderId = trim((string)($body['aliexpress_order_id'] ?? ''));
        $sourceUrl         = trim((string)($body['source_url'] ?? ''));

        try {
            $order = $this->fulfillmentService->fulfill($id, $aliexpressOrderId, $sourceUrl);
            Response::json($order);
        } catch (\RuntimeException $e) {
            Response::error($e->getMessage(), 422);
        }
    }

    // ── Tracking: push tracking number to eBay and mark shipped ──────────────

    public function track(array $params): void
    {
        $id   = $params['id'] ?? '';
        $body = json_decode((string) file_get_contents('php://input'), true) ?? [];

        $trackingNumber = trim((string)($body['tracking_number'] ?? ''));
        $carrier        = trim((string)($body['carrier'] ?? ''));

        if ($trackingNumber === '' || $carrier === '') {
            Response::error('tracking_number and carrier are required.', 422);
            return;
        }

        try {
            $order = $this->fulfillmentService->addTracking($id, $trackingNumber, $carrier);
            Response::json($order);
        } catch (\RuntimeException $e) {
            Response::error($e->getMessage(), 422);
        }
    }
}
