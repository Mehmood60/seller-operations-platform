<?php

declare(strict_types=1);

namespace App\Services;

use App\PDF\InvoicePdf;
use App\Storage\Json\OrderRepository;

class InvoiceService
{
    public function __construct(
        private readonly OrderRepository $orderRepo,
        private readonly InvoicePdf      $invoicePdf,
        private readonly ProfileService  $profileService,
    ) {}

    public function generateForOrder(string $orderId): string
    {
        $order = $this->orderRepo->find($orderId);
        if ($order === null) {
            throw new \RuntimeException('Order not found: ' . $orderId);
        }

        // Load seller profile for the invoice header.
        // Falls back to an empty array when no profile exists yet; InvoicePdf
        // will use safe defaults (e.g. "My eBay Shop") in that case.
        $seller = $this->profileService->getFirst();

        return $this->invoicePdf->generate($order, $seller);
    }
}
