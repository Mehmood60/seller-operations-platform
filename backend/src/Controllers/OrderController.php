<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\OrderService;
use App\Services\InvoiceService;
use App\Helpers\Response;

class OrderController
{
    public function __construct(
        private readonly OrderService $orderService,
        private readonly InvoiceService $invoiceService,
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
}
